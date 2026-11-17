<?php

// Environment-provided fields are authoritative, including explicit false/zero values.
$groups = [
    'authentication' => [
        'AUTH_LOCAL_ENABLED' => ['path' => 'auth.local_enabled', 'type' => 'boolean', 'options' => []],
        'AUTH_REGISTRATION_ENABLED' => ['path' => 'auth.registration_enabled', 'type' => 'boolean', 'options' => []],
    ],
    'storage' => [
        'DOCUMENTS_DISK' => ['path' => 'documents.disk', 'type' => 'select', 'options' => ['documents', 'documents_s3']],
        'AWS_ENDPOINT' => ['path' => 'filesystems.disks.documents_s3.endpoint', 'type' => 'url', 'options' => []],
        'AWS_DEFAULT_REGION' => ['path' => 'filesystems.disks.documents_s3.region', 'type' => 'text', 'options' => []],
        'AWS_BUCKET' => ['path' => 'filesystems.disks.documents_s3.bucket', 'type' => 'text', 'options' => []],
        'AWS_ACCESS_KEY_ID' => ['path' => 'filesystems.disks.documents_s3.key', 'type' => 'secret', 'options' => []],
        'AWS_SECRET_ACCESS_KEY' => ['path' => 'filesystems.disks.documents_s3.secret', 'type' => 'secret', 'options' => []],
        'AWS_USE_PATH_STYLE_ENDPOINT' => ['path' => 'filesystems.disks.documents_s3.use_path_style_endpoint', 'type' => 'boolean', 'options' => []],
    ],
    'mail' => [
        'MAIL_MAILER' => ['path' => 'mail.default', 'type' => 'select', 'options' => ['smtp', 'log']],
        'MAIL_HOST' => ['path' => 'mail.mailers.smtp.host', 'type' => 'text', 'options' => []],
        'MAIL_PORT' => ['path' => 'mail.mailers.smtp.port', 'type' => 'port', 'options' => []],
        'MAIL_USERNAME' => ['path' => 'mail.mailers.smtp.username', 'type' => 'text', 'options' => []],
        'MAIL_PASSWORD' => ['path' => 'mail.mailers.smtp.password', 'type' => 'secret', 'options' => []],
        'MAIL_FROM_ADDRESS' => ['path' => 'mail.from.address', 'type' => 'email', 'options' => []],
        'MAIL_FROM_NAME' => ['path' => 'mail.from.name', 'type' => 'text', 'options' => []],
    ],
    'home_assistant' => [
        'HOME_ASSISTANT_URL' => ['path' => 'home_assistant.url', 'type' => 'url', 'options' => []],
        'HOME_ASSISTANT_TOKEN' => ['path' => 'home_assistant.token', 'type' => 'secret', 'options' => []],
        'HOME_ASSISTANT_SYNC_ENABLED' => ['path' => 'home_assistant.sync_enabled', 'type' => 'boolean', 'options' => []],
        'HOME_ASSISTANT_SYNC_INTERVAL_SECONDS' => ['path' => 'home_assistant.sync_interval_seconds', 'type' => 'interval', 'options' => []],
        'HOME_ASSISTANT_EVENTS_ENABLED' => ['path' => 'home_assistant.events_enabled', 'type' => 'boolean', 'options' => []],
    ],
    'homebridge' => [
        'HOMEBRIDGE_URL' => ['path' => 'homebridge.url', 'type' => 'url', 'options' => []],
        'HOMEBRIDGE_USERNAME' => ['path' => 'homebridge.username', 'type' => 'text', 'options' => []],
        'HOMEBRIDGE_PASSWORD' => ['path' => 'homebridge.password', 'type' => 'secret', 'options' => []],
        'HOMEBRIDGE_SYNC_ENABLED' => ['path' => 'homebridge.sync_enabled', 'type' => 'boolean', 'options' => []],
        'HOMEBRIDGE_SYNC_INTERVAL_SECONDS' => ['path' => 'homebridge.sync_interval_seconds', 'type' => 'interval', 'options' => []],
        'HOMEBRIDGE_EVENTS_ENABLED' => ['path' => 'homebridge.events_enabled', 'type' => 'boolean', 'options' => []],
    ],
    'mqtt' => [
        'MQTT_ENABLED' => ['path' => 'mqtt.enabled', 'type' => 'boolean', 'options' => []],
        'MQTT_HOST' => ['path' => 'mqtt.host', 'type' => 'text', 'options' => []],
        'MQTT_PORT' => ['path' => 'mqtt.port', 'type' => 'port', 'options' => []],
        'MQTT_TLS' => ['path' => 'mqtt.tls', 'type' => 'boolean', 'options' => []],
        'MQTT_USERNAME' => ['path' => 'mqtt.username', 'type' => 'text', 'options' => []],
        'MQTT_PASSWORD' => ['path' => 'mqtt.password', 'type' => 'secret', 'options' => []],
        'MQTT_CA_FILE' => ['path' => 'mqtt.ca_file', 'type' => 'text', 'options' => []],
    ],
    'oidc' => [
        'OIDC_ENABLED' => ['path' => 'oidc.enabled', 'type' => 'boolean', 'options' => []],
        'OIDC_ISSUER' => ['path' => 'oidc.issuer', 'type' => 'url', 'options' => []],
        'OIDC_CLIENT_ID' => ['path' => 'oidc.client_id', 'type' => 'text', 'options' => []],
        'OIDC_CLIENT_SECRET' => ['path' => 'oidc.client_secret', 'type' => 'secret', 'options' => []],
        'OIDC_REDIRECT_URI' => ['path' => 'oidc.redirect', 'type' => 'url', 'options' => []],
    ],
    'webhook' => [
        'ALERT_WEBHOOK_URL' => ['path' => 'alert_notifications.webhook.url', 'type' => 'url', 'options' => []],
        'ALERT_WEBHOOK_ALLOWED_INTERNAL' => ['path' => 'alert_notifications.webhook.allowed_internal', 'type' => 'text', 'options' => []],
        'ALERT_WEBHOOK_TOKEN' => ['path' => 'alert_notifications.webhook.token', 'type' => 'secret', 'options' => []],
        'ALERT_WEBHOOK_FORMAT' => ['path' => 'alert_notifications.webhook.format', 'type' => 'select', 'options' => ['generic', 'ntfy', 'apprise']],
        'ALERT_WEBHOOK_TOPIC' => ['path' => 'alert_notifications.webhook.topic', 'type' => 'text', 'options' => []],
        'ALERT_WEBHOOK_APPRISE_TAG' => ['path' => 'alert_notifications.webhook.tag', 'type' => 'text', 'options' => []],
        'ALERT_WEBHOOK_APPRISE_URLS' => ['path' => 'alert_notifications.webhook.apprise_urls', 'type' => 'secret', 'options' => []],
    ],
    'push' => [
        'WEB_PUSH_PUBLIC_KEY' => ['path' => 'alert_notifications.push.public_key', 'type' => 'text', 'options' => []],
        'WEB_PUSH_PRIVATE_KEY' => ['path' => 'alert_notifications.push.private_key', 'type' => 'secret', 'options' => []],
        'WEB_PUSH_SUBJECT' => ['path' => 'alert_notifications.push.subject', 'type' => 'text', 'options' => []],
    ],
];
foreach ($groups as &$fields) {
    foreach ($fields as $name => &$field) {
        $value = env($name);
        $field['locked'] = $value !== null && $value !== '';
    }
}
unset($fields, $field);

return $groups;
