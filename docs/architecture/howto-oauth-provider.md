# Как добавить провайдера входа

1. Создайте `src/Integrations/OAuth/<Name>Provider.php`, наследуйте `AbstractHttpProvider` и реализуйте `OAuthProvider`: `id()` (короткий латинский id из 2–10 букв, он попадёт в URL и в `user_identities.provider`), `label()`, `authorizationHost()`, `authorizationUrl()`, `exchangeCode()`, `fetchProfile()`.
2. Заполняйте `SocialProfile::emailVerified = true` только если провайдер сам гарантирует владение почтой. Сомневаетесь — `false`.
3. Добавьте ключи в `config/oauth.php` и `.env.example`, подключите провайдера в `ProviderRegistry::redirectProviders()` (без ключей он должен отсутствовать) и название в `ProviderRegistry::label()`.
4. Положите записанные ответы провайдера в `tests/Fixtures/oauth/` и напишите тест по образцу `tests/Unit/OAuth/ProvidersTest.php`; сквозной сценарий — по образцу `SocialProviderFlowTest`.
5. Redirect URI в кабинете провайдера: `${APP_URL}/auth/<id>/callback`.
6. Обновите таблицу в `modules/social-auth.md` и `configuration.md`.
