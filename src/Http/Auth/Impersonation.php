<?php

declare(strict_types=1);

namespace App\Http\Auth;

use App\Domain\Audit\AuditLog;
use App\Domain\User\User;
use App\Kernel\Http\RequestContext;
use App\Kernel\Session\Session;
use App\Support\Clock;

/**
 * "Sign in as a user" for support. The browser session stays registered under the staff member (so the device list of the customer does
 * not change and staff cannot outlive their own session); the session only says "act as user X until T".
 *
 * Every start and stop is written to the audit log, and from then on `AuditLog` tags each entry with the staff member's id. A session
 * left in this mode ends by itself after `TTL` seconds. Billing, account security and destructive workspace actions answer 403 while it lasts
 * (see `DenyWhenImpersonating`).
 */
final class Impersonation
{
    public const TTL = 3600;

    private const KEY_STAFF = 'auth.impersonator';
    private const KEY_UNTIL = 'auth.impersonation_until';

    public function __construct(
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly RequestContext $context,
    ) {
    }

    public function start(Session $session, User $staff, User $target): void
    {
        $session->set(self::KEY_STAFF, $staff->id);
        $session->set(self::KEY_UNTIL, $this->clock->now()->getTimestamp() + self::TTL);
        $session->set('auth.user_id', $target->id);
        // Another person's page must not reuse the staff member's CSRF token or last workspace.
        $session->forget('_csrf');
        $session->forget('workspace.last');
        $this->audit->record('admin.impersonation_started', $staff->id, 'user', (string) $target->id, ['target_email' => (string) $target->email]);
    }

    /**
     * Go back to being the staff member. Returns false when the session was not impersonating.
     */
    public function stop(Session $session): bool
    {
        $staffId = $session->get(self::KEY_STAFF);
        if (!is_int($staffId)) {
            return false;
        }
        $targetId = $session->get('auth.user_id');
        $this->audit->record('admin.impersonation_stopped', $staffId, 'user', is_int($targetId) ? (string) $targetId : null);
        $this->restore($session, $staffId);

        return true;
    }

    /**
     * The staff member behind this session, if it is impersonating and the time is not up. An expired mode is ended here.
     */
    public function staffId(Session $session): ?int
    {
        $staffId = $session->get(self::KEY_STAFF);
        if (!is_int($staffId)) {
            return null;
        }
        $until = $session->get(self::KEY_UNTIL);
        if (!is_int($until) || $until < $this->clock->now()->getTimestamp()) {
            $this->restore($session, $staffId);

            return null;
        }

        return $staffId;
    }

    /**
     * True while the current request runs in this mode (used by templates and middleware).
     */
    public function active(): bool
    {
        $session = $this->context->session();

        return $session !== null && is_int($session->get(self::KEY_STAFF));
    }

    /**
     * The person being impersonated, for the banner; null when not impersonating.
     */
    public function target(): ?User
    {
        $user = $this->context->user();

        return $this->active() && $user instanceof User ? $user : null;
    }

    private function restore(Session $session, int $staffId): void
    {
        $session->forget(self::KEY_STAFF);
        $session->forget(self::KEY_UNTIL);
        $session->forget('_csrf');
        $session->forget('workspace.last');
        $session->set('auth.user_id', $staffId);
    }
}
