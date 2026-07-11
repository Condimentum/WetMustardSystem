<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manufacturing_orders', function (Blueprint $table) {
            $table->integer('winman_classification')->nullable()->after('quantity_outstanding');
        });
    }

    public function down(): void
    {
        Schema::table('manufacturing_orders', function (Blueprint $table) {
            $table->dropColumn('winman_classification');
        });
    }
};
