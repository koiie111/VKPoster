<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migrator;
use App\Kernel\Env;

require dirname(__DIR__) . '/vendor/autoload.php';
$env = Env::fromProcess();
$name = $env->required('SCHEMA_CHECK_DATABASE');
if (preg_match('/^app_check_[a-z0-9_]+$/D', $name) !== 1) {
    throw new RuntimeException('Schema checks require a dedicated disposable app_check_* database.');
}
$db = new Connection($env->string('DB_HOST', 'mysql'), $env->int('DB_PORT', 3306), $name, $env->required('DB_USERNAME'), $env->string('DB_PASSWORD'));
$migrator = new Migrator($db, dirname(__DIR__) . '/database/migrations');
$baseline = null;
$baselineShape = null;
$upgraded = [];
$shape = static fn (Connection $connection): array => $connection->select('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION');
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--baseline=')) {
        $baseline = substr($argument, strlen('--baseline='));
    }
}
if ($baseline !== null) {
    (new Migrator($db, $baseline))->fresh();
    $baselineShape = $shape($db);
    $db->execute('INSERT INTO users (email, name, created_at, updated_at) VALUES (?, ?, NOW(6), NOW(6))', ['upgrade-fixture@example.test', 'Upgrade fixture']);
    $upgraded = $migrator->migrate();
    if (($db->select('SELECT name FROM users WHERE email = ?', ['upgrade-fixture@example.test'])[0]['name'] ?? null) !== 'Upgrade fixture') {
        throw new RuntimeException('Upgrade lost existing data.');
    }
    $db->execute('DELETE FROM users WHERE email = ?', ['upgrade-fixture@example.test']);
} else {
    $migrator->fresh();
}
$schema = $db->select('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.columns WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$name]);
$indexes = $db->select('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX', [$name]);
$json = json_encode(['columns' => $schema, 'indexes' => $indexes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$path = dirname(__DIR__) . '/docs/architecture/schema.json';
if (in_array('--write', $argv, true)) {
    file_put_contents($path, $json);
    $doc = "\n\n<!-- generated-schema:start -->\n## Полный каталог схемы (автоматический)\n\nПроверка: `scripts/check-schema.php`; машиночитаемые типы/индексы: [schema.json](schema.json).\n\n";
    $tables = [];
    foreach ($schema as $column) {
        $tables[(string) $column['TABLE_NAME']][] = (string) $column['COLUMN_NAME'];
    }
    foreach ($tables as $table => $columns) {
        $doc .= '- `' . $table . '`: `' . implode('`, `', $columns) . "`\n";
    }
    $doc .= "<!-- generated-schema:end -->\n";
    $md = dirname(__DIR__) . '/docs/architecture/database.md';
    $text = (string) file_get_contents($md);
    $text = preg_replace('/\s*<!-- generated-schema:start -->.*<!-- generated-schema:end -->\s*/s', '', $text) ?? $text;
    file_put_contents($md, $text . $doc);
} elseif (!is_file($path) || !hash_equals((string) file_get_contents($path), $json)) {
    throw new RuntimeException('Documented schema differs from migrations. Regenerate in the disposable schema check database.');
}
if ($baselineShape !== null && $upgraded !== []) {
    $reverted = $migrator->rollback();
    if (count($reverted) !== count($upgraded) || $shape($db) !== $baselineShape) {
        throw new RuntimeException('Upgrade rollback did not restore the baseline schema.');
    }
}
// rollback() intentionally reverts one batch; an upgrade creates a second batch.
// Validate every down() instead of mistaking the preserved baseline for orphan tables.
while ($migrator->rollback() !== []) {
}
if ($db->select('SHOW TABLES') !== [['Tables_in_' . $name => 'migrations']]) {
    throw new RuntimeException('Rollback left unexpected tables.');
}
$migrator->migrate();
$replayed = $db->select('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.columns WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$name]);
$replayedIndexes = $db->select('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX', [$name]);
if ($schema !== $replayed || $indexes !== $replayedIndexes) {
    throw new RuntimeException('Schema changed after rollback/replay.');
}
echo "PASS: fresh, schema documentation, full rollback and replay\n";
