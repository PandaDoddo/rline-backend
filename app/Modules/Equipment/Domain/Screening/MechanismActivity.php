<?php

namespace App\Modules\Equipment\Domain\Screening;

final class MechanismActivity
{
    public function categories(array $result, ?bool $insulated, bool $flange): array
    {
        $mechanisms = array_column($result['mechanisms'], null, 'id');
        $thin = [];
        foreach ($mechanisms as $mechanism) {
            if ($mechanism['group'] === '2.B' && ! $mechanism['component_only']) {
                $thin[] = $mechanism['id'];
            }
        }
        $read = function (array $ids, string $side) use ($mechanisms): ?bool {
            $states = [];
            foreach ($ids as $id) {
                $states[] = array_column($mechanisms[$id]['sides'] ?? [], null, 'side')[$side]['state'] ?? null;
            }
            if (in_array('active', $states, true)) {
                return true;
            }
            if (! $states || in_array(null, $states, true) || in_array('potential', $states, true)) {
                return null;
            }

            return false;
        };
        $chloride = $insulated === null ? ($read(['extcl', 'cuicl'], 'external') === false ? false : null) : $read([$insulated ? 'cuicl' : 'extcl'], 'external');

        return ['thin' => $flange ? false : $read($thin, 'internal'), 'ext' => $read(['extf'], 'external'), 'cui' => $read(['cuif'], 'external'), 'cl' => $chloride,
            'scc' => $flange ? false : $read(['ssc', 'hschf', 'acscc', 'aminescc', 'causticscc'], 'internal'),
            'hic' => $flange ? false : $read(['hich2s', 'hichf'], 'internal'), 'aus' => $flange ? false : $read(['pascc', 'clscc'],'internal')];
    }
}
