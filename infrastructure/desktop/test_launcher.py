"""Exercise an installed desktop launcher against disposable data and local SMTP."""

from contextlib import nullcontext
import email.policy
import hashlib
import http.cookiejar
import io
import json
import os
from pathlib import Path
import plistlib
import queue
import re
import socket
import socketserver
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from email.parser import BytesParser

WINDOWS = os.environ.get("CANABICULTARR_TEST_WINDOWS_ROOT")
PACKAGE = Path(WINDOWS or "/opt/canabicultarr")
os.environ["CANABICULTARR_TEST_DESKTOP_LAUNCHER"] = str(PACKAGE / "project/infrastructure/desktop/run.php")
from test_runtime import DesktopRuntimeTest, SMTPFixture, assert_migrated, defer_migration


def fetch(url, *, data=None, headers=None, expected=200):
    request = urllib.request.Request(url, data, headers or {})
    try:
        response = urllib.request.urlopen(request, timeout=35 if WINDOWS else 15)
    except urllib.error.HTTPError as failure:
        response = failure
    with response:
        assert response.code == expected, f"{urlparse_path(url)} returned {response.code} instead of {expected}"
        return response.read()


def urlparse_path(url):
    return urllib.parse.urlparse(url).path.rsplit("/", 1)[-1] or "/"


def check(*, data_dir=None, while_running=None, upgrade_proof=False):
    if os.name != "nt":
        assert os.geteuid() != 0
    root = PACKAGE
    if WINDOWS:
        assert (root / "Canabicultarr.ico").is_file()
        command = [str(root / "Canabicultarr.exe")]
        if os.name != "nt":
            command.insert(0, "/usr/lib/wine/wine64")
    else:
        entries = Path("/usr/share/applications/canabicultarr.desktop").read_text()
        assert "Icon=canabicultarr" in entries and "Terminal=false" in entries
        assert Path("/usr/share/icons/hicolor/512x512/apps/canabicultarr.png").is_file()
        command = [str(root / "canabicultarr-desktop")]
    profile = nullcontext(str(data_dir)) if data_dir else tempfile.TemporaryDirectory(prefix="cana-desktop-")
    with profile as directory, socketserver.TCPServer(
        ("127.0.0.1", 0), SMTPFixture
    ) as smtp:
        smtp.messages = queue.Queue()
        threading.Thread(target=smtp.serve_forever, daemon=True).start()
        with socket.socket() as reservation:
            reservation.bind(("127.0.0.1", 0))
            port = reservation.getsockname()[1]
        environment = {
            **os.environ,
            "CANABICULTARR_DESKTOP_DATA_DIR": directory,
            "CANABICULTARR_DESKTOP_NO_BROWSER": "1",
        }
        if WINDOWS and data_dir:
            environment.pop("CANABICULTARR_DESKTOP_DATA_DIR")
        elif WINDOWS and os.name != "nt":
            environment["CANABICULTARR_DESKTOP_DATA_DIR"] = "Z:" + directory.replace("/", "\\")
        launcher = subprocess.Popen(command, env=environment)
        try:
            address_file = Path(directory) / "launcher-url"
            for _ in range(400 if WINDOWS else 80):
                assert launcher.poll() is None, "Launcher terminated during startup"
                if address_file.is_file():
                    break
                time.sleep(0.1)
            if not WINDOWS:
                assert address_file.stat().st_mode & 0o777 == 0o600
            address = address_file.read_text()
            origin = f"http://{urllib.parse.urlsplit(address).netloc}"
            app = f"http://127.0.0.1:{port}"
            language = {"Accept-Language": "fr"}
            initial = fetch(address, headers=language).decode()
            assert "Configurer Canabicultarr" in initial
            assert re.search(r'<input[^>]*name="port"[^>]*value="8420"', initial)
            palette = json.loads((root / "branding/palette.json").read_text())["colors"]
            assert palette["forest_green"] in initial
            assert hashlib.sha256(fetch(address + "logo")).digest() == hashlib.sha256((root / "branding/mark.png").read_bytes()).digest()
            fetch(origin + "/", expected=404)
            fetch(address, data=b"action=stop", headers={"Origin": "https://foreign.invalid"}, expected=403)
            assert not (Path(directory) / "canabicultarr.sqlite").exists()

            def submit(values):
                return fetch(address, data=urllib.parse.urlencode(values).encode(), headers={**language, "Origin": origin}).decode()

            gmail = submit({"action": "gmail", "port": port, "smtpPort": "587"})
            assert 'value="smtp.gmail.com"' in gmail
            administrator = submit({
                "action": "save", "port": port, "host": "127.0.0.1", "smtpPort": smtp.server_address[1],
                "sender": "sender@example.test", "senderName": "Canabicultarr fixture",
            })
            assert "Créer le premier administrateur" in administrator
            email_address = "desktop-admin@example.test"
            password = "Fictional desktop admin 123!"
            ready = submit({"action": "administrator", "name": "Desktop fixture", "email": email_address, "password": password, "confirmation": password})
            assert email_address in ready and "Ouvrir Canabicultarr" in ready
            message = BytesParser(policy=email.policy.default).parsebytes(smtp.messages.get(timeout=15))
            body = message.get_body(preferencelist=("plain", "html")).get_content()
            token = re.search(r"#activate-account=([A-Za-z0-9]{64})", body)
            assert token and app + "/#activate-account=" in body

            helper = DesktopRuntimeTest()
            helper.origin = app
            helper.cookies = http.cookiejar.CookieJar()
            helper.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(helper.cookies))
            helper.request("/api/v1/auth/csrf", 204)
            credentials = {"email": email_address, "password": password}
            helper.request("/api/v1/auth/login", 403, data=credentials)
            helper.request("/api/v1/auth/activation/confirm", 204, data={"email": email_address, "token": token[1]})
            helper.request("/api/v1/auth/login", data=credentials)
            assert json.loads(helper.request("/api/v1/auth/user"))["data"]["email"] == email_address
            if not upgrade_proof:
                diagnostics = fetch(address + "runtime-log", headers=language)
                assert b"services-launched" in diagnostics
                assert email_address.encode() not in diagnostics and password.encode() not in diagnostics
                fetch(origin + "/runtime-log", headers=language, expected=404)
                fetch(address + "runtime-log", data=b"", headers={**language, "Origin": origin}, expected=404)
            if while_running:
                while_running()
            assert "serveur local est arrêté" in submit({"action": "stop"})
            assert launcher.wait(timeout=12) == 0
            assert not address_file.exists()
            launcher = subprocess.Popen(command, env=environment)
            for _ in range(300 if WINDOWS else 100):
                assert launcher.poll() is None, "Launcher terminated during restart"
                if address_file.is_file():
                    break
                time.sleep(0.1)
            address = address_file.read_text()
            origin = f"http://{urllib.parse.urlsplit(address).netloc}"
            assert "Canabicultarr est prêt" in fetch(address, headers=language).decode()
            assert json.loads(helper.request("/api/v1/auth/user"))["data"]["email"] == email_address
            backup_password = "Desktop fixture archive password 456!"
            backup_data = urllib.parse.urlencode({"password": backup_password}).encode()
            post_headers = {**language, "Origin": origin}
            fetch(address + "backup", data=backup_data, headers=post_headers, expected=409)
            submit({"action": "edit"})
            if not upgrade_proof:
                generated = submit({"action": "generate-certificate", "lanHost": "cana-desktop.test"})
                assert "Certificat créé" in generated, generated
                assert "Approuver le certificat" in generated
                assert "Profil téléchargé" in generated
                assert "Activer la confiance totale pour les certificats racine" in generated
                certificate = fetch(address + "trust-certificate", headers=language)
                profile = plistlib.loads(fetch(address + "trust-profile", headers=language))
                assert profile["PayloadContent"][0]["PayloadContent"] == certificate
                fetch(origin + "/trust-profile", headers=language, expected=404)
                fingerprint = ":".join(f"{byte:02X}" for byte in hashlib.sha256(certificate).digest())
                assert fingerprint in generated
                assert "Remplacer le certificat local" in generated
                rejected = submit({"action": "generate-certificate", "lanHost": "cana-desktop.test"})
                assert fingerprint in rejected
                assert fetch(address + "trust-certificate", headers=language) == certificate
                replaced = submit({"action": "replace-certificate", "lanHost": "cana-desktop.test"})
                assert "Ancien certificat" in replaced and fingerprint in replaced
                assert fetch(address + "trust-certificate", headers=language) != certificate
                assert "Rétablir le nom et le certificat par défaut" in replaced
                removed = submit({"action": "remove-certificate"})
                assert "le HTTPS sur le réseau local est désactivé" in removed
                assert "Ancien certificat" in removed
                fetch(address + "trust-certificate", headers=language, expected=404)
            fetch(address + "backup", data=backup_data, headers={"Origin": "https://foreign.invalid"}, expected=403)
            archive = fetch(address + "backup", data=backup_data, headers=post_headers)
            with zipfile.ZipFile(io.BytesIO(archive)) as encrypted:
                assert set(encrypted.namelist()) == {"desktop.env", "canabicultarr.sqlite"}
                assert all(entry.flag_bits & 1 for entry in encrypted.infolist())
            assert "Planification enregistrée" in submit({
                "action": "schedule", "frequency": "weekly", "directory": "", "keep": "2", "password": backup_password,
            })
            assert "Canabicultarr est prêt" in submit({
                "action": "save", "port": port, "host": "127.0.0.1", "smtpPort": smtp.server_address[1],
                "sender": "sender@example.test", "senderName": "Canabicultarr fixture",
            })
            for _ in range(300):
                files = list((Path(directory) / "backups").glob("canabicultarr-auto-*.zip"))
                if files:
                    break
                time.sleep(0.1)
            assert len(files) == 1
            fetch(app + "/up")
            if upgrade_proof:
                boundary = "UpgradeDocumentFixture"
                upload = (
                    f'--{boundary}\r\nContent-Disposition: form-data; name="title"\r\n\r\nDesktop upgrade document\r\n'
                    f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="upgrade.txt"\r\n'
                    'Content-Type: text/plain\r\n\r\n'
                ).encode() + UPGRADE_DOCUMENT + f"\r\n--{boundary}--\r\n".encode()
                document = json.loads(helper.request("/api/v1/documents", 201, data=upload, headers={
                    "Content-Type": f"multipart/form-data; boundary={boundary}",
                }))["data"]["id"]
                proof = {"port": port, "document": document, "credentials": credentials}
            assert "serveur local est arrêté" in submit({"action": "stop"})
            assert launcher.wait(timeout=12) == 0

            # Keep the alternate restore profile on the Windows user drive under Wine.
            wine_drive = Path(os.environ["WINEPREFIX"]) / "drive_c" if WINDOWS and os.name != "nt" else None
            restore_parent = Path(directory).parent if wine_drive and data_dir else wine_drive
            with tempfile.TemporaryDirectory(prefix="cana-restored-wizard-", dir=restore_parent) as restored:
                target = "C:\\" + str(Path(restored).relative_to(wine_drive)).replace("/", "\\") if wine_drive else restored
                test_restore(command, {**environment, "CANABICULTARR_DESKTOP_DATA_DIR": target},
                             Path(restored), archive, backup_password, submit_headers=language)
            if upgrade_proof:
                defer_migration(directory)
                return proof
        finally:
            if launcher.poll() is None:
                launcher.terminate()
                launcher.wait(timeout=12)
            smtp.shutdown()


UPGRADE_DOCUMENT = b"Private Desktop update fixture.\n"


def verify_upgrade(profile, proof):
    application = PACKAGE / ("Canabicultarr.exe" if WINDOWS else "canabicultarr-desktop")
    command = [str(application)]
    if WINDOWS and os.name != "nt":
        command.insert(0, "/usr/lib/wine/wine64")
    environment = {**os.environ, "CANABICULTARR_DESKTOP_NO_BROWSER": "1"}
    if WINDOWS:
        environment.pop("CANABICULTARR_DESKTOP_DATA_DIR", None)
    else:
        environment["CANABICULTARR_DESKTOP_DATA_DIR"] = str(profile)
    process = subprocess.Popen(command, env=environment)
    try:
        locator = profile / "launcher-url"
        for _ in range(400 if WINDOWS else 100):
            assert process.poll() is None, "Upgrade launcher terminated"
            if locator.is_file():
                break
            time.sleep(0.1)
        address = locator.read_text()
        assert "Canabicultarr est prêt" in fetch(address, headers={"Accept-Language": "fr"}).decode()
        assert_migrated(profile)
        helper = DesktopRuntimeTest()
        helper.origin = f'http://127.0.0.1:{proof["port"]}'
        helper.cookies = http.cookiejar.CookieJar()
        helper.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(helper.cookies))
        helper.request("/api/v1/auth/csrf", 204)
        helper.request("/api/v1/auth/login", data=proof["credentials"])
        assert helper.request(f'/api/v1/documents/{proof["document"]}/download') == UPGRADE_DOCUMENT
        origin = f"http://{urllib.parse.urlsplit(address).netloc}"
        fetch(address, data=b"action=stop", headers={"Origin": origin})
        assert process.wait(timeout=12) == 0
        print("Desktop update: pending migration, account, and private document passed.")
    finally:
        if process.poll() is None:
            process.terminate()
            process.wait(timeout=12)


def test_restore(command, environment, directory, archive, password, *, submit_headers):
    process = subprocess.Popen(command, env=environment)
    try:
        locator = directory / "launcher-url"
        for _ in range(400 if WINDOWS else 80):
            assert process.poll() is None, "Restore launcher terminated during startup"
            if locator.is_file():
                break
            time.sleep(0.1)
        address = locator.read_text()
        origin = f"http://{urllib.parse.urlsplit(address).netloc}"
        boundary = "BackupRestoreFixture"
        body = (
            f'--{boundary}\r\nContent-Disposition: form-data; name="password"\r\n\r\n{password}\r\n'
            f'--{boundary}\r\nContent-Disposition: form-data; name="archive"; filename="backup.zip"\r\n'
            'Content-Type: application/zip\r\n\r\n'
        ).encode() + archive + f"\r\n--{boundary}--\r\n".encode()
        headers = {**submit_headers, "Origin": origin, "Content-Type": f"multipart/form-data; boundary={boundary}"}
        restored = fetch(address + "restore", data=body, headers=headers).decode()
        assert "Sauvegarde restaurée" in restored
        assert (directory / "canabicultarr.sqlite").is_file()
        assert 'value="sender@example.test"' in restored
        fetch(address, data=b"action=stop", headers={"Origin": origin})
        assert process.wait(timeout=12) == 0
    finally:
        if process.poll() is None:
            process.terminate()
            process.wait(timeout=12)


if __name__ == "__main__":
    if len(sys.argv) == 3:
        directory = Path(sys.argv[2])
        proof_path = directory / "upgrade-test-proof.json"
        if sys.argv[1] == "prepare-upgrade":
            proof_path.write_text(json.dumps(check(data_dir=directory, upgrade_proof=True)))
        elif sys.argv[1] == "verify-upgrade":
            verify_upgrade(directory, json.loads(proof_path.read_text()))
        else:
            raise SystemExit("Unknown upgrade test phase")
    else:
        check()
    package = "Windows installer payload" if WINDOWS else "Installed Linux package"
    print(f"{package}: branding, settings, SMTP activation, login and shutdown passed.")
