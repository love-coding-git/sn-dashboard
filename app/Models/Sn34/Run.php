<?php

namespace App\Models\Sn34;

use Illuminate\Database\Eloquent\Model;

/**
 * One Yugos run (billing-<uuid>) as the public history lists it, with its newest results progress.
 */
class Run extends Model
{
    protected $table = 'sn34_runs';

    protected $primaryKey = 'run_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['run_id', 'hotkey', 'coldkey', 'pack', 'state', 'enqueued_at', 'started_at', 'finished_at', 'submission_sha256', 'started_at_changes', 'progress', 'complete', 'progress_at', 'first_seen_at'];

    protected function casts(): array
    {
        return ['enqueued_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'progress' => 'array', 'complete' => 'boolean', 'progress_at' => 'datetime', 'first_seen_at' => 'datetime'];
    }
}
