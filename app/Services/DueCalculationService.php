<?php

namespace App\Services;

use App\Models\AdvancePaidModel;
use App\Models\EstablishmentSabeelModel;
use App\Models\MumineenSabeelModel;
use App\Models\ReceiptModel;
use App\Models\YearModel;
use Illuminate\Support\Facades\DB;

class DueCalculationService
{
    /**
     * Get current year from t_year or fallback.
     */
    public function getCurrentYear(): string
    {
        $cur = YearModel::where('is_current', 1)->value('year');
        if ($cur) {
            return (string) $cur;
        }
        $cur = YearModel::max('year');
        if ($cur) {
            return (string) $cur;
        }
        return (string) (int) date('Y');
    }

    /**
     * Family due for display (single family). Includes advance_paid and effective dues.
     *
     * @return array{sabeel: int, paid: float, due: float, prev_due: float, advance_paid: float, due_effective: float, prev_due_effective: float}
     */
    public function getFamilyDue(int $familyId, string $currentYear): array
    {
        $sabeel = (int) MumineenSabeelModel::where('family_id', $familyId)
            ->where('year', $currentYear)
            ->value('sabeel');

        $paid = (float) ReceiptModel::where('family_id', $familyId)
            ->where('year', $currentYear)
            ->where('status', 'active')
            ->sum('amount');

        $due = max(0.0, $sabeel - $paid);
        $prevDue = $this->computeFamilyPrevDueRaw($familyId, $currentYear);

        $advancePaid = (float) AdvancePaidModel::where('family_id', $familyId)
            ->where('type', 'family')
            ->where('status', 'pending')
            ->sum('amount');

        $dueEffective = max(0.0, $due - $advancePaid);
        $advRem = max(0.0, $advancePaid - $due);
        $prevDueEffective = max(0.0, $prevDue - $advRem);

        return [
            'sabeel' => $sabeel,
            'paid' => $paid,
            'due' => $due,
            'prev_due' => $prevDue,
            'advance_paid' => $advancePaid,
            'due_effective' => $dueEffective,
            'prev_due_effective' => $prevDueEffective,
        ];
    }

    /**
     * Total family due (all years) for validation. sabeel - receipts - advance_paid.
     */
    public function getFamilyTotalDue(int $familyId): float
    {
        $totalSabeel = (float) MumineenSabeelModel::where('family_id', $familyId)->sum('sabeel');
        $totalReceipts = (float) ReceiptModel::where('family_id', $familyId)
            ->where('status', 'active')
            ->sum('amount');
        $totalAdvancePaid = (float) AdvancePaidModel::where('family_id', $familyId)
            ->where('type', 'family')
            ->where('status', 'pending')
            ->sum('amount');
        return max(0.0, $totalSabeel - $totalReceipts - $totalAdvancePaid);
    }

    /**
     * Bulk family dues. Keyed by family_id.
     *
     * @param array<int> $familyIds
     * @return array<int, array{sabeel: int, paid: float, due: float, prev_due: float, advance_paid: float, due_effective: float, prev_due_effective: float}>
     */
    public function getFamilyDueBulk(array $familyIds, string $currentYear): array
    {
        if (empty($familyIds)) {
            return [];
        }

        $familyIds = array_values(array_unique(array_map('intval', $familyIds)));

        $sabeelRows = MumineenSabeelModel::whereIn('family_id', $familyIds)
            ->where('year', $currentYear)
            ->get()
            ->keyBy('family_id');

        $paidRows = ReceiptModel::whereIn('family_id', $familyIds)
            ->where('year', $currentYear)
            ->where('status', 'active')
            ->select('family_id', DB::raw('SUM(amount) as paid'))
            ->groupBy('family_id')
            ->get()
            ->keyBy('family_id');

        $advanceRows = AdvancePaidModel::whereIn('family_id', $familyIds)
            ->where('type', 'family')
            ->where('status', 'pending')
            ->select('family_id', DB::raw('SUM(amount) as total'))
            ->groupBy('family_id')
            ->get()
            ->keyBy('family_id');

        $prevDueByFamily = $this->computeFamilyPrevDueRawBulk($familyIds, $currentYear);

        $out = [];
        foreach ($familyIds as $fid) {
            $sabeel = (int) ($sabeelRows->get($fid)->sabeel ?? 0);
            $paid = (float) ($paidRows->get($fid)->paid ?? 0);
            $due = max(0.0, $sabeel - $paid);
            $prevDue = $prevDueByFamily[$fid] ?? 0.0;
            $advancePaid = (float) ($advanceRows->get($fid)->total ?? 0);
            $dueEffective = max(0.0, $due - $advancePaid);
            $advRem = max(0.0, $advancePaid - $due);
            $prevDueEffective = max(0.0, $prevDue - $advRem);
            $out[$fid] = [
                'sabeel' => $sabeel,
                'paid' => $paid,
                'due' => $due,
                'prev_due' => $prevDue,
                'advance_paid' => $advancePaid,
                'due_effective' => $dueEffective,
                'prev_due_effective' => $prevDueEffective,
            ];
        }
        return $out;
    }

    /**
     * Family year-wise due (raw, no advance_paid per year). For sabeel_details.
     *
     * @param array<int|string> $yearsList
     * @return array<int, array{year: string, sabeel: int, paid: float, due: float}>
     */
    public function getFamilyDueByYear(int $familyId, array $yearsList): array
    {
        $out = [];
        foreach ($yearsList as $yr) {
            $year = (string) $yr;
            $sabeel = (int) MumineenSabeelModel::where('family_id', $familyId)
                ->where('year', $year)
                ->value('sabeel');
            $paid = (float) ReceiptModel::where('family_id', $familyId)
                ->where('year', $year)
                ->where('status', 'active')
                ->sum('amount');
            $due = max(0.0, $sabeel - $paid);
            $out[] = [
                'year' => $year,
                'sabeel' => $sabeel,
                'paid' => $paid,
                'due' => $due,
            ];
        }
        return $out;
    }

    /**
     * Establishment due for display (single). establishment_id can be int or string (10-digit).
     *
     * @param int|string $establishmentId
     * @return array{sabeel: int, paid: float, due: float, prev_due: float, advance_paid: float, due_effective: float, prev_due_effective: float}
     */
    public function getEstablishmentDue($establishmentId, string $currentYear): array
    {
        $estId = $establishmentId;

        $sabeel = (int) EstablishmentSabeelModel::where('establishment_id', $estId)
            ->where('year', $currentYear)
            ->value('sabeel');

        $paid = (float) ReceiptModel::where('establishment_id', $estId)
            ->where('year', $currentYear)
            ->where('status', 'active')
            ->sum('amount');

        $due = max(0.0, $sabeel - $paid);
        $prevDue = $this->computeEstablishmentPrevDueRaw($estId, $currentYear);

        $advancePaid = (float) AdvancePaidModel::where('establishment_id', $estId)
            ->where('type', 'establishment')
            ->where('status', 'pending')
            ->sum('amount');

        $dueEffective = max(0.0, $due - $advancePaid);
        $advRem = max(0.0, $advancePaid - $due);
        $prevDueEffective = max(0.0, $prevDue - $advRem);

        return [
            'sabeel' => $sabeel,
            'paid' => $paid,
            'due' => $due,
            'prev_due' => $prevDue,
            'advance_paid' => $advancePaid,
            'due_effective' => $dueEffective,
            'prev_due_effective' => $prevDueEffective,
        ];
    }

    /**
     * Total establishment due for validation.
     *
     * @param int|string $establishmentId
     */
    public function getEstablishmentTotalDue($establishmentId): float
    {
        $totalSabeel = (float) EstablishmentSabeelModel::where('establishment_id', $establishmentId)->sum('sabeel');
        $totalReceipts = (float) ReceiptModel::where('establishment_id', $establishmentId)
            ->where('status', 'active')
            ->sum('amount');
        $totalAdvancePaid = (float) AdvancePaidModel::where('establishment_id', $establishmentId)
            ->where('type', 'establishment')
            ->where('status', 'pending')
            ->sum('amount');
        return max(0.0, $totalSabeel - $totalReceipts - $totalAdvancePaid);
    }

    /**
     * Bulk establishment dues. Keyed by establishment_id.
     *
     * @param array<int|string> $establishmentIds
     * @return array<string|int, array{sabeel: int, paid: float, due: float, prev_due: float, advance_paid: float, due_effective: float, prev_due_effective: float}>
     */
    public function getEstablishmentDueBulk(array $establishmentIds, string $currentYear): array
    {
        if (empty($establishmentIds)) {
            return [];
        }

        $estIds = array_values(array_unique($establishmentIds));

        $sabeelRows = EstablishmentSabeelModel::whereIn('establishment_id', $estIds)
            ->where('year', $currentYear)
            ->get()
            ->keyBy('establishment_id');

        $paidRows = ReceiptModel::whereIn('establishment_id', $estIds)
            ->where('year', $currentYear)
            ->where('status', 'active')
            ->select('establishment_id', DB::raw('SUM(amount) as paid'))
            ->groupBy('establishment_id')
            ->get()
            ->keyBy('establishment_id');

        $advanceRows = AdvancePaidModel::whereIn('establishment_id', $estIds)
            ->where('type', 'establishment')
            ->where('status', 'pending')
            ->select('establishment_id', DB::raw('SUM(amount) as total'))
            ->groupBy('establishment_id')
            ->get()
            ->keyBy('establishment_id');

        $prevDueByEst = $this->computeEstablishmentPrevDueRawBulk($estIds, $currentYear);

        $out = [];
        foreach ($estIds as $eid) {
            $sabeel = (int) ($sabeelRows->get($eid)->sabeel ?? 0);
            $paid = (float) ($paidRows->get($eid)->paid ?? 0);
            $due = max(0.0, $sabeel - $paid);
            $prevDue = $prevDueByEst[$eid] ?? 0.0;
            $advancePaid = (float) ($advanceRows->get($eid)->total ?? 0);
            $dueEffective = max(0.0, $due - $advancePaid);
            $advRem = max(0.0, $advancePaid - $due);
            $prevDueEffective = max(0.0, $prevDue - $advRem);
            $out[$eid] = [
                'sabeel' => $sabeel,
                'paid' => $paid,
                'due' => $due,
                'prev_due' => $prevDue,
                'advance_paid' => $advancePaid,
                'due_effective' => $dueEffective,
                'prev_due_effective' => $prevDueEffective,
            ];
        }
        return $out;
    }

    /**
     * Establishment year-wise due (raw). For sabeel_details.
     *
     * @param int|string $establishmentId
     * @param array<int|string> $yearsList
     * @return array<int, array{year: string, sabeel: int, paid: float, due: float}>
     */
    public function getEstablishmentDueByYear($establishmentId, array $yearsList): array
    {
        $out = [];
        foreach ($yearsList as $yr) {
            $year = (string) $yr;
            $sabeel = (int) EstablishmentSabeelModel::where('establishment_id', $establishmentId)
                ->where('year', $year)
                ->value('sabeel');
            $paid = (float) ReceiptModel::where('establishment_id', $establishmentId)
                ->where('year', $year)
                ->where('status', 'active')
                ->sum('amount');
            $due = max(0.0, $sabeel - $paid);
            $out[] = [
                'year' => $year,
                'sabeel' => $sabeel,
                'paid' => $paid,
                'due' => $due,
            ];
        }
        return $out;
    }

    /**
     * Sum of (sabeel - paid) for years < currentYear for one family.
     */
    private function computeFamilyPrevDueRaw(int $familyId, string $currentYear): float
    {
        $entries = MumineenSabeelModel::where('family_id', $familyId)
            ->where('year', '<', $currentYear)
            ->get();
        $total = 0.0;
        foreach ($entries as $entry) {
            $paid = (float) ReceiptModel::where('family_id', $familyId)
                ->where('year', $entry->year)
                ->where('status', 'active')
                ->sum('amount');
            $total += max(0.0, (float) $entry->sabeel - $paid);
        }
        return $total;
    }

    /**
     * @param array<int> $familyIds
     * @return array<int, float>
     */
    private function computeFamilyPrevDueRawBulk(array $familyIds, string $currentYear): array
    {
        $entries = MumineenSabeelModel::whereIn('family_id', $familyIds)
            ->where('year', '<', $currentYear)
            ->get();
        $paidMap = ReceiptModel::whereIn('family_id', $familyIds)
            ->where('status', 'active')
            ->select('family_id', 'year', DB::raw('SUM(amount) as paid'))
            ->groupBy('family_id', 'year')
            ->get()
            ->groupBy('family_id');
        $out = array_fill_keys($familyIds, 0.0);
        foreach ($entries as $entry) {
            $fid = $entry->family_id;
            $paid = (float) ($paidMap->get($fid)?->firstWhere('year', $entry->year)?->paid ?? 0);
            $out[$fid] = ($out[$fid] ?? 0) + max(0.0, (float) $entry->sabeel - $paid);
        }
        return $out;
    }

    /**
     * @param int|string $establishmentId
     */
    private function computeEstablishmentPrevDueRaw($establishmentId, string $currentYear): float
    {
        $entries = EstablishmentSabeelModel::where('establishment_id', $establishmentId)
            ->where('year', '<', $currentYear)
            ->get()
            ->groupBy('year');
        $total = 0.0;
        foreach ($entries as $year => $yearEntries) {
            $sabeelSum = (float) $yearEntries->sum('sabeel');
            $paid = (float) ReceiptModel::where('establishment_id', $establishmentId)
                ->where('year', $year)
                ->where('status', 'active')
                ->sum('amount');
            $total += max(0.0, $sabeelSum - $paid);
        }
        return $total;
    }

    /**
     * @param array<int|string> $establishmentIds
     * @return array<string|int, float>
     */
    private function computeEstablishmentPrevDueRawBulk(array $establishmentIds, string $currentYear): array
    {
        $entries = EstablishmentSabeelModel::whereIn('establishment_id', $establishmentIds)
            ->where('year', '<', $currentYear)
            ->get()
            ->groupBy('establishment_id');
        $out = [];
        foreach ($establishmentIds as $eid) {
            $out[$eid] = 0.0;
        }
        foreach ($entries as $eid => $estEntries) {
            $byYear = $estEntries->groupBy('year');
            foreach ($byYear as $year => $yearEntries) {
                $sabeelSum = (float) $yearEntries->sum('sabeel');
                $paid = (float) ReceiptModel::where('establishment_id', $eid)
                    ->where('year', $year)
                    ->where('status', 'active')
                    ->sum('amount');
                $out[$eid] = ($out[$eid] ?? 0) + max(0.0, $sabeelSum - $paid);
            }
        }
        return $out;
    }

    /**
     * Establishment totals for a family (sum over multiple establishments). Returns same shape as single establishment.
     * Used when a family has multiple linked establishments.
     *
     * @param array<int|string> $establishmentIds
     * @return array{sabeel: int, paid: float, due: float, prev_due: float, advance_paid: float, due_effective: float, prev_due_effective: float}
     */
    public function getEstablishmentTotalsForFamily(array $establishmentIds, string $currentYear): array
    {
        if (empty($establishmentIds)) {
            return [
                'sabeel' => 0,
                'paid' => 0.0,
                'due' => 0.0,
                'prev_due' => 0.0,
                'advance_paid' => 0.0,
                'due_effective' => 0.0,
                'prev_due_effective' => 0.0,
            ];
        }
        $bulk = $this->getEstablishmentDueBulk($establishmentIds, $currentYear);
        $sabeel = 0;
        $paid = 0.0;
        $due = 0.0;
        $prevDue = 0.0;
        $advancePaid = 0.0;
        foreach ($bulk as $row) {
            $sabeel += $row['sabeel'];
            $paid += $row['paid'];
            $due += $row['due'];
            $prevDue += $row['prev_due'];
            $advancePaid += $row['advance_paid'];
        }
        $dueEffective = max(0.0, $due - $advancePaid);
        $advRem = max(0.0, $advancePaid - $due);
        $prevDueEffective = max(0.0, $prevDue - $advRem);
        return [
            'sabeel' => $sabeel,
            'paid' => $paid,
            'due' => $due,
            'prev_due' => $prevDue,
            'advance_paid' => $advancePaid,
            'due_effective' => $dueEffective,
            'prev_due_effective' => $prevDueEffective,
        ];
    }
}
