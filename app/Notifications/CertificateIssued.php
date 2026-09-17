<?php

namespace App\Notifications;

use App\Models\Certificate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificateIssued extends Notification
{
    use Queueable;

    public function __construct(public Certificate $certificate) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your certificate is ready')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line("Your certificate for \"{$this->certificate->course->title}\" is available.")
            ->action('View Certificate', url('/certificates/'.$this->certificate->id))
            ->line('Certificate number: '.$this->certificate->certificate_number);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'certificate',
            'title' => 'Certificate available',
            'message' => "Your certificate for \"{$this->certificate->course->title}\" is ready.",
            'certificate_id' => $this->certificate->id,
        ];
    }
}
