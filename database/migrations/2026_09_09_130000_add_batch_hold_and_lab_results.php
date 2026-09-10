<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch-level QA hold (scope §8 lab hold management).
 *
 * Hold is orthogonal to the production status so a completed/closed batch can be
 * quarantined without disrupting the existing status state machine. A batch is
 * on hold when held_at is not null. Pallecons already carry held_at/hold_reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_records', function (Blueprint $table) {
            $table->dateTime('held_at')->nullable()->after('completed_at');
            $table->string('hold_reason', 500)->nullable()->after('held_at');
        });

        Schema::create('batch_lab_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_record_id')->constrained('batch_records')->cascadeOnDelete();
            $table->string('result');                 // pass | fail | pending
            $table->string('analytical_spec')->nullable();
            $table->string('comment', 1000)->nullable();
            $table->dateTime('tested_at')->nullable();
            $table->string('tested_by')->nullable();  // free-text technician name from the paper sheet
            // NO ACTION (constrained default): recorder is a cross-reference.
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['batch_record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_lab_results');

        Schema::table('batch_records', function (Blueprint $table) {
            $table->dropColumn(['held_at', 'hold_reason']);
        });
    }
};
