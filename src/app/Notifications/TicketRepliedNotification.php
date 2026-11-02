<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TicketRepliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketMessage $message,
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
        $senderName = $this->message->user->name;
        $snippet = Str::limit($this->message->body, 250);

        return (new MailMessage)
            ->subject("[{$this->ticket->ticket_number}] New Reply on: {$this->ticket->subject}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$senderName} has posted a new reply on ticket **{$this->ticket->ticket_number}**:")
            ->line("> {$snippet}")
            ->action('View Conversation Thread', route('tickets.show', $this->ticket))
            ->line('You can view the full history and reply directly in the ticket portal.');
    }
}
