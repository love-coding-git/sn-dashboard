<?php

namespace App\Models\Sn34;

use Illuminate\Database\Eloquent\Model;

/**
 * The seats (current / previous / two_back) from one board on, until the next change.
 */
class SeatChange extends Model
{
    protected $table = 'sn34_seat_changes';

    public $timestamps = false;

    protected $fillable = ['from_at', 'policy_id', 'seats', 'weights'];

    protected function casts(): array
    {
        return ['from_at' => 'datetime', 'seats' => 'array', 'weights' => 'array'];
    }
}
