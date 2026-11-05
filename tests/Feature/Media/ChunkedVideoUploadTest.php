<?php

declare(strict_types=1);

namespace App\Tests\Feature\Media;

use App\Domain\Media\ChunkedVideoUploads;
use App\Domain\Media\MediaException;
use App\Domain\Media\MediaService;
use App\Domain\Billing\Entitlements;
use App\Domain\Workspace\Role;
use App\Tests\Support\MediaFixtures;
use App\Tests\Support\MediaTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ChunkedVideoUploads::class)]
#[CoversClass(MediaService::class)]
final class ChunkedVideoUploadTest extends MediaTestCase
{
    public function testChunksResumeAndFinishOnlyAfterShaMatches(): void
    {
        [, $workspace] = $this->ownerSession();
        $bytes = file_get_contents(__DIR__ . '/../../Fixtures/short-video.mp4');
        self::assertIsString($bytes);
        $base = $this->base($workspace) . '/media/video-uploads';
        $start = $this->post($base, ['name' => 'clip.mp4', 'size' => (string) strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        self::assertSame(200, $start->status, $start->body);
        $id = (string) json_decode($start->body, true)['id'];
        $path = $base . '/' . $id;
        $half = intdiv(strlen($bytes), 2);
        $first = $this->request('POST', $path . '/chunks', ['_token' => $this->csrfToken(), 'offset' => '0'], [], ['chunk' => $this->uploadedFile(substr($bytes, 0, $half), 'part')]);
        self::assertSame($half, json_decode($first->body, true)['offset']);
        self::assertSame($half, json_decode($this->get($path)->body, true)['offset']);
        $second = $this->request('POST', $path . '/chunks', ['_token' => $this->csrfToken(), 'offset' => (string) $half], [], ['chunk' => $this->uploadedFile(substr($bytes, $half), 'part')]);
        self::assertSame(strlen($bytes), json_decode($second->body, true)['offset']);
        $finish = $this->post($path . '/finish');
        self::assertSame(200, $finish->status, $finish->body);
        self::assertSame('video', json_decode($finish->body, true)['items'][0]['kind']);
        self::assertSame(hash('sha256', $bytes), $this->db->select('SELECT sha256 FROM media')[0]['sha256']);
        self::assertSame(404, $this->get($path)->status);
        $videoId = (string) json_decode($finish->body, true)['items'][0]['id'];
        $cover = $this->post($this->base($workspace) . '/media/' . $videoId . '/cover', ['millisecond' => '0']);
        self::assertSame(200, $cover->status, $cover->body);
        self::assertSame('image', json_decode($cover->body, true)['item']['kind']);
    }

    public function testWrongChecksumDoesNotCreateMediaAndAnotherMemberCannotReadSession(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $bytes = MediaFixtures::mp4();
        $base = $this->base($workspace) . '/media/video-uploads';
        $start = $this->post($base, ['name' => 'clip.mp4', 'size' => (string) strlen($bytes), 'sha256' => str_repeat('a', 64)]);
        $id = (string) json_decode($start->body, true)['id'];
        $path = $base . '/' . $id;
        $this->actAsMember($workspace, 'media-author@example.com', Role::Author);
        self::assertSame(404, $this->get($path)->status);
        $this->actAs($owner);
        $sent = $this->request('POST', $path . '/chunks', ['_token' => $this->csrfToken(), 'offset' => '0'], [], ['chunk' => $this->uploadedFile($bytes, 'part')]);
        self::assertSame(200, $sent->status, $sent->body);
        $finish = $this->post($path . '/finish');
        self::assertSame(422, $finish->status);
        self::assertStringContainsString('Контрольная сумма', $finish->body);
        self::assertSame([], $this->db->select('SELECT id FROM media'));
        @unlink(dirname(__DIR__, 3) . '/storage/video-uploads/' . $id . '.part');
        @unlink(dirname(__DIR__, 3) . '/storage/video-uploads/' . $id . '.json');
    }

    public function testFinishRechecksPlanAfterUploadStarted(): void
    {
        [, $workspace] = $this->ownerSession();
        $bytes = MediaFixtures::mp4();
        $base = $this->base($workspace) . '/media/video-uploads';
        $start = $this->post($base, ['name' => 'clip.mp4', 'size' => (string) strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        self::assertSame(200, $start->status, $start->body);
        $id = (string) json_decode($start->body, true)['id'];
        $path = $base . '/' . $id;
        $chunk = $this->request('POST', $path . '/chunks', ['_token' => $this->csrfToken(), 'offset' => '0'], [], ['chunk' => $this->uploadedFile($bytes, 'part')]);
        self::assertSame(200, $chunk->status, $chunk->body);

        $planId = $this->app->container()->get(Entitlements::class)->plan($workspace->id)->id;
        $row = $this->db->select('SELECT id, limits_json FROM plans WHERE id=?', [$planId])[0];
        $originalLimits = (string) $row['limits_json'];
        $limits = json_decode($originalLimits, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($limits);
        $limits['short_video_file_mib'] = 0;
        $this->db->execute('UPDATE plans SET limits_json=? WHERE id=?', [json_encode($limits, JSON_THROW_ON_ERROR), $row['id']]);
        try {
            $finish = $this->post($path . '/finish');
        } finally {
            $this->db->execute('UPDATE plans SET limits_json=? WHERE id=?', [$originalLimits, $row['id']]);
        }
        self::assertSame(422, $finish->status, $finish->body);
        self::assertStringContainsString('ограничение тарифа', $finish->body);
        self::assertSame([], $this->db->select('SELECT id FROM media'));
        @unlink(dirname(__DIR__, 3) . '/storage/video-uploads/' . $id . '.part');
        @unlink(dirname(__DIR__, 3) . '/storage/video-uploads/' . $id . '.json');
    }

    public function testInvalidStartsIncompleteFinishAndExpiredResumeAreRejected(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $base = $this->base($workspace) . '/media/video-uploads';
        self::assertSame(422, $this->post($base, ['name' => 'clip.mp4', 'size' => '0', 'sha256' => str_repeat('a', 64)])->status);
        self::assertSame(422, $this->post($base, ['name' => 'clip.mp4', 'size' => '8', 'sha256' => 'bad'])->status);
        self::assertSame(422, $this->post($base, ['name' => str_repeat('a', 151), 'size' => '8', 'sha256' => str_repeat('a', 64)])->status);
        $start = $this->post($base, ['name' => 'clip.mp4', 'size' => '8', 'sha256' => hash('sha256', '12345678')]);
        self::assertSame(200, $start->status);
        $id = (string) json_decode($start->body, true)['id'];
        $path = $base . '/' . $id;
        $first = $this->request('POST', $path . '/chunks', ['_token' => $this->csrfToken(), 'offset' => '0'], [], ['chunk' => $this->uploadedFile('1234', 'part')]);
        self::assertSame(4, json_decode($first->body, true)['offset']);
        $repeat = $this->request('POST', $path . '/chunks', ['_token' => $this->csrfToken(), 'offset' => '0'], [], ['chunk' => $this->uploadedFile('1234', 'part')]);
        self::assertSame(4, json_decode($repeat->body, true)['offset']);
        self::assertSame(422, $this->post($path . '/finish')->status);
        $this->clock->advance(86401);
        $uploads = $this->app->container()->get(ChunkedVideoUploads::class);
        self::assertGreaterThanOrEqual(1, $uploads->pruneExpired());
        $this->expectException(MediaException::class);
        $uploads->status($this->contextFor($workspace, $owner), $id);
    }
}
