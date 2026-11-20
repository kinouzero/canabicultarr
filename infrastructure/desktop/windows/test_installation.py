"""Verify that the generated Windows installer preserves data across its lifecycle."""

import hashlib
import os
from pathlib import Path
import shutil
import subprocess

prefix = Path(os.environ["WINEPREFIX"])
local_app_data = prefix / "drive_c/users" / os.environ["USER"] / "AppData/Local"
install_dir = local_app_data / "Programs/Canabicultarr"
data_dir = local_app_data / "Canabicultarr/Data"
installer = "/packages/Canabicultarr-Desktop-preview-win-x64.exe"
shortcut = local_app_data.parent / "Roaming/Microsoft/Windows/Start Menu/Programs/Canabicultarr.lnk"
os.environ["CANABICULTARR_TEST_WINDOWS_ROOT"] = str(install_dir)

from test_launcher import check, verify_upgrade


def wine(executable, *arguments, status=0):
    result = subprocess.run(["/usr/lib/wine/wine64", str(executable), *arguments], timeout=180, check=False)
    assert result.returncode == status, f"{Path(executable).name} returned {result.returncode} instead of {status}"


def uninstall(directory=install_dir, *, status=0):
    # NSIS normally launches a temporary copy asynchronously. Run the same copy
    # explicitly to observe its status and avoid locking the program directory.
    temporary = Path(os.environ["HOME"]) / "uninstaller.exe"
    shutil.copy2(install_dir / "Uninstall.exe", temporary)
    windows_directory = ("C:/" + str(directory.relative_to(prefix / "drive_c"))).replace("/", "\\")
    wine(temporary, "/S", f"_?={windows_directory}", status=status)


def assert_program_installed():
    assert (install_dir / "Canabicultarr.exe").is_file()
    assert (install_dir / "Uninstall.exe").is_file()
    assert (install_dir / "runtime/php.exe").is_file()
    assert shortcut.is_file()


def check_running_guard():
    wine(installer, "/S", status=2)
    uninstall(status=2)
    assert_program_installed()


def fingerprint(path):
    return hashlib.sha256(path.read_bytes()).digest()


def main():
    previous = Path("/previous") / Path(installer).name
    upgrade = previous.is_file()
    wine(previous if upgrade else installer, "/S")
    assert_program_installed()
    proof = check(data_dir=data_dir, while_running=check_running_guard, upgrade_proof=upgrade)
    obsolete = install_dir / "project/obsolete-upgrade-test"
    if upgrade:
        obsolete.write_text("Obsolete file from the previous installation.")

    document = data_dir / "storage/app/documents/private-fixture.txt"
    document.write_bytes(b"Disposable document for the uninstall test.")
    persistent = [data_dir / "desktop.env", data_dir / "canabicultarr.sqlite", document]
    digests = [fingerprint(path) for path in persistent]

    # A crash may leave a locator file behind. Only a live launcher blocks changes.
    (data_dir / "launcher-url").write_text("http://127.0.0.1:1/")
    wine(installer, "/S")
    assert_program_installed()
    assert [fingerprint(path) for path in persistent] == digests
    if upgrade:
        assert not obsolete.exists()
        (data_dir / "launcher-url").unlink()
        verify_upgrade(data_dir, proof)
        digests = [fingerprint(path) for path in persistent]

    other_directory = local_app_data / "Unrelated"
    other_directory.mkdir()
    unrelated_file = other_directory / "runtime" / "keep.txt"
    unrelated_file.parent.mkdir()
    unrelated_file.write_text("This file is outside the installation directory.")
    uninstall(other_directory, status=2)
    assert unrelated_file.is_file()

    uninstall()
    assert not install_dir.exists()
    assert [fingerprint(path) for path in persistent] == digests
    assert not shortcut.exists()

    wine(installer, "/S")
    assert_program_installed()
    assert [fingerprint(path) for path in persistent] == digests
    print("Windows installer: installation, running guard, reinstall, uninstall and data preservation passed.")


if __name__ == "__main__":
    main()
