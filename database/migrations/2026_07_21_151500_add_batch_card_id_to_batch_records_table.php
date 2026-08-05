<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_records', function (Blueprint $table) {
            $table->foreignId('batch_card_id')
                ->nullable()
                ->after('variant_id')
                ->constrained('batch_cards');
        });
    }

    public function down(): void
    {
        Schema::table('batch_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_card_id');
        });
    }
};
