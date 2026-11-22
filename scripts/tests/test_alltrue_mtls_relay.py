"""Synthetic NGINX mTLS proof. Optional binary supplied by the operator."""

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import shutil
import socket
import ssl
import subprocess
import sys
import tempfile
from threading import Thread
import time
import unittest
from urllib.error import HTTPError, URLError
from urllib.request import HTTPSHandler, ProxyHandler, Request, build_opener

ROOT = Path(__file__).resolve().parents[2]
CONFIG = ROOT / "infra/agent-readonly/relay/nginx.conf"
CLIENT = ROOT / "infra/agent-readonly/query_alltrue.py"
NGINX = os.environ.get("ALLTRUE_RELAY_NGINX_TEST_BIN") or shutil.which("nginx")


class Upstream(BaseHTTPRequestHandler):
    def do_GET(self):
        self.server.calls.append((self.path, self.headers.get("Authorization"), self.headers.get("Cookie")))
        body = json.dumps({"tables": ["Invoice"]}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Set-Cookie", "synthetic")
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *_):
        pass


class RelayTest(unittest.TestCase):
    def test_template_has_the_production_origin_and_tls_checks(self):
        source = CONFIG.read_text()
        self.assertIn("ssl_verify_client on;", source)
        self.assertIn("listen 127.0.0.1:8443 ssl;", source)
        self.assertIn("proxy_ssl_verify on;", source)
        self.assertIn("proxy_ssl_name daan.lifenet.com.tw;", source)
        self.assertIn("proxy_pass https://daan.lifenet.com.tw;", source)
        self.assertNotIn("$request ", source)
        self.assertNotIn("$args", source)

    def test_relay_requires_enrollment_and_forwards_only_scoped_reads(self):
        self.assertIsNotNone(NGINX, "Install NGINX or set ALLTRUE_RELAY_NGINX_TEST_BIN.")
        with tempfile.TemporaryDirectory() as scratch:
            base = Path(scratch)

            def openssl(*args):
                return subprocess.run(["openssl", *args], cwd=base, capture_output=True, text=True, check=True, timeout=10).stdout

            openssl("req", "-x509", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1", "-nodes",
                    "-subj", "/CN=Synthetic CA", "-days", "1", "-keyout", "ca.key", "-out", "client-ca.crt")

            def identity(name, extensions):
                openssl("req", "-new", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1", "-nodes",
                        "-subj", f"/CN={name}", "-keyout", f"{name}.key", "-out", f"{name}.csr")
                (base / "ext").write_text(extensions)
                openssl("x509", "-req", "-in", f"{name}.csr", "-CA", "client-ca.crt", "-CAkey", "ca.key",
                        "-CAcreateserial", "-days", "1", "-extfile", "ext", "-out", f"{name}.crt")
                (base / f"{name}.key").chmod(0o600)

            identity("server", "extendedKeyUsage=serverAuth\nsubjectAltName=IP:127.0.0.1")
            identity("upstream", "extendedKeyUsage=serverAuth\nsubjectAltName=DNS:upstream.test")
            identity("enrolled", "extendedKeyUsage=clientAuth")
            identity("unenrolled", "extendedKeyUsage=clientAuth")
            fingerprint = openssl("x509", "-in", "enrolled.crt", "-noout", "-fingerprint", "-sha1").split("=")[-1]
            (base / "callers.conf").write_text(f"{fingerprint.strip().replace(':', '').lower()} 1;\n")
            (base / "upstream-auth.conf").write_text('proxy_set_header Authorization "Bearer synthetic-managed";\n')

            with ThreadingHTTPServer(("127.0.0.1", 0), Upstream) as upstream:
                upstream.calls = []
                server_tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
                server_tls.load_cert_chain(base / "upstream.crt", base / "upstream.key")
                upstream.socket = server_tls.wrap_socket(upstream.socket, server_side=True)
                thread = Thread(target=upstream.serve_forever)
                thread.start()
                try:
                    self.exercise(base, upstream)
                finally:
                    upstream.shutdown()
                    thread.join(timeout=5)

    def exercise(self, base, upstream):
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]
        rendered = CONFIG.read_text().replace(
            "listen 127.0.0.1:8443 ssl;", f"listen 127.0.0.1:{port} ssl;"
        ).replace("/etc/alltrue-read-relay", str(base)).replace(
            "/var/log/nginx/alltrue-read-relay.log", str(base / "access.log")
        ).replace("/etc/ssl/certs/ca-certificates.crt", str(base / "client-ca.crt")).replace(
            "proxy_pass https://daan.lifenet.com.tw;", f"proxy_pass https://127.0.0.1:{upstream.server_port};"
        ).replace("daan.lifenet.com.tw", "upstream.test")
        config = base / "nginx.conf"
        temp_paths = [
            f"{prefix}_temp_path {base}/{prefix};"
            for prefix in ("client_body", "proxy", "fastcgi", "uwsgi", "scgi")
        ]
        config.write_text(f"pid {base}/nginx.pid; error_log stderr crit;\nevents {{}}\nhttp {{\n"
                          + "\n".join(temp_paths) + f"\n{rendered}\n}}\n")
        workers = [NGINX, "-p", str(base), "-c", str(config), "-g", "daemon off;"]
        with (base / "error.log").open("w") as errors:
            process = subprocess.Popen(workers, stdout=subprocess.DEVNULL, stderr=errors)
            try:
                self.verify(base, upstream, port, process)
            finally:
                if process.poll() is None:
                    process.terminate()
                    process.wait(timeout=10)

    def verify(self, base, upstream, port, process):
        deadline = time.monotonic() + 5
        while True:
            if process.poll() is not None or time.monotonic() > deadline:
                self.fail((base / "error.log").read_text())
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=0.5):
                    break
            except OSError:
                time.sleep(0.05)
        relay = f"https://127.0.0.1:{port}"

        def request(path, certificate=None, method="GET", headers=None):
            context = ssl.create_default_context(cafile=base / "client-ca.crt")
            if certificate:
                context.load_cert_chain(base / f"{certificate}.crt", base / f"{certificate}.key")
            opener = build_opener(ProxyHandler({}), HTTPSHandler(context=context))
            try:
                with opener.open(Request(relay + path, method=method, headers=headers or {}), timeout=5) as response:
                    self.assertIsNone(response.headers.get("Set-Cookie"))
                    return response.status
            except HTTPError as error:
                return error.code
            except URLError:
                return None

        self.assertNotEqual(request("/api/v1/triage/db"), 200)
        self.assertEqual(request("/api/v1/triage/db", "unenrolled"), 403)
        self.assertEqual(request("/api/v1/triage/db/Invoice", "enrolled", "POST"), 405)
        self.assertEqual(request("/api/v1/pop/operations", "enrolled"), 404)
        self.assertEqual(request("/api/v1/triage/db/%49nvoice", "enrolled"), 404)
        self.assertEqual(upstream.calls, [])
        self.assertEqual(request("/api/v1/triage/db", "enrolled",
                                 headers={"Authorization": "Bearer client-controlled", "Cookie": "client-controlled"}), 200)
        environment = dict(os.environ, ALLTRUE_READ_CLIENT_CERT=str(base / "enrolled.crt"),
                           ALLTRUE_READ_CLIENT_KEY=str(base / "enrolled.key"), ALLTRUE_READ_SERVER_CA=str(base / "client-ca.crt"))
        result = subprocess.run([sys.executable, str(CLIENT), "--gateway", relay, "Invoice", "--eq", "id=7352"],
                                env=environment, text=True, capture_output=True, timeout=8)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {"tables": ["Invoice"]})
        self.assertEqual(upstream.calls, [
            ("/api/v1/triage/db", "Bearer synthetic-managed", None),
            ("/api/v1/triage/db/Invoice?eq%5Bid%5D=7352&limit=50", "Bearer synthetic-managed", None),
        ])
        self.assertNotIn("7352", (base / "access.log").read_text())


if __name__ == "__main__":
    unittest.main()
