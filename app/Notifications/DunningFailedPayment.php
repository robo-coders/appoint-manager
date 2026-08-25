<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DunningFailedPayment extends Notification
{
    use Queueable;

    public function __construct(public Tenant $tenant, public int $day) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $day = $this->day;

        $subject = match ($day) {
            3 => 'We still could not take payment',
            7 => 'Your account is about to become read-only',
            default => 'We could not take your subscription payment',
        };

        $line = match ($day) {
            3 => 'The card on file was declined again. Update it in the next few days to keep writing to the diary.',
            7 => 'After today the diary becomes read-only until a payment succeeds. Clients can still book online.',
            default => 'The card on file was declined. Update it under billing. Clients can still book online.',
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->action('Update billing', url('/billing'));
    }
}
