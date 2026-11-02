<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $customer;

    private TicketCategory $billingCategory;

    private TicketCategory $techCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = User::factory()->agent()->create(['name' => 'Agent Smith']);
        $this->customer = User::factory()->customer()->create(['name' => 'John Doe']);
        $this->billingCategory = TicketCategory::factory()->create(['name' => 'Billing Support']);
        $this->techCategory = TicketCategory::factory()->create(['name' => 'Technical Inquiry']);
    }

    #[Test]
    public function search_by_exact_and_partial_ticket_number(): void
    {
        $ticket1 = Ticket::factory()->create([
            'ticket_number' => 'TKT-2026-000101',
            'subject' => 'Billing invoice issue',
            'created_by' => $this->customer->id,
        ]);

        $ticket2 = Ticket::factory()->create([
            'ticket_number' => 'TKT-2026-000999',
            'subject' => 'Database crash error',
            'created_by' => $this->customer->id,
        ]);

        $response = $this->actingAs($this->agent)->get(route('tickets.index', [
            'keyword' => '000101',
        ]));

        $response->assertOk();
        $response->assertSee('TKT-2026-000101');
        $response->assertDontSee('TKT-2026-000999');

        $responseExplicit = $this->actingAs($this->agent)->get(route('tickets.index', [
            'ticket_number' => '000999',
        ]));

        $responseExplicit->assertOk();
        $responseExplicit->assertSee('TKT-2026-000999');
        $responseExplicit->assertDontSee('TKT-2026-000101');
    }

    #[Test]
    public function keyword_search_matches_subject_and_description(): void
    {
        $ticketMatchSubject = Ticket::factory()->create([
            'subject' => 'Kubernetes Pod Out of Memory',
            'description' => 'The cluster nodes are stable.',
            'created_by' => $this->customer->id,
        ]);

        $ticketMatchDesc = Ticket::factory()->create([
            'subject' => 'General Question',
            'description' => 'Please restart the Kubernetes cluster pods immediately.',
            'created_by' => $this->customer->id,
        ]);

        $ticketNoMatch = Ticket::factory()->create([
            'subject' => 'Email notification failed',
            'description' => 'SMTP server timeout.',
            'created_by' => $this->customer->id,
        ]);

        $response = $this->actingAs($this->agent)->get(route('tickets.index', [
            'keyword' => 'Kubernetes',
        ]));

        $response->assertOk();
        $response->assertSee($ticketMatchSubject->subject);
        $response->assertSee($ticketMatchDesc->subject);
        $response->assertDontSee($ticketNoMatch->subject);
    }

    #[Test]
    public function multi_filter_by_status_priority_and_category(): void
    {
        $targetTicket = Ticket::factory()->create([
            'category_id' => $this->billingCategory->id,
            'priority' => TicketPriority::CRITICAL,
            'status' => TicketStatus::OPEN,
            'subject' => 'Critical Billing Overcharge',
            'created_by' => $this->customer->id,
        ]);

        $otherCategory = Ticket::factory()->create([
            'category_id' => $this->techCategory->id,
            'priority' => TicketPriority::CRITICAL,
            'status' => TicketStatus::OPEN,
            'subject' => 'Critical Tech Issue',
            'created_by' => $this->customer->id,
        ]);

        $otherPriority = Ticket::factory()->create([
            'category_id' => $this->billingCategory->id,
            'priority' => TicketPriority::LOW,
            'status' => TicketStatus::OPEN,
            'subject' => 'Low Billing Inquiry',
            'created_by' => $this->customer->id,
        ]);

        $response = $this->actingAs($this->agent)->get(route('tickets.index', [
            'category_id' => $this->billingCategory->id,
            'priority' => TicketPriority::CRITICAL->value,
            'status' => TicketStatus::OPEN->value,
        ]));

        $response->assertOk();
        $response->assertSee($targetTicket->subject);
        $response->assertDontSee($otherCategory->subject);
        $response->assertDontSee($otherPriority->subject);
    }

    #[Test]
    public function filter_by_assigned_agent_and_unassigned(): void
    {
        $otherAgent = User::factory()->agent()->create(['name' => 'Agent Jones']);

        $ticketAssignedToSmith = Ticket::factory()->create([
            'assigned_agent_id' => $this->agent->id,
            'subject' => 'Smith Assignment Ticket',
            'created_by' => $this->customer->id,
        ]);

        $ticketAssignedToJones = Ticket::factory()->create([
            'assigned_agent_id' => $otherAgent->id,
            'subject' => 'Jones Assignment Ticket',
            'created_by' => $this->customer->id,
        ]);

        $unassignedTicket = Ticket::factory()->create([
            'assigned_agent_id' => null,
            'subject' => 'Lonely Unassigned Ticket',
            'created_by' => $this->customer->id,
        ]);

        // Filter by Agent Smith
        $responseSmith = $this->actingAs($this->agent)->get(route('tickets.index', [
            'assigned_agent_id' => $this->agent->id,
        ]));
        $responseSmith->assertOk();
        $responseSmith->assertSee($ticketAssignedToSmith->subject);
        $responseSmith->assertDontSee($ticketAssignedToJones->subject);
        $responseSmith->assertDontSee($unassignedTicket->subject);

        // Filter unassigned
        $responseUnassigned = $this->actingAs($this->agent)->get(route('tickets.index', [
            'assigned_agent_id' => 'unassigned',
        ]));
        $responseUnassigned->assertOk();
        $responseUnassigned->assertSee($unassignedTicket->subject);
        $responseUnassigned->assertDontSee($ticketAssignedToSmith->subject);
        $responseUnassigned->assertDontSee($ticketAssignedToJones->subject);
    }

    #[Test]
    public function filter_by_sla_status_and_date_range(): void
    {
        $breachedYesterday = Ticket::factory()->breached()->create([
            'subject' => 'Breached Ticket Yesterday',
            'created_by' => $this->customer->id,
            'created_at' => now()->subDay(),
        ]);

        $healthyLastWeek = Ticket::factory()->create([
            'sla_status' => SlaStatus::WITHIN_SLA,
            'subject' => 'Healthy Ticket Last Week',
            'created_by' => $this->customer->id,
            'created_at' => now()->subDays(7),
        ]);

        $breachedLastMonth = Ticket::factory()->breached()->create([
            'subject' => 'Breached Ticket Last Month',
            'created_by' => $this->customer->id,
            'created_at' => now()->subMonth(),
        ]);

        // Filter: breached AND created within last 2 days
        $response = $this->actingAs($this->agent)->get(route('tickets.index', [
            'sla_status' => SlaStatus::BREACHED->value,
            'date_from' => now()->subDays(2)->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee($breachedYesterday->subject);
        $response->assertDontSee($healthyLastWeek->subject);
        $response->assertDontSee($breachedLastMonth->subject);
    }

    #[Test]
    public function customer_cannot_tamper_filters_to_view_other_users_tickets(): void
    {
        $otherCustomer = User::factory()->customer()->create(['name' => 'Jane Secret']);

        $customerTicket = Ticket::factory()->create([
            'created_by' => $this->customer->id,
            'subject' => 'My Allowed Personal Ticket',
        ]);

        $otherCustomerTicket = Ticket::factory()->create([
            'created_by' => $otherCustomer->id,
            'subject' => 'Jane Private Financial Ticket',
        ]);

        // Customer attempts to pass customer_id or created_by to view Jane's ticket
        $response = $this->actingAs($this->customer)->get(route('tickets.index', [
            'customer_id' => $otherCustomer->id,
            'created_by' => $otherCustomer->id,
        ]));

        $response->assertOk();
        $response->assertSee('My Allowed Personal Ticket');
        $response->assertDontSee('Jane Private Financial Ticket');
    }

    #[Test]
    public function search_returns_json_when_requested_with_query_params(): void
    {
        Ticket::factory()->create([
            'ticket_number' => 'TKT-2026-777777',
            'subject' => 'JSON API Search Ticket',
            'created_by' => $this->customer->id,
            'status' => TicketStatus::OPEN,
        ]);

        $response = $this->actingAs($this->agent)
            ->getJson(route('tickets.index', ['keyword' => 'JSON API']));

        $response->assertOk()
            ->assertJsonPath('current_tab', 'active')
            ->assertJsonPath('filters.keyword', 'JSON API')
            ->assertJsonCount(1, 'tickets.data')
            ->assertJsonPath('tickets.data.0.ticket_number', 'TKT-2026-777777');
    }

    #[Test]
    public function pagination_preserves_filter_query_strings(): void
    {
        Ticket::factory()->count(25)->create([
            'priority' => TicketPriority::CRITICAL,
            'status' => TicketStatus::OPEN,
            'created_by' => $this->customer->id,
        ]);

        $response = $this->actingAs($this->agent)->get(route('tickets.index', [
            'priority' => TicketPriority::CRITICAL->value,
            'page' => 1,
        ]));

        $response->assertOk();
        // Assert pagination links contain priority=critical
        $response->assertSee('priority=critical');
        $response->assertSee('page=2');
    }
}
