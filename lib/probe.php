<?php

declare(strict_types=1);

function stt_probe_all(?array $sites = null, bool $rediscover = false): array
{
    $lock = fopen(STT_DATA . '/probe.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return ['ok' => false, 'reason' => 'busy'];
    }

    try {
        stt_discover($rediscover);
        $sites = $sites ?? stt_sites();
        if ($sites === []) {
            return ['ok' => true, 'probed' => 0];
        }

        $now = time();
        $results = [];
        foreach ($sites as $site) {
            $id = (int) $site['id'];
            $ch = curl_init($site['url']);
            $result = [
                'ok' => false,
                'status_class' => 'outage',
                'http_code' => null,
                'latency_ms' => null,
                'bytes' => 0,
                'error' => 'init impossible',
                'ip' => null,
                'tls_days' => null,
            ];
            if ($ch !== false) {
                curl_setopt_array($ch, stt_curl_opts());
                $body = curl_exec($ch);
                $result = stt_probe_result($ch, $body);
                curl_close($ch);
            }
            stt_store_check($id, $now, $result);
            $results[$site['host']] = $result;
        }

        stt_meta_set('last_probe_at', (string) $now);
        return ['ok' => true, 'probed' => count($results), 'ts' => $now, 'results' => $results];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function stt_curl_opts(): array
{
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => (int) stt_config('timeout', 12),
        CURLOPT_CONNECTTIMEOUT => (int) stt_config('connect_timeout', 6),
        CURLOPT_USERAGENT => (string) stt_config('user_agent'),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CAINFO => STT_ROOT . '/lib/cacert.pem',
        CURLOPT_ENCODING => '',
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: fr-FR,fr;q=0.9',
        ],
    ];
}

function stt_probe_result(\CurlHandle $ch, mixed $body = null): array
{
    if ($body === null) {
        $body = curl_exec($ch);
    }
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $bytes = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    $ip = curl_getinfo($ch, CURLINFO_PRIMARY_IP) ?: null;
    $tlsDays = stt_tls_days($ch);

    $latency = is_numeric($time) ? (int) round(((float) $time) * 1000) : null;
    $okBody = is_string($body);
    $error = $err !== '' ? $err : null;

    $class = 'outage';
    $ok = false;
    $degradedMs = (int) stt_config('degraded_ms', 2000);

    if ($error && !$okBody && $code === 0) {
        $class = 'outage';
    } elseif ($code >= 500 || $code === 0) {
        $class = 'outage';
        $error = $error ?: ('HTTP ' . $code);
    } elseif ($code === 401 || $code === 403) {
        $ok = true;
        $class = ($latency !== null && $latency >= $degradedMs) ? 'degraded' : 'operational';
        $error = 'HTTP ' . $code . ' · accès restreint';
    } elseif ($code >= 400) {
        $class = 'degraded';
        $error = 'HTTP ' . $code;
    } elseif ($code >= 200) {
        $ok = true;
        $class = ($latency !== null && $latency >= $degradedMs) ? 'degraded' : 'operational';
        if ($tlsDays !== null && $tlsDays <= 7) {
            $class = 'degraded';
            $error = 'TLS expire dans ' . $tlsDays . ' j';
        }
    }

    return [
        'ok' => $ok,
        'status_class' => $class,
        'http_code' => $code ?: null,
        'latency_ms' => $latency,
        'bytes' => $okBody ? max($bytes, strlen($body)) : $bytes,
        'error' => $error,
        'ip' => is_string($ip) && $ip !== '' ? $ip : null,
        'tls_days' => $tlsDays,
    ];
}

function stt_tls_days(\CurlHandle $ch): ?int
{
    $info = curl_getinfo($ch, CURLINFO_CERTINFO);
    if (!is_array($info) || $info === []) {
        return null;
    }
    $expire = $info[0]['Expire date'] ?? $info[0]['expire'] ?? null;
    if (!is_string($expire) || $expire === '') {
        return null;
    }
    $ts = strtotime($expire);
    if ($ts === false) {
        return null;
    }
    return (int) floor(($ts - time()) / 86400);
}

function stt_store_check(int $siteId, int $ts, array $result): void
{
    stt_insert_check($siteId, $ts, $result);
    stt_bump_daily($siteId, $ts, $result);
    stt_touch_incident($siteId, $ts, $result);
    stt_prune_checks($siteId);
}

function stt_maybe_probe(): ?array
{
    $last = (int) (stt_meta_get('last_probe_at', '0') ?? '0');
    $interval = (int) stt_config('probe_interval', 60);
    if ($last > 0 && (time() - $last) < $interval) {
        return null;
    }
    return stt_probe_all();
}

function stt_record_beacon(string $host, ?string $path, ?int $code, ?int $latency): array
{
    stt_discover();
    $site = stt_site_by_host($host);
    if (!$site) {
        $id = stt_upsert_site($host, stt_site_label($host), 'beacon', stt_site_group($host));
        $site = stt_site_by_host($host) ?? ['id' => $id, 'host' => $host];
    }
    $ts = time();
    stt_insert_beacon((int) $site['id'], $ts, $path, $code, $latency);
    stt_bump_daily((int) $site['id'], $ts, [], 1);
    return ['ok' => true, 'host' => $site['host']];
}
