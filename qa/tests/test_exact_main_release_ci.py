"""#1120: release selection must never combine check results across CI runs."""
import contextlib
import io
import json
import tempfile
import unittest
from pathlib import Path

from qa.verify_exact_main_release_ci import (
    REQUIRED_JOBS, ReleaseCIError, main, select_exact_main_ci, verify_jobs,
)

SHA = 'a' * 40
OTHER = 'b' * 40
REPO = 'walidatiyaai2025-gif/WalidSite'
RUN_ID = 12345


def run(*, run_id=RUN_ID, sha=SHA, branch='main', event='push', status='completed',
        conclusion='success', path='.github/workflows/ci.yml', repo=REPO):
    return {'id': run_id, 'run_attempt': 1, 'name': 'PR CI', 'head_sha': sha,
            'head_branch': branch, 'event': event, 'status': status,
            'conclusion': conclusion, 'path': path,
            'repository': {'full_name': repo}, 'head_repository': {'full_name': repo}}


def jobs(*, run_id=RUN_ID, sha=SHA):
    items = [{'name': name, 'run_id': run_id, 'head_sha': sha,
              'status': 'completed', 'conclusion': 'success'} for name in REQUIRED_JOBS]
    return {'total_count': len(items), 'jobs': items}


class ExactMainCIReleaseGateTest(unittest.TestCase):
    def setUp(self):
        self.runs = {'total_count': 1, 'workflow_runs': [run()]}
        self.jobs = jobs()

    def test_one_complete_main_push_run_passes_without_production_claim(self):
        self.assertEqual(select_exact_main_ci(self.runs, SHA, REPO), RUN_ID)
        receipt = verify_jobs(self.jobs, sha=SHA, run_id=RUN_ID)
        self.assertTrue(receipt['same_run_success_verified'])
        self.assertFalse(receipt['production_ready'])
        self.assertFalse(receipt['publisher_signature_verified'])
        self.assertFalse(receipt['live_deployment_verified'])

    def test_older_success_must_not_override_new_failed_or_in_progress_run(self):
        for status, conclusion in [('completed', 'failure'), ('in_progress', None),
                                   ('completed', 'cancelled')]:
            with self.subTest(status=status, conclusion=conclusion):
                latest = run(run_id=RUN_ID + 1, status=status, conclusion=conclusion)
                with self.assertRaises(ReleaseCIError):
                    select_exact_main_ci({'total_count': 2,
                                          'workflow_runs': [run(), latest]}, SHA, REPO)

    def test_reject_cross_commit_event_branch_path_and_repository(self):
        for change in ({'sha': OTHER}, {'branch': 'feature'}, {'event': 'pull_request'},
                       {'path': '.github/workflows/release.yml'},
                       {'repo': 'bad/other'}):
            with self.subTest(change=change), self.assertRaises(ReleaseCIError):
                select_exact_main_ci({'total_count': 1, 'workflow_runs': [run(**change)]},
                                     SHA, REPO)

    def test_reject_partial_or_paginated_run_evidence(self):
        for bad in ({'total_count': 2, 'workflow_runs': [run()]},
                    {'total_count': 101, 'workflow_runs': [run()]},
                    {'total_count': 0, 'workflow_runs': [run()]},
                    {'total_count': 1, 'workflow_runs': ['bad']},
                    {'total_count': 1, 'workflow_runs': [run(run_id=0)]}):
            with self.subTest(bad=bad), self.assertRaises(ReleaseCIError):
                select_exact_main_ci(bad, SHA, REPO)

    def test_missing_duplicate_skipped_failed_or_mixed_run_jobs_block(self):
        cases = []
        missing = jobs(); missing['jobs'].pop(); missing['total_count'] -= 1
        cases.append(missing)
        duplicate = jobs(); duplicate['jobs'].append(dict(duplicate['jobs'][0])); duplicate['total_count'] += 1
        cases.append(duplicate)
        skipped = jobs(); skipped['jobs'][1]['conclusion'] = 'skipped'; cases.append(skipped)
        failed = jobs(); failed['jobs'][0]['conclusion'] = 'failure'; cases.append(failed)
        pending = jobs(); pending['jobs'][0]['status'] = 'in_progress'; cases.append(pending)
        different_run = jobs(); different_run['jobs'][0]['run_id'] = RUN_ID + 1; cases.append(different_run)
        different_sha = jobs(); different_sha['jobs'][0]['head_sha'] = OTHER; cases.append(different_sha)
        incomplete_page = jobs(); incomplete_page['total_count'] += 1; cases.append(incomplete_page)
        for bad in cases:
            with self.subTest(bad=bad), self.assertRaises(ReleaseCIError):
                verify_jobs(bad, sha=SHA, run_id=RUN_ID)

    def test_cli_two_phases_enforce_same_run_and_redact_failures(self):
        with tempfile.TemporaryDirectory() as directory:
            runs_path = Path(directory) / 'runs.json'
            jobs_path = Path(directory) / 'jobs.json'
            runs_path.write_text(json.dumps(self.runs))
            jobs_path.write_text(json.dumps(self.jobs))
            args = ['--runs', str(runs_path), '--sha', SHA, '--repo', REPO]
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                self.assertEqual(main(args), 0)
            self.assertEqual(output.getvalue().strip(), str(RUN_ID))
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                self.assertEqual(main(args + ['--jobs', str(jobs_path), '--run-id', str(RUN_ID)]), 0)
            self.assertEqual(json.loads(output.getvalue())['ci_run_id'], RUN_ID)
            error = io.StringIO()
            with contextlib.redirect_stderr(error):
                self.assertEqual(main(args + ['--jobs', str(jobs_path), '--run-id', str(RUN_ID + 1)]), 2)
            self.assertNotIn(REPO, error.getvalue())
            self.assertNotIn(str(runs_path), error.getvalue())

    def test_release_workflow_calls_exact_run_gate_and_checks_all_native_jobs(self):
        text = (Path(__file__).resolve().parents[2] / '.github/workflows/release.yml').read_text()
        self.assertIn('qa/verify_exact_main_release_ci.py', text)
        self.assertIn('actions/runs/${ci_run_id}/jobs?per_page=100', text)
        self.assertIn('id: verified-ci', text)
        self.assertIn('CI_RUN_ID: ${{ steps.verified-ci.outputs.ci_run_id }}', text)
        self.assertIn('gh run download "$CI_RUN_ID"', text)
        self.assertNotIn('commits/${TESTED_SHA}/check-runs?', text)
        workflow = (Path(__file__).resolve().parents[2] / '.github/workflows/ci.yml').read_text()
        self.assertIn('qa.tests.test_exact_main_release_ci', workflow)

    def test_automatic_candidate_chain_is_exact_main_only_and_never_publishes(self):
        release = (Path(__file__).resolve().parents[2] / '.github/workflows/release.yml').read_text()
        self.assertIn('workflow_run:', release)
        self.assertIn('workflows: ["PR CI"]', release)
        self.assertIn('types: [completed]', release)
        self.assertIn('github.event.workflow_run.event == \'push\'', release)
        self.assertIn('github.event.workflow_run.head_branch == \'main\'', release)
        self.assertIn('github.event.workflow_run.head_repository.full_name == github.repository', release)
        self.assertIn('github.event.workflow_run.conclusion == \'success\'', release)
        self.assertIn("github.event_name == 'workflow_dispatch'", release)
        self.assertIn('github.event.workflow_run.head_sha || github.sha', release)
        self.assertIn('TESTED_SHA: ${{ github.event_name ==', release)
        self.assertIn('test "$(git ls-remote origin refs/heads/main | cut -f1)" = "$TESTED_SHA"', release)
        self.assertIn('source_sha: ${{ steps.verified-ci.outputs.source_sha }}', release)
        self.assertIn('version: ${{ steps.verified-version.outputs.version }}', release)
        self.assertIn("version=\"$(tr -d '\\r\\n' < backend/VERSION)\"", release)
        self.assertIn('test "$REQUESTED_VERSION" = "$version"', release)
        self.assertIn('CANDIDATE_VERSION: ${{ steps.verified-version.outputs.version }}', release)
        self.assertIn('SOURCE_SHA: ${{ steps.verified-ci.outputs.source_sha }}', release)
        self.assertIn('if: github.event_name == \'workflow_dispatch\'', release)
        self.assertIn('production_ready', release)
        self.assertIn('UNPUBLISHED-candidates-', release)
        self.assertNotIn('gh release create', release)
        self.assertNotIn('SITE_RELEASE_CRON_APPLY_ENABLED=true', release)


    def test_unified_release_negative_archive_proof_matches_php_gate(self):
        root = Path(__file__).resolve().parents[2]
        release = (root / '.github/workflows/release.yml').read_text()
        native_verifier = (root / 'qa/verify_release_android_qa.py').read_text()
        signed_probe = (root / 'qa/cpanel_signed_update_artifact_probe.php').read_text()
        # Avoid a regression where the real ZIP validator and unified native
        # receipt accept seven attacks but the Release workflow requires four.
        self.assertIn("assert result['php_zip_negative_rejections'] == 7", release)
        self.assertNotIn("assert result['php_zip_negative_rejections'] == 4", release)
        self.assertIn('php["negative_rejections"] != 7', native_verifier)
        self.assertIn('"php_zip_negative_rejections": php["negative_rejections"]', native_verifier)
        self.assertIn("'negative_rejections' => 7", signed_probe)



if __name__ == '__main__':
    unittest.main()
