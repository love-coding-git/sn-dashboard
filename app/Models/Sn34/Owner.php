<?php

namespace App\Models\Sn34;

use Illuminate\Database\Eloquent\Model;

/**
 * A coldkey that owns SN34 submitter hotkeys.
 */
class Owner extends Model
{
    protected $table = 'sn34_owners';

    protected $fillable = ['coldkey', 'hotkeys', 'owned_hotkeys', 'balance_tao', 'transfers_total', 'profiled_at'];

    protected function casts(): array
    {
        return ['hotkeys' => 'array', 'profiled_at' => 'datetime'];
    }
}
