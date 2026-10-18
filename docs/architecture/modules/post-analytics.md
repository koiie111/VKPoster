# Аналитика публикаций (Stage 15)

Модуль `Domain/Analytics/{StatsCollector,StatsJob,PostAnalytics,PostMetrics}` не меняет
Publisher/PostService. `Integrations/Social/Contracts/StatsFetcher` реализован отдельно
для VK/TG/MAX/IG/Fake. Fake ограничен существующим PlatformRegistry allowFake.
Credentials расшифровываются существующим ChannelCredentials только внутри job.

Таблицы migration36: analytics_tasks, post_stats_snapshots, channel_stats_snapshots,
telegram_stats_reactions. Все принадлежат workspace/channel. Background task payload
содержит только id. CAS lease исключает поздний результат, unique task_id — duplicate
snapshot. Publications имеет индекс (status,sent_at) для tick. Ошибки safe enum code; platform auth/permanent завершается unavailable,
временные ошибки попадают в существующий retry/backoff; один канал не блокирует другой.

Scheduler каждую минуту планирует milestones/daily, очистка 03:43 UTC.
После простоя выбирается только последний прошедший milestone: максимум одна текущая
post task за tick, ранние пропущенные сроки не backfill. Запоздавшая job раннего
milestone завершается milestone_missed без HTTP; observed_at всегда фактический.
StatsJob обслуживается отдельным analytics-worker (--queue=analytics), прежний worker
остаётся publish,default. Rate limit / недоступный limiter откладывает ту же job с delay,
освобождает lease и компенсирует reservation attempt. Provider errors используют обычные
5 attempts/backoff; recovery не создаёт новую job, пока существующая отложена в Queue.
Instagram читает каждую metric отдельно: available/unavailable/permission_denied/
unsupported/transient_failure сохраняются в metric_statuses. Перед retry успешные
частичные counters сохраняются под тем же task_id; повторный transient не стирает их. Общий лимит60
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
queue slot form показывают scoped best time через узкий BestTimeHints::forWorkspace:
только sent_at и последний engagement, без report/post text/subscriber snapshots.
Формула и пороги общие с dashboard, автоматической смены расписания нет.
Staff workspace card: pending/errors/snapshot count/last completion, без секретов.
Dashboard usage — idempotent analytics event post_analytics_viewed (workspace/day).

PDF: `docker compose --profile analytics up -d --build analytics-pdf`; production
так же `-f compose.prod.yaml --profile analytics`. PHP AnalyticsPdfKey получает
`hash_hmac('sha256', 'analytics-pdf-v1', APP_KEY)`. Trusted PHP init service
analytics-pdf-key атомарно пишет **только производный ключ** в dedicated Docker volume.
Chromium монтирует volume read-only, получает только путь ANALYTICS_PDF_KEY_FILE,
не APP_KEY/env_file/токены платформ. Новый секрет в .env не нужен. Missing/wrong key
fail closed. При ротации APP_KEY пересоздать init и renderer вместе:
`docker compose --profile analytics up -d --force-recreate analytics-pdf-key analytics-pdf`.

Unexpected Chromium disconnect завершает Node с code1; restart: unless-stopped
восстанавливает сервис. SIGTERM/SIGINT закрывают браузер намеренно с code0.
ANALYTICS_PDF_URL — operator-controlled endpoint, не пользовательский URL. Не публиковать
сервис наружу; обновлять pin Playwright/image совместно. См. ADR0014 для ограничений.

PDF presentation: A4, фиксированные доли колонок с 44% для публикации, перенос
длинных названий, повторяемый заголовок таблицы и запрет разрыва обычной строки.
SQL читает максимум 201 символ текста: 200 для прежнего dashboard/CSV и один
символ для определения усечения. PDF получает отдельный excerpt до 200 символов,
с многоточием по границе слова; исходный пост не изменяется. Последние доступные
subscriber snapshots за выбранный период выводятся с датой UTC; null остаётся
«Нет данных». Используется существующий report.followers, пустой у гостевого scope.
