<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_references', function (Blueprint $table) {
            // Recipe whose batch card this document is, when source_type is "recipe".
            $table->string('recipe_code', 50)->nullable()->after('program_key');
        });
    }

    public function down(): void
    {
        Schema::table('document_references', function (Blueprint $table) {
            $table->dropColumn('recipe_code');
        });
    }
};
