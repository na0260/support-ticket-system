<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ticket_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('successful_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('ticket_import_failures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_import_id')->constrained('ticket_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_data');
            $table->json('errors');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_import_id', 'row_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_import_failures');
        Schema::dropIfExists('ticket_imports');
    }
};
