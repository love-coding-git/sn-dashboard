<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Neuron extends Model
{
    public $timestamps = false;

    protected $fillable = ['netuid', 'hotkey', 'coldkey', 'uid', 'registered_at_block', 'daily_tao', 'incentive', 'registered', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['registered' => 'boolean', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
