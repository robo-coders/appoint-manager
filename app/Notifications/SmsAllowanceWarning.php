<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SmsAllowanceWarning extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public Tenant $tenant,
        public int $threshold,
        public array $snapshot,
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
        $used = (int) $this->snapshot['used'];
        $included = (int) $this->snapshot['included'];
        $topup = (string) $this->snapshot['topup_price'];
        $size = (int) $this->snapshot['topup_size'];

        if ($this->threshold >= 100) {
            return (new MailMessage)
                ->subject('SMS has stopped this cycle')
                ->line("You have used {$used} of {$included} included texts this cycle. New SMS will not send until you buy more, or the cycle resets.")
                ->line('Email still goes out. The overdue list still works. You can still ring people.')
                ->line("A top-up is {$topup} for {$size} more texts, and they do not expire with the cycle.")
                ->action('Buy more texts', url('/billing'));
        }

        return (new MailMessage)
            ->subject('You have used '.$this->threshold.'% of this cycle\'s texts')
            ->line("You have used {$used} of {$included} included texts this cycle.")
            ->line("A top-up is {$topup} for {$size} more, bought from billing, applied immediately.")
            ->action('Billing', url('/billing'));
    }
}
