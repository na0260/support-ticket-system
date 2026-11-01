<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case AGENT = 'agent';
    case CUSTOMER = 'customer';

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
            self::ADMIN => 'Administrator',
            self::AGENT => 'Support Agent',
            self::CUSTOMER => 'Customer',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ADMIN => 'purple',
            self::AGENT => 'blue',
            self::CUSTOMER => 'gray',
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::ADMIN;
    }

    public function isAgent(): bool
    {
        return $this === self::AGENT;
    }

    public function isCustomer(): bool
    {
        return $this === self::CUSTOMER;
    }

    /**
     * Staff members (admins and agents) can work on tickets.
     */
    public function isStaff(): bool
    {
        return $this === self::ADMIN || $this === self::AGENT;
    }
}
