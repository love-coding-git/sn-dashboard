<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Miner extends Model
{
    protected $fillable = [
        'competition_id', 'hotkey', 'place', 'status', 'stage1_score', 'stage1_verdict', 'stage2_score', 'stage2_verdict',
        'overall', 'cat_short', 'cat_medium', 'cat_long', 'last_submit_at', 'coldkey', 'uid', 'is_ours', 'our_label',
        'fingerprint', 'tasks_synced_at', 'first_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_submit_at' => 'datetime',
            'tasks_synced_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'is_ours' => 'boolean',
            'fingerprint' => 'array',
            'stage1_score' => 'float',
            'stage2_score' => 'float',
            'overall' => 'float',
            'cat_short' => 'float',
            'cat_medium' => 'float',
            'cat_long' => 'float',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(MinerTask::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'coldkey', 'coldkey');
    }
}
