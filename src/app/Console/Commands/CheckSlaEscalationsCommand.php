<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sla\SlaEscalationService;
use Illuminate\Console\Command;

final class CheckSlaEscalationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickets:check-sla';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check tickets against SLA warning and breach deadlines and escalate them.';

    /**
     * Execute the console command.
     */
    public function handle(SlaEscalationService $escalationService): int
    {
        $this->comment('Running SLA escalation checks...');

        $approachingCount = $escalationService->escalateApproachingBreach();
        $breachedCount = $escalationService->escalateBreached();

        $this->info("SLA Escalation Check: {$approachingCount} tickets approaching breach, {$breachedCount} tickets breached.");

        return self::SUCCESS;
    }
}
