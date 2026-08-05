<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_cards', function (Blueprint $table): void {
            $table->json('batch_sizes_kg')->nullable()->after('batch_size_kg');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_cards', function (Blueprint $table): void {
            $table->dropColumn('batch_sizes_kg');
        });
    }
};
