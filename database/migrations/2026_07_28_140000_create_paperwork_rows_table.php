<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paperwork_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manufacturing_order_id')->constrained();
            $table->string('manufacturing_order_ref')->nullable();
            $table->foreignId('batch_record_id')->constrained('batch_records')->cascadeOnDelete();
            $table->string('batch_number');
            $table->unsignedInteger('batch_column_index');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipe_code')->nullable()->index();
            $table->string('recipe_revision')->nullable();

            $table->string('row_key');
            $table->string('row_label');
            $table->unsignedInteger('row_order');

            $table->text('value_text')->nullable();
            $table->decimal('value_number', 18, 5)->nullable();
            $table->boolean('value_bool')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->string('unit', 30)->nullable();

            $table->string('status', 30)->default('pending');
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->dateTime('entered_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['manufacturing_order_id', 'batch_column_index'], 'paperwork_rows_mo_column_idx');
            $table->index(['manufacturing_order_id', 'row_order'], 'paperwork_rows_mo_row_order_idx');
            $table->index(['batch_record_id', 'row_order'], 'paperwork_rows_batch_row_order_idx');
            $table->index(['manufacturing_order_id', 'row_key'], 'paperwork_rows_mo_row_key_idx');
            $table->unique(['batch_record_id', 'row_key'], 'paperwork_rows_batch_row_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paperwork_rows');
    }
};
