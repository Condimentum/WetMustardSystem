<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_cards', function (Blueprint $table): void {
            $table->json('layout_config')->nullable()->after('steps');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_cards', function (Blueprint $table): void {
            $table->dropColumn('layout_config');
        });
    }
};
