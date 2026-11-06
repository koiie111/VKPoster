<?php

declare(strict_types=1);

// Synthetic stage 23 screenshots only. Refuse any database other than app_test.
require '/var/www/html/vendor/autoload.php';

$fixture = new class ('stage23') extends \App\Tests\Support\SourceSelectionTestCase {
    public function fixture(): void
    {
        $this->setUp();
        if ($this->db->select('SELECT DATABASE() AS name')[0]['name'] !== 'app_test') {
            throw new \RuntimeException('Test database required');
        }
        [$owner, $workspace] = $this->ownerWithWorkspace('stage23-ui@example.test', 'Источники UI');
        $context = $this->contextFor($workspace, $owner);
        $this->app->container()->get(\App\Domain\Source\SourceService::class)->create($context, 'Новости технологий', 'telegram', '@stage23_example', true);
        $this->app->container()->get(\App\Domain\Source\ReaderAccounts::class)->create($workspace->id, 'Мой Telegram', $owner->id);
        $staff = $this->createUser('stage23-staff@example.test', true);
        $this->app->container()->get(\App\Domain\User\UserRepository::class)->setSuperadmin($staff->id, true);
        echo 'Stage 23 UI fixture ready', PHP_EOL;
    }
};
$fixture->fixture();
