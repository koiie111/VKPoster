<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

/**
 * Who may manage whom. Holding `members.manage` is necessary, not sufficient: a person can only touch
 * members who rank below them and can only hand out roles below their own. So an admin can neither
 * remove or demote another admin nor create one, and nobody can reach the owner: ownership moves only
 * through a transfer. This also guarantees a workspace always has exactly one owner.
 */
final class MemberPolicy
{
    public function __construct(private readonly Permissions $permissions)
    {
    }

    public function canInvite(Role $actor, Role $invitedAs): bool
    {
        return $this->permissions->allows($actor, 'members.manage')
            && in_array($invitedAs, Role::assignable(), true)
            && $actor->rank() > $invitedAs->rank();
    }

    public function canChangeRole(Role $actor, Role $current, Role $new): bool
    {
        return $this->permissions->allows($actor, 'members.manage')
            && $current !== Role::Owner
            && in_array($new, Role::assignable(), true)
            && $actor->rank() > $current->rank()
            && $actor->rank() > $new->rank();
    }

    public function canRemove(Role $actor, Role $target): bool
    {
        return $this->permissions->allows($actor, 'members.manage')
            && $target !== Role::Owner
            && $actor->rank() > $target->rank();
    }

    public function canRevokeInvitation(Role $actor, Role $invitedAs): bool
    {
        return $this->permissions->allows($actor, 'members.manage') && $actor->rank() > $invitedAs->rank();
    }

    /**
     * Any member may leave except the owner, who must hand the workspace over first.
     */
    public function canLeave(Role $role): bool
    {
        return $role !== Role::Owner;
    }

    public function canTransfer(Role $actor): bool
    {
        return $this->permissions->allows($actor, 'workspace.transfer');
    }

    /**
     * Roles the actor may give out, for the role pickers in the interface.
     *
     * @return list<Role>
     */
    public function assignableBy(Role $actor): array
    {
        return array_values(array_filter(
            Role::assignable(),
            fn (Role $role): bool => $this->canInvite($actor, $role),
        ));
    }
}
