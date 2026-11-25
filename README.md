# SOMA Dashboard

A local mirror of the public SOMA (Bittensor SN114) dashboard that shows a whole competition on one screen, plus the
competition watcher that runs on the Laravel queue. Laravel 13 · React 19 · Inertia 3 · shadcn/ui · MySQL.

- **Overview** (`/soma`, the round the watcher follows - `config/soma.php` `competition`, 151 = round 14 since 2026-11-18 - its stage 1 is the same five round-12 SOMA tasks as round 13's, `stage1_by_competition`; `/soma?comp=150` for round 13, `/soma?comp=149` for round 12, `/soma?comp=148` for round 11): every entry × the five stage-1 problems (passes against the baseline, score,
  tokens, steps), the stage-1 fingerprint against the reference miner, owner and family, the same hotkey's earlier
  rounds, our entries (S1…), watcher events, and a table of owner families with their earlier rounds, other subnets
  and the links between owners. Refreshes itself every minute.
  It follows the competition's phase: during stage 2 and the full evaluation the leaderboard lists the entries that
  advanced first, by stage-2 rank, with a line at the stage-2 cut (max(60, 20%) of the stage-1 passers). The cards show
  stage-2 score, rank and full-evaluation progress. The **Stage 2** tab shows every entry × the six stage-2 tasks, and
  the **Full evaluation** tab shows the advanced entries × the full-evaluation tasks with their run progress. The
  platform's partial overall during the full evaluation is shown as "running", not as a score. Tasks a page no longer
  lists, such as after a rolled-back selection, are dropped on the next read.
- **Family** (click any row of the Leaderboard, Stage 1, Stage 2, Full evaluation, Families, Earnings or Earlier rounds tables;
  the address keeps it as `?tab=family&fam=<family key>`): that family's entries in this competition in the same table as our
  entries (bands, stage 2 and its tasks, stage 1 and its problems, fingerprint, upload time), ordered by the rank of the current
  phase, under a header with its owners, entries by status, best entry now, earlier rounds, SN114 earnings and link evidence. A
  click on a hotkey link still opens the miner page. Before the upload deadline the page is always in the stage-1 phase: in
  rounds 13 and 14 an entry that passed stage 1 is "qualified", which used to read as a running full evaluation. The stage-2 cut
  line is round 13's rule; round 14's stage 2 is not settled yet (the team may fully evaluate every stage-1 passer).
- **Our hotkeys** (`/soma/hotkeys`, sidebar "Our hotkeys"; user 2026-11-18): our SN114 hotkeys read straight from the chain every 10 min -
  registration, uid, emission, place in the deregistration queue (lowest emission first, then the earliest registration; immune uids
  skipped - our reading of the chain rule, the same as `../soma-miner/tools/prune_order.py`), the change against 24 h earlier, time left
  at the last day's and the 7-day registration pace, and each hotkey's entries in every round. Tabs: Our hotkeys · Deregistration queue
  (all 256 uids in order, ours highlighted, families from the owner tracing) · Place history (one reading per hour, 7 days). Our hotkeys
  are the public `*pub.txt` files that prune_order.py reads, so the page and the submissions watch (Telegram) name the same ones.
- **Miner** (`/soma/{competition}/{hotkey}`): every task with its runs, the fingerprint, the owner (age, balance,
  registrations on every subnet, TAO transfers), the family (direct transfers, shared wallets, sibling entries) and
  every earlier-round entry of the hotkey and its family.
- Earlier competitions (rounds 7–10: 112, 145, 146, 147) come from the files already collected in `../soma-miner/intel`; round 11 (148) was
  followed live by the watcher (2026-10-12 → 2026-10-21) and stays in MySQL. To follow a new round: add it to `competitions` and set
  `competition` in `config/soma.php`, add it to the sidebar (`resources/js/components/app-sidebar.tsx`, then `npm run build`), and
  `bin/soma-watch restart`.

## Watcher (Laravel queue)

| Job | Queue | When | Does |
|---|---|---|---|
| `SyncLeaderboard` | default | every 5 min (scheduler) | reads the leaderboard; records `upload` (new hotkey or new lastSubmit), `stage1_done` (left screening) and `status` events; queues a page read for changed and screening entries, and an owner lookup for entries without an owner |
| `SyncMinerTasks` | default | queued by the job above | reads one miner page (tasks and runs) and computes its fingerprint once all five stage-1 problems have finished |
| `SyncMetagraph` | default | every 10 min | reads the netuid-114 metagraph (Taostats); records `registration` events; links miners to coldkeys; queues owners to trace (new ones, then up to 3 of the current competition whose trace is older than `reprofile_days`, default 3) and up to 10 owner lookups |
| `ResolveOwner` | resolve | queued by both polls above | an entry whose hotkey left the metagraph before a poll saw it has no coldkey: reads the hotkey's SN114 registration on Taostats, sets the coldkey, queues the owner's trace (each hotkey at most once per 15 min) |
| `SyncHotkeys` | default | every 10 min | runs `scripts/chain_snapshot.py` on `../soma-miner/.venv/bin/python` (bittensor, about 5 s): the netuid-114 metagraph from the chain; stores a snapshot (`chain_snapshots` - the full uid list kept for a day) and the state of each of our hotkeys (`hotkey_states`) |
| `ProfileOwner` | owners | queued on new and stale owners | traces an owner on Taostats: account age and balance, registrations on every subnet, transfers (the newest page and the oldest ones, up to 5 pages of 200 - the funding sits on the last pages), kind of every wallet it shares with another owner; then recomputes families |

The Taostats worker reads its queues in order - `resolve`, `owners`, then `profile` (earnings) - so owner lookups never wait behind earnings reads (2026-11-11: a lookup for the stage-1 leader waited over an hour behind 268 of them on one shared queue). The header counts failed jobs of the last 24 h, the owners of this competition traced, and entries whose owner is still unknown.

Families: owners linked by a direct transfer, or merged by hand in `config/soma.php` (`family_merges`), form one
family. A wallet shared with another owner is shown as evidence: personal (≤ 300 transfers) is a medium link,
exchange-like (≥ 1,500) is ignored. The **Families** tab follows the phase too: entries by status, and the family's
best entry - stage 1 while uploads run, stage 2 while it runs, then the full evaluation (overall, best per band and best per two-band element S+M · S+L · M+L - one entry's mean
of its two bands, as the payout scores an element - with the platform place; a running estimate is marked ≈ while an entry is still evaluated; failed review never counts).
Owner labels come from `config/soma.php` (`owner_labels`) when an owner is first seen; after changing one, copy it to
the existing owners (the `owners.label` column).

## Run

```bash
bin/soma-watch start     # queue workers (default, profile), scheduler, web server on http://127.0.0.1:8000
bin/soma-watch status
bin/soma-watch stop
php artisan soma:sync --now          # poll once now
php artisan soma:wait-event          # block until the watcher records a new event, print it, exit
php artisan soma:import              # (re)load the collected files from ../soma-miner/intel (idempotent)
php artisan soma:hotkeys --now       # read our hotkeys from the chain now (the Hotkeys page)
php artisan soma:owners              # now: owner lookups for every entry without one + re-trace every stale owner (profile queue, ~1/min)
```

The web server listens on 127.0.0.1 only and the pages need no login - do not serve it on a public address.
To view it from another computer, tunnel over SSH and open http://localhost:8000/soma there:

```bash
ssh -N -L 8000:127.0.0.1:8000 <user>@<server>   # if local port 8000 is taken: -L 8080:127.0.0.1:8000
```

Taostats calls use the `/v1` API (`/v1/subnets/metagraph`, `/v1/subnets/metagraph/history`, `/v1/accounts/{address}`,
`/v1/subnets/neuron-registration-events`, `/v1/transfers`; spec https://api.taostats.io/openapi.json). The old `/api/...` paths
answer 410 since 2026-11-16 22:xx UTC; field names and units did not change, but pages hold at most 200 rows. A failed call is
logged as `soma: taostats <path> answered <status>`, and an owner trace stops on one instead of storing blanks.

The Taostats key is read at run time from `../.env` (`TAO_API_KEY`, config `soma.keys_env`) and is never stored or
logged. Logs: `storage/logs/{queue-default,queue-profile,schedule,web,laravel}.log`.

## Develop

```bash
npm run build            # or: composer run dev
npm run types:check
php artisan test
php artisan wayfinder:generate --with-form   # after changing routes
```
