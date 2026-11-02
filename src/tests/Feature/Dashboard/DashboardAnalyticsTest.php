<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $admin;

    private User $customer;

    private TicketCategory $billingCat;

    private TicketCategory $techCat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = User::factory()->agent()->create(['name' => 'Agent Alex']);
        $this->admin = User::factory()->admin()->create(['name' => 'Admin Boss']);
        $this->customer = User::factory()->customer()->create(['name' => 'Customer Charlie']);
        $this->billingCat = TicketCategory::factory()->create(['name' => 'Billing']);
        $this->techCat = TicketCategory::factory()->create(['name' => 'Engineering']);
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function staff_dashboard_returns_correct_kpis_and_distributions(): void
    {
        // 1 Unassigned critical active ticket
        Ticket::factory()->create([
            'status' => TicketStatus::OPEN,
            'priority' => TicketPriority::CRITICAL,
            'assigned_agent_id' => null,
            'sla_status' => SlaStatus::WITHIN_SLA,
            'category_id' => $this->billingCat->id,
            'created_by' => $this->customer->id,
        ]);

        // 1 Breached active ticket assigned to agent
        Ticket::factory()->breached()->create([
            'status' => TicketStatus::IN_PROGRESS,
            'priority' => TicketPriority::HIGH,
            'assigned_agent_id' => $this->agent->id,
            'category_id' => $this->billingCat->id,
            'created_by' => $this->customer->id,
        ]);

        // 1 Approaching breach active ticket assigned to agent
        Ticket::factory()->create([
            'status' => TicketStatus::WAITING_CUSTOMER,
            'priority' => TicketPriority::MEDIUM,
            'assigned_agent_id' => $this->agent->id,
            'sla_due_at' => now()->addHour(),
            'sla_warning_at' => now()->subMinute(),
            'sla_status' => SlaStatus::APPROACHING_BREACH,
            'category_id' => $this->techCat->id,
            'created_by' => $this->customer->id,
        ]);

        // 1 Resolved today ticket
        Ticket::factory()->create([
            'status' => TicketStatus::RESOLVED,
            'priority' => TicketPriority::LOW,
            'assigned_agent_id' => $this->agent->id,
            'sla_status' => SlaStatus::WITHIN_SLA,
            'category_id' => $this->techCat->id,
            'created_by' => $this->customer->id,
            'resolved_at' => now(),
        ]);

        // 1 Closed ticket (not counted in active)
        Ticket::factory()->create([
            'status' => TicketStatus::CLOSED,
            'priority' => TicketPriority::LOW,
            'assigned_agent_id' => $this->agent->id,
            'sla_status' => SlaStatus::WITHIN_SLA,
            'category_id' => $this->techCat->id,
            'created_by' => $this->customer->id,
            'closed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->agent)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Support Operations Dashboard');
        $response->assertSee('Active Tickets');
        $response->assertSee('Team Capacity');

        // Verify JSON response metrics
        $jsonResponse = $this->actingAs($this->agent)->getJson(route('dashboard'));
        $jsonResponse->assertOk()
            ->assertJsonPath('is_staff', true)
            ->assertJsonPath('kpis.total_active', 4) // 3 active + 1 resolved
            ->assertJsonPath('kpis.unassigned', 1)
            ->assertJsonPath('kpis.critical', 1)
            ->assertJsonPath('kpis.breached', 1)
            ->assertJsonPath('kpis.approaching_breach', 1)
            ->assertJsonPath('kpis.total_resolved', 1)
            ->assertJsonPath('kpis.resolved_today', 1)
            ->assertJsonPath('priority_distribution.critical', 1)
            ->assertJsonPath('priority_distribution.high', 1)
            ->assertJsonPath('priority_distribution.medium', 1)
            ->assertJsonPath('priority_distribution.low', 1);
    }

    #[Test]
    public function customer_dashboard_strictly_isolates_metrics(): void
    {
        $otherCustomer = User::factory()->customer()->create(['name' => 'Other Customer']);

        // 2 tickets created by our customer
        Ticket::factory()->create([
            'created_by' => $this->customer->id,
            'status' => TicketStatus::OPEN,
            'subject' => 'My Personal Dashboard Ticket',
        ]);
        Ticket::factory()->create([
            'created_by' => $this->customer->id,
            'status' => TicketStatus::RESOLVED,
            'subject' => 'My Resolved Ticket',
        ]);

        // 10 tickets created by other customer
        Ticket::factory()->count(10)->create([
            'created_by' => $otherCustomer->id,
            'status' => TicketStatus::OPEN,
            'subject' => 'Secret Other Customer Ticket',
        ]);

        $response = $this->actingAs($this->customer)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Customer Support Dashboard');
        $response->assertSee('My Personal Dashboard Ticket');
        $response->assertDontSee('Secret Other Customer Ticket');

        // Customer JSON API test
        $jsonResponse = $this->actingAs($this->customer)->getJson(route('dashboard'));
        $jsonResponse->assertOk()
            ->assertJsonPath('is_staff', false)
            ->assertJsonPath('kpis.total_active', 2) // open + resolved (not closed)
            ->assertJsonPath('kpis.open_count', 1)
            ->assertJsonPath('kpis.resolved_count', 1)
            ->assertJsonPath('kpis.total_tickets', 2);
    }

    #[Test]
    public function dashboard_kpis_execute_without_n_plus_one_queries(): void
    {
        // Create multiple agents, categories, and tickets
        $agents = User::factory()->agent()->count(5)->create();
        $categories = TicketCategory::factory()->count(4)->create();

        for ($i = 0; $i < 20; $i++) {
            Ticket::factory()->create([
                'category_id' => $categories->random()->id,
                'assigned_agent_id' => $i % 2 === 0 ? $agents->random()->id : null,
                'status' => TicketStatus::cases()[array_rand(TicketStatus::cases())],
                'priority' => TicketPriority::cases()[array_rand(TicketPriority::cases())],
                'created_by' => $this->customer->id,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->agent)->get(route('dashboard'));
        $response->assertOk();

        $queries = DB::getQueryLog();
        // The dashboard should use a small, bounded number of queries (<= 10 queries total: session, user, kpi aggregation, priority group, category group, agent workload withCount, recent tickets with relations)
        $this->assertLessThanOrEqual(10, count($queries), 'Dashboard query count exceeds optimal bounds - potential N+1 query regression');
    }
}
