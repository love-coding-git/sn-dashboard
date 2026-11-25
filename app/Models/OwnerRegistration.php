<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OwnerRegistration extends Model
{
    public $timestamps = false;

    protected $fillable = ['coldkey', 'netuid', 'uid', 'hotkey', 'registered_at', 'cost_tao'];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'cost_tao' => 'float'];
    }
}
