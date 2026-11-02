<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->agent = User::factory()->agent()->create();
        $this->customer = User::factory()->customer()->create();
    }

    #[Test]
    public function non_admins_are_forbidden_from_category_management(): void
    {
        // Agent access
        $this->actingAs($this->agent)->get(route('admin.categories.index'))->assertForbidden();
        $this->actingAs($this->agent)->post(route('admin.categories.store'), ['name' => 'Forbidden Cat'])->assertForbidden();

        // Customer access
        $this->actingAs($this->customer)->get(route('admin.categories.index'))->assertForbidden();
        $this->actingAs($this->customer)->post(route('admin.categories.store'), ['name' => 'Forbidden Cat'])->assertForbidden();
    }

    #[Test]
    public function admin_can_view_categories_with_ticket_counts(): void
    {
        $cat = TicketCategory::factory()->create(['name' => 'Server Outages']);
        Ticket::factory()->count(3)->create(['category_id' => $cat->id]);

        $response = $this->actingAs($this->admin)->get(route('admin.categories.index'));

        $response->assertOk();
        $response->assertSee('Server Outages');
        $response->assertSee('3 tickets');
    }

    #[Test]
    public function admin_can_create_new_category_with_auto_slug(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.categories.store'), [
            'name' => 'Enterprise Hardware Support',
        ]);

        $response->assertRedirect(route('admin.categories.index'));
        $this->assertDatabaseHas('ticket_categories', [
            'name' => 'Enterprise Hardware Support',
            'slug' => 'enterprise-hardware-support',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function category_creation_requires_valid_unique_name(): void
    {
        TicketCategory::factory()->create(['name' => 'Existing Category']);

        $response = $this->actingAs($this->admin)->post(route('admin.categories.store'), [
            'name' => 'Existing Category',
        ]);

        $response->assertSessionHasErrors(['name']);
    }

    #[Test]
    public function admin_can_toggle_category_active_status(): void
    {
        $cat = TicketCategory::factory()->create(['name' => 'Legacy Software', 'is_active' => true]);

        $response = $this->actingAs($this->admin)->patch(route('admin.categories.update', $cat), [
            'toggle_status' => 1,
        ]);

        $response->assertRedirect(route('admin.categories.index'));
        $cat->refresh();
        $this->assertFalse($cat->is_active);

        // Toggle back to active
        $this->actingAs($this->admin)->patch(route('admin.categories.update', $cat), [
            'toggle_status' => 1,
        ]);
        $cat->refresh();
        $this->assertTrue($cat->is_active);
    }

    #[Test]
    public function category_with_associated_tickets_is_deactivated_instead_of_deleted(): void
    {
        $cat = TicketCategory::factory()->create(['name' => 'Active Queue Category', 'is_active' => true]);
        Ticket::factory()->create(['category_id' => $cat->id]);

        $response = $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $cat));

        $response->assertRedirect(route('admin.categories.index'));
        $this->assertDatabaseHas('ticket_categories', [
            'id' => $cat->id,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function empty_category_can_be_permanently_deleted(): void
    {
        $cat = TicketCategory::factory()->create(['name' => 'Empty Category']);

        $response = $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $cat));

        $response->assertRedirect(route('admin.categories.index'));
        $this->assertDatabaseMissing('ticket_categories', [
            'id' => $cat->id,
        ]);
    }
}
