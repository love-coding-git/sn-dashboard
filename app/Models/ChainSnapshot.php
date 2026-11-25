<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChainSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['netuid', 'block', 'taken_at', 'immunity_period', 'uids', 'immune', 'zero_emission', 'neurons'];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'neurons' => 'array'];
    }

    public function states(): HasMany
    {
        return $this->hasMany(HotkeyState::class);
    }
}
