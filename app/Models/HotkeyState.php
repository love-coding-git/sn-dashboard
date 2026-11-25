<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotkeyState extends Model
{
    public $timestamps = false;

    protected $fillable = ['chain_snapshot_id', 'name', 'hotkey', 'registered', 'uid', 'emission', 'registered_at_block', 'immune', 'rank', 'note'];

    protected function casts(): array
    {
        return ['registered' => 'boolean', 'immune' => 'boolean'];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ChainSnapshot::class, 'chain_snapshot_id');
    }
}
