<?php

declare(strict_types=1);

namespace App\Tests\Feature\Media;

use App\Domain\Media\Media;
use App\Domain\Media\MediaUsageChecker;
use App\Domain\Workspace\Role;
use App\Domain\Workspace\WorkspaceContext;
use App\Http\Controllers\Media\FolderController;
use App\Http\Controllers\Media\MediaController;
use App\Tests\Support\MediaFixtures;
use App\Tests\Support\MediaTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MediaController::class)]
#[CoversClass(FolderController::class)]
final class MediaLibraryTest extends MediaTestCase
{
    public function testEmptyLibraryShowsAnInvitationToUpload(): void
    {
        [, $workspace] = $this->ownerSession();

        $page = $this->get($this->base($workspace) . '/media');

        self::assertSame(200, $page->status);
        $text = $this->text($page);
        self::assertStringContainsString('Медиатека пуста', $text);
        self::assertStringContainsString('Перетащите файлы сюда', $text);
        self::assertStringContainsString('data-upload-url="' . $this->base($workspace) . '/media/upload"', $page->body);
        self::assertStringContainsString('0 Б из 500 МБ', $text);
    }

    public function testGridListsFilesWithNamesAndThumbnails(): void
    {
        [, $workspace] = $this->ownerSession();
        $photo = $this->uploadOk($workspace, MediaFixtures::jpeg(), '<img src=x onerror=alert(1)>.jpg');
        $this->uploadOk($workspace, MediaFixtures::pdf(), 'price.pdf');

        $page = $this->get($this->base($workspace) . '/media');

        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;.jpg', $page->body, 'file names are escaped');
        self::assertStringNotContainsString('<img src=x', $page->body);
        self::assertStringContainsString('src="/media/' . $photo->publicId . '/thumb"', $page->body);
        self::assertStringContainsString('price.pdf', $page->body);
        self::assertStringNotContainsString($photo->storageKey, $page->body, 'storage keys never reach the page');
    }

    public function testSearchAndTypeFilter(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->uploadOk($workspace, MediaFixtures::jpeg(10, 10, 'red'), 'cat.jpg');
        $this->uploadOk($workspace, MediaFixtures::jpeg(10, 10, 'blue'), 'dog.jpg');
        $this->uploadOk($workspace, MediaFixtures::pdf(), 'cat-price.pdf');
        $base = $this->base($workspace) . '/media';

        $byName = $this->text($this->get($base . '?q=cat'));
        self::assertStringContainsString('cat.jpg', $byName);
        self::assertStringContainsString('cat-price.pdf', $byName);
        self::assertStringNotContainsString('dog.jpg', $byName);

        $docs = $this->text($this->get($base . '?kind=document'));
        self::assertStringContainsString('cat-price.pdf', $docs);
        self::assertStringNotContainsString('dog.jpg', $docs);

        $none = $this->text($this->get($base . '?q=' . rawurlencode('%')));
        self::assertStringContainsString('Ничего не нашли', $none);
        self::assertStringNotContainsString('cat.jpg', $none, 'LIKE wildcards are escaped');
    }

    public function testPagination(): void
    {
        [, $workspace] = $this->ownerSession();
        for ($i = 0; $i < 38; ++$i) {
            $this->db->execute(
                'INSERT INTO media (public_id, workspace_id, kind, original_name, storage_key, mime, size, sha256, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
                [sprintf('01J%023d', $i), $workspace->id, 'document', sprintf('doc-%02d.pdf', $i), 'k' . $i, 'application/pdf', 10, hash('sha256', (string) $i)],
            );
        }

        $first = $this->text($this->get($this->base($workspace) . '/media'));
        $second = $this->text($this->get($this->base($workspace) . '/media?page=2'));

        self::assertStringContainsString('Страница 1 из 2', $first);
        self::assertStringContainsString('doc-37.pdf', $first);
        self::assertStringNotContainsString('doc-00.pdf', $first);
        self::assertStringContainsString('doc-00.pdf', $second);
    }

    public function testFoldersCreateRenameFilterDelete(): void
    {
        [, $workspace] = $this->ownerSession();
        $base = $this->base($workspace) . '/media';

        $created = $this->post($base . '/folders', ['name' => '  Весенняя   акция ']);
        self::assertSame(302, $created->status);
        $folder = $this->db->select('SELECT public_id, name FROM media_folders')[0];
        self::assertSame('Весенняя акция', $folder['name']);
        self::assertSame($base . '?folder=' . $folder['public_id'], $created->header('Location'));

        $duplicate = $this->post($base . '/folders', ['name' => 'Весенняя акция']);
        self::assertSame(302, $duplicate->status);
        self::assertStringContainsString('уже есть', $this->get($base)->body);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM media_folders')[0]['c']);

        $this->post($base . '/folders', ['name' => '']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM media_folders')[0]['c'], 'an empty name creates nothing');

        $inFolder = $this->uploadOk($workspace, MediaFixtures::jpeg(10, 10, 'red'), 'in.jpg', (string) $folder['public_id']);
        $outside = $this->uploadOk($workspace, MediaFixtures::jpeg(10, 10, 'blue'), 'out.jpg');
        $listing = $this->text($this->get($base . '?folder=' . $folder['public_id']));
        self::assertStringContainsString('in.jpg', $listing);
        self::assertStringNotContainsString('out.jpg', $listing);

        $this->post($base . '/folders/' . $folder['public_id'] . '/rename', ['name' => 'Лето']);
        self::assertSame('Лето', $this->db->select('SELECT name FROM media_folders')[0]['name']);

        $this->post($base . '/folders/' . $folder['public_id'] . '/delete');
        self::assertSame([], $this->db->select('SELECT id FROM media_folders'));
        self::assertNull($this->db->select('SELECT folder_id FROM media WHERE public_id = ?', [$inFolder->publicId])[0]['folder_id'], 'files survive their folder');
        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM media')[0]['c']);
        self::assertSame($outside->sha256, $this->db->select('SELECT sha256 FROM media WHERE folder_id IS NULL AND original_name = ?', ['out.jpg'])[0]['sha256']);
    }

    public function testFoldersOfAnotherWorkspaceAreNotReachable(): void
    {
        [, $first] = $this->ownerSession();
        $this->post($this->base($first) . '/media/folders', ['name' => 'Секретная']);
        $folder = (string) $this->db->select('SELECT public_id FROM media_folders')[0]['public_id'];
        [$stranger, $second] = $this->ownerWithWorkspace('stranger@example.com', 'Чужой');
        $this->actAs($stranger);

        self::assertSame(404, $this->post($this->base($first) . '/media/folders/' . $folder . '/delete')->status);
        self::assertSame(404, $this->post($this->base($second) . '/media/folders/' . $folder . '/delete')->status);
        self::assertSame(404, $this->post($this->base($second) . '/media/folders/' . $folder . '/rename', ['name' => 'x'])->status);
        self::assertStringNotContainsString('Секретная', $this->get($this->base($second) . '/media?folder=' . $folder)->body);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM media_folders')[0]['c']);
    }

    public function testItemPageShowsFactsAndPlatformFit(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(1000, 100), 'strip.jpg');

        $page = $this->get($this->base($workspace) . '/media/' . $media->publicId);

        self::assertSame(200, $page->status);
        $text = $this->text($page);
        self::assertStringContainsString('strip.jpg', $text);
        self::assertStringContainsString('1000×100', $text);
        self::assertStringContainsString('Instagram: соотношение сторон фото должно быть от 4:5 до 1.91:1', $text);
        self::assertStringContainsString('Telegram — подходит', $text);
        self::assertStringContainsString('src="/media/' . $media->publicId . '/original"', $page->body);
    }

    public function testItemOfAnotherWorkspaceIsNotFound(): void
    {
        [, $first] = $this->ownerSession();
        $media = $this->uploadOk($first, MediaFixtures::jpeg(), 'a.jpg');
        [$stranger, $second] = $this->ownerWithWorkspace('stranger@example.com', 'Чужой');
        $this->actAs($stranger);

        self::assertSame(404, $this->get($this->base($first) . '/media/' . $media->publicId)->status);
        self::assertSame(404, $this->get($this->base($second) . '/media/' . $media->publicId)->status, 'known id, wrong workspace');
        self::assertSame(404, $this->post($this->base($second) . '/media/' . $media->publicId . '/delete')->status);
        self::assertSame(404, $this->post($this->base($second) . '/media/' . $media->publicId . '/rename', ['name' => 'x'])->status);
        self::assertSame(404, $this->post($this->base($second) . '/media/' . $media->publicId . '/move', ['folder' => ''])->status);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM media')[0]['c']);
        self::assertStringNotContainsString('a.jpg', $this->get($this->base($second) . '/media')->body);
    }

    public function testRenameAndMove(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');
        $this->post($this->base($workspace) . '/media/folders', ['name' => 'Папка']);
        $folder = (string) $this->db->select('SELECT public_id FROM media_folders')[0]['public_id'];
        $item = $this->base($workspace) . '/media/' . $media->publicId;

        $this->post($item . '/rename', ['name' => 'Новое имя.jpg']);
        self::assertSame('Новое имя.jpg', $this->db->select('SELECT original_name FROM media')[0]['original_name']);

        $this->post($item . '/move', ['folder' => $folder]);
        self::assertNotNull($this->db->select('SELECT folder_id FROM media')[0]['folder_id']);
        $this->post($item . '/move', ['folder' => '']);
        self::assertNull($this->db->select('SELECT folder_id FROM media')[0]['folder_id']);
        $this->post($item . '/move', ['folder' => '01JABCDEFGHJKMNPQRSTVWXYZ0']);
        self::assertNull($this->db->select('SELECT folder_id FROM media')[0]['folder_id'], 'a foreign folder is refused');
    }

    public function testDeleteRemovesRowAndAllStoredObjects(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(800, 400), 'a.jpg');
        $this->get('/media/' . $media->publicId . '/1x1'); // creates a cached variant
        self::assertCount(3, $this->storage->objects);

        $response = $this->post($this->base($workspace) . '/media/' . $media->publicId . '/delete');

        self::assertSame(302, $response->status);
        self::assertSame([], $this->db->select('SELECT id FROM media'));
        self::assertSame([], $this->storage->objects);
        self::assertContains('media.deleted', $this->auditActions($workspace));
        self::assertSame(404, $this->get('/media/' . $media->publicId . '/original')->status);
    }

    public function testDeleteIsRefusedWhileAScheduledPostUsesTheFile(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->usageChecker(new class () implements MediaUsageChecker {
            public function blockingReason(WorkspaceContext $context, Media $media): string
            {
                return 'Файл используется в запланированном посте на 12 октября.';
            }
        });
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');

        $this->post($this->base($workspace) . '/media/' . $media->publicId . '/delete');

        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM media')[0]['c']);
        self::assertCount(2, $this->storage->objects);
        self::assertStringContainsString('используется в запланированном посте', $this->get($this->base($workspace) . '/media/' . $media->publicId)->body);
    }

    public function testRolesSeeTheRightControls(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');
        $viewer = $this->memberOf($workspace, 'viewer@example.com', Role::Viewer);
        $author = $this->memberOf($workspace, 'author@example.com', Role::Author);
        $client = $this->memberOf($workspace, 'client@example.com', Role::Client);
        $base = $this->base($workspace) . '/media';

        $this->actAs($viewer);
        $viewerPage = $this->get($base)->body;
        self::assertStringNotContainsString('data-upload-input', $viewerPage);
        self::assertStringNotContainsString('Новая папка', $viewerPage);
        self::assertSame(403, $this->post($base . '/' . $media->publicId . '/delete')->status);
        self::assertSame(403, $this->post($base . '/folders', ['name' => 'x'])->status);
        self::assertSame(403, $this->get($base . '/watermarks')->status);

        $this->actAs($author);
        self::assertStringContainsString('data-upload-input', $this->get($base)->body);
        self::assertStringNotContainsString('Новая папка', $this->get($base)->body);
        self::assertSame(403, $this->post($base . '/' . $media->publicId . '/delete')->status);

        $this->actAs($client);
        self::assertSame(403, $this->get($base)->status);

        $this->useBrowser();
        self::assertSame(302, $this->get($base)->status);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM media')[0]['c']);
    }

    public function testSidebarHasAMediaLinkForRolesThatMayLook(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $client = $this->memberOf($workspace, 'client@example.com', Role::Client);

        $this->actAs($owner);
        self::assertStringContainsString('href="' . $this->base($workspace) . '/media"', $this->get($this->base($workspace))->body);
        $this->actAs($client);
        self::assertStringNotContainsString('/media"', $this->get($this->base($workspace))->body);
    }

    public function testMediaIsRemovedWithItsWorkspace(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');

        $this->db->execute('DELETE FROM workspaces WHERE id = ?', [$workspace->id]);

        self::assertSame([], $this->db->select('SELECT id FROM media'));
    }
}
