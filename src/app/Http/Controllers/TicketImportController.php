<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TicketImportRequest;
use App\Jobs\ProcessTicketImportJob;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TicketImportController extends Controller
{
    /**
     * Display a listing of ticket imports.
     */
    public function index(Request $request): View|JsonResponse
    {
        Gate::authorize('viewAny', TicketImport::class);

        $imports = TicketImport::query()
            ->with('user')
            ->recent()
            ->paginate(15);

        if ($request->wantsJson()) {
            return response()->json($imports);
        }

        return view('imports.index', [
            'imports' => $imports,
        ]);
    }

    /**
     * Store and queue a new ticket import CSV.
     */
    public function store(TicketImportRequest $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('create', TicketImport::class);

        /** @var User $user */
        $user = $request->user();

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $storedPath = $file->store('imports', 'private');

        /** @var TicketImport $import */
        $import = TicketImport::query()->create([
            'user_id' => $user->id,
            'file_name' => $originalName,
            'file_path' => $storedPath,
            'status' => TicketImport::STATUS_PENDING,
            'total_rows' => 0,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
        ]);

        ProcessTicketImportJob::dispatch($import);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Import queued successfully.',
                'import' => $import,
            ], 202);
        }

        return redirect()->route('imports.show', $import)
            ->with('status', 'CSV import queued successfully and is processing in the background.');
    }

    /**
     * Display the specified ticket import status and failures.
     */
    public function show(Request $request, TicketImport $import): View|JsonResponse
    {
        Gate::authorize('view', $import);

        $import->load('user');
        $failures = $import->failures()
            ->orderBy('row_number')
            ->paginate(25);

        if ($request->wantsJson()) {
            return response()->json([
                'import' => $import,
                'failures' => $failures,
            ]);
        }

        return view('imports.show', [
            'import' => $import,
            'failures' => $failures,
        ]);
    }

    /**
     * Stream a valid sample CSV template for download.
     */
    public function downloadSample(): StreamedResponse
    {
        Gate::authorize('create', TicketImport::class);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ticket_import_template.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function (): void {
            $handle = fopen('php://output', 'w');

            // Add UTF-8 BOM for Excel compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'subject',
                'description',
                'priority',
                'category',
                'customer_email',
                'assigned_agent_email',
                'status',
                'ticket_number',
            ]);

            fputcsv($handle, [
                'Database connection timeout during peak load',
                'Web application loses connection to MySQL cluster during peak trading hours.',
                'high',
                'technical-support',
                'customer@example.com',
                'agent@example.com',
                'open',
                '',
            ]);

            fputcsv($handle, [
                'Invoice discrepancy for monthly subscription',
                'Billing statement reflects an extra charge for unused enterprise seat licenses.',
                'medium',
                'billing-inquiry',
                'customer@example.com',
                '',
                'open',
                '',
            ]);

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Stream CSV export of failed rows with error reasons for an import.
     */
    public function downloadFailures(TicketImport $import): StreamedResponse
    {
        Gate::authorize('view', $import);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"import_{$import->id}_failures.csv\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($import): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'row_number',
                'subject',
                'description',
                'priority',
                'category',
                'customer_email',
                'assigned_agent_email',
                'status',
                'ticket_number',
                'error_reasons',
            ]);

            // Stream failures with cursor to keep memory flat O(1)
            foreach ($import->failures()->orderBy('row_number')->cursor() as $failure) {
                $raw = (array) $failure->raw_data;
                $errs = (array) $failure->errors;

                $flattenedErrors = [];
                foreach ($errs as $field => $messages) {
                    if (is_array($messages)) {
                        $flattenedErrors[] = "{$field}: ".implode(', ', $messages);
                    } else {
                        $flattenedErrors[] = "{$field}: {$messages}";
                    }
                }

                fputcsv($handle, [
                    $failure->row_number,
                    $raw['subject'] ?? '',
                    $raw['description'] ?? '',
                    $raw['priority'] ?? '',
                    $raw['category'] ?? '',
                    $raw['customer_email'] ?? '',
                    $raw['assigned_agent_email'] ?? '',
                    $raw['status'] ?? '',
                    $raw['ticket_number'] ?? '',
                    implode(' | ', $flattenedErrors),
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
