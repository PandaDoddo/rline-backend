<?php

namespace App\Http\Controllers;

use App\Domain\CatalogHistory;
use App\Domain\CatalogListProjection;
use App\Domain\CatalogRecords;
use App\Http\Requests\DamageMechanismIndexRequest;
use App\Http\Requests\UpdateDamageMechanismRequest;
use App\Http\Resources\DamageMechanismEventResource;
use App\Http\Resources\DamageMechanismResource;
use App\Http\Resources\DamageMechanismSummaryResource;
use App\Models\DamageMechanism;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\DB;

class DamageMechanismController extends Controller
{
    public function schema()
    {
        return ['fields' => collect(config('damage-mechanisms.fields'))->map(fn ($field, $key) => ['key' => $key] + $field)->values(), 'groups' => (object) config('damage-mechanisms.groups'), 'sections' => (object) config('damage-mechanisms.sections'), 'baseline_notice' => '71 карточка из исходного проекта: рабочие пересказы, версия базы и независимые проверки тенанта. Серверный скрининг ЕО ещё не подключён.'];
    }

    public function index(DamageMechanismIndexRequest $request)
    {
        $data = $request->validated();
        $effective = '(base_values || overrides)';
        $query = DamageMechanism::where('status', $data['status'] ?? 'active');
        if (($q = trim($data['q'] ?? '')) !== '') {
            $pattern = '%'.addcslashes($q, '\\%_').'%';
            $query->where(function ($query) use ($effective, $pattern) {
                $query->whereRaw('label ILIKE ?', [$pattern]);
                foreach (['english_name', 'paragraph', 'designation'] as $key) {
                    $query->orWhereRaw($effective."->>'".$key."' ILIKE ?", [$pattern]);
                }
            });
        }
        $mode = (string) ($data['mode'] ?? '571');
        if ($mode === '581') {
            $query->whereRaw('NULLIF(TRIM('.$effective."->>'df_mapping'), '') IS NOT NULL");
        } elseif ($mode !== '571') {
            $query->whereRaw($effective."->>'kind' = ?", [match ($mode) {
                'corrosion' => 'коррозия', 'cracking' => 'растрескивание', 'fatigue' => 'усталость', 'embrittlement' => 'охрупчивание'
            }]);
        }
        if ($materials = $data['materials'] ?? []) {
            $query->where(function ($query) use ($materials, $effective) {
                foreach ($materials as $group) {
                    $query->orWhereRaw('('.$effective."->'material_groups') @> ?::jsonb", [json_encode([$group], JSON_THROW_ON_ERROR)]);
                }
            });
        }

        return DamageMechanismSummaryResource::collection(CatalogListProjection::damageMechanisms($query)->orderBy('label')->orderBy('id')->paginate($data['per_page'] ?? 50));
    }

    #[Response(status: 200, type: DamageMechanismResource::class)]
    public function show(string $mechanism)
    {
        return DB::connection('tenant')->transaction(function () use ($mechanism) {
            DB::connection('tenant')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return (new DamageMechanismResource(DamageMechanism::findOrFail($mechanism)))->response();
        });
    }

    #[Response(status: 200, type: DamageMechanismResource::class)]
    public function update(UpdateDamageMechanismRequest $request, string $mechanism, CatalogRecords $records)
    {
        return (new DamageMechanismResource($records->update(DamageMechanism::class, $mechanism, $request->validated(), $request->user()->id, 'damage_mechanism')))->response();
    }

    public function events(string $mechanism, CatalogHistory $history)
    {
        DamageMechanism::findOrFail($mechanism);

        return DamageMechanismEventResource::collection($history->page('damage_mechanism', $mechanism, config('damage-mechanisms.fields')));
    }
}
