"""Exercise the checkout-based desktop prototype using disposable data and local SMTP."""

from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import base64
import email.policy
import hashlib
import http.cookiejar
import json
import os
import plistlib
from pathlib import Path
import queue
import re
import socket
import socketserver
import ssl
import sqlite3
import subprocess
import tempfile
import threading
import time
import unittest
import uuid
import zipfile
import urllib.error
import urllib.parse
import urllib.request
from email.parser import BytesParser


LAUNCHER = Path(os.environ.get("CANABICULTARR_TEST_DESKTOP_LAUNCHER", Path(__file__).with_name("run.php"))).resolve()
ROOT = LAUNCHER.parents[2]
PHP = os.environ.get("CANABICULTARR_TEST_PHP", "php")


PENDING_MIGRATION = "2026_11_17_020000_add_bluetooth_server_address"


def defer_migration(profile):
    # Both previews contain the same application schema. Recreate the older
    # shape using the down operation of a real migration in this disposable DB.
    with sqlite3.connect(Path(profile) / "canabicultarr.sqlite") as database:
        database.execute("ALTER TABLE bluetooth_devices DROP COLUMN address")
        result = database.execute("DELETE FROM migrations WHERE migration = ?", (PENDING_MIGRATION,))
        assert result.rowcount == 1


def assert_migrated(profile):
    with sqlite3.connect(Path(profile) / "canabicultarr.sqlite") as database:
        assert "address" in {column[1] for column in database.execute("PRAGMA table_info(bluetooth_devices)")}
        assert database.execute("SELECT count(*) FROM migrations WHERE migration = ?", (PENDING_MIGRATION,)).fetchone() == (1,)


class SMTPFixture(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b"220 localhost Desktop smoke test\r\n")
        while command := self.rfile.readline():
            command = command.upper()
            if command.startswith(b"DATA"):
                self.wfile.write(b"354 End with a single dot\r\n")
                message = bytearray()
                while (line := self.rfile.readline()) not in (b".\r\n", b""):
                    message.extend(line)
                self.server.messages.put(bytes(message))
            elif command.startswith(b"QUIT"):
                self.wfile.write(b"221 Closing connection\r\n")
                return
            self.wfile.write(b"250 OK\r\n")


class DesktopRuntimeTest(unittest.TestCase):
    def request(self, path, expected=200, *, data=None, headers=None, client=None, method=None):
        headers = {"Accept": "application/json", **(headers or {})}
        opener = client or self.client
        if data is not None:
            cookies = next((cookie.value for cookie in self.cookies if cookie.name == "XSRF-TOKEN"), "")
            headers["X-XSRF-TOKEN"] = urllib.parse.unquote(cookies)
            if isinstance(data, dict):
                headers["Content-Type"] = "application/json"
                data = json.dumps(data).encode()
        request = urllib.request.Request(self.origin + path, data, headers, method=method)
        try:
            response = opener.open(request, timeout=4)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            self.assertIn(response.code, expected if isinstance(expected, tuple) else (expected,), path)
            return response.read()

    def start(self):
        self.server = subprocess.Popen([PHP, str(LAUNCHER)], env=self.environment, stdout=self.logs, stderr=self.logs)
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline and self.server.poll() is None:
            try:
                self.request("/up")
                return
            except urllib.error.URLError:
                time.sleep(0.1)
        self.fail("Desktop app failed to start")

    def stop(self):
        if self.server and self.server.poll() is None:
            self.server.terminate()
            self.assertEqual(self.server.wait(timeout=10), 0)

    def test_reconfiguration_keeps_the_key_and_preserves_other_settings(self):
        with tempfile.TemporaryDirectory(prefix="cana-desktop-settings-") as directory:
            environment = {**os.environ, "CANABICULTARR_DESKTOP_DATA_DIR": directory}
            configuration = Path(directory) / "desktop.env"
            configuration.write_text('APP_KEY="base64:fake-development-fixture"\n# Keep this comment\nAPP_LOCALE=fr\n')
            configuration.chmod(0o600)
            password = " Fake password # \"quotes\" \\ ${APP_KEY} "
            settings = {
                "port": "8421", "host": "localhost", "smtpPort": "587", "sender": "sender@example.test",
                "senderName": "Desktop", "username": "demo", "password": password,
            }
            arguments = [PHP, str(LAUNCHER), "configure"]
            rejected = subprocess.run(
                arguments, env=environment, capture_output=True, text=True, timeout=4,
                input=json.dumps({**settings, "port": "0"}),
            )
            self.assertNotEqual(rejected.returncode, 0)
            self.assertIn("Enter a port between 1024 and 65535.", rejected.stderr)
            result = subprocess.run(
                arguments, env=environment, capture_output=True, text=True, timeout=4,
                input=json.dumps(settings),
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertNotIn(password, result.stdout + result.stderr)
            saved = configuration.read_text()
            self.assertIn('APP_KEY="base64:fake-development-fixture"', saved)
            self.assertIn("# Keep this comment\nAPP_LOCALE=fr", saved)
            self.assertIn('CANABICULTARR_DESKTOP_PORT="8421"', saved)
            expectations = {"APP_KEY": "base64:fake-development-fixture", "MAIL_PASSWORD": password}
            parsed = subprocess.run(
                [PHP, "-r", "require 'backend/vendor/autoload.php'; "
                 "$settings=Dotenv\\Dotenv::parse(file_get_contents($argv[1])); "
                 "$expected=json_decode(stream_get_contents(STDIN),true); "
                 "exit(array_intersect_key($settings,$expected) == $expected ? 0 : 1);", str(configuration)],
                cwd=ROOT, input=json.dumps(expectations), text=True, timeout=4,
            )
            self.assertEqual(parsed.returncode, 0)
            self.assertFalse((Path(directory) / "canabicultarr.sqlite").exists())

    @unittest.skipIf(os.environ.get("CANABICULTARR_TEST_MAC_UPGRADE_HELPER"), "Earlier preview lacks diagnostics")
    def test_startup_log_records_failure_and_stays_bounded(self):
        limit = 256 * 1024
        with tempfile.TemporaryDirectory(prefix="cana-desktop-log-") as directory, socket.socket() as occupied:
            profile = Path(directory)
            occupied.bind(("127.0.0.1", 0))
            occupied.listen()
            port = occupied.getsockname()[1]
            environment = {**os.environ, "CANABICULTARR_DESKTOP_DATA_DIR": directory}
            password = "fixture-smtp-secret"
            fields = {"port": str(port), "host": "127.0.0.1", "smtpPort": "2525",
                      "sender": "fixture@example.test", "username": "fixture-user", "password": password}
            command = [PHP, str(LAUNCHER)]
            subprocess.run(command + ["configure"], env=environment, input=json.dumps(fields), text=True, check=True)
            result = subprocess.run(command, env=environment, capture_output=True, timeout=15)
            self.assertNotEqual(result.returncode, 0)
            journal = profile / "desktop-runtime.log"
            lines = subprocess.check_output(command + ["runtime-log"], env=environment).splitlines()
            self.assertTrue(any(line.endswith(b"service-exited web") for line in lines), lines)
            self.assertTrue(lines[-1].endswith(b"server-failed"))
            self.assertNotIn(password.encode(), b"".join(lines))
            self.assertNotIn(fields["sender"].encode(), b"".join(lines))
            self.assertNotIn(str(port).encode(), b" ".join(line[21:] for line in lines))
            self.assertEqual(journal.stat().st_mode & 0o777, 0o600)
            with journal.open("wb") as log:
                log.write(b"old entry\n" * (limit // 10 + 1))
            result = subprocess.run(command, env=environment, capture_output=True, timeout=15)
            self.assertNotEqual(result.returncode, 0)
            self.assertLess(journal.stat().st_size, limit)
            self.assertTrue(journal.read_bytes().startswith(b"old entry\n"))
            self.assertTrue(journal.read_text().rstrip().endswith("server-failed"))
            journal.rename(profile / "archive.log")
            journal.symlink_to(profile / "desktop.env")
            self.assertEqual(subprocess.check_output(command + ["runtime-log"], env=environment), b"")

    def test_onboarding_upload_and_restart(self):
        with tempfile.TemporaryDirectory(prefix="cana-desktop-") as temporary, socketserver.TCPServer(
            ("127.0.0.1", 0), SMTPFixture
        ) as smtp, tempfile.TemporaryFile() as logs:
            smtp.messages = queue.Queue()
            threading.Thread(target=smtp.serve_forever, daemon=True).start()
            with socket.socket() as reservation:
                reservation.bind(("127.0.0.1", 0))
                port = reservation.getsockname()[1]
            self.origin = f"http://127.0.0.1:{port}"
            self.environment = {
                **os.environ,
                "CANABICULTARR_DESKTOP_DATA_DIR": temporary,
                "CANABICULTARR_DESKTOP_RUN_ID": str(uuid.uuid4()),
                # The prototype must ignore settings from the calling shell.
                "DB_CONNECTION": "pgsql",
                "DB_URL": "postgresql://unused.invalid/unused",
            }
            self.environment.pop("CANABICULTARR_DESKTOP_PORT", None)
            self.cookies = http.cookiejar.CookieJar()
            self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
            self.logs = logs
            self.server = None
            try:
                before_configure = subprocess.run([PHP, str(LAUNCHER)], env=self.environment, capture_output=True, timeout=4)
                self.assertNotEqual(before_configure.returncode, 0)
                self.assertIn(b"Complete the Desktop configuration", before_configure.stderr)
                self.assertFalse((Path(temporary) / "canabicultarr.sqlite").exists())
                configured = subprocess.run(
                    [PHP, str(LAUNCHER), "configure"], env=self.environment, capture_output=True, text=True, timeout=4,
                    input=json.dumps({
                        "port": str(port), "host": "127.0.0.1", "smtpPort": str(smtp.server_address[1]),
                        "sender": "desktop@example.test", "senderName": "Desktop smoke test",
                    }),
                )
                self.assertEqual(configured.returncode, 0, configured.stderr)
                self.assertFalse((Path(temporary) / "canabicultarr.sqlite").exists())
                configuration = Path(temporary) / "desktop.env"
                self.assertEqual(configuration.stat().st_mode & 0o777, 0o600)
                saved_configuration = configuration.read_bytes()
                self.start()
                self.assertTrue(json.loads(self.request("/api/v1/setup"))["data"]["requiresSetup"])
                self.assertIn(b'type="module"', self.request("/plants"))
                self.request("/assets/not-present.js", 404)
                self.request("/backend/.env", 404)
                self.request("/up", 400, headers={"Host": "foreign.example.test"})
                duplicate = subprocess.run([PHP, str(LAUNCHER)], env=self.environment, capture_output=True, timeout=4)
                self.assertNotEqual(duplicate.returncode, 0)
                self.assertIn(b"already uses this data directory", duplicate.stderr)
                token = subprocess.check_output([PHP, str(LAUNCHER), "setup-token"], env=self.environment, text=True).strip()
                self.request("/api/v1/auth/csrf", 204)
                credentials = {"email": "desktop-admin@example.test", "password": "Fictional desktop admin 123!"}
                native_client = os.environ.get("CANABICULTARR_TEST_NATIVE_SETUP")
                if native_client:
                    setup_result = subprocess.run(
                        [native_client, self.origin, self.environment["CANABICULTARR_DESKTOP_RUN_ID"]],
                        input=json.dumps({**credentials, "token": token}), text=True, capture_output=True, timeout=20,
                    )
                    self.assertEqual(setup_result.returncode, 0, "The Swift setup client failed.")
                else:
                    self.request("/api/v1/setup", 201, data={
                        **credentials, "name": "Desktop smoke test", "token": token,
                        "password_confirmation": credentials["password"], "locale": "en",
                    })
                message = BytesParser(policy=email.policy.default).parsebytes(smtp.messages.get(timeout=15))
                text = message.get_body(preferencelist=("plain", "html")).get_content()
                activation = re.search(r"#activate-account=([A-Za-z0-9]{64})", text)
                self.assertIsNotNone(activation)
                self.assertIn(self.origin + "/#activate-account=", text)
                pending_login = self.request("/api/v1/auth/login", 403, data=credentials)
                self.assertEqual(json.loads(pending_login)["error"]["code"], "accountActivation.required")
                self.request("/api/v1/auth/activation/confirm", 204, data={
                    "email": credentials["email"], "token": activation[1],
                })
                self.request("/api/v1/auth/login", data=credentials)
                self.assertEqual(json.loads(self.request("/api/v1/auth/user"))["data"]["email"], credentials["email"])

                payload = b"Disposable desktop file.\n"
                boundary = "DesktopFixtureBoundary"
                upload = (
                    f'--{boundary}\r\nContent-Disposition: form-data; name="title"\r\n\r\nDesktop sample\r\n'
                    f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="example.txt"\r\n'
                    'Content-Type: text/plain\r\n\r\n'
                ).encode() + payload + f"\r\n--{boundary}--\r\n".encode()
                document_id = json.loads(self.request("/api/v1/documents", 201, data=upload, headers={
                    "Content-Type": f"multipart/form-data; boundary={boundary}",
                }))["data"]["id"]
                self.stop()
                upgrade_helper = os.environ.get("CANABICULTARR_TEST_MAC_UPGRADE_HELPER")
                if upgrade_helper:
                    defer_migration(temporary)
                    subprocess.run([
                        upgrade_helper, os.environ["CANABICULTARR_TEST_MAC_UPGRADE_SOURCE"],
                        str(LAUNCHER.parents[5]), temporary,
                    ], timeout=40, check=True)
                    self.start()
                    try:
                        assert_migrated(temporary)
                        self.request("/api/v1/auth/csrf", 204)
                        self.request("/api/v1/auth/login", data=credentials)
                        self.assertEqual(self.request(f"/api/v1/documents/{document_id}/download"), payload)
                    finally:
                        self.stop()
                self.assertEqual(configuration.read_bytes(), saved_configuration)
                with sqlite3.connect(Path(temporary) / "canabicultarr.sqlite") as database:
                    self.assertEqual(database.execute("PRAGMA journal_mode").fetchone()[0], "wal")
                archive = Path(temporary) / "backup.zip"
                password = "Desktop fixture password 456!"
                arguments = {"path": str(archive), "password": password}

                def transfer(action, environment, arguments):
                    return subprocess.run([PHP, str(LAUNCHER), action], env=environment, capture_output=True,
                                          text=True, input=json.dumps(arguments), timeout=15)

                backup = transfer("backup", self.environment, arguments)
                self.assertEqual(backup.returncode, 0, backup.stderr)
                with zipfile.ZipFile(archive) as encrypted:
                    self.assertEqual(len(encrypted.infolist()), 3)
                    for entry in encrypted.infolist():
                        self.assertTrue(entry.flag_bits & 1)
                self.assertNotIn(saved_configuration, archive.read_bytes())
                original_archive = archive.read_bytes()
                self.assertNotEqual(transfer("backup", self.environment, arguments).returncode, 0)
                self.assertEqual(archive.read_bytes(), original_archive)
                self.assertNotEqual(transfer("restore", self.environment, arguments).returncode, 0)
                with tempfile.TemporaryDirectory(prefix="cana-restored-") as restored:
                    self.environment["CANABICULTARR_DESKTOP_DATA_DIR"] = restored
                    invalid = Path(temporary) / "invalid-backup.zip"
                    with zipfile.ZipFile(invalid, "w") as foreign:
                        foreign.writestr("../unexpected.txt", "This entry must never be extracted.")
                    self.assertNotEqual(transfer("restore", self.environment, {**arguments, "path": str(invalid)}).returncode, 0)
                    self.assertFalse((Path(restored) / "unexpected.txt").exists())
                    wrong = transfer("restore", self.environment, {**arguments, "password": "Fictional wrong password"})
                    self.assertNotEqual(wrong.returncode, 0)
                    self.assertEqual(list(Path(restored).iterdir()), [Path(restored) / "run.lock"])
                    recovered = transfer("restore", self.environment, arguments)
                    self.assertEqual(recovered.returncode, 0, recovered.stderr)
                    self.assertEqual((Path(restored) / "desktop.env").read_bytes(), saved_configuration)
                    self.start()
                    try:
                        self.assertFalse(json.loads(self.request("/api/v1/setup"))["data"]["requiresSetup"])
                        self.request("/api/v1/auth/csrf", 204)
                        self.request("/api/v1/auth/login", data=credentials)
                        self.assertEqual(self.request(f"/api/v1/documents/{document_id}/download"), payload)
                    finally:
                        self.stop()
                        self.environment["CANABICULTARR_DESKTOP_DATA_DIR"] = temporary

                self.cookies.clear()
                self.start()
                self.request("/api/v1/auth/csrf", 204)
                self.request("/api/v1/auth/login", data=credentials)
                self.assertFalse(json.loads(self.request("/api/v1/setup"))["data"]["requiresSetup"])
                self.assertEqual(self.request(f"/api/v1/documents/{document_id}/download"), payload)
                self.request(f"/api/v1/documents/{document_id}/download", 401, client=urllib.request.build_opener())

                scheduled = Path(temporary) / "backups"
                schedule = {"frequency": "daily", "directory": "", "keep": "1", "password": password}
                self.assertNotEqual(transfer("configure-backups", self.environment, schedule).returncode, 0)
                self.stop()
                self.assertNotEqual(transfer("configure-backups", self.environment, {**schedule, "keep": "0"}).returncode, 0)
                configured_backup = transfer("configure-backups", self.environment, schedule)
                self.assertEqual(configured_backup.returncode, 0, configured_backup.stderr)
                schedule_json = Path(temporary) / "backup-schedule.json"
                self.assertNotIn(password, schedule_json.read_text())
                self.start()
                status_file = Path(temporary) / "backup-status.json"
                for _ in range(240):
                    if status_file.is_file() and json.loads(status_file.read_text())["lastSuccess"] is not None:
                        break
                    time.sleep(0.1)
                status = json.loads(status_file.read_text())
                self.assertIsNotNone(status["lastSuccess"])
                self.assertFalse(status["failed"])
                self.request("/up")
                first = next(scheduled.glob("canabicultarr-auto-*.zip"))
                with zipfile.ZipFile(first) as encrypted:
                    self.assertEqual(len(encrypted.infolist()), 3)
                    self.assertTrue(all(entry.flag_bits & 1 for entry in encrypted.infolist()))
                self.stop()

                copied = scheduled / "manual.zip"
                copied.write_bytes(archive.read_bytes())
                advance_clock = r"""
                    require $argv[1].'/../../backend/vendor/autoload.php';
                    require $argv[1].'/Configuration.php';
                    require $argv[1].'/ProfileBackup.php';
                    require $argv[1].'/BackupSchedule.php';
                    $settings = DesktopConfiguration::parse(file_get_contents($argv[2].'/desktop.env'));
                    $schedule = new BackupSchedule($argv[2], $settings['APP_KEY']);
                    $schedule->tick((int) $argv[3]);
                """
                command = [PHP, "-r", advance_clock, str(LAUNCHER.parent), temporary]
                start_time = status["lastSuccess"]
                subprocess.run([*command, str(start_time + 10)], env=self.environment, timeout=15, check=True)
                self.assertEqual(list(scheduled.glob("canabicultarr-auto-*.zip")), [first])
                subprocess.run([*command, str(start_time + 86400)], env=self.environment, timeout=15, check=True)
                archives = list(scheduled.glob("canabicultarr-auto-*.zip"))
                self.assertEqual(len(archives), 1)
                self.assertNotEqual(archives[0], first)
                self.assertEqual(copied.read_bytes(), archive.read_bytes())

                moved = scheduled.with_name("backups-unavailable")
                scheduled.rename(moved)
                next_due = start_time + 2 * 86400
                failed = subprocess.run([*command, str(next_due)], env=self.environment, timeout=15, capture_output=True)
                self.assertNotEqual(failed.returncode, 0)
                self.assertTrue(json.loads(status_file.read_text())["failed"])
                moved.rename(scheduled)
                subprocess.run([*command, str(next_due + 3599)], env=self.environment, timeout=15, check=True)
                self.assertEqual(list(scheduled.glob("canabicultarr-auto-*.zip")), archives)
                subprocess.run([*command, str(next_due + 3600)], env=self.environment, timeout=15, check=True)
                self.assertFalse(json.loads(status_file.read_text())["failed"])
                archives = list(scheduled.glob("canabicultarr-auto-*.zip"))
                self.assertEqual(len(archives), 1)
                self.assertEqual(copied.read_bytes(), archive.read_bytes())
                with tempfile.TemporaryDirectory(prefix="cana-scheduled-restore-") as recovered:
                    recovered_env = {**self.environment, "CANABICULTARR_DESKTOP_DATA_DIR": recovered}
                    result = transfer("restore", recovered_env, {"path": str(archives[0]), "password": password})
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertFalse((Path(recovered) / "backup-schedule.json").exists())

                if os.environ.get("CANABICULTARR_TEST_LAN") or os.environ.get("CANABICULTARR_TEST_LAN_BROWSER_ADDRESS"):
                    self.verify_lan(transfer, self.environment, temporary, port, smtp.server_address[1], credentials, document_id, payload)
                elif native_client and not os.environ.get("CANABICULTARR_TEST_MAC_UPGRADE_HELPER"):
                    previous_settings, previous_root = self.generate_lan_certificate(transfer, self.environment, "192.168.44.6")
                    denied = transfer("generate-lan-certificate", self.environment, {"hostname": "192.168.44.6"})
                    self.assertNotEqual(denied.returncode, 0)
                    self.assertEqual(subprocess.check_output([PHP, str(LAUNCHER), "export-lan-certificate"], env=self.environment), previous_root)
                    current, root = self.generate_lan_certificate(transfer, self.environment, "192.168.44.6", replace=True)
                    self.assertNotEqual(root, previous_root)
                    self.assertEqual(current["lanPreviousAuthority"], previous_settings["lanAuthority"])
                    self.assertEqual(current["lanPreviousFingerprint"], previous_settings["lanFingerprint"])
                    self.assertFalse(Path(previous_settings["lanKey"]).exists())
                    denied = transfer("remove-lan-certificate", self.environment, {"confirm": False})
                    self.assertNotEqual(denied.returncode, 0)
                    self.assertTrue(Path(current["lanKey"]).is_file())
                    result = transfer("remove-lan-certificate", self.environment, {"confirm": True})
                    self.assertEqual(result.returncode, 0, result.stderr)
                    removed = json.loads(subprocess.check_output([PHP, str(LAUNCHER), "settings"], env=self.environment))
                    self.assertFalse(removed["lanEnabled"])
                    self.assertEqual(removed["lanCert"], "")
                    self.assertEqual(removed["lanKey"], "")
                    self.assertEqual(removed["lanFingerprint"], "")
                    self.assertEqual(removed["lanPreviousFingerprint"], current["lanFingerprint"])
                    self.assertFalse(Path(current["lanKey"]).parent.exists())
            finally:
                self.stop()
                smtp.shutdown()


    def generate_lan_certificate(self, transfer, environment, host, *, replace=False):
        generated = transfer("generate-lan-certificate", environment, {"hostname": host, "replace": replace})
        self.assertEqual(generated.returncode, 0, generated.stderr)
        settings = json.loads(subprocess.check_output([PHP, str(LAUNCHER), "settings"], env=environment))
        self.assertFalse(settings["lanEnabled"])
        root_der = subprocess.check_output([PHP, str(LAUNCHER), "export-lan-certificate"], env=environment)
        self.assertEqual(settings["lanFingerprint"].replace(":", "").lower(), hashlib.sha256(root_der).hexdigest())
        profile = plistlib.loads(subprocess.check_output([PHP, str(LAUNCHER), "export-lan-profile"], env=environment))
        self.assertEqual(profile["PayloadType"], "Configuration")
        self.assertEqual(profile["PayloadDisplayName"], settings["lanAuthority"])
        self.assertEqual(len(profile["PayloadContent"]), 1)
        payload = profile["PayloadContent"][0]
        self.assertEqual(payload["PayloadType"], "com.apple.security.root")
        self.assertEqual(payload["PayloadContent"], root_der)
        self.assertNotEqual(uuid.UUID(profile["PayloadUUID"]), uuid.UUID(payload["PayloadUUID"]))
        return settings, root_der

    def verify_lan(self, transfer, environment, profile, port, smtp_port, credentials, document_id, document):
        browser_address = os.environ.get("CANABICULTARR_TEST_LAN_BROWSER_ADDRESS")
        host = browser_address or "cana-desktop.test"
        settings, root_der = self.generate_lan_certificate(transfer, environment, host)
        bind = socket.gethostbyname(host)
        with socket.socket() as reservation:
            reservation.bind((bind, 0))
            tls_port = reservation.getsockname()[1]
        fields = {
            "port": str(port), "host": "127.0.0.1", "smtpPort": str(smtp_port),
            "sender": "desktop@example.test", "lanEnabled": "1", "lanBind": host,
            "lanHost": host, "lanPort": str(tls_port),
            "lanCert": settings["lanCert"], "lanKey": settings["lanKey"],
        }
        invalid = transfer("configure", environment, {**fields, "lanBind": "0.0.0.0"})
        self.assertNotEqual(invalid.returncode, 0)
        non_lan = transfer("configure", environment, {**fields, "lanBind": "localhost"})
        self.assertNotEqual(non_lan.returncode, 0)
        configured = transfer("configure", environment, fields)
        self.assertEqual(configured.returncode, 0, configured.stderr)
        saved = json.loads(subprocess.check_output([PHP, str(LAUNCHER), "settings"], env=environment))
        self.assertEqual(saved["lanBind"], host)
        self.start()
        local_origin = self.origin
        self.request("/up", 400, headers={"X-Canabicultarr-Lan-Token": "0" * 64, "X-Canabicultarr-Lan-Client": bind})
        self.request("/up")
        self.origin = f"https://{host}:{tls_port}"
        context = ssl.create_default_context(cadata=ssl.DER_cert_to_PEM_cert(root_der))
        self.cookies = http.cookiejar.CookieJar()
        self.client = urllib.request.build_opener(
            urllib.request.HTTPSHandler(context=context), urllib.request.HTTPCookieProcessor(self.cookies)
        )
        try:
            for _ in range(50):
                try:
                    self.request("/up")
                    break
                except urllib.error.URLError:
                    time.sleep(0.15)
            self.request("/up")
            with self.assertRaises(urllib.error.URLError) as untrusted:
                urllib.request.urlopen(self.origin + "/up", timeout=4)
            self.assertIsInstance(untrusted.exception.reason, ssl.SSLCertVerificationError)
            self.assertNotEqual(transfer("generate-lan-certificate", environment, {"hostname": host}).returncode, 0)
            self.request("/up", 400, headers={"Host": "foreign.example.test"})
            self.request("/api/v1/auth/csrf", 204)
            self.assertTrue(any(cookie.name == "XSRF-TOKEN" and cookie.secure for cookie in self.cookies))
            self.assertTrue(any(cookie.name == "canabicultarr_desktop_session" and cookie.secure for cookie in self.cookies))
            self.request("/api/v1/setup", 404, data={})
            self.request("/api/v1/setup/oidc", 404, data={})
            self.request("/api/v1/auth/login", data=credentials)
            self.assertEqual(self.request(f"/api/v1/documents/{document_id}/download"), document)
            with self.client.open(self.origin + "/up") as response:
                self.assertIsNone(response.headers.get("X-Canabicultarr-Desktop-Run"))
            print("Desktop HTTPS: trusted TLS, secure cookies, local setup guard, and authenticated download passed.")
            self.verify_paired_offline_sync(document_id, document)
            if browser_address:
                public_key = subprocess.check_output(["openssl", "x509", "-in", settings["lanCert"], "-pubkey", "-noout"])
                key_der = subprocess.check_output(["openssl", "pkey", "-pubin", "-outform", "DER"], input=public_key)
                fingerprint = base64.b64encode(hashlib.sha256(key_der).digest()).decode()
                browser_input = {"origin": self.origin, "credentials": credentials, "fingerprint": fingerprint}
                manifest = ROOT / "frontend/dist/application.json"
                if manifest.is_file():
                    browser_input["version"] = json.loads(manifest.read_text())["version"]
                subprocess.run([
                    os.environ["CANABICULTARR_TEST_NODE"], str(Path(__file__).with_name("test-lan-browser.mjs")),
                ], input=json.dumps(browser_input), text=True, check=True, timeout=90)
            item_id = self.verify_concurrent_sessions(profile, context, credentials, document_id, document)
        finally:
            self.origin = local_origin
            self.stop()

        archive = Path(profile) / "network-backup.zip"
        password = "Desktop network fixture password"
        arguments = {"path": str(archive), "password": password}
        self.assertEqual(transfer("backup", environment, arguments).returncode, 0)
        with tempfile.TemporaryDirectory(prefix="cana-desktop-network-restore-") as restored:
            target = {**environment, "CANABICULTARR_DESKTOP_DATA_DIR": restored}
            result = transfer("restore", target, arguments)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn('LAN_ENABLED="0"', (Path(restored) / "desktop.env").read_text())
            self.assertFalse((Path(restored) / "lan-certificates").exists())
            with sqlite3.connect(Path(restored) / "canabicultarr.sqlite") as database:
                self.assertEqual(database.execute("SELECT total_units FROM inventory_items WHERE id = ?", (item_id,)).fetchone(), (2000,))
                self.assertEqual(database.execute("SELECT count(*) FROM inventory_movements WHERE inventory_item_id = ?", (item_id,)).fetchone(), (2,))


    def verify_paired_offline_sync(self, document_id, document):
        base = "/api/v1/account/mobile-devices"
        site = json.loads(self.request("/api/v1/sites", 201, data={"name": "Mobile sync fixture"}))["data"]
        self.request(base + "/offline-sites", 403)
        code = json.loads(self.request(base + "/pairing", 201, data={}))["data"]["code"]
        device = json.loads(self.request(base + "/redeem", 201, data={"code": code, "label": "Desktop LAN client"}))["data"]
        directory = json.loads(self.request(base + "/offline-sites"))["data"]
        self.assertEqual(directory["deviceId"], device["id"])
        self.assertIn(site["id"], directory["journalSiteIds"])

        file_base = base + "/offline-files"
        files = json.loads(self.request(file_base))["data"]
        self.assertIn(document_id, [entry["id"] for entry in files])
        selection = json.loads(self.request(file_base + "/selection", data={"ids": [document_id]}))["data"]
        self.assertEqual(selection["files"][0]["sha256"], hashlib.sha256(document).hexdigest())
        self.assertEqual(self.request(file_base + "/" + document_id), document)
        verified = json.loads(self.request(file_base + "/selection", data={"ticket": selection["ticket"]}))["data"]
        self.assertTrue(verified["valid"])

        def pull(cursor=None):
            query = {"version": directory["version"]}
            if cursor:
                query["cursor"] = cursor
            return json.loads(self.request(base + "/offline-journal?" + urllib.parse.urlencode(query)))["data"]

        initial = pull()
        entry = {
            "operation_id": str(uuid.uuid4()), "site_id": site["id"], "title": "Saved off-network",
            "body": "Journal sync on the installed desktop server.", "occurred_at": "2026-11-19T12:00:00Z",
            "account_id": directory["accountId"], "device_id": directory["deviceId"],
        }
        receipt = json.loads(self.request(base + "/journal-notes", 201, data=entry))["data"]
        self.assertEqual(json.loads(self.request(base + "/journal-notes", data=entry))["data"], receipt)
        changes = pull(initial["cursor"])
        self.assertEqual([(note["id"], note["title"]) for note in changes["changes"]], [(receipt["id"], entry["title"])])
        self.assertEqual(pull(changes["cursor"])["changes"], [])
        strain = json.loads(self.request("/api/v1/strains", 201, data={"name": "Mobile fixture strain"}))["data"]
        plants = "/api/v1/plants"
        plant = json.loads(self.request(plants, 201, data={
            "strain_id": strain["id"], "location": {"type": "site", "id": site["id"]},
            "request_id": str(uuid.uuid4()), "planned": True,
        }))["data"]
        for action in ("sow", "plant"):
            plant = json.loads(self.request(f'{plants}/{plant["id"]}/actions', data={
                "action": action, "version": plant["version"], "request_id": str(uuid.uuid4()),
            }))["data"]

        shelf = json.loads(self.request("/api/v1/inventory/locations", 201, data={"name": "Offline recipe shelf"}))["data"]
        product = json.loads(self.request("/api/v1/inventory/items", 201, data={
            "name": "Offline recipe product", "type": "fertilizer", "unit": "ml",
        }))["data"]
        product_url = f'/api/v1/inventory/items/{product["id"]}'
        self.request(product_url + "/movements", 201, data={
            "kind": "receipt", "quantity": "10", "version": product["version"], "request_id": str(uuid.uuid4()),
            "reason": "Offline recipe initial stock", "destination": {"id": shelf["id"]},
        })
        settings_url = "/api/v1/lifecycle-settings"
        workflow = json.loads(self.request(settings_url))["data"]
        stage = next(stage for stage in workflow["stages"] if stage["id"] == plant["phase"])
        stage["water_liters"] = "1.000"
        stage["fertilizer_periods"] = [{
            "id": str(uuid.uuid4()), "name": None, "unit": "days", "start": 0, "end": None,
            "products": [{"inventory_item_id": product["id"], "dose_per_liter": "2.000"}],
        }]
        self.request(settings_url, method="PATCH", data={key: workflow[key] for key in ("stages", "durations", "version")})

        catalog = json.loads(self.request(base + "/offline-plants?" + urllib.parse.urlencode({"version": directory["version"]})))["data"]
        self.assertEqual(len(catalog["plants"]), 1)
        saved = catalog["plants"][0]
        self.assertTrue(saved["canMaintain"])
        self.assertTrue(saved["canPhotograph"])
        self.assertEqual(saved["id"], plant["id"])
        self.assertEqual(saved["version"], plant["version"])
        maintenance = {
            "type": "Inspection", "comment": "Offline maintenance fixture", "version": saved["version"],
            "occurred_at": datetime.now(timezone.utc).isoformat(), "request_id": str(uuid.uuid4()),
            "account_id": directory["accountId"], "device_id": directory["deviceId"],
        }
        upload_url = f'{base}/offline-attachments/{plant["id"]}'

        def upload(name, media_type, content, gallery=False):
            upload_id = str(uuid.uuid4())
            boundary = "desktop-fixture-" + uuid.uuid4().hex
            fields = {"upload_id": upload_id, "version": saved["version"],
                      "account_id": directory["accountId"], "device_id": directory["deviceId"],
                      "gallery": int(gallery)}
            chunks = [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'
                      for key, value in fields.items()]
            chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\n'
                          f'Content-Type: {media_type}\r\n\r\n')
            body = ''.join(chunks).encode() + content + f'\r\n--{boundary}--\r\n'.encode()
            headers = {"Content-Type": "multipart/form-data; boundary=" + boundary}
            receipt = json.loads(self.request(upload_url, data=body, headers=headers))["data"]
            self.assertEqual(json.loads(self.request(upload_url, data=body, headers=headers))["data"], receipt)
            return upload_id, receipt

        image = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j0wAAAABJRU5ErkJggg==")
        _, photo = upload("gallery.png", "image/png", image, gallery=True)
        self.assertTrue(photo["gallery"])
        gallery = json.loads(self.request(f'{plants}/{plant["id"]}/photos'))["data"]
        self.assertEqual([(entry["id"], entry["isCover"]) for entry in gallery], [(photo["id"], False)])
        self.assertEqual(self.request(gallery[0]["url"]), image)
        upload_id, receipt = upload("inspection.txt", "text/plain", b"Offline inspection fixture.")
        maintenance["attachment_id"] = upload_id
        url = f'{base}/offline-maintenance/{plant["id"]}'
        self.assertEqual(json.loads(self.request(url, data=maintenance))["data"]["id"], plant["id"])
        self.assertEqual(json.loads(self.request(url, data=maintenance))["data"]["id"], plant["id"])
        conflict = self.request(url, 409, data={**maintenance, "request_id": str(uuid.uuid4())})
        self.assertEqual(json.loads(conflict)["error"]["code"], "plants.errors.conflict")
        events = json.loads(self.request(f'{plants}/{plant["id"]}/events'))["data"]
        maintained = [event for event in events if event["kind"] == "maintained"]
        self.assertEqual(len(maintained), 1)
        self.assertEqual([item["id"] for item in maintained[0]["attachments"]], [receipt["id"]])
        watering = {
            "with_products": True, "water_liters": "1.250", "version": saved["version"] + 1,
            "fertilizers": [{"inventory_item_id": product["id"], "unit": "ml", "dose_per_liter": "2.500",
                             "balance_id": saved["wateringRecipe"]["products"][0]["balances"][0]["id"]}],
            "occurred_at": datetime.now(timezone.utc).isoformat(), "request_id": str(uuid.uuid4()),
            "account_id": directory["accountId"], "device_id": directory["deviceId"],
        }
        watering_url = f'{base}/offline-watering/{plant["id"]}'
        for _ in range(2):
            result = self.request(watering_url, data=watering)
            self.assertEqual(json.loads(result)["data"]["id"], plant["id"])
        updated_events = json.loads(self.request(f'{plants}/{plant["id"]}/events'))["data"]
        water_events = [event for event in updated_events if event["kind"] == "watered"]
        self.assertEqual(len(water_events), 1)
        self.assertEqual(water_events[0]["watering"]["waterLiters"], "1.250")
        self.assertEqual(water_events[0]["watering"]["fertilizers"][0]["quantity"], "3.125")
        self.assertEqual(json.loads(self.request(product_url))["data"]["totalQuantity"], "6.875")

        creation = {
            "planned": True, "strain_id": strain["id"], "name": "Plant drafted offline",
            "location": {"type": "site", "id": site["id"]}, "request_id": str(uuid.uuid4()),
            "account_id": directory["accountId"], "device_id": directory["deviceId"],
        }
        created = json.loads(self.request(base + "/offline-plants", data=creation))["data"]
        self.assertEqual(json.loads(self.request(base + "/offline-plants", data=creation))["data"], created)
        planned = json.loads(self.request(f'{plants}/{created["id"]}'))["data"]
        self.assertEqual(planned["phase"], "planned")
        print("Desktop HTTPS: paired sync of journal, plant creation, maintenance with attachment, gallery photo replay, fertilizer deduction, and offline library passed.")


    def verify_concurrent_sessions(self, profile, context, credentials, document_id, document):
        location = json.loads(self.request("/api/v1/inventory/locations", 201, data={"name": "Concurrent fixture"}))["data"]
        item = json.loads(self.request("/api/v1/inventory/items", 201, data={"name": "Concurrent item", "unit": "piece"}))["data"]
        url = f'/api/v1/inventory/items/{item["id"]}'
        receipt = {
            "kind": "receipt", "quantity": "1", "reason": "Concurrent receipt",
            "version": item["version"], "request_id": str(uuid.uuid4()), "destination": {"id": location["id"]},
        }
        sessions = []
        for _ in range(6):
            session = DesktopRuntimeTest()
            session.origin = self.origin
            session.cookies = http.cookiejar.CookieJar()
            session.client = urllib.request.build_opener(
                urllib.request.HTTPSHandler(context=context), urllib.request.HTTPCookieProcessor(session.cookies),
            )
            session.request("/api/v1/auth/csrf", 204)
            session.request("/api/v1/auth/login", data=credentials)
            sessions.append(session)

        # A background writer holds SQLite's reserved write lock, as a queue job can.
        database = sqlite3.connect(Path(profile) / "canabicultarr.sqlite", check_same_thread=False)
        database.execute("BEGIN IMMEDIATE")
        release = threading.Timer(0.8, database.commit)
        release.start()
        started = time.perf_counter()
        try:
            first = json.loads(self.request(url + "/movements", 201, data=receipt))["data"]
            lock_wait_ms = round((time.perf_counter() - started) * 1000)
        finally:
            release.join()
            database.close()

        def parallel(operation):
            barrier = threading.Barrier(len(sessions), timeout=5)

            def send(session):
                barrier.wait()
                start = time.perf_counter()
                result = operation(session)
                return result, (time.perf_counter() - start) * 1000

            with ThreadPoolExecutor(max_workers=len(sessions)) as pool:
                return list(pool.map(send, sessions))

        replays = parallel(lambda session: json.loads(session.request(url + "/movements", 201, data=receipt))["data"])
        self.assertEqual({row["id"] for row, _ in replays}, {first["id"]})

        competing = parallel(lambda session: json.loads(session.request(url + "/movements", (201, 409), data={
            **receipt, "request_id": str(uuid.uuid4()), "version": first["version"],
        })))
        self.assertEqual(sum("data" in row for row, _ in competing), 1)
        self.assertEqual(
            {row["error"]["code"] for row, _ in competing if "error" in row}, {"inventory.errors.conflict"},
        )
        downloads = parallel(lambda session: session.request(f"/api/v1/documents/{document_id}/download"))
        self.assertTrue(all(content == document for content, _ in downloads))

        balances = json.loads(self.request(url + "/balances"))["data"]
        self.assertEqual([balance["quantity"] for balance in balances], ["2.000"])
        movements = json.loads(self.request(url + "/movements"))["data"]
        self.assertEqual(len(movements), 2)
        with sqlite3.connect(Path(profile) / "canabicultarr.sqlite") as database:
            self.assertEqual(database.execute("PRAGMA quick_check").fetchone(), ("ok",))
        peak_ms = round(max(duration for _, duration in replays + competing + downloads))
        print(f"Desktop concurrency: 6 authenticated sessions, lock recovery {lock_wait_ms} ms, peak parallel request {peak_ms} ms.")
        return item["id"]


if __name__ == "__main__":
    unittest.main()
