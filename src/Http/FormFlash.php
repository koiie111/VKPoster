<?php

declare(strict_types=1);

namespace App\Http;

use App\Kernel\Http\RequestContext;
use App\Kernel\Session\Session;
use RuntimeException;

/**
 * Helpers for the post/redirect/get cycle of forms: remember what the user typed plus the error
 * messages (never passwords), and queue toast notifications for the next page.
 */
final class FormFlash
{
    /** @var list<array{text: string, kind: string}> */
    private array $toasts = [];

    public function __construct(private readonly RequestContext $context)
    {
    }

    public function session(): Session
    {
        return $this->context->session() ?? throw new RuntimeException('No session in this request.');
    }

    /**
     * Keep input and errors for the form shown after the redirect.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>|string> $errors field => message(s); `form` is the error for the whole form
     */
    public function invalid(array $input, array $errors): void
    {
        $normalized = [];
        foreach ($errors as $field => $messages) {
            $normalized[$field] = is_array($messages) ? $messages : [$messages];
        }
        $this->session()->flashValidation($input, $normalized);
    }

    /**
     * @param string $kind success|error|warning|info
     */
    public function toast(string $text, string $kind = 'success'): void
    {
        $this->toasts[] = ['text' => $text, 'kind' => $kind];
        $this->session()->flash('_toasts', $this->toasts);
    }
}
