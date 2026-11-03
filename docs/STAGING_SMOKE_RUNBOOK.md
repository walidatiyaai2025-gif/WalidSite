# T025 — Read-only staging smoke gate (#26)

After an immutable release is built, its integrity checked and its Laravel database migrated on an **authorized public HTTPS staging host**, run:

```bash
python3 -B qa/staging_smoke.py --url https://staging.your-public-domain.com \
  --approved-host staging.your-public-domain.com \
  --output /secure/evidence/walidsite-staging-smoke.json
```

The runner accepts **no credentials**, does not weaken TLS validation, does not follow redirects, and issues nine **GET-only** checks: Laravel `/up`, the public home page, Admin login, the disabled `/setup` path, anonymous bootstrap/home/projects JSON, and denied guest mobile/admin API calls. It rejects private/loopback IP literals, non-public DNS suffixes, embedded credentials, arbitrary paths and query strings. The required `--approved-host` must exactly match the URL hostname, so accidental target swaps fail before any request. **This is only operator allowlisting, not proof of a public DNS result or network isolation.** A public-looking hostname could resolve to loopback/private/link-local addresses or change after resolution. Run the command **only from an operator-managed egress-restricted network** that blocks RFC1918, loopback, link-local, metadata services and other unauthorized destinations through a verified firewall or proxy policy. Do not run it with untrusted DNS or a compromised proxy. The script does not itself attest those network controls.

A receipt contains observed HTTP statuses and generic error codes only, never response bodies, credentials, PII or tokens. Exit **0** means the nine HTTP checks passed, **1** means a failed check, and **2** means invalid input or report write failure. Redirects, TLS errors, exposed setup, an accidentally public private API, malformed JSON and 5xx are failures. Existing receipt files are never overwritten. Archive the receipt privately with the external deployment change ticket, checked artifact SHA, environment record and installation time.

**No deployment or release readiness is certified by this tool.** It cannot verify the installed commit SHA, actual DB backup/restore, migrations, queue workers, mail delivery, AI services, screen pixel fidelity, Flutter devices, rollback or owner acceptance. The output always says `network_egress_isolation_verified=false`, `production_ready=false` and `deployment_release_sha_verified=false`, `backup_restore_verified=false`, `rollback_verified=false`. Each still requires separate, independently witnessed real staging evidence, T024/G-01..G-20 acceptance, and T026/T027 release/owner gates. Keep Issue #26 open until those pass.

Repository CI runs only the **synthetic, offline** `qa.tests.test_staging_smoke` regression tests; it never accesses a staging host. No third workflow is introduced. A complete rehearsal must deploy a versioned artifact, inspect migration state, take and restore a verified private backup on an isolated instance, run authenticated functional/security checks, verify mail/AI truthfully, execute this read-only smoke check, and conduct a timed rollback to a tested previous release without data loss.
