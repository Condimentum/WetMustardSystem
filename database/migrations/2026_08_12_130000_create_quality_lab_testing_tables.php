<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wm003_ibc_traceability_entries', function (Blueprint $table): void {
            $table->id();
            $table->date('date_used')->index();
            $table->date('supplier_production_date')->nullable();
            $table->date('best_before_date')->nullable();
            $table->string('batch_no', 120)->nullable();
            $table->time('time_on')->nullable();
            $table->string('operator_name');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('wm005_lab_testing_entries', function (Blueprint $table): void {
            $table->id();
            $table->date('tested_date')->index();
            $table->string('part_number', 120)->nullable();
            $table->string('product', 255)->nullable();
            $table->string('mo_number', 120)->nullable();
            $table->time('tested_time')->nullable();
            $table->string('batch_number', 120)->nullable();
            $table->string('analytical_specification', 255)->nullable();
            $table->decimal('ph', 10, 3)->nullable();
            $table->decimal('acidity_acetic', 10, 3)->nullable();
            $table->decimal('acidity_citric', 10, 3)->nullable();
            $table->decimal('salt', 10, 3)->nullable();
            $table->decimal('viscosity_brookfield', 10, 3)->nullable();
            $table->decimal('viscosity_bostwick', 10, 3)->nullable();
            $table->decimal('aw', 10, 3)->nullable();
            $table->decimal('solids', 10, 3)->nullable();
            $table->string('appearance', 255)->nullable();
            $table->string('tested_by', 255);
            $table->string('qa_sign_off', 255)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('wm010_rinse_water_test_entries', function (Blueprint $table): void {
            $table->id();
            $table->date('tested_date')->index();
            $table->string('section', 40);
            $table->string('equipment', 255)->nullable();
            $table->decimal('reading', 10, 3)->nullable();
            $table->string('reading_unit', 30)->nullable();
            $table->string('pass_or_fail', 10)->nullable();
            $table->string('action_taken_if_failed', 500)->nullable();
            $table->string('operator_name', 255)->nullable();
            $table->string('chemical_used', 255)->nullable();
            $table->string('target', 255)->nullable();
            $table->string('result', 255)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tested_date', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wm010_rinse_water_test_entries');
        Schema::dropIfExists('wm005_lab_testing_entries');
        Schema::dropIfExists('wm003_ibc_traceability_entries');
    }
};
