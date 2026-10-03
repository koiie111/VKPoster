# Конфигурация (переменные окружения)

Источник правды — `.env.example`. `make init` копирует его в `.env` и генерирует `APP_KEY`. Реальные значения секретов в git не попадают.

| Переменная | По умолчанию | Описание |
|---|---|---|
| `APP_ENV` | `local` | `local` · `testing` · `production`. Dev-фичи (DEV_LOGIN, Fake-адаптеры) только при `local` |
| `APP_URL` | `http://localhost:8080` | Базовый URL (ссылки в письмах, OAuth-коллбеки) |
| `APP_NAME` | `ezposter` | Название продукта |
| `APP_DEBUG` | `0` | Показывать детали ошибок (только local/testing). В production приложение не запустится при `1` |
| `APP_KEY` | — | **Обязательна.** Ключ libsodium `secretbox` в base64 (32 байта). Шифрует токены соцсетей и TOTP, из него выводится ключ `Signer` |
| `APP_KEY_ID` | `k1` | Идентификатор текущего ключа в шифртексте (`v1:<id>:…`) |
| `APP_KEYS` | — | Старые ключи для расшифровки после ротации: `id:base64,id:base64` (см. `security.md`) |
| `TRUSTED_PROXIES` | — | IP и CIDR обратных прокси через запятую; только от них принимаются `X-Forwarded-For/Proto`. `*` не поддерживается намеренно |
| `LOG_LEVEL` | `info` | `debug` · `info` · `notice` · `warning` · `error` · `critical` |
| `LOG_DISABLE_STDERR` | — | `1` отключает дублирование логов в stderr (нужно только тестам) |
| `SESSION_IDLE_TTL` | `7200` | Секунд бездействия до конца сессии |
| `SESSION_ABSOLUTE_TTL` | `2592000` | Максимальная жизнь сессии (30 дней) |
| `ARGON_MEMORY_KIB` `ARGON_TIME_COST` | `65536` `4` | Параметры Argon2id (в тестах занижены) |
| `DEV_*` | — | Любой флаг `DEV_*` со значением `1/true/yes/on` запрещён в production (приложение не запустится) |
| `DB_HOST` `DB_PORT` | — `3306` | MySQL. `DB_HOST` обязательна |
| `DB_DATABASE` | — | **Обязательна.** Имя БД (в тестах `app_test`, задаётся `phpunit.xml`) |
| `DB_USERNAME` `DB_PASSWORD` | — | Доступ приложения к БД. `DB_USERNAME` обязательна |
| `DB_ROOT_PASSWORD` | `root` | Только для инициализации контейнера MySQL (dev) |
| `REDIS_HOST` `REDIS_PORT` | — `6379` | Redis. `REDIS_HOST` обязательна |
| `MAIL_HOST` `MAIL_PORT` | `mailpit` `1025` | SMTP (в dev — Mailpit) |
| `MAIL_FROM` `MAIL_FROM_NAME` | | Адрес и имя отправителя |
| `S3_KEY` `S3_SECRET` | `minioadmin` | Доступ к MinIO (профиль `s3`) |

Обязательные переменные проверяются при старте (`Config::load`): если чего-то нет, приложение и `bin/console` не запускаются, а в журнал попадает имя переменной без значения. В `config/*.php` env читается только через `Env`; прямых `getenv()` в коде нет.
