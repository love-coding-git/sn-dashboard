<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MinerTask extends Model
{
    protected $fillable = [
        'miner_id', 'platform_task_id', 'task_name', 'is_screener', 'screener_stage', 'complexity', 'benchmark_type',
        'run_count', 'baseline_passed', 'baseline_total', 'compression_passed', 'compression_total', 'finished_runs',
        'platform_score', 'weighted_ratio', 'mean_steps', 'tokens',
    ];

    protected function casts(): array
    {
        return [
            'is_screener' => 'boolean',
            'tokens' => 'array',
            'platform_score' => 'float',
            'weighted_ratio' => 'float',
            'mean_steps' => 'float',
        ];
    }

    public function miner(): BelongsTo
    {
        return $this->belongsTo(Miner::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(TaskRun::class)->orderBy('seq');
    }
}
