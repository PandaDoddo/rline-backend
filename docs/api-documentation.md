# Автоматическая документация API

Scramble 0.13.53 строит OpenAPI 3.1 из маршрутов, правил валидации и кода ответов Laravel.

- Интерфейс: [http://localhost:8080/docs/api](http://localhost:8080/docs/api).
- Спецификация: [http://localhost:8080/docs/api.json](http://localhost:8080/docs/api.json).
- Включены `/api/*`, `/auth/*` и `/sanctum/csrf-cookie`.

Страница доступна в окружении `local`. В production оба адреса по умолчанию возвращают 403. Для открытия документации в другом окружении требуется явная политика доступа `viewApiDocs`, поддерживаемая Scramble.

## Проверка запросов

Авторизация использует session cookie Sanctum/Fortify. Страница получает CSRF-cookie и автоматически передаёт `X-XSRF-TOKEN` и cookie в запросах к своему серверу. Последовательность входа описана в начале документации: выбор организации, получение провайдеров, вход и подтверждение TOTP. `X-Tenant` должен соответствовать организации сеанса.

Запросы **Try It** выполняются на настоящем локальном backend и могут менять данные. Для LDAP/AD и Multifactor нужны доступные внешние серверы; наличие описания маршрута не означает настроенное внешнее подключение.

Интерфейс Stoplight Elements 8.4.2 и лицензии включены в `public/vendor/stoplight-elements/8.4.2`. Внешний CDN для открытия документации не требуется.

## Экспорт и CI

```sh
docker compose exec app composer docs:check
```

Команда экспортирует `build/openapi.json` и запускает `scramble:analyze --fail-on-empty`. Каталог `build` исключён из Git. Для одного экспорта используйте `composer docs:export` внутри контейнера.

Workflow `.github/workflows/backend.yml` при push и pull request собирает Docker-образ, проверяет Composer/Pint, запускает тесты на отдельном PostgreSQL, применяет миграции в тестовой БД и экспортирует спецификацию. Результат публикуется как артефакт `rline-openapi`. Рабочая БД в CI не используется.

`ApiDocumentationTest` проверяет покрытие всех HTTP-методов выбранных маршрутов, уникальность operationId, описание cookie/CSRF/тенантов, вложенные настройки провайдеров, закрытие документации в production и отсутствие секретов моделей в схеме.

## Поддержка

Для новых API используйте типизированные ответы, модели с корректным `$hidden`, inline-валидацию или FormRequest. Правила вложенных настроек провайдеров находятся в `TenantAuthSettings` и используются одновременно приложением и генератором.

Общие заголовки, ошибки и особенности Fortify описаны в `app/Documentation`. Scramble не может полностью вывести контракты Fortify через интерфейсы ответов и actions: адаптер дополняет поля запросов, провайдер входа и HTTP-статусы. При изменении этих сценариев обновляйте адаптеры и тесты. Диагностический `scramble:analyze --fail-on-unknown` может сообщать о неизвестных типах исходного vendor-кода до применения этих дополнений; основной CI проверяет отсутствие пустых ответов и итоговые контракты тестами.

Настройки генерации — `config/scramble.php`, вступление — `resources/docs/api-introduction.md`, шаблон интерфейса — `resources/views/docs/api.blade.php`.

Официальные источники: [Scramble](https://scramble.dedoc.co/), [Stoplight Elements](https://github.com/stoplightio/elements).
