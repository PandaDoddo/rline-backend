<?php

namespace App\Modules\Shared\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantWrites
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $action
     * @return TReturn
     */
    public function transaction(callable $action): mixed
    {
        try {
            return DB::connection('tenant')->transaction($action);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                if (str_contains($exception->getMessage(), 'damage_mechanisms_active_name_unique')) {
                    throw ValidationException::withMessages(['values.name' => 'В каталоге уже есть действующий механизм с таким наименованием.']);
                }
                if (str_contains($exception->getMessage(), 'steel_grades_active_name_unique')) {
                    throw ValidationException::withMessages(['values.name' => 'В марочнике уже есть действующая марка с таким наименованием.']);
                }
                if (str_contains($exception->getMessage(), 'substances_active_name_unique')) {
                    throw ValidationException::withMessages(['values.name' => 'В каталоге уже есть действующее вещество с таким наименованием.']);
                }
                if (str_contains($exception->getMessage(), 'equipment_installation_tag_unique')) {
                    throw ValidationException::withMessages(['passport.tag' => 'Такая техническая позиция уже используется на этой установке.']);
                }
                throw ValidationException::withMessages(['code' => 'Такой идентификатор уже используется в выбранном размещении.']);
            }
            throw $exception;
        }
    }

    public function version(Model $model, int $expected): void
    {
        abort_if($model->version !== $expected, 409, 'Запись изменена другим пользователем. Обновите данные; ваш черновик не сохранён.');
    }

    public function event(string $type, Model $model, string $action, int $actorId, ?array $before = null, ?array $after = null): void
    {
        DB::connection('tenant')->table('domain_events')->insert(['entity_type' => $type, 'entity_id' => $model->id,
            'action' => $action, 'actor_id' => $actorId, 'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $action === 'deleted' ? 'null' : json_encode($after ?? $model->toArray(), JSON_THROW_ON_ERROR)]);
    }
}
