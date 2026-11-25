<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Owner extends Model
{
    protected $fillable = ['coldkey', 'family', 'family_name', 'label', 'created_on', 'balance_tao', 'profiled_at'];

    protected function casts(): array
    {
        return ['created_on' => 'date', 'profiled_at' => 'datetime', 'balance_tao' => 'float'];
    }

    public function miners(): HasMany
    {
        return $this->hasMany(Miner::class, 'coldkey', 'coldkey');
    }

    /**
     * Family key: the first eight characters of the coldkey, merged for owners linked by direct transfers.
     */
    public static function familyOf(string $coldkey): string
    {
        $short = substr($coldkey, 0, 8);

        return config("soma.family_merges.$short", $short);
    }

    public static function ensure(string $coldkey): self
    {
        $family = self::familyOf($coldkey);

        return self::firstOrCreate(['coldkey' => $coldkey], [
            'family' => $family,
            'label' => config('soma.owner_labels.'.substr($coldkey, 0, 8)) ?? config("soma.owner_labels.$family"),
        ]);
    }
}
