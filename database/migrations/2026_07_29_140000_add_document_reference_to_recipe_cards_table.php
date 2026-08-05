<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_cards', function (Blueprint $table): void {
            $table->string('document_reference')->nullable()->after('recipe_code');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_cards', function (Blueprint $table): void {
            $table->dropColumn('document_reference');
        });
    }
};
