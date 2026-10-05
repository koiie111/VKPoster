<?php

declare(strict_types=1);

namespace App\Tests\Feature\Site;

use App\Domain\Legal\LegalDocuments;
use App\Http\Controllers\Account\ConsentController;
use App\Http\Middleware\RequireConsent;
use App\Tests\Support\WorkspaceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Re-consent: when the legal documents change, a signed-in person must agree again before using the application.
 */
#[CoversClass(RequireConsent::class)]
#[CoversClass(ConsentController::class)]
#[CoversClass(LegalDocuments::class)]
final class ConsentTest extends WorkspaceTestCase
{
    public function testOutdatedConsentRedirectsToTheConsentPageAndAcceptingRestoresAccess(): void
    {
        [$user] = $this->ownerWithWorkspace();
        $this->actAs($user);
        self::assertSame(200, $this->getApp()->status);

        $this->db->execute("UPDATE users SET consent_version = '2020-01-01' WHERE id = ?", [$user->id]);
        $blocked = $this->get('/app');
        self::assertSame('/consent', $blocked->header('Location'));
        self::assertStringContainsString('Мы обновили документы', $this->get('/consent')->body);

        $refused = $this->post('/consent', []);
        self::assertSame('/consent', $refused->header('Location'));
        self::assertStringContainsString('нужно согласиться', $this->get('/consent')->body);

        $accepted = $this->post('/consent', ['consent' => '1']);
        self::assertSame('/app', $accepted->header('Location'));
        $version = $this->app->container()->get(LegalDocuments::class)->consentVersion();
        $row = $this->db->select('SELECT consent_version FROM users WHERE id = ?', [$user->id])[0];
        self::assertSame($version, $row['consent_version']);
        self::assertCount(2, $this->db->select('SELECT 1 FROM user_consents WHERE user_id = ? AND version = ?', [$user->id, $version]));
        self::assertSame(200, $this->getApp()->status);
    }

    public function testConsentPageIsClosedToGuests(): void
    {
        $this->useBrowser();
        self::assertSame('/login', $this->get('/consent')->header('Location'));
    }

    public function testRegistrationStoresTheCurrentVersionInTheHistory(): void
    {
        $this->post('/register', ['name' => 'Мария', 'email' => 'm@example.com', 'password' => 'a-long-unusual-passphrase', 'consent' => '1']);
        $version = $this->app->container()->get(LegalDocuments::class)->consentVersion();

        self::assertCount(1, $this->db->select('SELECT 1 FROM user_consents WHERE version = ?', [$version]));
    }

    public function testConsentVersionIsTheNewestOfTheRequiredDocuments(): void
    {
        $dir = sys_get_temp_dir() . '/legal-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/offer.md', "---\ntitle: A\nversion: 2026-01-01\nrequired: yes\n---\nтекст");
        file_put_contents($dir . '/privacy.md', "---\ntitle: B\nversion: 2026-03-05\nrequired: yes\n---\nтекст [[ПУСТО]]");
        file_put_contents($dir . '/cookies.md', "---\ntitle: C\nversion: 2027-01-01\nrequired: no\n---\nтекст");
        $documents = new LegalDocuments($dir);

        self::assertSame('2026-03-05', $documents->consentVersion());
        self::assertTrue($documents->find('privacy')?->isDraft());
        self::assertFalse($documents->find('offer')?->isDraft());
        self::assertCount(2, $documents->required());
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
