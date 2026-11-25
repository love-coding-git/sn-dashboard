<?php

namespace App\Models\Sn34;

use Illuminate\Database\Eloquent\Model;

/**
 * A counterparty wallet and its size: personal (few transfers) or exchange-like.
 */
class Wallet extends Model
{
    protected $table = 'sn34_wallets';

    public $timestamps = false;

    protected $fillable = ['address', 'transfer_count', 'kind', 'checked_at'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public static function kindFor(?int $count): ?string
    {
        return match (true) {
            $count === null => null,
            $count >= (int) config('sn34.wallet_exchange') => 'exchange-like',
            $count <= (int) config('sn34.wallet_personal') => 'personal',
            default => 'medium',
        };
    }

    public static function record(string $address, ?int $count): self
    {
        return self::updateOrCreate(['address' => $address], ['transfer_count' => $count, 'kind' => self::kindFor($count), 'checked_at' => now()]);
    }
}
