<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SlaStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Activity\TicketActivityLogger;
use App\Services\Sla\BusinessHoursCalculator;
use App\Services\TicketNumberGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

final class DemoDataSeeder extends Seeder
{
    public function run(
        TicketNumberGenerator $numberGen,
        BusinessHoursCalculator $slaCalc,
        TicketActivityLogger $activityLogger,
    ): void {
        // Ensure base categories and users exist
        $this->call([
            TicketCategorySeeder::class,
            UserSeeder::class,
        ]);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $agent1 = User::query()->where('email', 'agent1@example.com')->firstOrFail();
        $agent2 = User::query()->where('email', 'agent2@example.com')->firstOrFail();
        $customer = User::query()->where('email', 'customer@example.com')->firstOrFail();

        // Additional demo customer for multi-tenant feel
        $customer2 = User::query()->firstOrCreate(
            ['email' => 'sarah.connor@example.com'],
            [
                'name' => 'Sarah Connor',
                'password' => 'password',
                'role' => UserRole::CUSTOMER,
                'is_available' => true,
                'email_verified_at' => now(),
            ]
        );

        $categories = TicketCategory::query()->where('is_active', true)->get()->keyBy('slug');
        $billingCat = $categories['billing-support'] ?? $categories->first();
        $techCat = $categories['technical-inquiry'] ?? $categories->first();
        $generalCat = $categories['general-request'] ?? $categories->first();

        $ticketDefinitions = [
            // 1. Critical, In Progress, Approaching Breach
            [
                'subject' => 'Production API Gateway 504 Gateway Timeout during peak hours',
                'description' => 'Our production checkout services are intermittently failing with 504 Gateway Timeouts when traffic surges past 1,200 req/sec. Immediate engineering triage required.',
                'category' => $techCat,
                'priority' => TicketPriority::CRITICAL,
                'status' => TicketStatus::IN_PROGRESS,
                'customer' => $customer,
                'agent' => $agent1,
                'sla_status' => SlaStatus::APPROACHING_BREACH,
                'time_offset' => '-1 hour 30 minutes',
                'sla_due_offset' => '+30 minutes',
                'sla_warning_offset' => '-10 minutes',
                'messages' => [
                    ['user' => $customer, 'internal' => false, 'body' => 'Observability dashboards show p99 latency spiked to 8.4 seconds at 11:30 UTC.'],
                    ['user' => $agent1, 'internal' => true, 'body' => 'Investigating connection pool exhaustion on the upstream Redis cache cluster.'],
                    ['user' => $agent1, 'internal' => false, 'body' => 'We have scaled up the ingress pod replicas and are analyzing connection timeouts.'],
                ],
            ],
            // 2. Critical, Breached, Open
            [
                'subject' => 'Zero-Day vulnerability report in public webhook endpoint',
                'description' => 'Security team identified unvalidated signature parsing on incoming Stripe webhook callback endpoints. Needs emergency patching.',
                'category' => $techCat,
                'priority' => TicketPriority::CRITICAL,
                'status' => TicketStatus::OPEN,
                'customer' => $customer2,
                'agent' => null,
                'sla_status' => SlaStatus::BREACHED,
                'time_offset' => '-4 hours',
                'sla_due_offset' => '-2 hours',
                'sla_warning_offset' => '-3 hours',
                'messages' => [
                    ['user' => $customer2, 'internal' => false, 'body' => 'Attaching proof-of-concept payload reproduction steps.'],
                ],
            ],
            // 3. High, In Progress, Within SLA
            [
                'subject' => 'Database replication lag exceeding 45 seconds on read replica',
                'description' => 'Read replica in region us-east-2 is falling behind master during batch background jobs. Reporting queries are returning stale data.',
                'category' => $techCat,
                'priority' => TicketPriority::HIGH,
                'status' => TicketStatus::IN_PROGRESS,
                'customer' => $customer,
                'agent' => $agent2,
                'sla_status' => SlaStatus::WITHIN_SLA,
                'time_offset' => '-2 hours',
                'sla_due_offset' => '+2 hours',
                'sla_warning_offset' => '+1 hour',
                'messages' => [
                    ['user' => $agent2, 'internal' => true, 'body' => 'Throttled large bulk email export job to alleviate I/O contention.'],
                    ['user' => $agent2, 'internal' => false, 'body' => 'Replication lag has subsided to 1.2 seconds. Monitoring for the next hour.'],
                    ['user' => $customer, 'internal' => false, 'body' => 'Verified our analytics dashboard now displays current inventory.'],
                ],
            ],
            // 4. High, Waiting Customer, Within SLA
            [
                'subject' => 'SSO SAML authentication loop with Okta identity provider',
                'description' => 'Corporate employees attempting to log in via Okta are trapped in an infinite SAML callback redirect loop after credential submission.',
                'category' => $techCat,
                'priority' => TicketPriority::HIGH,
                'status' => TicketStatus::WAITING_CUSTOMER,
                'customer' => $customer,
                'agent' => $agent1,
                'sla_status' => SlaStatus::WITHIN_SLA,
                'time_offset' => '-1 hour',
                'sla_due_offset' => '+3 hours',
                'sla_warning_offset' => '+2 hours',
                'messages' => [
                    ['user' => $agent1, 'internal' => false, 'body' => 'Could you please confirm if your IdP clock skew tolerance is set to at least 120 seconds in Okta admin settings?'],
                ],
            ],
            // 5. High, Breached, Open
            [
                'subject' => 'Automated nightly backups failing with disk full error',
                'description' => 'Backup daemon halted at 02:00 UTC due to /var/backups partition reaching 100% inode capacity. Retention pruning did not trigger.',
                'category' => $techCat,
                'priority' => TicketPriority::HIGH,
                'status' => TicketStatus::OPEN,
                'customer' => $customer,
                'agent' => null,
                'sla_status' => SlaStatus::BREACHED,
                'time_offset' => '-6 hours',
                'sla_due_offset' => '-2 hours',
                'sla_warning_offset' => '-3 hours',
                'messages' => [],
            ],
            // 6. Medium, Open, Unassigned, Within SLA
            [
                'subject' => 'Incorrect VAT calculation on enterprise annual invoice #INV-9942',
                'description' => 'Our finance department noticed 20% UK VAT was levied despite our registered Irish reverse-charge VAT ID IE9823412X being active.',
                'category' => $billingCat,
                'priority' => TicketPriority::MEDIUM,
                'status' => TicketStatus::OPEN,
                'customer' => $customer,
                'agent' => null,
                'sla_status' => SlaStatus::WITHIN_SLA,
                'time_offset' => '-30 minutes',
                'sla_due_offset' => '+7 hours',
                'sla_warning_offset' => '+5 hours',
                'messages' => [],
            ],
            // 7. Medium, In Progress, Within SLA
            [
                'subject' => 'Request to update credit card on file and change billing contact',
                'description' => 'Our corporate card expired last month. Need assistance migrating billing receipts to accounts-payable@acme-corp.com.',
                'category' => $billingCat,
                'priority' => TicketPriority::MEDIUM,
                'status' => TicketStatus::IN_PROGRESS,
                'customer' => $customer2,
                'agent' => $agent1,
                'sla_status' => SlaStatus::WITHIN_SLA,
                'time_offset' => '-3 hours',
                'sla_due_offset' => '+5 hours',
                'sla_warning_offset' => '+3 hours',
                'messages' => [
                    ['user' => $agent1, 'internal' => true, 'body' => 'Sent secure Stripe portal link with one-time authentication token.'],
                    ['user' => $agent1, 'internal' => false, 'body' => 'We have sent a secure self-service link to your primary account administrator.'],
                ],
            ],
            // 8. Medium, Waiting Customer, Approaching Breach
            [
                'subject' => 'Webhook retry backoff causing delayed inventory syncing',
                'description' => 'Third-party ERP connector is receiving out-of-order webhook delivery when intermittent network blips trigger aggressive retries.',
                'category' => $techCat,
                'priority' => TicketPriority::MEDIUM,
                'status' => TicketStatus::WAITING_CUSTOMER,
                'customer' => $customer,
                'agent' => $agent2,
                'sla_status' => SlaStatus::APPROACHING_BREACH,
                'time_offset' => '-6 hours',
                'sla_due_offset' => '+45 minutes',
                'sla_warning_offset' => '-30 minutes',
                'messages' => [
                    ['user' => $agent2, 'internal' => false, 'body' => 'We implemented idempotent event payload keys. Could you verify if your receiver checks the X-Event-Id header?'],
                ],
            ],
            // 9. Medium, Resolved, Within SLA
            [
                'subject' => 'Invoice payment receipt missing transaction breakdown PDF',
                'description' => 'Billing statement confirmation email had a broken link to the itemized receipt PDF.',
                'category' => $billingCat,
                'priority' => TicketPriority::MEDIUM,
                'status' => TicketStatus::RESOLVED,
                'customer' => $customer,
                'agent' => $agent1,
                'sla_status' => SlaStatus::WITHIN_SLA,
                'time_offset' => '-1 day',
                'sla_due_offset' => '+4 hours',
                'sla_warning_offset' => '+2 hours',
                'messages' => [
                    ['user' => $agent1, 'internal' => false, 'body' => 'Generated and re-sent your itemized PDF receipt. Resolving this ticket.'],
                    ['user' => $customer, 'internal' => false, 'body' => 'Received the PDF, looks great. Thank you!'],
                ],
            ],
            // 10. Low, Open, Unassigned, Within SLA
            [
                'subject' => 'Dark mode UI contrast issue on ticket activity history badge',
                'description' => 'When using dark theme, the gray activity pills have insufficient contrast ratio against the slate background according to WCAG AA.',
                'category' => $generalCat,
                'priority' => TicketPriority::LOW,
                'status' => TicketStatus::OPEN,
                'customer' => $customer,
                'agent' => null,
                'sla_status' => SlaStatus::WITHIN_SLA,
                'time_offset' => '-1 hour',
                'sla_due_offset' => '+15 hours',
                'sla_warning_offset' => '+12 hours',
                'messages' => [],
            ],
            // 11. Low, In Progress, Within SLA
            [
                'subject' => 'Feature Request: Export ticket reports to Excel format (.xlsx)',
                'description' => 'Currently only CSV export is supported. Our executive team would appreciate native formatted Excel workbook export with summary sheets.',
                'category' => $generalCat,
                'priority' => TicketPriority::LOW,
                'status' => TicketStatus::IN_PROGRESS,
                'customer' => $customer2,
                'agent' => $agent2,
                'sla_status' => SlaStatus::WITHIN_SLA,
                'time_offset' => '-4 hours',
                'sla_due_offset' => '+12 hours',
                'sla_warning_offset' => '+9 hours',
                'messages' => [
                    ['user' => $agent2, 'internal' => true, 'body' => 'Logged in Product Backlog item PROD-441.'],
                    ['user' => $agent2, 'internal' => false, 'body' => 'Thank you for the suggestion. This has been queued for our Q1 roadmap.'],
                ],
            ],
            // 12. Low, Closed
            [
                'subject' => 'How do I add additional email recipients to notification threads?',
                'description' => 'Need instructions on how to CC our secondary IT helpdesk inbox on all ticket updates.',
                'category' => $generalCat,
                'priority' => TicketPriority::LOW,
                'status' => TicketStatus::CLOSED,
                'customer' => $customer,
                'agent' => $agent1,
                'sla_status' => SlaStatus::WITHIN_SLA,
                'time_offset' => '-3 days',
                'sla_due_offset' => '+10 hours',
                'sla_warning_offset' => '+8 hours',
                'messages' => [
                    ['user' => $agent1, 'internal' => false, 'body' => 'You can configure department CC addresses under Profile Settings > Notifications.'],
                    ['user' => $customer, 'internal' => false, 'body' => 'Found it, worked like a charm! Closing ticket.'],
                ],
            ],
            // 13. Critical, Resolved, Within SLA
            [
                'subject' => 'Data pipeline ingestion halted due to corrupted schema migration',
                'description' => 'Hourly ETL pipeline crashed on unexpected null values in user_metadata JSON column.',
                'category' => $techCat,
                'priority' => TicketPriority::CRITICAL,
                'status' => TicketStatus::RESOLVED,
                'customer' => $customer,
                'agent' => $agent2,
                'sla_status' => SlaStatus::WITHIN_SLA,
                'time_offset' => '-5 hours',
                'sla_due_offset' => '-3 hours',
                'sla_warning_offset' => '-4 hours',
                'messages' => [
                    ['user' => $agent2, 'internal' => true, 'body' => 'Applied non-destructive migration hotfix and backfilled null keys.'],
                    ['user' => $agent2, 'internal' => false, 'body' => 'Schema patch deployed and pipeline resumed processing backlogged records.'],
                ],
            ],
            // 14. High, Open, Approaching Breach
            [
                'subject' => 'SSL certificate expiring in 48 hours for api.clientdomain.com',
                'description' => 'Let\'s Encrypt ACME automated renewal failed due to DNS challenge propagation timeout.',
                'category' => $techCat,
                'priority' => TicketPriority::HIGH,
                'status' => TicketStatus::OPEN,
                'customer' => $customer2,
                'agent' => $agent1,
                'sla_status' => SlaStatus::APPROACHING_BREACH,
                'time_offset' => '-3 hours 15 minutes',
                'sla_due_offset' => '+45 minutes',
                'sla_warning_offset' => '-15 minutes',
                'messages' => [],
            ],
            // 15. Medium, Closed
            [
                'subject' => 'Refund processed confirmation for cancelled annual subscription',
                'description' => 'Requesting confirmation email and credit note number for our credit card account.',
                'category' => $billingCat,
                'priority' => TicketPriority::MEDIUM,
                'status' => TicketStatus::CLOSED,
                'customer' => $customer2,
                'agent' => $agent1,
                'sla_status' => SlaStatus::WITHIN_SLA,
                'time_offset' => '-4 days',
                'sla_due_offset' => '+2 hours',
                'sla_warning_offset' => '+1 hour',
                'messages' => [
                    ['user' => $agent1, 'internal' => false, 'body' => 'Credit note #CR-1099 has been issued and funds will settle within 3-5 business days.'],
                ],
            ],
        ];

        // Seed 15 additional procedural tickets to achieve ~25-30 tickets
        for ($i = 16; $i <= 25; $i++) {
            $prio = match ($i % 4) {
                0 => TicketPriority::CRITICAL,
                1 => TicketPriority::HIGH,
                2 => TicketPriority::MEDIUM,
                default => TicketPriority::LOW,
            };

            $status = match ($i % 5) {
                0 => TicketStatus::OPEN,
                1 => TicketStatus::IN_PROGRESS,
                2 => TicketStatus::WAITING_CUSTOMER,
                3 => TicketStatus::RESOLVED,
                default => TicketStatus::CLOSED,
            };

            $sla = match ($i % 3) {
                0 => SlaStatus::WITHIN_SLA,
                1 => SlaStatus::APPROACHING_BREACH,
                default => SlaStatus::BREACHED,
            };

            $ticketDefinitions[] = [
                'subject' => "System maintenance inspection #{$i}: Service node health check",
                'description' => "Automated monitoring detected routine telemetry variance on compute cluster node-0{$i}. Diagnostics logged for scheduled review.",
                'category' => ($i % 2 === 0) ? $techCat : $billingCat,
                'priority' => $prio,
                'status' => $status,
                'customer' => ($i % 3 === 0) ? $customer2 : $customer,
                'agent' => ($i % 2 === 0) ? $agent1 : (($i % 3 === 0) ? $agent2 : null),
                'sla_status' => $sla,
                'time_offset' => "-{$i} hours",
                'sla_due_offset' => ($sla === SlaStatus::BREACHED) ? '-1 hour' : '+'.(8 - ($i % 6)).' hours',
                'sla_warning_offset' => ($sla === SlaStatus::BREACHED || $sla === SlaStatus::APPROACHING_BREACH) ? '-30 minutes' : '+2 hours',
                'messages' => [
                    ['user' => $customer, 'internal' => false, 'body' => "Routine diagnostic log generated for node-0{$i}."],
                ],
            ];
        }

        foreach ($ticketDefinitions as $def) {
            $createdAt = Carbon::parse($def['time_offset']);
            $dueAt = Carbon::parse($def['sla_due_offset']);
            $warningAt = Carbon::parse($def['sla_warning_offset']);

            $ticketNumber = $numberGen->generate();

            /** @var Ticket $ticket */
            $ticket = Ticket::query()->create([
                'ticket_number' => $ticketNumber,
                'subject' => $def['subject'],
                'description' => $def['description'],
                'priority' => $def['priority'],
                'category_id' => $def['category']->id,
                'status' => $def['status'],
                'assigned_agent_id' => $def['agent']?->id,
                'created_by' => $def['customer']->id,
                'sla_due_at' => $dueAt,
                'sla_warning_at' => $warningAt,
                'sla_status' => $def['sla_status'],
                'escalated_at' => ($def['sla_status'] === SlaStatus::BREACHED) ? $dueAt : null,
                'resolved_at' => ($def['status']->isResolved() || $def['status']->isTerminal()) ? Carbon::now()->subHour() : null,
                'closed_at' => ($def['status']->isTerminal()) ? Carbon::now()->subMinutes(30) : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            // Initial audit entry
            $activityLogger->log($ticket, 'ticket_created', $def['customer'], [
                'ticket_number' => $ticket->ticket_number,
                'priority' => $ticket->priority->value,
                'category_id' => $ticket->category_id,
                'sla_due_at' => $dueAt->toIso8601String(),
            ]);

            if ($def['agent'] !== null) {
                $activityLogger->log($ticket, 'ticket_assigned', $admin, [
                    'assigned_agent_id' => $def['agent']->id,
                    'agent_name' => $def['agent']->name,
                ]);
            }

            // Seed threaded conversation messages
            foreach ($def['messages'] as $msg) {
                /** @var TicketMessage $message */
                $message = TicketMessage::query()->create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $msg['user']->id,
                    'body' => $msg['body'],
                    'is_internal' => $msg['internal'],
                    'created_at' => $createdAt->copy()->addMinutes(15),
                    'updated_at' => $createdAt->copy()->addMinutes(15),
                ]);

                $event = $msg['internal']
                    ? 'internal_note_added'
                    : ($msg['user']->role->isStaff() ? 'agent_replied' : 'customer_replied');

                $activityLogger->log($ticket, $event, $msg['user'], [
                    'message_id' => $message->id,
                    'is_internal' => $msg['internal'],
                ]);
            }

            if ($def['status']->isResolved()) {
                $activityLogger->log($ticket, 'status_changed', $def['agent'] ?? $admin, [
                    'old_status' => TicketStatus::IN_PROGRESS->value,
                    'new_status' => TicketStatus::RESOLVED->value,
                ]);
            }

            if ($def['status']->isTerminal()) {
                $activityLogger->log($ticket, 'ticket_closed', $def['customer'], [
                    'closed_at' => $ticket->closed_at?->toIso8601String(),
                ]);
            }
        }
    }
}
