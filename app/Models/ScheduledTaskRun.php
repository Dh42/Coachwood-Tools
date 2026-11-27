<?php

namespace App\Models;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledTaskRun extends Model
{
    /**
     * A run stuck this long with no finished_at is treated as stale rather
     * than "still running" — covers a SIGKILL, OOM kill, or server reboot
     * that never got to run the after() callback that closes the row out.
     */
    protected const STALE_AFTER_MINUTES = 60;

    protected $fillable = [
        'scheduled_task_id', 'started_at', 'finished_at', 'exit_code', 'output', 'triggered_by', 'triggered_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function scheduledTask(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class);
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function duration(): ?CarbonInterval
    {
        if (! $this->finished_at) {
            return null;
        }

        return $this->started_at->diffAsCarbonInterval($this->finished_at);
    }

    public function status(): string
    {
        if (! $this->finished_at) {
            return $this->started_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES)) ? 'stale' : 'running';
        }

        return $this->exit_code === 0 ? 'success' : 'failed';
    }
}
