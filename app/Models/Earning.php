<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Earning extends Model
{
    public $timestamps = false;

    protected $fillable = ['netuid', 'day', 'hotkey', 'coldkey', 'tao', 'alpha', 'incentive'];

    protected function casts(): array
    {
        return ['day' => 'date', 'tao' => 'float', 'alpha' => 'float', 'incentive' => 'float'];
    }
}
