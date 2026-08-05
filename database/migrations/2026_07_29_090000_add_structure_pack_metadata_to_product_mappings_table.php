<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_mappings', function (Blueprint $table): void {
            $table->string('structure_unit_of_measure_description')->nullable()->after('structure_product_description');
            $table->integer('structure_boxes_per_pallet')->nullable()->after('structure_unit_of_measure_description');
            $table->decimal('structure_batch_size_kg', 18, 5)->nullable()->after('structure_boxes_per_pallet');
        });
    }

    public function down(): void
    {
        Schema::table('product_mappings', function (Blueprint $table): void {
            $table->dropColumn([
                'structure_unit_of_measure_description',
                'structure_boxes_per_pallet',
                'structure_batch_size_kg',
            ]);
        });
    }
};
