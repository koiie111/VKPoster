<?php

declare(strict_types=1);

use App\Domain\Post\PublicationScheduler;
use App\Kernel\Application;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = Application::create(dirname(__DIR__, 2));
if (preg_match('/^app_load_[a-z0-9_]+$/D', $app->config()->string('database.database')) !== 1 || $app->config()->isProduction()) {
    throw new RuntimeException('Only isolated non-production app_load_* databases are allowed.');
}
$db = $app->container()->get(Connection::class);
$workspace = (int) ($db->select('SELECT w.id FROM workspaces w JOIN users u ON u.id=w.owner_id WHERE u.email=?', ['bench@ezposter.local'])[0]['id'] ?? 0);
if ($workspace === 0) {
    throw new RuntimeException('Prepare the fixture with bench:publish first.');
}
if (($argv[1] ?? '') === 'arm') {
    $done = (int) ($db->select("SELECT COUNT(*) AS n FROM publications WHERE workspace_id=? AND status <> 'queued'", [$workspace])[0]['n'] ?? 0);
    if ($done !== 0) {
        throw new RuntimeException('Cannot re-arm completed/in-flight publications. Prepare a new fixture.');
    }
    $db->execute("DELETE FROM jobs WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.class')) = ? AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.data.publication_id')) AS UNSIGNED) IN (SELECT id FROM publications WHERE workspace_id = ?)", [\App\Domain\Post\PublishJob::class, $workspace]);
    $due = DbTime::format($app->container()->get(Clock::class)->now()->modify('+10 seconds'));
    $db->execute('UPDATE publications SET due_at=?, run_at=?, enqueued_at=NULL WHERE workspace_id=?', [$due, $due, $workspace]);
    $db->execute('UPDATE posts SET scheduled_at=? WHERE workspace_id=?', [$due, $workspace]);
    echo json_encode(['enqueued' => $app->container()->get(PublicationScheduler::class)->enqueueDue(), 'due_at' => $due], JSON_THROW_ON_ERROR) . "\n";
} elseif (($argv[1] ?? '') === 'tick') {
    echo json_encode(['enqueued' => $app->container()->get(PublicationScheduler::class)->enqueueDue()], JSON_THROW_ON_ERROR) . "\n";
} elseif (($argv[1] ?? '') === 'status') {
    $rows = $db->select('SELECT status, due_at, sent_at FROM publications WHERE workspace_id=?', [$workspace]);
    $delays = [];
    $states = [];
    foreach ($rows as $row) {
        $states[(string) $row['status']] = ($states[(string) $row['status']] ?? 0) + 1;
        if ($row['status'] === 'sent' && is_string($row['sent_at']) && is_string($row['due_at'])) {
            $delays[] = (float) DbTime::parse($row['sent_at'])?->format('U.u') - (float) DbTime::parse($row['due_at'])?->format('U.u');
        }
    }
    sort($delays);
    $quantile = static fn (float $p): ?float => $delays === [] ? null : $delays[min(count($delays) - 1, (int) ceil($p * count($delays)) - 1)];
    echo json_encode(['count' => count($rows), 'states' => $states, 'p50' => $quantile(0.50), 'p95' => $quantile(0.95), 'p99' => $quantile(0.99)], JSON_THROW_ON_ERROR) . "\n";
} elseif (($argv[1] ?? '') === 'explain') {
    $now = $app->container()->get(Clock::class)->now();
    $sql = "SELECT id, run_at FROM publications WHERE status='queued' AND run_at <= ? AND (enqueued_at IS NULL OR GREATEST(enqueued_at, run_at) < ?) ORDER BY run_at,id LIMIT 500";
    $bindings = [DbTime::format($now->modify('+90 seconds')), DbTime::format($now->modify('-300 seconds'))];
    echo json_encode(['plan' => $db->select('EXPLAIN ' . $sql, $bindings), 'actual' => $db->select('EXPLAIN ANALYZE ' . $sql, $bindings)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} else {
    throw new InvalidArgumentException('Use arm, tick, status or explain.');
}
