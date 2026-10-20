"""Exercise the diagnostic transport without production credentials or a network."""
import base64
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / ".github/scripts/pop-mount-policy-probe.sh"
REMOTE = ROOT / ".github/scripts/pop-mount-policy-readonly.sh"
SENTINEL = "PRIVATE_POLICY_SENTINEL_DO_NOT_LOG"


class PolicyProbeTest(unittest.TestCase):
    def run_probe(self, status=0, host="pi.lifenet.com.tw", expired=False, mode="probe-policy", complete=True):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        root = Path(temp.name)
        bindir = root / "bin"
        bindir.mkdir()
        ssh = bindir / "ssh"
        ssh.write_text("#!/usr/bin/env python3\nimport sys,os,json,hashlib,pathlib\n"
                       "data=sys.stdin.buffer.read()\n"
                       "pathlib.Path(os.environ['CALL']).write_text(json.dumps({'args':sys.argv[1:],'sha256':hashlib.sha256(data).hexdigest()}))\n"
                       "print(os.environ['SENTINEL'])\n"
                       "if os.environ['COMPLETE']=='1': print('diagnostic_complete; no mount/data/permission mutation performed')\n"
                       "sys.exit(int(os.environ['SSH_STATUS']))\n")
        ssh.chmod(0o700)
        date = bindir / "date"
        date.write_text("#!/bin/bash\nif [ \"$*\" = '-u +%s' ]; then echo \"$NOW\"; else exec /usr/bin/date \"$@\"; fi\n")
        date.chmod(0o700)
        env = os.environ.copy()
        env.update(PATH=str(bindir)+":"+env["PATH"], MODE=mode, PI_SSH_HOST=host,
                   PI_SSH_USER="admin", PI_SSH_KEY=base64.b64encode(b"FAKE_KEY_NOT_A_CREDENTIAL").decode(),
                   DUMP_ARTIFACT_KEY="FAKE_TEST_ENCRYPTION_KEY_ONLY", RUNNER_TEMP=str(root),
                   HOME=str(root), CALL=str(root/"call.json"), SENTINEL=SENTINEL,
                   SSH_STATUS=str(status), COMPLETE="1" if complete else "0", NOW="1792627201" if expired else "1792508400")
        result = subprocess.run(["bash", str(SCRIPT)], cwd=ROOT, env=env,
                                capture_output=True, text=True, timeout=15)
        self.assertNotIn(SENTINEL, result.stdout+result.stderr)
        self.assertFalse(list(root.glob("pop-mount-policy.*")), "raw evidence/key not cleaned")
        return result, root, env

    def test_success_encrypts_evidence_and_sends_exact_bounded_script(self):
        result, root, env = self.run_probe()
        self.assertEqual(result.returncode, 0, result.stderr)
        call = json.loads((root/"call.json").read_text())
        self.assertEqual(call["sha256"], "bb615a3a5643c153f08a6413e7004731250873800acbaee75abda97663b6505d")
        for flag in ["BatchMode=yes", "StrictHostKeyChecking=yes", "ConnectionAttempts=1",
                     "IdentitiesOnly=yes", "ClearAllForwardings=yes", "ForwardAgent=no"]:
            self.assertIn(flag, call["args"])
        self.assertEqual(call["args"][-1], "/usr/bin/timeout --kill-after=5s 100s /bin/bash --noprofile --norc -s")
        encrypted = root/"pop-mount-policy-artifact/pop-mount-policy.full.tar.gz.enc"
        archive = root/"decrypted.tar.gz"
        subprocess.run(["openssl", "enc", "-d", "-aes-256-cbc", "-pbkdf2", "-iter", "600000",
                        "-pass", "env:DUMP_ARTIFACT_KEY", "-in", str(encrypted), "-out", str(archive)],
                       env=env, check=True, capture_output=True)
        with tarfile.open(archive) as tar:
            self.assertEqual(set(tar.getnames()), {"policy.txt", "receipt.json"})
            self.assertIn(SENTINEL, tar.extractfile("policy.txt").read().decode())
            self.assertEqual(json.load(tar.extractfile("receipt.json"))["ssh_exit_status"], 0)
        checksum = encrypted.with_suffix(encrypted.suffix+".sha256").read_text().split()[0]
        self.assertEqual(checksum, hashlib.sha256(encrypted.read_bytes()).hexdigest())

    def test_policy_denial_and_auth_failure_keep_exit_and_encrypted_evidence(self):
        for status in [71, 127, 255]:
            with self.subTest(status=status):
                result, root, _ = self.run_probe(status=status)
                self.assertEqual(result.returncode, status)
                self.assertTrue((root/"pop-mount-policy-artifact/pop-mount-policy.full.tar.gz.enc").exists())
                self.assertTrue((root/"call.json").exists())

    def test_successful_ssh_without_diagnostic_completion_is_not_success(self):
        result, _, _ = self.run_probe(complete=False)
        self.assertEqual(result.returncode, 65)

    def test_wrong_target_wrong_mode_and_expiry_stop_before_ssh(self):
        for kwargs in [dict(host="other.example"), dict(mode="mount"), dict(expired=True)]:
            with self.subTest(kwargs=kwargs):
                result, root, _ = self.run_probe(**kwargs)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse((root/"call.json").exists())

    def test_workflow_branch_skips_mount_and_never_injects_mount_payload(self):
        workflow = (ROOT/".github/workflows/pop-mount-reviewed-financial-once.yml").read_text()
        diagnostic = workflow.split("- name: Read effective mount policy", 1)[1].split("- name: Upload encrypted", 1)[0]
        self.assertIn("if: inputs.mode == 'probe-policy'", diagnostic)
        self.assertNotIn("MOUNT_B64", diagnostic)
        self.assertNotIn("scp", diagnostic)
        mount = workflow.split("- name: Install or probe reviewed-financial mount", 1)[1]
        self.assertIn("if: inputs.mode != 'probe-policy'", mount)
        self.assertIn("environment: production-activation", workflow)
        self.assertEqual(hashlib.sha256(REMOTE.read_bytes()).hexdigest(),
                         "bb615a3a5643c153f08a6413e7004731250873800acbaee75abda97663b6505d")

    def test_remote_symlink_stops_before_metadata_or_policy(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            (root/"link").symlink_to(root/"absent")
            bindir = root/"bin"
            bindir.mkdir()
            for name, body in {"id": "echo admin", "getent": ":", "readlink": ":",
                               "stat": "echo MUST_NOT_REACH; exit 99", "sudo": "echo MUST_NOT_REACH; exit 99"}.items():
                f = bindir/name
                f.write_text("#!/bin/sh\n"+body+"\n")
                f.chmod(0o700)
            lines = REMOTE.read_text().splitlines()
            # Substitute only fixture paths in a test copy; shipped hash remains fixed.
            lines = ['paths=("$FIXTURE_LINK")' if l.startswith("paths=(") else l for l in lines]
            env = os.environ.copy()
            env.update(PATH=str(bindir)+":"+env["PATH"], FIXTURE_LINK=str(root/"link"))
            result = subprocess.run(["bash"], input="\n".join(lines)+"\n", env=env,
                                    capture_output=True, text=True, timeout=5)
            self.assertEqual(result.returncode, 72)
            self.assertIn("SYMLINK_STOP", result.stderr)
            self.assertNotIn("MUST_NOT_REACH", result.stdout+result.stderr)


if __name__ == "__main__":
    unittest.main()
