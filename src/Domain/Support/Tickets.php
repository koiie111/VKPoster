<?php

declare(strict_types=1);

namespace App\Domain\Support;

use App\Domain\Notification\MailComposer;
use App\Domain\Notification\SendTelegramNotificationJob;
use App\Domain\User\User;
use App\Kernel\Database\Connection;
use App\Kernel\Queue\Queue;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/**
 * Customer support tickets. A ticket comes from the "report a problem" form (answered by email) or from a private chat with the shared
 * Telegram bot (answered in that chat), and carries the context the service already knows (plan, the latest failed publications), so support
 * does not have to ask. Staff reply, leave internal notes (never sent), change the status and assign the ticket.
 * Statuses: `open` (waiting for us), `pending` (we answered, waiting for the customer), `solved`.
 */
final class Tickets
{
    public const STATUSES = ['open' => 'Ждёт ответа', 'pending' => 'Ждём клиента', 'solved' => 'Решён'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly MailComposer $mail,
        private readonly Queue $queue,
    ) {
    }

    /**
     * Open a ticket with the customer's first message. Returns the public id.
     *
     * @param array<string, scalar|null> $context
     */
    public function open(?User $user, ?string $email, string $subject, string $body, string $source, array $context = [], ?int $telegramChatId = null): string
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $id = (int) $this->db->table('support_tickets')->insert([
            'public_id' => $publicId,
            'user_id' => $user?->id,
            'email' => $email ?? $user?->email,
            'subject' => mb_substr(trim($subject) === '' ? 'Без темы' : trim($subject), 0, 200),
            'status' => 'open',
            'source' => in_array($source, ['form', 'telegram', 'email'], true) ? $source : 'form',
            'context_json' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'telegram_chat_id' => $telegramChatId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->addMessage($id, 'customer', $user?->id, $body, null);

        return $publicId;
    }

    /**
     * A message from a private Telegram chat: it continues the chat's open ticket or starts a new one.
     *
     * @param array<string, scalar|null> $context
     */
    public function fromTelegram(User $user, int $chatId, string $text, array $context = []): string
    {
        $text = mb_substr(trim($text), 0, 4000);
        $open = $this->db->select("SELECT id, public_id FROM support_tickets WHERE telegram_chat_id = ? AND status <> 'solved' ORDER BY id DESC LIMIT 1", [$chatId]);
        if ($open !== []) {
            $this->addMessage((int) $open[0]['id'], 'customer', $user->id, $text, null);
            $this->db->execute("UPDATE support_tickets SET status = 'open', updated_at = ? WHERE id = ?", [DbTime::format($this->clock->now()), $open[0]['id']]);

            return (string) $open[0]['public_id'];
        }

        return $this->open($user, $user->email, mb_substr((string) preg_replace('/\s+/', ' ', $text), 0, 80), $text, 'telegram', $context, $chatId);
    }

    /**
     * What the service knows about a person when they write: their plan and the latest publications that failed.
     *
     * @return array<string, scalar|null>
     */
    public function contextFor(User $user): array
    {
        $plan = $this->db->select(
            'SELECT p.name, s.status FROM workspaces w JOIN subscriptions s ON s.workspace_id = w.id JOIN plans p ON p.id = s.plan_id WHERE w.owner_id = ? ORDER BY p.sort DESC LIMIT 1',
            [$user->id],
        )[0] ?? null;
        $errors = $this->db->select(
            "SELECT v.platform, p.error_code, p.error_message, p.due_at FROM publications p JOIN post_variants v ON v.id = p.variant_id JOIN workspaces w ON w.id = p.workspace_id WHERE w.owner_id = ? AND p.status IN ('failed', 'unknown') ORDER BY p.id DESC LIMIT 3",
            [$user->id],
        );
        $context = ['plan' => $plan === null ? 'нет' : $plan['name'] . ' (' . $plan['status'] . ')', 'registered' => $user->createdAt->format('Y-m-d')];
        foreach ($errors as $i => $row) {
            $context['error_' . ($i + 1)] = $row['platform'] . ': ' . ($row['error_code'] ?? '') . ' ' . mb_substr((string) $row['error_message'], 0, 120);
        }

        return $context;
    }

    public function addMessage(int $ticketId, string $kind, ?int $authorId, string $body, ?string $deliveredVia): void
    {
        $this->db->table('support_messages')->insert([
            'ticket_id' => $ticketId,
            'kind' => $kind,
            'author_id' => $authorId,
            'body' => mb_substr(trim($body), 0, 5000),
            'delivered_via' => $deliveredVia,
            'created_at' => DbTime::format($this->clock->now()),
        ]);
        $this->db->execute('UPDATE support_tickets SET updated_at = ? WHERE id = ?', [DbTime::format($this->clock->now()), $ticketId]);
    }

    /**
     * Answer the customer: in the Telegram chat the ticket came from, or by email. The ticket then waits for the customer.
     *
     * @param array<string, mixed> $ticket a row from `find()`
     * @return string where it went: `telegram`, `email` or `none` (no way to reach the person)
     */
    public function reply(array $ticket, User $staff, string $body): string
    {
        $body = trim($body);
        $via = 'none';
        if ($ticket['source'] === 'telegram' && $ticket['telegram_chat_id'] !== null) {
            $this->queue->dispatch(new SendTelegramNotificationJob((int) $ticket['telegram_chat_id'], $body));
            $via = 'telegram';
        } elseif (is_string($ticket['email']) && $ticket['email'] !== '') {
            $this->mail->send($ticket['email'], 'Re: ' . $ticket['subject'], 'support_reply', ['body' => $body, 'subject_line' => (string) $ticket['subject']]);
            $via = 'email';
        }
        $this->addMessage((int) $ticket['id'], 'staff', $staff->id, $body, $via);
        $this->db->execute("UPDATE support_tickets SET status = 'pending', assignee_id = COALESCE(assignee_id, ?) WHERE id = ?", [$staff->id, $ticket['id']]);

        return $via;
    }

    /**
     * An internal note: never sent anywhere.
     *
     * @param array<string, mixed> $ticket
     */
    public function note(array $ticket, User $staff, string $body): void
    {
        $this->addMessage((int) $ticket['id'], 'note', $staff->id, $body, null);
    }

    public function setStatus(string $publicId, string $status): bool
    {
        return isset(self::STATUSES[$status])
            && $this->db->execute('UPDATE support_tickets SET status = ?, updated_at = ? WHERE public_id = ?', [$status, DbTime::format($this->clock->now()), $publicId]) >= 0;
    }

    public function assign(string $publicId, ?int $assigneeId): void
    {
        $this->db->execute('UPDATE support_tickets SET assignee_id = ? WHERE public_id = ?', [$assigneeId, $publicId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $publicId): ?array
    {
        $rows = $this->db->select(
            'SELECT t.*, u.name AS user_name, u.email AS user_email, a.name AS assignee_name FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN users a ON a.id = t.assignee_id WHERE t.public_id = ?',
            [strtoupper($publicId)],
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $context = is_string($row['context_json']) ? json_decode($row['context_json'], true) : [];
        $row['context'] = is_array($context) ? $context : [];
        $row['created_at'] = DbTime::parse($row['created_at']);
        $row['updated_at'] = DbTime::parse($row['updated_at']);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function messages(int $ticketId): array
    {
        return array_map(static function (array $r): array {
            $r['created_at'] = DbTime::parse($r['created_at']);

            return $r;
        }, $this->db->select('SELECT m.kind, m.body, m.delivered_via, m.created_at, u.name AS author FROM support_messages m LEFT JOIN users u ON u.id = m.author_id WHERE m.ticket_id = ? ORDER BY m.id', [$ticketId]));
    }

    /**
     * @param array{status?: string, assignee?: string, q?: string} $filters assignee `me:<id>` or `none`
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function page(array $filters, int $page, int $perPage = 25): array
    {
        $where = '1 = 1';
        $bindings = [];
        $status = $filters['status'] ?? '';
        if (isset(self::STATUSES[$status])) {
            $where .= ' AND t.status = ?';
            $bindings[] = $status;
        }
        $assignee = $filters['assignee'] ?? '';
        if ($assignee === 'none') {
            $where .= ' AND t.assignee_id IS NULL';
        } elseif (preg_match('/^me:(\d{1,12})$/', $assignee, $m) === 1) {
            $where .= ' AND t.assignee_id = ?';
            $bindings[] = (int) $m[1];
        }
        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (t.subject LIKE ? OR t.email LIKE ? OR t.public_id = ?)';
            array_push($bindings, $like, $like, strtoupper($q));
        }
        $total = (int) ($this->db->select('SELECT COUNT(*) AS c FROM support_tickets t WHERE ' . $where, $bindings)[0]['c'] ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->select(
            'SELECT t.public_id, t.subject, t.status, t.source, t.email, t.created_at, t.updated_at, u.name AS user_name, a.name AS assignee_name FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN users a ON a.id = t.assignee_id '
            . 'WHERE ' . $where . " ORDER BY FIELD(t.status, 'open', 'pending', 'solved'), t.updated_at DESC LIMIT " . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $bindings,
        );

        return ['rows' => array_map(static function (array $r): array {
            $r['created_at'] = DbTime::parse($r['created_at']);
            $r['updated_at'] = DbTime::parse($r['updated_at']);

            return $r;
        }, $rows), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * Number of tickets waiting for staff (for the menu badge).
     */
    public function openCount(): int
    {
        return (int) ($this->db->select("SELECT COUNT(*) AS c FROM support_tickets WHERE status = 'open'")[0]['c'] ?? 0);
    }

    /**
     * Tickets of one person, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        return array_map(static function (array $r): array {
            $r['updated_at'] = DbTime::parse($r['updated_at']);

            return $r;
        }, $this->db->select('SELECT public_id, subject, status, updated_at FROM support_tickets WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$userId]));
    }
}
