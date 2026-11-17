"""Generate a disposable acceptance stack from the two deployment Compose files."""
import json
import os
from pathlib import Path
import subprocess
import sys

# Fictional providers only; this configuration must never load the deployment .env.
ENVIRONMENT = {
    'APP_ENV': 'testing',
    'LOG_CHANNEL': 'stderr',
    'APP_URL': 'http://127.0.0.1:18080',
    'APP_QUEUE_WORKER_ENABLED': 'false',
    'APP_SCHEDULER_ENABLED': 'false',
    'APP_MQTT_WORKER_ENABLED': 'false',
    'MQTT_ENABLED': 'true',
    'MQTT_HOST': 'fictional-e2e-broker',
    'MQTT_PORT': '1883',
    'MQTT_TLS': 'false',
    'MQTT_USERNAME': '',
    'MQTT_PASSWORD': '',
    'MQTT_CA_FILE': '',
    'OIDC_ENABLED': 'false',
    'PASSWORD_RECOVERY_ENABLED': 'true',
    'PASSWORD_RECOVERY_URL': 'http://127.0.0.1:18080/',
    'MAIL_MAILER': 'smtp',
    'MAIL_HOST': '127.0.0.1',
    'MAIL_PORT': '2526',
    'MAIL_USERNAME': '',
    'MAIL_PASSWORD': '',
    'MAIL_FROM_ADDRESS': 'notifications@example.test',
    'ALERT_WEBHOOK_URL': 'http://127.0.0.1:8126/notifications',
    'ALERT_WEBHOOK_TOKEN': 'fictional-notification-token',
    'ALERT_WEBHOOK_FORMAT': 'generic',
    'ALERT_WEBHOOK_TOPIC': 'e2e-alerts',
    'ALERT_WEBHOOK_APPRISE_TAG': '',
    'ALERT_WEBHOOK_APPRISE_URLS': '',
    'WEB_PUSH_PUBLIC_KEY': 'BJbBVYcjRNdlfmp7m__Chw4nwJMfA87zG6e9l2gJryzA_FJM3r9yKjIFXeCrHubbSgfxkvVbFOILqvmyPVxn2F4',
    'WEB_PUSH_PRIVATE_KEY': 'llHkOq7PBH7IQNCDP0M_8X01Cnyl_SKbd8Rleq95Ruo',
    'WEB_PUSH_SUBJECT': 'mailto:notifications@example.test',
    'WEB_PUSH_ALLOWED_HOSTS': 'fcm.googleapis.com',
    'HOME_ASSISTANT_URL': 'http://127.0.0.1:8124',
    'HOME_ASSISTANT_TOKEN': 'e2e-home-assistant-token',
    'APP_DEBUG': 'false',
    'APP_KEY': 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
    'DB_CONNECTION': 'sqlite',
    'DB_DATABASE': '/var/www/html/storage/e2e.sqlite',
    'DOCUMENTS_DISK': 'documents',
    'REDIS_HOST': 'redis',
    'CACHE_STORE': 'redis',
    'SESSION_DRIVER': 'database',
    'SESSION_COOKIE': 'canabicultarr_e2e_session',
    'QUEUE_CONNECTION': 'database',
    'ARGON_MEMORY': '4096',
    'ARGON_TIME': '1',
}


def generate(root, workspace, target='development', coverage=False, dev_server=False):
    root, workspace = Path(root).resolve(), Path(workspace).resolve()
    if target not in ['runtime', 'development']:
        raise ValueError('Unsupported acceptance build target.')
    workspace.mkdir(parents=True, exist_ok=True)
    safe_env = workspace / 'acceptance.env'
    safe_env.write_text((root / '.env.example').read_text())
    safe_env.chmod(0o600)
    environment = {key: value for key, value in os.environ.items()
                   if key in ['PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH']}
    environment.update({'DB_PASSWORD': 'ci-e2e-password', 'APP_ENV_FILE': str(safe_env),
                        'COMPOSE_PROJECT_NAME': 'canabicultarr-e2e'})
    result = subprocess.run(['docker', 'compose', '--project-directory', str(root), '--env-file', str(safe_env),
                             '-f', str(root / 'compose.yaml'), '-f', str(root / 'compose.dev.yaml'),
                             'config', '--format', 'json'], env=environment, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError('Acceptance configuration failed: ' + result.stderr.strip())
    configuration = json.loads(result.stdout)
    app = configuration['services']['app']
    app['image'] = 'canabicultarr-e2e-app'
    app['build']['target'] = target
    app['build']['args'] = {'APP_COVERAGE': '1' if coverage else '0'}
    app['environment'] = {**app.get('environment', {}), **ENVIRONMENT, 'APP_DEV_SERVER': str(dev_server).lower()}
    for field in ['env_file', 'depends_on', 'develop']:
        app.pop(field, None)
    app['ports'] = ['127.0.0.1:18000:8420', '127.0.0.1:18080:8420']
    app['volumes'] = [{'type': 'volume', 'source': 'backend_storage', 'target': '/var/www/html/storage'}]
    configuration['services'] = {'app': app, 'redis': configuration['services']['redis']}
    configuration['name'] = 'canabicultarr-e2e'
    configuration['volumes'] = {name: {'name': 'canabicultarr-e2e_' + name} for name in ['backend_storage', 'redis_data']}
    configuration['networks'] = {'default': {'name': 'canabicultarr-e2e_default'}}
    return configuration


if __name__ == '__main__':
    destination = Path(sys.argv[1]).resolve()
    config = generate(Path(__file__).resolve().parents[1], destination.parent,
                      os.environ.get('APP_BUILD_TARGET', 'development'), os.environ.get('APP_COVERAGE') == '1',
                      os.environ.get('E2E_DEV_SERVER') == 'true')
    destination.write_text(json.dumps(config))
    destination.chmod(0o600)
