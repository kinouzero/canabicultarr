"""Exercise entrypoint service gating without Docker or deployment credentials."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ContainerStartupTest(unittest.TestCase):
    def startup(self, arguments=('supervisord',), **overrides):
        with tempfile.TemporaryDirectory(prefix='canabicultarr-startup-') as directory:
            root = Path(directory)
            application = root / 'application'
            (application / 'bootstrap/cache').mkdir(parents=True)
            binaries = root / 'bin'
            binaries.mkdir()
            events = root / 'events'
            programs = {
                'chown': 'exit 0',
                'find': 'exit 0',
                'ln': 'exit 0',
                'su': 'printf "initialize\\n" >> "$EVENTS"; exit "${INITIALIZATION_EXIT:-0}"',
                'supervisord': 'printf "services\\n" >> "$EVENTS"',
                'php': 'printf "artisan\\n" >> "$EVENTS"',
            }
            for name, content in programs.items():
                program = binaries / name
                program.write_text('#!/bin/sh\n' + content + '\n')
                program.chmod(0o755)
            # Translate only the image filesystem location into a temporary fixture.
            source = (ROOT / 'infrastructure/app/entrypoint.sh').read_text()
            script = root / 'entrypoint.sh'
            script.write_text(source.replace('cd /var/www/html', 'cd "' + str(application) + '"'))
            environment = {'PATH': str(binaries) + os.pathsep + os.environ['PATH'],
                           'APP_KEY': 'fictional-test-key', 'APP_DEV_SERVER': 'false',
                           'EVENTS': str(events), **overrides}
            result = subprocess.run(['sh', str(script), *arguments], env=environment,
                                    text=True, capture_output=True)
            return result, events.read_text().splitlines() if events.exists() else []

    def test_default_startup_initializes_before_services(self):
        result, events = self.startup()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(events, ['initialize', 'services'])

    def test_failed_initialization_never_starts_services(self):
        result, events = self.startup(INITIALIZATION_EXIT='1')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(events, ['initialize'])
        self.assertIn('Application services will not start', result.stderr)

    def test_manual_migration_mode_and_one_off_artisan_commands_bypass_initialization(self):
        result, events = self.startup(APP_AUTO_MIGRATE='false')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(events, ['services'])
        result, events = self.startup(arguments=('php', 'artisan', 'key:generate'))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(events, ['artisan'])

    def test_missing_key_and_invalid_mode_fail_before_initialization(self):
        for overrides in [{'APP_KEY': ''}, {'APP_AUTO_MIGRATE': 'invalid'}]:
            with self.subTest(overrides=overrides):
                result, events = self.startup(**overrides)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(events, [])
