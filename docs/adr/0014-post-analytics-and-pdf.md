# 0014. Analytics: unavailable counters, snapshot jobs and isolated HTML-to-PDF

- Статус: принято; живое сравнение метрик требует владельца
- Дата: 2026-10-16

## Решение

StatsFetcher отделён от publishing adapter; используются прежние VkApi, TelegramClient,
MaxClient, InstagramApi и ChannelCredentials. Snapshot planner не делает HTTP: он
создаёт атомарно task + обычный Queue job. Один task = publication milestone или
channel/day. Unique task_key и task_id, lease/fencing и транзакция результата защищают
от дублей при retry/crash/ACK loss. Отдельного publishing pipeline нет.

План: 1/6/24/72/168/336/504/672 часов после sent_at (последний недельный снимок на
28-й день, горизонт 30 дней). Пропущенные сроки дают текущие наблюдения, не выдуманную
историю. observed_at — реальное время чтения. Retry 1/5/15/60 минут, 5 попыток;
планировщик восстанавливает незавершённые задачи спустя 6 часов. Read-only запрос
можно повторить. Обработчики ошибок не раскрывают HTTP options/credential.

NULL означает «API не отдаёт / ещё нет снимка». Ноль — только реально полученное 0.
Охват и views различаются. Engagement VK = likes+comments+reposts, только когда все
три известны; Instagram = total_interactions; TG = aggregate reaction update, если он
пришёл. Разные платформы считают interaction по-разному, сравнение описательное.
Сводка неполных данных — null с coverage; ER = сумма engagement / сумма reach ×100
при полном покрытии. Нет деления на views и нет усреднения процентов.

## Платформы и первоисточники

- VK: [wall.getById](https://dev.vk.com/ru/method/wall.getById),
  [stats.getPostReach](https://dev.vk.com/ru/method/stats.getPostReach), groups.getById.
  Права reach могут отсутствовать; auth/permanent отказ именно optional reach даёт
  null, 429/temporary продолжает retry. Страницы VK при исследовании были недоступны
  инструменту; контракт сверяется с существующим API v5.199/fixtures, live pending.
- Telegram: [Bot API](https://core.telegram.org/bots/api#getchatmembercount),
  [MessageReactionCountUpdated](https://core.telegram.org/bots/api#messagereactioncountupdated).
  Views нет. getChatMemberCount — подписчики. Реакции только после aggregate update
  общего бота-админа; UPDATE_TYPES дополнен явно. Свой бот без webhook, старые реакции
  и история недоступны. Пустой полученный список reactions — известный 0, отсутствие
  update — null. MTProto мог бы добавить views, но это отдельная опция с отдельной
  авторизацией/рисками; Sources reader не связан с аналитикой.
- MAX: [Message](https://dev.max.ru/docs-api/objects/Message), optional stat.views /
  stat.reposts; getChat participants_count. При отсутствии поля null. Лайки, ER/reach
  не выдумываются. Живое подтверждение семантики/прав остаётся как в ADR 0007.
- Instagram: [Insights with Instagram Login](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/insights),
  существующий v23.0 клиент Stage 12. views/reach/total_interactions и counters,
  followers_count. Метрики зависят от типа media/прав; API refusal отражён как
  unavailable, не обрушает другие jobs. Страницу Meta инструмент не открыл; live pending.

Chart.js **не добавляется заново**: используется существующий vendor 4.4.7 и SHA256
из ADR 0009. CDN нет. Каждому canvas соответствует HTML-таблица, NULL не рисуется нулём.

PDF — HTML→headless Chromium в отдельном tools/analytics-pdf image, Playwright 1.64.1
(stable upstream release проверен), lockfile. Никаких Node/Chromium в PHP image.
Внутренний POST подписан HMAC APP_KEY; публичного порта нет. Renderer отвергает
неподписанный/слишком большой HTML, concurrency 2, JS/service workers отключены,
все network requests Chromium заблокированы, offline context, временные context
закрываются. Только autoescaped серверный template, никакого пользовательского URL.
503 при недоступности renderer. PHP timeout 40s, renderer bounded PDF timeout20s.

Гостевой отчёт — тот же Stage14 guest link, PIN/TTL/revoke/plan/scope. Отдельных токенов
или capability tables нет. Agency client_reports проверяется на каждом запросе;
набор постов фиксирован ссылкой, subscriber history вне её scope не раскрывается.

Retention берётся только из Entitlements.analytics_days (админские overrides сохраняются).
Снимки удаляются по workspace + observed_at; task identity живёт минимум35дней,
чтобы Free7 не переимпортировал уже собранный milestone. Исходные посты/публикации
и общий metrics_daily не удаляются.

## Границы

Тепловая карта — локальная timezone workspace, минимум20 наблюдений и5 на ячейку,
средний engagement. Это эвристика своих публикаций, не статистическая гарантия.
Нет истории внешних/чужих постов, ретроспективных точных метрик или уникального охвата
между несколькими постами (reach — сумма per-post reach, не deduplicated audience).
