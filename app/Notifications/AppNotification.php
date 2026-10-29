<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app (database) notification. The payload is deliberately generic: no amounts or private figures.
 */
class AppNotification extends Notification
{
    /**
     * @param  array{title: string, message: string, url: string, category: string, key: string}  $payload
     */
    public function __construct(private array $payload) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
