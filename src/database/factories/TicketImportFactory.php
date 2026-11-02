<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketImport>
 */
class TicketImportFactory extends Factory
{
    protected $model = TicketImport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->agent(),
            'file_name' => 'tickets_batch_'.fake()->lexify('????').'.csv',
            'file_path' => 'imports/'.fake()->uuid().'.csv',
            'status' => TicketImport::STATUS_PENDING,
            'total_rows' => 0,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
            'completed_at' => null,
        ];
    }
}
