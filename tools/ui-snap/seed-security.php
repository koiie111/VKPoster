<?php

declare(strict_types=1);

// Local, disposable UI fixture only; never accepts an owner/application database.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = \App\Kernel\Application::create(dirname(__DIR__, 2));
$db = $app->container()->get(\App\Kernel\Database\Connection::class);
if ($db->select('SELECT DATABASE() AS name')[0]['name'] !== 'app_test'
    || $app->container()->get(\App\Kernel\Config::class)->string('app.env') !== 'local') {
    throw new \LogicException('Disposable local app_test only.');
}
$users = $app->container()->get(\App\Domain\User\UserRepository::class);
$user = $users->findByEmail('demo@ezposter.local') ?? throw new \LogicException('Run local seed first.');
$users->clearTotp($user->id);
$users->setPendingTotp($user->id, $app->container()->get(\App\Kernel\Security\Crypto::class)->encrypt('JBSWY3DPEHPK3PXP'));
$users->enableTotp($user->id, (int) floor(time() / 30) - 1);
$db->execute('DELETE FROM recovery_codes WHERE user_id = ?', [$user->id]);
foreach (['c', 'd'] as $fixture) {
    $db->table('recovery_codes')->insert(['user_id' => $user->id, 'code_hash' => hash('sha256', str_repeat($fixture, 32)), 'created_at' => gmdate('Y-m-d H:i:s')]);
}
echo "Synthetic security fixture ready (no secret output).\n";
