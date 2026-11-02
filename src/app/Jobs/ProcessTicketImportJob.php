<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketImport;
use App\Models\TicketImportFailure;
use App\Models\User;
use App\Services\Activity\TicketActivityLogger;
use App\Services\Import\CsvReaderService;
use App\Services\Sla\BusinessHoursCalculator;
use App\Services\TicketNumberGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class ProcessTicketImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600;

    /**
     * Chunk size for streaming processing.
     */
    private const CHUNK_SIZE = 200;

    public function __construct(
        public TicketImport $ticketImport,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        CsvReaderService $csvReader,
        TicketNumberGenerator $numberGen,
        BusinessHoursCalculator $slaCalc,
        TicketActivityLogger $activityLogger,
    ): void {
        $filePath = Storage::disk('private')->path($this->ticketImport->file_path);

        if (! file_exists($filePath)) {
            $this->ticketImport->update([
                'status' => TicketImport::STATUS_FAILED,
                'completed_at' => now(),
            ]);

            return;
        }

        try {
            $totalRows = $csvReader->countRows($filePath);
            $this->ticketImport->update([
                'total_rows' => $totalRows,
                'status' => TicketImport::STATUS_PROCESSING,
            ]);

            if ($totalRows === 0) {
                $this->ticketImport->update([
                    'status' => TicketImport::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
                Storage::disk('private')->delete($this->ticketImport->file_path);

                return;
            }

            // Cache active categories lookup for high-performance matching
            $activeCategories = TicketCategory::query()
                ->where('is_active', true)
                ->get();

            $processedRows = 0;
            $successfulRows = 0;
            $failedRows = 0;
            $seenTicketNumbers = [];

            // Stream chunks lazily
            $csvReader->streamRows($filePath)->chunk(self::CHUNK_SIZE)->each(function ($chunk) use (
                &$processedRows,
                &$successfulRows,
                &$failedRows,
                &$seenTicketNumbers,
                $activeCategories,
                $numberGen,
                $slaCalc,
                $activityLogger,
            ): void {
                $failuresToInsert = [];

                foreach ($chunk as $item) {
                    $rowNumber = (int) $item['row_number'];
                    $data = (array) $item['data'];
                    $processedRows++;

                    $validationErrors = $this->validateRow($data, $seenTicketNumbers, $activeCategories);

                    if (! empty($validationErrors)) {
                        $failedRows++;
                        $failuresToInsert[] = [
                            'ticket_import_id' => $this->ticketImport->id,
                            'row_number' => $rowNumber,
                            'raw_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                            'errors' => json_encode($validationErrors, JSON_UNESCAPED_UNICODE),
                            'created_at' => now(),
                        ];

                        continue;
                    }

                    // Row is valid - create the ticket
                    try {
                        $this->createTicketFromRow(
                            $data,
                            $activeCategories,
                            $numberGen,
                            $slaCalc,
                            $activityLogger,
                            $seenTicketNumbers,
                        );
                        $successfulRows++;
                    } catch (Throwable $e) {
                        $failedRows++;
                        $failuresToInsert[] = [
                            'ticket_import_id' => $this->ticketImport->id,
                            'row_number' => $rowNumber,
                            'raw_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                            'errors' => json_encode(['general' => [$e->getMessage()]], JSON_UNESCAPED_UNICODE),
                            'created_at' => now(),
                        ];
                    }
                }

                if (! empty($failuresToInsert)) {
                    TicketImportFailure::query()->insert($failuresToInsert);
                }

                $this->ticketImport->update([
                    'processed_rows' => $processedRows,
                    'successful_rows' => $successfulRows,
                    'failed_rows' => $failedRows,
                ]);
            });

            // Mark completed
            $this->ticketImport->update([
                'status' => TicketImport::STATUS_COMPLETED,
                'processed_rows' => $processedRows,
                'successful_rows' => $successfulRows,
                'failed_rows' => $failedRows,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->ticketImport->update([
                'status' => TicketImport::STATUS_FAILED,
                'completed_at' => now(),
            ]);

            throw $e;
        } finally {
            // Remove uploaded file from private disk
            if (Storage::disk('private')->exists($this->ticketImport->file_path)) {
                Storage::disk('private')->delete($this->ticketImport->file_path);
            }
        }
    }

    /**
     * Validate an individual row against business requirements.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, bool>  $seenTicketNumbers
     * @param  \Illuminate\Support\Collection<int, TicketCategory>  $activeCategories
     * @return array<string, list<string>>
     */
    private function validateRow(array $data, array &$seenTicketNumbers, $activeCategories): array
    {
        $rules = [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:10'],
            'priority' => ['required', 'string', 'in:low,medium,high,critical'],
            'category' => ['required', 'string'],
            'customer_email' => ['required', 'email'],
            'assigned_agent_email' => ['nullable', 'email'],
            'ticket_number' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'in:open,in_progress,waiting_customer,resolved,closed'],
        ];

        $validator = Validator::make($data, $rules);
        $errors = [];

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
        }

        // Validate Category matches an active category by name or slug
        if (! isset($errors['category']) && ! empty($data['category'])) {
            $catValue = strtolower(trim((string) $data['category']));
            $categoryExists = $activeCategories->contains(function (TicketCategory $cat) use ($catValue): bool {
                return strtolower($cat->slug) === $catValue || strtolower($cat->name) === $catValue;
            });

            if (! $categoryExists) {
                $errors['category'] = ['The selected category does not exist or is inactive.'];
            }
        }

        // Validate Customer Email exists in users table
        if (! isset($errors['customer_email']) && ! empty($data['customer_email'])) {
            $customerExists = User::query()->where('email', trim((string) $data['customer_email']))->exists();
            if (! $customerExists) {
                $errors['customer_email'] = ['The customer email does not exist in the system.'];
            }
        }

        // Validate Assigned Agent Email (if present, must exist and have agent/admin role)
        if (! isset($errors['assigned_agent_email']) && ! empty($data['assigned_agent_email'])) {
            $agent = User::query()->where('email', trim((string) $data['assigned_agent_email']))->first();
            if ($agent === null) {
                $errors['assigned_agent_email'] = ['The assigned agent email does not exist in the system.'];
            } elseif (! $agent->role->isStaff()) {
                $errors['assigned_agent_email'] = ['The assigned user must have agent or admin role.'];
            }
        }

        // Validate Ticket Number (if present, must not already exist in DB or in current import batch)
        if (! isset($errors['ticket_number']) && ! empty($data['ticket_number'])) {
            $ticketNumber = trim((string) $data['ticket_number']);
            if (isset($seenTicketNumbers[$ticketNumber])) {
                $errors['ticket_number'] = ['Duplicate ticket number in import file.'];
            } elseif (Ticket::query()->where('ticket_number', $ticketNumber)->exists()) {
                $errors['ticket_number'] = ['The ticket number has already been taken.'];
            }
        }

        return $errors;
    }

    /**
     * Create a validated ticket record and audit log.
     *
     * @param  array<string, mixed>  $data
     * @param  \Illuminate\Support\Collection<int, TicketCategory>  $activeCategories
     * @param  array<string, bool>  $seenTicketNumbers
     */
    private function createTicketFromRow(
        array $data,
        $activeCategories,
        TicketNumberGenerator $numberGen,
        BusinessHoursCalculator $slaCalc,
        TicketActivityLogger $activityLogger,
        array &$seenTicketNumbers,
    ): Ticket {
        return DB::transaction(function () use (
            $data,
            $activeCategories,
            $numberGen,
            $slaCalc,
            $activityLogger,
            &$seenTicketNumbers,
        ): Ticket {
            $catValue = strtolower(trim((string) $data['category']));
            /** @var TicketCategory $category */
            $category = $activeCategories->first(function (TicketCategory $cat) use ($catValue): bool {
                return strtolower($cat->slug) === $catValue || strtolower($cat->name) === $catValue;
            });

            /** @var User $customer */
            $customer = User::query()->where('email', trim((string) $data['customer_email']))->firstOrFail();

            $assignedAgentId = null;
            if (! empty($data['assigned_agent_email'])) {
                $agent = User::query()->where('email', trim((string) $data['assigned_agent_email']))->first();
                $assignedAgentId = $agent?->id;
            }

            $ticketNumber = ! empty($data['ticket_number'])
                ? trim((string) $data['ticket_number'])
                : $numberGen->generate();

            $seenTicketNumbers[$ticketNumber] = true;

            $priority = TicketPriority::from(strtolower(trim((string) $data['priority'])));
            $status = ! empty($data['status'])
                ? TicketStatus::from(strtolower(trim((string) $data['status'])))
                : TicketStatus::OPEN;

            $now = now();
            $dueAt = $slaCalc->calculateDueAt($now, $priority);
            $warningAt = $slaCalc->calculateWarningAt($now, $dueAt);

            /** @var Ticket $ticket */
            $ticket = Ticket::query()->create([
                'ticket_number' => $ticketNumber,
                'subject' => trim((string) $data['subject']),
                'description' => trim((string) $data['description']),
                'priority' => $priority,
                'category_id' => $category->id,
                'status' => $status,
                'assigned_agent_id' => $assignedAgentId,
                'created_by' => $customer->id,
                'sla_due_at' => $dueAt,
                'sla_warning_at' => $warningAt,
                'sla_status' => SlaStatus::WITHIN_SLA,
            ]);

            $activityLogger->log($ticket, 'ticket_imported', $this->ticketImport->user, [
                'ticket_number' => $ticket->ticket_number,
                'priority' => $ticket->priority->value,
                'category_id' => $ticket->category_id,
                'import_id' => $this->ticketImport->id,
            ]);

            return $ticket;
        });
    }
}
