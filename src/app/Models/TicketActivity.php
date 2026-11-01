<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit trail entry for a ticket.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $causer_id
 * @property string $event
 * @property array<string, mixed>|null $properties
 * @property Carbon $created_at
 * @property-read Ticket $ticket
 * @property-read User|null $causer
 */
#[Fillable(['ticket_id', 'causer_id', 'event', 'properties'])]
class TicketActivity extends Model
{
    /**
     * Audit entries are immutable; there is no updated_at column.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }
}
