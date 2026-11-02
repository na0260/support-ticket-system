<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketAssignedNotification extends Notification implements ShouldQueue
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

        return (new MailMessage)
            ->subject("[{$this->ticket->ticket_number}] Ticket Assigned to You: {$this->ticket->subject}")
            ->greeting("Hello {$notifiable->name},")
            ->line("You have been assigned to handle support ticket **{$this->ticket->ticket_number}**.")
            ->line("**Subject:** {$this->ticket->subject}")
            ->line("**Priority:** {$this->ticket->priority->label()}")
            ->line("**Category:** {$this->ticket->category->name}")
            ->line("**Target Resolution SLA:** {$dueText}")
            ->action('View Ticket in Workspace', route('tickets.show', $this->ticket))
            ->line('Please review the customer details and provide assistance as soon as possible.');
    }
}
