<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AgentWorkloadController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Agent\AgentQueueController;
use App\Http\Controllers\Agent\UnassignedTicketController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketImportController;
use App\Http\Controllers\TicketMessageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Tickets Resource & Lifecycle Actions
    Route::get('/tickets', [TicketController::class, 'index'])
        ->middleware('can:viewAny,App\Models\Ticket')
        ->name('tickets.index');

    Route::get('/tickets/create', [TicketController::class, 'create'])
        ->middleware('can:create,App\Models\Ticket')
        ->name('tickets.create');

    Route::post('/tickets', [TicketController::class, 'store'])
        ->middleware('can:create,App\Models\Ticket')
        ->name('tickets.store');

    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
        ->middleware('can:view,ticket')
        ->name('tickets.show');

    Route::get('/tickets/{ticket}/edit', [TicketController::class, 'edit'])
        ->middleware('can:update,ticket')
        ->name('tickets.edit');

    Route::match(['put', 'patch'], '/tickets/{ticket}', [TicketController::class, 'update'])
        ->name('tickets.update');

    Route::patch('/tickets/{ticket}/priority', [TicketController::class, 'updatePriority'])
        ->name('tickets.update_priority');

    Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])
        ->middleware('can:delete,ticket')
        ->name('tickets.destroy');

    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])
        ->middleware('can:assign,ticket')
        ->name('tickets.assign');

    Route::match(['post', 'patch'], '/tickets/{ticket}/reassign', [TicketController::class, 'assign'])
        ->middleware('can:assign,ticket')
        ->name('tickets.reassign');

    Route::post('/tickets/{ticket}/auto-assign', [TicketController::class, 'autoAssign'])
        ->middleware('can:autoAssign,ticket')
        ->name('tickets.auto_assign');

    Route::post('/tickets/{ticket}/claim', [TicketController::class, 'claim'])
        ->middleware('can:claim,ticket')
        ->name('tickets.claim');

    Route::post('/tickets/{ticket}/resolve', [TicketController::class, 'resolve'])
        ->middleware('can:resolve,ticket')
        ->name('tickets.resolve');

    Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])
        ->middleware('can:close,ticket')
        ->name('tickets.close');

    // Ticket Messages & Internal Notes
    Route::get('/messages/{message}', [TicketMessageController::class, 'show'])
        ->middleware('can:view,message')
        ->name('ticket_messages.show');

    Route::post('/tickets/{ticket}/messages', [TicketMessageController::class, 'store'])
        ->middleware('can:create,App\Models\TicketMessage,ticket')
        ->name('ticket_messages.store');

    Route::post('/tickets/{ticket}/internal-notes', [TicketMessageController::class, 'storeInternalNote'])
        ->middleware('can:createInternalNote,App\Models\TicketMessage,ticket')
        ->name('ticket_messages.internal_note');

    // Attachments Download
    Route::get('/attachments/{attachment}/download', [TicketAttachmentController::class, 'download'])
        ->middleware('can:download,attachment')
        ->name('ticket_attachments.download');

    Route::get('/tickets/attachments/{attachment}/download', [TicketAttachmentController::class, 'download'])
        ->middleware('can:download,attachment')
        ->name('attachments.download');

    // CSV Bulk Ticket Import (Staff Only: Agent & Admin)
    Route::middleware('role:agent,admin')->group(function (): void {
        Route::get('/imports', [TicketImportController::class, 'index'])->name('imports.index');
        Route::post('/imports', [TicketImportController::class, 'store'])->name('imports.store');
        Route::post('/tickets/import', [TicketImportController::class, 'store'])->name('tickets.import');
        Route::get('/imports/sample', [TicketImportController::class, 'downloadSample'])->name('imports.sample');
        Route::get('/imports/{import}', [TicketImportController::class, 'show'])->name('imports.show');
        Route::get('/imports/{import}/failures', [TicketImportController::class, 'downloadFailures'])->name('imports.failures');
    });

    // Role-Guarded Staff Workspace: Agent & Admin
    Route::middleware('role:agent,admin')->prefix('agent')->name('agent.')->group(function (): void {
        Route::get('/unassigned', [UnassignedTicketController::class, 'index'])
            ->name('unassigned');
        Route::get('/queue', [AgentQueueController::class, 'index'])
            ->name('queue');
    });

    // Role-Guarded Admin Console: Admin Only
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/categories', [CategoryController::class, 'index'])
            ->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])
            ->name('categories.store');
        Route::match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update'])
            ->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
            ->name('categories.destroy');

        Route::get('/agents/workload', [AgentWorkloadController::class, 'index'])
            ->name('agents.workload');
        Route::post('/agents/{agent}/toggle-availability', [AgentWorkloadController::class, 'toggleAvailability'])
            ->name('agents.toggle_availability');
    });
});

require __DIR__.'/auth.php';
