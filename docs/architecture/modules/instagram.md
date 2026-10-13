# Instagram (этап 12)

Публикация в профессиональные аккаунты Instagram: фото, карусели (до 10 файлов), Reels, истории, первый комментарий. Решение об API и допущения: [ADR 0010](../../adr/0010-instagram-api.md). **Сеть выключена по умолчанию** (решение владельца, Meta признана в РФ экстремистской организацией): пока `instagram` нет в `PLATFORMS_ENABLED`, её нет в интерфейсе, маршруты подключения отвечают 404, адаптер не используется.

Код: `src/Integrations/Social/Instagram` (`InstagramApi`, `InstagramOAuth`, `InstagramAccounts`, `InstagramAdapter`, `InstagramTokens`), `src/Domain/Channel/InstagramConnections.php`, `OAuthRefresher` (продление токена), `src/Http/Controllers/Channels/InstagramConnectController.php`, шаблон `templates/workspace/channels/connect_instagram.twig`, `PublishRequestBuilder` (публичные ссылки на файлы).

## Включение
1. Meta-приложение с продуктом «Instagram API with Instagram Login»; в нём адрес возврата `<APP_URL>/channels/connect/instagram/callback`; тестовый Business- или Creator-аккаунт (для чужих аккаунтов нужен App Review).
2. `.env`: `INSTAGRAM_APP_ID`, `INSTAGRAM_APP_SECRET` (при необходимости `INSTAGRAM_SCOPE`, `INSTAGRAM_API_VERSION`, `INSTAGRAM_POSTS_PER_DAY`) и `PLATFORMS_ENABLED=telegram,instagram`.
3. `APP_URL` должен быть публичным адресом по **https**: Instagram сам скачивает файлы по ссылкам. Локально: профиль `tunnel` (cloudflared) и `APP_URL` туннеля. Без https публикация отвечает понятной ошибкой, ничего не отправляя.

## Подключение
`/w/{id}/channels/connect/instagram` (право `channels.manage`) → «Войти через Instagram» → `www.instagram.com/oauth/authorize`. `state` лежит в сессии 15 минут и одноразовый; callback `/channels/connect/instagram/callback` (адрес фиксирован) заново проверяет членство и право. `InstagramConnections::complete`: обмен кода на краткоживущий токен, обмен на долгоживущий (≈60 дней), `GET /me` (личный аккаунт отклоняется), запись в `platform_credentials` (`kind=oauth`, `refresh_enc = NULL`, `expires_at`, `account_id`) и канал (`mode=account`, `external_id` = id аккаунта Instagram). Один вход = один аккаунт = один канал; повторное подключение того же аккаунта обновляет канал на месте и удаляет старый токен.

## Токен
Refresh-токена нет: `OAuthRefresher` меняет сам access-токен (`refresh_access_token`), когда до конца осталось меньше 7 дней (для VK запас 5 минут). Продление срабатывает при проверке здоровья канала (раз в 6 часов) и перед публикацией, под тем же замком Redis, что и у VK. Отказ Instagram → `auth` → статус «нужно переподключить» и письмо.

## Публикация (`InstagramAdapter`)
1. Конвейер строит запрос с `Capabilities::$publicMediaUrls`: вместо временных файлов в `PublishMedia::$url` лежит **подписанная ссылка** `/media/{id}/{вариант}?signature=…` (`MediaUrls::signed`, срок `MEDIA_SIGNED_URL_TTL`). Фото всегда в варианте `-jpg` (JPEG ≤ 8 МБ), при соотношении сторон вне 4:5…1,91:1 добавляется обрезка (`4x5` или `191x100`). Видео отдаётся оригиналом. Анимации и документы отклоняются с объяснением.
2. Перед созданием контейнеров спрашивается `content_publishing_limit`; при исчерпании — `rate_limited` на час.
3. Контейнер: фото (`image_url`), карусель (дочерние контейнеры `is_carousel_item`, затем `CAROUSEL`), Reels (`REELS`, `share_to_feed`), история (`STORIES`, без подписи). Формат выбирается в настройках поста («Что опубликовать»: по файлам / пост или карусель / Reels / история); по умолчанию одно видео — Reels, остальное — пост или карусель.
4. Ожидание `status_code=FINISHED` с паузами 2, 3, 5, 8, 13, 20, 30 … секунд (≈5 минут). `ERROR`/`EXPIRED` → `permanent`; не успел → `temporary` (поста ещё нет, повтор безопасен).
5. `media_publish` — публикующий вызов: обрыв после отправки → `unknown_outcome`; конвейер сверяет канал (`findPublished`: последние 20 публикаций, подпись и время). Ссылка на пост (`permalink`) запрашивается после публикации и сохраняется в `publications.external_url`.
6. Первый комментарий: `POST /{media}/comments` (нужно право `instagram_business_manage_comments`). Удаления, закрепления и правки через API нет: `Capabilities::delete/pin = false`, редактор не предлагает эти настройки.

Ограничения в `validate()`: подпись до 2200 символов, до 30 хэштегов и 20 упоминаний, до 10 файлов, без кнопок, опросов и документов; Reels — ровно одно видео; история — один файл; пост без файла отклоняется.

## Проверки и тесты
`tests/Unit/Channel/InstagramAdapterTest.php` (контейнеры, ожидание, лимит, ошибки, классификация кодов, `findPublished`), `tests/Feature/Channel/InstagramConnectTest.php` (поток подключения, роли, флаги), `tests/Integration/Post/InstagramPublishingTest.php` (сквозной конвейер с подписанной ссылкой), `OAuthRefresherTest` (продление). Фикстуры — `tests/Fixtures/instagram/*.json`, собраны по документации Meta; ни один тест не обращается к Instagram.

## Не проверено вживую
Список в ADR 0010 (раздел «Что не проверено»): OAuth с реальным приложением, скачивание файлов Meta по нашей ссылке, лимит, поведение Reels и ошибок контейнера, продление токена. Живая проверка отложена владельцем.
