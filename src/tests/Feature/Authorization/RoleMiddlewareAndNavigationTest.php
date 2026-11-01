<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RoleMiddlewareAndNavigationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unauthenticated_user_accessing_role_guarded_route_is_redirected_to_login(): void
    {
        $this->get(route('agent.unassigned'))->assertRedirect(route('login'));
        $this->get(route('admin.categories.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function customer_is_forbidden_from_agent_and_admin_routes(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->get(route('agent.unassigned'))
            ->assertForbidden();

        $this->actingAs($customer)
            ->get(route('agent.queue'))
            ->assertForbidden();

        $this->actingAs($customer)
            ->get(route('admin.categories.index'))
            ->assertForbidden();

        $this->actingAs($customer)
            ->get(route('admin.agents.workload'))
            ->assertForbidden();
    }

    #[Test]
    public function agent_can_access_agent_workspace_but_not_admin_console(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->get(route('agent.unassigned'))
            ->assertOk();

        $this->actingAs($agent)
            ->get(route('agent.queue'))
            ->assertOk();

        $this->actingAs($agent)
            ->get(route('admin.categories.index'))
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_access_both_agent_and_admin_workspaces(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('agent.unassigned'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.categories.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.agents.workload'))
            ->assertOk();
    }

    #[Test]
    public function customer_dashboard_renders_customer_specific_nav_links_and_badge(): void
    {
        $customer = User::factory()->customer()->create(['name' => 'Alice Customer']);

        $response = $this->actingAs($customer)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Alice Customer');
        $response->assertSee('[Customer]');
        $response->assertSee('My Tickets');
        $response->assertSee('Submit Ticket');

        // Must not leak agent/admin nav items
        $response->assertDontSee('Unassigned Tickets');
        $response->assertDontSee('My Queue');
        $response->assertDontSee('Ticket Center');
        $response->assertDontSee('Agent Workload');
    }

    #[Test]
    public function agent_dashboard_renders_agent_nav_links_and_badge(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Bob Agent']);

        $response = $this->actingAs($agent)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Bob Agent');
        $response->assertSee('[Support Agent]');
        $response->assertSee('All Tickets');
        $response->assertSee('Unassigned Tickets');
        $response->assertSee('My Queue');

        $response->assertDontSee('Agent Workload');
    }

    #[Test]
    public function admin_dashboard_renders_admin_nav_links_and_badge(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Carol Admin']);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Carol Admin');
        $response->assertSee('[Administrator]');
        $response->assertSee('Ticket Center');
        $response->assertSee('Categories');
        $response->assertSee('Agent Workload');
    }
}
