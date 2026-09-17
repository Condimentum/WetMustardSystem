<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attributes each WinMan booking attempt to the specific pallecon fill it was
 * for, since a batch can now feed multiple fills across multiple pallecons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winman_booking_logs', function (Blueprint $table) {
            $table->foreignId('pallecon_fill_id')->nullable()->after('batch_record_id')
                ->constrained('pallecon_fills')->nullOnDelete();

            $table->index(['pallecon_fill_id', 'booking_status']);
        });
    }

    public function down(): void
    {
        Schema::table('winman_booking_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pallecon_fill_id');
        });
    }
};
