<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\SessionRegistry;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Integrations\Storage\MediaStorage;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/**
 * Requests about a person's data under 152-FZ. A request is a row of `data_requests` (it outlives the account). Answering one is either a
 * copy of that person's data (JSON, or a ZIP with a readme) or deleting the account in the way the law allows: the person is made anonymous
 * and their content and connections are removed, while the financial records the law requires us to keep (invoices, payments, the money
 * journal) stay, tied to a workspace that no longer says who it was.
 *
 * The export holds only data about this person: rows are selected by their id, never by a search. Tokens and secrets are not exported.
 */
final class PersonalData
{
    public const TYPES = ['export' => 'Выгрузка данных', 'delete' => 'Удаление данных'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly UserRepository $users,
        private readonly SessionRegistry $sessions,
        private readonly MediaStorage $storage,
        private readonly AuditLog $audit,
        private readonly StaffAccess $staff,
    ) {
    }

    /**
     * Record a request. The person is found by id or email; a request about nobody known is still recorded (the answer is "no data").
     */
    public function open(string $reference, string $type, string $note, User $by): string
    {
        $reference = trim($reference);
        $person = ctype_digit($reference) ? $this->users->find((int) $reference) : ($reference === '' ? null : $this->users->findByEmail($reference));
        $publicId = (string) new Ulid();
        $this->db->table('data_requests')->insert([
            'public_id' => $publicId,
            'user_id' => $person?->id,
            'email' => ($person === null ? null : $person->email) ?? (str_contains($reference, '@') ? mb_substr($reference, 0, 254) : null),
            'type' => isset(self::TYPES[$type]) ? $type : 'export',
            'status' => 'open',
            'note' => $note === '' ? null : mb_substr($note, 0, 1000),
            'created_by' => $by->id,
            'created_at' => DbTime::format($this->clock->now()),
        ]);

        return $publicId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requests(?string $status = null): array
    {
        $rows = $status === null
            ? $this->db->select('SELECT * FROM data_requests ORDER BY id DESC LIMIT 200')
            : $this->db->select('SELECT * FROM data_requests WHERE status = ? ORDER BY id DESC LIMIT 200', [$status]);

        return array_map(static function (array $r): array {
            $r['created_at'] = DbTime::parse($r['created_at']);
            $r['completed_at'] = DbTime::parse($r['completed_at']);

            return $r;
        }, $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $publicId): ?array
    {
        $rows = $this->db->select('SELECT * FROM data_requests WHERE public_id = ?', [$publicId]);

        return $rows === [] ? null : $rows[0];
    }

    public function complete(string $publicId, string $status, User $by): void
    {
        $this->db->execute(
            'UPDATE data_requests SET status = ?, completed_by = ?, completed_at = ? WHERE public_id = ? AND status = \'open\'',
            [$status === 'rejected' ? 'rejected' : 'done', $by->id, DbTime::format($this->clock->now()), $publicId],
        );
    }

    /**
     * Everything we hold about the person, as plain data.
     *
     * @return array<string, mixed>
     */
    public function export(int $userId): array
    {
        $rows = static fn (array $list): array => array_map(static fn (array $r): array => $r, $list);
        $user = $this->db->select('SELECT id, email, email_verified_at, name, locale, timezone, totp_enabled_at, status, consent_version, consent_at, created_at FROM users WHERE id = ?', [$userId]);
        $workspaces = $this->db->select('SELECT w.id, w.public_id, w.name, m.role, m.joined_at, (w.owner_id = m.user_id) AS is_owner FROM workspace_members m JOIN workspaces w ON w.id = m.workspace_id WHERE m.user_id = ?', [$userId]);
        $owned = array_map(static fn (array $w): int => (int) $w['id'], array_filter($workspaces, static fn (array $w): bool => (int) $w['is_owner'] === 1));
        $in = $owned === [] ? '0' : implode(',', array_map('intval', $owned));

        return [
            'exported_at' => $this->clock->now()->format(DATE_ATOM),
            'about' => 'Данные одного человека: аккаунт, способы входа, пространства, содержимое, платежи и действия. Токены и пароли не выгружаются.',
            'account' => $user[0] ?? null,
            'sign_in_methods' => $rows($this->db->select('SELECT provider, email, display_name, linked_at, last_login_at FROM user_identities WHERE user_id = ?', [$userId])),
            'devices' => $rows($this->db->select('SELECT ip, user_agent, created_at, last_seen_at, revoked_at FROM user_sessions WHERE user_id = ?', [$userId])),
            'consents' => $rows($this->db->select('SELECT version, ip, accepted_at FROM user_consents WHERE user_id = ?', [$userId])),
            'workspaces' => $rows($workspaces),
            'channels' => $rows($this->db->select("SELECT platform, title, status, created_at FROM channels WHERE workspace_id IN ($in)")),
            'posts' => $rows($this->db->select('SELECT public_id, status, base_text, scheduled_at, published_at, created_at FROM posts WHERE author_id = ? ORDER BY id', [$userId])),
            'media' => $rows($this->db->select('SELECT original_name, mime, size, created_at FROM media WHERE uploader_id = ?', [$userId])),
            'notifications' => $rows($this->db->select('SELECT type, title, body, created_at, read_at FROM notifications WHERE user_id = ?', [$userId])),
            'invoices' => $rows($this->db->select("SELECT number, kind, amount, currency, status, description, customer_email, period_start, period_end, paid_at FROM invoices WHERE workspace_id IN ($in)")),
            'payments' => $rows($this->db->select("SELECT provider, status, amount, currency, refunded_amount, created_at FROM payments WHERE workspace_id IN ($in)")),
            'activity' => $rows($this->db->select('SELECT action, subject_type, subject_id, ip, created_at FROM audit_log WHERE actor_id = ? ORDER BY id', [$userId])),
            'came_from' => $this->db->select('SELECT utm_source, utm_medium, utm_campaign, referrer, landing, first_seen_at FROM user_attribution WHERE user_id = ?', [$userId])[0] ?? null,
        ];
    }

    /**
     * The export as JSON text.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The export as a ZIP archive (`data.json` and a short readme).
     *
     * @param array<string, mixed> $data
     * @return string the bytes of the archive
     */
    public static function zip(array $data): string
    {
        $file = tempnam(sys_get_temp_dir(), 'export');
        if ($file === false) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('data.json', self::json($data));
        $zip->addFromString('README.txt', "Выгрузка данных человека (152-ФЗ).\nФайл data.json содержит данные в формате JSON: аккаунт, способы входа, пространства, каналы, посты, платежи и действия.\nПароли и токены не выгружаются.\n");
        $zip->close();
        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    /**
     * Delete the account in the way the law allows (see the class description). Refused for staff, and for a person who owns a workspace
     * that other people still work in (the ownership has to be handed over first).
     *
     * @return string|null why it was refused
     */
    public function anonymize(User $target, User $by): ?string
    {
        if ($this->staff->isStaff($target)) {
            return 'Сотрудника анонимизировать нельзя: сначала снимите роль.';
        }
        if ($target->status === User::STATUS_BLOCKED && $target->email === null) {
            return 'Этот аккаунт уже анонимизирован.';
        }
        $shared = $this->db->select(
            'SELECT w.name FROM workspaces w WHERE w.owner_id = ? AND (SELECT COUNT(*) FROM workspace_members m WHERE m.workspace_id = w.id) > 1 LIMIT 1',
            [$target->id],
        );
        if ($shared !== []) {
            return 'Человек владеет пространством «' . $shared[0]['name'] . '», где работают другие. Сначала нужно передать владение.';
        }
        $owned = array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM workspaces WHERE owner_id = ?', [$target->id]));
        $before = ['email' => $target->email === null ? 'none' : 'set', 'name' => 'set'];
        foreach ($owned as $workspaceId) {
            $this->wipeContent($workspaceId);
        }
        $this->db->transaction(function (Connection $db) use ($target, $owned): void {
            $now = DbTime::format($this->clock->now());
            $db->execute(
                'UPDATE users SET email = NULL, email_verified_at = NULL, password_hash = NULL, name = ?, totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = NULL, '
                . "status = 'blocked', block_reason = ?, updated_at = ? WHERE id = ?",
                ['Удалённый пользователь', 'Аккаунт удалён по запросу владельца данных', $now, $target->id],
            );
            foreach (['user_identities', 'user_sessions', 'recovery_codes', 'auth_tokens', 'telegram_links', 'telegram_link_tokens', 'notifications', 'notification_settings', 'admin_notes', 'user_attribution', 'user_activity_days'] as $table) {
                $db->execute('DELETE FROM ' . $table . ' WHERE user_id = ?', [$target->id]);
            }
            $db->execute('DELETE FROM workspace_members WHERE user_id = ? AND workspace_id NOT IN (SELECT id FROM (SELECT id FROM workspaces WHERE owner_id = ?) o)', [$target->id, $target->id]);
            $db->execute('UPDATE audit_log SET ip = NULL WHERE actor_id = ?', [$target->id]);
            foreach ($owned as $workspaceId) {
                $db->execute('UPDATE workspaces SET name = ? WHERE id = ?', ['Удалённое пространство', $workspaceId]);
                $db->execute('DELETE FROM payment_methods WHERE workspace_id = ?', [$workspaceId]);
                $db->execute('UPDATE subscriptions SET cancel_at_period_end = 1, payment_method_id = NULL, next_renewal_attempt_at = NULL WHERE workspace_id = ?', [$workspaceId]);
                $db->execute('DELETE FROM platform_credentials WHERE workspace_id = ?', [$workspaceId]);
            }
        });
        $this->sessions->revokeAll($target->id);
        $this->audit->record('admin.account_anonymized', $by->id, 'user', (string) $target->id, ['before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => 'anonymous', 'workspaces' => count($owned)]);

        return null;
    }

    /**
     * Remove what the person put into a workspace of theirs: posts (with their variants and publications), library files and the channels.
     */
    private function wipeContent(int $workspaceId): void
    {
        foreach ($this->db->select('SELECT storage_key, thumb_key, variants_json FROM media WHERE workspace_id = ?', [$workspaceId]) as $row) {
            $keys = [(string) $row['storage_key'], is_string($row['thumb_key']) ? $row['thumb_key'] : null];
            $variants = is_string($row['variants_json']) ? json_decode($row['variants_json'], true) : [];
            foreach (is_array($variants) ? $variants : [] as $variant) {
                $keys[] = is_array($variant) && is_string($variant['key'] ?? null) ? $variant['key'] : null;
            }
            foreach ($keys as $key) {
                if ($key === null || $key === '') {
                    continue;
                }
                try {
                    $this->storage->delete($key);
                } catch (\Throwable) {
                    // A file that is already gone is the goal; a storage hiccup must not leave the account half-deleted.
                }
            }
        }
        $this->db->execute('DELETE FROM media WHERE workspace_id = ?', [$workspaceId]);
        $this->db->execute('DELETE FROM posts WHERE workspace_id = ?', [$workspaceId]);
        $this->db->execute('DELETE FROM post_templates WHERE workspace_id = ?', [$workspaceId]);
        $this->db->execute('DELETE FROM channels WHERE workspace_id = ?', [$workspaceId]);
    }
}
