<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SlaApproachingBreachNotification extends Notification implements ShouldQueue
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
            ->subject("[URGENT WARNING] SLA Approaching Breach: {$this->ticket->ticket_number}")
            ->greeting("Attention {$notifiable->name},")
            ->line("Ticket **{$this->ticket->ticket_number}** has elapsed 80% of its SLA resolution window and is approaching an SLA breach.")
            ->line("**Subject:** {$this->ticket->subject}")
            ->line("**Priority:** {$this->ticket->priority->label()}")
            ->line("**Assigned To:** {$assignedText}")
            ->line("**Hard SLA Due Date:** {$dueText}")
            ->action('Take Action on Ticket', route('tickets.show', $this->ticket))
            ->line('Immediate action is required to avoid an SLA violation.');
    }
}
