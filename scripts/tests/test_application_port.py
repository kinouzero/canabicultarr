"""Check public port/URL defaults without reading private environment files."""
import json
import importlib.util
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('test_environment', ROOT / 'scripts/test-environment.py')
test_environment = importlib.util.module_from_spec(spec)
spec.loader.exec_module(test_environment)


class ApplicationPortTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not shutil.which('docker'):
            raise unittest.SkipTest('Docker Compose is required.')

    def configuration(self, values='', development=False, testing=False, overrides=None):
        with tempfile.TemporaryDirectory(prefix='canabicultarr-port-') as directory:
            root = Path(directory)
            for filename in ['compose.yaml', 'compose.dev.yaml']:
                shutil.copyfile(ROOT / filename, root / filename)
            shutil.copyfile(ROOT / '.env.example', root / '.env.example')
            content = (ROOT / '.env.example').read_text()
            (root / '.env').write_text(content + '\n' + values)
            if testing:
                return test_environment.generate(root, root / 'acceptance')['services']
            environment = {key: value for key, value in os.environ.items()
                           if key in ['PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG']}
            environment.update(overrides or {})
            command = ['docker', 'compose', '--profile', '*', '--project-directory', directory, '-f', str(root / 'compose.yaml')]
            if development:
                command.extend(['-f', str(root / 'compose.dev.yaml')])
            result = subprocess.run(command + ['config', '--format', 'json'], env=environment,
                                    text=True, capture_output=True)
            if result.returncode:
                self.fail(result.stderr.strip())
            return json.loads(result.stdout)['services']

    def test_defaults_publish_port_8420_to_container_port_8420(self):
        services = self.configuration()
        app = services['app']
        version = json.loads((ROOT / 'frontend/package.json').read_text())['version']
        self.assertEqual(app['image'], f'ghcr.io/kinouzero/canabicultarr:{version}')
        self.assertNotIn('build', app)
        self.assertEqual(app['ports'][0]['published'], '8420')
        self.assertEqual(app['ports'][0]['target'], 8420)
        self.assertEqual(app['environment']['APP_URL'], 'http://localhost:8420')
        self.assertEqual(str(services['postgres']['environment']['POSTGRES_DB']), 'canabicultarr')
        self.assertEqual(app['environment']['DB_PORT'], '5432')
        self.assertEqual(services['rustfs']['ports'][0]['target'], 9001)

    def test_bundled_storage_reuses_generic_aws_credentials_and_console_port(self):
        services = self.configuration(overrides={
            'AWS_ACCESS_KEY_ID': 'fictional-storage-user',
            'AWS_SECRET_ACCESS_KEY': 'fictional-storage-password',
            'S3_CONSOLE_PORT': '19001',
        })
        storage = services['rustfs']
        self.assertEqual(storage['environment']['RUSTFS_ACCESS_KEY'], 'fictional-storage-user')
        self.assertEqual(storage['environment']['RUSTFS_SECRET_KEY'], 'fictional-storage-password')
        self.assertEqual(storage['ports'][0]['published'], '19001')
        self.assertEqual(storage['ports'][0]['host_ip'], '127.0.0.1')
        self.assertIn('AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY', storage['command'][0])

    def test_bundled_storage_rejects_missing_aws_credentials_at_startup(self):
        for overrides in [{}, {'AWS_ACCESS_KEY_ID': 'fictional-storage-user'},
                          {'AWS_SECRET_ACCESS_KEY': 'fictional-storage-password'}]:
            with self.subTest(provided=list(overrides)):
                storage = self.configuration(overrides=overrides)['rustfs']
                # Config output escapes dollars so it can be reloaded by Compose.
                shell_command = storage['command'][0].replace('$$', '$')
                result = subprocess.run(['sh', '-ec', shell_command],
                                        env=storage['environment'], text=True, capture_output=True)
                self.assertEqual(result.returncode, 1)
                self.assertIn('Set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY', result.stderr)

    def test_custom_port_also_changes_the_default_public_url(self):
        app = self.configuration(overrides={'APP_PORT': '18420'})['app']
        self.assertEqual(app['ports'][0]['published'], '18420')
        self.assertEqual(app['ports'][0]['target'], 8420)
        self.assertEqual(app['environment']['APP_URL'], 'http://localhost:18420')

    def test_published_images_do_not_replace_development_or_test_images(self):
        value = 'APP_IMAGE=ghcr.io/example/canabicultarr:0.1.0\n'
        self.assertEqual(self.configuration(value)['app']['image'], 'ghcr.io/example/canabicultarr:0.1.0')
        self.assertEqual(self.configuration(value, development=True)['app']['image'], 'canabicultarr-app-dev')
        self.assertEqual(self.configuration(value, testing=True)['app']['image'], 'canabicultarr-e2e-app')

    def test_reverse_proxy_url_and_existing_8080_configuration_are_preserved(self):
        for port, url in [('8080', 'http://localhost:8080'), ('18420', 'https://garden.example.test')]:
            with self.subTest(port=port):
                app = self.configuration(f'APP_PORT={port}\nAPP_URL={url}\n')['app']
                self.assertEqual(app['ports'][0]['published'], port)
                self.assertEqual(app['environment']['APP_URL'], url)

    def test_shared_environment_reaches_laravel_without_a_backend_copy(self):
        services = self.configuration('APP_KEY=fictional-test-key\nMAIL_HOST=smtp.example.test\nHOME_ASSISTANT_TOKEN=fictional-token\n')
        self.assertEqual(services['app']['environment']['APP_KEY'], 'fictional-test-key')
        self.assertEqual(services['app']['environment']['MAIL_HOST'], 'smtp.example.test')
        self.assertEqual(services['app']['environment']['HOME_ASSISTANT_TOKEN'], 'fictional-token')
        self.assertNotIn('HOME_ASSISTANT_TOKEN', services['rustfs']['environment'])

    def test_generated_acceptance_stack_has_its_own_cache_and_excludes_private_connections(self):
        services = self.configuration('HOME_ASSISTANT_TOKEN=fictional-secret\nPRIVATE_TEST_MARKER=not-for-acceptance\n', testing=True)
        app = services['app']['environment']
        self.assertNotIn('PRIVATE_TEST_MARKER', app)
        self.assertEqual(set(services), {'app', 'redis'})
        self.assertEqual(app['REDIS_HOST'], 'redis')
        self.assertEqual(app['CACHE_STORE'], 'redis')
        self.assertEqual(app['LOG_CHANNEL'], 'stderr')
        self.assertEqual(app['DB_CONNECTION'], 'sqlite')
        self.assertEqual(app['HOME_ASSISTANT_TOKEN'], 'e2e-home-assistant-token')

    def test_development_overrides_keep_the_same_public_port_contract(self):
        app = self.configuration(development=True)['app']
        self.assertEqual(app['ports'][0]['published'], '8420')
        self.assertEqual(app['ports'][0]['target'], 8420)
        self.assertEqual(app['environment']['APP_DEV_SERVER'], 'true')
        self.assertEqual(app['build']['target'], 'development')
        self.assertEqual(app['build']['dockerfile'], 'infrastructure/app/Dockerfile')


if __name__ == '__main__':
    unittest.main()
