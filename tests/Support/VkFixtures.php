<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Answers of the VK API and VK ID for tests, from `tests/Fixtures/vk/` (built from the shapes in the VK documentation; no test reaches VK).
 */
final class VkFixtures
{
    public const TOKEN = 'vk-access-token-not-real';
    public const API = 'https://api.vk.com/method/';

    public static function raw(string $name): string
    {
        $body = file_get_contents(dirname(__DIR__) . '/Fixtures/vk/' . $name . '.json');
        if ($body === false) {
            throw new \LogicException('Missing VK fixture ' . $name);
        }

        return $body;
    }

    /**
     * An error answer of the API.
     */
    public static function error(int $code, string $message = 'error'): string
    {
        return json_encode(['error' => ['error_code' => $code, 'error_msg' => $message, 'request_params' => []]], JSON_THROW_ON_ERROR);
    }
}
