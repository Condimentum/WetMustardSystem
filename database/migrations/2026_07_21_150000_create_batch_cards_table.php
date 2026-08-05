<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_cards', function (Blueprint $table) {
            $table->id();
            $table->string('document_code');
            $table->string('title');
            $table->string('product_code')->nullable();
            $table->string('revision');
            $table->date('issue_date')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status')->default('draft');
            $table->boolean('is_current')->default(false);
            $table->string('pdf_path')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['document_code', 'revision']);
            $table->index(['document_code', 'status']);
            $table->index(['product_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_cards');
    }
};
