<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transfer extends Model
{
    public $timestamps = false;

    protected $fillable = ['external_id', 'from_address', 'to_address', 'amount_tao', 'block_number', 'transferred_at'];

    protected function casts(): array
    {
        return ['amount_tao' => 'float', 'transferred_at' => 'datetime'];
    }
}
