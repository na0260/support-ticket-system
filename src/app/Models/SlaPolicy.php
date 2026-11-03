<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketPriority;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Dynamic SLA policy configuration per priority.
 *
 * @property int $id
 * @property TicketPriority $priority
 * @property int $resolution_time_minutes
 * @property float $warning_threshold_percentage
 * @property bool $business_hours_only
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'priority',
    'resolution_time_minutes',
    'warning_threshold_percentage',
    'business_hours_only',
])]
class SlaPolicy extends Model
{
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'warning_threshold_percentage' => 0.80,
        'business_hours_only' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'resolution_time_minutes' => 'integer',
            'warning_threshold_percentage' => 'float',
            'business_hours_only' => 'boolean',
        ];
    }

    /**
     * Find the SLA policy for a specific priority, or create a default instance.
     */
    public static function forPriority(TicketPriority $priority): self
    {
        /** @var self|null $policy */
        $policy = static::query()->where('priority', $priority->value)->first();

        if ($policy !== null) {
            return $policy;
        }

        // Fallback default policies
        $defaultMinutes = match ($priority) {
            TicketPriority::LOW => 48 * 60,
            TicketPriority::MEDIUM => 24 * 60,
            TicketPriority::HIGH => 8 * 60,
            TicketPriority::CRITICAL => 2 * 60,
        };

        return new static([
            'priority' => $priority,
            'resolution_time_minutes' => $defaultMinutes,
            'warning_threshold_percentage' => 0.80,
            'business_hours_only' => true,
        ]);
    }
}
