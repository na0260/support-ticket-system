<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketAttachmentPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_cannot_download_files_attached_to_internal_notes(): void
    {
        $owner = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($owner, 'creator')->create();
        $agent = User::factory()->agent()->create();

        $internalNote = TicketMessage::factory()
            ->for($ticket)
            ->internal()
            ->create(['user_id' => $agent->id]);

        $attachment = $internalNote->attachments()->create([
            'disk' => 'private',
            'file_path' => 'attachments/secret.pdf',
            'file_name' => 'secret.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $agent->id,
        ]);

        // Customer who owns the ticket MUST be rejected with 403
        $this->actingAs($owner)
            ->get(route('ticket_attachments.download', $attachment))
            ->assertForbidden();

        // Other customer also rejected
        $otherCustomer = User::factory()->customer()->create();
        $this->actingAs($otherCustomer)
            ->get(route('ticket_attachments.download', $attachment))
            ->assertForbidden();

        // Staff can download internal note attachment
        $this->actingAs($agent)
            ->get(route('ticket_attachments.download', $attachment))
            ->assertOk();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->get(route('ticket_attachments.download', $attachment))
            ->assertOk();
    }

    #[Test]
    public function customer_can_download_attachments_belonging_to_their_own_tickets_or_replies(): void
    {
        $owner = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($owner, 'creator')->create();

        // Attachment on ticket directly
        $ticketAttachment = $ticket->attachments()->create([
            'disk' => 'private',
            'file_path' => 'attachments/ticket.png',
            'file_name' => 'ticket.png',
            'mime_type' => 'image/png',
            'file_size' => 2048,
            'uploaded_by' => $owner->id,
        ]);

        // Attachment on a public reply
        $publicReply = TicketMessage::factory()->for($ticket)->create(['is_internal' => false]);
        $replyAttachment = $publicReply->attachments()->create([
            'disk' => 'private',
            'file_path' => 'attachments/reply.png',
            'file_name' => 'reply.png',
            'mime_type' => 'image/png',
            'file_size' => 2048,
            'uploaded_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->get(route('ticket_attachments.download', $ticketAttachment))
            ->assertOk();

        $this->actingAs($owner)
            ->get(route('ticket_attachments.download', $replyAttachment))
            ->assertOk();
    }

    #[Test]
    public function unauthorized_user_cannot_download_random_ticket_attachments(): void
    {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $ticket = Ticket::factory()->for($owner, 'creator')->create();

        $ticketAttachment = $ticket->attachments()->create([
            'disk' => 'private',
            'file_path' => 'attachments/random.png',
            'file_name' => 'random.png',
            'mime_type' => 'image/png',
            'file_size' => 2048,
            'uploaded_by' => $owner->id,
        ]);

        $this->actingAs($stranger)
            ->get(route('ticket_attachments.download', $ticketAttachment))
            ->assertForbidden();
    }
}
