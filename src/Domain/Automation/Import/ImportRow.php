<?php

declare(strict_types=1);

namespace App\Domain\Automation\Import;

/**
 * One checked line of an import file: what it would create, or what is wrong with it.
 */
final class ImportRow
{
    /**
     * @param list<string> $channelIds public ids of the channels
     * @param list<string> $channelNames as shown to the person
     * @param list<string> $media addresses of files to download
     * @param list<string> $errors in Russian; empty = the line can be imported
     */
    public function __construct(
        public readonly int $line,
        public readonly ?string $date,
        public readonly ?string $time,
        public readonly array $channelIds,
        public readonly array $channelNames,
        public readonly string $text,
        public readonly array $media,
        public readonly array $errors,
    ) {
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['line' => $this->line, 'date' => $this->date, 'time' => $this->time, 'channel_ids' => $this->channelIds, 'channel_names' => $this->channelNames, 'text' => $this->text, 'media' => $this->media, 'errors' => $this->errors];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $strings = static fn (mixed $v): array => is_array($v) ? array_values(array_filter($v, 'is_string')) : [];

        return new self(
            is_int($data['line'] ?? null) ? $data['line'] : 0,
            is_string($data['date'] ?? null) ? $data['date'] : null,
            is_string($data['time'] ?? null) ? $data['time'] : null,
            $strings($data['channel_ids'] ?? null),
            $strings($data['channel_names'] ?? null),
            is_string($data['text'] ?? null) ? $data['text'] : '',
            $strings($data['media'] ?? null),
            $strings($data['errors'] ?? null),
        );
    }
}
