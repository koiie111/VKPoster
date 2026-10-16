<?php

declare(strict_types=1);

// Disposable app_test only. No real channels, credentials, posts or consent are touched.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$fixture = new class ('fixture') extends \App\Tests\Support\BillingTestCase {
    public function fixture(): void
    {
        $this->setUp();
        if ($this->db->select('SELECT DATABASE() AS name')[0]['name'] !== 'app_test') {
            throw new \LogicException('Disposable database required');
        }
        [$owner,$ws] = $this->ownerWithWorkspace('analytics-ui@example.com', 'Аналитика UI');
        $this->payWithFake($ws, $owner, 'agency');
        $ctx = $this->contextFor($ws, $owner);
        $channel = $this->fakeChannel($ws, $owner, 'analytics-ui', 'Технологии');
        $start = $this->clock->now()->modify('-5 days');
        $ids = [];
        for ($i = 0; $i < 20; ++$i) {
            $this->clock->set($start->modify('+' . $i . ' minutes')->format('Y-m-d H:i:s'));
            [$post] = $this->scheduled($ctx, [$channel], '+1 minute', 'Обновления продукта: исследование ' . ($i + 1));
            $this->clock->advance(61);
            $this->drain();
            $ids[] = $post->publicId;
        }
        $this->clock->set(gmdate('Y-m-d H:i:s'));
        $collector = $this->app->container()->get(\App\Domain\Analytics\StatsCollector::class);
        $collector->tick();
        $worker = $this->app->container()->get(\App\Kernel\Queue\Worker::class);
        for ($i = 0; $i < 200 && $worker->runNext('default', 'analytics-ui'); ++$i) {
        }
        $guest = $this->app->container()->get(\App\Domain\Approval\GuestLinks::class)->create($ctx, array_slice($ids, 0, 5), 72);
        file_put_contents('storage/testing/analytics-ui.json', json_encode(['workspace' => $ws->publicId,'guest' => $guest], JSON_THROW_ON_ERROR));
        echo 'Synthetic analytics fixture ready.', PHP_EOL;
    }
};
$fixture->fixture();
