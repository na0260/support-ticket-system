<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string $disk
 * @property string $file_path
 * @property string $file_name
 * @property string $mime_type
 * @property int $file_size Size in bytes.
 * @property int $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ticket|TicketMessage $attachable
 * @property-read User $uploader
 */
#[Fillable([
    'disk',
    'file_path',
    'file_name',
    'mime_type',
    'file_size',
    'uploaded_by',
])]
class TicketAttachment extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'disk' => 'private',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachable_id' => 'integer',
            'file_size' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
