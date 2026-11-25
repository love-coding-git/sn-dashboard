<?php

namespace App\Jobs\Soma;

use App\Services\Soma\Earnings;
use App\Services\Soma\Families;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Read mining rewards from Taostats: a whole subnet day (`$day` set) or one hotkey's history on a subnet (`$hotkey` set).
 */
class FetchEarnings implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(public int $netuid, public ?string $day = null, public ?string $hotkey = null)
    {
        $this->onQueue('profile');    // shares the Taostats rate limit with owner tracing
    }

    public function uniqueId(): string
    {
        return $this->netuid.':'.($this->day ?? $this->hotkey);
    }

    public function handle(Earnings $earnings, Families $families): void
    {
        if ($this->day) {
            $earnings->subnetDay($this->netuid, CarbonImmutable::parse($this->day, 'UTC'));
        } elseif ($this->hotkey) {
            $earnings->hotkeyHistory($this->netuid, $this->hotkey);
        }
        $families->refresh();    // new earners become owners with a family name
    }
}
