<?php

namespace App\Models\Sn34;

use Illuminate\Database\Eloquent\Model;

/**
 * One world card (stored once per sha256), with our route choices checked on it.
 */
class Card extends Model
{
    protected $table = 'sn34_cards';

    public $timestamps = false;

    protected $fillable = ['sha256', 'synced_at', 'source', 'our_choices', 'card', 'read_at'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime', 'our_choices' => 'array', 'read_at' => 'datetime'];
    }
}
