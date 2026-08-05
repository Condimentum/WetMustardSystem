<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_cards', function (Blueprint $table): void {
            $table->id();
            $table->string('recipe_code')->unique();
            $table->decimal('batch_size_kg', 12, 3)->nullable();
            $table->string('plc_recipe_number')->nullable();
            $table->string('revision_no')->nullable();
            $table->date('issue_date')->nullable();
            $table->text('reason_for_issue')->nullable();
            $table->json('steps')->nullable();
            $table->timestamps();

            $table->index('recipe_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_cards');
    }
};
