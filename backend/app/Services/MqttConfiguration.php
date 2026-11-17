<?php

namespace App\Services;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class MqttConfiguration
{
    public function client(): MqttClient
    {
        return new MqttClient(config('mqtt.host'), config('mqtt.port'), 'canabicultarr-'.bin2hex(random_bytes(6)), MqttClient::MQTT_3_1_1);
    }

    public function connectionSettings(): ConnectionSettings
    {
        return (new ConnectionSettings)->setConnectTimeout(3)->setSocketTimeout(5)->setKeepAliveInterval(10)->setUsername(config('mqtt.username'))->setPassword(config('mqtt.password'))->setUseTls((bool) config('mqtt.tls'))->setTlsVerifyPeer(true)->setTlsVerifyPeerName(true)->setTlsSelfSignedAllowed(false)->setTlsCertificateAuthorityFile(config('mqtt.ca_file'));
    }

    public function ready(): bool
    {
        $host = config('mqtt.host');

        return config('mqtt.enabled') && is_string($host) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-:]*$/D', $host) && strlen($host) <= 253 && config('mqtt.port') >= 1 && config('mqtt.port') <= 65535;
    }

    public function instanceKey(): string
    {
        return hash('sha256', strtolower((string) config('mqtt.host')).':'.config('mqtt.port').':'.(config('mqtt.tls') ? 'tls' : 'tcp'));
    }
}
