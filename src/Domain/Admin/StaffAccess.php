<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Domain\User\User;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use InvalidArgumentException;

/**
 * Who is staff and what each role may do in the back office. The superadmin (`users.is_superadmin`) holds every permission; any other
 * role is a row of `staff_members` and holds what `config/admin_permissions.php` lists for it. A blocked person has no role.
 * An unknown permission name throws, so a typo in a route or template can never silently grant or hide access.
 */
final class StaffAccess
{
    /** @var array<int, StaffRole|null> */
    private array $roles = [];

    /**
     * @param array<string, list<string>> $matrix permission => role values
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly array $matrix,
    ) {
    }

    public function roleOf(User $user): ?StaffRole
    {
        if ($user->isBlocked()) {
            return null;
        }
        if ($user->isSuperadmin) {
            return StaffRole::Superadmin;
        }
        if (!array_key_exists($user->id, $this->roles)) {
            $rows = $this->db->select('SELECT role FROM staff_members WHERE user_id = ?', [$user->id]);
            $this->roles[$user->id] = $rows === [] ? null : StaffRole::tryFrom((string) $rows[0]['role']);
        }

        return $this->roles[$user->id];
    }

    public function isStaff(User $user): bool
    {
        return $this->roleOf($user) !== null;
    }

    /**
     * @throws InvalidArgumentException for a permission that is not in the matrix
     */
    public function allows(?StaffRole $role, string $permission): bool
    {
        if (!isset($this->matrix[$permission])) {
            throw new InvalidArgumentException(sprintf('Unknown admin permission "%s".', $permission));
        }

        return $role !== null && ($role === StaffRole::Superadmin || in_array($role->value, $this->matrix[$permission], true));
    }

    public function can(User $user, string $permission): bool
    {
        return $this->allows($this->roleOf($user), $permission);
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_keys($this->matrix);
    }

    /**
     * Staff other than the owner, with the person's name and email.
     *
     * @return list<array{user_id: int, role: StaffRole, email: ?string, name: string, status: string, created_at: \DateTimeImmutable}>
     */
    public function members(): array
    {
        $result = [];
        foreach ($this->db->select(
            'SELECT s.user_id, s.role, s.created_at, u.email, u.name, u.status FROM staff_members s JOIN users u ON u.id = s.user_id ORDER BY s.created_at',
        ) as $row) {
            $role = StaffRole::tryFrom((string) $row['role']);
            if ($role === null) {
                continue;
            }
            $result[] = [
                'user_id' => (int) $row['user_id'],
                'role' => $role,
                'email' => is_string($row['email']) ? $row['email'] : null,
                'name' => (string) $row['name'],
                'status' => (string) $row['status'],
                'created_at' => DbTime::parse($row['created_at']) ?? new \DateTimeImmutable('@0'),
            ];
        }

        return $result;
    }

    /**
     * Give a person a staff role (or change it). The owner account cannot be given a lower role this way.
     */
    public function assign(int $userId, StaffRole $role, ?int $byUserId): void
    {
        if ($role === StaffRole::Superadmin) {
            throw new InvalidArgumentException('The owner role is not assigned here.');
        }
        $this->db->execute(
            'INSERT INTO staff_members (user_id, role, created_by, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)',
            [$userId, $role->value, $byUserId, DbTime::format($this->clock->now())],
        );
        unset($this->roles[$userId]);
    }

    public function remove(int $userId): void
    {
        $this->db->execute('DELETE FROM staff_members WHERE user_id = ?', [$userId]);
        unset($this->roles[$userId]);
    }
}
