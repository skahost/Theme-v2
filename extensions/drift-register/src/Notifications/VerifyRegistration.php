<?php

namespace DriftRegister\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent on demand to the address being registered (there is no User yet). */
class VerifyRegistration extends Notification
{
    public function __construct(
        private readonly string $name,
        private readonly string $url,
        private readonly int $minutes,
    ) {}

    /** @return list<string> */
    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your email for '.config('app.name'))
            ->greeting('Hello '.$this->name.'!')
            ->line('Someone used this address to create an account on '.config('app.name').'.')
            ->action('Verify email address', $this->url)
            ->line('This link expires in '.$this->minutes.' minutes. After you verify, we email you a second link to choose your password.')
            ->line('If this was not you, you can ignore this email and no account will be created.');
    }
}
