<?php

declare(strict_types=1);

namespace App\Tests\Feature\Api;

use App\Domain\Api\{ApiTokens, ApiIdentity, Idempotency};
use App\Domain\Workspace\{WorkspaceContext, Role, ChannelAccessRepository};
use App\Domain\Approval\ApprovalWorkflow;
use App\Kernel\Http\{Request, Response};
use App\Kernel\HttpClient\{SsrfGuard, HttpClientInterface, GuzzleHttpClient};
use App\Kernel\Security\{RateLimiter};
use App\Tests\Support\BillingTestCase;

/** Public boundary security, domain reuse, workspace scoping, idempotency and session isolation. */
final class PublicApiTest extends BillingTestCase
{
    private function tokens(): ApiTokens
    {
        return $this->app->container()->get(ApiTokens::class);
    }

    /** @return array{WorkspaceContext,string} */
    private function fixture(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner);
        $ctx = $this->contextFor($workspace, $owner);
        return [$ctx, $this->tokens()->create($ctx, 'Integration', ApiTokens::SCOPES)['secret']];
    }

    /** @param array<string,mixed> $body
     * @phpstan-impure
     */
    private function api(string $token, string $method, string $path, array $body = [], string $key = 'operation-1'): Response
    {
        return $this->request($method, '/api/v1' . $path, $body, ['Authorization' => 'Bearer ' . $token, 'Idempotency-Key' => $key]);
    }

    /** @return array<string,mixed> */
    private function data(Response $r): array
    {
        return json_decode($r->body, true, 32, JSON_THROW_ON_ERROR);
    }

    public function testBearerOnlyNoCookieAuthAndScopeChecks(): void
    {
        [$ctx, $token] = $this->fixture();
        $owner = $this->app->container()->get(\App\Domain\User\UserRepository::class)->find($ctx->userId) ?? throw new \LogicException();
        $this->actAs($owner);
        $r = $this->request('GET', '/api/v1/channels');
        self::assertSame(401, $r->status);
        self::assertSame([], $r->cookies);
        self::assertSame('attachment; filename="api.json"', $r->header('Content-Disposition'));
        self::assertSame(['code', 'message', 'details'], array_keys($this->data($r)['error']));
        $limited = $this->tokens()->create($ctx, 'Read posts', ['posts:read'])['secret'];
        self::assertSame(403, $this->api($limited, 'GET', '/channels')->status);
        $ok = $this->api($token, 'GET', '/channels');
        self::assertSame(200, $ok->status);
        self::assertSame([], $ok->cookies);
        self::assertNotNull($ok->header('X-RateLimit-Limit'));
        self::assertSame('attachment; filename="api.json"', $ok->header('Content-Disposition'));
        self::assertStringNotContainsString($token, $ok->body);
    }

    public function testTokenHashExpiryRevocationAndLiveBlockedMembership(): void
    {
        [$ctx, $token] = $this->fixture();
        $row = $this->db->select('SELECT * FROM api_tokens')[0];
        self::assertSame(hash('sha256', $token), $row['token_hash']);
        self::assertStringNotContainsString($token, json_encode($row, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($token, json_encode($this->db->select('SELECT * FROM audit_log'), JSON_THROW_ON_ERROR));
        $this->api($token, 'GET', '/posts');
        $used = $this->db->select('SELECT last_used_at FROM api_tokens')[0]['last_used_at'];
        $this->clock->advance(60);
        $this->api($token, 'GET', '/posts');
        self::assertSame($used, $this->db->select('SELECT last_used_at FROM api_tokens')[0]['last_used_at']);
        $this->db->execute('UPDATE api_tokens SET expires_at=?', [$this->clock->now()->modify('-1 second')->format('Y-m-d H:i:s')]);
        self::assertSame(401, $this->api($token, 'GET', '/posts')->status);
        $this->db->execute('UPDATE api_tokens SET expires_at=NULL');
        $this->tokens()->revoke($ctx, (string) $row['public_id']);
        self::assertSame(401, $this->api($token, 'GET', '/posts', key: 'after-revoke')->status);
        $fresh = $this->tokens()->create($ctx, 'fresh', ['posts:read'])['secret'];
        $this->db->execute("UPDATE users SET status='blocked' WHERE id=?", [$ctx->userId]);
        self::assertSame(401, $this->api($fresh, 'GET', '/posts')->status);
    }

    public function testPostsCrudVariantsSchedulingAndPublicationUseExistingDomain(): void
    {
        [$ctx, $token] = $this->fixture();
        $workspace = $this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException();
        $owner = $this->app->container()->get(\App\Domain\User\UserRepository::class)->find($ctx->userId) ?? throw new \LogicException();
        $channel = $this->fakeChannel($workspace, $owner);
        $created = $this->api($token, 'POST', '/posts', ['text' => 'Common', 'variants' => [['channel_id' => $channel->publicId, 'text' => 'Variant']]]);
        self::assertSame(201, $created->status, $created->body);
        $id = $this->data($created)['data']['id'];
        self::assertSame('Variant', $this->data($created)['data']['variants'][0]['text']);
        self::assertSame(200, $this->api($token, 'GET', '/posts/' . $id)->status);
        self::assertSame(200, $this->api($token, 'PATCH', '/posts/' . $id, ['text' => 'Updated'], 'edit')->status);
        $plan = $this->api($token, 'POST', '/posts/' . $id . '/schedule', ['scheduled_at' => $this->clock->now()->modify('+1 hour')->format(DATE_ATOM)], 'schedule');
        self::assertSame(200, $plan->status, $plan->body);
        self::assertSame('scheduled', $this->data($plan)['data']['status']);
        $pub = $this->data($plan)['data']['publications'][0]['id'];
        self::assertSame('queued', $this->data($this->api($token, 'GET', '/publications/' . $pub))['data']['status']);
        self::assertSame(200, $this->api($token, 'POST', '/posts/' . $id . '/unschedule', [], 'unschedule')->status);
        self::assertSame(200, $this->api($token, 'DELETE', '/posts/' . $id, [], 'delete')->status);
        self::assertSame(404, $this->api($token, 'GET', '/posts/' . $id)->status);
    }

    public function testIdempotencyReplayConflictTokenSeparationAndRequiredKey(): void
    {
        [$ctx, $token] = $this->fixture();
        $a = $this->api($token, 'POST', '/posts', ['text' => 'One']);
        $b = $this->api($token, 'POST', '/posts', ['text' => 'One']);
        self::assertSame(201, $a->status, $a->body);
        self::assertSame($a->body, $b->body);
        self::assertSame('true', $b->header('Idempotency-Replayed'));
        self::assertSame(1, $this->db->table('posts')->count());
        self::assertSame(409, $this->api($token, 'POST', '/posts', ['text' => 'Other'])->status);
        self::assertSame(400, $this->request('POST', '/api/v1/posts', ['text' => 'No key'], ['Authorization' => 'Bearer ' . $token])->status);
        $other = $this->tokens()->create($ctx, 'Other', ['posts:write'])['secret'];
        self::assertSame(201, $this->api($other, 'POST', '/posts', ['text' => 'Other token'])->status);
        self::assertSame(2, $this->db->table('posts')->count());
    }

    public function testConcurrentRequestsCommitOneDraftAndReplayHonorsCurrentAccess(): void
    {
        [$ctx, $token] = $this->fixture();
        $program = <<<'PHP'
require 'vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$app = \App\Tests\Support\TestEnv::app();
$r = $app->handle(new \App\Kernel\Http\Request('POST', '/api/v1/posts', body: ['text' => 'Concurrent draft'], headers: ['authorization' => 'Bearer '.$input['token'], 'idempotency-key' => 'concurrent']));
echo json_encode(['status' => $r->status, 'body' => $r->body], JSON_THROW_ON_ERROR);
PHP;
        $processes = [];
        for ($i = 0; $i < 2; ++$i) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-r', $program], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, \App\Tests\Support\TestEnv::basePath());
            self::assertIsResource($process);
            fwrite($pipes[0], json_encode(['token' => $token], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $result = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            $results[] = json_decode($result, true, 32, JSON_THROW_ON_ERROR);
        }
        self::assertSame(201, $results[0]['status'], $results[0]['body']);
        self::assertSame($results[0], $results[1]);
        self::assertSame(1, $this->db->table('posts')->count());
        self::assertSame(1, $this->db->table('api_idempotency')->count());
        $this->db->execute("UPDATE workspace_members SET role='viewer' WHERE workspace_id=? AND user_id=?", [$ctx->workspaceId, $ctx->userId]);
        self::assertSame(403, $this->api($token, 'POST', '/posts', ['text' => 'Concurrent draft'], 'concurrent')->status);
    }

    public function testForeignWorkspaceAndRestrictedChannelsDoNotLeak(): void
    {
        [$ctx, $token] = $this->fixture();
        [$other, $workspace] = $this->ownerWithWorkspace('other-api@example.com');
        $this->payWithFake($workspace, $other);
        $foreignCtx = $this->contextFor($workspace, $other);
        $channel = $this->fakeChannel($workspace, $other, 'other-channel');
        $post = $this->service()->saveDraft($foreignCtx, null, $this->draft('Secret', [$channel]));
        self::assertSame(404, $this->api($token, 'GET', '/posts/' . $post->publicId)->status);
        self::assertSame(404, $this->api($token, 'PATCH', '/posts/' . $post->publicId, ['text' => 'bad'])->status);
        self::assertSame([], $this->data($this->api($token, 'GET', '/channels'))['data']);
        [$scheduled, $publications] = $this->scheduled($foreignCtx, [$channel], '+1 hour', 'Foreign publication');
        self::assertSame(404, $this->api($token, 'GET', '/publications/' . $publications[0]->publicId)->status);
        self::assertSame(404, $this->api($token, 'GET', '/stats?channel=' . $channel->publicId)->status);
        $otherToken = $this->tokens()->create($foreignCtx, 'restricted', ApiTokens::SCOPES)['secret'];
        $this->app->container()->get(ChannelAccessRepository::class)->set($foreignCtx, $other->id, []);
        self::assertSame(404, $this->api($otherToken, 'GET', '/posts/' . $post->publicId)->status);
        self::assertSame([], $this->data($this->api($otherToken, 'GET', '/channels'))['data']);
        self::assertSame(404, $this->api($token, 'GET', '/publications/' . $channel->publicId)->status);
        self::assertStringNotContainsString('Secret', $this->api($token, 'GET', '/stats')->body);
    }

    public function testCursorIsStableOpaqueAndBoundToToken(): void
    {
        [$ctx, $token] = $this->fixture();
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(201, $this->api($token, 'POST', '/posts', ['text' => 'Post ' . $i], 'create-' . $i)->status);
        }
        $first = $this->data($this->api($token, 'GET', '/posts?limit=2'));
        self::assertCount(2, $first['data']);
        self::assertIsString($first['next_cursor']);
        $second = $this->data($this->api($token, 'GET', '/posts?limit=2&cursor=' . urlencode($first['next_cursor'])));
        self::assertCount(1, $second['data']);
        self::assertNotSame($first['data'][0]['id'], $second['data'][0]['id']);
        self::assertNull($second['next_cursor']);
        $other = $this->tokens()->create($ctx, 'another', ['posts:read'])['secret'];
        self::assertSame(400, $this->api($other, 'GET', '/posts?cursor=' . urlencode($first['next_cursor']))->status);
        self::assertSame(422, $this->api($token, 'GET', '/posts?limit=101')->status);
        self::assertSame(400, $this->api($token, 'GET', '/posts?cursor=forged')->status);
    }

    public function testRateLimitAndUnavailableRedisFailClosed(): void
    {
        [$ctx, $token] = $this->fixture();
        $r = null;
        for ($i = 0; $i < 61; ++$i) {
            $r = $this->api($token, 'GET', '/channels');
        }
        self::assertInstanceOf(Response::class, $r);
        self::assertSame(429, $r->status);
        self::assertSame('0', $r->header('X-RateLimit-Remaining'));
        self::assertSame('60', $r->header('X-RateLimit-Limit'));
        self::assertNotNull($r->header('X-RateLimit-Reset'));
        $fresh = $this->tokens()->create($ctx, 'separate', ['posts:read'])['secret'];
        self::assertSame(200, $this->api($fresh, 'GET', '/posts')->status);
        $this->app->container()->instance(RateLimiter::class, new RateLimiter(new \Redis(), $this->clock));
        $this->app->container()->instance(\App\Http\Middleware\PublicApi::class, $this->app->container()->make(\App\Http\Middleware\PublicApi::class));
        self::assertSame(503, $this->api($fresh, 'POST', '/posts', ['text' => 'Not allowed'])->status);
        self::assertSame(0, $this->db->table('posts')->count());
    }

    public function testApprovalAndPlanLimitsCannotBeBypassed(): void
    {
        [$ctx, $token] = $this->fixture();
        $ws = $this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException();
        $owner = $this->app->container()->get(\App\Domain\User\UserRepository::class)->find($ctx->userId) ?? throw new \LogicException();
        $author = $this->memberOf($ws, 'api-author@example.com', Role::Author, 'Автор');
        $channel = $this->fakeChannel($ws, $owner);
        $workflow = $this->app->container()->get(ApprovalWorkflow::class);
        $workflow->configure($ctx, true, []);
        $post = $this->service()->saveDraft($this->contextFor($ws, $author), null, $this->draft('Requires approval', [$channel]));
        self::assertSame(422, $this->api($token, 'POST', '/posts/' . $post->publicId . '/schedule', ['scheduled_at' => $this->clock->now()->modify('+1 hour')->format(DATE_ATOM)])->status);
        self::assertSame(0, $this->db->table('publications')->count());
        $this->db->execute('DELETE FROM subscriptions WHERE workspace_id=?', [$ctx->workspaceId]);
        self::assertSame(403, $this->api($token, 'POST', '/posts', ['text' => 'No plan'])->status);
    }

    public function testMediaPrivateUrlAndMalformedJsonAreRejectedSafely(): void
    {
        [$ctx, $token] = $this->fixture();
        $this->app->container()->instance(HttpClientInterface::class, new GuzzleHttpClient(new SsrfGuard()));
        $r = $this->api($token, 'POST', '/media', ['url' => 'http://169.254.169.254/latest/meta-data']);
        self::assertSame(422, $r->status, $r->body);
        self::assertSame(0, $this->db->table('media')->count());
        $bad = Request::create('POST', '/api/v1/posts', headers: ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Idempotency-Key' => 'json'], rawBody: '{broken');
        self::assertSame(400, $this->app->handle($bad)->status);
        $unknown = $this->api($token, 'GET', '/unknown');
        self::assertSame(404, $unknown->status);
        self::assertArrayHasKey('details', $this->data($unknown)['error']);
    }

    public function testFileAndUrlUploadsReuseMediaAndRespectWorkspaceOwnership(): void
    {
        [$ctx, $token] = $this->fixture();
        $jpeg = \App\Tests\Support\MediaFixtures::jpeg(60, 40);
        $path = $this->tempFile($jpeg);
        $request = new Request('POST', '/api/v1/media', files: ['file' => new \App\Kernel\Http\UploadedFile('photo.jpg', $path, strlen($jpeg), UPLOAD_ERR_OK)], headers: ['authorization' => 'Bearer ' . $token, 'idempotency-key' => 'file-1']);
        $uploaded = $this->app->handle($request);
        self::assertSame(201, $uploaded->status, $uploaded->body);
        $media = $this->data($uploaded)['data'];
        self::assertSame(60, $media['width']);
        self::assertSame(40, $media['height']);
        self::assertSame($uploaded->body, $this->app->handle($request)->body);
        $this->http->expect('GET', 'https://images.example/photo.jpg', 200, $jpeg, ['Content-Type' => 'image/jpeg']);
        $url = $this->api($token, 'POST', '/media', ['url' => 'https://images.example/photo.jpg'], 'url-1');
        self::assertSame(201, $url->status, $url->body);
        self::assertTrue($this->data($url)['data']['duplicate']);
        self::assertSame($media['id'], $this->data($url)['data']['id']);
        [$other, $ws] = $this->ownerWithWorkspace('foreign-media@example.com');
        $this->payWithFake($ws, $other);
        $otherToken = $this->tokens()->create($this->contextFor($ws, $other), 'Foreign media', ['posts:write'])['secret'];
        self::assertSame(422, $this->api($otherToken, 'POST', '/posts', ['text' => 'Foreign', 'media_ids' => [$media['id']]])->status);
        self::assertSame(0, $this->db->table('posts')->count());
    }

    public function testSlowOperationClaimsKeyReleasesItOnFailureAndRecoversStaleClaim(): void
    {
        [$ctx, $token] = $this->fixture();
        $identity = $this->tokens()->authenticate($token);
        $idempotency = $this->app->container()->get(Idempotency::class);
        $request = new Request('POST', '/api/v1/media', body: ['url' => 'https://images.example/a.jpg'], headers: ['authorization' => 'Bearer ' . $token, 'idempotency-key' => 'slow-1']);
        $created = static fn (): Response => Response::json(['data' => ['id' => 'media']], 201);
        $first = $idempotency->run($identity, $request, function () use ($idempotency, $identity, $request, $created): Response {
            try {
                $idempotency->run($identity, $request, $created, static fn (Response $r) => null, true);
                self::fail('A concurrent duplicate must not run.');
            } catch (\App\Domain\Api\ApiException $e) {
                self::assertSame(409, $e->status);
                self::assertSame('idempotency_in_progress', $e->errorCode);
            }
            return $created();
        }, static fn (Response $r) => null, true);
        self::assertSame(201, $first->status);
        $replay = $idempotency->run($identity, $request, static fn (): Response => throw new \LogicException('Stored result must be replayed.'), static fn (Response $r) => null, true);
        self::assertSame($first->body, $replay->body);
        self::assertSame('true', $replay->header('Idempotency-Replayed'));

        $failing = new Request('POST', '/api/v1/media', body: ['url' => 'https://images.example/b.jpg'], headers: ['authorization' => 'Bearer ' . $token, 'idempotency-key' => 'slow-2']);
        try {
            $idempotency->run($identity, $failing, static fn (): Response => throw new \RuntimeException('download failed'), static fn (Response $r) => null, true);
            self::fail('The failure must propagate.');
        } catch (\RuntimeException) {
        }
        self::assertSame(1, $this->db->table('api_idempotency')->count(), 'A failed slow request must not keep its key.');
        $retry = $idempotency->run($identity, $failing, $created, static fn (Response $r) => null, true);
        self::assertSame(201, $retry->status);

        // A worker that died after claiming leaves an unfinished row; after the lease another request may take it over.
        $stale = new Request('POST', '/api/v1/media', body: ['url' => 'https://images.example/c.jpg'], headers: ['authorization' => 'Bearer ' . $token, 'idempotency-key' => 'slow-3']);
        $idempotency->run($identity, $stale, function () use ($idempotency, $identity, $stale, $created): Response {
            $this->clock->advance(181);
            $taken = $idempotency->run($identity, $stale, $created, static fn (Response $r) => null, true);
            self::assertSame(201, $taken->status);
            return $created();
        }, static fn (Response $r) => null, true);
        self::assertSame(3, $this->db->table('api_idempotency')->count());
    }

    public function testPostListingBatchLoadsRelatedRowsAndKeepsRestrictedVisibility(): void
    {
        [$ctx, $token] = $this->fixture();
        $workspace = $this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException();
        $owner = $this->app->container()->get(\App\Domain\User\UserRepository::class)->find($ctx->userId) ?? throw new \LogicException();
        $channel = $this->fakeChannel($workspace, $owner, 'batch-channel');
        for ($i = 0; $i < 4; ++$i) {
            $this->scheduled($ctx, [$channel], '+' . ($i + 1) . ' hour', 'Batch ' . $i);
        }
        self::assertSame(201, $this->api($token, 'POST', '/posts', ['text' => 'No channels'], 'no-channels')->status);
        $page = $this->data($this->api($token, 'GET', '/posts?limit=100'));
        self::assertCount(5, $page['data']);
        $withChannel = 0;
        foreach ($page['data'] as $row) {
            if ($row['text'] !== 'No channels') {
                ++$withChannel;
                self::assertSame($channel->publicId, $row['variants'][0]['channel_id']);
                self::assertCount(1, $row['publications']);
            }
        }
        self::assertSame(4, $withChannel);
        $paged = $this->data($this->api($token, 'GET', '/posts?limit=2'));
        self::assertCount(2, $paged['data']);
        self::assertIsString($paged['next_cursor']);
        $this->app->container()->get(ChannelAccessRepository::class)->set($ctx, $ctx->userId, []);
        self::assertSame([], $this->data($this->api($token, 'GET', '/posts'))['data']);
    }

    public function testMaintenancePreservesJsonContractWithoutSessionBypass(): void
    {
        [$ctx, $token] = $this->fixture();
        $settings = $this->app->container()->get(\App\Domain\Settings\Settings::class);
        $settings->set('site.maintenance', true, null);
        try {
            $response = $this->api($token, 'GET', '/channels');
            self::assertSame(503, $response->status);
            self::assertSame('maintenance', $this->data($response)['error']['code']);
            self::assertSame('600', $response->header('Retry-After'));
            self::assertSame([], $response->cookies);
        } finally {
            $settings->set('site.maintenance', false, null);
        }
    }

    public function testStatsUnknownRemainsNullAndNoInternalIdentifiers(): void
    {
        [$ctx, $token] = $this->fixture();
        $r = $this->api($token, 'GET', '/stats');
        self::assertSame(200, $r->status, $r->body);
        $data = $this->data($r)['data'];
        self::assertNull($data['totals']['views']);
        self::assertNull($data['totals']['reach']);
        self::assertNull($data['totals']['er']);
        self::assertArrayNotHasKey('workspace_id', $data);
        self::assertArrayNotHasKey('user_id', $data);
    }
}
