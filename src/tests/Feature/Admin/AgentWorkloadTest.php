<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AgentWorkloadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin Boss']);
        $this->agent = User::factory()->agent()->create(['name' => 'Agent Alex', 'is_available' => true]);
        $this->customer = User::factory()->customer()->create(['name' => 'Customer Charlie']);
    }

    #[Test]
    public function non_admins_are_forbidden_from_agent_workload(): void
    {
        $this->actingAs($this->agent)->get(route('admin.agents.workload'))->assertForbidden();
        $this->actingAs($this->customer)->get(route('admin.agents.workload'))->assertForbidden();

        $this->actingAs($this->agent)->post(route('admin.agents.toggle_availability', $this->agent))->assertForbidden();
        $this->actingAs($this->customer)->post(route('admin.agents.toggle_availability', $this->agent))->assertForbidden();
    }

    #[Test]
    public function admin_can_view_workload_metrics_and_agent_capacity(): void
    {
        // 1 critical active ticket for Agent Alex
        Ticket::factory()->create([
            'assigned_agent_id' => $this->agent->id,
            'status' => TicketStatus::IN_PROGRESS,
            'priority' => TicketPriority::CRITICAL,
            'sla_status' => SlaStatus::WITHIN_SLA,
            'created_by' => $this->customer->id,
        ]);

        // 1 approaching breach active ticket for Agent Alex
        Ticket::factory()->create([
            'assigned_agent_id' => $this->agent->id,
            'status' => TicketStatus::OPEN,
            'priority' => TicketPriority::HIGH,
            'sla_due_at' => now()->addMinutes(30),
            'sla_status' => SlaStatus::APPROACHING_BREACH,
            'created_by' => $this->customer->id,
        ]);

        // 1 resolved ticket for Agent Alex
        Ticket::factory()->create([
            'assigned_agent_id' => $this->agent->id,
            'status' => TicketStatus::RESOLVED,
            'priority' => TicketPriority::LOW,
            'created_by' => $this->customer->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.agents.workload'));

        $response->assertOk();
        $response->assertSee('Agent Workload Dashboard');
        $response->assertSee('Agent Alex');
        $response->assertSee('Available');

        // Test JSON endpoint
        $jsonResponse = $this->actingAs($this->admin)->getJson(route('admin.agents.workload'));
        $jsonResponse->assertOk()
            ->assertJsonPath('metrics.total_agents', 1)
            ->assertJsonPath('metrics.available_agents', 1)
            ->assertJsonPath('metrics.total_active_tickets', 3)
            ->assertJsonPath('agents.0.active_tickets_count', 3)
            ->assertJsonPath('agents.0.critical_tickets_count', 1)
            ->assertJsonPath('agents.0.at_risk_tickets_count', 1)
            ->assertJsonPath('agents.0.resolved_tickets_count', 1);
    }

    #[Test]
    public function admin_can_toggle_agent_availability(): void
    {
        $this->assertTrue($this->agent->is_available);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.agents.workload'))
            ->post(route('admin.agents.toggle_availability', $this->agent));

        $response->assertRedirect(route('admin.agents.workload'));
        $this->agent->refresh();
        $this->assertFalse($this->agent->is_available);

        // Toggle back to available
        $this->actingAs($this->admin)->post(route('admin.agents.toggle_availability', $this->agent));
        $this->agent->refresh();
        $this->assertTrue($this->agent->is_available);
    }
}
