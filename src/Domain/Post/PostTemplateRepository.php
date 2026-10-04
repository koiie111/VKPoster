<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/**
 * Saved post templates of a workspace. A template is a `PostDraft` stored as JSON (text, files, options, per-network switch and
 * the channels with their own values); channels that were disconnected since are skipped when the template is used.
 */
final class PostTemplateRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @return list<array{id: string, name: string, created_by: ?int, created_at: \DateTimeImmutable}> newest first
     */
    public function all(WorkspaceContext $context): array
    {
        $rows = $this->scoped($context, 'post_templates')->select(['public_id', 'name', 'created_by', 'created_at'])->orderBy('id', 'desc')->limit(200)->get();

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['public_id'],
            'name' => (string) $row['name'],
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'created_at' => DbTime::parse($row['created_at']) ?? new \DateTimeImmutable('@0'),
        ], $rows);
    }

    /**
     * @return array{id: string, name: string, created_by: ?int, draft: PostDraft}|null
     */
    public function find(WorkspaceContext $context, string $publicId): ?array
    {
        $row = $this->scoped($context, 'post_templates')->where('public_id', '=', strtoupper($publicId))->first();
        if ($row === null) {
            return null;
        }
        $payload = json_decode((string) $row['payload_json'], true);

        return ['id' => (string) $row['public_id'], 'name' => (string) $row['name'], 'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null, 'draft' => self::fromPayload(is_array($payload) ? $payload : [])];
    }

    public function create(WorkspaceContext $context, string $name, PostDraft $draft): string
    {
        $publicId = (string) new Ulid();
        $this->db->table('post_templates')->insert([
            'public_id' => $publicId,
            'workspace_id' => $context->workspaceId,
            'name' => mb_substr($name, 0, 100),
            'payload_json' => json_encode(self::toPayload($draft), JSON_THROW_ON_ERROR),
            'created_by' => $context->userId,
            'created_at' => DbTime::format($this->clock->now()),
        ]);

        return $publicId;
    }

    public function delete(WorkspaceContext $context, string $publicId): bool
    {
        return $this->scoped($context, 'post_templates')->where('public_id', '=', strtoupper($publicId))->delete() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toPayload(PostDraft $draft): array
    {
        return [
            'text' => $draft->text,
            'media' => $draft->mediaIds,
            'options' => $draft->options->toArray(),
            'per_network' => $draft->perNetwork,
            'variants' => array_map(static fn (VariantInput $v): array => [
                'channel' => $v->channelId,
                'text' => $v->text,
                'media' => $v->mediaIds,
                'options' => $v->options?->toArray(),
            ], $draft->variants),
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromPayload(array $payload): PostDraft
    {
        $variants = [];
        foreach (is_array($payload['variants'] ?? null) ? $payload['variants'] : [] as $v) {
            if (!is_array($v) || !is_string($v['channel'] ?? null)) {
                continue;
            }
            $variants[] = new VariantInput(
                $v['channel'],
                is_string($v['text'] ?? null) ? $v['text'] : null,
                is_array($v['media'] ?? null) ? array_values(array_filter($v['media'], 'is_string')) : null,
                is_array($v['options'] ?? null) ? PostOptions::fromArray($v['options']) : null,
            );
        }

        return new PostDraft(
            is_string($payload['text'] ?? null) ? $payload['text'] : '',
            is_array($payload['media'] ?? null) ? array_values(array_filter($payload['media'], 'is_string')) : [],
            PostOptions::fromArray(is_array($payload['options'] ?? null) ? $payload['options'] : []),
            ($payload['per_network'] ?? false) === true,
            $variants,
        );
    }
}
