# R-Line Backend

Репозиторий: [PandaDoddo/rline-backend](https://github.com/PandaDoddo/rline-backend). Frontend развивается отдельно в [PandaDoddo/rline](https://github.com/PandaDoddo/rline).

Базовый Laravel API для локальной разработки. PHP 8.5 (Alpine 3.23), Laravel 13, PostgreSQL 18, Nginx и PHP-FPM. Зависимости зафиксированы в `composer.lock`.

Авторизация SPA, TOTP, LDAP/AD и Multifactor: [настройка провайдеров](docs/authentication.md). В production-входе frontend используются реальные серверные операции; демонстрационные истории Storybook сохранены отдельно.

Тенанты, их отдельные БД, worker и провайдеры: [инструкция](docs/tenancy.md). Стартовый код организации — `rline`; управление доступно через «Аккаунт → Панель администратора».

## Первый запуск

Нужен запущенный Docker Engine с Linux-контейнерами и Docker Compose. Все команды выполняются из корня этого репозитория.

Для PowerShell подготовьте настройки один раз.

```powershell
if (!(Test-Path .env)) { Copy-Item .env.example .env }
```

Для bash используйте `cp -n .env.example .env`.

```sh
docker compose build
docker compose up -d db
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose up -d --wait
```

Генерация `APP_KEY` относится к первичной настройке. При дальнейшем запуске используйте сохранённую `.env`.

В новом checkout создайте собственную учётную запись (пароль вводится скрыто), назначьте администратора и подготовьте БД стартового тенанта:

```sh
docker compose exec app php artisan auth:user-create admin@example.org --name="Администратор"
docker compose exec app php artisan tenant:platform-admin admin@example.org
docker compose exec app php artisan tenant:user-add rline admin@example.org --role=owner
docker compose exec app php artisan tenant:provision rline
```

При первом входе настройте своё приложение TOTP. Файлы локальных реквизитов из `.tools` в репозиторий не включены.

## Адреса и проверки

| Адрес | Результат |
| --- | --- |
| `http://localhost:8080/` | JSON с именем сервиса |
| `http://localhost:8080/up` | Проверка работоспособности Laravel, HTTP 200 |
| `http://localhost:8080/api/health` | Проверка соединения с БД, JSON и HTTP 200 либо 503 |

Пример успешного ответа `/api/health`.

```json
{"status":"ok","database":"connected"}
```

PostgreSQL доступен с хоста по `localhost:5433`. Внутри контейнеров используется `db:5432`. База и пользователь по умолчанию — `rline`, локальный пароль задан в `.env.example`. `APP_PORT` и `FORWARD_DB_PORT` меняют внешние порты. Оба порта привязаны к loopback-интерфейсу хоста.

Локальные настройки включают debug-режим и CORS для `localhost:5173` и `127.0.0.1:5173`. Список CORS задаётся `CORS_ALLOWED_ORIGINS`. Текущая конфигурация предназначена для разработки.

## Работа с окружением

Локальные стенды frontend: [main](http://main.rline.test), [testing](http://testing.rline.test), [demo](http://demo.rline.test). Общий backend, отдельные сборки и атомарное переключение симлинков. [Управление стендами](docs/local-stands.md).

Документация API автоматически формируется Scramble: [интерфейс](http://localhost:8080/docs/api), [OpenAPI JSON](http://localhost:8080/docs/api.json). Включены авторизация, CSRF и тенанты. Экспорт, доступ и CI описаны в [docs/api-documentation.md](docs/api-documentation.md).

```sh
docker compose up -d --wait
docker compose logs -f app nginx db worker
docker compose exec app php artisan route:list
docker compose exec app php artisan migrate:status
docker compose exec app composer lint
docker compose down
```

Исходники подключены с хоста. `vendor`, `storage` и PostgreSQL имеют отдельные именованные тома. При обновлении `composer.lock` выполните `docker compose exec app composer install`. После изменения Dockerfile пересоберите образ и пересоздайте контейнеры. После изменения `.env` пересоздайте контейнер `app`, чтобы применить переменные окружения.

`docker compose down` сохраняет тома и данные. Изменение имени/пароля базы в `.env` для уже инициализированного тома требует отдельного изменения роли/БД в PostgreSQL.

## Структура

| Путь | Назначение |
| --- | --- |
| `app`, `bootstrap`, `config`, `routes` | Приложение Laravel и HTTP API |
| `database/migrations` | Стандартные миграции Laravel для пользователей, служебного кэша и очередей |
| `docker` | Конфигурация Nginx и PHP |
| `tests/Feature` | Проверки API, доступности БД и CORS |
| `docs` | Планирование backend |

Тесты используют отдельный PostgreSQL 18 в сервисе `db-test`, базу и роль `rline_test`. Данные тестовой БД размещены в `tmpfs`. Миграции применяются автоматически через `RefreshDatabase`, отдельная проверка подтверждает драйвер `pgsql` и наличие таблиц.

```sh
docker compose --profile test up --build --abort-on-container-exit --exit-code-from test test
docker compose --profile test stop db-test
```

Первая команда запускает тестовый PostgreSQL и контейнер `test`, ожидает готовность БД и выполняет тесты. Код завершения соответствует результату тестов. Вторая останавливает тестовую БД и освобождает её `tmpfs`. Рабочие сервисы продолжают работать. Параметры подключения зафиксированы в `phpunit.xml` и тестовом сервисе.

Backend ведётся в отдельном репозитории `PandaDoddo/rline-backend`. В соседнем основном репозитории `PandaDoddo/rline` папка `backend` остаётся исключённой. `.env`, `.tools` и `vendor` не отправляются в Git. `.tools` содержит временные средства проверки на этой машине, Docker-сборка их исключает.

## Проверка подготовки от 06.11.2026

Установлен Docker Desktop, запущен Linux Engine. Docker-образ собран, три стандартные миграции применены к рабочему PostgreSQL. HTTP-проверки `/`, `/up` и `/api/health` вернули 200; health подтвердил соединение с БД. На отдельном PostgreSQL прошли 5 тестов (14 проверок), включая миграции, драйвер `pgsql`, недоступность БД и CORS. Composer validate и Pint также прошли.
