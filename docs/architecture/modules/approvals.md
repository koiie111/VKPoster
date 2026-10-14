# Согласование, обсуждения и гостевые ссылки (этап 14)

`Domain/Approval` использует существующие PostRepository/PostService, workspace
permissions/ChannelAccess, Entitlements, AuditLog, Notifier и Queue. Отдельного
publisher или job framework нет. Решение клиента **не публикует** пост.

## Политика и workflow

Настройки пространства: обязательное согласование постов роли author, список
конкретных согласующих (public IDs участников) либо owner/admin/editor по умолчанию.
Автор не согласует свой пост. Client может согласовывать только при явном назначении
и полном доступе ко всем вариантам поста; публикация и редактирование ему недоступны.
Согласование входит в существующую feature `approvals`, гостевые ссылки —
`guest_links` (Про и выше), оформление клиента — `white_label` (Агентство).
Отключение политики не расширяет publish-права автора.

`draft → pending_approval → approved → scheduled`; запрос изменений переводит
материал в `changes_requested`. PostService проверяет актуальное решение **в той
же транзакции**, после блокировки поста/проверки кандидата и до создания publications.
Прямой author schedule POST — 403. Worker также проверяет approval перед отправкой
через существующие Telegram/VK/MAX/Instagram adapters.

Hash решения включает общий текст, упорядоченные media, options, per-network флаг
и канонические варианты каналов; не включает timestamps, schedule или ID версии.
Идентичный autosave не сбрасывает approval. Изменение любой части контента создаёт
версию и требует нового решения. Возврат прежнего текста не восстанавливает старое
approval: pending/changes status тоже проверяется под post lock. Старый browser
revision не может согласовать новый контент. Решения и версии сохраняют историю.

## Обсуждение и уведомления

Комментарии — plain text до 5000 символов, reply с проверкой parent workspace/post.
`@Имя_Фамилия` или `@MEMBER_PUBLIC_ID` уведомляет только участника, которому доступен
пост. Идентичные имена можно различить по public ID. История версий содержит snapshot
и bounded line diff; вывод экранирует Twig. Гостевой preview не показывает внутренние
комментарии, историю или участников команды.

Approval submitted/approved/changes_requested/revision_changed/scheduled и mentions
используют существующие email/Telegram preferences и Queue. Opt-out сохраняется.
Guest actor в decision/comment history и audit не подменяется владельцем ссылки.
Audit не содержит токен, PIN или текст комментария. Ключевые audit события отражаются
в analytics_events; daily metrics сохраняют decisions и created guest links.
Существующая staff-страница workspace показывает счётчики без секретов/контента.

## Гостевой capability

URL `/g/{opaque_random_token}.{HMAC}`: Signer с domain separation, в БД только
SHA-256 токена и Argon2id PIN. TTL 1–720 часов, отзыв, optional PIN 4–12 цифр.
Проверяется current plan, актуальная membership/permissions создателя и scope.
Набор — 1–100 постов; период календаря фиксирует **снимок набора** на момент создания,
а не автоматически делится новыми постами. Каналы ограничены исходным scope и
текущим доступом создателя; добавление приватного варианта не расширяет capability.
Secret URL показывается создателю один раз; в списке только public ID, TTL и отзыв.

Гость имеет обычный серверный session/CSRF, без регистрации и без workspace role.
PIN-unlock: отдельные per-link и per-IP buckets 5 попыток/15 минут; Redis outage
отказывает, не открывает ссылку. Успех меняет session ID; PIN не сохраняется в session.
Каждый preview/action/media request повторно проверяет TTL/отзыв/PIN. Guest action
и revoke сериализуются текущим `FOR UPDATE` чтением link row. Никаких публичных
изменений через GET. HTML и media — no-store; Referrer-Policy — no-referrer.

Медиа проходит existing MediaFileController (MIME, sandbox, Range, storage). Внутренняя
signed media URL не передаётся браузеру: внешний URL остаётся guest-bound, поэтому
отзыв блокирует и файлы. White-label использует проверенный media logo и hex-цвет
в отдельном self-hosted CSS; нет inline CSS/JS, внешних URL логотипа и HTML бренда.

Nginx не пишет capability paths `/g/` в access log; SecretRedactor скрывает guest
paths и PIN в application errors. Reverse proxy/CDN перед приложением также должен
исключать guest URLs из логов и кэша. Миграция 35 обратима: down переводит review
статусы в draft, удаляет только stage tables, сохраняет сами посты и медиа.

## Проверки и границы

Fake/mock regression не публикует реальный контент и не отправляет настоящую почту
или Telegram. UI fixture — отдельная app_test DB и local-only dev login. Нет live
проверки внешних каналов, rich-text comments, автоматического публикационного
действия при approval, динамического расширения calendar links или публичной
команды workspace. Все дальнейшие публикации идут через прежний pipeline.
