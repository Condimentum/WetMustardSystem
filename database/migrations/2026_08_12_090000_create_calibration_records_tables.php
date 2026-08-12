<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_scale_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->date('checked_date')->index();
            $table->decimal('reading', 10, 3);
            $table->boolean('passed')->default(true);
            $table->string('operator_name');
            $table->string('deviation_reason', 1000)->nullable();
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('salt_meter_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->date('checked_date')->index();
            $table->decimal('reading', 10, 3);
            $table->boolean('passed')->default(true);
            $table->string('operator_name');
            $table->string('deviation_reason', 1000)->nullable();
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('viscosity_meter_autozero_checks', function (Blueprint $table): void {
            $table->id();
            $table->date('checked_date')->index();
            $table->boolean('complete');
            $table->string('operator_name');
            $table->string('deviation_reason', 1000)->nullable();
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('production_scale_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->date('checked_date')->index();
            $table->decimal('powder_3kg_reading', 10, 3);
            $table->decimal('powder_30kg_reading', 10, 3);
            $table->decimal('pallecon_scale_reading', 10, 3);
            $table->decimal('bucket_filler_scale_reading', 10, 3);
            $table->boolean('passed')->default(true);
            $table->string('operator_name');
            $table->string('deviation_reason', 1000)->nullable();
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_scale_calibrations');
        Schema::dropIfExists('viscosity_meter_autozero_checks');
        Schema::dropIfExists('salt_meter_calibrations');
        Schema::dropIfExists('lab_scale_calibrations');
    }
};
