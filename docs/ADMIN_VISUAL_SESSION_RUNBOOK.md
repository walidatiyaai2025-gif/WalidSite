# ADMIN-08–11: real authenticated visual capture session (Issue #481)

This runbook prepares an **actual** browser session against the local Laravel
application. It does not capture, compare or certify any screen by itself.
The independent canonical gate is `qa/capture_visual_evidence.py` with
zero differing RGBA pixels; every currently pending entry stays pending until
that proof is produced.

## Preconditions

- Check out the exact commit to be tested; run the Laravel app and its
  configured test database on `http://127.0.0.1:8000`. Never aim this
  credential-bearing helper at an Internet host, a public preview, or a
  production database.
- Provision a **real active Admin or owner account** through the application's
  documented secure account setup, with appropriate consent and non-personal
  test fixtures. This script does not seed users, invent credentials, use API
  bearer-token substitutes, skip CSRF, or bypass role middleware.
- Install Python Playwright and Chromium on the controlled QA machine:
  `python3 -m pip install playwright && python3 -m playwright install chromium`.
- Ensure no source tree changes and check `git rev-parse HEAD` before using
  the visual evidence admission command.

## Start and verify the temporary session

```bash
export WALIDSITE_QA_ADMIN_ORIGIN=http://127.0.0.1:8000
export WALIDSITE_QA_ADMIN_LOCALE=en
read -r -p "QA Admin email: " WALIDSITE_QA_ADMIN_EMAIL
export WALIDSITE_QA_ADMIN_EMAIL
read -r -s -p "QA Admin password: " WALIDSITE_QA_ADMIN_PASSWORD
echo
export WALIDSITE_QA_ADMIN_PASSWORD
python3 qa/admin_visual_session.py
unset WALIDSITE_QA_ADMIN_PASSWORD WALIDSITE_QA_ADMIN_EMAIL
```

The output prints only the path to a temporary private Playwright
`storage-state.json`. The directory is mode 0700, the state file 0600.
The helper performs the real login with Laravel's hidden CSRF field and
verifies HTTP 200, exact route, HTML locale/direction, and protected content
on **all four** `/admin/github`, `/admin/projects`,
`/admin/audience`, `/admin/analytics` pages. Wrong credentials, missing
authorization, redirects, unknown locales and failed responses are rejected.
No authenticated state is retained on a failed attempt.

Record the absolute path from the output **locally**, not in a commit, PR,
issue, screenshots, terminal transcript or CI artifact:

```bash
export WALIDSITE_CAPTURE_STORAGE_STATE=/private/temporary/path/storage-state.json
export WALIDSITE_RENDER_BASE_URL=http://127.0.0.1:8000
sha="$(git rev-parse HEAD)"
# Use a trusted local plan with canonical ADMIN-08..11 route/locale/DPR entries,
# each renderer_command = ["python3", "qa/render_admin_screenshot.py"].
python3 qa/visual_diagnostics.py --plan /private/temporary/admin-plan.json --commit "$sha"
# A mismatch diagnostic is NOT a passed visual comparison or an admitted capture.
# The same trusted plan is eligible for strict zero-difference evidence admission
# only after the real renderer is proven correct:
# python3 qa/capture_visual_batch.py --plan /private/temporary/admin-plan.json --commit "$sha"
```

The independently merged-on-this-branch Admin renderer `qa/render_admin_screenshot.py` requires
`WALIDSITE_CAPTURE_STORAGE_STATE` to render protected pages. Never share its
contents. No renderer is allowed to reinterpret a login screen as an Admin
screenshot. Run the strict visual evidence admission only once a genuine
render has been investigated and is an exact RGBA match; preserve all 40
canonical references without masking or changing thresholds.

## Dispose and report

Remove the state file and its **specific** temporary directory after the
capture. For example, after confirming the path is the helper's output:

```bash
rm -- "$WALIDSITE_CAPTURE_STORAGE_STATE"
rmdir -- "$(dirname "$WALIDSITE_CAPTURE_STORAGE_STATE")"
unset WALIDSITE_CAPTURE_STORAGE_STATE
```

If any real screen still differs, leave its ledger entry **pending**.
Record the actual source SHA, route, viewport/DPR, locale, non-sensitive
mismatch counts and CI evidence in Issue #481; never paste authenticated
cookies, private client records, source portrait substitutes, or credentials.
Cross-device Flutter and final 40/40 attestation remain separate.
