<?php

namespace App\Notifications;

use App\Models\Course;
use App\Notifications\Concerns\RetriesTransientMailFailures;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CourseCompleted extends Notification implements ShouldQueue
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
            ->subject('Congratulations on completing a course!')
            ->greeting('Congratulations '.$notifiable->name.'!')
            ->line("You have completed \"{$this->course->title}\". Your certificate is on its way.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'course_completed',
            'title' => 'Course completed',
            'message' => "You completed \"{$this->course->title}\". Congratulations!",
            'course_slug' => $this->course->slug,
        ];
    }
}
