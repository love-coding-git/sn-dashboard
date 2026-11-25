<?php

namespace App\Models\Sn34;

use Illuminate\Database\Eloquent\Model;

/**
 * One TAO transfer of a traced owner (Taostats /v1/transfers).
 */
class Transfer extends Model
{
    protected $table = 'sn34_transfers';

    public $timestamps = false;

    protected $fillable = ['external_id', 'from_address', 'to_address', 'amount_tao', 'block_number', 'transferred_at'];

    protected function casts(): array
    {
        return ['transferred_at' => 'datetime'];
    }
}
