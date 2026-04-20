<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reporting-only aggregation: merged establishment slab groups sum yearly sabeel per primary.
 */
class EstablishmentSlabAggregationService
{
    /**
     * Slab breakdown rows: each row has sabeel (yearly combined or per-est) and cnt (number of units in bucket).
     *
     * @return Collection<int, object{sabeel: int|string, cnt: int}>
     */
    public function establishmentBreakdownRows(string $year, bool $mergeGroups): Collection
    {
        if (!$mergeGroups) {
            return DB::table('t_establishment_sabeel as es')
                ->join('t_establishment as e', function ($join) {
                    $join->on('e.establishment_id', '=', 'es.establishment_id')
                        ->where('e.status', 'active');
                })
                ->where('es.year', $year)
                ->select('es.sabeel', DB::raw('COUNT(DISTINCT es.establishment_id) as cnt'))
                ->groupBy('es.sabeel')
                ->orderByDesc('es.sabeel')
                ->get();
        }

        $memberMap = DB::table('t_establishment_slab_group as g')
            ->join('t_establishment_slab_group_member as m', 'm.group_id', '=', 'g.id')
            ->where('g.is_active', true)
            ->select('m.establishment_id as eid', 'g.primary_establishment_id as pid');

        $perPrimary = DB::query()
            ->fromSub(
                DB::table('t_establishment_sabeel as es')
                    ->join('t_establishment as e', function ($join) {
                        $join->on('e.establishment_id', '=', 'es.establishment_id')
                            ->where('e.status', 'active');
                    })
                    ->leftJoinSub($memberMap, 'mp', 'mp.eid', '=', 'es.establishment_id')
                    ->where('es.year', $year)
                    ->selectRaw('COALESCE(mp.pid, es.establishment_id) as primary_id')
                    ->addSelect('es.sabeel'),
                'x'
            )
            ->select('primary_id', DB::raw('SUM(x.sabeel) as combined'))
            ->groupBy('primary_id');

        return DB::query()
            ->fromSub($perPrimary, 'y')
            ->selectRaw('y.combined as sabeel, COUNT(*) as cnt')
            ->groupBy('y.combined')
            ->orderByDesc('y.combined')
            ->get();
    }

    /**
     * Detail list for establishment slab popup.
     *
     * @return array<int, array<string, mixed>>
     */
    public function establishmentSlabDetailItems(
        string $year,
        int $slab,
        ?int $yearlyExact,
        bool $mergeGroups
    ): array {
        if (!$mergeGroups) {
            $rows = DB::table('t_establishment_sabeel as es')
                ->join('t_establishment as e', function ($join) {
                    $join->on('e.establishment_id', '=', 'es.establishment_id')
                        ->where('e.status', 'active');
                })
                ->where('es.year', $year)
                ->where('es.sabeel', $slab)
                ->orderBy('e.name')
                ->select('e.establishment_id', 'e.name', 'es.sabeel as yearly_sabeel')
                ->get();

            return $rows->map(fn ($r) => [
                'establishment_id' => $r->establishment_id,
                'name'             => (string) $r->name,
                'yearly_sabeel'    => (int) $r->yearly_sabeel,
            ])->values()->all();
        }

        $targetCombined = $yearlyExact !== null ? $yearlyExact : $slab;

        $memberMap = DB::table('t_establishment_slab_group as g')
            ->join('t_establishment_slab_group_member as m', 'm.group_id', '=', 'g.id')
            ->where('g.is_active', true)
            ->select('m.establishment_id as eid', 'g.primary_establishment_id as pid');

        $perPrimary = DB::query()
            ->fromSub(
                DB::table('t_establishment_sabeel as es')
                    ->join('t_establishment as e', function ($join) {
                        $join->on('e.establishment_id', '=', 'es.establishment_id')
                            ->where('e.status', 'active');
                    })
                    ->leftJoinSub($memberMap, 'mp', 'mp.eid', '=', 'es.establishment_id')
                    ->where('es.year', $year)
                    ->selectRaw('COALESCE(mp.pid, es.establishment_id) as primary_id')
                    ->addSelect('es.sabeel'),
                'x'
            )
            ->select('primary_id', DB::raw('SUM(x.sabeel) as combined'))
            ->groupBy('primary_id')
            ->having('combined', '=', $targetCombined)
            ->get();

        $items = [];
        foreach ($perPrimary as $row) {
            $primaryId = (string) $row->primary_id;
            $combined = (int) $row->combined;

            $groupId = DB::table('t_establishment_slab_group')
                ->where('primary_establishment_id', $primaryId)
                ->where('is_active', true)
                ->value('id');

            if ($groupId) {
                $memberIds = DB::table('t_establishment_slab_group_member')
                    ->where('group_id', $groupId)
                    ->pluck('establishment_id');
                $membersOut = [];
                foreach ($memberIds as $eid) {
                    $name = (string) (DB::table('t_establishment')->where('establishment_id', $eid)->value('name') ?? '');
                    $y = (int) (DB::table('t_establishment_sabeel')
                        ->where('establishment_id', $eid)
                        ->where('year', $year)
                        ->value('sabeel') ?? 0);
                    $membersOut[] = [
                        'establishment_id' => $eid,
                        'name'             => $name,
                        'yearly_sabeel'    => $y,
                    ];
                }
                usort($membersOut, fn ($a, $b) => strcmp($a['name'], $b['name']));
                $primaryName = (string) (DB::table('t_establishment')->where('establishment_id', $primaryId)->value('name') ?? '');
                $items[] = [
                    'primary_establishment_id' => $primaryId,
                    'name'                     => $primaryName,
                    'combined_yearly_sabeel'  => $combined,
                    'members'                  => $membersOut,
                ];
            } else {
                $solo = DB::table('t_establishment_sabeel as es')
                    ->join('t_establishment as e', 'e.establishment_id', '=', 'es.establishment_id')
                    ->where('es.year', $year)
                    ->where('es.establishment_id', $primaryId)
                    ->where('e.status', 'active')
                    ->select('e.establishment_id', 'e.name', 'es.sabeel as yearly_sabeel')
                    ->first();
                if ($solo) {
                    $items[] = [
                        'primary_establishment_id' => $primaryId,
                        'name'                     => (string) $solo->name,
                        'combined_yearly_sabeel'   => $combined,
                        'members'                  => [[
                            'establishment_id' => $solo->establishment_id,
                            'name'             => (string) $solo->name,
                            'yearly_sabeel'    => (int) $solo->yearly_sabeel,
                        ]],
                    ];
                }
            }
        }

        return $items;
    }
}
