import importlib.util
import json
from pathlib import Path
import subprocess
import sys
from threading import Thread
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import unittest
from urllib.parse import parse_qs, urlsplit

CLIENT = Path(__file__).resolve().parents[2] / "infra/agent-readonly/query_alltrue.py"
SPEC = importlib.util.spec_from_file_location("query_alltrue", CLIENT)
QUERY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(QUERY)


class StubRelay(BaseHTTPRequestHandler):
    def do_GET(self):
        self.server.requests.append((self.path, self.headers.get("Authorization")))
        if self.server.redirect:
            self.send_response(302)
            self.send_header("Location", "/unexpected")
            self.end_headers()
            return
        body = json.dumps({"synthetic": True}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


class AllTrueGatewayTest(unittest.TestCase):
    def setUp(self):
        self.relay = ThreadingHTTPServer(("127.0.0.1", 0), StubRelay)
        self.relay.requests = []
        self.relay.redirect = False
        thread = Thread(target=self.relay.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(thread.join, timeout=5)
        self.addCleanup(self.relay.server_close)
        self.addCleanup(self.relay.shutdown)

    def run_client(self, *args):
        return subprocess.run([sys.executable, str(CLIENT), "--gateway", f"http://127.0.0.1:{self.relay.server_port}", *args],
                              text=True, capture_output=True, timeout=8)

    def test_catalog_and_scoped_invoice_query(self):
        self.assertEqual(json.loads(self.run_client().stdout), {"synthetic": True})
        self.assertEqual(json.loads(self.run_client("Invoice", "--in", "StudentID=1,2",
                                                   "--gte", "id=201", "--order", "id", "--limit", "200").stdout),
                         {"synthetic": True})
        self.assertEqual(self.relay.requests[0], ("/api/v1/triage/db", None))
        path, auth = self.relay.requests[1]
        self.assertEqual(urlsplit(path).path, "/api/v1/triage/db/Invoice")
        self.assertIsNone(auth)
        self.assertEqual(parse_qs(urlsplit(path).query), {"in[StudentID]": ["1,2"], "gte[id]": ["201"],
                                                          "order": ["id"], "limit": ["200"]})

    def test_bad_arguments_and_redirects_fail_closed(self):
        self.assertNotEqual(self.run_client("Invoice/../Payment").returncode, 0)
        self.assertNotEqual(self.run_client("Invoice", "--limit", "201").returncode, 0)
        self.assertNotEqual(self.run_client("Invoice", "--eq", "StudentID=name").returncode, 0)
        self.assertNotEqual(self.run_client("Invoice", "--in", "StudentID=1,name").returncode, 0)
        self.assertNotEqual(self.run_client("Invoice", "--eq", "StudentID=1", "--eq", "StudentID=2").returncode, 0)
        self.assertEqual(self.relay.requests, [])
        self.relay.redirect = True
        result = self.run_client("Invoice")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("HTTP 302", result.stderr)
        self.assertEqual(len(self.relay.requests), 1)

    def test_requires_safe_relay_origin(self):
        for candidate in ("http://192.0.2.1", "https://name:secret@relay.invalid", "https://relay.invalid/path",
                          "https://daan.lifenet.com.tw", "file:///tmp/test"):
            with self.subTest(candidate=candidate), self.assertRaises(ValueError):
                QUERY.origin(candidate)


if __name__ == "__main__":
    unittest.main()
