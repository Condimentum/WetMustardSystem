<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A WinMan booking is now one transaction per sealed pallecon, not one per
 * contributing batch fill - so the log attaches to the pallecon directly
 * instead of to a single fill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winman_booking_logs', function (Blueprint $table) {
            $table->dropIndex(['pallecon_fill_id', 'booking_status']);
        });

        Schema::table('winman_booking_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pallecon_fill_id');
        });

        Schema::table('winman_booking_logs', function (Blueprint $table) {
            $table->foreignId('pallecon_id')->nullable()->after('batch_record_id')
                ->constrained('pallecons')->nullOnDelete();

            $table->index(['pallecon_id', 'booking_status']);
        });
    }

    public function down(): void
    {
        Schema::table('winman_booking_logs', function (Blueprint $table) {
            $table->dropIndex(['pallecon_id', 'booking_status']);
        });

        Schema::table('winman_booking_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pallecon_id');
        });

        Schema::table('winman_booking_logs', function (Blueprint $table) {
            $table->foreignId('pallecon_fill_id')->nullable()->after('batch_record_id')
                ->constrained('pallecon_fills')->nullOnDelete();

            $table->index(['pallecon_fill_id', 'booking_status']);
        });
    }
};
