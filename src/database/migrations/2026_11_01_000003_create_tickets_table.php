<?php

declare(strict_types=1);

use App\Enums\SlaStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_number', 32)->unique();
            $table->string('subject', 255);
            $table->longText('description');
            $table->string('priority', 20);
            $table->foreignId('category_id')->constrained('ticket_categories');
            $table->string('status', 30);
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamp('sla_due_at')->index();
            $table->timestamp('sla_warning_at')->nullable()->index();
            $table->string('sla_status', 30)->default(SlaStatus::WITHIN_SLA->value);
            $table->timestamp('escalated_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Composite indexes (left-most columns also serve single-column lookups:
            // status, assigned_agent_id, sla_status).
            $table->index(['status', 'priority'], 'tickets_status_priority_index');
            $table->index(['assigned_agent_id', 'status'], 'tickets_agent_status_index');
            $table->index(['sla_status', 'sla_due_at'], 'tickets_sla_status_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
