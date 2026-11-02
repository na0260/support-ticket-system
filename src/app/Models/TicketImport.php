<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TicketImport extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'file_name',
        'file_path',
        'status',
        'total_rows',
        'processed_rows',
        'successful_rows',
        'failed_rows',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'successful_rows' => 'integer',
            'failed_rows' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TicketImportFailure, $this>
     */
    public function failures(): HasMany
    {
        return $this->hasMany(TicketImportFailure::class);
    }

    /**
     * Scope query to recent imports.
     *
     * @param  Builder<TicketImport>  $query
     * @return Builder<TicketImport>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->latest('id');
    }

    /**
     * Scope query to pending or currently processing imports.
     *
     * @param  Builder<TicketImport>  $query
     * @return Builder<TicketImport>
     */
    public function scopePendingOrProcessing(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function hasFailures(): bool
    {
        return $this->failed_rows > 0;
    }

    public function progressPercentage(): int
    {
        if ($this->total_rows <= 0) {
            return $this->isCompleted() ? 100 : 0;
        }

        return min(100, (int) round(($this->processed_rows / $this->total_rows) * 100));
    }
}
