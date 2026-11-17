<?php

namespace App\Services;

class WebhookDnsResolver
{
    public function addresses(string $host): array
    {
        // System IPv4 lookup honors /etc/hosts and container DNS. Include DNS IPv6
        // answers too, so a mixed public/private result cannot pass unchecked.
        $addresses = @gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
