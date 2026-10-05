<?php

declare(strict_types=1);

use App\Kernel\Env;

/**
 * The back office permission matrix, read as `admin_permissions` in `Config`: permission => staff roles that hold it
 * (`StaffRole` values). Every route under `/admin` names exactly one of these (`RequireStaffPermission`), so the matrix is the
 * single answer to "who may open or do this". The superadmin holds all of them; listing it here keeps the table readable.
 * An unknown permission name throws (a typo can never silently grant or hide access).
 *
 * Reading and changing are separate permissions on purpose: `analyst` and `support` can look at things they cannot change.
 */
return static fn (Env $env): array => [
    // Dashboards and statistics (read only).
    'dashboard.view' => ['superadmin', 'finance', 'support', 'content', 'analyst'],
    'stats.view' => ['superadmin', 'finance', 'support', 'analyst'],
    'system.view' => ['superadmin', 'analyst', 'support'],
    // Queues, failed jobs, platform switches, channel health: looking vs touching.
    'ops.manage' => ['superadmin'],

    // People.
    'users.view' => ['superadmin', 'support', 'finance'],
    'users.manage' => ['superadmin', 'support'],
    'users.impersonate' => ['superadmin', 'support'],
    'users.secure' => ['superadmin'],
    'users.export' => ['superadmin'],
    'privacy.manage' => ['superadmin'],
    'workspaces.view' => ['superadmin', 'support', 'finance'],

    // Money.
    'finance.view' => ['superadmin', 'finance'],
    'finance.manage' => ['superadmin', 'finance'],
    'plans.manage' => ['superadmin', 'finance'],
    'grants.manage' => ['superadmin', 'finance'],

    // Site content and talking to customers.
    'content.manage' => ['superadmin', 'content'],
    'design.manage' => ['superadmin', 'content'],
    'campaigns.manage' => ['superadmin', 'content'],
    'support.view' => ['superadmin', 'support'],
    'support.manage' => ['superadmin', 'support'],

    // The site itself, the staff and the trail of what they did.
    'settings.manage' => ['superadmin'],
    'staff.manage' => ['superadmin'],
    'audit.view' => ['superadmin'],
];
