<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores WinMan Inventory.SupplierLotNumber alongside the allocated LotNumber.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_ingredient_lots', function (Blueprint $table) {
            $table->string('supplier_lot_number')->nullable()->after('lot_number');
        });
    }

    public function down(): void
    {
        Schema::table('batch_ingredient_lots', function (Blueprint $table) {
            $table->dropColumn('supplier_lot_number');
        });
    }
};
