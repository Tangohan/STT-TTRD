<?php

declare(strict_types=1);

function stt_store_boot(): void
{
    foreach (['checks', 'daily'] as $dir) {
        $path = STT_DATA . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
    foreach (['meta.json' => '{}', 'sites.json' => '[]', 'seq.json' => '{"site":0,"incident":0}', 'incidents.json' => '[]'] as $file => $init) {
        $path = STT_DATA . '/' . $file;
        if (!is_file($path)) {
            file_put_contents($path, $init, LOCK_EX);
        }
    }
}

function stt_read_json(string $file, mixed $default): mixed
{
    stt_store_boot();
    $path = STT_DATA . '/' . $file;
    if (!is_file($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    return $data === null ? $default : $data;
}

function stt_write_json(string $file, mixed $data): void
{
    stt_store_boot();
    $path = STT_DATA . '/' . $file;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $path . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, $payload, LOCK_EX);
    rename($tmp, $path);
}

function stt_next_id(string $key): int
{
    $seq = stt_read_json('seq.json', ['site' => 0, 'incident' => 0]);
    $seq[$key] = (int) ($seq[$key] ?? 0) + 1;
    stt_write_json('seq.json', $seq);
    return (int) $seq[$key];
}

function stt_meta_get(string $key, ?string $default = null): ?string
{
    $meta = stt_read_json('meta.json', []);
    return isset($meta[$key]) ? (string) $meta[$key] : $default;
}

function stt_meta_set(string $key, string $value): void
{
    $meta = stt_read_json('meta.json', []);
    $meta[$key] = $value;
    stt_write_json('meta.json', $meta);
}

function stt_upsert_site(string $host, string $label, string $source, string $group): int
{
    $host = stt_canonical_host($host);
    $now = time();
    $sites = stt_read_json('sites.json', []);
    foreach ($sites as &$site) {
        if ($site['host'] === $host) {
            $site['label'] = $label;
            $site['url'] = stt_site_url($host);
            $site['site_group'] = $group;
            $site['updated_at'] = $now;
            stt_write_json('sites.json', $sites);
            return (int) $site['id'];
        }
    }
    unset($site);
    $id = stt_next_id('site');
    $sites[] = [
        'id' => $id,
        'host' => $host,
        'label' => $label,
        'url' => stt_site_url($host),
        'source' => $source,
        'site_group' => $group,
        'enabled' => 1,
        'discovered_at' => $now,
        'updated_at' => $now,
    ];
    stt_write_json('sites.json', $sites);
    return $id;
}

function stt_sites(bool $enabledOnly = true): array
{
    $sites = stt_read_json('sites.json', []);
    if ($enabledOnly) {
        $sites = array_values(array_filter($sites, static fn($s) => !empty($s['enabled'])));
    }
    usort($sites, static function ($a, $b) {
        $g = strcmp((string) $a['site_group'], (string) $b['site_group']);
        if ($g !== 0) {
            return $g;
        }
        return strcasecmp((string) $a['label'], (string) $b['label']);
    });
    return $sites;
}

function stt_site_by_host(string $host): ?array
{
    $host = stt_canonical_host($host);
    foreach (stt_read_json('sites.json', []) as $site) {
        if ($site['host'] === $host) {
            return $site;
        }
    }
    return null;
}

function stt_site_by_id(int $id): ?array
{
    foreach (stt_read_json('sites.json', []) as $site) {
        if ((int) $site['id'] === $id) {
            return $site;
        }
    }
    return null;
}

function stt_checks_file(int $siteId): string
{
    return STT_DATA . '/checks/' . $siteId . '.jsonl';
}

function stt_append_jsonl(string $path, array $row): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function stt_read_jsonl(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

function stt_insert_check(int $siteId, int $ts, array $result): array
{
    $row = [
        'id' => $ts . '-' . $siteId,
        'site_id' => $siteId,
        'ts' => $ts,
        'ok' => !empty($result['ok']) ? 1 : 0,
        'status_class' => $result['status_class'],
        'http_code' => $result['http_code'],
        'latency_ms' => $result['latency_ms'],
        'bytes' => (int) ($result['bytes'] ?? 0),
        'error' => $result['error'] ?? null,
        'ip' => $result['ip'] ?? null,
        'tls_days' => $result['tls_days'] ?? null,
    ];
    stt_append_jsonl(stt_checks_file($siteId), $row);
    $latest = stt_read_json('latest.json', []);
    $latest[(string) $siteId] = $row;
    stt_write_json('latest.json', $latest);
    return $row;
}

function stt_latest_checks(): array
{
    $latest = stt_read_json('latest.json', []);
    $out = [];
    foreach ($latest as $id => $row) {
        $out[(int) $id] = $row;
    }
    return $out;
}

function stt_checks_since(int $siteId, int $since): array
{
    $rows = stt_read_jsonl(stt_checks_file($siteId));
    return array_values(array_filter($rows, static fn($r) => (int) $r['ts'] >= $since));
}

function stt_recent_checks(int $siteId, int $limit = 40): array
{
    $rows = stt_read_jsonl(stt_checks_file($siteId));
    return array_slice(array_reverse($rows), 0, $limit);
}

function stt_prune_checks(int $siteId): void
{
    $keep = time() - 8 * 86400;
    $path = stt_checks_file($siteId);
    $rows = array_values(array_filter(stt_read_jsonl($path), static fn($r) => (int) $r['ts'] >= $keep));
    if ($rows === []) {
        if (is_file($path)) {
            unlink($path);
        }
        return;
    }
    $fh = fopen($path, 'w');
    if ($fh === false) {
        return;
    }
    foreach ($rows as $row) {
        fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
    fclose($fh);
}

function stt_daily_file(int $siteId): string
{
    return 'daily/' . $siteId . '.json';
}

function stt_bump_daily(int $siteId, int $ts, array $result, int $fluxDelta = 0): void
{
    $day = date('Y-m-d', $ts);
    $file = stt_daily_file($siteId);
    $map = stt_read_json($file, []);
    $row = $map[$day] ?? [
        'site_id' => $siteId,
        'day' => $day,
        'checks' => 0,
        'ok' => 0,
        'errors' => 0,
        'crashes' => 0,
        'latency_sum' => 0,
        'latency_max' => 0,
        'bytes' => 0,
        'flux' => 0,
        'worst' => 'empty',
    ];
    if ($result !== []) {
        $worst = $result['status_class'] ?? 'empty';
        $row['checks']++;
        $row['ok'] += !empty($result['ok']) ? 1 : 0;
        $row['errors'] += in_array($worst, ['degraded', 'outage'], true) ? 1 : 0;
        $row['crashes'] += $worst === 'outage' ? 1 : 0;
        $lat = (int) ($result['latency_ms'] ?? 0);
        $row['latency_sum'] += $lat;
        $row['latency_max'] = max((int) $row['latency_max'], $lat);
        $row['bytes'] += (int) ($result['bytes'] ?? 0);
        $row['flux']++;
        $row['worst'] = stt_worst($row['worst'], $worst);
    }
    if ($fluxDelta > 0) {
        $row['flux'] += $fluxDelta;
    }
    $map[$day] = $row;
    $from = (new DateTimeImmutable('today'))->modify('-120 days')->format('Y-m-d');
    $map = array_filter($map, static fn($_, $k) => $k >= $from, ARRAY_FILTER_USE_BOTH);
    stt_write_json($file, $map);
}

function stt_worst(string $a, string $b): string
{
    $rank = ['empty' => 0, 'operational' => 1, 'degraded' => 2, 'outage' => 3];
    return (($rank[$b] ?? 0) > ($rank[$a] ?? 0)) ? $b : $a;
}

function stt_daily_map(int $siteId, int $days = 90): array
{
    return stt_read_json(stt_daily_file($siteId), []);
}

function stt_incidents(): array
{
    return stt_read_json('incidents.json', []);
}

function stt_save_incidents(array $rows): void
{
    stt_write_json('incidents.json', $rows);
}

function stt_open_incidents(): array
{
    $out = [];
    foreach (stt_incidents() as $row) {
        if ($row['ended_at'] === null) {
            $out[(int) $row['site_id']] = $row;
        }
    }
    return $out;
}

function stt_touch_incident(int $siteId, int $ts, array $result): void
{
    $kind = ($result['status_class'] ?? '') === 'outage' ? 'crash' : (($result['status_class'] ?? '') === 'degraded' ? 'erreur' : null);
    $rows = stt_incidents();
    $current = null;
    $currentIdx = null;
    foreach ($rows as $i => $row) {
        if ((int) $row['site_id'] === $siteId && $row['ended_at'] === null) {
            $current = $row;
            $currentIdx = $i;
        }
    }
    if ($kind === null) {
        if ($currentIdx !== null) {
            $rows[$currentIdx]['ended_at'] = $ts;
            stt_save_incidents($rows);
        }
        return;
    }
    if ($current && $current['kind'] === $kind) {
        return;
    }
    if ($currentIdx !== null) {
        $rows[$currentIdx]['ended_at'] = $ts;
    }
    $rows[] = [
        'id' => stt_next_id('incident'),
        'site_id' => $siteId,
        'kind' => $kind,
        'detail' => $result['error'] ?? ('HTTP ' . ($result['http_code'] ?? '?')),
        'started_at' => $ts,
        'ended_at' => null,
    ];
    stt_save_incidents($rows);
}

function stt_incidents_for(int $siteId, int $limit = 12): array
{
    $rows = array_values(array_filter(stt_incidents(), static fn($r) => (int) $r['site_id'] === $siteId));
    usort($rows, static fn($a, $b) => (int) $b['started_at'] <=> (int) $a['started_at']);
    return array_slice($rows, 0, $limit);
}

function stt_insert_beacon(int $siteId, int $ts, ?string $path, ?int $code, ?int $latency): void
{
    stt_append_jsonl(STT_DATA . '/beacons.jsonl', [
        'site_id' => $siteId,
        'ts' => $ts,
        'path' => $path,
        'http_code' => $code,
        'latency_ms' => $latency,
    ]);
}

function stt_beacon_count(int $siteId, int $since): int
{
    $n = 0;
    foreach (stt_read_jsonl(STT_DATA . '/beacons.jsonl') as $row) {
        if ((int) $row['site_id'] === $siteId && (int) $row['ts'] >= $since) {
            $n++;
        }
    }
    return $n;
}

function stt_hourly_since(int $since): array
{
    $buckets = [];
    foreach (stt_sites() as $site) {
        foreach (stt_checks_since((int) $site['id'], $since) as $row) {
            $bucket = intdiv((int) $row['ts'], 3600) * 3600;
            $buckets[$bucket] ??= ['ts' => $bucket, 'lat_sum' => 0, 'lat_n' => 0, 'crashes' => 0, 'errors' => 0, 'n' => 0, 'beacons' => 0];
            $buckets[$bucket]['n']++;
            if ($row['latency_ms'] !== null) {
                $buckets[$bucket]['lat_sum'] += (int) $row['latency_ms'];
                $buckets[$bucket]['lat_n']++;
            }
            if (($row['status_class'] ?? '') === 'outage') {
                $buckets[$bucket]['crashes']++;
            } elseif (($row['status_class'] ?? '') === 'degraded') {
                $buckets[$bucket]['errors']++;
            }
        }
    }
    foreach (stt_read_jsonl(STT_DATA . '/beacons.jsonl') as $row) {
        if ((int) $row['ts'] < $since) {
            continue;
        }
        $bucket = intdiv((int) $row['ts'], 3600) * 3600;
        $buckets[$bucket] ??= ['ts' => $bucket, 'lat_sum' => 0, 'lat_n' => 0, 'crashes' => 0, 'errors' => 0, 'n' => 0, 'beacons' => 0];
        $buckets[$bucket]['beacons']++;
    }
    ksort($buckets);
    $out = [];
    foreach ($buckets as $row) {
        $out[] = [
            'ts' => $row['ts'],
            'latency' => $row['lat_n'] > 0 ? (int) round($row['lat_sum'] / $row['lat_n']) : null,
            'crashes' => $row['crashes'],
            'errors' => $row['errors'],
            'flux' => $row['beacons'] > 0 ? $row['beacons'] : $row['n'],
        ];
    }
    return $out;
}
