<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Content\Announcements;
use App\Domain\User\User;
use App\Kernel\Database\Connection;
use App\Kernel\Http\RequestContext;

/**
 * The Twig helper `announcements()`: the owner's notices that apply to the signed-in person in the workspace on screen (their plan, their
 * channels' networks), minus the ones they closed. Nothing is shown to guests, in the admin area, or while support acts as the customer
 * (a closed notice would be closed in the customer's name).
 */
final class AnnouncementFeed
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly Announcements $announcements,
        private readonly WorkspaceNav $nav,
        private readonly Connection $db,
    ) {
    }

    /**
     * @return list<array{id: int, title: string, body: string, level: string}>
     */
    public function current(): array
    {
        $user = $this->context->user();
        if (!$user instanceof User || $this->context->session()?->get('auth.impersonator') !== null) {
            return [];
        }
        $workspace = $this->nav->current();
        $plan = null;
        $platforms = [];
        if ($workspace !== null) {
            $row = $this->db->select('SELECT p.code FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.workspace_id = ?', [$workspace->workspaceId])[0] ?? null;
            $plan = $row === null ? null : (string) $row['code'];
            foreach ($this->db->select('SELECT DISTINCT platform FROM channels WHERE workspace_id = ?', [$workspace->workspaceId]) as $r) {
                $platforms[] = (string) $r['platform'];
            }
        }

        return $this->announcements->visible($user->id, $plan, $platforms);
    }
}
