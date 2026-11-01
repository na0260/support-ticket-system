<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->longText('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            // Serves: thread listing per ticket, public/internal filtering, chronological order.
            $table->index(['ticket_id', 'is_internal', 'created_at'], 'ticket_messages_thread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
    }
};
