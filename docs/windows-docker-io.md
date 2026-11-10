# Windows bind mount и Linux volume — замер 10.11.2026

Исходный backend находится на NTFS (`C:\Users\dmitry\Desktop\r-line\backend`), Docker Desktop использует WSL2. Проверено чтение исходников из Windows bind mount против копии в именованном Docker volume (Linux). Рабочий API не переключался, миграции/импорт/тесты БД не запускались.

Оба варианта используют один PHP-образ `3181204881e8`, одинаковые vendor/storage volumes, конфигурацию PHP и ту же локальную PostgreSQL с rline: 241 ЕО. Сравнение касается размещения исходников; это не замер Ubuntu WSL и не замер отрисовки React. В WSL установлен только служебный docker-desktop, пользовательского Linux-дистрибутива пока нет.

## Методика и результаты

Файловый тест: 428 PHP-файлов app/config/routes/bootstrap (без bootstrap/cache); is_file + полное чтение + SHA256. Десять чередующихся проходов, первый исключён. Хеши копий совпали, ОС-кеши не сбрасывались. Медиана Windows **2992,777 мс**, Linux **9,260 мс** (323,2 раза). Это пакетный файловый тест; не переносить его коэффициент на скорость приложения.

HTTP-тест выполнен отдельно после файлового, последовательно с чередованием вариантов. На каждый endpoint два прогрева и 15 замеров. Время включает HTTP от Windows-клиента и полное чтение тела. Один вход штатной локальной учётной записью, один контекст сессии, Origin localhost:5173 и X-Tenant rline. JSON-ответы полностью совпали после нормализации только origin в ссылках пагинации.

| GET | Windows median / p95, мс | Linux median / p95, мс | По медиане |
|---|---:|---:|---:|
| /up | 137,96 / 207,04 | 15,04 / 18,96 | 9,17× |
| /api/equipment/navigation | 203,06 / 228,70 | 55,53 / 60,66 | 3,66× |
| /api/equipment | 349,96 / 414,72 | 140,51 / 193,75 | 2,49× |
| /api/equipment/{EQ0007 UUID} | 636,76 / 748,49 | 226,91 / 289,17 | 2,81× |

Файловая граница Windows/Linux — подтверждённое узкое место этого окружения. Перенос исходников в Linux даёт выигрыш API примерно 2,5–3,7 раза для проверенных предметных endpoint. Остальное время включает Laravel, SQL, сериализацию и передачу данных. Результаты одного локального окружения не гарантируют те же числа на другой машине.

Сырые локальные результаты: `.tools/io-filesystem-results.json`, `.tools/io-api-results.json`, `.tools/io-api-benchmark.log`. Секреты/сессии в результаты не записываются. Первые разведочные HTTP-прогоны отброшены: один совпал по времени с файловой нагрузкой, другой остановлен проверкой origin ссылок пагинации. Таблица содержит только последний полный отдельный прогон.

## Повторение

Из backend, Docker context desktop-linux; действующие локальные app/db и frontend localhost:5173 запущены. Используется уже собранный образ rline-backend-app:latest (образ обоих вариантов должен совпадать). Account-файл `.tools/local-account.txt` с Email/Password существует локально и игнорируется Git; скрипт не выводит его содержимое.

```powershell
docker --context desktop-linux compose -f compose.yaml -f compose.io-benchmark.yaml --profile io-benchmark up -d io-app io-nginx
docker --context desktop-linux compose -f compose.yaml -f compose.io-benchmark.yaml exec -T io-app php /benchmark/filesystem.php > .tools/io-filesystem-results.json
node tools/performance/api.mjs
```

Дождаться конца файлового теста перед HTTP. Код копируется io-sync из явно перечисленных каталогов; .env/.git/.tools не копируются. env_file передаёт обычную локальную конфигурацию, vendor readonly, storage сессий общий. Дополнительный nginx слушает только 127.0.0.1:18081. Новые workers, DB-сервисы и миграции не запускаются. Вход может создавать обычные записи сессии/аутентификации; сами тестовые запросы только GET.

После замера удалить только созданные для сравнения контейнеры и volume с копией кода:

```powershell
docker --context desktop-linux compose -f compose.yaml -f compose.io-benchmark.yaml rm -sf io-nginx io-app io-sync
docker --context desktop-linux volume rm rline-backend_io_source
```

Это также обеспечивает свежую копию при следующем запуске: tar-синхронизация поверх старой копии сама не удаляет файлы, удалённые в оригинале. Не выполнять общий down -v: рабочие volumes не относятся к эксперименту.

## Рекомендация

Для разработки — отдельный пользовательский WSL-дистрибутив и checkout в его Linux FS (`~/projects/r-line`), запуск Docker Compose/Node из Linux. Редактировать через WSL-aware editor; не переносить обратно на /mnt/c. Для стендов — собранный образ с исходниками внутри без dev bind mount. PostgreSQL/vendor/storage сохранять в volumes.

Временный Linux volume — снимок, а не автоматическая синхронизация исходников для разработки. Нельзя переключать рабочий API на него без явного процесса синхронизации: иначе он будет исполнять устаревший код. По итогам этой задачи рабочее размещение не менялось; экспериментальные контейнеры и копия удалены. Production не затронут.
