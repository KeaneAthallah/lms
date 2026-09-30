<?php

namespace App\Notifications;

use App\Models\Assignment;
use App\Notifications\Concerns\RetriesTransientMailFailures;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssignmentGraded extends Notification implements ShouldQueue
{
    use Queueable;
    use RetriesTransientMailFailures;

    public function __construct(
        public Assignment $assignment,
        public float $grade,
        public ?string $feedback = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your assignment has been graded')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line("\"{$this->assignment->title}\" has been graded with a score of {$this->grade}.");

        if ($this->feedback) {
            $mail->line('Feedback: '.$this->feedback);
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'assignment_graded',
            'title' => 'Assignment graded',
            'message' => "\"{$this->assignment->title}\" was graded with {$this->grade} points.",
            'assignment_id' => $this->assignment->id,
            'course_slug' => $this->assignment->course->slug,
        ];
    }
}
