<?php

declare(strict_types=1);

namespace App\Domain\Source;

use App\Domain\Audit\AuditLog;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Kernel\Security\Crypto;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/** Encrypted Telegram reader identities and atomic source placement; never returns a session to a page. */
final class ReaderAccounts
{
    private const PURPOSE = 'telegram-reader-session';

    public function __construct(
        private readonly Connection $db,
        private readonly Crypto $crypto,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /** Creates a pending service account, or an account owned by exactly one workspace. */
    public function create(?int $workspaceId, string $label, int $actorId): string
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 80) {
            throw new SourceException('Название аккаунта: от 1 до 80 символов.', 'label');
        }
        $publicId = (string) new Ulid();
        $now = DbTime::format($this->clock->now());
        $this->db->transaction(function () use ($workspaceId, $label, $actorId, $publicId, $now): void {
            if ($workspaceId !== null) {
                $this->db->select('SELECT id FROM workspaces WHERE id=? FOR UPDATE', [$workspaceId]);
                if ($this->db->select('SELECT id FROM source_reader_accounts WHERE workspace_id=? LIMIT 1', [$workspaceId]) !== []) {
                    throw new SourceException('Аккаунт для чтения уже подключён к пространству.', 'account');
                }
            }
            $this->db->table('source_reader_accounts')->insert([
                'public_id' => $publicId, 'workspace_id' => $workspaceId, 'label' => $label,
                'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->record('source.reader_account_created', $actorId, 'reader_account', $publicId, ['scope' => $workspaceId === null ? 'service' : 'workspace'], $workspaceId);
        });
        return $publicId;
    }

    /** Stores only purpose-bound authenticated ciphertext; caller must have completed Telegram authorization. */
    public function attachSession(string $publicId, int $telegramUserId, #[\SensitiveParameter] string $session, int $actorId, ?int $workspaceId = null): void
    {
        if ($telegramUserId <= 0 || preg_match('/^1[A-Za-z0-9_-]{100,1000}$/D', $session) !== 1) {
            throw new SourceException('Сессия Telegram не прошла проверку.', 'session');
        }
        $ciphertext = $this->crypto->encrypt($session, self::PURPOSE);
        $this->db->transaction(function () use ($publicId, $telegramUserId, $ciphertext, $actorId, $workspaceId): void {
            $row = $this->account($publicId, $workspaceId, true);
            if ($row['status'] === 'disabled') {
                throw new SourceException('Сначала включите аккаунт для чтения.', 'account');
            }
            if ($row['telegram_user_id'] !== null && (int) $row['telegram_user_id'] !== $telegramUserId) {
                throw new SourceException('Этот аккаунт привязан к другому пользователю Telegram. Добавьте новый аккаунт для чтения.', 'session');
            }
            if ($row['session_enc'] !== null) {
                $this->db->execute('UPDATE sources SET connection_version=connection_version+1, status=?, updated_at=? WHERE reader_account_id=?', [SourceStatus::NotConnected->value, DbTime::format($this->clock->now()), $row['id']]);
            }
            $this->db->execute('UPDATE source_reader_accounts SET telegram_user_id=?, session_enc=?, status=?, updated_at=? WHERE id=?', [$telegramUserId, $ciphertext, 'active', DbTime::format($this->clock->now()), $row['id']]);
            $this->assignWaiting($workspaceId);
            $this->audit->record('source.reader_account_connected', $actorId, 'reader_account', $publicId, [], $workspaceId);
        });
    }

    /** A blocked service account fails over to another active service account; customer accounts never consume service capacity. */
    public function changeStatus(string $publicId, string $status, int $actorId, ?int $workspaceId = null): void
    {
        if (!in_array($status, ['active', 'blocked', 'disabled'], true)) {
            throw new SourceException('Неизвестное состояние аккаунта.', 'status');
        }
        $this->db->transaction(function () use ($publicId, $status, $actorId, $workspaceId): void {
            $row = $this->account($publicId, $workspaceId, true);
            if ($status === 'active' && $row['session_enc'] === null) {
                throw new SourceException('Сначала подключите аккаунт Telegram.', 'account');
            }
            $this->db->execute('UPDATE source_reader_accounts SET status=?, updated_at=? WHERE id=?', [$status, DbTime::format($this->clock->now()), $row['id']]);
            if ($status !== 'active') {
                $this->reassignFrom((int) $row['id'], $workspaceId === null);
            } else {
                $this->assignWaiting($workspaceId);
            }
            $this->audit->record('source.reader_account_status_changed', $actorId, 'reader_account', $publicId, ['status' => $status], $workspaceId);
        });
    }

    /** Assigns a newly created source without ever crossing the account owner's workspace. */
    public function assignNewSource(int $workspaceId, int $sourceId): void
    {
        $accountId = $this->pick($workspaceId);
        if ($accountId !== null) {
            $this->move($sourceId, $accountId);
        }
    }

    /** @return list<array<string, mixed>> Operational state without secrets. */
    public function list(?int $workspaceId = null): array
    {
        $filter = $workspaceId === null ? '' : ' WHERE a.workspace_id=?';
        return $this->db->select('SELECT a.id, a.public_id, a.workspace_id, a.label, a.status, a.telegram_user_id, a.last_seen_at, a.created_at, COUNT(s.id) AS source_count FROM source_reader_accounts a LEFT JOIN sources s ON s.reader_account_id=a.id' . $filter . ' GROUP BY a.id ORDER BY a.id', $workspaceId === null ? [] : [$workspaceId]);
    }

    /** @return list<array{id: string, telegram_user_id: int, session: string, sources: list<array<string, mixed>>}> */
    public function activeForReader(): array
    {
        $accounts = [];
        foreach ($this->db->select("SELECT id, public_id, telegram_user_id, session_enc FROM source_reader_accounts WHERE status='active' AND session_enc IS NOT NULL ORDER BY id") as $row) {
            $sources = $this->db->select("SELECT public_id AS id, telegram_username AS username, connection_version FROM sources WHERE reader_account_id=? AND enabled=1 AND type='telegram' ORDER BY id", [$row['id']]);
            $accounts[] = [
                'id' => (string) $row['public_id'],
                'telegram_user_id' => (int) $row['telegram_user_id'],
                'session' => $this->crypto->decrypt((string) $row['session_enc'], self::PURPOSE),
                'sources' => $sources,
            ];
        }
        return $accounts;
    }

    /** A terminal Telegram authorization error invalidates this identity and moves service sources. */
    public function reportBlocked(string $publicId): void
    {
        $this->db->transaction(function () use ($publicId): void {
            $rows = $this->db->select('SELECT id, workspace_id, status FROM source_reader_accounts WHERE public_id=? FOR UPDATE', [strtoupper($publicId)]);
            $row = $rows[0] ?? throw new HttpException(404, 'Reader account not found');
            if ($row['status'] !== 'active') {
                return;
            }
            $this->db->execute('UPDATE source_reader_accounts SET status=?, updated_at=? WHERE id=?', ['blocked', DbTime::format($this->clock->now()), $row['id']]);
            $this->reassignFrom((int) $row['id'], $row['workspace_id'] === null);
            $this->audit->record('source.reader_account_blocked', null, 'reader_account', $publicId, [], $row['workspace_id'] === null ? null : (int) $row['workspace_id']);
        });
    }

    /** @return array<string, mixed> */
    private function account(string $publicId, ?int $workspaceId, bool $lock): array
    {
        $rows = $this->db->select('SELECT * FROM source_reader_accounts WHERE public_id=? AND workspace_id <=> ?' . ($lock ? ' FOR UPDATE' : ''), [strtoupper($publicId), $workspaceId]);
        return $rows[0] ?? throw new HttpException(404, 'Not found');
    }

    private function pick(int $workspaceId): ?int
    {
        $own = $this->db->select('SELECT id, status FROM source_reader_accounts WHERE workspace_id=? LIMIT 1 FOR UPDATE', [$workspaceId]);
        if ($own !== []) {
            return $own[0]['status'] === 'active' ? (int) $own[0]['id'] : null;
        }
        $service = $this->db->select("SELECT a.id FROM source_reader_accounts a LEFT JOIN sources s ON s.reader_account_id=a.id WHERE a.workspace_id IS NULL AND a.status='active' GROUP BY a.id ORDER BY COUNT(s.id), a.id LIMIT 1 FOR UPDATE");
        return $service === [] ? null : (int) $service[0]['id'];
    }

    private function move(int $sourceId, ?int $accountId): void
    {
        $this->db->execute('UPDATE sources SET reader_account_id=?, connection_version=connection_version+1, status=?, updated_at=? WHERE id=? AND NOT (reader_account_id <=> ?)', [$accountId, SourceStatus::NotConnected->value, DbTime::format($this->clock->now()), $sourceId, $accountId]);
    }

    private function reassignFrom(int $accountId, bool $service): void
    {
        foreach ($this->db->select('SELECT id, workspace_id FROM sources WHERE reader_account_id=? ORDER BY id FOR UPDATE', [$accountId]) as $source) {
            $target = $service ? $this->pickServiceExcluding($accountId) : null;
            $this->move((int) $source['id'], $target);
            if ($target === null) {
                $this->db->execute('UPDATE sources SET status=? WHERE id=?', [SourceStatus::Error->value, $source['id']]);
            }
        }
    }

    private function pickServiceExcluding(int $excluded): ?int
    {
        $rows = $this->db->select("SELECT a.id FROM source_reader_accounts a LEFT JOIN sources s ON s.reader_account_id=a.id WHERE a.workspace_id IS NULL AND a.status='active' AND a.id<>? GROUP BY a.id ORDER BY COUNT(s.id), a.id LIMIT 1 FOR UPDATE", [$excluded]);
        return $rows === [] ? null : (int) $rows[0]['id'];
    }

    private function assignWaiting(?int $workspaceId): void
    {
        $sql = $workspaceId === null
            ? 'SELECT id, workspace_id FROM sources WHERE reader_account_id IS NULL AND enabled=1 ORDER BY id FOR UPDATE'
            : 'SELECT id, workspace_id FROM sources WHERE workspace_id=? AND enabled=1 ORDER BY id FOR UPDATE';
        foreach ($this->db->select($sql, $workspaceId === null ? [] : [$workspaceId]) as $source) {
            $target = $this->pick((int) $source['workspace_id']);
            if ($target !== null) {
                $this->move((int) $source['id'], $target);
            }
        }
    }
}
