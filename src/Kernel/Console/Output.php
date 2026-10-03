<?php

declare(strict_types=1);

namespace App\Kernel\Console;

/**
 * Line-oriented output for console commands (stdout by default; a memory buffer in tests).
 */
final class Output
{
    private string $buffer = '';

    /**
     * @param resource|null $stream null collects output in memory (see `contents()`)
     */
    public function __construct(private $stream = null)
    {
    }

    public static function stdout(): self
    {
        return new self(STDOUT);
    }

    public function line(string $text = ''): void
    {
        if ($this->stream === null) {
            $this->buffer .= $text . "\n";

            return;
        }
        fwrite($this->stream, $text . "\n");
    }

    public function error(string $text): void
    {
        if ($this->stream === null) {
            $this->buffer .= $text . "\n";

            return;
        }
        fwrite(STDERR, $text . "\n");
    }

    public function contents(): string
    {
        return $this->buffer;
    }
}
