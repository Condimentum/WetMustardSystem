<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DBMTS Waste / Scrap record (scope §3): material spilled, damaged, disposed of
 * or otherwise lost during production. Optionally linked to a batch for yield /
 * variance reporting. App-only - never sent to WinMan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_record_id')->nullable()->constrained('batch_records')->nullOnDelete();
            $table->string('material_code')->nullable();
            $table->string('material_description')->nullable();
            $table->string('lot_number')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('uom')->default('kg');
            $table->string('category'); // spillage | damaged_packaging | disposal | process_loss | qa_rejection
            $table->string('reason', 1000);
            // NO ACTION (constrained default): recorder is a cross-reference.
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->dateTime('recorded_at');
            $table->timestamps();

            $table->index('batch_record_id');
            $table->index(['category', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_records');
    }
};
