<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Notifications\SlaApproachingBreachNotification;
use App\Notifications\SlaBreachedNotification;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketNotificationsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function all_ticket_notifications_implement_should_queue(): void
    {
        $ticket = Ticket::factory()->make();

        $notifications = [
            new TicketCreatedNotification($ticket),
            new TicketAssignedNotification($ticket),
            new TicketRepliedNotification($ticket, $ticket->messages()->make(['body' => 'Test'])),
            new TicketResolvedNotification($ticket),
            new SlaApproachingBreachNotification($ticket),
            new SlaBreachedNotification($ticket),
        ];

        foreach ($notifications as $notification) {
            $this->assertInstanceOf(
                ShouldQueue::class,
                $notification,
                get_class($notification).' must implement ShouldQueue.'
            );
        }
    }

    #[Test]
    public function customer_receives_ticket_created_notification_on_submission(): void
    {
        Notification::fake();

        $customer = User::factory()->customer()->create();
        $category = TicketCategory::factory()->create(['is_active' => true]);

        $this->actingAs($customer)
            ->post(route('tickets.store'), [
                'category_id' => $category->id,
                'priority' => TicketPriority::MEDIUM->value,
                'subject' => 'SSL certificate validation error',
                'description' => 'Our production domain returns ERR_SSL_VERSION_OR_CIPHER_MISMATCH.',
            ]);

        Notification::assertSentTo($customer, TicketCreatedNotification::class);
    }

    #[Test]
    public function agent_receives_ticket_assigned_notification_on_manual_assignment(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'status' => TicketStatus::OPEN,
        ]);

        $this->actingAs($admin)
            ->post(route('tickets.assign', $ticket), [
                'assigned_agent_id' => $agent->id,
            ]);

        Notification::assertSentTo($agent, TicketAssignedNotification::class);
    }

    #[Test]
    public function customer_receives_reply_notification_when_agent_replies_publicly(): void
    {
        Notification::fake();

        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        $this->actingAs($agent)
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'We have deployed an updated cipher suite. Please retry.',
                'is_internal' => false,
            ]);

        Notification::assertSentTo($customer, TicketRepliedNotification::class);
    }

    #[Test]
    public function agent_receives_reply_notification_when_customer_replies(): void
    {
        Notification::fake();

        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::WAITING_CUSTOMER,
        ]);

        $this->actingAs($customer)
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'Thank you, confirmed working properly on our staging environment.',
                'is_internal' => false,
            ]);

        Notification::assertSentTo($agent, TicketRepliedNotification::class);
    }

    #[Test]
    public function internal_notes_never_trigger_notifications_to_customers(): void
    {
        Notification::fake();

        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        $this->actingAs($agent)
            ->post(route('ticket_messages.store', $ticket), [
                'body' => 'Private internal diagnostic details for infrastructure team.',
                'is_internal' => true,
            ]);

        Notification::assertNotSentTo($customer, TicketRepliedNotification::class);
    }

    #[Test]
    public function customer_receives_ticket_resolved_notification(): void
    {
        Notification::fake();

        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        $ticket = Ticket::factory()->for($customer, 'creator')->create([
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        $this->actingAs($agent)
            ->post(route('tickets.resolve', $ticket));

        Notification::assertSentTo($customer, TicketResolvedNotification::class);
    }
}
