"""Exercise the actual notify entry point with a stub transport; never send messages."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'monitor-alert.sh'
STUB = '''#!/usr/bin/env python3
import json, os, pathlib, sys
args = sys.argv[1:]
def value(flag): return args[args.index(flag)+1]
modern = '--config' in args
config = pathlib.Path(value('--config')) if modern else None
payload = pathlib.Path(value('--data-binary').removeprefix('@')).read_text() if modern else value('-d')
pathlib.Path(os.environ['STUB_CAPTURE']).write_text(json.dumps({
 'args': args, 'config': config.read_text() if modern else ' '.join(args), 'payload': json.loads(payload),
 'tempdir': str(config.parent) if modern else None}))
if '--output' in args: pathlib.Path(value('--output')).write_text(os.environ['STUB_BODY'])
print(os.environ['STUB_STATUS'], end='')
print('synthetic-private-token and provider response must stay private', file=sys.stderr)
sys.exit(int(os.environ['STUB_RC']))
'''


class MonitorNotifyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if Path('/home/admin/.env.monitor').exists():
            raise RuntimeError('Refusing to load a real monitor configuration in tests')

    def run_notify(self, provider='telegram', status='200', body=None, rc=0, both=False):
        if body is None:
            body = {'ok': True, 'result': {}} if provider == 'telegram' else {'sentMessages': [{'id': 'test-only-id'}]}
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            stub = root / 'curl'
            stub.write_text(STUB)
            stub.chmod(0o700)
            env = {'PATH': f'{root}:/usr/bin:/bin', 'HOME': directory,
                   'STUB_CAPTURE': str(root / 'capture.json'), 'STUB_STATUS': status,
                   'STUB_BODY': body if isinstance(body, str) else json.dumps(body), 'STUB_RC': str(rc)}
            if provider == 'telegram' or both:
                env.update(TELEGRAM_BOT_TOKEN='synthetic-private-token', TELEGRAM_CHAT_ID='test-chat')
            if provider == 'line' or both:
                env.update(LINE_CHANNEL_TOKEN='synthetic-private-token', LINE_GROUP_ID='test-group')
            message = '測試 "引號"\\反斜線\n第二行'
            result = subprocess.run(['bash', str(SCRIPT), 'notify', message], env=env, text=True, capture_output=True)
            capture = json.loads((root / 'capture.json').read_text()) if (root / 'capture.json').exists() else None
            if capture and capture['tempdir']:
                self.assertFalse(Path(capture['tempdir']).exists(), 'request/response files must be removed')
            self.assertNotIn('synthetic-private-token', result.stdout + result.stderr)
            self.assertNotIn('provider response must stay private', result.stdout + result.stderr)
            return result, capture, message

    def test_provider_acknowledgements_and_safe_request(self):
        for provider in ['telegram', 'line']:
            with self.subTest(provider=provider):
                result, capture, message = self.run_notify(provider)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn('已確認服務接收', result.stdout)
                self.assertIn('--connect-timeout', capture['args'])
                self.assertIn('--max-time', capture['args'])
                self.assertNotIn('synthetic-private-token', ' '.join(capture['args']))
                self.assertNotIn('--retry', capture['args'], 'ambiguous sends must not be duplicated automatically')
                sent = capture['payload']['text'] if provider == 'telegram' else capture['payload']['messages'][0]['text']
                self.assertEqual(sent, message)

    def test_http_rejections_and_transport_failures(self):
        for provider in ['telegram', 'line']:
            for status, rc in [('401', 0), ('429', 0), ('500', 0), ('000', 28), ('000', 7)]:
                with self.subTest(provider=provider, status=status, rc=rc):
                    result, _, _ = self.run_notify(provider, status=status, rc=rc)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertNotIn('[NOTIFY]', result.stdout)
                    self.assertIn('[ERROR]', result.stderr)

    def test_success_http_with_invalid_provider_body_is_failure(self):
        for provider, bodies in [('telegram', [{'ok': False}, {'ok': 'true'}, {}, 'invalid-json', []]),
                                 ('line', [{}, {'sentMessages': []}, {'sentMessages': [{'id': ''}]}, 'invalid-json', []])]:
            for body in bodies:
                with self.subTest(provider=provider, body=body):
                    result, _, _ = self.run_notify(provider, body=body)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertNotIn('[NOTIFY]', result.stdout)
                    self.assertIn('[ERROR]', result.stderr)

    def test_existing_telegram_priority_and_missing_configuration(self):
        result, capture, _ = self.run_notify(both=True)
        self.assertEqual(result.returncode, 0)
        self.assertIn('api.telegram.org', capture['config'])
        result, capture, _ = self.run_notify(provider='none')
        self.assertEqual(result.returncode, 0)
        self.assertIsNone(capture)
        self.assertIn('[WARN]', result.stdout)
        self.assertNotIn('[NOTIFY]', result.stdout)


if __name__ == '__main__':
    unittest.main()
