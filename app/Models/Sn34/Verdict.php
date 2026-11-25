<?php

namespace App\Models\Sn34;

use Illuminate\Database\Eloquent\Model;

/**
 * A board decision on one run (the newest board entry that carries class measurements).
 */
class Verdict extends Model
{
    protected $table = 'sn34_verdicts';

    protected $fillable = ['run_id', 'hotkey', 'policy_id', 'eligible', 'promoted', 'net_value', 'lower_bound', 'reasons', 'classes', 'first_confirmed_at', 'confirmed_at'];

    protected function casts(): array
    {
        return ['eligible' => 'boolean', 'promoted' => 'boolean', 'reasons' => 'array', 'classes' => 'array', 'first_confirmed_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }
}
