<?php

declare(strict_types=1);

namespace App\Integrations\Social\Vk;

use App\Integrations\Social\Contracts\PlatformError;
use SensitiveParameter;

/**
 * The communities a VK account manages (`groups.get` with `filter=admin,editor`). Used when connecting (to offer the list) and by the health
 * check (is the person still an administrator or editor there).
 */
final class VkCommunities
{
    public function __construct(private readonly VkApi $api)
    {
    }

    /**
     * @return list<array{id: string, name: string, screen_name: ?string, level: int, closed: bool}> `level`: 2 editor, 3 administrator
     * @throws PlatformError
     */
    public function managedBy(#[SensitiveParameter] string $token): array
    {
        $response = $this->api->call('groups.get', ['filter' => 'admin,editor', 'extended' => 1, 'fields' => 'screen_name,admin_level,is_admin', 'count' => 1000], $token);
        $items = is_array($response['items'] ?? null) ? $response['items'] : [];
        $groups = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_int($item['id'] ?? null)) {
                continue;
            }
            $level = is_int($item['admin_level'] ?? null) ? $item['admin_level'] : (in_array($item['is_admin'] ?? 0, [1, true], true) ? 3 : 2);
            $groups[] = [
                'id' => (string) $item['id'],
                'name' => is_string($item['name'] ?? null) ? $item['name'] : 'Сообщество ' . $item['id'],
                'screen_name' => is_string($item['screen_name'] ?? null) && $item['screen_name'] !== '' ? $item['screen_name'] : null,
                'level' => $level,
                'closed' => is_int($item['deactivated'] ?? null) || is_string($item['deactivated'] ?? null),
            ];
        }

        return $groups;
    }
}
