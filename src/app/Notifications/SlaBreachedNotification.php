<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SlaBreachedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dueText = $this->ticket->sla_due_at?->format('M d, Y H:i T') ?? 'N/A';
        $assignedText = $this->ticket->assignedAgent?->name ?? 'Unassigned';

        return (new MailMessage)
            ->error()
            ->subject("[ESCALATION] SLA BREACHED: Ticket {$this->ticket->ticket_number}")
            ->greeting("Attention {$notifiable->name},")
            ->line("Ticket **{$this->ticket->ticket_number}** has exceeded its target resolution deadline and has been officially escalated.")
            ->line("**Subject:** {$this->ticket->subject}")
            ->line("**Priority:** {$this->ticket->priority->label()}")
            ->line("**Assigned To:** {$assignedText}")
            ->line("**Target Deadline Was:** {$dueText}")
            ->action('Review Escalated Ticket', route('tickets.show', $this->ticket))
            ->line('This SLA breach has been recorded in the system audit logs.');
    }
}
