<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local snapshot of outstanding WinMan manufacturing orders, refreshed
 * periodically by SyncOutstandingManufacturingOrdersJob (via
 * `php artisan winman:sync-manufacturing-orders`, scheduled externally like
 * dbmts:reports:run). Lets the MO Search screen keep working (with a
 * "last synced" banner) when WinMan itself is unreachable (resilience tier 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winman_mo_sync_cache', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('winman_manufacturing_order');
            $table->string('winman_manufacturing_order_id');
            $table->string('winman_product_internal')->nullable();
            $table->string('winman_product_id')->nullable();
            $table->string('product_description')->nullable();
            $table->char('system_type', 1)->nullable();
            $table->decimal('planned_quantity', 14, 3)->default(0);
            $table->decimal('quantity_outstanding', 14, 3)->default(0);
            $table->integer('classification')->nullable();
            $table->integer('unit_of_measure')->nullable();
            $table->string('unit_of_measure_description')->nullable();
            $table->string('due_date')->nullable();
            $table->string('last_modified_date')->nullable();
            $table->dateTime('synced_at');
            $table->timestamps();

            $table->unique('winman_manufacturing_order');
            $table->index('classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winman_mo_sync_cache');
    }
};
