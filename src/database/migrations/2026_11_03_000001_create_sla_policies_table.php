<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('priority', 20)->unique();
            $table->unsignedInteger('resolution_time_minutes');
            $table->decimal('warning_threshold_percentage', 5, 2)->default(0.80);
            $table->boolean('business_hours_only')->default(true);
            $table->timestamps();
        });

        // Seed default SLA targets
        $defaults = [
            [
                'priority' => TicketPriority::LOW->value,
                'resolution_time_minutes' => 48 * 60, // 48 Hours = 2880 mins
                'warning_threshold_percentage' => 0.80,
                'business_hours_only' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'priority' => TicketPriority::MEDIUM->value,
                'resolution_time_minutes' => 24 * 60, // 24 Hours = 1440 mins
                'warning_threshold_percentage' => 0.80,
                'business_hours_only' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'priority' => TicketPriority::HIGH->value,
                'resolution_time_minutes' => 8 * 60, // 8 Hours = 480 mins
                'warning_threshold_percentage' => 0.80,
                'business_hours_only' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'priority' => TicketPriority::CRITICAL->value,
                'resolution_time_minutes' => 2 * 60, // 2 Hours = 120 mins
                'warning_threshold_percentage' => 0.80,
                'business_hours_only' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('sla_policies')->insert($defaults);

        // Add index on tickets (status, sla_due_at) for fast SLA filtering and dashboard metrics
        Schema::table('tickets', function (Blueprint $table): void {
            $table->index(['status', 'sla_due_at'], 'tickets_status_sla_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_status_sla_due_index');
        });

        Schema::dropIfExists('sla_policies');
    }
};
