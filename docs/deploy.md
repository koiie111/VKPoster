# Развёртывание на сервере (минимальная инструкция для MVP)

Подробная инструкция (бэкапы, мониторинг, обновления без простоя, аварийное восстановление) будет на этапе 19. Здесь то, что нужно, чтобы запустить сервис на одном VDS в России.

## Что понадобится
- VDS с Docker и плагином Docker Compose (2 ядра, 4 ГБ памяти, 40 ГБ диска хватит для начала). Сервер должен находиться в России: так требует 152-ФЗ для данных граждан РФ.
- Домен, направленный на сервер.
- Обратный прокси с HTTPS (nginx + certbot или Caddy) на самом сервере. Приложение слушает только `127.0.0.1:8080`, наружу его выставляет прокси.
- Почтовый SMTP (свой или сервис рассылок) для писем: подтверждение почты, сброс пароля, уведомления.

## Первый запуск
1. Клонируйте репозиторий и перейдите в него: `git clone … && cd VKPoster`.
2. `cp .env.production.example .env` и заполните **каждое** значение:
   - `APP_URL`: адрес с `https://`, без слэша в конце;
   - `APP_KEY`: `openssl rand -base64 32`. **Сохраните копию ключа отдельно** (менеджер паролей): без него токены каналов и 2FA прочитать нельзя;
   - `DB_PASSWORD`, `DB_ROOT_PASSWORD`: длинные случайные строки;
   - `TRUSTED_PROXIES`: адрес вашего обратного прокси (без этого лимиты запросов считают всех посетителей одним человеком);
   - `MAIL_DSN`, `MAIL_FROM`, `SUPPORT_EMAIL`;
   - ключи соцсетей и платёжных систем по списку в `.env.example`.
3. Соберите и запустите: `docker compose -f compose.prod.yaml up -d --build`.
4. Примените миграции: `docker compose -f compose.prod.yaml exec app php bin/console migrate`.
5. Создайте сотрудника-администратора (пароль будет показан один раз): `docker compose -f compose.prod.yaml exec app php bin/console user:create-admin you@example.com --name="Имя"`.
6. Настройте обратный прокси: `https://ваш-домен` → `http://127.0.0.1:8080`, с заголовками `X-Forwarded-For`, `X-Forwarded-Proto`, `Host`. Пример для nginx:

   ```
   location / {
       proxy_pass http://127.0.0.1:8080;
       proxy_set_header Host $host;
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       proxy_set_header X-Forwarded-Proto $scheme;
       client_max_body_size 55m;
   }
   ```
7. Откройте сайт, войдите администратором, включите двухфакторную защиту («Безопасность»). Без неё админка не откроется. Затем откройте `/admin`.

## Вебхуки соцсетей
После того как сайт открывается по HTTPS:
- Telegram: `docker compose -f compose.prod.yaml exec app php bin/console telegram:webhook set https://ваш-домен` (в `.env` должен быть `TELEGRAM_WEBHOOK_SECRET`);
- MAX: `… max:webhook set https://ваш-домен`;
- ЮKassa: в кабинете укажите адрес уведомлений `https://ваш-домен/webhooks/billing/yookassa`, события `payment.succeeded` и `payment.canceled`.

## Что проверить перед открытием для людей
- `https://ваш-домен/healthz` отвечает `{"db":"ok","redis":"ok"}`.
- Письмо о подтверждении почты доходит (зарегистрируйте тестовый аккаунт).
- Тексты оферты и политики заполнены: файлы `resources/legal/*.md`, незаполненные места помечены `[[…]]`. Пока они есть, на страницах виден значок «Шаблон», а в админке предупреждение. После правки текста поднимите дату `version:` в начале файла: люди увидят просьбу подтвердить согласие заново.
- `/robots.txt` разрешает индексацию (только при `APP_ENV=production`), `/sitemap.xml` открывается.
- Работают воркер и планировщик: `docker compose -f compose.prod.yaml ps` показывает `worker` и `scheduler` как `running`. Планировщик должен быть **один**.

## Обновление
```
git pull
docker compose -f compose.prod.yaml up -d --build
docker compose -f compose.prod.yaml exec app php bin/console migrate
```
Воркер и планировщик пересоздаются вместе с приложением: долгие процессы держат старый код, поэтому после обновления их нужно перезапускать (команда выше это делает).

## Резервные копии (минимум)
- База: `docker compose -f compose.prod.yaml exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" app' > backup.sql` по расписанию (cron), копии хранить вне сервера.
- Файлы медиатеки: том `app-storage` (`docker volume inspect …`).
- `.env` и `APP_KEY`: в менеджере паролей.

## Чего здесь нет (этап 19)
Автоматические бэкапы и проверка восстановления, мониторинг и оповещения, обновление без простоя, блокировки между несколькими экземплярами планировщика, S3-хранилище медиа для Instagram.
