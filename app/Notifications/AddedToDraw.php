<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The office put a company on an event's draw list and HQ confirmed it.
 *
 * THE ONLY THING THAT TELLS A PARTICIPANT THEY ARE IN A DRAW. There is no
 * self-registration in undilot: the office enters every company, so nothing a
 * participant does could have told them. Without this they sign in to a
 * dashboard that says nothing and a lot they were never asked to confirm.
 * Mail matters as much as the database row: a participant who never opens the
 * portal must still hear about it.
 *
 * Sent ONCE, on HQ's first verification — `first_verified_at`. A row verified
 * before, edited by a zone and verified again, has nothing new to announce.
 *
 * Queued, like every notification here. On a developer machine no worker runs,
 * so it sits in `jobs` until the button on /auto is pressed.
 */
class AddedToDraw extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Event $event)
    {
        // Sent from inside the office's transaction (EntRegistrationController::verify
        // runs under DB::transaction): only once it holds, so a rollback can't leave a
        // participant notified about a row that was never actually verified.
        $this->afterCommit();
    }

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->greeting('Hi '.$notifiable->name.',')
            ->subject('You are in the draw: '.$this->event->title)
            ->line('Your company is in the lot draw for '.$this->event->title.'.')
            ->action('Open dashboard', route('dashboard'));
    }

    /**
     * `join_event_id` and `approved` keep their names from the notification this
     * replaced: NotificationController and notifications/index.blade.php read
     * them, and a renamed key renders an empty row rather than failing.
     */
    public function toArray($notifiable): array
    {
        return [
            'title' => 'You are in the draw',
            'join_event_id' => $this->event->id,
            'approved' => true,
            'event_name' => $this->event->title,
            'message' => 'Your company is in the lot draw for '.$this->event->title.'.',
            'type' => 'success',
        ];
    }
}
