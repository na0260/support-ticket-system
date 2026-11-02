<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketCreatePriorityTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->customer()->create();
        $this->category = TicketCategory::factory()->create(['is_active' => true]);
    }

    #[Test]
    public function ticket_create_view_renders_priority_selector_with_sla_commitments(): void
    {
        $response = $this->actingAs($this->customer)->get(route('tickets.create'));

        $response->assertOk();
        $response->assertSee('id="priority"', false);
        $response->assertSee('name="priority"', false);
        $response->assertSee('Low: 48 Hours Resolution SLA');
        $response->assertSee('Medium: 24 Hours Resolution SLA');
        $response->assertSee('High: 8 Hours Resolution SLA');
        $response->assertSee('Critical: 2 Hours Resolution SLA');
    }

    #[Test]
    public function ticket_creation_accepts_all_priority_levels_and_sets_correct_sla(): void
    {
        foreach (TicketPriority::cases() as $priority) {
            $subject = "Testing SLA for priority {$priority->value}";

            $response = $this->actingAs($this->customer)->post(route('tickets.store'), [
                'category_id' => $this->category->id,
                'priority' => $priority->value,
                'subject' => $subject,
                'description' => 'Detailed description of the issue that exceeds ten characters in length.',
            ]);

            $ticket = Ticket::query()->where('subject', $subject)->first();
            $this->assertNotNull($ticket, "Failed to create ticket for priority {$priority->value}");
            $this->assertSame($priority, $ticket->priority);
            $this->assertSame(TicketStatus::OPEN, $ticket->status);
            $this->assertSame(SlaStatus::WITHIN_SLA, $ticket->sla_status);
            $this->assertNotNull($ticket->sla_due_at);
            $this->assertNotNull($ticket->sla_warning_at);
            $this->assertTrue($ticket->sla_due_at->isAfter(now()));

            $response->assertRedirect(route('tickets.show', $ticket));
        }
    }

    #[Test]
    public function ticket_creation_rejects_invalid_priority_value(): void
    {
        $response = $this->actingAs($this->customer)->post(route('tickets.store'), [
            'category_id' => $this->category->id,
            'priority' => 'ultra_critical_invalid',
            'subject' => 'Invalid Priority Test Subject',
            'description' => 'Detailed description of the issue that exceeds ten characters in length.',
        ]);

        $response->assertSessionHasErrors(['priority']);
        $this->assertDatabaseMissing('tickets', [
            'subject' => 'Invalid Priority Test Subject',
        ]);
    }
}
