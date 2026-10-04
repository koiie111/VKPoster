<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Domain\Media\FolderRepository;
use App\Domain\Media\Media;
use App\Domain\Media\MediaKind;
use App\Domain\Media\MediaLookup;
use App\Domain\Media\MediaRepository;
use App\Domain\Media\WatermarkRepository;
use App\Domain\User\UserRepository;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Kernel\Database\Connection;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The media tables against a real MySQL: scoping by workspace, search escaping, quota sums, folders and watermarks.
 */
#[CoversClass(MediaRepository::class)]
#[CoversClass(MediaLookup::class)]
#[CoversClass(FolderRepository::class)]
#[CoversClass(WatermarkRepository::class)]
final class MediaRepositoriesTest extends TestCase
{
    private Connection $db;
    private FakeClock $clock;
    private WorkspaceRepository $workspaces;
    private MediaRepository $media;
    private FolderRepository $folders;
    private WatermarkRepository $marks;
    private int $counter = 0;

    protected function setUp(): void
    {
        $this->db = TestEnv::connection();
        $this->db->execute('DELETE FROM workspaces');
        $this->db->execute('DELETE FROM users');
        $this->clock = new FakeClock('2026-10-04 12:00:00');
        $this->workspaces = new WorkspaceRepository($this->db, $this->clock);
        $this->media = new MediaRepository($this->db, $this->clock);
        $this->folders = new FolderRepository($this->db, $this->clock);
        $this->marks = new WatermarkRepository($this->db, $this->clock);
    }

    private function context(string $email): WorkspaceContext
    {
        $user = (new UserRepository($this->db, $this->clock))->create(['email' => $email, 'name' => $email, 'password_hash' => null]);
        self::assertNotNull($user);
        $workspace = $this->workspaces->create($user->id, 'WS ' . $email, 'Europe/Moscow');
        $membership = $this->workspaces->membership($workspace->id, $user->id);
        self::assertNotNull($membership);

        return WorkspaceContext::from($workspace, $membership);
    }

    private function add(WorkspaceContext $context, string $name, int $size = 100, MediaKind $kind = MediaKind::Image, ?int $folderId = null): Media
    {
        ++$this->counter;

        return $this->media->insert($context, [
            'uploader_id' => $context->userId, 'folder_id' => $folderId, 'kind' => $kind, 'original_name' => $name,
            'storage_key' => 'k' . $this->counter, 'thumb_key' => null, 'mime' => 'image/jpeg', 'size' => $size,
            'width' => 10, 'height' => 10, 'duration_ms' => null, 'codec' => null, 'animated' => false, 'sha256' => hash('sha256', (string) $this->counter),
        ], MediaRepository::newPublicId());
    }

    public function testEverythingIsScopedToTheWorkspace(): void
    {
        $a = $this->context('a@example.com');
        $b = $this->context('b@example.com');
        $mine = $this->add($a, 'mine.jpg', 300);
        $this->add($b, 'theirs.jpg', 5000);

        self::assertNotNull($this->media->find($a, $mine->publicId));
        self::assertNotNull($this->media->find($a, strtolower($mine->publicId)), 'ids are case-insensitive');
        self::assertNull($this->media->find($b, $mine->publicId));
        self::assertNull($this->media->findBySha($b, $mine->sha256));
        self::assertSame(300, $this->media->usedBytes($a));
        self::assertSame(5000, $this->media->usedBytes($b));
        self::assertSame(1, $this->media->page($a, null, null, '', 1)['total']);
        self::assertSame([], $this->media->findMany($b, [$mine->publicId]));
        self::assertSame(0, $this->media->move($b, [$mine->publicId], null));

        $this->media->delete($b, $mine);
        self::assertNotNull($this->media->find($a, $mine->publicId), 'a foreign context cannot delete');
        // Serving works before a context exists, and says which workspace owns the file.
        self::assertSame($a->workspaceId, (new MediaLookup($this->db))->find($mine->publicId)?->workspaceId);
        self::assertNull((new MediaLookup($this->db))->find('01JABCDEFGHJKMNPQRSTVWXYZ0'));
    }

    public function testFindManyKeepsTheRequestedOrder(): void
    {
        $a = $this->context('a@example.com');
        $one = $this->add($a, '1.jpg');
        $two = $this->add($a, '2.jpg');

        $found = $this->media->findMany($a, [$two->publicId, 'nope', $one->publicId]);

        self::assertSame([$two->publicId, $one->publicId], array_map(static fn (Media $m): string => $m->publicId, $found));
        self::assertSame([], $this->media->findMany($a, []));
    }

    public function testSearchTreatsWildcardsAsPlainCharacters(): void
    {
        $a = $this->context('a@example.com');
        $this->add($a, '100%_sale.jpg');
        $this->add($a, 'other.jpg');

        self::assertSame(1, $this->media->page($a, null, null, '%', 1)['total']);
        self::assertSame(1, $this->media->page($a, null, null, '_sale', 1)['total']);
        self::assertSame(0, $this->media->page($a, null, null, '%%%x', 1)['total']);
        self::assertSame(2, $this->media->page($a, null, null, '.jpg', 1)['total']);
        self::assertSame(1, $this->media->page($a, null, null, 'OTHER', 1)['total'], 'case-insensitive');
    }

    public function testPagesAreNewestFirstAndClamped(): void
    {
        $a = $this->context('a@example.com');
        for ($i = 1; $i <= 40; ++$i) {
            $this->add($a, sprintf('f%02d.jpg', $i));
        }

        $first = $this->media->page($a, null, null, '', 1);
        $beyond = $this->media->page($a, null, null, '', 99);

        self::assertSame(40, $first['total']);
        self::assertSame(2, $first['pages']);
        self::assertSame('f40.jpg', $first['rows'][0]->originalName);
        self::assertCount(MediaRepository::PER_PAGE, $first['rows']);
        self::assertSame(2, $beyond['page']);
        self::assertCount(4, $beyond['rows']);
        self::assertSame(0, $this->media->page($a, null, MediaKind::Video, '', 1)['total']);
    }

    public function testVariantsRoundTripAndBadJsonIsIgnored(): void
    {
        $a = $this->context('a@example.com');
        $media = $this->add($a, 'a.jpg');

        $this->media->setVariants($a, $media, ['abc' => ['key' => 'k_v_abc.jpg', 'size' => 5, 'width' => 2, 'height' => 3, 'mime' => 'image/jpeg']]);
        $loaded = $this->media->find($a, $media->publicId);
        self::assertSame(['key' => 'k_v_abc.jpg', 'size' => 5, 'width' => 2, 'height' => 3, 'mime' => 'image/jpeg'], $loaded?->variants['abc'] ?? null);

        $this->db->execute('UPDATE media SET variants_json = ? WHERE id = ?', ['{broken', $media->id]);
        self::assertSame([], $this->media->find($a, $media->publicId)?->variants);
        $this->media->setVariants($a, $media, []);
        self::assertNull($this->db->select('SELECT variants_json FROM media')[0]['variants_json']);
    }

    public function testSameHashTwiceInOneWorkspaceViolatesTheUniqueKey(): void
    {
        $a = $this->context('a@example.com');
        $first = $this->add($a, 'a.jpg');

        $this->expectException(\PDOException::class);
        $this->media->insert($a, [
            'uploader_id' => null, 'folder_id' => null, 'kind' => MediaKind::Image, 'original_name' => 'b.jpg', 'storage_key' => 'z', 'thumb_key' => null,
            'mime' => 'image/jpeg', 'size' => 1, 'width' => 1, 'height' => 1, 'duration_ms' => null, 'codec' => null, 'animated' => false, 'sha256' => $first->sha256,
        ], MediaRepository::newPublicId());
    }

    public function testFoldersAreScopedUniquePerWorkspaceAndKeepTheirFiles(): void
    {
        $a = $this->context('a@example.com');
        $b = $this->context('b@example.com');

        $folder = $this->folders->create($a, 'Акции');
        self::assertNotNull($folder);
        self::assertNull($this->folders->create($a, 'Акции'), 'duplicate names are refused');
        self::assertNotNull($this->folders->create($b, 'Акции'), 'another workspace may reuse the name');
        self::assertNull($this->folders->find($b, $folder->publicId));
        $item = $this->add($a, 'a.jpg', 10, MediaKind::Image, $folder->id);

        self::assertSame(1, $this->folders->all($a)[0]->count);
        $other = $this->folders->create($a, 'Лето');
        self::assertNotNull($other);
        self::assertFalse($this->folders->rename($a, $other, 'Акции'));
        self::assertTrue($this->folders->rename($a, $other, 'Зима'));
        self::assertTrue($this->folders->rename($a, $other, 'Зима'), 'keeping the same name is fine');
        self::assertSame(1, $this->media->page($a, $folder->id, null, '', 1)['total']);

        $this->folders->delete($b, $folder);
        self::assertNotNull($this->folders->find($a, $folder->publicId), 'another workspace cannot delete it');
        $this->folders->delete($a, $folder);
        self::assertNull($this->media->find($a, $item->publicId)?->folderId);
    }

    public function testWatermarkDefaultsAndHandOver(): void
    {
        $a = $this->context('a@example.com');
        $b = $this->context('b@example.com');

        $first = $this->marks->create($a, 'Первый', 'k1', 100, 50);
        $second = $this->marks->create($a, 'Второй', 'k2', 100, 50);
        $foreign = $this->marks->create($b, 'Чужой', 'k3', 100, 50);

        self::assertTrue($first->isDefault);
        self::assertFalse($second->isDefault);
        self::assertTrue($foreign->isDefault, 'each workspace has its own default');
        self::assertSame($first->publicId, $this->marks->default($a)?->publicId);
        self::assertNull($this->marks->find($a, $foreign->publicId));

        $this->marks->makeDefault($a, $second);
        self::assertSame($second->publicId, $this->marks->default($a)?->publicId);
        self::assertSame($foreign->publicId, $this->marks->default($b)?->publicId, 'making one default leaves other workspaces alone');

        $this->marks->delete($a, $second);
        self::assertSame($first->publicId, $this->marks->default($a)?->publicId);
        $this->marks->delete($a, $first);
        self::assertNull($this->marks->default($a));
        self::assertSame([], $this->marks->all($a));
        $this->marks->delete($a, $foreign);
        self::assertNotNull($this->marks->find($b, $foreign->publicId), 'a foreign context cannot delete');
    }
}
