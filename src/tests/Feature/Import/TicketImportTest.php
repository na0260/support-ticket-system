<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Jobs\ProcessTicketImportJob;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketImport;
use App\Models\TicketImportFailure;
use App\Models\User;
use App\Services\Import\CsvReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketImportTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $customer;

    private TicketCategory $billingCategory;

    private TicketCategory $techCategory;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->agent = User::factory()->agent()->create(['name' => 'Agent Scully', 'email' => 'scully@example.com']);
        $this->customer = User::factory()->customer()->create(['name' => 'Mulder Fox', 'email' => 'mulder@example.com']);
        $this->billingCategory = TicketCategory::factory()->create(['name' => 'Billing Support', 'slug' => 'billing-support', 'is_active' => true]);
        $this->techCategory = TicketCategory::factory()->create(['name' => 'Technical Inquiry', 'slug' => 'technical-inquiry', 'is_active' => true]);
    }

    #[Test]
    public function customer_cannot_access_import_routes(): void
    {
        $responseIndex = $this->actingAs($this->customer)->get(route('imports.index'));
        $responseIndex->assertForbidden();

        $responseSample = $this->actingAs($this->customer)->get(route('imports.sample'));
        $responseSample->assertForbidden();

        $responseStore = $this->actingAs($this->customer)->post(route('imports.store'), [
            'file' => UploadedFile::fake()->create('test.csv', 10),
        ]);
        $responseStore->assertForbidden();

        $responseTicketsImport = $this->actingAs($this->customer)->post(route('tickets.import'), [
            'file' => UploadedFile::fake()->create('test.csv', 10),
        ]);
        $responseTicketsImport->assertForbidden();

        $import = TicketImport::factory()->create([
            'user_id' => $this->agent->id,
            'file_name' => 'dummy.csv',
            'file_path' => 'imports/dummy.csv',
        ]);

        $responseShow = $this->actingAs($this->customer)->get(route('imports.show', $import));
        $responseShow->assertForbidden();

        $responseFailures = $this->actingAs($this->customer)->get(route('imports.failures', $import));
        $responseFailures->assertForbidden();
    }

    #[Test]
    public function staff_can_upload_valid_csv_and_dispatch_job(): void
    {
        Queue::fake();

        $csvContent = implode("\n", [
            'subject,description,priority,category,customer_email,assigned_agent_email',
            'Payment Gateway Failure,"The checkout gateway is returning HTTP 500 on all transactions.",critical,billing-support,mulder@example.com,scully@example.com',
        ]);

        $file = UploadedFile::fake()->createWithContent('tickets.csv', $csvContent);

        $response = $this->actingAs($this->agent)->post(route('imports.store'), [
            'file' => $file,
        ]);

        $import = TicketImport::query()->first();
        $this->assertNotNull($import);
        $this->assertSame('tickets.csv', $import->file_name);
        $this->assertSame(TicketImport::STATUS_PENDING, $import->status);
        $this->assertSame($this->agent->id, $import->user_id);

        Storage::disk('private')->assertExists($import->file_path);

        Queue::assertPushed(ProcessTicketImportJob::class, function (ProcessTicketImportJob $job) use ($import): bool {
            return $job->ticketImport->id === $import->id;
        });

        $response->assertRedirect(route('imports.show', $import));
        $response->assertSessionHas('status');
    }

    #[Test]
    public function staff_can_upload_csv_via_tickets_import_endpoint(): void
    {
        Queue::fake();

        $csvContent = implode("\n", [
            'subject,description,priority,category,customer_email,assigned_agent_email',
            'API rate limiter timeout,"Rate limiter redis cluster degraded.",high,technical-inquiry,mulder@example.com,scully@example.com',
        ]);

        $file = UploadedFile::fake()->createWithContent('tickets_batch.csv', $csvContent);

        $response = $this->actingAs($this->agent)->postJson(route('tickets.import'), [
            'file' => $file,
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('message', 'Import queued successfully.');

        Queue::assertPushed(ProcessTicketImportJob::class);
    }

    #[Test]
    public function import_job_processes_valid_rows_successfully(): void
    {
        $csvContent = implode("\n", [
            'subject,description,priority,category,customer_email,assigned_agent_email,status',
            'Database latency spike,"Database queries are taking over 5000ms during peak load.",high,technical-inquiry,mulder@example.com,scully@example.com,in_progress',
            'Refund calculation error,"Incorrect VAT rate applied to EU international refund.",medium,billing-support,mulder@example.com,,open',
        ]);

        $path = 'imports/test_valid.csv';
        Storage::disk('private')->put($path, $csvContent);

        $import = TicketImport::query()->create([
            'user_id' => $this->agent->id,
            'file_name' => 'test_valid.csv',
            'file_path' => $path,
            'status' => TicketImport::STATUS_PENDING,
            'total_rows' => 0,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
        ]);

        ProcessTicketImportJob::dispatchSync($import);

        $import->refresh();

        $this->assertSame(TicketImport::STATUS_COMPLETED, $import->status);
        $this->assertSame(2, $import->total_rows);
        $this->assertSame(2, $import->processed_rows);
        $this->assertSame(2, $import->successful_rows);
        $this->assertSame(0, $import->failed_rows);
        $this->assertNotNull($import->completed_at);

        // Verify tickets created in DB
        $ticket1 = Ticket::query()->where('subject', 'Database latency spike')->first();
        $this->assertNotNull($ticket1);
        $this->assertSame(TicketPriority::HIGH, $ticket1->priority);
        $this->assertSame(TicketStatus::IN_PROGRESS, $ticket1->status);
        $this->assertSame($this->agent->id, $ticket1->assigned_agent_id);
        $this->assertSame($this->customer->id, $ticket1->created_by);
        $this->assertSame($this->techCategory->id, $ticket1->category_id);
        $this->assertSame(SlaStatus::WITHIN_SLA, $ticket1->sla_status);
        $this->assertNotNull($ticket1->sla_due_at);
        $this->assertNotNull($ticket1->sla_warning_at);

        $ticket2 = Ticket::query()->where('subject', 'Refund calculation error')->first();
        $this->assertNotNull($ticket2);
        $this->assertSame(TicketPriority::MEDIUM, $ticket2->priority);
        $this->assertSame(TicketStatus::OPEN, $ticket2->status);
        $this->assertNull($ticket2->assigned_agent_id);

        // Verify audit trail logged
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket1->id,
            'event' => 'ticket_imported',
        ]);

        // Verify file deleted upon completion
        Storage::disk('private')->assertMissing($path);
    }

    #[Test]
    public function import_job_handles_invalid_rows_and_duplicate_tickets(): void
    {
        $existingTicket = Ticket::factory()->create([
            'ticket_number' => 'TKT-2026-EXISTS',
            'created_by' => $this->customer->id,
        ]);

        $csvContent = implode("\n", [
            'subject,description,priority,category,customer_email,assigned_agent_email,ticket_number',
            // Row 2: Valid
            'Valid Ticket Alpha,"Detailed description that is definitely longer than ten chars.",low,billing-support,mulder@example.com,,',
            // Row 3: Invalid category
            'Invalid Category Ticket,"Detailed description about some nonexistent category.",low,nonexistent-category,mulder@example.com,,',
            // Row 4: Description too short (< 10 chars)
            'Short Desc,"Short",high,billing-support,mulder@example.com,,',
            // Row 5: Duplicate ticket_number (matches existing in DB)
            'Duplicate Ticket DB,"Detailed description about a ticket that collides with DB.",critical,technical-inquiry,mulder@example.com,,TKT-2026-EXISTS',
            // Row 6: Non-existent customer email
            'Missing Customer Ticket,"Detailed description about unknown customer account.",medium,technical-inquiry,nonexistent@example.com,,',
        ]);

        $path = 'imports/test_invalid.csv';
        Storage::disk('private')->put($path, $csvContent);

        $import = TicketImport::query()->create([
            'user_id' => $this->agent->id,
            'file_name' => 'test_invalid.csv',
            'file_path' => $path,
            'status' => TicketImport::STATUS_PENDING,
            'total_rows' => 0,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
        ]);

        ProcessTicketImportJob::dispatchSync($import);

        $import->refresh();

        $this->assertSame(TicketImport::STATUS_COMPLETED, $import->status);
        $this->assertSame(5, $import->total_rows);
        $this->assertSame(5, $import->processed_rows);
        $this->assertSame(1, $import->successful_rows);
        $this->assertSame(4, $import->failed_rows);

        // Only valid ticket was created
        $this->assertDatabaseHas('tickets', [
            'subject' => 'Valid Ticket Alpha',
        ]);
        $this->assertDatabaseMissing('tickets', [
            'subject' => 'Invalid Category Ticket',
        ]);

        // Verify failures recorded with row numbers
        $this->assertDatabaseCount('ticket_import_failures', 4);

        $failures = TicketImportFailure::query()->where('ticket_import_id', $import->id)->get();

        $catFailure = $failures->firstWhere('row_number', 3);
        $this->assertNotNull($catFailure);
        $this->assertArrayHasKey('category', $catFailure->errors);

        $shortFailure = $failures->firstWhere('row_number', 4);
        $this->assertNotNull($shortFailure);
        $this->assertArrayHasKey('description', $shortFailure->errors);

        $dupFailure = $failures->firstWhere('row_number', 5);
        $this->assertNotNull($dupFailure);
        $this->assertArrayHasKey('ticket_number', $dupFailure->errors);

        $custFailure = $failures->firstWhere('row_number', 6);
        $this->assertNotNull($custFailure);
        $this->assertArrayHasKey('customer_email', $custFailure->errors);
    }

    #[Test]
    public function memory_safe_streaming_processes_large_dataset_with_flat_memory(): void
    {
        $csvReader = new CsvReaderService;
        $tempPath = tempnam(sys_get_temp_dir(), 'csv_mem_test_');
        $this->assertNotFalse($tempPath);

        $handle = fopen($tempPath, 'w');
        fputcsv($handle, ['subject', 'description', 'priority', 'category', 'customer_email']);

        // Generate 1,500 rows
        for ($i = 1; $i <= 1500; $i++) {
            fputcsv($handle, [
                "Automated Stress Test Subject {$i}",
                "Detailed description content for ticket number {$i} to verify memory safety.",
                'medium',
                'billing-support',
                'mulder@example.com',
            ]);
        }
        fclose($handle);

        $startMemory = memory_get_usage(true);

        $stream = $csvReader->streamRows($tempPath);
        $iteratedCount = 0;

        foreach ($stream as $row) {
            $iteratedCount++;
            $this->assertArrayHasKey('row_number', $row);
            $this->assertArrayHasKey('data', $row);
            $this->assertSame("Automated Stress Test Subject {$iteratedCount}", $row['data']['subject']);
        }

        $endMemory = memory_get_usage(true);
        $memoryDeltaMb = ($endMemory - $startMemory) / 1024 / 1024;

        unlink($tempPath);

        $this->assertSame(1500, $iteratedCount);
        // Assert memory growth was strictly minimal (under 3MB)
        $this->assertLessThan(3.0, $memoryDeltaMb, 'Streaming CSV resulted in excessive memory allocation.');
    }

    #[Test]
    public function download_failures_csv_stream(): void
    {
        $import = TicketImport::query()->create([
            'user_id' => $this->agent->id,
            'file_name' => 'corrupted.csv',
            'file_path' => 'imports/corrupted.csv',
            'status' => TicketImport::STATUS_COMPLETED,
            'total_rows' => 2,
            'processed_rows' => 2,
            'successful_rows' => 0,
            'failed_rows' => 2,
        ]);

        TicketImportFailure::query()->create([
            'ticket_import_id' => $import->id,
            'row_number' => 2,
            'raw_data' => [
                'subject' => 'Bad Priority Ticket',
                'description' => 'Valid description length here.',
                'priority' => 'ultra_urgent',
                'category' => 'billing-support',
                'customer_email' => 'mulder@example.com',
            ],
            'errors' => [
                'priority' => ['The selected priority is invalid.'],
            ],
        ]);

        TicketImportFailure::query()->create([
            'ticket_import_id' => $import->id,
            'row_number' => 3,
            'raw_data' => [
                'subject' => 'Bad Category Ticket',
                'description' => 'Another valid description string.',
                'priority' => 'low',
                'category' => 'ghost-category',
                'customer_email' => 'mulder@example.com',
            ],
            'errors' => [
                'category' => ['The selected category does not exist or is inactive.'],
            ],
        ]);

        $response = $this->actingAs($this->agent)->get(route('imports.failures', $import));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', "attachment; filename=\"import_{$import->id}_failures.csv\"");

        $content = $response->streamedContent();
        $this->assertStringContainsString('row_number', $content);
        $this->assertStringContainsString('Bad Priority Ticket', $content);
        $this->assertStringContainsString('The selected priority is invalid.', $content);
        $this->assertStringContainsString('Bad Category Ticket', $content);
        $this->assertStringContainsString('ghost-category', $content);
    }

    #[Test]
    public function download_sample_template_csv(): void
    {
        $response = $this->actingAs($this->agent)->get(route('imports.sample'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="ticket_import_template.csv"');

        $content = $response->streamedContent();
        $this->assertStringContainsString('subject,description,priority,category,customer_email,assigned_agent_email,status,ticket_number', $content);
        $this->assertStringContainsString('Database connection timeout during peak load', $content);
    }
}
