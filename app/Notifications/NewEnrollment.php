<?php

namespace App\Notifications;

use App\Models\Enrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewEnrollment extends Notification
{
    use Queueable;

    public function __construct(public Enrollment $enrollment) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New student enrolled in your course')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line("{$this->enrollment->student->name} just enrolled in \"{$this->enrollment->course->title}\".");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_enrollment',
            'title' => 'New enrollment',
            'message' => "{$this->enrollment->student->name} enrolled in \"{$this->enrollment->course->title}\".",
            'course_slug' => $this->enrollment->course->slug,
        ];
    }
}
