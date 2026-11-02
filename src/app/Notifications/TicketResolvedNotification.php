<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketResolvedNotification extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject("[{$this->ticket->ticket_number}] Ticket Resolved: {$this->ticket->subject}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your support ticket **{$this->ticket->ticket_number}** has been marked as **Resolved**.")
            ->line("**Subject:** {$this->ticket->subject}")
            ->line('If your issue is fully solved, no further action is required and the ticket will automatically close.')
            ->line('**Need further help?** If you still require assistance, simply post a reply on the ticket thread to automatically reopen it.')
            ->action('View Ticket Details', route('tickets.show', $this->ticket))
            ->line('Thank you for contacting our support team.');
    }
}
