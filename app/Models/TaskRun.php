<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'miner_task_id', 'seq', 'run_id', 'attempt_no', 'passed', 'agent_steps', 'input_tokens', 'cached_input_tokens',
        'output_tokens', 'jev_input_tokens', 'weighted_tokens', 'time_taken_seconds',
    ];

    protected function casts(): array
    {
        return ['passed' => 'boolean', 'weighted_tokens' => 'float', 'time_taken_seconds' => 'float'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(MinerTask::class, 'miner_task_id');
    }
}
