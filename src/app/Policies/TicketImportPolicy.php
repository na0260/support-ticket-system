<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TicketImport;
use App\Models\User;

final class TicketImportPolicy
{
    /**
     * Determine whether the user can view any imports.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->isStaff();
    }

    /**
     * Determine whether the user can view a specific import.
     */
    public function view(User $user, TicketImport $import): bool
    {
        return $user->role->isStaff();
    }

    /**
     * Determine whether the user can create/upload imports.
     */
    public function create(User $user): bool
    {
        return $user->role->isStaff();
    }

    /**
     * Determine whether the user can download failures for an import.
     */
    public function downloadFailures(User $user, TicketImport $import): bool
    {
        return $user->role->isStaff();
    }
}
