<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DBMTS first-class pallecon container + batch fill bridge.
 *
 * Replaces the one-batch-per-pallecon assumption of pallecon_records: a pallecon
 * is a physical container filled from one or more batches (pallecon_fills), and
 * one batch may be split across several containers. The final recorded weight
 * lives on the container (captured at scale-off) and is authoritative for labels.
 *
 * serial_number is intentionally NOT globally unique: a physical pallecon is
 * reused across many production runs. Uniqueness among currently-active
 * containers is enforced in the OpenPalleconJob, not at the database level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pallecons', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number')->nullable();
            $table->string('status')->default('open'); // open | filling | sealed | on_hold | consumed
            $table->string('mo_number')->nullable();    // legacy/reporting convenience, non-authoritative
            $table->decimal('capacity_kg', 12, 3)->nullable();      // snapshot of configured capacity at open
            $table->decimal('final_weight', 12, 3)->nullable();     // recorded at scale-off, drives label
            $table->string('top_seal_number')->nullable();
            $table->string('bottom_seal_number')->nullable();
            $table->string('liner_number')->nullable();
            $table->string('liner_batch_code')->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('sealed_at')->nullable();
            // NO ACTION (constrained default) - user is a cross-reference, not an owner.
            $table->foreignId('sealed_by')->nullable()->constrained('users');
            $table->string('hold_reason', 500)->nullable();
            $table->dateTime('held_at')->nullable();
            $table->timestamps();

            $table->index('serial_number');
            $table->index('status');
        });

        Schema::create('pallecon_fills', function (Blueprint $table) {
            $table->id();
            // Container owns its fills: cascade on the single owner FK.
            $table->foreignId('pallecon_id')->constrained('pallecons')->cascadeOnDelete();
            // NO ACTION avoids a second cascade path; batch is a cross-reference here.
            $table->foreignId('batch_record_id')->constrained('batch_records');
            $table->decimal('fill_weight', 12, 3)->nullable(); // optional per-batch contribution weight
            $table->unsignedInteger('sequence')->default(1);
            $table->dateTime('filled_at')->nullable();
            // NO ACTION (constrained default).
            $table->foreignId('signed_by')->nullable()->constrained('users');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('pallecon_id');
            $table->index('batch_record_id');
        });

        $this->backfillFromLegacyPalleconRecords();
    }

    public function down(): void
    {
        Schema::dropIfExists('pallecon_fills');
        Schema::dropIfExists('pallecons');
    }

    /**
     * Mechanically migrate existing one-to-one pallecon_records rows: each legacy
     * row becomes one container plus one fill for its owning batch. This is exact
     * under the old assumption; only genuinely shared containers created after
     * this migration need the new multi-fill flow.
     */
    private function backfillFromLegacyPalleconRecords(): void
    {
        if (! Schema::hasTable('pallecon_records')) {
            return;
        }

        DB::table('pallecon_records')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $finalWeight = $row->fill_weight ?? null;
                $sealedAt = $row->checked_at ?? $row->finish_time ?? $row->updated_at ?? null;
                $openedAt = $row->start_time ?? $row->created_at ?? null;

                $palleconId = DB::table('pallecons')->insertGetId([
                    'serial_number' => $row->serial_number ?? null,
                    'status' => $finalWeight !== null ? 'sealed' : 'filling',
                    'mo_number' => $row->mo_number ?? null,
                    'final_weight' => $finalWeight,
                    'top_seal_number' => $row->top_seal_number ?? null,
                    'bottom_seal_number' => $row->bottom_seal_number ?? null,
                    'liner_number' => $row->liner_number ?? null,
                    'liner_batch_code' => $row->liner_batch_code ?? null,
                    'opened_at' => $openedAt,
                    'sealed_at' => $finalWeight !== null ? $sealedAt : null,
                    'sealed_by' => $finalWeight !== null ? ($row->checked_by ?? null) : null,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);

                DB::table('pallecon_fills')->insert([
                    'pallecon_id' => $palleconId,
                    'batch_record_id' => $row->batch_record_id,
                    'fill_weight' => $finalWeight,
                    'sequence' => 1,
                    'filled_at' => $row->finish_time ?? $row->checked_at ?? $row->created_at ?? now(),
                    'signed_by' => $row->checked_by ?? null,
                    'notes' => 'Migrated from legacy pallecon_records #'.$row->id,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }
        });
    }
};
