<?php

declare(strict_types=1);

namespace App\Tests\Integration\Channel;

use App\Domain\Channel\ChannelHealthService;
use App\Domain\Channel\ChannelMode;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Channel\ChannelStatus;
use App\Domain\Channel\ChannelSystem;
use App\Domain\Channel\CheckChannelHealthJob;
use App\Domain\Channel\ChannelCredentials;
use App\Domain\Channel\ConnectCodeRedeemer;
use App\Domain\Channel\ConnectCodes;
use App\Domain\Channel\CredentialVault;
use App\Domain\Workspace\Role;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Fake\FakeAdapter;
use App\Kernel\Console\Command\CryptoRotateCommand;
use App\Kernel\Console\Output;
use App\Kernel\Queue\Worker;
use App\Kernel\Security\Crypto;
use App\Tests\Support\ChannelTestCase;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ChannelRepository::class)]
#[CoversClass(ChannelSystem::class)]
#[CoversClass(ChannelCredentials::class)]
#[CoversClass(ConnectCodes::class)]
#[CoversClass(ConnectCodeRedeemer::class)]
#[CoversClass(CredentialVault::class)]
#[CoversClass(ChannelHealthService::class)]
#[CoversClass(CheckChannelHealthJob::class)]
#[CoversClass(CryptoRotateCommand::class)]
final class ChannelDomainTest extends ChannelTestCase
{
    public function testTheRepositoryNeverReachesAcrossWorkspaces(): void
    {
        [$ownerA, $workspaceA] = $this->ownerWithWorkspace('a@example.com', 'Анна');
        [$ownerB, $workspaceB] = $this->ownerWithWorkspace('b@example.com', 'Борис');
        $channel = $this->makeChannel($workspaceA, $ownerA);
        $repository = $this->app->container()->get(ChannelRepository::class);
        $contextB = $this->contextFor($workspaceB, $ownerB);

        self::assertNull($repository->find($contextB, $channel->publicId));
        self::assertNull($repository->findByExternal($contextB, Platform::Telegram, $channel->externalId));
        self::assertSame([], $repository->all($contextB));
        self::assertSame(0, $repository->count($contextB));
        $repository->delete($contextB, $channel);
        $repository->setStatus($contextB, $channel, ChannelStatus::Paused);
        self::assertSame('active', $this->db->select('SELECT status FROM channels WHERE id = ?', [$channel->id])[0]['status']);
        self::assertSame(1, $repository->count($this->contextFor($workspaceA, $ownerA)));
    }

    public function testTheSameChatMayBeConnectedByTwoWorkspacesIndependently(): void
    {
        [$ownerA, $workspaceA] = $this->ownerWithWorkspace('a@example.com', 'Анна');
        [$ownerB, $workspaceB] = $this->ownerWithWorkspace('b@example.com', 'Борис');

        $a = $this->makeChannel($workspaceA, $ownerA);
        $b = $this->makeChannel($workspaceB, $ownerB);

        self::assertNotSame($a->id, $b->id);
        self::assertCount(2, $this->app->container()->get(ChannelSystem::class)->sharedBotChannels(Platform::Telegram, '-1001234567890'));
    }

    public function testConnectingTwiceUpdatesInsteadOfDuplicating(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $first = $this->makeChannel($workspace, $owner, title: 'Старое имя');
        $this->db->execute('UPDATE channels SET alias = ? WHERE id = ?', ['Мой псевдоним', $first->id]);

        $second = $this->makeChannel($workspace, $owner, title: 'Новое имя');

        self::assertSame($first->id, $second->id);
        self::assertSame('Новое имя', $second->title);
        self::assertSame('Мой псевдоним', $second->alias, 'the owner\'s own name survives');
    }

    public function testDeletingAWorkspaceRemovesItsChannelsCodesAndCredentials(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $credentialId = $this->app->container()->get(CredentialVault::class)->store($context, Platform::Telegram, 'bot_token', TestEnv::SHARED_BOT_TOKEN);
        $this->makeChannel($workspace, $owner, mode: ChannelMode::OwnBot, credentialId: $credentialId);
        $this->issueCode($workspace, $owner);

        $this->db->execute('DELETE FROM workspaces WHERE id = ?', [$workspace->id]);

        foreach (['channels', 'platform_credentials', 'channel_connect_codes'] as $table) {
            self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM ' . $table)[0]['c'], $table);
        }
    }

    public function testTokensAreEncryptedAtRestAndDecryptedOnlyForTheirOwnChannel(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        [$otherOwner, $otherWorkspace] = $this->ownerWithWorkspace('b@example.com', 'Борис');
        $vault = $this->app->container()->get(CredentialVault::class);
        $secret = '555555555:secret-token-value-0123456789abcdef';
        $credentialId = $vault->store($this->contextFor($workspace, $owner), Platform::Telegram, 'bot_token', $secret);
        $channel = $this->makeChannel($workspace, $owner, mode: ChannelMode::OwnBot, credentialId: $credentialId);

        $raw = (string) $this->db->select('SELECT secret_enc FROM platform_credentials')[0]['secret_enc'];
        self::assertStringNotContainsString('secret-token-value', $raw);
        self::assertSame($secret, $this->app->container()->get(ChannelCredentials::class)->forChannel($channel)->secret);

        // A channel row pointing at another workspace's credential gets nothing.
        $stolen = $this->makeChannel($otherWorkspace, $otherOwner, '-1007', 'Чужой', ChannelMode::OwnBot, $credentialId);
        $this->expectException(PlatformError::class);
        $this->app->container()->get(ChannelCredentials::class)->forChannel($stolen);
    }

    public function testTheSharedBotTokenComesFromTheEnvironment(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner);

        self::assertSame(TestEnv::SHARED_BOT_TOKEN, $this->app->container()->get(ChannelCredentials::class)->forChannel($channel)->secret);
        self::assertSame('fake', $this->app->container()->get(ChannelCredentials::class)->forChannel($this->makeChannel($workspace, $owner, 'fake-1', 'Тест', platform: Platform::Fake))->secret);
    }

    public function testWithoutASharedBotTokenAChannelHasNoCredential(): void
    {
        $this->app = TestEnv::app(['TELEGRAM_BOT_TOKEN' => '']);
        $this->app->container()->instance(\App\Support\Clock::class, $this->clock);
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner);

        try {
            $this->app->container()->get(ChannelCredentials::class)->forChannel($channel);
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Auth, $e->kind);
        }
    }

    public function testCodesAreHashedExpireAndAreReplacedByNewOnes(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $codes = $this->app->container()->get(ConnectCodes::class);
        $redeemer = $this->app->container()->get(ConnectCodeRedeemer::class);

        $first = $codes->issue($context, Platform::Telegram);
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{10}$/', $first->code);
        self::assertSame(hash('sha256', $first->code), $this->db->select('SELECT code_hash FROM channel_connect_codes')[0]['code_hash']);
        self::assertNotNull($redeemer->peek($first->code, Platform::Telegram));
        self::assertNotNull($redeemer->peek(strtolower($first->pretty()), Platform::Telegram), 'any case, with the dash');
        self::assertNull($redeemer->peek($first->code, Platform::Vk), 'a code is for one platform');

        $second = $codes->issue($context, Platform::Telegram);
        self::assertNull($redeemer->peek($first->code, Platform::Telegram), 'a new code cancels the unused earlier one');
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM channel_connect_codes')[0]['c']);

        $this->clock->advance(899);
        self::assertNotNull($redeemer->peek($second->code, Platform::Telegram));
        $this->clock->advance(2);
        self::assertNull($redeemer->peek($second->code, Platform::Telegram), 'valid for 15 minutes');
        self::assertSame('expired', $codes->status($context, $second->publicId)['state'] ?? null);
    }

    public function testACodeIsConsumedExactlyOnceEvenWhenTwoMessagesRace(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->app->container()->get(ConnectCodes::class)->issue($this->contextFor($workspace, $owner), Platform::Telegram);
        $redeemer = $this->app->container()->get(ConnectCodeRedeemer::class);
        $peeked = $redeemer->peek($code->code, Platform::Telegram);
        self::assertNotNull($peeked);

        self::assertTrue($redeemer->consume($peeked['id']));
        self::assertFalse($redeemer->consume($peeked['id']), 'the second racer loses');
        self::assertNull($redeemer->peek($code->code, Platform::Telegram));
    }

    public function testMalformedCodesAreRejectedWithoutTouchingTheDatabase(): void
    {
        $redeemer = $this->app->container()->get(ConnectCodeRedeemer::class);

        self::assertNull($redeemer->peek("' OR 1=1 --", Platform::Telegram));
        self::assertNull($redeemer->peek('', Platform::Telegram));
        self::assertNull($redeemer->peek(str_repeat('A', 500), Platform::Telegram));
    }

    public function testHealthChecksAreQueuedOnlyForChannelsThatAreDue(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $fresh = $this->makeChannel($workspace, $owner, '-1', 'Свежий');
        $stale = $this->makeChannel($workspace, $owner, '-2', 'Давний');
        $never = $this->makeChannel($workspace, $owner, '-3', 'Не проверялся');
        $paused = $this->makeChannel($workspace, $owner, '-4', 'Пауза');
        $revoked = $this->makeChannel($workspace, $owner, '-5', 'Отозван');
        $broken = $this->makeChannel($workspace, $owner, '-6', 'Сломан');
        $now = $this->clock->now();
        $this->db->execute('UPDATE channels SET last_health_at = ? WHERE id = ?', [$now->format('Y-m-d H:i:s.u'), $fresh->id]);
        $this->db->execute('UPDATE channels SET last_health_at = ? WHERE id = ?', [$now->modify('-7 hours')->format('Y-m-d H:i:s.u'), $stale->id]);
        $this->db->execute('UPDATE channels SET last_health_at = NULL WHERE id = ?', [$never->id]);
        $this->db->execute('UPDATE channels SET status = ?, last_health_at = NULL WHERE id = ?', ['paused', $paused->id]);
        $this->db->execute('UPDATE channels SET status = ?, last_health_at = NULL WHERE id = ?', ['revoked', $revoked->id]);
        $this->db->execute('UPDATE channels SET status = ?, last_health_at = ? WHERE id = ?', ['error', $now->modify('-8 hours')->format('Y-m-d H:i:s.u'), $broken->id]);

        self::assertSame(3, $this->app->container()->get(ChannelHealthService::class)->enqueueDue());

        $queued = array_map(
            static fn (array $row): int => json_decode((string) $row['payload_json'], true)['data']['channel_id'] ?? 0,
            $this->db->select('SELECT payload_json FROM jobs'),
        );
        sort($queued);
        $expected = [$stale->id, $never->id, $broken->id];
        sort($expected);
        self::assertSame($expected, $queued, 'paused and revoked channels are not checked, fresh ones are not checked again');
    }

    public function testTheQueuedCheckUpdatesTheChannel(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner);
        $this->db->execute('UPDATE channels SET last_health_at = NULL WHERE id = ?', [$channel->id]);
        $this->expectChannelInspection('get_chat_channel', 'member_bot_no_post');

        $this->app->container()->get(ChannelHealthService::class)->enqueueDue();
        $this->drainQueue();

        $row = $this->db->select('SELECT status, last_health_at FROM channels WHERE id = ?', [$channel->id])[0];
        self::assertSame('error', $row['status']);
        self::assertNotNull($row['last_health_at']);
        self::assertCount(1, $this->mailer->sent, 'the owner is told once');
        self::assertStringContainsString('Публикация сообщений', $this->mailer->sent[0]->text);
        self::assertStringContainsString('/channels', $this->mailer->sent[0]->text);
    }

    public function testEveryOwnerAndAdministratorGetsTheEmailButOthersDoNot(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $this->memberOf($workspace, 'editor@example.com', Role::Editor);
        $channel = $this->makeChannel($workspace, $owner);
        $this->tg('getChat', 'error_403_kicked', 403);

        $this->app->container()->get(ChannelHealthService::class)->check($channel);
        $this->drainQueue();

        self::assertCount(1, $this->mailer->to('owner@example.com'));
        self::assertCount(1, $this->mailer->to('admin@example.com'));
        self::assertSame([], $this->mailer->to('editor@example.com'));
    }

    public function testAPausedChannelThatBreaksStaysPausedAndSilent(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner);
        $this->db->execute('UPDATE channels SET status = ? WHERE id = ?', ['paused', $channel->id]);
        $paused = $this->app->container()->get(ChannelSystem::class)->find($channel->id);
        self::assertNotNull($paused);
        $this->tg('getChat', 'error_403_kicked', 403);

        $after = $this->app->container()->get(ChannelHealthService::class)->check($paused);
        $this->drainQueue();

        self::assertSame(ChannelStatus::Paused, $after->status);
        self::assertNotNull($after->lastError);
        self::assertSame([], $this->mailer->sent);
    }

    public function testTheFakeNetworkRunsThroughTheSamePath(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $good = $this->makeChannel($workspace, $owner, 'fake-1', 'Тест', platform: Platform::Fake);
        $bad = $this->makeChannel($workspace, $owner, 'broken-1', 'Сломанный тест', platform: Platform::Fake);
        $health = $this->app->container()->get(ChannelHealthService::class);

        self::assertSame(ChannelStatus::Active, $health->check($good)->status);
        self::assertSame(ChannelStatus::Error, $health->check($bad)->status);
        self::assertSame([], $this->http->requests, 'no HTTP at all');
        self::assertInstanceOf(FakeAdapter::class, $this->app->container()->get(FakeAdapter::class));
    }

    public function testAPlatformThatIsSwitchedOffIsNotChecked(): void
    {
        $this->app = TestEnv::app(['PLATFORMS_ENABLED' => 'fake']);
        $this->app->container()->instance(\App\Support\Clock::class, $this->clock);
        $this->app->container()->instance(\App\Kernel\HttpClient\HttpClientInterface::class, $this->http);
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner);

        $after = $this->app->container()->get(ChannelHealthService::class)->check($channel);

        self::assertSame(ChannelStatus::Active, $after->status);
        self::assertSame([], $this->http->requests);
        self::assertSame(0, $this->app->container()->get(ChannelHealthService::class)->enqueueDue());
    }

    public function testRotatingSecretsReencryptsWithTheCurrentKeyOnly(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $oldKey = base64_encode(str_repeat('o', 32));
        $newKey = base64_encode(str_repeat('n', 32));
        $old = new Crypto('old', ['old' => $oldKey]);
        $both = new Crypto('new', ['new' => $newKey, 'old' => $oldKey]);
        $this->db->execute(
            'INSERT INTO platform_credentials (public_id, workspace_id, platform, kind, secret_enc, hint, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(6), NOW(6))',
            [(string) new \Symfony\Component\Uid\Ulid(), $context->workspaceId, 'telegram', 'bot_token', $old->encrypt('old-secret'), '1234…abcd'],
        );
        $current = $both->encrypt('current-secret');
        $this->db->execute(
            'INSERT INTO platform_credentials (public_id, workspace_id, platform, kind, secret_enc, hint, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(6), NOW(6))',
            [(string) new \Symfony\Component\Uid\Ulid(), $context->workspaceId, 'telegram', 'bot_token', $current, '1234…abcd'],
        );
        $command = new CryptoRotateCommand($this->db, $both);

        $out = new Output();
        self::assertSame(0, $command->run(['--dry-run'], $out));
        self::assertStringContainsString('1 to rotate', $out->contents());
        self::assertTrue($both->needsRotation((string) $this->db->select('SELECT secret_enc FROM platform_credentials ORDER BY id')[0]['secret_enc']), 'a dry run changes nothing');

        $out = new Output();
        self::assertSame(0, $command->run([], $out));
        self::assertStringContainsString('Done: 1 secret(s).', $out->contents());
        $rows = $this->db->select('SELECT secret_enc FROM platform_credentials ORDER BY id');
        self::assertFalse($both->needsRotation((string) $rows[0]['secret_enc']));
        self::assertSame('old-secret', $both->decrypt((string) $rows[0]['secret_enc']));
        self::assertSame($current, (string) $rows[1]['secret_enc'], 'secrets already on the current key are left as they are');
    }

    public function testAChannelCannotBeAssignedToAMemberBeforeItExists(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->expectException(\PDOException::class);
        $this->db->execute('INSERT INTO member_channel_access (workspace_id, user_id, channel_id, granted_at) VALUES (?, ?, 999999, NOW(6))', [$workspace->id, $owner->id]);
    }

    public function testTheJobPayloadRoundTrips(): void
    {
        $job = CheckChannelHealthJob::fromPayload((new CheckChannelHealthJob(42))->toPayload());

        self::assertSame(42, $job->channelId);
        self::assertSame(0, CheckChannelHealthJob::fromPayload([])->channelId);
        self::assertInstanceOf(Worker::class, $this->app->container()->get(Worker::class));
    }

    public function testTheVaultReplacesSecretsAndRemovesOnlyUnusedCredentials(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $vault = $this->app->container()->get(CredentialVault::class);
        $crypto = $this->app->container()->get(Crypto::class);
        $id = $vault->store($context, Platform::Telegram, 'bot_token', '555555555:first-token-value-0123456789abcdefgh');
        $channel = $this->makeChannel($workspace, $owner, mode: ChannelMode::OwnBot, credentialId: $id);

        self::assertSame('5555…efgh', $vault->hint($context, $id));
        $vault->replace($context, $id, '555555555:second-token-value-0123456789abcdefg');
        self::assertSame('5555…defg', $vault->hint($context, $id));
        self::assertSame('555555555:second-token-value-0123456789abcdefg', $crypto->decrypt((string) $this->db->select('SELECT secret_enc FROM platform_credentials WHERE id = ?', [$id])[0]['secret_enc']));

        $vault->deleteIfUnused($context, $id);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM platform_credentials')[0]['c'], 'a channel still uses it');
        $this->db->execute('DELETE FROM channels WHERE id = ?', [$channel->id]);
        $vault->deleteIfUnused($context, $id);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM platform_credentials')[0]['c']);
        self::assertNull($vault->hint($context, $id));
    }

    public function testAvatarsAreStoredServedAndDeleted(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner);
        $avatars = $this->app->container()->get(\App\Domain\Channel\ChannelAvatars::class);
        $client = $this->app->container()->get(\App\Integrations\Social\Telegram\TelegramClientFactory::class)->make(TestEnv::SHARED_BOT_TOKEN);

        $avatars->refresh($channel, $client, null);
        self::assertNull($this->app->container()->get(ChannelSystem::class)->find($channel->id)?->avatarKey, 'a channel without a picture keeps initials');

        // A file that is not a picture is refused.
        $this->tg('getFile', 'get_file');
        $this->http->expect('GET', 'https://api.telegram.org/file/bot' . TestEnv::SHARED_BOT_TOKEN . '/photos/file_1.jpg', 200, '<html>not an image</html>');
        $avatars->refresh($channel, $client, 'AVATAR_SMALL');
        self::assertNull($this->app->container()->get(ChannelSystem::class)->find($channel->id)?->avatarKey);

        $this->tg('getFile', 'get_file');
        $this->http->expect('GET', 'https://api.telegram.org/file/bot' . TestEnv::SHARED_BOT_TOKEN . '/photos/file_1.jpg', 200, \App\Tests\Support\MediaFixtures::png());
        $avatars->refresh($channel, $client, 'AVATAR_SMALL');
        $stored = $this->app->container()->get(ChannelSystem::class)->find($channel->id);
        self::assertNotNull($stored);
        self::assertNotNull($stored->avatarKey);
        self::assertSame('image/png', $avatars->open($stored)['mime'] ?? null);

        $avatars->delete($stored);
        self::assertFalse($this->storage->exists($stored->avatarKey));
        self::assertNull($avatars->open($stored), 'the file is gone');
        $this->http->assertAllConsumed();
    }

    public function testADownloadThatFailsLeavesTheChannelWithoutAvatar(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner);
        $client = $this->app->container()->get(\App\Integrations\Social\Telegram\TelegramClientFactory::class)->make(TestEnv::SHARED_BOT_TOKEN);
        $this->tg('getFile', 'error_500', 502);

        $this->app->container()->get(\App\Domain\Channel\ChannelAvatars::class)->refresh($channel, $client, 'AVATAR_SMALL');

        self::assertNull($this->app->container()->get(ChannelSystem::class)->find($channel->id)?->avatarKey);
    }
}
