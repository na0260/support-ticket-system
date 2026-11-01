<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * `role` and `is_available` are intentionally NOT mass assignable to prevent
 * privilege escalation through request payloads.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserRole $role
 * @property bool $is_available
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Ticket> $assignedTickets
 * @property-read Collection<int, Ticket> $createdTickets
 * @property-read Collection<int, TicketMessage> $ticketMessages
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * Mirrors database defaults so new instances are fully hydrated.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::CUSTOMER->value,
        'is_available' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_available' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_agent_id');
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function createdTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'created_by');
    }

    /**
     * @return HasMany<TicketMessage, $this>
     */
    public function ticketMessages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeAgents(Builder $query): void
    {
        $query->where('role', UserRole::AGENT->value);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeAvailableAgents(Builder $query): void
    {
        // Column order matches the (role, is_available) composite index.
        $query->where('role', UserRole::AGENT->value)
            ->where('is_available', true);
    }
}
