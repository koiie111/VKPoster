# VK (этап 08)

Публикация на стены сообществ ВКонтакте. Решение об авторизации: [ADR 0006](../../adr/0006-vk-auth.md). Код: `src/Integrations/Social/Vk`, `src/Domain/Channel` (`VkConnections`, `OAuthRefresher`), `src/Http/Controllers/Channels/VkConnectController.php`, шаблоны `templates/workspace/channels/{connect,choose}_vk.twig`.

## Включение
`PLATFORMS_ENABLED=telegram,vk`; приложение VK ID (`VK_CLIENT_ID`, `VK_CLIENT_SECRET`, по умолчанию берутся `VKID_*`), в нём адрес возврата `<APP_URL>/channels/connect/vk/callback`; права `VK_SCOPE` (по умолчанию `wall photos video docs groups`). Без `VK_CLIENT_ID` страница подключения сообщает, что подключение не настроено.

## Подключение сообщества
1. `/w/{id}/channels/connect/vk` (право `channels.manage`): объяснение и кнопка. Кнопка ведёт на `…/connect/vk/start`: состояние (`state`) и PKCE-проверочная строка лежат только в серверной сессии (15 минут, одноразовые), браузер уходит на `id.vk.com/authorize`.
2. VK возвращает на `/channels/connect/vk/callback` (адрес фиксированный, поэтому пространство берётся из сессии, а членство и право `channels.manage` проверяются заново; без совпадения состояния ничего не обменивается). `VkConnections::complete`: обмен кода (`device_id` из callback), шифрованное сохранение пары в `platform_credentials` (`kind=oauth`, `refresh_enc`, `expires_at`, `device_id`, `account_id`), проверка `groups.get`; забытые за сутки входы без каналов удаляются.
3. `…/connect/vk/choose`: список сообществ, где человек администратор или редактор (`groups.get`, `filter=admin,editor`, удалённые и заблокированные скрыты). При отправке список запрашивается у VK ещё раз: чужие номера из формы отбрасываются. Выбранные становятся каналами (`mode=account`, один общий `credential_id`); уже подключённое сообщество подключается заново на той же строке (посты и доступы сохраняются). Если ничего не подошло, токен удаляется.

## Токены
`ChannelCredentials::forChannel` для `mode=account` вызывает `OAuthRefresher::accessToken`: токен свежий (больше 5 минут до конца) возвращается как есть; иначе замок Redis `oauth:refresh:{id}` (30 с), повторное чтение (кто-то мог обновить), `VkOAuth::refresh`, немедленная запись новой пары. Не взявший замок ждёт до 12 с, потом `temporary`. VK отверг refresh: `auth` (канал сломан, письмо); VK недоступен: `temporary`, пара не тронута.

## Клиент и ошибки
`VkApi::call(метод, параметры, токен, mutating)`: POST `https://api.vk.com/method/…`, версия 5.199, токен в `Authorization: Bearer`, логические значения как 0/1, массивы как JSON. `VkRateGate`: 3 запроса в секунду на токен (счётчик Redis `vk:rate:{sha256(токен)[0:16]}:{секунда}`). Коды ошибок:

| Код VK | Класс | Смысл |
|---|---|---|
| 5, 7, 15, 17, 18, 27, 28, 203 | `auth` | токен недействителен, нет прав на сообщество (канал помечается сломанным) |
| 6 | `rate_limited` (2 с) | слишком частые запросы |
| 9 | `rate_limited` (60 с) | flood control |
| 29 | `rate_limited` (600 с) | лимит метода |
| 1, 10, ≥500 | `temporary` | сбой VK |
| 14 | `permanent` | капча: пост не принят, повторить позже |
| 214 | `permanent` | публикация запрещена (суточный лимит, дубль) |
| 220, 222, 224 | `permanent` | запрещённая ссылка или текст |
| 100 и прочие | `permanent` | параметры |

Обрыв после отправки `wall.post` (таймаут, 5xx без разбираемого тела) даёт `unknown_outcome`; обрыв до соединения и любые сбои загрузки файлов и вспомогательных вызовов дают `temporary` (пока поста нет, повтор безопасен). Адрес загрузки от VK принимается только по https и только на доменах VK (`vk.com`, `vk.ru`, `userapi.com`, `vkuservideo.net` и др.).

## Что умеет адаптер
Текст до 16 384 символов (без разметки: `textFormat=plain`); до 10 вложений, опрос считается одним; фото (`photos.getWallUploadServer` → загрузка → `photos.saveWallPhoto`), видео (`video.save` → загрузка в `video_file`), документы (`docs.getWallUploadServer` → `file` → `docs.save`), опрос (`polls.create`, `add_answers` JSON), `wall.post` с `from_group=1` и `guid`; закрепление (`wall.pin`/`unpin`), удаление (`wall.delete`), первый комментарий от имени сообщества (`wall.createComment`, `from_group`), правка текста (`wall.getById` + `wall.edit` с возвратом текущих вложений, ссылки пропускаются). Кнопок, тихой отправки и отключения предпросмотра у VK нет (`validate()` предупреждает о кнопках). Подпись автора (`signed`) и отключение комментариев (`close_comments`) в редактор не добавлены (техдолг).

## Здоровье
`healthCheck`: `groups.get` и поиск сообщества в списке. Нет в списке: «вы больше не администратор» (`revoked`); сообщество удалено или заблокировано: `revoked`; токен недействителен: `error`; сбой VK: «не удалось выяснить» (статус не меняется).

## Проверка исхода `unknown_outcome`
`OutcomeVerifier::findPublished` (`wall.get`, 20 последних постов): текст без учёта пробелов, автор `-id` сообщества, время не раньше начала попытки минус минута; пост без текста сопоставляется по числу вложений. `Publisher` спрашивает адаптер до того, как записать `unknown` (и при неожиданном исключении во время вызова). Найденный пост становится обычным успехом; не найден или стена недоступна: остаётся `unknown`, решает человек.

## Лимит постов в сутки
`Capabilities::$maxPostsPerDay` (VK: `VK_POSTS_PER_DAY`, 50). `PostService::schedule` и `reschedule` считают публикации канала (`queued`, `sending`, `sent`, `unknown`) за календарный день пространства; при превышении `PostException` называет канал и день.

## Тесты
`tests/Unit/Channel/{VkApiTest,VkAdapterTest}.php` (ошибки, загрузки в несколько шагов, проверка исхода), `tests/Integration/Channel/OAuthRefresherTest.php` (обновление, замок, отказ), `tests/Integration/Post/UnknownOutcomeAndDailyLimitTest.php`, `tests/Feature/Channel/VkConnectTest.php` (поток подключения, подделка состояния и номеров, права). Ответы VK: `tests/Fixtures/vk/*.json`, загрузчик `tests/Support/VkFixtures`. Ни один тест не ходит в сеть.
