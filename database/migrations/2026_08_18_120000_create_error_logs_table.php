<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DBMTS Error Log - an "event viewer" style record of problems encountered
 * while operators book through checks and production (validation failures,
 * SQL/DB errors, domain exceptions and any other uncaught exception).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level')->default('error'); // validation | error | critical
            $table->string('exception_class')->nullable();
            $table->text('message');
            $table->string('context')->nullable(); // route name or feature/job that raised it
            $table->string('url')->nullable();
            $table->string('http_method', 10)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->text('trace')->nullable();
            $table->timestamps();

            $table->index(['level', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
