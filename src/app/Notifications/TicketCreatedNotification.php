<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketCreatedNotification extends Notification implements ShouldQueue
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
            ->subject("[{$this->ticket->ticket_number}] Ticket Created: {$this->ticket->subject}")
            ->greeting("Hello {$notifiable->name},")
            ->line('Your support ticket has been received and logged into our system.')
            ->line("**Ticket Number:** {$this->ticket->ticket_number}")
            ->line("**Subject:** {$this->ticket->subject}")
            ->line("**Priority:** {$this->ticket->priority->label()}")
            ->line("**Target Resolution SLA:** {$dueText}")
            ->action('View Support Ticket', route('tickets.show', $this->ticket))
            ->line('Our support team will review and update your ticket promptly.');
    }
}
