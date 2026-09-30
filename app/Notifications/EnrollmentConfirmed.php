<?php

namespace App\Notifications;

use App\Models\Course;
use App\Notifications\Concerns\RetriesTransientMailFailures;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EnrollmentConfirmed extends Notification implements ShouldQueue
{
    use Queueable;
    use RetriesTransientMailFailures;

    public function __construct(public Course $course) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You are enrolled in '.$this->course->title)
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('You have been successfully enrolled in the course "'.$this->course->title.'".')
            ->action('Start Learning', url(config('app.url').'/courses/'.$this->course->slug.'/learn'))
            ->line('Enjoy your learning journey!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'enrollment',
            'title' => 'Enrollment confirmed',
            'message' => "You are now enrolled in \"{$this->course->title}\".",
            'course_slug' => $this->course->slug,
        ];
    }
}
