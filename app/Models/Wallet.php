<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    public $timestamps = false;

    /** Thousands of transfers = exchange or custodial (a weak link); a few hundred or fewer = personal (strong). */
    public const EXCHANGE_LIKE = 1500;

    public const PERSONAL = 300;

    protected $fillable = ['address', 'transfer_count', 'kind', 'checked_at'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public static function kindFor(?int $count): ?string
    {
        return match (true) {
            $count === null => null,
            $count >= self::EXCHANGE_LIKE => 'exchange-like',
            $count <= self::PERSONAL => 'personal',
            default => 'medium',
        };
    }

    public static function record(string $address, ?int $count): self
    {
        return self::updateOrCreate(['address' => $address], ['transfer_count' => $count, 'kind' => self::kindFor($count), 'checked_at' => now()]);
    }
}
