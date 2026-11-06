<?php

declare(strict_types=1);

namespace App\Integrations\Selection;

use App\Integrations\Ai\Llm\SourceLlm;
use App\Integrations\ContentProviders\ProviderException;
use App\Integrations\ContentProviders\ProviderMetadata;

/** Structured source relevance using the same LLM registry and BYOK settings as the editor. */
final class SharedLlmSemanticSelectionProvider implements WorkspaceSemanticSelectionProvider, ProviderMetadata
{
    public function __construct(private readonly SourceLlm $llm)
    {
    }

    public function name(): string
    {
        return 'llm';
    }

    public function usesOwnKey(int $workspaceId): bool
    {
        return $this->llm->ownKey($workspaceId);
    }

    public function availableForWorkspace(int $workspaceId): bool
    {
        return $this->llm->available($workspaceId, 'source_semantic');
    }

    public function evaluate(SemanticSelectionInput $input): SemanticSelectionResult
    {
        throw new ProviderException('workspace_required');
    }

    public function evaluateForWorkspace(int $workspaceId, SemanticSelectionInput $input): SemanticSelectionResult
    {
        $system = SemanticSelectionInput::POLICY . ' Return exactly one JSON object with decision (approved, rejected or needs_review), score (0-100), confidence (0-1), reason (Russian, at most 512 characters), matched_criteria and review_flags (arrays of at most 20 short strings). Trusted criteria: ' . $input->criteria;
        $answer = $this->llm->complete($workspaceId, 'source_semantic', $system, json_encode(['untrusted_material' => ['text' => $input->text, 'title' => $input->title, 'metadata' => $input->metadata, 'revision' => $input->revision]], JSON_THROW_ON_ERROR));
        try {
            $row = json_decode($answer, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProviderException('invalid_semantic_output');
        }
        if (!is_array($row) || count($row) !== 6 || array_diff(['decision', 'score', 'confidence', 'reason', 'matched_criteria', 'review_flags'], array_keys($row)) !== []
            || !in_array($row['decision'], ['approved', 'rejected', 'needs_review'], true)
            || !is_int($row['score']) || $row['score'] < 0 || $row['score'] > 100
            || (!is_int($row['confidence']) && !is_float($row['confidence'])) || (float) $row['confidence'] < 0 || (float) $row['confidence'] > 1
            || !is_string($row['reason']) || mb_strlen($row['reason']) > 512
            || !is_array($row['matched_criteria']) || !array_is_list($row['matched_criteria']) || count($row['matched_criteria']) > 20
            || !is_array($row['review_flags']) || !array_is_list($row['review_flags']) || count($row['review_flags']) > 20) {
            throw new ProviderException('invalid_semantic_output');
        }
        foreach ([$row['matched_criteria'], $row['review_flags']] as $values) {
            foreach ($values as $value) {
                if (!is_string($value) || mb_strlen($value) > 128) {
                    throw new ProviderException('invalid_semantic_output');
                }
            }
        }
        return new SemanticSelectionResult($row['decision'], $row['score'], (float) $row['confidence'], $row['reason'], $row['matched_criteria'], $row['review_flags']);
    }

    public function metadata(): array
    {
        return $this->llm->metadata();
    }
}
