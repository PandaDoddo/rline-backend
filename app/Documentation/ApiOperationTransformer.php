<?php

namespace App\Documentation;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

class ApiOperationTransformer implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $uri = $routeInfo->route->uri();
        if ($uri === 'api/process-flows/schema') {
            $labels = new ObjectType;
            $labels->additionalProperties = new StringType;
            $field = (new ObjectType)->addProperty('key', (new StringType)->enum(array_keys(config('process-flows.fields'))))->addProperty('label', new StringType)->addProperty('type', (new StringType)->enum(['number', 'text', 'choice']))->addProperty('unit', new StringType)->addProperty('options', (new ArrayType)->setItems(new StringType))->setRequired(['key', 'label', 'type']);
            $schema = (new ObjectType)->addProperty('categories', $labels)->addProperty('media', $labels->clone())->addProperty('fields', (new ArrayType)->setItems($field))->addProperty('impurity_units', (new ArrayType)->setItems(new StringType))->addProperty('screening', FlowScreeningSchema::baseline())->setRequired(['categories', 'media', 'fields', 'impurity_units', 'screening']);
            $operation->responses = array_values(array_filter($operation->responses, fn ($response) => ($response->code ?? null) != 200));
            $operation->addResponse((new Response(200))->setDescription('Поля потока и версионированная база первичного скрининга.')->setContent('application/json', Schema::fromType($schema)));
        }
        $hasTenant = ! in_array($uri, ['api/health', 'api/public/tenants', 'sanctum/csrf-cookie'], true);
        $mutating = ! in_array(strtoupper($operation->method), ['GET', 'HEAD', 'OPTIONS'], true);
        $fortifyFields = match ($uri) {
            'auth/reset-password' => ['token', 'email', 'password', 'password_confirmation'],
            'auth/user/password' => ['current_password', 'password', 'password_confirmation'],
            'auth/user/profile-information' => ['name', 'email'],
            'auth/user/confirm-password' => ['password'],
            default => [],
        };
        if ($fortifyFields) {
            $type = new ObjectType;
            foreach ($fortifyFields as $field) {
                $property = new StringType;
                if (str_contains($field, 'password')) {
                    $property->format('password');
                }
                if ($field === 'email') {
                    $property->format('email');
                }
                $type->addProperty($field, $property);
            }
            $type->setRequired($fortifyFields);
            $operation->addRequestBodyObject((new RequestBodyObject)->required()->setContent('application/json', Schema::fromType($type)));
            $this->error($operation, 422, 'Проверка реквизитов или полей не пройдена.');
        }
        if ($uri === 'auth/tenant') {
            $type = (new ObjectType)->addProperty('tenant', (new StringType)->example('rline')->setDescription('Код организации; должен совпадать с X-Tenant, если заголовок задан.'));
            $operation->addRequestBodyObject((new RequestBodyObject)->setContent('application/json', Schema::fromType($type)));
        }
        if ($uri === 'auth/multifactor/callback') {
            $operation->addParameters([
                (new Parameter('code', 'query'))->required(true)->setSchema(Schema::fromType(new StringType)),
                (new Parameter('state', 'query'))->required(true)->setSchema(Schema::fromType(new StringType)),
            ]);
        }
        // Fortify returns response contracts; their concrete status is not inferable from the interface.
        $status = match ($uri) {
            'auth/logout', 'auth/two-factor-challenge' => 204,
            'auth/user/confirm-password' => 201,
            default => null,
        };
        if ($status) {
            $operation->responses = array_values(array_filter($operation->responses, fn ($response) => ($response->code ?? null) != 200));
            $response = (new Response($status))->setDescription('Операция выполнена.');
            if ($status === 201) {
                $response->setContent('application/json', Schema::fromType(new StringType));
            }
            $operation->addResponse($response);
        }
        if ($hasTenant) {
            if ($mutating && (str_starts_with($uri, 'api/equipment') || str_starts_with($uri, 'api/installations') || str_starts_with($uri, 'api/facility-nodes') || str_starts_with($uri, 'api/substances') || str_starts_with($uri, 'api/steel-grades') || str_starts_with($uri, 'api/damage-mechanisms'))) {
                $this->error($operation, 409, 'Версия записи устарела; обновите данные, сохранив черновик. Возможна также смена тенанта сеанса.');
            }
            $operation->addParameters([(new Parameter('X-Tenant', 'header'))->setSchema(Schema::fromType(new StringType))
                ->description('Код организации. Должен совпадать с тенантом сеанса; без заголовка используется сеанс, затем rline.')->example('rline')]);
            foreach ([401 => 'Сеанс завершён или настройки входа изменились.', 404 => 'Организация или объект недоступны.', 409 => 'Тенант запроса отличается от тенанта сеанса.'] as $code => $description) {
                $this->error($operation, $code, $description);
            }
        }
        if ($mutating) {
            $operation->addParameters([(new Parameter('X-XSRF-TOKEN', 'header'))->required(true)->setSchema(Schema::fromType(new StringType))
                ->description('URL-decoded cookie XSRF-TOKEN после GET /sanctum/csrf-cookie. Страница документации подставляет заголовок автоматически.')]);
            $this->error($operation, 419, 'Недействительный CSRF-токен.');
        }
        if (collect($routeInfo->route->gatherMiddleware())->contains(fn ($value) => is_string($value) && str_starts_with($value, 'throttle:'))) {
            $this->error($operation, 429, 'Превышено число попыток.');
        }
        if ($uri === 'auth/two-factor-challenge') {
            $this->error($operation, 410, 'Подтверждение истекло или больше недействительно.');
        }
        $featureTag = match (true) {
            str_starts_with($uri, 'api/substances') => 'Каталог веществ',
            str_starts_with($uri, 'api/steel-grades') => 'Марочник',
            str_starts_with($uri, 'api/damage-mechanisms') => 'Механизмы деградации',
            str_starts_with($uri, 'api/diagnostic-schedule') => 'Диагностика',
            str_starts_with($uri, 'api/equipment') => 'Оборудование',
            str_starts_with($uri, 'api/installations'), str_starts_with($uri, 'api/facility-nodes') => 'Установки',
            default => null,
        };
        if ($featureTag) {
            $operation->setTags([$featureTag]);
            $this->error($operation, 403, 'Нет прав на изменение данных организации.');
            $this->error($operation, 503, 'База организации ещё не подготовлена.');

            return;
        }
        $operation->setTags([str_starts_with($uri, 'api/tenants') || $uri === 'api/tenant/database' ? 'Тенанты'
            : (str_starts_with($uri, 'auth/user/') || $uri === 'auth/sessions' || str_starts_with($uri, 'auth/sessions/') || $uri === 'auth/me' ? 'Личный кабинет'
                : ($uri === 'api/health' ? 'Состояние' : 'Аутентификация'))]);
    }

    private function error(Operation $operation, int $code, string $description): void
    {
        if (collect($operation->responses)->contains(fn ($response) => ($response->code ?? null) == $code)) {
            return;
        }
        $type = (new ObjectType)->addProperty('message', new StringType)->setRequired(['message']);
        $operation->addResponse((new Response($code))->setDescription($description)->setContent('application/json', Schema::fromType($type)));
    }
}
