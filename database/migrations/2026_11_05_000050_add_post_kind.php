<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute("ALTER TABLE posts ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'regular' AFTER status");
        $db->execute('CREATE INDEX idx_posts_kind ON posts (workspace_id, kind, scheduled_at)');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP INDEX idx_posts_kind ON posts');
        $db->execute('ALTER TABLE posts DROP COLUMN kind');
    }
};
