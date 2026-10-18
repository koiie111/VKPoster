# ИИ-ассистент (этап 17)

Помощник в редакторе поста: пишет черновик по теме, переписывает под соцсеть, сокращает до лимита, предлагает заголовки и хэштеги, исправляет орфографию. Решения и ограничения: [ADR 0016](../../adr/0016-ai-assistant-providers.md).

## Поток запроса
`POST /w/{id}/ai/run` (`AiController`, право `ai.use`, лимит маршрута) → `AiAssistant::run`:
1. проверка ввода (не пусто, до `AI_MAX_INPUT_CHARS`), платформа и лимит знаков из выбранного канала;
2. модель: своя модель пространства (`AiWorkspaceSettings::own`) или провайдер по умолчанию (`LlmFactory::default`), иначе тестовая вне production или «не подключено»;
3. лимит запросов в минуту (Redis), проверка кредитов (`AiCredits::canAfford`, не для своей и тестовой модели);
4. `PromptBuilder` собирает запрос: правила (доверенные) отдельно, текст/пожелания/голос бренда как данные в тегах;
5. `LlmProvider::complete`; при ошибке запись в `ai_usage` со статусом и без списания;
6. успех: запись в `ai_usage`, списание кредитов, событие `ai_request`; ответ — обычный текст.

## Классы
- `src/Integrations/Ai/Llm`: `LlmProvider`, `OpenAiCompatibleProvider`, `AnthropicProvider`, `FakeLlmProvider`, `LlmTransport` (SSRF-guard, лимиты), `LlmCatalog` (пресеты), `LlmSettings` (админские настройки, шифрование), `LlmFactory`.
- `src/Domain/Ai`: `AiAction`, `BrandVoice`, `PromptBuilder`, `AiAssistant`, `AiCredits`, `AiUsageLog`, `AiWorkspaceSettings`, `AiAnswer`, `AiException`.
- HTTP: `Ai\AiController` (редактор), `Ai\AiSettingsController` (`/w/{id}/settings/ai`: голос бренда, своя модель, кредиты), `Admin\AiProvidersController` (`/admin/ai`).
- Интерфейс: блок «ИИ-помощник» в `templates/workspace/posts/editor.twig`, поведение `public/assets/js/ai.js` (без innerHTML; ответ только через `textContent`, человек сам вставляет его в пост).

## Добавить провайдера
- Совместим с OpenAI: ничего писать не нужно, добавьте пресет в `LlmCatalog::presets()` (или создайте `custom-…` в админке).
- Другой протокол: новый класс `LlmProvider`, ветка в `LlmFactory::build`, значение `kind` в `LlmSettings::save`, тест на ответах из документации.

## Аудит и аналитика
`ai.voice_updated`, `ai.own_model_updated` (журнал пространства), `admin.ai_provider_updated/deleted` (журнал админки), событие `ai_request`. Счётчики запросов и токенов по провайдерам за 30 дней: `/admin/ai`.

## Стиль по каналам
У каждого канала (паблика, группы, аккаунта) свой стиль: правила и формат постов, тон, запретные слова, до трёх примеров (`ai_channel_styles`, `/w/{id}/settings/ai#channels`, право `workspace.settings`). Редактор передаёт канал, для которого пишется текст (открытая вкладка «для каждой соцсети» или первый выбранный канал); канал ищется внутри пространства, чужой id игнорируется. `AiWorkspaceSettings::effectiveVoice` накладывает стиль канала на голос бренда (`BrandVoice::withChannel`): тон и примеры канала главнее, правила дописываются, запретные слова объединяются. Пустой стиль удаляет запись, при удалении канала стиль удаляется каскадом. В ответе редактору приходит, какой стиль применён (`style`: `channel`, `workspace`, `none`).
