<?php

namespace Tests\Feature\Audit;

use App\Domains\Audit\Jobs\RecordErrorLogJob;
use App\Models\ErrorLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ErrorLogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_error_log_job_stores_exception_details(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $exception = new RuntimeException('Something went wrong booking the batch.');

        $log = app(RecordErrorLogJob::class)($exception, 'batches.show.complete');

        $this->assertDatabaseHas('error_logs', [
            'id' => $log->id,
            'level' => ErrorLog::LEVEL_ERROR,
            'exception_class' => RuntimeException::class,
            'message' => 'Something went wrong booking the batch.',
            'context' => 'batches.show.complete',
            'user_id' => $user->id,
        ]);
    }

    public function test_admin_can_view_error_log_page_filtered_by_level(): void
    {
        Role::findOrCreate('administrator');

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('administrator');

        ErrorLog::create([
            'level' => ErrorLog::LEVEL_VALIDATION,
            'exception_class' => 'Illuminate\\Validation\\ValidationException',
            'message' => 'The reading field is required.',
            'context' => 'calibrations.daily.saveWm001',
        ]);

        ErrorLog::create([
            'level' => ErrorLog::LEVEL_CRITICAL,
            'exception_class' => 'Illuminate\\Database\\QueryException',
            'message' => 'SQLSTATE connection refused.',
            'context' => 'winman.booking',
        ]);

        Volt::actingAs($user)
            ->test('pages.audit.errors')
            ->set('level', 'validation')
            ->assertSee('The reading field is required.')
            ->assertDontSee('SQLSTATE connection refused.');
    }
}
