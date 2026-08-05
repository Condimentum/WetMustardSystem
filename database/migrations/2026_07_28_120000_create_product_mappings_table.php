<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('structure_product')->nullable();
            $table->string('structure_product_id')->index();
            $table->string('structure_classification', 10)->nullable();
            $table->string('structure_product_description');
            $table->unsignedInteger('component_product')->nullable();
            $table->string('component_classification', 10)->nullable();
            $table->string('component_product_id')->index();
            $table->string('component_product_description');
            $table->decimal('quantity_per_unit', 18, 5);
            $table->dateTime('component_valid_from')->nullable();
            $table->dateTime('component_valid_to')->nullable();
            $table->unsignedInteger('structure_level')->default(1);
            $table->dateTime('synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique([
                'structure_product_id',
                'component_product_id',
                'structure_level',
            ], 'product_mappings_structure_component_level_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_mappings');
    }
};
