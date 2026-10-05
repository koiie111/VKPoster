<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Admin\StaffAccess;
use App\Domain\Admin\StaffRole;
use App\Domain\User\User;
use App\Kernel\Http\RequestContext;

/**
 * Twig helpers of the back office layout: `admin_can('finance.view')` for hiding what the signed-in role may not open, and the role itself.
 * The routes enforce the same matrix; hiding a link is only courtesy.
 */
final class AdminNav
{
    public function __construct(private readonly RequestContext $context, private readonly StaffAccess $staff)
    {
    }

    public function can(string $permission): bool
    {
        $user = $this->context->user();

        return $user instanceof User && $this->staff->can($user, $permission);
    }

    public function role(): ?StaffRole
    {
        $user = $this->context->user();

        return $user instanceof User ? $this->staff->roleOf($user) : null;
    }
}
