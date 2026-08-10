<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_reference_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_reference_id')->constrained('document_references')->cascadeOnDelete();
            $table->string('issue_version')->nullable();
            $table->date('date_issued')->nullable();
            $table->string('issued_by')->nullable();
            $table->string('reason_for_change', 1000)->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['document_reference_id', 'date_issued']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reference_changes');
    }
};
