<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manufacturing_orders', function (Blueprint $table): void {
            $table->integer('winman_unit_of_measure')->nullable()->after('winman_system_type');
            $table->string('winman_unit_of_measure_description')->nullable()->after('winman_unit_of_measure');
        });
    }

    public function down(): void
    {
        Schema::table('manufacturing_orders', function (Blueprint $table): void {
            $table->dropColumn(['winman_unit_of_measure', 'winman_unit_of_measure_description']);
        });
    }
};
