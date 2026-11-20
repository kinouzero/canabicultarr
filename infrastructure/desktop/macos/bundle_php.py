"""Relocate a macOS PHP CLI and its non-system dynamic libraries into the app."""

import hashlib
import re
import shutil
import subprocess
import sys
from pathlib import Path


def output(*command):
    return subprocess.check_output(command, text=True)


def bundle(php, target):
    target.mkdir()
    copied = {}
    notices = target / "notices"
    notices.mkdir()

    def install(source, name):
        real_source = source.resolve(strict=True)
        if name in copied:
            if copied[name] != real_source:
                raise ValueError(f"Conflicting dependency {name}")
            return target / name
        destination = target / name
        shutil.copy2(real_source, destination)
        destination.chmod(0o755)
        copied[name] = real_source
        for parent in real_source.parents:
            if parent.parent.name == "Cellar":
                for notice in parent.iterdir():
                    if notice.is_file() and re.match(r"(license|copying|copyright|notice)([._-]|$)", notice.name, re.I):
                        shutil.copy2(notice, notices / f"{parent.parent.name}-{notice.name}")
                break
        dependencies = [line.strip().split(" (compatibility version", 1)[0] for line in output("otool", "-L", str(real_source)).splitlines()[1:]]
        if destination.suffix == ".dylib":
            # The first entry is the library's install ID, not a dependency.
            subprocess.run(["install_name_tool", "-id", f"@loader_path/{name}", str(destination)], check=True, stderr=subprocess.DEVNULL)
            dependencies = dependencies[1:]
        for dependency in dependencies:
            if dependency.startswith(("/usr/lib/", "/System/Library/")):
                continue
            if dependency.startswith("@loader_path/"):
                dependency_path = real_source.parent / dependency.removeprefix("@loader_path/")
            elif dependency.startswith("@rpath/"):
                suffix = dependency.removeprefix("@rpath/")
                paths = re.findall(r"cmd LC_RPATH\s+cmdsize \d+\s+path (.*?) \(offset", output("otool", "-l", str(real_source)))
                candidates = [real_source.parent / suffix]
                for path in paths:
                    path = path.replace("@loader_path", str(real_source.parent))
                    if path.startswith("/"):
                        candidates.append(Path(path) / suffix)
                dependency_path = next((path for path in candidates if path.is_file()), None)
                if dependency_path is None:
                    raise ValueError(f"Unresolved dependency {dependency} in {real_source}")
            elif dependency.startswith("/"):
                dependency_path = Path(dependency)
            else:
                raise ValueError(f"Unresolved dependency {dependency} in {real_source}")
            installed = install(dependency_path, dependency_path.name)
            subprocess.run(["install_name_tool", "-change", dependency, f"@loader_path/{installed.name}", str(destination)], check=True, stderr=subprocess.DEVNULL)
        subprocess.run(["codesign", "--force", "--sign", "-", str(destination)], check=True, stderr=subprocess.DEVNULL)
        return destination

    install(php, "php")
    with (target / "manifest.txt").open("w") as manifest:
        for name, source in sorted(copied.items()):
            checksum = hashlib.file_digest(source.open("rb"), "sha256").hexdigest()
            manifest.write(f"{name} {checksum}\n")


if __name__ == "__main__":
    bundle(Path(sys.argv[1]), Path(sys.argv[2]))
