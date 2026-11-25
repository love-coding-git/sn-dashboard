<?php

namespace App\Models\Sn34;

use Illuminate\Database\Eloquent\Model;

/**
 * One confirmed competition board (stored once per confirmed_at).
 */
class Board extends Model
{
    protected $table = 'sn34_boards';

    public $timestamps = false;

    protected $fillable = ['policy_id', 'confirmed_at', 'phase', 'paused', 'kings', 'published_weights', 'high_water_marks', 'queue', 'challenges', 'read_at'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'paused' => 'boolean', 'kings' => 'array', 'published_weights' => 'array', 'high_water_marks' => 'array', 'queue' => 'array', 'read_at' => 'datetime'];
    }
}
