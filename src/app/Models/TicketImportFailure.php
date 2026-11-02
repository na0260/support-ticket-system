<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TicketImportFailure extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ticket_import_id',
        'row_number',
        'raw_data',
        'errors',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_import_id' => 'integer',
            'row_number' => 'integer',
            'raw_data' => 'array',
            'errors' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TicketImport, $this>
     */
    public function ticketImport(): BelongsTo
    {
        return $this->belongsTo(TicketImport::class, 'ticket_import_id');
    }
}
