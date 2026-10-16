# Аналитика публикаций (Stage 15)

Модуль `Domain/Analytics/{StatsCollector,StatsJob,PostAnalytics,PostMetrics}` не меняет
Publisher/PostService. `Integrations/Social/Contracts/StatsFetcher` реализован отдельно
для VK/TG/MAX/IG/Fake. Fake ограничен существующим PlatformRegistry allowFake.
Credentials расшифровываются существующим ChannelCredentials только внутри job.

Таблицы migration36: analytics_tasks, post_stats_snapshots, channel_stats_snapshots,
telegram_stats_reactions. Все принадлежат workspace/channel. Background task payload
содержит только id. CAS lease исключает поздний результат, unique task_id — duplicate
snapshot. Ошибки safe enum code; platform auth/permanent завершается unavailable,
временные ошибки попадают в существующий retry/backoff; один канал не блокирует другой.

Scheduler каждую минуту планирует milestones/daily, очистка 03:43 UTC. Общий лимит60
stats jobs/min на платформу,30/min на канал плюс прежние VK API rate gates. Redis
недоступен — запрос к платформе не делается, очередь повторит. Collection по умолчанию
выключен, явный `ANALYTICS_COLLECTION_ENABLED=true`; повторная проверка есть в worker.

`/w/{workspace}/analytics`, `export.csv`, `export.pdf`: analytics.view + текущие права
каналов, включая client. Период — целые локальные дни, ограничен analytics_days; счётчики
накопительные последнего snapshot, а не изменения за период. Нет данных и0 различаются.
CSV UTF8 BOM/semicolon neutralizes formula prefix и control/whitespace. Export требует
analytics_export. Guest `/g/{capability}/analytics` использует Stage14 link и Agency
client_reports, замороженный набор постов, без подписчиков за пределами capability.

Chart.js уже поставляется с проектом (ADR0009); таблицы доступны без JS. Calendar и
queue slot form показывают scoped best time, без автоматической смены расписания.
Staff workspace card: pending/errors/snapshot count/last completion, без секретов.
Dashboard usage — idempotent analytics event post_analytics_viewed (workspace/day).

PDF: `docker compose --profile analytics up -d --build analytics-pdf`; production
так же `-f compose.prod.yaml --profile analytics`. APP_KEY передаётся контейнеру только
через env, signing HMAC в памяти. ANALYTICS_PDF_URL — operator-controlled endpoint,
не пользовательский URL. Не публиковать сервис наружу; обновлять pin Playwright/image
совместно. См. ADR0014 для источников API, формул, ограничений и угроз.
