<?php

declare(strict_types=1);

function stt_discover(bool $force = false): array
{
    $last = (int) (stt_meta_get('discover_at', '0') ?? '0');
    $ttl = (int) stt_config('discover_ttl', 21600);
    $hosts = [];

    if (!$force && $last > 0 && (time() - $last) < $ttl) {
        foreach (stt_sites(false) as $site) {
            $hosts[] = $site['host'];
        }
        return $hosts;
    }

    $domain = (string) stt_config('domain', 'ttrd.fr');
    $exclude = [];
    foreach (stt_config('exclude_hosts', []) as $blocked) {
        $exclude[] = strtolower(trim((string) $blocked));
    }

    $hosts[] = $domain;
    foreach (array_keys(stt_config('labels', [])) as $known) {
        $hosts[] = $known;
    }
    foreach (stt_ct_hosts($domain) as $host) {
        $hosts[] = $host;
    }
    foreach (stt_config('extra_sites', []) as $extra) {
        $hosts[] = $extra['host'] ?? '';
    }

    $unique = [];
    foreach ($hosts as $host) {
        $host = stt_canonical_host((string) $host);
        if ($host === '' || in_array($host, $exclude, true)) {
            continue;
        }
        $unique[$host] = true;
    }

    foreach (array_keys($unique) as $host) {
        $extra = stt_extra_meta($host);
        stt_upsert_site(
            $host,
            $extra['label'] ?? stt_site_label($host),
            $extra ? 'config' : 'ct',
            $extra['group'] ?? stt_site_group($host)
        );
    }

    stt_meta_set('discover_at', (string) time());
    return array_keys($unique);
}

function stt_extra_meta(string $host): ?array
{
    foreach (stt_config('extra_sites', []) as $extra) {
        if (stt_canonical_host((string) ($extra['host'] ?? '')) === $host) {
            return $extra;
        }
    }
    return null;
}

function stt_ct_hosts(string $domain): array
{
    $hosts = [];
    $url = 'https://api.certspotter.com/v1/issuances?domain=' . rawurlencode($domain)
        . '&include_subdomains=true&expand=dns_names';
    $after = null;

    for ($page = 0; $page < 8; $page++) {
        $pageUrl = $url . ($after ? '&after=' . rawurlencode($after) : '');
        $json = stt_http_get($pageUrl, 20);
        if ($json === null) {
            break;
        }
        $rows = json_decode($json, true);
        if (!is_array($rows) || $rows === []) {
            break;
        }
        foreach ($rows as $row) {
            $after = (string) ($row['id'] ?? $after);
            foreach ($row['dns_names'] ?? [] as $name) {
                $name = stt_canonical_host((string) $name);
                if ($name === $domain || str_ends_with($name, '.' . $domain)) {
                    $hosts[$name] = true;
                }
            }
        }
        if (count($rows) < 20) {
            break;
        }
    }

    return array_keys($hosts);
}

function stt_http_get(string $url, int $timeout = 12): ?string
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => (string) stt_config('user_agent'),
        CURLOPT_CAINFO => STT_ROOT . '/lib/cacert.pem',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $code >= 400) {
        return null;
    }
    return $body;
}
