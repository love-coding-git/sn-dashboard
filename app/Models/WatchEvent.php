<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatchEvent extends Model
{
    protected $fillable = ['occurred_at', 'type', 'competition_id', 'hotkey', 'coldkey', 'uid', 'is_ours', 'source', 'data'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'is_ours' => 'boolean', 'data' => 'array'];
    }
}
