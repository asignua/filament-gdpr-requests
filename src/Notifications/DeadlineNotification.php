<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminder for staff: a request is about to fall due, or is overdue. Names a reference and a date, nothing else.
 */
final class DeadlineNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $reference,
        public readonly string $dueDate,
        public readonly bool $overdue,
        public readonly ?string $url = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        /** @var list<string> */
        return array_values((array) config('filament-gdpr-requests.reminders.channels', ['mail']));
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $key = $this->overdue ? 'overdue' : 'due';
        $base = 'filament-gdpr-requests::filament-gdpr-requests.reminder.';
        $message = (new MailMessage)
            ->subject(__($base.$key.'.subject', ['reference' => $this->reference]))
            ->line(__($base.$key.'.line', ['reference' => $this->reference, 'date' => $this->dueDate]));

        if ($this->url !== null) {
            $message->action(__($base.'open'), $this->url);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        $key = $this->overdue ? 'overdue' : 'due';
        $base = 'filament-gdpr-requests::filament-gdpr-requests.reminder.';

        return [
            'title' => __($base.$key.'.subject', ['reference' => $this->reference]),
            'body' => __($base.$key.'.line', ['reference' => $this->reference, 'date' => $this->dueDate]),
            'url' => $this->url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
