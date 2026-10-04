# Модуль Auth (этап 02)

Регистрация и вход по почте и паролю, подтверждение почты, сброс пароля, смена почты и пароля, «запомнить меня», двухфакторная защита (TOTP), список устройств. Вход через соцсети добавит этап 03 поверх тех же сессий.

## Где что лежит
| Что | Где |
|---|---|
| Сущность и репозиторий | `Domain/User/User`, `UserRepository` |
| Токены (почта, сброс) | `Domain/Auth/AuthTokens`, `TokenType` |
| Регистрация и подтверждение | `RegistrationService` |
| Проверка пароля, блокировки, журнал | `LoginService`, `LoginThrottle` |
| Сброс и смена пароля | `PasswordService` |
| Смена почты | `EmailChangeService` |
| 2FA и резервные коды | `TwoFactorService`, `QrCode` ([ADR 0003](../../adr/0003-qr-code-library.md)) |
| Устройства | `SessionRegistry` (таблица `user_sessions`) |
| «Запомнить меня» | `RememberMe` |
| Политика паролей | `PasswordPolicy` + `resources/data/common-passwords.txt` |
| Письма | `AuthMailer` → `Notification/MailComposer` → `SendMailJob`; шаблоны `templates/emails/*.html.twig` и `*.text.twig` |
| Аудит | `Domain/Audit/AuditLog` (таблица `audit_log`) |
| Сессия в браузере | `Http/Auth/SessionAuth` (вход, 2FA-ожидание, выход) |
| Middleware | `Authenticate`, `RequireVerifiedEmail`, `Guest`, `RememberLogin` (глобальный) |
| Контроллеры | `Http/Controllers/Auth/*`, `Http/Controllers/Account/*`, `Dev/DevLoginController` |
| CLI | `user:create-admin`, `auth:prune` (также раз в сутки из планировщика) |

## Маршруты
| Путь | Кто | Что |
|---|---|---|
| `GET/POST /register`, `GET /register/done` | гость | регистрация, страница «проверьте почту» |
| `GET/POST /login`, `GET/POST /login/2fa` | гость | пароль, затем код (если включена 2FA) |
| `GET/POST /password/forgot`, `GET /password/forgot/sent` | гость | запрос ссылки |
| `GET/POST /password/reset/{token}` | любой | новый пароль по ссылке |
| `GET/POST /email/verify/{token}` | любой | подтверждение почты (GET только показывает кнопку) |
| `GET/POST /email/change/{token}` | любой | подтверждение нового адреса |
| `GET /email/verification`, `POST /email/verification/resend` | вошедший | страница для неподтверждённых |
| `POST /logout`, `POST /logout/all` | вошедший | выход, выход на всех устройствах |
| `GET /account/security`, `POST /account/password`, `POST /account/email`, `POST /account/sessions/{id}/revoke` | вошедший | страница «Безопасность» |
| `POST /account/2fa/start`, `GET /account/2fa/setup`, `GET /account/2fa/qr.svg`, `POST /account/2fa/confirm`, `/disable`, `/recovery-codes` | вошедший | настройка 2FA |
| `GET /app` | вошедший + подтверждённая почта | стартовая страница (пока заглушка) |
| `GET /dev/login-as/{id или email}` | только `APP_ENV=local` | вход без пароля для разработки |

Неподтверждённый пользователь видит только `/email/verification` и `/account/*` (чтобы мог исправить неверный адрес); всё остальное требует подтверждения (`RequireVerifiedEmail`).

## Жизненный цикл входа
1. `POST /login` → `LoginService::attempt()` (проверка блокировки, пароля, статуса).
2. Если включена 2FA, в сессии появляется `auth.pending` на 10 минут, браузер идёт на `/login/2fa`; иначе сразу п. 3.
3. `SessionAuth::signIn()`: новый id сессии, новый CSRF-токен, `auth.user_id`, строка в `user_sessions`, запись в `login_attempts` и `audit_log`, при галочке — cookie «запомнить меня» (выдаётся только после прохождения 2FA).
4. Каждый запрос `Authenticate` проверяет, что устройство не отозвано и пользователь не заблокирован.
5. Если сессии нет, но есть cookie `remember`, `RememberLogin` входит автоматически и ротирует cookie.

## Сроки и лимиты
| Что | Значение |
|---|---|
| Ссылка подтверждения почты / смены почты | 24 часа |
| Ссылка сброса пароля | 1 час |
| Письма подтверждения | не больше 3 в час на пользователя |
| Письма сброса, «у вас уже есть аккаунт» | не больше 3 в час на адрес |
| Попытки входа | 20 / 10 мин с IP; блокировка аккаунта с 5-й неудачи (30 с → 15 мин) |
| Ввод кода 2FA | 5 / 10 мин на пользователя |
| Проверка текущего пароля (смена пароля, почты, отключение 2FA) | 5 / 10 мин на пользователя |
| Регистрация | 10 / час с IP |
| «Запомнить меня» | 30 дней, ротация при каждом использовании |

## События аудита
`auth.register`, `auth.login`, `auth.logout`, `auth.email.verified`, `auth.email.change_requested`, `auth.email.changed`, `auth.password.reset_requested`, `auth.password.reset`, `auth.password.changed`, `auth.2fa.enabled`, `auth.2fa.disabled`, `auth.2fa.recovery_regenerated`, `auth.remember.theft_detected`.

## Как проверить руками
`make up && make seed`, затем `/dev/login-as/demo@ezposter.local` или вход `demo@ezposter.local` / `demo-password-2026`. Письма: http://localhost:8025. Суперадмин: `make console CMD="user:create-admin you@example.com --name=Имя"`.

## Известные ограничения
- Список частых паролей сгенерирован (≈8,6 тыс. записей длиной от 10 символов); его можно заменить файлом побольше без правок кода.
- Страницы политики и оферты (ссылка в форме регистрации) появятся на этапе 11.
