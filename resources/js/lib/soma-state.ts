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

// fullState() below gives the same states for the full evaluation; stateIn() picks the one of the round's current stage.

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

type FullLike = Pick<MinerRow, 'status' | 'stage2_score' | 'stage2' | 'overall' | 'full_progress'>;

/**
 * Where an entry stands in the full evaluation (2026-11-26; user: "우리 제출의 1의 본평가는 다 끝났고 2는 거의 끝나게
 * 되였는데 SOMA의 첫페지는 아직도 stage 2를 반영하고 있어"). Same states as stage 2:
 * - done     the platform scored it ("scored" with an overall) - the overall and the bands are final;
 * - scoring  every planned run has a result, the platform has not published the score yet;
 * - running  some runs have a result. The platform's status flips between "qualified" and "evaluating" while an entry
 *            runs (S2 on 2026-11-26: 506 of 766 runs scored, status "qualified"), so the runs decide, not the status;
 * - waiting  no full-evaluation run scored yet;
 * - out      did not pass stage 1 (or failed review / no key).
 */
export function fullState(m: FullLike): EntryState {
    if (entryState(m) === 'out') {
        return 'out';
    }
    if (m.status === 'scored' && m.overall !== null && m.overall !== undefined) {
        return 'done';
    }
    const p = m.full_progress;
    if (p && p.planned > 0 && p.graded >= p.planned) {
        return 'scoring';
    }
    if ((p && p.graded > 0) || m.status === 'evaluating') {
        return 'running';
    }

    return 'waiting';
}

/** Share of the planned full-evaluation runs that have a result (0 - 1). */
export function fullProgressShare(m: Pick<MinerRow, 'full_progress'>): number {
    const p = m.full_progress;

    return p && p.planned > 0 ? Math.min(1, p.graded / p.planned) : 0;
}

/** Which evaluation the round is in: stage 2 until the full evaluation starts, then the full evaluation. */
export type EvalStage = 'stage2' | 'full';

export const evalStageOf = (phase: string | null | undefined): EvalStage => (phase === 'full' || phase === 'scored' ? 'full' : 'stage2');

/** The entry's state in the round's current evaluation. */
export const stateIn = (m: FullLike, stage: EvalStage): EntryState => (stage === 'full' ? fullState(m) : entryState(m));

/** The entry's progress in the round's current evaluation (0 - 1). */
export const progressIn = (m: Pick<MinerRow, 'stage2' | 'full_progress'>, stage: EvalStage): number => (stage === 'full' ? fullProgressShare(m) : stage2Progress(m));

export const STATE_LABEL: Record<EntryState, string> = { done: 'done', scoring: 'scoring', running: 'running', waiting: 'waiting', out: 'out' };

/** The entry's page on the official SOMA dashboard (opened in a new tab). */
export function somaUrl(competition: number, hotkey: string): string {
    return `https://thesoma.ai/dashboard/miner/${competition}/${hotkey}`;
}
