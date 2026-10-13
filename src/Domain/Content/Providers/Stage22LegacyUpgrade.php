<?php

declare(strict_types=1);

namespace App\Domain\Content\Providers;

use App\Kernel\Database\Connection;

/** Explicit compatibility upgrade for pre-merge development databases; never drops material history. */
final class Stage22LegacyUpgrade
{
    public function up(Connection $db): void
    {
        $columns = [
            'content_automation_settings' => ['orchestration_queued_at' => 'DATETIME(6) NULL', 'orchestration_due_at' => 'DATETIME(6) NULL'],
            'sources' => ['connection_version' => 'INT UNSIGNED NOT NULL DEFAULT 1'],
            'source_items' => ['connection_version' => 'INT UNSIGNED NOT NULL DEFAULT 1'],
            'source_selection_decisions' => ['revision_hash' => 'CHAR(64) NULL'],
            'source_video_generations' => ['lease_generation' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'error_category' => 'VARCHAR(64) NULL'],
            'semantic_selection_evaluations' => ['attempt_count' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'input_json' => 'JSON NULL', 'started_at' => 'DATETIME(6) NULL', 'error_category' => 'VARCHAR(64) NULL'],
            'source_text_processings' => ['error_category' => 'VARCHAR(64) NULL', 'retryable' => 'BOOLEAN NOT NULL DEFAULT FALSE'],
        ];
        foreach ($columns as $table => $definitions) {
            $present = array_column($db->select('SHOW COLUMNS FROM ' . $table), 'Field');
            foreach ($definitions as $column => $definition) {
                if (!in_array($column, $present, true)) {
                    $db->execute('ALTER TABLE ' . $table . ' ADD ' . $column . ' ' . $definition);
                }
            }
        }
        $this->bindLegacyDecisions($db);
        $this->connections($db);
        $this->collations($db);
        $db->execute("CREATE TABLE IF NOT EXISTS content_provider_usage (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, operation_key CHAR(64) NOT NULL, metric VARCHAR(40) NOT NULL, period_key VARCHAR(16) NOT NULL, created_at DATETIME(6) NOT NULL, UNIQUE KEY provider_operation (workspace_id, operation_key), FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** Preserve proven-current legacy results when replacing the timestamp-based decision identity. */
    private function bindLegacyDecisions(Connection $db): void
    {
        $cursor = 0;
        do {
            $rows = $db->select('SELECT * FROM source_selection_decisions WHERE revision_hash IS NULL AND id>? ORDER BY id LIMIT 100', [$cursor]);
            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                $db->transaction(function () use ($db, $row): void {
                    $row = $db->select('SELECT * FROM source_selection_decisions WHERE id=? FOR UPDATE', [$row['id']])[0];
                    if ($row['revision_hash'] !== null) {
                        return;
                    }
                    $item = $db->select('SELECT * FROM source_items WHERE workspace_id=? AND id=?', [$row['workspace_id'], $row['item_id']])[0] ?? null;
                    if ($item === null) {
                        return;
                    }
                    $messages = $db->select('SELECT message_id, text, entities_json, media_json, metadata_json, published_at, edited_at, revision_hash FROM source_messages WHERE workspace_id=? AND item_id=? ORDER BY message_id', [$row['workspace_id'], $row['item_id']]);
                    $revision = \App\Domain\Content\Processing\MaterialRepository::revision($item, $messages);
                    $legacy = $row;
                    unset($legacy['revision_hash']);
                    $oldHash = hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR));
                    $proof = $db->select("SELECT id FROM source_text_processings WHERE workspace_id=? AND item_id=? AND revision_hash=? AND selection_hash=? AND status='completed' LIMIT 1", [$row['workspace_id'], $row['item_id'], $revision, $oldHash]) !== [];
                    if ($row['decision_mode'] !== 'manual' || $proof) {
                        $row['revision_hash'] = $revision;
                    } else {
                        $row['selection_status'] = 'needs_review';
                        $row['reason'] = 'Подтвердите актуальную ревизию: прежнее ручное решение не содержит её идентификатора.';
                        $row['matched_rule'] = 'manual.revision_unverified';
                    }
                    $newHash = \App\Domain\Content\Processing\MaterialRepository::selectionHash($row);
                    $db->execute('UPDATE source_selection_decisions SET revision_hash=?, selection_status=?, reason=?, matched_rule=? WHERE workspace_id=? AND id=?', [$row['revision_hash'], $row['selection_status'], $row['reason'], $row['matched_rule'], $row['workspace_id'], $row['id']]);
                    if ($row['revision_hash'] === null) {
                        return;
                    }
                    foreach (['source_text_processings', 'source_image_processings', 'source_video_generations'] as $table) {
                        $db->execute('UPDATE ' . $table . ' SET selection_hash=? WHERE workspace_id=? AND item_id=? AND revision_hash=? AND selection_hash=?', [$newHash, $row['workspace_id'], $row['item_id'], $revision, $oldHash]);
                    }
                    $oldKey = hash('sha256', 'material:' . $row['item_id'] . ':' . $revision . ':' . $oldHash);
                    $key = hash('sha256', 'material:' . $row['item_id'] . ':' . $revision . ':' . $newHash);
                    // Copies keep their own identity; only the original export key changes.
                    $db->execute('UPDATE content_post_origins SET selection_hash=?, idempotency_key=IF(idempotency_key=?, ?, idempotency_key) WHERE workspace_id=? AND item_id=? AND revision_hash=? AND selection_hash=?', [$newHash, $oldKey, $key, $row['workspace_id'], $row['item_id'], $revision, $oldHash]);
                });
            }
        } while (count($rows) === 100);
    }

    private function connections(Connection $db): void
    {
        $columns = array_column($db->select('SHOW COLUMNS FROM source_messages'), 'Field');
        if (!in_array('connection_version', $columns, true)) {
            $db->execute('ALTER TABLE source_messages ADD connection_version INT UNSIGNED NOT NULL DEFAULT 1');
            $db->execute('UPDATE source_messages m JOIN source_items i ON i.id=m.item_id SET m.connection_version=i.connection_version');
        }
        foreach (['source_items' => ['uq_source_item', 'source_id, connection_version, peer_id, item_key'], 'source_messages' => ['uq_source_message', 'source_id, connection_version, peer_id, message_id']] as $table => [$index, $keys]) {
            $indices = $db->select('SHOW INDEX FROM ' . $table . ' WHERE Key_name=?', [$index]);
            if (count($indices) === 3) {
                $db->execute('ALTER TABLE ' . $table . ' DROP INDEX ' . $index . ', ADD UNIQUE KEY ' . $index . ' (' . $keys . ')');
            }
        }
        $columns = array_column($db->select('SHOW COLUMNS FROM content_automation_calls'), 'Field');
        if (!in_array('status', $columns, true)) {
            $db->execute("ALTER TABLE content_automation_calls ADD status VARCHAR(16) NOT NULL DEFAULT 'uncertain', ADD error_category VARCHAR(64) NULL, ADD attempts INT UNSIGNED NOT NULL DEFAULT 1, ADD available_at DATETIME(6) NULL, ADD result_json JSON NULL");
        }
    }
    private function collations(Connection $db): void
    {
        foreach ($db->select("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_COLLATION<>? AND (TABLE_NAME LIKE 'source\_%' OR TABLE_NAME='sources' OR TABLE_NAME LIKE 'semantic\_%' OR TABLE_NAME LIKE 'discovery\_%' OR TABLE_NAME LIKE 'content\_%')", ['utf8mb4_unicode_ci']) as $row) {
            $table = (string) $row['TABLE_NAME'];
            if (preg_match('/^[a-z_]+$/D', $table) !== 1) {
                throw new \LogicException('Unexpected table identifier');
            }
            $db->execute('ALTER TABLE ' . $table . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    }
}
