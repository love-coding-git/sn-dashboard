<?php

namespace App\Services\Soma;

/**
 * The stage-1 problems of a competition. Rounds 7-12 screened on the same five public SWE-bench problems (config
 * soma.stage1_problems and its short / longer split); rounds 13 and 14 (competitions 150, 151) screen on the same five
 * round-12 SOMA tasks (config soma.stage1_by_competition). A competition without its own entry falls back to the SWE-bench five.
 */
final class Stage1
{
    /**
     * @return array{problems: list<string>, short: list<string>, longer: list<string>}
     */
    public static function lists(?int $competitionId = null): array
    {
        $own = $competitionId === null ? null : (config('soma.stage1_by_competition', [])[$competitionId] ?? null);

        return [
            'problems' => array_values($own['problems'] ?? config('soma.stage1_problems')),
            'short' => array_values($own['short'] ?? config('soma.short_problems')),
            'longer' => array_values($own['longer'] ?? config('soma.longer_problems')),
        ];
    }

    /**
     * @return list<string>
     */
    public static function problems(?int $competitionId = null): array
    {
        return self::lists($competitionId)['problems'];
    }
}
