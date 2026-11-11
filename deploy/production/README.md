# Production: pandadoddo.ru

Обновление 12.11.2026: опубликованы frontend/API/worker **20261112-02**, код frontend ea64d4b / backend 3a471a2. Backend добавляет порядок числовых кодов потоков до пагинации; frontend — карточки/дерево/материальный состав и правильный источник расчётных плиток. Схема **73**, без миграций/импорта/изменения каталога, следующая миграция 74+. Лендинг 20261109-03. Backup **/opt/rline/backups/20261112-02** (700, env 600), восстановление tenant проверено. Хэши 96 предметных таблиц совпали; публичный браузер проверил вход, cookie/tenant isolation, потоки, расчёт и материалы без ошибок. Откат: предыдущие frontend 20261112-01 / API 20261111-01; живые БД не заменять. Полный отчёт — docs/PRODUCTION.md основного репозитория, локальные журналы .tools/production-20261112-02-*.log.

Далее сохранены сведения о предыдущих выпусках и общая инструкция.

Последнее обновление 12.11.2026: frontend **20261112-01** (f2bf770) опубликован без миграций/импорта и остановки API. Production app/worker и RELEASE в .env остаются **20261111-01**, tenant-схема **73**, лендинг **20261109-03**. Frontend/landing версии определяются независимыми симлинками. Backend-коммит **0aa12c7** меняет автозапуск локального compose.yaml; production restart policy уже настроена. Backup /opt/rline/backups/20261112-01, восстановление проверено, данные/настройки сохранены; полный отчёт в основном репозитории docs/PRODUCTION.md. Следующая миграция 74+.
Решение пользователя от 09.11.2026: разрабатывать и проверять изменения локально. Выполнять публикацию только после отдельной команды; наличие готовых сборок не является командой обновить сервер.

Лендинг — `https://pandadoddo.ru`, кабинет — `https://<slug>.pandadoddo.ru`.
Сервер: `130.49.150.242`, каталог `/opt/rline`, Compose-проект `rline-production`.
Репозитории независимы: rline (frontend), rline-backend, rline-landing.

## Состав

Caddy слушает 80/443, выдаёт и продлевает сертификаты. Внутренний Nginx раздаёт две статические сборки и отправляет `/api`, `/auth`, `/sanctum` кабинета в PHP-FPM. PostgreSQL 18 и PHP не имеют опубликованных портов. Worker обрабатывает в том числе создание tenant-БД. Production-образ PHP 8.5 собирается без dev-зависимостей, выполняется от www-data; исходники не подменяются bind mount.

В `current/frontend` и `current/landing` находятся относительные симлинки на `../releases/<release>/<app>`. Монтируется каталог `current`, поэтому смена ссылки видна без пересоздания gateway.

## Первое развёртывание и перенос

1. Установить Docker Engine и Compose; убедиться, что 80/443 свободны, DNS apex и tenant-имён указывает на сервер. Для автоматического появления новых тенантов добавить wildcard A `*.pandadoddo.ru` → `130.49.150.242` либо A-запись каждого тенанта.
2. Собрать frontend с `VITE_TENANT_BASE_DOMAIN=pandadoddo.ru`; лендинг с `PUBLIC_SITE_URL=https://pandadoddo.ru`, `PUBLIC_LK_URL=https://rline.pandadoddo.ru`.
3. В закрытый `/opt/rline/incoming` передать backend без `.env`, `.tools`, vendor, node_modules, storage и git; отдельно передать сборки и `.env` с правами 600. Скопировать compose.yaml, Caddyfile, gateway.conf, fastcgi.conf из этой папки в `/opt/rline`.
4. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` из источника БД (не генерировать новый при переносе), PostgreSQL db:5432, `SESSION_DRIVER=tenant-database`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `SESSION_SAME_SITE=lax`, `SESSION_COOKIE=rline_session`. **SESSION_DOMAIN не задавать**. `TENANT_BASE_DOMAIN=pandadoddo.ru`, `LANDING_ORIGIN=https://pandadoddo.ru`, `CORS_ALLOWED_ORIGINS=https://pandadoddo.ru`, `SANCTUM_STATEFUL_DOMAINS=pandadoddo.ru,*.pandadoddo.ru`, `FRONTEND_URL=https://rline.pandadoddo.ru`, `RELEASE=<release>`. DB-пароль и ключи хранить только в закрытом env. Пароли с `$` заключать в одинарные кавычки для Compose dotenv.
5. Снять `pg_dumpall --globals-only` и `pg_dump -Fc` центральной БД и **всех** `rline_t_*`. Восстановить роли (исключив повторный CREATE bootstrap-роли), центральную БД и отдельные БД с прежними владельцами. После CREATE DATABASE выполнить `REVOKE ALL ON DATABASE "rline_t_N" FROM PUBLIC` для каждой tenant-БД. Перенести storage/app при наличии пользовательских файлов. На новом сервере удалить импортированные sessions/password_reset_tokens. Не запускать seeders и не восстанавливать snapshot поверх действующей БД.
6. `docker compose up -d db`, `docker compose build app`, `docker compose run --rm app php artisan migrate --force`, `docker compose run --rm app php artisan tenant:migrate`, `docker compose up -d`.
7. Проверить HTTPS, `/up`, `/api/public/tenants` на apex, вход и `/auth/me` на tenant-host. Сверить количества предметных записей с источником и проверить отсутствие опубликованного PostgreSQL-порта.

В первом переносе создана `.database-restored`, резервные копии находятся в закрытой `backups`. Это начальный snapshot, дальнейшие локальные изменения автоматически не синхронизируются. Локальная политика TOTP и пароли переносятся вместе с БД, без смены.

## Следующие сборки и откат статики

Перед изменением backend сделать свежие резервные копии. Для каждой поставки использовать новый ID и новый тег PHP-образа в RELEASE. Сборки распаковать в `releases/<release>/frontend` и `releases/<release>/landing`.

```sh
cd /opt/rline
# Сначала проверить наличие обоих index.html в новом release.
test -f releases/RELEASE_ID/frontend/index.html
test -f releases/RELEASE_ID/landing/index.html
ln -s ../releases/RELEASE_ID/frontend current/frontend.next
mv -Tf current/frontend.next current/frontend
ln -s ../releases/RELEASE_ID/landing current/landing.next
mv -Tf current/landing.next current/landing
```

Для отката повторить переключение на предыдущий ID. API и миграции должны быть совместимы с обеими версиями интерфейса. Для backend: обновить исходники, RELEASE, собрать app, применить совместимые миграции и пересоздать app/worker; затем `docker compose exec -T gateway nginx -t` и `docker compose exec -T gateway nginx -s reload`, чтобы обновить адрес PHP upstream. Откат БД не заменяется сменой симлинка; сначала оценить миграции и backup. Не выполнять `docker compose down -v`.

## Авторизация и тенанты

Лендинг по запросу пользователя публикует только имя, slug и URL активных готовых организаций. При открытии окна входа запрашивает каталог, затем провайдеры выбранного кабинета. CSRF bootstrap `/auth/csrf-token` и login/challenge выполняются на origin выбранного тенанта с credentials:include и X-CSRF-TOKEN. Cookie host-only; пароли и токены в URL не попадают. CORS разрешает только точный origin лендинга. Поддомен авторитетен: конфликтующий X-Tenant/body отвергается. Без JavaScript работает запасная ссылка PUBLIC_LK_URL.

Для нового tenant создать организацию через платформенную админку и дождаться database_status=ready. Настроить провайдеры/участников и DNS. Caddy запрашивает внутренний allowlist перед выдачей сертификата: разрешены только активные готовые tenant-домены. Listener gateway:8080 и `/internal/*` не доступны извне; не публиковать этот порт. Для LDAP/OIDC нужны рабочие внешние серверы; callback каждого OIDC-провайдера задаётся для конкретного tenant-host.

Scramble docs публично не раздаются. На лендинге и кабинете установлен X-Robots-Tag; лендинг дополнительно сохраняет meta robots и robots.txt Disallow: /.

## Эксплуатация

`docker compose ps`, `docker compose logs --tail=100 app worker gateway caddy`; журналы не должны содержать пароли/тела login. `.env`, dumps, APP_KEY и ключи SSH не коммитить. MAIL_MAILER=log означает отсутствие реальной доставки восстановления пароля: перед рабочей эксплуатацией настроить SMTP. Начальная копия backups не заменяет регулярные внешние резервные копии; перед обновлением делать новые `pg_dump` центральной и всех tenant-БД и проверять восстановление в изолированном окружении.
