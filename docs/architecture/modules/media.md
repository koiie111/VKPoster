# Медиатека (этап 05)

Безопасная загрузка, хранение и раздача фото, видео и PDF; варианты под соцсети; водяные знаки. Код: `src/Domain/Media`, `src/Integrations/Storage`, `src/Http/Controllers/Media`, шаблоны `templates/workspace/media/`.

## Таблицы
- `media_folders(public_id, workspace_id, name)`: плоские папки (один уровень), имя уникально в пространстве. Удаление папки не удаляет файлы (`ON DELETE SET NULL`).
- `media`: `public_id` (ULID для URL), `workspace_id`, `uploader_id`, `folder_id`, `kind` (image/video/document), `original_name` (только для показа), `storage_key`, `thumb_key`, `mime`, `size` (байты после пересжатия, идут в квоту), `width`, `height`, `duration_ms`, `codec`, `animated`, `sha256` (хэш файла **как загружен**; `UNIQUE(workspace_id, sha256)` — дедупликация), `variants_json` (кэш вариантов).
- `watermarks`: логотип PNG, `position` (`tl tc tr ml mc mr bl bc br`), `opacity`, `scale` (доля ширины фото), `margin` (доля меньшей стороны), `is_default` (один на пространство).
Всё каскадно удаляется вместе с пространством. Файлы из хранилища при удалении пространства пока не чистятся (см. техдолг в `PROGRESS.md`).

## Хранилище
`MediaStorage` (интерфейс) → `FlysystemMediaStorage`. `MEDIA_DISK=local` пишет в `storage/media` (вне docroot nginx, права 0600/0700), `s3` — в S3/MinIO (`S3_ENDPOINT`, `S3_BUCKET`, `S3_KEY`, `S3_SECRET`, `S3_REGION`, `S3_PATH_STYLE`; в dev `docker compose --profile s3 up -d minio`). Ключи: `ws/{workspaceId}/{yyyy}/{mm}/{ulid}.{ext}`, превью `…_thumb.webp|jpg`, варианты `…_v_{hash}.{jpg|png}`, логотипы `ws/{id}/watermarks/{ulid}.png`. Имя файла от клиента в ключ не попадает. Тесты используют `ArrayMediaStorage`.

## Конвейер загрузки (`MediaService::upload`)
Порядок от дешёвого к дорогому, любой отказ — `MediaException` с русским сообщением, в хранилище ничего не остаётся:
1. размер файла ≤ `MEDIA_MAX_FILE_MB` (50 МБ), непустой;
2. квота пространства (`MEDIA_QUOTA_MB`, 500 МБ; потом из тарифа). В квоту входят размеры сохранённых оригиналов; превью и кэш вариантов не считаются;
3. тип по содержимому (`finfo`), белый список: jpeg, png, webp, gif, mp4, mov, pdf. SVG, HTML, PHP и всё остальное отклоняются; расширение берётся из типа;
4. дубликат по SHA-256 → возвращается существующий файл (тост «Такой файл уже есть»);
5. по виду:
   - **изображение** (`ImageProcessor`): размеры из заголовка (`getimagesize`) до декодирования, каждая сторона ≤ 10000 (защита от decompression bomb), `pingImage` сверяет формат, анимации ≤ 500 кадров и ≤ 400 млн пикселей суммарно, анимированный WebP отклоняется; затем пересборка из пикселей: EXIF-ориентация применяется, CMYK → sRGB, все метаданные и хвостовые байты исчезают (полиглот-файл теряет полезную нагрузку); ограничения ресурсов Imagick (память, площадь, диск, время); превью 320 px WebP;
   - **видео** (`FfprobeVideoProbe`): `ffprobe` (длительность ≤ `MEDIA_MAX_VIDEO_SECONDS`, 15 мин; разрешение ≤ 7680; кодек; поворот учитывается), кадр-превью через `ffmpeg`. Команды без shell, с таймаутом 30 с и `-protocol_whitelist file`. Видео не перекодируется (тяжело; см. техдолг);
   - **PDF**: проверка сигнатуры `%PDF-`, без превью.
6. запись в хранилище, затем строка в БД; при сбое БД объекты удаляются; гонка двух одинаковых загрузок разрешается через `UNIQUE`.

Загрузка по ссылке (`MediaFetcher`): запрос через `HttpClientInterface` с `user_url => true` (SSRF-guard, закрепление IP, проверка каждого редиректа), таймаут `MEDIA_URL_TIMEOUT`, остановка по размеру (заголовок, callback прогресса и счёт байт). После скачивания идёт тот же конвейер.

## Варианты
`VariantSpec`: кадр `original|1x1|4x5|191x100|9x16` (по центру), `-wm` — с водяным знаком, `maxEdge` (по умолчанию 2560), `maxBytes` (ступенчатое снижение качества, потом размера). `VariantService::get` делает вариант при первом обращении и кэширует в хранилище; ключ кэша включает файл и настройки водяного знака, поэтому смена знака не отдаёт старые картинки. Только для обычных фото (не GIF-анимация, не видео). Этап 07 вызывает `VariantService` при публикации со спецификацией платформы; требования платформ лежат данными в `config/media.php → platforms`, проверка — `PlatformRequirements` (показывается на странице файла).

## Раздача
Единственный путь наружу — `GET /media/{id}/{variant}` (`MediaFileController`), `variant`: `original`, `thumb` или слаг варианта.
- **Сессия:** участник пространства-владельца с правом `media.view` (иначе 404, гость 404 без редиректа — `AuthenticateOrSigned`).
- **Подписанная ссылка:** `MediaUrls::signed($media, $variant, $ttl)` даёт абсолютный URL с `expires` и `signature` (HMAC, `Signer`), для сетей, которые сами скачивают файл (Instagram). Неверная или просроченная подпись → 403; подпись проверяется до любого обращения к данным.
- Заголовки: тип из содержимого, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, `Content-Disposition` (документы и `?download=1` как вложение; имя в `filename*`), `ETag`/304, `Accept-Ranges` и ответы 206 (нужны Safari для видео). Тело стримится (`Response::stream`), память не растёт.
Файлы лежат вне `public/`, nginx отдаёт только `public/`; тест проверяет конфиг и отсутствие каталогов в `public/`.

## Права
`media.view` (owner, admin, editor, author, viewer), `media.upload` (…, author), `media.manage` (owner, admin, editor: удаление, переименование, папки, водяные знаки). Клиент (`client`) медиатеку не видит. Маршруты `/w/{id}/media…` под `ResolveWorkspace`, чужое пространство и чужие id файлов/папок/знаков → 404.

## Удаление и использование
`MediaService::delete` спрашивает `MediaUsageChecker`: реализация `PostUsageChecker` (этап 07) находит запланированные и публикуемые посты с файлом и возвращает текст причины. Удаляются строка, оригинал, превью и все варианты; событие `media.deleted` в журнале.

## Интерфейс
Страница `/w/{id}/media`: загрузка перетаскиванием и выбором (несколько файлов, прогресс у каждого, параллельно 3, `public/assets/js/media.js`, компонент Alpine `mediaUploader`), загрузка по ссылке, папки-вкладки, поиск, фильтр по типу, сетка, квота. Страница файла: просмотр, сведения, подходит ли для сетей, ссылки на кадрирование, переименование, перенос, удаление. Страница «Водяной знак»: загрузка PNG, положение 3×3, непрозрачность, размер, отступ с живым предпросмотром (`…/preview`, ничего не сохраняет).
Без JS форма загрузки отправляет один файл обычным POST (редирект с тостом); `Accept: application/json` даёт JSON `{ok, items, errors}`.

## Env
`MEDIA_DISK`, `MEDIA_LOCAL_ROOT`, `MEDIA_MAX_FILE_MB`, `MEDIA_QUOTA_MB`, `MEDIA_MAX_VIDEO_SECONDS`, `MEDIA_URL_TIMEOUT`, `MEDIA_SIGNED_URL_TTL`, `S3_*` (см. `configuration.md`). Лимит PHP: `upload_max_filesize=50M`, `post_max_size=55M`, nginx `client_max_body_size 55m`.

## Локально
`make seed` создаёт демо-медиатеку в «Кофейне «Зерно»» (картинки, папки, PDF, логотип). Образ приложения содержит `ffmpeg`.
