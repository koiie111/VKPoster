<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Answers of the Instagram API and Instagram Login for tests, from `tests/Fixtures/instagram/` (built from the shapes in the Meta
 * documentation; no test reaches Instagram).
 */
final class InstagramFixtures
{
    public const TOKEN = 'ig-access-token-not-real';
    public const ACCOUNT = '17841400000000001';
    public const GRAPH = 'https://graph.instagram.com/v23.0/';

    public static function raw(string $name): string
    {
        $body = file_get_contents(dirname(__DIR__) . '/Fixtures/instagram/' . $name . '.json');
        if ($body === false) {
            throw new \LogicException('Missing Instagram fixture ' . $name);
        }

        return $body;
    }

    /**
     * An error answer of the Graph API.
     */
    public static function error(int $code, int $subcode = 0, string $message = 'error'): string
    {
        return json_encode(['error' => ['message' => $message, 'type' => 'OAuthException', 'code' => $code, 'error_subcode' => $subcode, 'fbtrace_id' => 'trace']], JSON_THROW_ON_ERROR);
    }
}
