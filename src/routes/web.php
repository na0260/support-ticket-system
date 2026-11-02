<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketImportController;
use App\Http\Controllers\TicketMessageController;
use Illuminate\Http\JsonResponse;
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
        ->middleware('can:update,ticket')
        ->name('tickets.update');

    Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])
        ->middleware('can:delete,ticket')
        ->name('tickets.destroy');

    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])
        ->middleware('can:assign,ticket')
        ->name('tickets.assign');

    Route::post('/tickets/{ticket}/auto-assign', [TicketController::class, 'autoAssign'])
        ->middleware('can:assign,ticket')
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

    // CSV Bulk Ticket Import (Staff Only: Agent & Admin)
    Route::middleware('role:agent,admin')->group(function (): void {
        Route::get('/imports', [TicketImportController::class, 'index'])->name('imports.index');
        Route::post('/imports', [TicketImportController::class, 'store'])->name('imports.store');
        Route::get('/imports/sample', [TicketImportController::class, 'downloadSample'])->name('imports.sample');
        Route::get('/imports/{import}', [TicketImportController::class, 'show'])->name('imports.show');
        Route::get('/imports/{import}/failures', [TicketImportController::class, 'downloadFailures'])->name('imports.failures');
    });

    // Role-Guarded Staff Workspace: Agent & Admin
    Route::middleware('role:agent,admin')->prefix('agent')->name('agent.')->group(function (): void {
        Route::get('/unassigned', fn (): JsonResponse => response()->json(['message' => 'agent.unassigned stub']))
            ->name('unassigned');
        Route::get('/queue', fn (): JsonResponse => response()->json(['message' => 'agent.queue stub']))
            ->name('queue');
    });

    // Role-Guarded Admin Console: Admin Only
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/categories', fn (): JsonResponse => response()->json(['message' => 'admin.categories stub']))
            ->name('categories.index');
        Route::get('/agents/workload', fn (): JsonResponse => response()->json(['message' => 'admin.workload stub']))
            ->name('agents.workload');
    });
});

require __DIR__.'/auth.php';
