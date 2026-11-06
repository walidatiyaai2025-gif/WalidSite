"""Mock ADB adversarial regression tests; not proof of a real device or APK."""
import hashlib
import io
import json
import tempfile
import unittest
from contextlib import redirect_stderr
from pathlib import Path
from subprocess import CompletedProcess
from unittest.mock import patch

from qa.android_device_smoke import DeviceSmokeError, main, smoke

SHA = "a" * 40
PACKAGE = "com.example.waleed_tech_companion"


class AndroidDeviceSmokeTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.apk = Path(temp.name) / "test.apk"
        self.apk.write_bytes(b"fixture-not-a-real-apk")
        self.digest = hashlib.sha256(self.apk.read_bytes()).hexdigest()
        self.kwargs = dict(package_id=PACKAGE, version_name="0.1.0", version_code=1,
                           source_sha=SHA, expected_sha256=self.digest,
                           serial="emulator-5554", confirm_install=True)
        self.requests = []

    def inspect(self, path, **kwargs):
        self.assertEqual(kwargs["kind"], "debug")
        self.assertNotEqual(path, self.apk)
        self.assertTrue(path.is_file())
        self.assertEqual(path.read_bytes(), self.apk.read_bytes())
        return dict(apk_integrity_verified=True, debug_signature_verified=True,
                    apk_signature_cryptographically_valid=True,
                    apk_sha256=self.digest, package_id=PACKAGE)

    def adb(self, argv, *, timeout=15):
        self.requests.append(tuple(argv))
        self.assertEqual(argv[:3], ["adb", "-s", "emulator-5554"])
        cmd = argv[3:]
        if cmd == ["get-state"]:
            value = "device\n"
        elif cmd == ["shell", "getprop", "sys.boot_completed"]:
            value = "1\n"
        elif cmd == ["shell", "pm", "path", PACKAGE]:
            value = "package:/data/app/app/base.apk\n" if getattr(self, "installed", False) else ""
        elif cmd[0] == "install":
            self.assertGreaterEqual(timeout, 90)
            self.assertTrue(cmd[-1].endswith("candidate.apk"))
            self.installed = True
            value = "Performing Streamed Install\nSuccess\n"
        elif cmd == ["shell", "am", "start", "-W", "-n", PACKAGE + "/.MainActivity"]:
            value = "Status: ok\nActivity: " + PACKAGE + "/.MainActivity\n"
        elif cmd == ["shell", "pidof", PACKAGE]:
            value = "1234\n"
        else:
            raise AssertionError(f"Unexpected simulated adb operation {cmd!r}")
        return CompletedProcess(argv, 0, value, "")

    def run_smoke(self, **changes):
        with patch("qa.android_device_smoke.inspect", side_effect=self.inspect), \
                patch("qa.android_device_smoke._command", side_effect=self.adb):
            return smoke(self.apk, **{**self.kwargs, **changes})

    def test_mock_transport_installs_and_launches_without_production_claim(self):
        receipt = self.run_smoke()
        self.assertTrue(receipt["device_install_launch_verified"])
        self.assertFalse(receipt["production_ready"])
        self.assertFalse(receipt["visual_fidelity_verified"])
        self.assertFalse(receipt["physical_device_type_verified"])
        self.assertFalse(receipt["distribution_signing_identity_verified"])
        self.assertEqual(receipt["apk_sha256"], self.digest)
        self.assertTrue(any(argv[3] == "install" for argv in self.requests))
        self.assertEqual(self.requests[-1][3:], ("shell", "pidof", PACKAGE))

    def test_refuse_mutation_without_serial_confirmation_or_safe_apk(self):
        cases = [("confirm_install", False), ("serial", "-s all"),
                 ("serial", "device; rm -rf /"), ("package_id", "invalid"),
                 ("expected_sha256", "1" * 63), ("expected_sha256", "F" * 64)]
        for key, value in cases:
            with self.subTest(key=key, value=value):
                with self.assertRaises(DeviceSmokeError):
                    self.run_smoke(**{key: value})
                self.assertEqual(self.requests, [])
        other = self.apk.with_name("link.apk")
        other.symlink_to(self.apk)
        with patch("qa.android_device_smoke.inspect",
                   side_effect=AssertionError("must not inspect")):
            with self.assertRaises(DeviceSmokeError):
                smoke(other, **self.kwargs)
        self.assertEqual(self.requests, [])

    def test_reject_tampered_native_receipt_before_adb(self):
        receipt = dict(apk_integrity_verified=True, debug_signature_verified=True,
                       apk_signature_cryptographically_valid=True,
                       apk_sha256=self.digest, package_id=PACKAGE)
        for changes in ({"apk_sha256": "f" * 64},
                        {"debug_signature_verified": False},
                        {"apk_signature_cryptographically_valid": False},
                        {"package_id": "com.other.app"},
                        {"apk_integrity_verified": False}):
            with self.subTest(changes=changes):
                with patch("qa.android_device_smoke.inspect",
                           return_value={**receipt, **changes}), \
                     patch("qa.android_device_smoke._command",
                           side_effect=AssertionError("adb executed")):
                    with self.assertRaises(DeviceSmokeError):
                        smoke(self.apk, **self.kwargs)
        self.assertEqual(self.requests, [])

    def test_offline_unbooted_and_unauthorized_devices_fail_before_install(self):
        for target, output in (("get-state", "offline"),
                               ("get-state", "unauthorized"),
                               ("getprop", "0")):
            self.requests.clear()

            def fail(argv, *, timeout=15):
                value = self.adb(argv, timeout=timeout)
                if target in argv:
                    value.stdout = output
                return value

            with self.subTest(target=target, output=output):
                with patch("qa.android_device_smoke.inspect", side_effect=self.inspect), \
                     patch("qa.android_device_smoke._command", side_effect=fail):
                    with self.assertRaises(DeviceSmokeError):
                        smoke(self.apk, **self.kwargs)
                self.assertFalse(any(argv[3] == "install" for argv in self.requests))

    def test_existing_app_must_opt_in_to_replace(self):
        self.installed = True
        with self.assertRaisesRegex(DeviceSmokeError, "allow-replace"):
            self.run_smoke()
        self.assertFalse(any(argv[3] == "install" for argv in self.requests))
        receipt = self.run_smoke(allow_replace=True)
        self.assertTrue(receipt["replaced_existing_install"])
        self.assertTrue(any(argv[3:5] == ("install", "-r") for argv in self.requests))

    def test_install_launch_and_process_fail_closed(self):
        for target, output in (("install", "Failed to install"),
                               ("am", "Status: error\nActivity: " + PACKAGE + "/.MainActivity"),
                               ("am", "Status: ok\nActivity: " + PACKAGE + "_malicious/.MainActivity"),
                               ("pidof", "")):
            self.requests.clear()
            self.installed = False

            def faulty(argv, *, timeout=15):
                value = self.adb(argv, timeout=timeout)
                if target == argv[3] or target in argv:
                    value.stdout = output
                return value

            with self.subTest(target=target):
                with patch("qa.android_device_smoke.inspect", side_effect=self.inspect), \
                     patch("qa.android_device_smoke._command", side_effect=faulty):
                    with self.assertRaises(DeviceSmokeError):
                        smoke(self.apk, **self.kwargs)

    def test_rejected_adb_stage_is_static_and_never_leaks_transport_output(self):
        for operation, stage in (("get-state", "get-state"),
                                 ("install", "install"),
                                 ("am", "launch"), ("pidof", "process-check")):
            self.requests.clear()
            self.installed = False

            def rejected(argv, *, timeout=15):
                result = self.adb(argv, timeout=timeout)
                if operation == argv[3] or operation in argv:
                    result.returncode = 1
                    result.stderr = "secret transport log: device serial and private path"
                return result

            with self.subTest(operation=operation):
                with patch("qa.android_device_smoke.inspect", side_effect=self.inspect), \\
                     patch("qa.android_device_smoke._command", side_effect=rejected):
                    with self.assertRaisesRegex(DeviceSmokeError, "\\\\[" + stage + "\\\\]") as caught:
                        smoke(self.apk, **self.kwargs)
                self.assertNotIn("secret transport log", str(caught.exception))
                self.assertNotIn("emulator-5554", str(caught.exception))

    def test_cli_reports_only_static_safe_device_failure_reason(self):
        with patch("qa.android_device_smoke.smoke",
                   side_effect=DeviceSmokeError("Android device command failed [launch]")), \\
             redirect_stderr(io.StringIO()) as errors:
            status = main(["--apk", str(self.apk), "--package-id", PACKAGE,
                           "--version-name", "0.1.0", "--version-code", "1",
                           "--source-sha", SHA, "--expected-sha256", self.digest,
                           "--serial", "secret-device"])
        self.assertEqual(status, 1)
        data = json.loads(errors.getvalue())
        self.assertEqual(data["reason"], "Android device command failed [launch]")
        self.assertNotIn("secret-device", errors.getvalue())
        self.assertNotIn(str(self.apk), errors.getvalue())

    def test_adb_unavailable_and_failed_cli_do_not_leak_serial_or_path(self):
        with patch("qa.android_device_smoke.inspect", side_effect=self.inspect), \
             patch("qa.android_device_smoke.subprocess.run",
                   side_effect=OSError("secret path")):
            with self.assertRaisesRegex(DeviceSmokeError, "unavailable"):
                smoke(self.apk, **self.kwargs)
        with redirect_stderr(io.StringIO()) as errors:
            status = main(["--apk", str(self.apk), "--package-id", PACKAGE,
                           "--version-name", "0.1.0", "--version-code", "1",
                           "--source-sha", SHA, "--expected-sha256", self.digest,
                           "--serial", "secret-device"])
        self.assertEqual(status, 1)
        payload = json.loads(errors.getvalue())
        self.assertFalse(payload["device_install_launch_verified"])
        self.assertNotIn(str(self.apk), errors.getvalue())
        self.assertNotIn("secret-device", errors.getvalue())


if __name__ == "__main__":
    unittest.main()
