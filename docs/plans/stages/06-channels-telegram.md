# Этап 06 — Каналы и Telegram-коннектор

**Зависимости:** 04 · **Ветка:** `stage-06-channels-telegram`

## Цель
Общая модель «канал + учётные данные + адаптер платформы» и первая реальная платформа — Telegram.

## БД
`channels`, `platform_credentials`, `channel_connect_codes`.

## Задачи
- [ ] Контракты в `Integrations/Social/Contracts`: `PlatformAdapter`, `ChannelConnector`, `Capabilities`, `PublishResult`, `HealthStatus`, `PlatformError` (с классификацией `temporary | permanent | auth | rate_limited | unknown_outcome`).
- [ ] `PlatformRegistry` — регистрация адаптеров, feature-флаги платформ (`PLATFORMS_ENABLED=telegram,vk,max`).
- [ ] `CredentialVault`: сохранение и чтение токенов только через `Crypto`, токены не покидают сервер, в UI показываются маскированно.
- [ ] Telegram-клиент Bot API (свой, на HttpClient): `getMe`, `getChat`, `getChatMember`, `getChatAdministrators`, `sendMessage`, `sendPhoto`, `sendVideo`, `sendDocument`, `sendMediaGroup`, `sendPoll`, `pinChatMessage`, `unpinChatMessage`, `deleteMessage`, `editMessageText`, `setWebhook`. Обработка 429 `retry_after`, 400/403 → permanent/auth.
- [ ] Подключение канала через **наш общий бот** (`TELEGRAM_BOT_TOKEN`):
  1. Пользователь получает одноразовый код (TTL 15 мин), добавляет бота админом с правом «публикация сообщений» и пишет в канал `/connect КОД` (или пересылает пост боту).
  2. Вебхук бота (`/webhooks/telegram/{secret}` + проверка заголовка `X-Telegram-Bot-Api-Secret-Token`) ловит код, проверяет через `getChatMember`, что бот админ с нужными правами **и** что инициатор — админ канала.
  3. Канал появляется в workspace, сообщение с кодом удаляется.
- [ ] Альтернативный режим **«свой бот»**: пользователь вводит токен своего бота → `getMe` → выбор канала из тех, где бот админ.
- [ ] Health check каналов (периодическая задача раз в 6 ч + перед публикацией): бот всё ещё админ, есть права; иначе `channel.status=error` + уведомление.
- [ ] UI «Каналы»: список с аватаркой, платформой, статусом и последней ошибкой; подключить, переподключить, поставить на паузу, удалить; инструкция по подключению с картинками.
- [ ] Fake-адаптер (`Integrations/Social/Fake`), который пишет «публикации» в таблицу или лог — для dev и тестов.
- [ ] Dev: `bin/console telegram:poll` (long polling getUpdates для локальной разработки без публичного HTTPS).

## Тесты
Фикстуры ответов Bot API; код подключения: неверный, истёкший, повторный, из канала, где бот без прав; вебхук без секрета → 403; токен в БД зашифрован (проверка сырого значения в БД); чужой workspace не видит канал.

## Проверка владельцем
1. Создать бота в @BotFather, вписать `TELEGRAM_BOT_TOKEN` в `.env`.
2. Создать тестовый канал, подключить по инструкции на странице «Каналы». Подключилось? Понятна ли инструкция?

## DoD
Канал TG подключается вживую у владельца (через `telegram:poll` или туннель).
