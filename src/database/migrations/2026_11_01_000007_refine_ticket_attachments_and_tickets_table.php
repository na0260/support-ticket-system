<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. ticket_attachments.uploaded_by -> nullable with onDelete('set null')
        Schema::table('ticket_attachments', function (Blueprint $table): void {
            if (DB::getDriverName() === 'mysql') {
                $table->dropForeign(['uploaded_by']);
            }
        });

        Schema::table('ticket_attachments', function (Blueprint $table): void {
            $table->foreignId('uploaded_by')
                ->nullable()
                ->change()
                ->constrained('users')
                ->nullOnDelete();
        });

        // 2. tickets date columns -> dateTime
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dateTime('sla_due_at')->change();
            $table->dateTime('sla_warning_at')->nullable()->change();
            $table->dateTime('escalated_at')->nullable()->change();
            $table->dateTime('resolved_at')->nullable()->change();
            $table->dateTime('closed_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->timestamp('sla_due_at')->change();
            $table->timestamp('sla_warning_at')->nullable()->change();
            $table->timestamp('escalated_at')->nullable()->change();
            $table->timestamp('resolved_at')->nullable()->change();
            $table->timestamp('closed_at')->nullable()->change();
        });

        Schema::table('ticket_attachments', function (Blueprint $table): void {
            if (DB::getDriverName() === 'mysql') {
                $table->dropForeign(['uploaded_by']);
            }
        });

        Schema::table('ticket_attachments', function (Blueprint $table): void {
            $table->foreignId('uploaded_by')
                ->nullable(false)
                ->change()
                ->constrained('users');
        });
    }
};
