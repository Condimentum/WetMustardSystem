<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_references', function (Blueprint $table) {
            // WinMan Product IDs that, when issued to a batch, auto-generate this document for that day.
            $table->json('trigger_material_codes')->nullable()->after('module');
        });
    }

    public function down(): void
    {
        Schema::table('document_references', function (Blueprint $table) {
            $table->dropColumn('trigger_material_codes');
        });
    }
};
