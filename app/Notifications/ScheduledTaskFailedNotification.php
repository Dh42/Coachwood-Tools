<?php

namespace App\Notifications;

use App\Models\ScheduledTaskRun;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ScheduledTaskFailedNotification extends Notification
{
    public function __construct(public ScheduledTaskRun $run) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $task = $this->run->scheduledTask;

        return (new MailMessage)
            ->subject('Scheduled task failed: '.$task->name)
            ->line('The scheduled task "'.$task->name.'" ('.$task->command.') failed.')
            ->line('Exit code: '.$this->run->exit_code)
            ->line('Output: '.Str::limit($this->run->output ?? '(no output captured)', 500))
            ->action('View run history', route('admin.schedules.show', $task));
    }
}
