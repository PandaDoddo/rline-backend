# Авторизация: реализованное подключение

Обновление 06.11.2026: реализованы [тенанты и отдельные настройки провайдеров](tenancy.md). Блоки `.env` ниже описывают исходную подготовку/значения bootstrap; после миграции провайдеры настраиваются для конкретного тенанта через UI или `tenant:auth-config`. Сеансы теперь используют `SESSION_DRIVER=tenant-database`. Базовые роли tenant owner/admin/member и администратор платформы реализованы; полная матрица F02 ещё не перенесена.

Решение и проверка: 06.11.2026. Для текущего локального окружения используется Laravel Sanctum (cookie/session SPA) и Fortify (пароль, TOTP, recovery codes). JWT собственного API и Passport не используются. Данные предметной области во frontend пока остаются демонстрационными; права организаций и ролей из F02 ещё не реализованы сервером.

## Провайдеры

| Система | Реализация | Локальный статус |
| --- | --- | --- |
| Local | E-mail/пароль в PostgreSQL, TOTP через Fortify | Включён, проверен в браузере |
| LDAP | Поиск по uid, service bind, user bind, стабильный entryUUID | Адаптер установлен, выключен до настройки сервера |
| Active Directory | Поиск по sAMAccountName, bind, стабильный objectGUID в hex | Адаптер установлен, выключен до настройки сервера |
| Multifactor | OIDC Authorization Code + PKCE, state, nonce, подпись RS256/JWKS, issuer/audience/expiry | Адаптер и тесты готовы; нужны issuer, Client ID/Secret, настройка ресурса |
| Ассистент (Астерит) | Зарегистрирован как недоступный вариант без протокола входа | Уточнить продукт и его роль |
| BearPass | Зарегистрирован как недоступный вариант | Не используется как источник идентификации |

В прежнем плане последние две системы ошибочно представлены как обычные провайдеры входа. [Ассистент](https://asterit.ru/moi_assistent) предназначен для удалённого доступа. [BearPass API](https://docs.bear-pass.ru/admin-guide/api.html) принимает JWT внешнего OIDC-провайдера для доступа к самому BearPass; это не подтверждает выдачу OIDC-токенов сторонним приложениям. Отдельные подключения появятся после подтверждения протокола конкретного продукта. Подмена их универсальным password endpoint не реализована.

Актуальный способ второго фактора — TOTP; SMS исключён в MASTER §5.3 и appsec §1.4. MFA Google Authenticator, Aegis, Яндекс Ключ и аналогичных приложений работает через RFC 6238, отдельные аккаунты разработчика не нужны.

## Первый локальный вход

Backend — `http://localhost:8080`, frontend — `http://localhost:5173`. Случайный пароль созданной локальной учётной записи находится в `backend/.tools/local-account.txt`. Этот файл исключён из Git и Docker-образа. После входа React требует настройку TOTP: подтвердить текущий пароль, отсканировать QR/ввести ключ в своём приложении, ввести его код, сохранить резервные коды. Фиксированный демо-код рабочего входа не применяется.

Создание другой учётной записи из `backend`:

```sh
docker compose exec app php artisan auth:user-create engineer@example.org --name="Инженер"
```

Команда запросит пароль скрытым вводом. Публичная регистрация выключена. Опция `--generate` выдаёт случайный пароль в терминал; используйте её только в подходящем окружении. Команда отказывается перезаписывать существующего пользователя.

## Запуск и параметры SPA

```sh
docker compose build app
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php artisan migrate --force
docker compose up -d --wait
```

Frontend запускается обычным `npm run dev`. Vite проксирует `/auth`, `/sanctum`, `/api` в backend. Изменённый `vite.config.ts` требует перезапуска Vite. React запрашивает CSRF-cookie и передаёт X-XSRF-TOKEN; session cookie имеет HttpOnly и SameSite=Lax. Токен доступа не хранится в localStorage. Storybook сохраняет демонстрационные экраны и использует изолированный клиент в новых историях авторизации.

Рабочие параметры `.env`:

```dotenv
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173,localhost:8080,127.0.0.1:8080
SESSION_DRIVER=database
SESSION_LIFETIME=30
CACHE_STORE=database
AUTH_LOCAL_ENABLED=true
```

При размещении за HTTPS нужны `APP_URL`, `FRONTEND_URL`, точные CORS origins, Sanctum stateful domains и `SESSION_SECURE_COOKIE=true`. Для раздельных поддоменов задайте подходящий `SESSION_DOMAIN`. В production нужна совместимая доменная схема SPA/API или reverse proxy; конфигурация Vite относится к разработке.

## LDAP и AD

Заполните соответствующий блок `backend/.env`, секреты в Git не добавлять:

```dotenv
AUTH_LDAP_ENABLED=true
LDAP_URI=ldaps://ldap.example.org:636
LDAP_BASE_DN=ou=people,dc=example,dc=org
LDAP_BIND_DN=cn=rline-reader,ou=services,dc=example,dc=org
LDAP_BIND_PASSWORD=<service-account-password>
LDAP_START_TLS=false

AUTH_AD_ENABLED=true
AD_URI=ldaps://dc.example.org:636
AD_BASE_DN=dc=example,dc=org
AD_BIND_DN=CN=rline-reader,OU=Services,DC=example,DC=org
AD_BIND_PASSWORD=<service-account-password>
AD_START_TLS=false
```

Для `ldap://...:389` обязательно `*_START_TLS=true`. Проверка сертификата обязательна. Корневой сертификат внутреннего УЦ следует установить в trust store PHP-образа и пересобрать его; отключать проверку сертификатов нельзя. Bind-пользователю достаточно чтения каталога. PHP LDAP установлен в Dockerfile. Рефералы отключены, фильтры экранируются, время соединения и поиска ограничено.

Учётные записи предоставляются явно:

```sh
docker compose exec app php artisan auth:user-create engineer@example.org --provider=ldap --subject="<entryUUID>" --name="Инженер"
docker compose exec app php artisan auth:user-create engineer@example.org --provider=ad --subject="<objectGUID-as-lowercase-hex>" --name="Инженер"
```

В форме LDAP вводится uid, AD — sAMAccountName. Поле e-mail приложения не обязано совпадать с логином каталога. Пароли каталога не сохраняются. Успешный bind без предоставленной учётной записи не выдаёт доступ. Совпадение e-mail с локальным пользователем не объединяет учётные записи. При ошибке LDAP автоматического перехода к локальному паролю нет; Local выбирается явно. Для отключённого пользователя приложения вход запрещён независимо от ответа каталога. Отображение групп в роли будет частью F02, сейчас группы не повышают права.

После изменения `.env`: `docker compose up -d --force-recreate app`. После появления реального каталога необходимо проверить цепочку сертификатов, bind, стабильный ID, выключенного пользователя и отказ доступа. В локальной проверке использовался подменённый DirectoryClient; живой LDAP/AD не заявлен проверенным.

## Multifactor

По [официальной инструкции OIDC](https://multifactor.ru/docs/oidc/) создайте ресурс OAuth/OpenID, настройте первый фактор и обязательный второй фактор на стороне Multifactor. В разрешённые redirect URI добавьте точное значение callback. SMS на ресурсе не включать, если применяется политика этого проекта.

```dotenv
AUTH_MULTIFACTOR_ENABLED=true
MULTIFACTOR_DISCOVERY_URL=https://api.multifactor.ru/.well-known/openid-configuration
MULTIFACTOR_ISSUER=<exact-issuer-from-discovery>
MULTIFACTOR_CLIENT_ID=<client-id>
MULTIFACTOR_CLIENT_SECRET=<client-secret>
MULTIFACTOR_CALLBACK=http://localhost:8080/auth/multifactor/callback
```

Issuer должен точно совпадать с discovery и ID token. Callback в production — HTTPS. Сервис требует RS256 и nonce, передаёт PKCE S256. Совместимость настроенного ресурса с этими параметрами следует подтвердить при подключении. Метаданные и JWKS запрашиваются по HTTPS, проверка TLS включена. Установлена firebase/php-jwt; декодирование без проверки подписи не применяется.

Предоставьте пользователя по проверенному `sub` конкретного issuer:

```sh
docker compose exec app php artisan auth:user-create engineer@example.org --provider=multifactor --subject="<sub>" --name="Инженер"
```

После выбора Multifactor браузер переходит на `/auth/multifactor/redirect`. Callback `/auth/multifactor/callback` принимает только незавершённый запрос этой сессии со сроком 5 минут. Ответ не создаёт пользователя автоматически. Токены внешнего сервиса не передаются React и не сохраняются как bearer-токены приложения. Успешный OIDC завершается обычным сеансом Sanctum. Локальная настройка TOTP для этого пользователя не дублируется: обязательность MFA должна обеспечиваться политикой ресурса Multifactor.

## API и ограничения

| Маршрут | Назначение |
| --- | --- |
| GET `/auth/providers` | Состояния провайдеров без секретов |
| GET `/sanctum/csrf-cookie` | CSRF для изменяющих запросов |
| POST `/auth/login` | provider, email (или логин каталога), password; `{two_factor:true}` при необходимости |
| POST `/auth/two-factor-challenge` | code либо recovery_code |
| GET `/auth/me` | Текущий пользователь, provider, признак подтверждённого TOTP |
| POST `/auth/logout` | Инвалидация сеанса |
| GET/DELETE `/auth/sessions[/{id}]` | Список/отзыв своих других сеансов, `id=others` — все другие |
| POST `/auth/forgot-password`, `/auth/reset-password` | Восстановление локального пароля |
| PUT `/auth/user/profile-information`, `/auth/user/password` | Локальный профиль/пароль |
| POST `/auth/user/confirm-password` | Повторная проверка текущего пароля |
| POST/DELETE `/auth/user/two-factor-authentication` | Начало настройки/отключение TOTP |
| POST `/auth/user/confirmed-two-factor-authentication` | Подтверждение нового TOTP |
| GET `/auth/user/two-factor-qr-code`, `/auth/user/two-factor-secret-key` | Данные подключения после подтверждения пароля |
| GET/POST `/auth/user/two-factor-recovery-codes` | Чтение/перевыпуск резервных кодов после подтверждения пароля |

Локальный пароль: 12+ символов, upper/lower/digit/symbol, срок 90 дней, история 12, обычная смена не чаще суток. Восстановление разрешено до истечения суток, но с проверкой истории. При смене удаляются другие сеансы, восстановление отзывает существующие сеансы. Секрет TOTP и recovery codes шифруются APP_KEY. Не теряйте этот ключ при перезапуске.

Лимиты: 5 запросов входа на логин за 15 минут независимо от IP, 20 на IP за 15 минут, 5 попыток второго фактора за 5 минут. Хранилище ограничений/блокировок кодов — общая PostgreSQL cache table. Сеанс: 30 минут бездействия, абсолютный срок 8 часов, максимум 3 при последовательных входах. Remember-me выключен. При конкурентных входах лимит количества сеансов пока не сериализован. TOTP одноразовый для пары секрет/временной шаг; recovery-коды проверяются под пользовательской блокировкой. Подтверждение входа истекает через 5 минут.

Журнал `auth_events` сохраняет вход/отказ, настройку MFA, пароль, отзыв сеансов, IP и user-agent; пароли, OTP, JWT, cookie и bind-секреты не записываются. События сохраняются без автоматического удаления. Восстановление не раскрывает наличие адреса в системе. Для локальной разработки `MAIL_MAILER=log`: ссылка попадает в `storage/logs/laravel.log` контейнера. Для настоящей отправки настройте SMTP в `.env` (`MAIL_MAILER=smtp`, host/port/user/password/encryption/from) и пересоздайте app.

Это реализация подключения, а не подтверждение соответствия ФСТЭК. Дополнительные требования старого appsec-плана — CAPTCHA, словарь 100k паролей, отдельные длительные блокировки и progressive delay, изоляция tenant-конфигураций, полная ролевая модель — ещё требуют реализации. Лимиты запросов выше уже работают. Конфликтующие значения длительности блокировок в MASTER и appsec необходимо свести в одну политику перед production.

## Проверки

```sh
docker compose --profile test up --build --abort-on-container-exit --exit-code-from test test
docker compose --profile test stop db-test
docker compose exec app composer lint
docker compose exec app composer validate --strict
```

Frontend: `npm run build`, `npm run lint`, `npm run build-storybook`. Истории `R-Line/Авторизация` используют собственный клиент без реальных серверов. Подключения LDAP/AD и Multifactor покрыты локальными подменами транспорта; окончательная интеграционная приёмка требует настоящих серверов и аккаунтов.
