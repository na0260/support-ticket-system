<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            if (! Schema::hasColumn('tickets', 'is_escalated')) {
                $table->boolean('is_escalated')->default(false)->after('sla_status')->index();
            }

            // High-traffic composite index for customer ticket querying by status
            $table->index(['created_by', 'status'], 'tickets_created_by_status_index');
        });

        Schema::table('ticket_messages', function (Blueprint $table): void {
            // High-traffic composite index for filtering internal notes
            $table->index(['ticket_id', 'is_internal'], 'ticket_messages_ticket_internal_index');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->dropIndex('ticket_messages_ticket_internal_index');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_created_by_status_index');
            if (Schema::hasColumn('tickets', 'is_escalated')) {
                $table->dropColumn('is_escalated');
            }
        });
    }
};
