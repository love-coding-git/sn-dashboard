<?php

namespace Tests\Feature\Soma;

use App\Models\Competition;
use App\Models\Miner;
use App\Models\Owner;
use App\Models\Transfer;
use App\Services\Soma\Families;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyNamesTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $prefix, int $entries = 0): string
    {
        $coldkey = str_pad($prefix, 48, 'x');
        Owner::ensure($coldkey);
        $c = Competition::ensure(149);
        for ($i = 0; $i < $entries; $i++) {
            Miner::create(['competition_id' => $c->id, 'hotkey' => "$prefix-$i", 'coldkey' => $coldkey]);
        }

        return $coldkey;
    }

    private function nameOf(string $coldkey): ?string
    {
        return Owner::where('coldkey', $coldkey)->value('family_name');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['soma.family_merges' => [], 'soma.family_names' => [], 'soma.dossier_dir' => '/nonexistent', 'soma.family_link_wallet_kinds' => []]);
    }

    public function test_a_name_freed_by_a_merge_is_not_given_to_another_family(): void
    {
        $a = $this->owner('5AAAAAAA', 2);
        $b = $this->owner('5BBBBBBB', 1);
        (new Families)->refresh();
        [$na, $nb] = [$this->nameOf($a), $this->nameOf($b)];
        $this->assertNotSame($na, $nb);

        config(['soma.family_merges' => ['5BBBBBBB' => '5AAAAAAA']]);
        (new Families)->refresh();
        $merged = $this->nameOf($a);
        $this->assertSame($merged, $this->nameOf($b));
        $freed = $merged === $na ? $nb : $na;

        $c = $this->owner('5CCCCCCC', 1);
        (new Families)->refresh();
        $this->assertNotSame($freed, $this->nameOf($c));
        $this->assertNotSame($merged, $this->nameOf($c));
    }

    public function test_a_family_keeps_its_name_when_its_root_changes(): void
    {
        $a = $this->owner('5AAAAAAA', 3);
        $b = $this->owner('5BBBBBBB', 1);
        $d = $this->owner('5DDDDDDD', 1);
        foreach ([[$a, $b], [$a, $d]] as $i => [$from, $to]) {       // direct transfers link them (no merge target to prefer)
            Transfer::create(['external_id' => "t$i", 'from_address' => $from, 'to_address' => $to, 'amount_tao' => 1.0]);
        }
        (new Families)->refresh();
        $this->assertSame('5AAAAAAA', Owner::where('coldkey', $b)->value('family'));
        $name = $this->nameOf($a);

        // the root is the owner with the most entries: give B more and the family key moves to B
        $c = Competition::ensure(149);
        for ($i = 0; $i < 5; $i++) {
            Miner::create(['competition_id' => $c->id, 'hotkey' => "5BBBBBBB-x$i", 'coldkey' => $b]);
        }
        (new Families)->refresh();
        $this->assertSame('5BBBBBBB', Owner::where('coldkey', $a)->value('family'));
        $this->assertSame($name, $this->nameOf($a));
        $this->assertSame($name, $this->nameOf($d));
    }
}
