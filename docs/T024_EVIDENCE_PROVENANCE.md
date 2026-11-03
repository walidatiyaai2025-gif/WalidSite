# T024 integrated QA proof provenance — fail-closed source binding

**Owner:** T024 / Issue #25, LEAD. This document specifies validation of independently produced evidence; it does not claim that QA checks or screenshots have passed.

## Threat model

An artifact can have a correct SHA-256 hash while its `source_sha` is fabricated, references an unrelated Git repository, or identifies a product revision that was modified after the test. A valid hash only demonstrates integrity of the artifact's current bytes; it **does not** establish its runtime origin or certify an end-to-end journey.

`qa/t024_readiness.py` therefore requires, **when a proof is actually presented for an action, API method or internal G-gate**, all of the following:

1. Existing on-repository evidence in `qa/t024-evidence/`, with the pinned SHA-256, strict schema and no symlink components.
2. An exact local Git repository root, not a subfolder accidentally borrowing a parent repository.
3. A real 40-character commit object reachable as an ancestor of the checkout's `HEAD`. This allows attaching test output in a later evidence-only commit.
4. No source drift between the tested commit and checked-out HEAD in `backend/`, `mobile/`, `api/`, `contracts/`, `database/`, `assets/`, `locales/`, `qa/`, `.github/workflows/` or `design/tokens.json`. Only the evidence/capture/diagnostic subfolders under `qa/` are excluded from this drift check.
5. No staged, unstaged or untracked code inside those source paths. Git failures/timeouts, unrelated revisions and absent source objects reject a proof. Checks are cached per tested SHA for the current audit call; no network requests or shell interpretation occur.

This protects evidence from being counted against materially changed application/runtime code. It deliberately does **not** treat an ancestry check as proof of legitimate captures: humans and independent browser/device/HTTP/security tests must validate origin, test identifiers, results and provenance before adding entries. The 40 canonical screen matches require separate genuine decoded-RGBA validation under Issue #481. External staging/release G-12 and owner handoff G-20 remain deferred to #26 and #28.

## Verification and release behavior

```sh
python3 -m unittest qa.tests.test_visual_t024_readiness -v
python3 qa/t024_readiness.py
python3 qa/t024_readiness.py --require-ready
```

The normal report remains usable with every proof pending even if not executed inside a Git checkout. With any claimed proof, Git provenance must be verifiable; the audit fails closed for missing source, changed application code, or altered artifact bytes. The `--require-ready` command remains **red** until independent evidence exists for all 40 visuals, 97 screen/action pairs, 46 API operations and 18 internal G-gates; G-12/G-20 retain their external owners.

Temporary repositories and fake payloads inside unit tests are synthetic safety fixtures only and must never be copied into `design/t024-gate-evidence.json` as actual product evidence. No generated data in the tests represents a real GitHub user, provider, production server, deployment, verified image or passed acceptance gate.
