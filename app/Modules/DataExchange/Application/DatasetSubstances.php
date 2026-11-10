<?php

namespace App\Modules\DataExchange\Application;

use App\Modules\Catalogs\Application\CatalogRecords;
use App\Modules\Catalogs\Application\SubstanceInputValidation;
use App\Modules\Catalogs\Models\Substance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Baseline edits share the public write contract and never overwrite existing tenant edits. */
final class DatasetSubstances
{
    public function __construct(private readonly SubstanceInputValidation $inputs, private readonly CatalogRecords $records) {}

    public function import(array $records, int $actor, string $batch): int
    {
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new \LogicException('Substance imports require the dataset transaction.');
        }
        $models = Substance::whereIn('legacy_code', array_column($records, 'code'))->orderBy('id')->lockForUpdate()->get()->keyBy('legacy_code');
        $receipts = [];
        foreach ($records as $index => $record) {
            $model = $models->get($record['code']);
            if (! $model) {
                throw ValidationException::withMessages(['substances.'.$index.'.code' => 'Базовое вещество не найдено; новые записи требуют отдельного сопоставления.']);
            }
            abort_unless($model->version === 1 && $model->status === 'active' && $model->overrides === [] && $model->sources === [] && $model->checks === [], 409, 'Вещество '.$record['code'].' уже изменено в организации; первичный импорт не перезаписывает правки.');
            try {
                $data = $this->inputs->validated(['version' => $model->version, 'values' => $record['values']], true);
                $model = $this->records->update(Substance::class, $model->id, $data, $actor, 'substance');
                $receipts[] = ['substance_id' => $model->id, 'batch_id' => $batch, 'source_code' => $record['code'], 'imported_version' => $model->version];
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => ['substances.'.$index.'.'.$key => $messages])->all());
            }
        }
        foreach (array_chunk($receipts, 500) as $chunk) {
            $db->table('dataset_import_substances')->insert($chunk);
        }

        return count($receipts);
    }
}
