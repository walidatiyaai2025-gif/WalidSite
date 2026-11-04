# T026 / #27 — offline release artifact integrity gate

This engineering check verifies bytes **already built** by a trusted build pipeline. It is not a signing, installation, source-reproducibility, store-publishing or staging verification.

## Manifest format

Store the manifest independently from the binaries in a controlled output directory. Example (values are placeholders; never submit them as an actual production receipt):

```json
{
  "schema": "walidsite.release-artifact-inventory.v1",
  "version": "1.2.3",
  "source_sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "artifacts": [
    {"name": "walidsite-1.2.3.zip", "kind": "web-cpanel-zip", "bytes": 12345, "sha256": "0000000000000000000000000000000000000000000000000000000000000000"}
  ]
}
```

Allowed kinds: `web-cpanel-zip` (.zip), `android-apk` (.apk), `android-aab` (.aab), `ios-ipa` (.ipa). A real manifest **must** contain the observed byte length and full SHA-256 of each actual artifact. File names cannot be paths, absolute paths, dot entries or symlinks. The 40-character lower-case source revision must equal the independently recorded GitHub release candidate head.

Run offline after a real artifact build:

```bash
python3 qa/release_artifact_gate.py --dir /secure/artifacts \
  --manifest /secure/manifest.json --sha EXACT_GIT_SHA
python3 -m unittest qa.tests.test_visual_release_artifact_gate -v
```

The offline verifier requires POSIX no-follow descriptor support (the Linux/cPanel release QA environment). It opens the artifact directory once, hashes each whitelisted regular artifact by a single directory-relative file descriptor, and rejects swapped symlinks, symlink directory roots, nonregular files and changed file metadata during hashing. The inventory manifest can remain a separate file beside the artifacts as shown above; it is also bounded and read through one no-follow regular-file descriptor. This avoids pathname re-open races, but **does not authenticate the manifest or replace publisher signatures**. The publication and Admin update paths must independently verify the trusted Ed25519 publisher identity and package provenance. An offline checksum PASS never means production ready.

Exit 0 confirms only existence, expected size and digest of every explicitly inventoried artifact. Exit 1 means no verification and never includes raw file paths in the error receipt. Reports always leave `production_ready`, `signing_identity_verified`, `store_distribution_verified`, `staging_deployment_verified`, and `reproducible_build_verified` set to false: those require independent attestations. The native placeholder Android ID `com.example.waleed_tech_companion` and absent external signing identities remain release blockers; do not claim these are production signed files.

The pre-existing cPanel ZIP builder's internal `PACKAGE-MANIFEST.json` and `verify_archive` checks remain separate and mandatory. This outer inventory does not replace inspection of package contents, native signatures, real CI build provenance, store identities, privacy disclosures, DNS/TLS hosting, data backup/restore, or T025/T027 owner gates. Keep #27 OPEN until those have genuine evidence.
