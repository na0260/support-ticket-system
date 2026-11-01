<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\TicketNumberGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $priority = fake()->randomElement(TicketPriority::cases());
        $slaMinutes = $priority->slaHours() * 60;
        $now = Date::now();

        return [
            'ticket_number' => fn (): string => app(TicketNumberGenerator::class)->generate(),
            'subject' => fake()->sentence(6),
            'description' => fake()->paragraphs(3, true),
            'priority' => $priority,
            'category_id' => TicketCategory::factory(),
            'status' => TicketStatus::OPEN,
            'assigned_agent_id' => null,
            'created_by' => User::factory()->customer(),
            'sla_due_at' => $now->copy()->addMinutes($slaMinutes),
            'sla_warning_at' => $now->copy()->addMinutes((int) round($slaMinutes * 0.75)),
            'sla_status' => SlaStatus::WITHIN_SLA,
        ];
    }

    public function priority(TicketPriority $priority): static
    {
        return $this->state(function (array $attributes) use ($priority): array {
            $slaMinutes = $priority->slaHours() * 60;
            $now = Date::now();

            return [
                'priority' => $priority,
                'sla_due_at' => $now->copy()->addMinutes($slaMinutes),
                'sla_warning_at' => $now->copy()->addMinutes((int) round($slaMinutes * 0.75)),
            ];
        });
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
