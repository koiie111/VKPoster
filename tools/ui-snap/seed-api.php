<?php

declare(strict_types=1);

// Disposable app_test only. No real accounts, secrets, endpoint deliveries or consents are touched.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$fixture = new class ('fixture') extends \App\Tests\Support\BillingTestCase {
    public function fixture(): void
    {
        $this->setUp();
        if ($this->db->select('SELECT DATABASE() AS name')[0]['name'] !== 'app_test') {
            throw new \LogicException('Disposable database required');
        }
        [$owner, $ws] = $this->ownerWithWorkspace('api-ui@example.com', 'Интеграции UI');
        $this->payWithFake($ws, $owner);
        $ctx = $this->contextFor($ws, $owner);
        $c = $this->app->container();
        $c->instance(\App\Kernel\HttpClient\SsrfGuard::class, new \App\Kernel\HttpClient\SsrfGuard(static fn (string $host): array => ['93.184.216.34']));
        $c->get(\App\Domain\Api\ApiTokens::class)->create($ctx, 'n8n — редакционный календарь', ['posts:read', 'posts:write'], $this->clock->now()->modify('+30 days'));
        $c->get(\App\Domain\Webhook\Webhooks::class)->create($ctx, 'https://receiver.example.com/events', ['post.published', 'approval.*']);
        $c->get(\App\Domain\Webhook\WebhookOutbox::class)->capture($ws->id, 'post.published', ['post_id' => (string) new \Symfony\Component\Uid\Ulid()], 'ui-fixture');
        $c->get(\App\Domain\Webhook\WebhookDispatcher::class)->tick();
        $this->db->execute("UPDATE webhook_deliveries SET status='failed',attempts=5,response_status=503,error_code='http_error',completed_at=next_attempt_at");
        [$free, $freeWs] = $this->ownerWithWorkspace('api-free-ui@example.com', 'Без интеграций');
        $this->db->execute('DELETE FROM subscriptions WHERE workspace_id=?', [$freeWs->id]);
        [$empty, $emptyWs] = $this->ownerWithWorkspace('api-empty-ui@example.com', 'Первое подключение');
        $this->payWithFake($emptyWs, $empty);
        $staff = $this->createUser('api-staff-ui@example.com', true, null, 'Сотрудник UI');
        $c->get(\App\Domain\User\UserRepository::class)->setSuperadmin($staff->id, true);
        file_put_contents('storage/testing/api-ui.json', json_encode(['workspace' => $ws->publicId], JSON_THROW_ON_ERROR));
        echo 'Synthetic API UI fixture ready.', PHP_EOL;
    }
};
$fixture->fixture();
