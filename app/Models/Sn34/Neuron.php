<?php

namespace App\Models\Sn34;

use Illuminate\Database\Eloquent\Model;

/**
 * One netuid-34 uid from the newest metagraph read.
 */
class Neuron extends Model
{
    protected $table = 'sn34_neurons';

    public $timestamps = false;

    protected $fillable = ['uid', 'hotkey', 'coldkey', 'registered_at', 'registered_at_block', 'block_number', 'immune', 'in_danger', 'incentive', 'emission', 'daily_alpha', 'daily_tao', 'read_at'];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'immune' => 'boolean', 'in_danger' => 'boolean', 'read_at' => 'datetime'];
    }
}
