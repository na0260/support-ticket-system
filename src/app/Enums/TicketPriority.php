<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketPriority: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Low',
            self::MEDIUM => 'Medium',
            self::HIGH => 'High',
            self::CRITICAL => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::LOW => 'gray',
            self::MEDIUM => 'blue',
            self::HIGH => 'amber',
            self::CRITICAL => 'red',
        };
    }

    /**
     * Resolution SLA target in hours.
     */
    public function slaHours(): int
    {
        return match ($this) {
            self::LOW => 48,
            self::MEDIUM => 24,
            self::HIGH => 8,
            self::CRITICAL => 2,
        };
    }

    /**
     * Numeric weight for ordering (higher = more urgent).
     */
    public function weight(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::CRITICAL => 4,
        };
    }
}
