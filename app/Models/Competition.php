<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Competition extends Model
{
    public $incrementing = false;

    protected $fillable = ['id', 'round', 'upload_deadline_at', 'evaluation_end_at', 'leaderboard_synced_at'];

    protected function casts(): array
    {
        return [
            'upload_deadline_at' => 'datetime',
            'evaluation_end_at' => 'datetime',
            'leaderboard_synced_at' => 'datetime',
        ];
    }

    public function miners(): HasMany
    {
        return $this->hasMany(Miner::class);
    }

    /**
     * Find or create a competition with the round and dates from config/soma.php.
     */
    public static function ensure(int $id): self
    {
        $meta = config("soma.competitions.$id", []);

        return self::updateOrCreate(['id' => $id], [
            'round' => $meta['round'] ?? null,
            'upload_deadline_at' => $meta['upload_deadline'] ?? null,
            'evaluation_end_at' => $meta['evaluation_end'] ?? null,
        ]);
    }
}
