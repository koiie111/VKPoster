<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\User\User;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Http\Request;
use LogicException;

/**
 * Typed access to what `Authenticate` and `ResolveWorkspace` put on the request, for controllers.
 */
final class WorkspaceRequest
{
    public static function context(Request $request): WorkspaceContext
    {
        $context = $request->attribute('workspace');

        return $context instanceof WorkspaceContext ? $context : throw new LogicException('The route is missing the ResolveWorkspace middleware.');
    }

    public static function user(Request $request): User
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user : throw new LogicException('The route is missing the Authenticate middleware.');
    }

    public static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
