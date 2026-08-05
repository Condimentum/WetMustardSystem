<?php

namespace App\Domains\Batch\Jobs;

use App\Domains\Batch\Exceptions\BatchException;
use App\Models\BatchIngredientLot;
use App\Models\User;

/**
 * Applies a weighed or tipped sign-off to an ingredient lot.
 *
 * Lot number and actual quantity are mandatory before a lot can be signed off
 * (scope §11 validation).
 */
class SignIngredientLotJob
{
    public const PURPOSE_WEIGHED = 'weighed';
    public const PURPOSE_TIPPED = 'tipped';

    public function __invoke(BatchIngredientLot $lot, string $purpose, User $user): BatchIngredientLot
    {
        if (! in_array($purpose, [self::PURPOSE_WEIGHED, self::PURPOSE_TIPPED], true)) {
            throw new BatchException("Unknown sign-off purpose: {$purpose}.");
        }

        if (blank($lot->lot_number) || $lot->actual_quantity === null) {
            throw new BatchException('Lot number and actual quantity are required before sign-off.');
        }

        if ($purpose === self::PURPOSE_TIPPED && $lot->weighed_at === null) {
            throw new BatchException('Ingredient must be weighed before tipped sign-off.');
        }

        if ($purpose === self::PURPOSE_WEIGHED) {
            $lot->forceFill(['weighed_by' => $user->id, 'weighed_at' => now()]);
        } else {
            $lot->forceFill(['tipped_by' => $user->id, 'tipped_at' => now()]);
        }

        $lot->save();

        return $lot;
    }
}
