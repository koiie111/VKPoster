# Конфигурация (переменные окружения)

Источник правды — `.env.example`. `make init` копирует его в `.env` и генерирует `APP_KEY`. Реальные значения секретов в git не попадают.

| Переменная | По умолчанию | Описание |
|---|---|---|
| `APP_ENV` | `local` | `local` · `testing` · `production`. Dev-фичи (DEV_LOGIN, Fake-адаптеры) только при `local` |
| `APP_URL` | `http://localhost:8080` | Базовый URL (ссылки в письмах, OAuth-коллбеки) |
| `APP_NAME` | `VKPoster` | Название продукта |
| `APP_KEY` | — | Ключ libsodium `secretbox` в base64 (32 байта). Шифрует токены соцсетей и TOTP |
| `DB_HOST` `DB_PORT` | `mysql` `3306` | MySQL |
| `DB_DATABASE` | `app` | Имя БД (в тестах `app_test`, задаётся `phpunit.xml`) |
| `DB_USERNAME` `DB_PASSWORD` | `app` `app` | Доступ приложения к БД |
| `DB_ROOT_PASSWORD` | `root` | Только для инициализации контейнера MySQL (dev) |
| `REDIS_HOST` `REDIS_PORT` | `redis` `6379` | Redis |
| `MAIL_HOST` `MAIL_PORT` | `mailpit` `1025` | SMTP (в dev — Mailpit) |
| `MAIL_FROM` `MAIL_FROM_NAME` | | Адрес и имя отправителя |
| `S3_KEY` `S3_SECRET` | `minioadmin` | Доступ к MinIO (профиль `s3`) |
