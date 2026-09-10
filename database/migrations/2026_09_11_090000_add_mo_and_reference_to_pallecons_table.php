<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pallecons are now created from the MO Workspace with a target weight and a
 * production date, before any batch fill. They gain:
 *  - manufacturing_order_id: authoritative MO link (mo_number stays as a hint)
 *  - target_weight_kg: operator-entered planned weight at creation
 *  - production_date: like a batch's production date
 *  - winman_reference: the lot string stamped back on seal
 *    ("{MO WinMan id} {pallecon number} {yjjj}00M96")
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pallecons', function (Blueprint $table): void {
            $table->foreignId('manufacturing_order_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->decimal('target_weight_kg', 12, 3)->nullable()->after('capacity_kg');
            $table->date('production_date')->nullable()->after('target_weight_kg');
            $table->string('winman_reference')->nullable()->after('production_date');
        });
    }

    public function down(): void
    {
        Schema::table('pallecons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manufacturing_order_id');
            $table->dropColumn(['target_weight_kg', 'production_date', 'winman_reference']);
        });
    }
};
