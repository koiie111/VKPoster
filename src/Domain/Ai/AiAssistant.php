<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Analytics\Analytics;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Ai\Llm\FakeLlmProvider;
use App\Integrations\Ai\Llm\LlmException;
use App\Integrations\Ai\Llm\LlmFactory;
use App\Kernel\Config;
use App\Support\Clock;

/**
 * The editor's assistant: picks the model (the workspace's own key first, else the provider the owner made default), checks input,
 * rate and credits, asks the model with the brand voice and the user text as delimited data, and takes credits only after an answer.
 *
 * The answer is returned as text. It is never executed, never put into a template unescaped and never stored here; the usage log keeps
 * only metrics. Requests with the workspace's own key and with the offline fake cost nothing.
 */
final class AiAssistant
{
    public function __construct(
        private readonly LlmFactory $factory,
        private readonly AiWorkspaceSettings $settings,
        private readonly AiCredits $credits,
        private readonly AiUsageLog $log,
        private readonly Analytics $analytics,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly \Redis $redis,
    ) {
    }

    /** Whether the assistant has something to answer with (a provider, the workspace's own model, or the offline fake). */
    public function available(WorkspaceContext $context): bool
    {
        return $this->settings->own($context->workspaceId) !== null || $this->factory->default() !== null;
    }

    /**
     * @param string $platform `telegram|vk|max|instagram` or empty
     * @param int $limit characters the target network allows (0 = unknown)
     * @throws AiException
     */
    public function run(WorkspaceContext $context, AiAction $action, string $text, string $brief, string $platform = '', int $limit = 0): AiAnswer
    {
        $text = trim($text);
        $brief = trim($brief);
        $max = $this->config->int('ai.max_input_chars', 6000);
        if (($action->needsBrief() && $brief === '' && $text === '') || ($action->needsText() && $text === '')) {
            throw new AiException('empty', $action->needsBrief() ? 'Напишите тему или тезисы, о чём должен быть пост.' : 'Сначала напишите текст поста: ассистенту нужно с чем работать.');
        }
        if (mb_strlen($text) > $max || mb_strlen($brief) > $max) {
            throw new AiException('too_long', 'Текст слишком длинный: ассистент принимает до ' . $max . ' символов за раз.');
        }
        if (!in_array($platform, ['telegram', 'vk', 'max', 'instagram'], true)) {
            $platform = '';
        }
        $limit = $limit >= 20 && $limit <= 20000 ? $limit : 0;

        $own = $this->settings->own($context->workspaceId);
        $provider = $own !== null ? $this->factory->own($own['base_url'], $own['model'], $own['api_key'], $own['auth']) : $this->factory->default();
        if ($provider === null) {
            throw new AiException('not_connected', 'ИИ-ассистент ещё не подключён. Владелец сервиса подключает модель в админке; вы можете указать свою в настройках пространства.');
        }
        $free = $own !== null || $provider instanceof FakeLlmProvider;
        $cost = $free ? 0 : $action->cost();

        $this->burst($context->workspaceId);
        if ($cost > 0 && !$this->credits->canAfford($context->workspaceId, $context->workspacePublicId, $cost)) {
            throw new AiException('no_credits', 'ИИ-кредиты закончились. Они обновятся в начале месяца; можно докупить пакет или перейти на тариф выше.', true);
        }

        $request = (new PromptBuilder($this->config->int('ai.max_output_tokens', 1024)))->build($action, $this->settings->brandVoice($context->workspaceId), $text, $brief, $platform, $limit);
        $started = microtime(true);
        try {
            $result = $provider->complete($request);
        } catch (LlmException $e) {
            $this->log->record($context->workspaceId, $context->userId, $action, $provider->name(), '', $own !== null, $e->reason, 0, 0, 0, $this->elapsed($started));
            throw $this->failure($e, $own !== null);
        }
        $answer = self::clean($result->text);
        if ($answer === '') {
            $this->log->record($context->workspaceId, $context->userId, $action, $provider->name(), $result->model, $own !== null, 'empty', 0, $result->tokensIn, $result->tokensOut, $this->elapsed($started));
            throw new AiException('provider', 'Модель вернула пустой ответ. Попробуйте ещё раз или уточните просьбу; кредиты не списаны.');
        }

        $usageId = $this->log->record($context->workspaceId, $context->userId, $action, $provider->name(), $result->model, $own !== null, 'ok', $cost, $result->tokensIn, $result->tokensOut, $this->elapsed($started));
        $this->credits->spend($context->workspaceId, $context->workspacePublicId, $cost, 'ai:' . $usageId);
        $this->analytics->track('ai_request', $context->userId, $context->workspaceId, ['action' => $action->value, 'own_key' => $own !== null ? 1 : 0]);

        return new AiAnswer($answer, $cost, $limit > 0 && mb_strlen($answer) > $limit, $own !== null);
    }

    /** Plain text only: no code fence around the answer, no control characters, bounded size. */
    private static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));
        if (preg_match('/^```[a-z]*\R(.*?)\R?```$/su', $text, $m) === 1) {
            $text = trim($m[1]);
        }

        return mb_substr($text, 0, 20000);
    }

    private function failure(LlmException $e, bool $own): AiException
    {
        return match ($e->reason) {
            'credentials' => new AiException('credentials', $own ? 'Модель не приняла ваш ключ. Проверьте адрес, модель и ключ в настройках ИИ.' : 'Ассистент временно недоступен: мы уже разбираемся. Кредиты не списаны.'),
            'rate_limited' => new AiException('provider', 'Модель сейчас перегружена. Попробуйте через минуту; кредиты не списаны.'),
            'timeout' => new AiException('provider', 'Модель отвечает слишком долго. Попробуйте ещё раз или сократите текст; кредиты не списаны.'),
            'refused' => new AiException('refused', 'Модель отказалась отвечать на такую просьбу. Переформулируйте её; кредиты не списаны.'),
            'forbidden_url' => new AiException('forbidden', $own ? 'Адрес вашей модели не подходит: он должен быть публичным https-адресом.' : 'Ассистент временно недоступен. Кредиты не списаны.'),
            'not_configured' => new AiException('not_connected', 'ИИ-ассистент подключён не до конца. Кредиты не списаны.'),
            default => new AiException('provider', 'Не получилось получить ответ от модели. Попробуйте ещё раз; кредиты не списаны.'),
        };
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /** A cap per workspace per minute: a paid model must not be hammered. A Redis failure denies the call. */
    private function burst(int $workspaceId): void
    {
        $key = 'ai-quota:' . $workspaceId . ':minute:' . intdiv($this->clock->now()->getTimestamp(), 60);
        try {
            $n = $this->redis->eval("local n=redis.call('INCR',KEYS[1]); redis.call('EXPIRE',KEYS[1],120); return n", [$key], 1);
        } catch (\Throwable) {
            throw new AiException('rate_limited', 'Ассистент сейчас недоступен. Попробуйте через минуту.');
        }
        if ((int) $n > $this->config->int('ai.workspace_per_minute', 10)) {
            throw new AiException('rate_limited', 'Слишком много запросов подряд. Подождите минуту и попробуйте снова.');
        }
    }
}
