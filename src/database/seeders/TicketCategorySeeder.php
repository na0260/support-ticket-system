<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TicketCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TicketCategorySeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const array CATEGORIES = [
        'Technical Support',
        'Billing & Invoicing',
        'Account Access',
        'Feature Request',
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $name) {
            TicketCategory::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true],
            );
        }
    }
}
