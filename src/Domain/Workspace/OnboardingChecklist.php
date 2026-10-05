<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Kernel\Database\Connection;

/**
 * The three first steps of a new workspace (connect a channel, write a post, plan it), worked out from the data: nothing is stored,
 * so a step ticks itself the moment the work is done and the list goes away when all three are.
 */
final class OnboardingChecklist
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{steps: list<array{key: string, done: bool}>, complete: bool, next: string|null}
     */
    public function for(WorkspaceContext $context): array
    {
        $id = $context->workspaceId;
        $channel = $this->exists('SELECT 1 FROM channels WHERE workspace_id = ? LIMIT 1', [$id]);
        $post = $this->exists('SELECT 1 FROM posts WHERE workspace_id = ? LIMIT 1', [$id]);
        $planned = $this->exists("SELECT 1 FROM posts WHERE workspace_id = ? AND status NOT IN ('draft', 'cancelled') LIMIT 1", [$id]);
        $steps = [['key' => 'channel', 'done' => $channel], ['key' => 'post', 'done' => $post], ['key' => 'schedule', 'done' => $planned]];
        $next = null;
        foreach ($steps as $step) {
            if (!$step['done']) {
                $next = $step['key'];
                break;
            }
        }

        return ['steps' => $steps, 'complete' => $next === null, 'next' => $next];
    }

    /**
     * @param list<int|string> $bindings
     */
    private function exists(string $sql, array $bindings): bool
    {
        return $this->db->select($sql, $bindings) !== [];
    }
}
