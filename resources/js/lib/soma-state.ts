import type { MinerRow } from '@/types';

/**
 * Where an entry stands in stage 2, the same on every page (2026-11-25; user: "일부 채점이 되지 않은것들이 채점이 다 된것처럼
 * 나오고 있어"):
 * - done     every stage-2 task has all its planned runs scored - the score is final, the entry is ranked;
 * - scoring  the platform is past running it (status back to "qualifying") but some runs have no result yet - the score is
 *            partial (47 entries looked "done" this way on 2026-11-25 15:5x UTC, e.g. 25 of 40 runs scored on one task);
 * - running  stage 2 is running ("screening");
 * - waiting  passed stage 1, stage 2 not started;
 * - out      did not pass stage 1 (or failed review / no key).
 * A partial score (scoring, running) counts unscored tasks as -1, so it is never shown as a result nor ranked.
 */
export type EntryState = 'done' | 'scoring' | 'running' | 'waiting' | 'out';

type Stage2Like = Pick<MinerRow, 'status' | 'stage2_score' | 'stage2'>;

export function entryState(m: Stage2Like): EntryState {
    if (m.status === 'not qualified' || m.status === 'failed review' || m.status === 'no api key') {
        return 'out';
    }
    if (m.status === 'screening') {
        return 'running';
    }
    if (m.stage2_score === null || m.stage2_score === undefined) {
        return 'waiting';
    }
    const cells = Object.values(m.stage2 ?? {});
    const open = cells.some((c) => (c.total ?? 0) > 0 && (c.finished ?? 0) < (c.total ?? 0));

    return open ? 'scoring' : 'done';
}

/** Share of the planned stage-2 runs that have a result (0 - 1). */
export function stage2Progress(m: Pick<MinerRow, 'stage2'>): number {
    const cells = Object.values(m.stage2 ?? {});
    const total = cells.reduce((a, c) => a + (c.total ?? c.baseline_total ?? 0), 0);

    return total > 0 ? cells.reduce((a, c) => a + (c.finished ?? 0), 0) / total : 0;
}

export const STATE_LABEL: Record<EntryState, string> = { done: 'done', scoring: 'scoring', running: 'running', waiting: 'waiting', out: 'out' };

/** The entry's page on the official SOMA dashboard (opened in a new tab). */
export function somaUrl(competition: number, hotkey: string): string {
    return `https://thesoma.ai/dashboard/miner/${competition}/${hotkey}`;
}
