<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ticket_number
 * @property string $subject
 * @property string $description
 * @property TicketPriority $priority
 * @property int $category_id
 * @property TicketStatus $status
 * @property int|null $assigned_agent_id
 * @property int $created_by
 * @property Carbon $sla_due_at
 * @property Carbon|null $sla_warning_at
 * @property SlaStatus $sla_status
 * @property Carbon|null $escalated_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read TicketCategory $category
 * @property-read User|null $assignedAgent
 * @property-read User $creator
 * @property-read Collection<int, TicketMessage> $messages
 * @property-read Collection<int, TicketAttachment> $attachments
 * @property-read Collection<int, TicketActivity> $activities
 */
#[Fillable([
    'ticket_number',
    'subject',
    'description',
    'priority',
    'category_id',
    'status',
    'assigned_agent_id',
    'created_by',
    'sla_due_at',
    'sla_warning_at',
    'sla_status',
    'escalated_at',
    'resolved_at',
    'closed_at',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => TicketStatus::OPEN->value,
        'sla_status' => SlaStatus::WITHIN_SLA->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'sla_status' => SlaStatus::class,
            'sla_due_at' => 'datetime',
            'sla_warning_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TicketCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /**
     * Attachments uploaded directly against the ticket (not via a message).
     *
     * @return MorphMany<TicketAttachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(TicketAttachment::class, 'attachable');
    }

    /**
     * @return HasMany<TicketActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class);
    }
}
