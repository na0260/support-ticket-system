<?php

declare(strict_types=1);

namespace App\Enums;

enum SlaStatus: string
{
    case WITHIN_SLA = 'within_sla';
    case APPROACHING_BREACH = 'approaching_breach';
    case BREACHED = 'breached';

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
            self::WITHIN_SLA => 'Within SLA',
            self::APPROACHING_BREACH => 'Approaching Breach',
            self::BREACHED => 'Breached',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::WITHIN_SLA => 'green',
            self::APPROACHING_BREACH => 'amber',
            self::BREACHED => 'red',
        };
    }

    public function isBreached(): bool
    {
        return $this === self::BREACHED;
    }
}
