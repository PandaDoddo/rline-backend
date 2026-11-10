<?php

use App\Models\Tenant;
use App\Modules\DataExchange\Application\DatasetEquipmentIdentifiers;
use App\Modules\DataExchange\Application\DatasetImport;
use App\Modules\DataExchange\Application\DatasetSchedulePlans;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

Artisan::command('dataset:identifiers {path} {--tenant=rline} {--actor=} {--apply}', function () {
    $path = realpath($this->argument('path'));
    if (! $path || ! is_file($path) || filesize($path) > 4 * 1024 * 1024 || ! ctype_digit((string) $this->option('actor'))) {
        return 1;
    }
    $context = app(TenantContext::class);
    $previous = $context->tenant;
    try {
        $context->set(Tenant::where('slug', $this->option('tenant'))->where('status', 'active')->firstOrFail());
        $input = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $report = app(DatasetEquipmentIdentifiers::class)->run($input, (int) $this->option('actor'), ! $this->option('apply'));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return 0;
    } catch (ValidationException $exception) {
        $this->error(json_encode($exception->errors(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return 1;
    } finally {
        $context->set($previous);
    }
})->purpose('Дополнить отсутствующие техпозиции импортированного old, по умолчанию с откатом');

Artisan::command('dataset:schedule-plans {path} {--tenant=rline} {--actor=} {--apply}', function () {
    $path = realpath($this->argument('path'));
    if (! $path || ! is_file($path) || filesize($path) > 4 * 1024 * 1024 || ! ctype_digit((string) $this->option('actor'))) {
        $this->error('Укажите JSON-дельту до 4 МБ и --actor администратора организации.');

        return 1;
    }
    $context = app(TenantContext::class);
    $previous = $context->tenant;
    try {
        $context->set(Tenant::where('slug', $this->option('tenant'))->where('status', 'active')->firstOrFail());
        $input = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $report = app(DatasetSchedulePlans::class)->run($input, (int) $this->option('actor'), ! $this->option('apply'));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return 0;
    } catch (ValidationException $exception) {
        $this->error(json_encode($exception->errors(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return 1;
    } finally {
        $context->set($previous);
    }
})->purpose('Дополнить только отсутствующие демо-планы old; по умолчанию предпросмотр с откатом');

Artisan::command('dataset:import {path} {--tenant=rline} {--actor=} {--apply} {--allow-source-issues}', function () {
    if (! ctype_digit((string) $this->option('actor')) || (int) $this->option('actor') < 1) {
        $this->error('Укажите --actor с ID действующего владельца или администратора выбранной организации.');

        return 1;
    }
    $path = realpath($this->argument('path'));
    if (! $path || ! is_file($path) || filesize($path) > 64 * 1024 * 1024) {
        $this->error('Нужен JSON-манифест размером до 64 МБ.');

        return 1;
    }
    $tenant = Tenant::where('slug', $this->option('tenant'))->where('status', 'active')->firstOrFail();
    $context = app(TenantContext::class);
    $previous = $context->tenant;
    try {
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new RuntimeException('Корень манифеста должен быть объектом.');
        }
        $context->set($tenant);
        $progress = function ($done, $total, $source) {
            if ($done % 10 === 0 || $done === $total) {
                $this->line('В транзакции: '.$done.'/'.$total.' · '.$source);
            }
        };
        $service = app(DatasetImport::class);
        $report = $this->option('apply') ? $service->apply($manifest, (int) $this->option('actor'), (bool) $this->option('allow-source-issues'), $progress) : $service->preview($manifest, (int) $this->option('actor'), (bool) $this->option('allow-source-issues'), $progress);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info($report['preview'] ? 'Предпросмотр завершён; все предметные изменения отменены.' : ($report['replayed'] ? 'Набор уже применялся; последующие пользовательские изменения сохранены.' : 'Импорт применён одной транзакцией.'));

        return 0;
    } catch (ValidationException $exception) {
        $this->error('Импорт не применён: ошибки данных.');
        $this->line(json_encode($exception->errors(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return 1;
    } finally {
        $context->set($previous);
    }
})->purpose('Проверить или применить начальный набор через общие прикладные команды');
