<?php

namespace App\Notifications;

use App\Models\Assignment;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssignmentDeadlineReminder extends Notification
{
    use Queueable;

    public function __construct(public Assignment $assignment) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Assignment deadline approaching')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line("\"{$this->assignment->title}\" is due on ".Carbon::parse($this->assignment->due_at)->format('M j, Y g:i A').'.')
            ->action('View Assignment', url('/courses/'.$this->assignment->course->slug.'/assignments'))
            ->line('Make sure to submit before the deadline.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'assignment_reminder',
            'title' => 'Assignment deadline reminder',
            'message' => "\"{$this->assignment->title}\" is due soon.",
            'assignment_id' => $this->assignment->id,
        ];
    }
}
