<?php

namespace App\Domains\Batch\Jobs;

use App\Models\BatchRecord;
use Illuminate\Support\Carbon;

/**
 * Generates a unique app-only Batch Reference of the form
 * "{MO number}-{WinMan product id}-{NN}", where NN is a two-digit sequence of
 * the batches created against that manufacturing order (01, 02, ...).
 *
 * The reference is app-only - WinMan keys on the pallecon reference instead.
 * Falls back to the legacy "WM{yymmdd}-{nn}" daily form when no MO number is
 * supplied.
 */
class GenerateBatchNumberJob
{
    public function __invoke(
        ?string $moNumber = null,
        ?string $productId = null,
        ?int $sequence = null,
        ?Carbon $productionDate = null,
    ): string {
        $moNumber = $this->token($moNumber);

        if ($moNumber !== '') {
            $parts = [$moNumber];

            $productId = $this->token($productId);
            if ($productId !== '') {
                $parts[] = $productId;
            }

            $next = max(1, $sequence ?? 1);

            do {
                $candidate = implode('-', [
                    ...$parts,
                    str_pad((string) $next, 2, '0', STR_PAD_LEFT),
                ]);
                $next++;
            } while (BatchRecord::query()->where('batch_number', $candidate)->exists());

            return $candidate;
        }

        $date = ($productionDate ?? now())->format('ymd');
        $prefix = "WM{$date}";

        $sequence = BatchRecord::query()
            ->where('batch_number', 'like', "{$prefix}-%")
            ->count() + 1;

        do {
            $candidate = sprintf('%s-%02d', $prefix, $sequence);
            $sequence++;
        } while (BatchRecord::query()->where('batch_number', $candidate)->exists());

        return $candidate;
    }

    /**
     * Normalise a segment to an uppercase, trimmed token so it stays a single
     * field within the hyphen-delimited reference.
     */
    private function token(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }
}
