<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_references', function (Blueprint $table) {
            // "program" (filled from an app program) or "material_trigger" (generated when a trigger material is issued).
            $table->string('source_type', 30)->nullable()->after('module');
            // DocumentSources program key, e.g. "wm002_salt_meter", when source_type is "program".
            $table->string('program_key', 80)->nullable()->after('source_type');
        });
    }

    public function down(): void
    {
        Schema::table('document_references', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'program_key']);
        });
    }
};
