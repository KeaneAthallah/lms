<?php

namespace App\Notifications;

use App\Models\QuizAttempt;
use App\Notifications\Concerns\RetriesTransientMailFailures;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuizResult extends Notification implements ShouldQueue
{
    use Queueable;
    use RetriesTransientMailFailures;

    public function __construct(public QuizAttempt $attempt) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $outcome = $this->attempt->passed ? 'passed' : 'did not pass';

        return (new MailMessage)
            ->subject('Your quiz result is in')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('You '.$outcome." \"{$this->attempt->quiz->title}\" with a score of {$this->attempt->score_percentage}%.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'quiz_result',
            'title' => 'Quiz result',
            'message' => $this->attempt->passed
                ? "You passed \"{$this->attempt->quiz->title}\" with {$this->attempt->score_percentage}%."
                : "You scored {$this->attempt->score_percentage}% on \"{$this->attempt->quiz->title}\". Keep practising!",
            'course_slug' => $this->attempt->quiz->course->slug,
        ];
    }
}
