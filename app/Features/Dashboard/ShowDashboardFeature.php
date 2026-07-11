<?php

namespace App\Features\Dashboard;

use App\Features\ManufacturingOrders\SearchManufacturingOrdersFeature;
use App\Models\MetalDetectorCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ShowDashboardFeature
{
    public function __construct(
        private readonly SearchManufacturingOrdersFeature $searchOrders,
    ) {
    }

    public function __invoke(Request $request)
    {
        $loadError = null;

        try {
            $orders = ($this->searchOrders)(null, 250);
        } catch (\Throwable $e) {
            report($e);
            $orders = [];
            $loadError = 'Unable to load live WinMan dashboard data right now.';
        }

        $rows = collect($orders)->map(static function ($order): array {
            return [
                'mo_ref' => $order->winmanManufacturingOrderId,
                'product_id' => $order->winmanProductId,
                'product_description' => $order->productDescription,
                'outstanding' => $order->quantityOutstanding,
                'classification' => $order->classification,
                'unit_of_measure' => $order->unitOfMeasure,
                'due_date' => $order->dueDate,
            ];
        });

        $sections = collect([
            30 => 'Intermediate',
            29 => 'Wet Packed',
        ])->map(function (string $label, int $classification) use ($rows): array {
            $classRows = $rows
                ->where('classification', $classification)
                ->sortBy('due_date')
                ->values();

            $uomGroups = $classRows
                ->groupBy(static fn (array $row): string => $row['unit_of_measure'] !== null ? (string) $row['unit_of_measure'] : 'unknown')
                ->map(function (Collection $groupRows, string $uom) use ($classification): array {
                    $uomValue = is_numeric($uom) ? (int) $uom : null;

                    return [
                        'uom' => $uomValue,
                        'uom_label' => $this->uomLabel($classification, $uomValue),
                        'order_count' => $groupRows->count(),
                        'outstanding_total' => (float) $groupRows->sum('outstanding'),
                        'rows' => $groupRows->values()->all(),
                    ];
                })
                ->sortBy(fn (array $group): int => $group['uom'] ?? PHP_INT_MAX)
                ->values()
                ->all();

            return [
                'classification' => $classification,
                'label' => $label,
                'order_count' => $classRows->count(),
                'outstanding_total' => (float) $classRows->sum('outstanding'),
                'uom_groups' => $uomGroups,
            ];
        })->values()->all();

        $otherClassifications = $rows
            ->whereNotIn('classification', [29, 30])
            ->count();

        $todayChecks = MetalDetectorCheck::query()
            ->with(['signedBy', 'batchRecord'])
            ->whereDate('check_time', today())
            ->orderByDesc('check_time')
            ->get();

        $recentChecks = $todayChecks
            ->take(10)
            ->map(static function (MetalDetectorCheck $check): array {
                return [
                    'time' => $check->check_time?->format('d M H:i') ?? '—',
                    'type' => $check->check_type,
                    'result' => $check->overall_result,
                    'signed_by' => $check->signedBy?->name ?? '—',
                    'context' => $check->batchRecord?->batch_number ? 'Batch '.$check->batchRecord->batch_number : 'Daily register',
                ];
            })
            ->values()
            ->all();

        return view('dashboard', [
            'sections' => $sections,
            'otherClassifications' => $otherClassifications,
            'loadError' => $loadError,
            'metalDetector' => [
                'today_total' => $todayChecks->count(),
                'pass_count' => $todayChecks->where('overall_result', MetalDetectorCheck::RESULT_PASS)->count(),
                'fail_count' => $todayChecks->where('overall_result', MetalDetectorCheck::RESULT_FAIL)->count(),
                'last_check_time' => $todayChecks->first()?->check_time?->format('d M H:i') ?? 'No checks yet today',
                'recent_checks' => $recentChecks,
            ],
        ]);
    }

    private function uomLabel(int $classification, ?int $uom): string
    {
        if ($uom === null) {
            return 'Unknown';
        }

        if ($classification === 29) {
            return match ($uom) {
                2 => 'Pallecon',
                44 => 'Buckets',
                default => 'Other ('.number_format($uom).')',
            };
        }

        return number_format($uom);
    }
}
