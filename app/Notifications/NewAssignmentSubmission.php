<?php

namespace App\Notifications;

use App\Models\AssignmentSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewAssignmentSubmission extends Notification
{
    use Queueable;

    public function __construct(public AssignmentSubmission $submission) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New assignment submission')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line("{$this->submission->student->name} submitted \"{$this->submission->assignment->title}\".");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'submission',
            'title' => 'New submission',
            'message' => "{$this->submission->student->name} submitted \"{$this->submission->assignment->title}\".",
            'course_slug' => $this->submission->assignment->course->slug,
        ];
    }
}
