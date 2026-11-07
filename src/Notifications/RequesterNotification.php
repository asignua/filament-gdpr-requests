<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A short stage mail to the person who made the request. It carries the reference only, no personal data.
 */
final class RequesterNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $stage, public readonly string $reference, public readonly ?string $dueDate = null) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $base = 'filament-gdpr-requests::filament-gdpr-requests.requester_mail.';

        return (new MailMessage)
            ->subject(__($base.$this->stage.'.subject', ['reference' => $this->reference]))
            ->line(__($base.$this->stage.'.line', ['reference' => $this->reference, 'date' => (string) $this->dueDate]))
            ->line(__($base.'footer'));
    }
}
