#!/usr/bin/env python3
"""Validate owner-supplied, source-attributed CV baseline without private claims."""
import json
from pathlib import Path
p = Path(__file__).resolve().parents[1] / "contracts/owner-cv-baseline.json"
v = json.loads(p.read_text(encoding="utf-8"))
assert v["schema"] == "walidsite.owner-cv-baseline.v1"
assert v["identity"]["full_name"] == "Waleed Atiya Ata Atiya"
sources = {s["id"] for s in v["sources"]}
assert sources == {"cv_dba", "cv_pm"}
for section in ("experience", "certifications", "selected_projects"):
    ids = [entry.get("id",entry.get("title")) for entry in v[section]]
    assert len(ids) == len(set(ids)), f"duplicate items in {section}"
    for entry in v[section]:
        assert entry["source_refs"] and set(entry["source_refs"]) <= sources
for employment in v["experience"]:
    assert employment["period"]["start"].isdigit() and len(employment["period"]["start"]) == 4
    assert employment["roles"] and employment["organization"]
for credential in v["certifications"]:
    assert credential["source_type"] == "self-reported CV"
assert len(v["experience"]) == 4
assert {x["title"] for x in v["certifications"]} == {
    "PMP", "ITIL Expert V3", "MCSE SharePoint 2013",
    "MCSA Windows Server 2012", "MCTS SQL Server 2005"
}
assert v["publication_rules"]["first_run_seed"].startswith("T003 must import")
assert v["publication_rules"]["ai_updates"].startswith("Never overwrite")
assert all(p["visibility"] == "restricted_case_study" for p in v["selected_projects"])
print("PASS: owner CV is source-attributed, non-duplicated and safe for controlled first-run seeding")
