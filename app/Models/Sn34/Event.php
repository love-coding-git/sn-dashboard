<?php

namespace App\Models\Sn34;

use Illuminate\Database\Eloquent\Model;

/**
 * A change the SN34 jobs saw: new run, state, second attempt, verdict, seat change, card.
 */
class Event extends Model
{
    protected $table = 'sn34_events';

    public $timestamps = false;

    protected $fillable = ['occurred_at', 'type', 'run_id', 'hotkey', 'coldkey', 'data'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'data' => 'array'];
    }
}
