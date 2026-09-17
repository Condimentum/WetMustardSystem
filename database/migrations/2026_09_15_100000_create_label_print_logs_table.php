<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DBMTS Label Print Log. Records every attempted pallecon label print (BarTender
 * request) so a completed batch's view-only screen can show what was printed,
 * with what data, and whether it succeeded - printing was previously
 * fire-and-forget with no persisted history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_print_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pallecon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_record_id')->nullable()->constrained('batch_records')->nullOnDelete();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label_type')->default('pallecon');
            $table->string('serial_number')->nullable();
            $table->decimal('fill_weight', 14, 3)->nullable();
            $table->date('production_date')->nullable();
            $table->string('status'); // success | failed
            $table->text('error_message')->nullable();
            $table->json('label_data')->nullable(); // named data sources sent to BarTender
            $table->dateTime('printed_at');
            $table->timestamps();

            $table->index(['pallecon_id', 'status']);
            $table->index(['batch_record_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_print_logs');
    }
};
