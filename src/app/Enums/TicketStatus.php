<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketStatus: string
{
    case OPEN = 'open';
    case IN_PROGRESS = 'in_progress';
    case WAITING_CUSTOMER = 'waiting_customer';
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Statuses in which work is still outstanding.
     *
     * @return list<self>
     */
    public static function activeCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $status): bool => $status->isActive()));
    }

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::IN_PROGRESS => 'In Progress',
            self::WAITING_CUSTOMER => 'Waiting on Customer',
            self::RESOLVED => 'Resolved',
            self::CLOSED => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OPEN => 'blue',
            self::IN_PROGRESS => 'indigo',
            self::WAITING_CUSTOMER => 'amber',
            self::RESOLVED => 'green',
            self::CLOSED => 'gray',
        };
    }

    /**
     * The ticket still requires work (SLA clock relevant).
     */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Work on the ticket has finished permanently (closed only).
     */
    public function isTerminal(): bool
    {
        return $this === self::CLOSED;
    }

    /**
     * Whether the ticket has been resolved (can still be closed or reopened).
     */
    public function isResolved(): bool
    {
        return $this === self::RESOLVED;
    }
}
