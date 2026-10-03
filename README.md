# VKPoster

## Запуск в Docker

```bash
docker compose up --build
```

- Приложение: http://localhost:8080
- Локальный вход без VK: http://localhost:8080/dev_login (по умолчанию ВЫКЛЮЧЕН; для разработки: `DEV_LOGIN=1 docker compose up`; никогда не включайте на публичном сервере)
- phpMyAdmin (опционально): `docker compose --profile tools up`, http://localhost:8081
- Сброс БД: `docker compose down -v`

Таблицы создаются из `docker/init.sql` при первом старте MySQL.

### Переменные окружения

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `APP_PORT` | 8080 | порт на хосте |
| `APP_URL` | http://localhost:8080 | базовый URL (redirect для VK OAuth) |
| `DEV_LOGIN` | 0 | вход через `/dev_login` |
| `VK_CLIENT_ID` / `VK_CLIENT_SECRET` | — | данные VK-приложения для настоящей авторизации |

Для работы с группами нужен реальный VK-токен (поле `private_tocken` в таблице `users`).

## Безопасность

- `VK_CLIENT_SECRET` берётся только из переменной окружения. Секрет, ранее лежавший в коде (и остающийся в истории git), необходимо **перевыпустить** в настройках VK-приложения.
- OAuth использует случайный `state`, форма сохранения токена защищена CSRF-токеном, сессионные cookie `HttpOnly` + `SameSite=Lax`.
