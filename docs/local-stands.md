# Локальные стенды frontend

| Адрес | Указатель сборки | Тенанты по умолчанию |
| --- | --- | --- |
| http://main.rline.test | main | Все действующие, с проверкой членства |
| http://testing.rline.test | testing | rline |
| http://demo.rline.test | demo | rline |

Все стенды используют существующий Laravel, worker и PostgreSQL из этого Compose-проекта. Данные одного тенанта общие для всех стендов; это не копии баз. Моковое рабочее пространство frontend по-прежнему находится в браузере и разделяется по origin и тенанту.

В исходной установке main/demo указывают на `initial-20261107`, testing — на `candidate-20261107`. Обе сборки сделаны из одного состояния исходников; разные идентификаторы позволяют проверить переключение.

## Подготовка Windows

Docker Desktop должен работать с Linux Engine. В PowerShell от имени администратора:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Users\dmitry\Desktop\r-line\backend\deploy\setup-hosts.ps1"
```

Скрипт сохраняет резервную копию hosts, добавляет отсутствующие записи 127.0.0.1 и очищает DNS-кеш. Повторный запуск не создаёт дубликаты; конфликтующий IP останавливает выполнение.

Если используется системный прокси, добавьте `main.rline.test;testing.rline.test;demo.rline.test` в его исключения. На текущей машине исключения уже добавлены, остальные настройки сохранены. Порт 80 привязан только к 127.0.0.1; с других компьютеров стенды не опубликованы.

## Управление

Краткий цикл: `Publish -Stand testing -Release test-002` → проверка testing → `Promote -Stand main -Release test-002`. Один билд можно назначить нескольким стендам; Promote не пересобирает исходники. Публикуются текущие файлы frontend на диске, включая незакоммиченные изменения. Git-ветка не является привязкой стенда: привязка хранится в симлинке на идентификатор релиза.

Из каталога backend, обычный PowerShell:

```powershell
# Запустить стенды, существующий backend и worker.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\deploy\stand.ps1 -Action Start

# Собрать frontend, опубликовать неизменяемый релиз и переключить testing.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\deploy\stand.ps1 -Action Publish -Stand testing -Release test-001

# Переключить main на уже проверенную сборку без повторной сборки.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\deploy\stand.ps1 -Action Promote -Stand main -Release test-001

# Вернуть main к сохранённой сборке.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\deploy\stand.ps1 -Action Promote -Stand main -Release initial-20261107

powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\deploy\stand.ps1 -Action Status
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\deploy\stand.ps1 -Action Stop
```

Без `-Release` публикация создаёт идентификатор по времени. Повторно публиковать существующий идентификатор нельзя. Нужны установленные зависимости frontend (`npm ci` в frontend при первой подготовке). Скрипт использует Docker context `desktop-linux`; другой задаётся через `-DockerContext`.

По умолчанию исходники frontend ищутся в соседней папке `../frontend`. Для отдельных checkout задайте `-FrontendPath "C:\path\to\rline\frontend"` при публикации. Backend не включает исходники frontend в свой Git-репозиторий.

Сборки и относительные симлинки находятся в Linux-томе `stand_releases` данного Compose-проекта. Nginx видит его только для чтения, менеджер релизов — для записи. Переключение выполняется атомарным переименованием симлинка. Это не перезапускает backend и не применяет миграции. Статус текущей сборки: `/__release.json` на каждом адресе.

Vite собирается с base `/releases/<id>/`. Старые ассеты сохраняются для открытых вкладок и отложенной загрузки модулей. Для index.html установлен no-store, для версионированных файлов — immutable. Удаление тома `stand_releases` удалит все опубликованные сборки; команда Stop том не удаляет. `docker compose down --volumes` удаляет также рабочие данные — для остановки стендов она не нужна.

## Тенанты и авторизация

Разрешённые тенанты задаются в backend/.env:

```dotenv
STAND_TESTING_TENANTS=rline,customer-a
STAND_DEMO_TENANTS=demo-company
```

Тенанты должны уже существовать. Пустая строка запрещает все тенанты на соответствующем стенде. После изменения переменных пересоздайте app/worker; при использовании config:cache пересоберите также кеш конфигурации. Политика main и имена хостов находятся в `config/stands.php` и `deploy/nginx-stands.conf`.

Laravel определяет стенд по имени хоста, проверяет allowlist при выборе тенанта, фильтрует список организаций и ограничивает операции с целевыми организациями. Клиентский X-RLine-Stand игнорируется. Ограничения стенда не предоставляют членство и не отменяют проверку прав. Основной адрес допускает все организации, поэтому стенд не является самостоятельной границей безопасности или заменой авторизации.

Sanctum принимает эти три хоста, cookie по умолчанию host-only (SESSION_DOMAIN не задан). Не задавайте общий SESSION_DOMAIN=.rline.test. Для внешнего OIDC/Multifactor нужно отдельно согласовать callback URL; реальные внешние подключения сейчас не настроены. Используется локальный HTTP, не конфигурация публичного production.

Backend остаётся общим по коду и данным. Его изменения должны сохранять совместимость со всеми активными сборками. Для проверки несовместимых backend-изменений потребуется отдельный экземпляр backend и подходящие базы.

## Проверки

Проверены переключение/возврат testing, сохранение ассетов предыдущего релиза, 404 для отсутствующих ассетов, отрисовка React на всех хостах, POST выбора тенанта с CSRF и независимые host-only session cookie. Тесты StandAccessTest проверяют ограничения по заголовку/телу/сеансу, неизвестный хост, пустой allowlist, операции администратора и сохранение проверки членства.
