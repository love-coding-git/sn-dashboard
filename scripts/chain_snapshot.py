#!/usr/bin/env python3
"""Print one JSON snapshot of the netuid-114 metagraph read straight from the chain (for the Hotkeys page).

Run with the research workspace's Python (it has bittensor): /home/admin/projects/Ridges/soma-miner/.venv/bin/python scripts/chain_snapshot.py
Our hotkeys come from soma-miner/tools/prune_order.py (public *pub.txt files only), so this page and the
submissions watch name the same hotkeys. Read-only; prints nothing secret.
"""
from __future__ import annotations

import json
import os
import pathlib
import sys

# The app moved out of Ridges on 2026-11-25 (Ridges/soma-dashboard -> ~/projects/sn-dashboard): the research workspace is no
# longer a sibling, so its path comes from SOMA_MINER_DIR when set, else the fixed workspace path.
TOOLS = pathlib.Path(os.environ.get("SOMA_MINER_DIR") or "/home/admin/projects/Ridges/soma-miner") / "tools"
sys.path.insert(0, str(TOOLS))

import bittensor as bt  # noqa: E402
from prune_order import BANNED, NETUID, our_hotkeys  # noqa: E402


def main() -> None:
    st = bt.Subtensor(network="finney")
    block = st.block
    immunity = st.read("immunity_period", netuid=NETUID)
    reg = {int(k): int(v) for k, v in st.query_map(("SubtensorModule", "BlockAtRegistration"), [NETUID])}
    neurons = []
    for neuron in st.read("neurons", netuid=NETUID, lite=True):
        r = neuron.raw
        neurons.append({
            "uid": int(r["uid"]),
            "hotkey": r["hotkey"],
            "coldkey": r["coldkey"],
            "emission": int(r["emission"]),
            "registered_at_block": reg.get(int(r["uid"]), 0),
            # the chain hands a validator permit to the uids with the most stake, miners included; only a uid that
            # earns dividends is validating (2026-11-18: 13 permits, 10 validating)
            "validator": bool(r.get("validator_permit")),
            "validating": bool(r.get("validator_permit")) and int(r.get("dividends") or 0) > 0,
        })
    print(json.dumps({
        "netuid": NETUID,
        "block": int(block),
        "immunity_period": int(immunity),
        "neurons": neurons,
        "ours": our_hotkeys(),
        "notes": BANNED,
    }))


if __name__ == "__main__":
    main()
