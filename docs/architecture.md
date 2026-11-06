# Архитектура ИС RISK-Line

**Дата:** 04.11.2026<br>
**Статус:** v1.3 — бэкенд определён (PHP/Laravel)

---

## 1. Общие положения

### 1.1. Тип системы
SaaS-платформа для управления техническим состоянием и рисками оборудования на ОПО.

### 1.2. География

```
Phase 1 (сейчас):   Россия
Phase 2 (future):   СНГ
Phase 3 (future):   Европа
```

### 1.3. Целевые клиенты

| Тип | Требования |
|---|---|
| **Госпредприятия** | 187-ФЗ, ФСТЭК 117 (2025), реестр ПО, КИИ |
| **Частные** | softer требования, базовая безопасность |

### 1.4. Масштаб
~50 клиентов (tenant), каждый — от 1 до N предприятий, в сумме до 50 000 единиц оборудования

### 1.5. SLA
**Целевой: 99.9999%** (six nines) — ~31.5 секунд простоя в год.
Планировочный: начать с 99.99% (52 мин/год), расти.

### 1.6. Ключевые принципы
- Каждая tenant — отдельная БД в кластере (physical isolation)
- Все расчёты на бэкенде (25 JS-модулей → PHP)
- Plugin-based auth (динамическое добавление провайдеров)
- Laravel MVP → future migration (Symfony / Mezzio/Laminas)
- Cloud-native + on-premise-ready (Docker Compose)
- Миграция существующих данных тестового предприятия
- Region-aware (готовность к мульти-региону)

---

## 2. Бэкенд: Laravel

### 2.1. Почему Laravel

| Критерий | Laravel |
|---|---|
| **Команда** | ✅ PHP-разработчики в команде |
| **Скорость** | MVP за 4 спринта (16 недель) |
| **Multi-tenant** | stancl/tenancy — out of the box |
| **On-premise** | Nginx + PHP-FPM — тривиально |
| **PostgreSQL** | Отличная поддержка через Eloquent ORM |
| **Hiring в РФ** | ✅ Много PHP-разработчиков |
| **Rejest российского ПО** | ✅ Многие пакеты в реестре |
| **PHP 8.3+** | ✅ Strong types, readonly, enums, JIT |

### 2.2. Эволюция стека

```
Phase 1 (MVP, сейчас):     Laravel 11 + PHP 8.3
                            - Быстрый старт
                            - Всё из коробки
                            - stancl/tenancy, spatie/*

Phase 2 (growth):          Laravel 11 / 12
                            - Доработка
                            - Оптимизация
                            - KII compliance

Phase 3 (enterprise):      Symfony 7+ / Mezzio/Laminas (опционально)
                            - Если Laravel не справится
                            - Более строгая архитектура
                            - Больше контроля за кодом
```

**Зачем будущее на Symfony/Mezzio?**
- Symfony: более строгая архитектура, better for large enterprise
- Mezzio/Laminas: PSR-15 middleware, microservice-friendly
- **Но:** Laravel тоже масштабируется, migration не обязательна

---

## 3. Laravel Architecture

### 3.1. Структура проекта

```
risk-line/
├── app/
│   ├── Models/
│   │   ├── Tenant.php              — tenant (shared_db)
│   │   ├── Equipment.php           — единицы оборудования
│   │   ├── Component.php           — компоненты
│   │   ├── TmData.php              — толщинометрия
│   │   ├── CrData.php              — коррозия и ресурс
│   │   ├── Flow.php                — технологические потоки
│   │   ├── CalculationResult.php   — результаты расчётов
│   │   ├── DiagHistory.php         — история диагностики
│   │   ├── CardOverride.php        — переопределения карточек
│   │   ├── Reference.php           — локальные справочники
│   │   ├── Installation.php        — установки
│   │   ├── User.php                — пользователи
│   │   └── AuditLog.php            — журнал изменений
│   │
│   ├── Services/
│   │   ├── EquipmentService.php    — бизнес-логика оборудования
│   │   ├── CardOverrideService.php — переопределения карточек
│   │   ├── TenantService.php       — управление tenant
│   │   ├── FlowService.php         — потоки
│   │   └── ExportService.php       — экспорт (Excel, PDF)
│   │
│   ├── Calculations/               ← перенесённые JS модули
│   │   ├── DfThin.php
│   │   ├── DfExtCorr.php
│   │   ├── DfClScc.php
│   │   ├── DfScc.php               ← SSC, HIC, HSC
│   │   ├── DfScc2.php              ← ACSCC, Amine, Caustic, HTHA, PASCC
│   │   ├── DfEmb.php               ← Brittle, TE, 885, Sigma
│   │   ├── CofCalc.php             ← 12 этапов
│   │   ├── PofCalc.php             ← gff, FMS, PoF
│   │   └── RiskCalc.php            ← PoF × DF × CoF
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/
│   │   │   │   ├── EquipmentController.php
│   │   │   │   ├── ComponentController.php
│   │   │   │   ├── TmDataController.php
│   │   │   │   ├── CalculationController.php
│   │   │   │   ├── FlowController.php
│   │   │   │   ├── CardController.php
│   │   │   │   └── TenantController.php
│   │   │   └── AuthController.php
│   │   ├── Middleware/
│   │   │   ├── TenantMiddleware.php    — resolution + DB switching
│   │   │   ├── AuthMiddleware.php      — JWT validation
│   │   │   └── AuditMiddleware.php     — logging
│   │   ├── Resources/                  — API Resources (JSON)
│   │   └── Requests/                   — validation
│   │
│   ├── Jobs/
│   │   ├── CalculateDfJob.php
│   │   ├── CalculateCoFJob.php
│   │   ├── CalculatePofJob.php
│   │   └── CalculateRiskJob.php
│   │
│   ├── Tenancy/
│   │   ├── TenantManager.php
│   │   ├── DatabaseTenantResolver.php
│   │   └── TenantSwitcher.php
│   │
│   └── Auth/
│       ├── Providers/
│       │   ├── LocalAuthProvider.php
│       │   ├── LdapAuthProvider.php
│       │   └── AdAuthProvider.php
│       └── AuthPluginManager.php
│
├── config/
│   ├── tenancy.php           — stancl/tenancy config
│   ├── auth.php              — Sanctum/Passport
│   ├── database.php          — multi-DB config
│   └── calculations/         — табличные данные для расчётов
│       ├── df_materials.php
│       ├── df_coefficients.php
│       └── cof_constants.php
│
├── database/
│   ├── migrations/
│   │   ├── 0001_01_01_000000_create_tenants_table.php
│   │   ├── 0001_01_01_000001_create_users_table.php
│   │   └── ...
│   ├── tenant_migrations/    — миграции для tenant DB
│   └── seeders/
│       ├── DatabaseSeeder.php
│       └── TestEnterpriseSeeder.php  — тестовое предприятие
│
├── routes/
│   ├── api.php               — API routes (tenant-scoped)
│   └── web.php               — web routes (if needed)
│
├── resources/
│   └── views/                — Blade templates (if SSR needed)
│
├── tests/
│   ├── Feature/
│   │   ├── CalculationTest.php
│   │   └── MultiTenantTest.php
│   └── Unit/
│       ├── DfThinTest.php
│       ├── CofCalcTest.php
│       └── ExcelComparisonTest.php  — сверка с Excel
│
├── docker/
│   ├── nginx/
│   │   └── default.conf
│   ├── php/
│   │   ├── Dockerfile
│   │   └── php.ini
│   └── postgres/
│       └── init.sql
│
├── docker-compose.yml        — dev environment
├── docker-compose.prod.yml   — production (on-premise)
└── README.md
```

### 3.2. Multi-tenant: stancl/tenancy

Реализация 06.11.2026: общие users/user_tenants, отдельные БД и роли PostgreSQL, Laravel TenantContext и провайдеры по тенанту описаны в [tenancy.md](tenancy.md). Ниже сохранён исходный план со stancl/tenancy/JWT; текущий вход — Sanctum cookie/session, пакет stancl не установлен.

```php
// composer.json
"require": {
    "stancl/tenancy": "^3.0"
}

// config/tenancy.php
return [
    'tenant_model' => App\Models\Tenant::class,
    'id_column' => 'slug',
    'database' => [
        'management_connection' => env('DB_CONNECTION', 'pgsql'),
        'prefix' => 'tenant_',
        'suffix' => '',
    ],
    'routes' => [
        'register' => true,
        'middleware' => ['api'],
    ],
    'tenancy_init' => [
        Stancl\Tenancy\InitTenancy::class,
    ],
];

// Middleware для переключения БД
class TenantMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Resolve tenant from JWT
        $tenant = $this->tenantResolver->resolve();

        // 2. Switch database connection
        config(['database.connections.tenant' => [
            'driver' => 'pgsql',
            'host' => $tenant->db_host,
            'database' => $tenant->db_name,
            'username' => $tenant->db_user,
            'password' => $tenant->db_password,
        ]]);

        // 3. Set the active connection
        config(['database.default' => 'tenant']);

        return $next($request);
    }
}
```

### 3.3. Расчётные ядра в PHP

```php
// app/Calculations/DfThin.php
class DfThin
{
    use CalculatesDf;

    protected static array $tables = [];

    /**
     * Расчёт DF утонения (API 581, ч.2, 4.5/4.6/4.9)
     */
    public static function calculate(array $input): array
    {
        $material = self::getMaterial($input['material']);
        $age = self::calculateAge($input['yearOfConstruction'], $input['currentYear']);

        // Step 1: Corrosion rate
        $vLong = self::longTermCorrosion($input);
        $vShort = self::shortTermCorrosion($input);
        $vAccepted = max($vLong, $vShort, $input['designCorrosion']);

        // Step 2: Attack depth
        $attackDepth = max($vAccepted * $age, 0);

        // Step 3: SRP (Surveillance Risk Program)
        $srp = self::calculateSRPThin($input, $attackDepth);

        // Step 4: FOM (Frequency of Monitoring)
        $fom = self::getFOM($material, $srp);

        // Step 5: DF
        $df = $srp ? $fom * $srp : null;

        return [
            'df' => round($df, 2),
            'srp' => $srp,
            'vAccepted' => $vAccepted,
            'attackDepth' => $attackDepth,
            'fom' => $fom,
            'steps' => self::getCalculationSteps(),
        ];
    }

    protected static function getMaterial(string $material): array
    {
        return self::$tables['materials'][$material] ?? self::$tables['defaultMaterial'];
    }
}
```

**Конвертация JS → PHP:**
- `function` → `public static function`
- `const/let/var` → `protected static array`
- `if (x === y)` → `if ($x === $y)`
- `obj.prop` → `$obj['prop']`
- `array.map()` → `array_map()`
- Таблицы из JS → PHP `config/calculations/*.php`

---

## 4. Plugin-based Auth System

Актуализация 06.11.2026: реализованные подключения, настройки и исправления списка провайдеров описаны в [authentication.md](authentication.md). Примеры ниже — исходный архитектурный план; Ассистент и BearPass не подтверждены как поставщики идентификации для приложения.

### 4.1. Архитектура

```php
// app/Auth/Contracts/AuthProviderInterface.php
interface AuthProviderInterface
{
    public function validate(string $username, string $password): AuthResult;
    public function getUserInfo(string $token): UserInfo;
    public function getGroups(string $token): array;
    public function syncUsers(): array;
}

// Built-in providers
// app/Auth/Providers/LocalAuthProvider.php
// app/Auth/Providers/LdapAuthProvider.php
// app/Auth/Providers/AdAuthProvider.php

// Future plugins (Russian auth ecosystem)
// app/Auth/Providers/AssistentAuthProvider.php  — Ассистент (Астерит)
// app/Auth/Providers/MultifactorAuthProvider.php — Multifactor
// app/Auth/Providers/BearpassAuthProvider.php    — BearPass
```

### 4.2. AuthPluginManager

```php
// app/Auth/AuthPluginManager.php
class AuthPluginManager
{
    protected array $plugins = [];

    public function register(string $id, AuthProviderInterface $provider): void
    {
        $this->plugins[$id] = $provider;
    }

    public function resolve(string $providerId): AuthProviderInterface
    {
        return $this->plugins[$providerId]
            ?? throw new InvalidArgumentException("Provider {$providerId} not registered");
    }

    public function list(): array
    {
        return array_keys($this->plugins);
    }
}
```

### 4.3. Tenant auth config

```json
{
  "strategy": "mixed",
  "providers": {
    "local": {
      "enabled": true,
      "fallback": true,
      "password_policy": {
        "min_length": 8,
        "require_upper": true
      }
    },
    "ldap": {
      "enabled": true,
      "host": "ldap.corp.local",
      "port": 389,
      "base_dn": "OU=Users,DC=corp,DC=local",
      "group_mapping": {
        "RISK-Admins": "admin",
        "RISK-Engineers": "engineer"
      }
    }
  },
  "fallback_to_local": true
}
```

---

## 5. Database Schema

### 5.1. shared_db (global)

```sql
-- shared_db.tenants
CREATE TABLE tenants (
    id UUID PRIMARY KEY,
    slug VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    region VARCHAR(10) DEFAULT 'ru',
    auth_strategy VARCHAR(20) DEFAULT 'local',
    auth_config JSONB,
    migration_version INTEGER DEFAULT 1,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- shared_db.users (global users, cross-tenant)
CREATE TABLE users (
    id UUID PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255),
    auth_method VARCHAR(20),
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- shared_db.user_tenants
CREATE TABLE user_tenants (
    user_id UUID REFERENCES users(id),
    tenant_id UUID REFERENCES tenants(id),
    roles TEXT[],
    is_admin BOOLEAN DEFAULT false,
    PRIMARY KEY (user_id, tenant_id)
);

-- shared_db.audit_log (global audit)
CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY,
    tenant_id UUID REFERENCES tenants(id),
    user_id UUID REFERENCES users(id),
    action VARCHAR(50),
    entity VARCHAR(50),
    entity_id UUID,
    changes JSONB,
    ip_address INET,
    user_agent TEXT,
    created_at TIMESTAMP
);
```

### 5.2. tenant_db (per tenant)

```sql
-- tenant_db.equipment
CREATE TABLE equipment (
    id UUID PRIMARY KEY,
    tag VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    group_type VARCHAR(50),
    profile VARCHAR(100),
    installation_id UUID,
    year_of_construction INTEGER,
    pressure_rating DECIMAL(10,3),
    temperature_rating DECIMAL(10,3),
    volume DECIMAL(10,3),
    material_id UUID,
    environment_id UUID,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- tenant_db.components
CREATE TABLE components (
    id UUID PRIMARY KEY,
    equipment_id UUID REFERENCES equipment(id),
    type VARCHAR(50),
    sequence INTEGER,
    diameter DECIMAL(10,3),
    nominal_thickness DECIMAL(10,3),
    length DECIMAL(10,3),
    material_id UUID
);

-- tenant_db.tm_data (толщинометрия)
CREATE TABLE tm_data (
    id BIGSERIAL PRIMARY KEY,
    equipment_id UUID REFERENCES equipment(id),
    cml_id VARCHAR(100),
    measurement_date DATE NOT NULL,
    measurements JSONB,
    min_thickness DECIMAL(10,3),
    ave_thickness DECIMAL(10,3),
    max_thickness DECIMAL(10,3),
    v_long DECIMAL(10,3),
    v_short DECIMAL(10,3),
    anomaly_flags JSONB,
    created_at TIMESTAMP
);

-- tenant_db.calc_results
CREATE TABLE calc_results (
    id UUID PRIMARY KEY,
    equipment_id UUID REFERENCES equipment(id),
    calc_type VARCHAR(20),  -- df, cof, pof, risk
    engine_version VARCHAR(50),
    input_hash VARCHAR(64),
    output JSONB,
    calculated_at TIMESTAMP,
    calculated_by UUID REFERENCES users(id)
);
```

---

## 6. Расчётные ядра: миграция JS → PHP

### 6.1. Модули для переноса

| Файл | Расчёт | Приоритет |
|---|---|---|
| dfthin.js | DF утонения | P0 |
| dfext.js | DF наружной коррозии | P0 |
| dfcl.js | DF ClSCC (внешний) | P0 |
| dfscc.js | DF SSC/HIC/HSC | P0 |
| dfscc2.js | DF ACSCC/Amine/Caustic/HTHA/PASCC | P0 |
| dfemb.js | DF Brittle/TE/885/Sigma | P0 |
| cofcalc.js | CoF (последствия) | P0 |
| pofcalc.js | PoF (вероятность) | P0 |
| riskcalc.js | RBI Risk = PoF × CoF | P0 |
| fincost.js | Финансовые коэффициенты | P1 |
| ppkcalc.js | Расчёт ППК | P1 |
| sanpin21.js | СанПиН 1.2.3685-21 | P1 |
| mscheck.js | Чек-лист систем управления | P1 |

### 6.2. Пример конвертации

```javascript
// dfthin.js (original)
function calculateDF(input) {
    let age = input.currentYear - input.yearOfConstruction;
    let vLong = Math.max(...input.measurements.map(m => m.vLong));
    let vShort = Math.max(...input.measurements.slice(-2).map((m, i, arr) =>
        i > 0 ? (arr[i-1].s - m.s) / (m.date - arr[i-1].date) : 0
    ));
    let vAccepted = Math.max(vLong, vShort, input.designCorrosion);
    let attackDepth = Math.max(vAccepted * age, 0);
    let srp = input.thickness <= input.rejectionThickness ? 0 : 1;
    let fom = getFOM(input.material, srp);
    return srp ? fom * srp : null;
}
```

```php
// DfThin.php (converted)
class DfThin
{
    public static function calculate(array $input): array
    {
        $age = $input['currentYear'] - $input['yearOfConstruction'];
        $vLong = max(array_map(fn($m) => $m['vLong'], $input['measurements']));
        $vShort = max(array_slice($input['measurements'], -2)
            ?-> map(fn($m, $i, $arr) => $i > 0
                ? ($arr[$i-1]['s'] - $m['s']) / ($m['date'] - $arr[$i-1]['date'])
                : 0)
            ?? [0]);
        $vAccepted = max($vLong, $vShort, $input['designCorrosion']);
        $attackDepth = max($vAccepted * $age, 0);
        $srp = $input['thickness'] <= $input['rejectionThickness'] ? 0 : 1;
        $fom = self::getFOM($input['material'], $srp);
        return $srp ? $fom * $srp : null;
    }
}
```

### 6.3. Оценка времени миграции

| Модуль | Сложность | Время |
|---|---|---|
| dfthin, dfext, dfcl | Низкая | 3 дня |
| dfscc, dfscc2 | Средняя | 5 дней |
| dfemb | Средняя | 4 дня |
| cofcalc | Средняя | 4 дня |
| pofcalc | Низкая | 2 дня |
| riskcalc | Низкая | 2 дня |
| fincost, ppkcalc, sanpin21 | Низкая | 3 дня |
| **Итого** | | **~23 дня** |

---

## 7. On-premise: Docker Compose

```yaml
version: '3.8'

services:
  nginx:
    image: nginx:1.25-alpine
    ports:
      - "443:443"
    volumes:
      - ./certs:/etc/nginx/certs
      - ./nginx.conf:/etc/nginx/nginx.conf

  php:
    build: ./docker/php
    volumes:
      - ./app:/app
    environment:
      - DB_HOST=postgres
      - REDIS_HOST=redis
      - QUEUE_DRIVER=redis
    depends_on:
      - postgres
      - redis

  postgres:
    image: postgres:16-alpine
    environment:
      - POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
    volumes:
      - pgdata:/var/lib/postgresql/data

  redis:
    image: redis:7-alpine

  worker:
    build: ./docker/php
    command: php artisan queue:work --queue=default,calculations
    volumes:
      - ./app:/app
    environment:
      - DB_HOST=postgres
      - REDIS_HOST=redis
      - QUEUE_DRIVER=redis

volumes:
  pgdata:
```

**Запуск:**
```bash
# 1. Скопировать на сервер
scp -r risk-line-onpremise.tar.gz user@server:/opt/

# 2. Распаковать
tar xzf risk-line-onpremise.tar.gz -C /opt/
cd /opt/risk-line-onpremise

# 3. Запустить
docker compose up -d

# 4. Инициализировать
docker compose exec php php artisan migrate --seed
```

---

## 8. Frontend

### 8.1. Технологический стек

| Компонент | Технология |
|---|---|
| Framework | React + TypeScript (Next.js) |
| State | Zustand |
| HTTP | TanStack Query |
| Forms | React Hook Form + Zod |
| UI | Custom (сохранение фирменных стилей) |
| Tables | TanStack Table |
| Offline | Workbox (service worker → IndexedDB sync) |

### 8.2. Мобильное приложение (future)

```
Mobile App (future):
  - React Native (cross-platform)
  - Offline-first (IndexedDB → sync)
  - Features:
    - Просмотр оборудования
    - Ввод замеров толщинометрии
    - ВИК (наружная коррозия)
    - Фотофиксация
    - Geo-location
```

---

## 9. Требования КИИ — roadmap

### 9.1. Этапы

| Этап | Что делаем | Когда |
|---|---|---|
| **Stage 0** | Базовая безопасность, аудит, бэкапы | Сейчас |
| **Stage 1** (КИИ ready) | Шифрование, RBAC, журнал, мониторинг | v1.0 |
| **Stage 2** (КИИ compliant) | ФСТЭК 117, сертифицированные средства | v2.0 |
| **Stage 3** (реестр ПО) | Замена на сертифицированное ПО | v3.0 |

### 9.2. Ключевые требования ФСТЭК 117

```
1. Многофакторная аутентификация — для КИИ I/II класса
2. Контроль сложности паролей
3. Ограничение попыток входа
4. Шифрование паролей (bcrypt/Argon2)
5. Журналирование попыток входа
6. Периодическая смена пароля (90 дней)
```

### 9.3. Plugin system для российских auth решений

| Plugin | Решение | Статус |
|---|---|---|
| local | email/pass | ✅ Built-in |
| ldap | RFC 4513 | ✅ Built-in |
| ad | Active Directory | ✅ Built-in |
| assistent | Ассистент (Астерит) | 🔜 Future (сертификат ФСТЭК) |
| multifactor | Multifactor | 🔜 Future (в реестре) |
| bearpass | BearPass | 🔜 Future (в реестре) |
| multi-directory | MultiDirectory | 🔜 Future (в реестре) |
| ald-pro | ALD Pro | 🔜 Future (в реестре) |

---

## 10. Миграция данных

### 10.1. Источник

rldata.js (~3 МБ):
- Equipment cards
- Components
- TM data
- Calc results
- References
- Flow data

### 10.2. Процесс

```
migration/
├── 001_create_schema.sql
├── 002_seed_tenant.sql
├── 003_import_equipment.sql
├── 004_import_components.sql
├── 005_import_tm_data.sql
├── 006_import_calc_results.sql
└── 007_import_refs.sql
```

---

## 11. План разработки (спринты)

### Спринт 1 (недели 1-4): Foundation

1. **Laravel project** — scaffold, CI/CD, linting
2. **PostgreSQL schema** — shared_db + tenant template
3. **Auth service** — JWT, local auth, bcrypt
4. **Core service** — equipment CRUD, card overrides
5. **Migration script** — rldata.js → PostgreSQL (тестовое предприятие)
6. **Docker Compose** — полный стек для dev/prod

### Спринт 2 (недели 5-8): Calculations

7. **Calc service** — dfthin, dfext, dfcl (P0)
8. **Calc service** — dfscc, dfscc2, dfemb (P0)
9. **Calc service** — cofcalc, pofcalc, riskcalc (P0)
10. **Tests** — snapshot tests vs Excel
11. **API docs** — Swagger/OpenAPI

### Спринт 3 (недели 9-12): Frontend + Deploy

12. **React/TypeScript** — scaffold, auth integration
13. **Equipment list** — реестр
14. **Equipment card** — паспорт, компоненты
15. **Docker Compose** — production-ready
16. **Monitoring** — Prometheus + Grafana

### Спринт 4 (недели 13-16): KII + Polish

17. **LDAP/AD** — built-in plugins
18. **Audit log** — per tenant
19. **Backup/restore** — automation
20. **On-premise package** — docker-compose + scripts
21. **HA** — 2 AZ, Patroni, PgBouncer

---

## 12. Open Questions

1. **Очередь расчётов** — RabbitMQ или Laravel Database queue?
2. **Производительность** — сколько расчётов в секунду нужно?
3. **On-premise — Docker Compose vs K8s** — для крупных клиентов K8s?
4. **Специфичные LDAP/AD** — какие именно системы используются у клиентов?
5. **Миграция на Symfony** — когда и почему? (пока не планировать)
