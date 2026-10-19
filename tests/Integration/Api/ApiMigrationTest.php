<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Support\BillingTestCase;
use App\Tests\Support\TestEnv;

/** Upgrade over existing posts and subscriptions, reversible without disturbing other stage data. */
final class ApiMigrationTest extends BillingTestCase
{
    public function testUpgradeRollbackReplayKeepsExistingPublishingData(): void
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $this->payWithFake($ws, $owner);
        $ctx = $this->contextFor($ws, $owner);
        $post = $this->service()->saveDraft($ctx, null, $this->draft('Existing draft', []));
        $migration = require TestEnv::basePath() . '/database/migrations/2026_10_18_000044_create_public_api.php';
        $tables = ['api_tokens', 'api_idempotency', 'webhook_subscriptions', 'outgoing_webhook_events', 'webhook_deliveries', 'webhook_delivery_attempts'];
        self::assertSame('app_test', $this->db->select('SELECT DATABASE() AS name')[0]['name']);
        $migration->down($this->db);
        try {
            foreach ($tables as $table) {
                self::assertSame([], $this->db->select('SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]));
            }
        } finally {
            $migration->up($this->db);
        }
        self::assertSame($post->publicId, $this->db->select('SELECT public_id FROM posts WHERE id=?', [$post->id])[0]['public_id']);
        $token = $this->app->container()->get(\App\Domain\Api\ApiTokens::class)->create($ctx, 'After upgrade', ['posts:read']);
        self::assertSame($ctx->workspaceId, $this->app->container()->get(\App\Domain\Api\ApiTokens::class)->authenticate($token['secret'])->context->workspaceId);
        foreach ($tables as $table) {
            self::assertCount(1, $this->db->select('SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]));
        }
    }
}
