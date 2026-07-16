<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pallecon_submission_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_record_id')->constrained('batch_records')->cascadeOnDelete();
            $table->foreignId('pallecon_record_id')->constrained('pallecon_records');
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->dateTime('submitted_at');
            $table->string('booking_status')->nullable();
            $table->string('print_status')->nullable();
            $table->json('winman_preview')->nullable();
            $table->json('label_preview')->nullable();
            $table->timestamps();

            $table->index(['batch_record_id', 'submitted_at']);
            $table->index(['pallecon_record_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pallecon_submission_audits');
    }
};
