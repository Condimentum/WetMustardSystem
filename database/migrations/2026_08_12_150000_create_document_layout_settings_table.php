<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_layout_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_reference_id')->constrained('document_references')->cascadeOnDelete();
            $table->longText('settings')->nullable();
            $table->timestamps();

            $table->unique('document_reference_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_layout_settings');
    }
};
