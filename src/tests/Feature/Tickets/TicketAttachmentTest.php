<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    #[Test]
    public function customer_can_upload_attachments_during_ticket_creation(): void
    {
        $customer = User::factory()->customer()->create();
        $category = TicketCategory::factory()->create(['is_active' => true]);

        $file1 = UploadedFile::fake()->create('screenshot.png', 500, 'image/png');
        $file2 = UploadedFile::fake()->create('system-report.pdf', 1200, 'application/pdf');

        $response = $this->actingAs($customer)
            ->from(route('tickets.create'))
            ->post(route('tickets.store'), [
                'category_id' => $category->id,
                'priority' => TicketPriority::MEDIUM->value,
                'subject' => 'Issue with invoices and receipts',
                'description' => 'The invoice PDFs are failing to generate properly on checkout.',
                'attachments' => [$file1, $file2],
            ]);

        $ticket = Ticket::query()->where('subject', 'Issue with invoices and receipts')->first();
        $this->assertNotNull($ticket);

        $response->assertRedirect(route('tickets.show', $ticket));

        $this->assertCount(2, $ticket->attachments);

        /** @var TicketAttachment $attachment */
        foreach ($ticket->attachments as $attachment) {
            $this->assertSame('private', $attachment->disk);
            $this->assertSame($customer->id, $attachment->uploaded_by);
            Storage::disk('private')->assertExists($attachment->file_path);
        }
    }

    #[Test]
    public function user_can_upload_attachments_with_ticket_reply(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($customer, 'creator')->create();

        $file = UploadedFile::fake()->create('stacktrace.txt', 50, 'text/plain');

        $response = $this->actingAs($customer)
            ->from(route('tickets.show', $ticket))
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'Attaching full exception stacktrace from our error logs.',
                'attachments' => [$file],
            ]);

        $response->assertRedirect(route('tickets.show', $ticket));

        $message = $ticket->messages()->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertCount(1, $message->attachments);

        $attachment = $message->attachments->first();
        $this->assertSame('stacktrace.txt', $attachment->file_name);
        Storage::disk('private')->assertExists($attachment->file_path);
    }

    #[Test]
    public function attachment_upload_rejects_unauthorized_extensions_and_oversized_files(): void
    {
        $customer = User::factory()->customer()->create();
        $category = TicketCategory::factory()->create(['is_active' => true]);

        // .exe extension should be rejected
        $exeFile = UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload');
        // > 10MB (11MB = 11264 KB) should be rejected
        $largeFile = UploadedFile::fake()->create('massive_archive.zip', 11500, 'application/zip');

        $response = $this->actingAs($customer)
            ->from(route('tickets.create'))
            ->post(route('tickets.store'), [
                'category_id' => $category->id,
                'priority' => TicketPriority::LOW->value,
                'subject' => 'Testing validation rules',
                'description' => 'Detailed ticket description for file validation test.',
                'attachments' => [$exeFile, $largeFile],
            ]);

        $response->assertRedirect(route('tickets.create'));
        $response->assertSessionHasErrors(['attachments.0', 'attachments.1']);
        $this->assertDatabaseCount('ticket_attachments', 0);
    }

    #[Test]
    public function authorized_users_can_download_attachments_and_unauthorized_users_are_forbidden(): void
    {
        $owner = User::factory()->customer()->create();
        $otherCustomer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $ticket = Ticket::factory()->for($owner, 'creator')->create([
            'assigned_agent_id' => $agent->id,
        ]);

        $fakeFile = UploadedFile::fake()->create('test-document.pdf', 300, 'application/pdf');
        $attachmentService = app(AttachmentService::class);
        $attachment = $attachmentService->upload($fakeFile, $ticket, $owner);

        // Ticket owner can download
        $this->actingAs($owner)
            ->get(route('ticket_attachments.download', $attachment))
            ->assertOk();

        // Assigned agent can download
        $this->actingAs($agent)
            ->get(route('ticket_attachments.download', $attachment))
            ->assertOk();

        // Admin can download
        $this->actingAs($admin)
            ->get(route('ticket_attachments.download', $attachment))
            ->assertOk();

        // Unrelated customer cannot download
        $this->actingAs($otherCustomer)
            ->get(route('ticket_attachments.download', $attachment))
            ->assertForbidden();
    }

    #[Test]
    public function customer_cannot_download_attachments_on_internal_notes(): void
    {
        $owner = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->for($owner, 'creator')->create();

        $internalNote = TicketMessage::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'body' => 'Confidential internal note with private document attached.',
            'is_internal' => true,
        ]);

        $fakeFile = UploadedFile::fake()->create('confidential-internal-notes.pdf', 200, 'application/pdf');
        $attachmentService = app(AttachmentService::class);
        $attachment = $attachmentService->upload($fakeFile, $internalNote, $agent);

        // Agent can download
        $this->actingAs($agent)
            ->get(route('ticket_attachments.download', $attachment))
            ->assertOk();

        // Ticket owner is forbidden from downloading internal note attachments
        $this->actingAs($owner)
            ->get(route('ticket_attachments.download', $attachment))
            ->assertForbidden();
    }

    #[Test]
    public function attachment_service_delete_removes_file_and_database_record(): void
    {
        $user = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($user, 'creator')->create();

        $fakeFile = UploadedFile::fake()->create('cleanup-test.png', 100, 'image/png');
        $attachmentService = app(AttachmentService::class);
        $attachment = $attachmentService->upload($fakeFile, $ticket, $user);

        $filePath = $attachment->file_path;
        Storage::disk('private')->assertExists($filePath);
        $this->assertDatabaseHas('ticket_attachments', ['id' => $attachment->id]);

        $deleted = $attachmentService->delete($attachment);
        $this->assertTrue($deleted);

        Storage::disk('private')->assertMissing($filePath);
        $this->assertDatabaseMissing('ticket_attachments', ['id' => $attachment->id]);
    }
}
