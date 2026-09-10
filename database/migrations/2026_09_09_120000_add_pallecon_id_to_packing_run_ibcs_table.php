<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Points packing IBC consumption at the first-class pallecon container.
 *
 * Legacy pallecon_record_id is retained for historical rows; new consumption
 * records the container and derives source batches from its fills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packing_run_ibcs', function (Blueprint $table) {
            // NO ACTION (constrained default): container is a cross-reference here.
            $table->foreignId('pallecon_id')->nullable()->after('pallecon_record_id')->constrained('pallecons');
        });
    }

    public function down(): void
    {
        Schema::table('packing_run_ibcs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pallecon_id');
        });
    }
};
