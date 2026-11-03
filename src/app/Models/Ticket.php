<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Services\Sla\BusinessHoursCalculator;
use App\Services\Sla\SlaEvaluationService;
use Carbon\CarbonInterface;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

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
 * @property-read string $sla_state
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

    public function isResolved(): bool
    {
        return $this->status->isResolved();
    }

    /**
     * Live SLA evaluation (independent of the persisted sla_status column).
     */
    public function isSlaBreached(?CarbonInterface $now = null): bool
    {
        return app(SlaEvaluationService::class)->evaluate($this, $now) === SlaStatus::BREACHED;
    }

    public function isSlaApproachingBreach(?CarbonInterface $now = null): bool
    {
        return app(SlaEvaluationService::class)->evaluate($this, $now) === SlaStatus::APPROACHING_BREACH;
    }

    /**
     * Dynamic SLA state evaluation against the current (or given) instant.
     * Evaluates:
     * - 'within_sla': Current time < warning threshold.
     * - 'approaching_breach': Warning threshold <= Current time < sla_due_at.
     * - 'sla_breached': Current time >= sla_due_at and ticket is not resolved/closed.
     */
    public function getSlaState(?CarbonInterface $now = null): string
    {
        $currentTime = $now ? Carbon::instance($now) : Carbon::now();

        if ($this->isResolved() || $this->status->isTerminal()) {
            $completedAt = $this->resolved_at ?? $this->closed_at;
            if ($completedAt !== null && $completedAt->isAfter($this->sla_due_at)) {
                return 'sla_breached';
            }

            return 'within_sla';
        }

        if ($currentTime->greaterThanOrEqualTo($this->sla_due_at)) {
            return 'sla_breached';
        }

        if ($this->sla_warning_at !== null && $currentTime->greaterThanOrEqualTo($this->sla_warning_at)) {
            return 'approaching_breach';
        }

        return 'within_sla';
    }

    public function getSlaStateAttribute(): string
    {
        return $this->getSlaState();
    }

    /**
     * Business minutes left until sla_due_at. Zero once breached or when the
     * SLA clock has stopped (resolved / closed).
     */
    public function remainingBusinessMinutes(?CarbonInterface $now = null): int
    {
        if ($this->isResolved() || $this->status->isTerminal()) {
            return 0;
        }

        return app(BusinessHoursCalculator::class)->businessMinutesBetween($now ?? Date::now(), $this->sla_due_at);
    }

    /**
     * Data isolation scope: restricts ticket visibility based on user role.
     * Customers see only their own tickets; Agents and Admins see all.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->role->isCustomer()) {
            $query->where('created_by', $user->id);
        }
    }

    /**
     * Scope tickets that are actively in flight (non-terminal, i.e. status != 'closed').
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', TicketStatus::CLOSED->value);
    }

    /**
     * Scope tickets that are either resolved or closed.
     *
     * @param  Builder<self>  $query
     */
    public function scopeResolvedOrClosed(Builder $query): void
    {
        $query->whereIn('status', [
            TicketStatus::RESOLVED->value,
            TicketStatus::CLOSED->value,
        ]);
    }

    /**
     * Scope active tickets that have no assigned agent.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull('assigned_agent_id')
            ->where('status', '!=', TicketStatus::CLOSED->value);
    }

    /**
     * Scope tickets assigned to a specific agent.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAssignedTo(Builder $query, User $agent): void
    {
        $query->where('assigned_agent_id', $agent->id);
    }
}
