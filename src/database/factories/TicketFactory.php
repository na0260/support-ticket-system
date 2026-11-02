<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Sla\SlaEvaluationService;
use App\Services\TicketNumberGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * SLA dates are computed by the real business-hours engine after making,
 * unless explicitly provided (e.g. via the breached() state).
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_number' => fn (): string => app(TicketNumberGenerator::class)->generate(),
            'subject' => fake()->sentence(6),
            'description' => fake()->paragraphs(3, true),
            'priority' => fake()->randomElement(TicketPriority::cases()),
            'category_id' => TicketCategory::factory(),
            'status' => TicketStatus::OPEN,
            'assigned_agent_id' => null,
            'created_by' => User::factory()->customer(),
            'sla_status' => SlaStatus::WITHIN_SLA,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(static function (Ticket $ticket): void {
            if (($ticket->getAttributes()['sla_due_at'] ?? null) === null) {
                app(SlaEvaluationService::class)->applySla($ticket);
            }
        });
    }

    public function priority(TicketPriority $priority): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => $priority,
        ]);
    }

    public function assignedTo(User $agent): static
    {
        return $this->state(fn (array $attributes): array => [
            'assigned_agent_id' => $agent->id,
            'status' => TicketStatus::IN_PROGRESS,
        ]);
    }

    public function breached(): static
    {
        return $this->state(fn (array $attributes): array => [
            'sla_due_at' => Date::now()->subHour(),
            'sla_warning_at' => Date::now()->subHours(2),
            'sla_status' => SlaStatus::BREACHED,
        ]);
    }
}
