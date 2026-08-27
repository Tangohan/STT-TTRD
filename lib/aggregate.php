<?php

declare(strict_types=1);

function stt_day_range(int $days): array
{
    $out = [];
    $end = new DateTimeImmutable('today');
    for ($i = $days - 1; $i >= 0; $i--) {
        $out[] = $end->modify('-' . $i . ' days')->format('Y-m-d');
    }
    return $out;
}

function stt_uptime_bar(array $dailyMap, int $days = 90): array
{
    $bar = [];
    $okDays = 0;
    $known = 0;
    foreach (stt_day_range($days) as $day) {
        $row = $dailyMap[$day] ?? null;
        $checks = (int) ($row['checks'] ?? 0);
        if (!$row || $checks === 0) {
            $bar[] = 'empty';
            continue;
        }
        $known++;
        $ok = (int) ($row['ok'] ?? 0);
        $crashes = (int) ($row['crashes'] ?? 0);
        $ratio = $ok / $checks;
        $okDays += $ratio;
        if ($crashes === $checks) {
            $bar[] = 'outage';
        } elseif ($crashes > 0 || $ratio < 1) {
            $bar[] = 'degraded';
        } else {
            $bar[] = 'operational';
        }
    }
    $pct = $known > 0 ? round(100 * $okDays / $known, 2) : 0.0;
    return ['days' => $bar, 'pct' => $pct, 'known' => $known];
}

function stt_site_metrics(int $siteId, array $latest, array $incident): array
{
    $since = time() - 86400;
    $rows = stt_checks_since($siteId, $since);
    $n = count($rows);
    $ok = 0;
    $crashes = 0;
    $errors = 0;
    $latSum = 0;
    $latN = 0;
    $latMax = 0;
    $bytes = 0;
    foreach ($rows as $row) {
        $ok += (int) ($row['ok'] ?? 0);
        if (($row['status_class'] ?? '') === 'outage') {
            $crashes++;
        } elseif (($row['status_class'] ?? '') === 'degraded') {
            $errors++;
        }
        if ($row['latency_ms'] !== null) {
            $latSum += (int) $row['latency_ms'];
            $latN++;
            $latMax = max($latMax, (int) $row['latency_ms']);
        }
        $bytes += (int) ($row['bytes'] ?? 0);
    }

    $beacons = stt_beacon_count($siteId, $since);
    $flux = $beacons > 0 ? $beacons : $n;

    $daily = stt_daily_map($siteId, (int) stt_config('history_days', 90));
    $uptime = stt_uptime_bar($daily, (int) stt_config('history_days', 90));

    $class = $latest['status_class'] ?? 'empty';
    $lat = $latest['latency_ms'] ?? null;
    $latAvg = $latN > 0 ? (int) round($latSum / $latN) : $lat;

    $integrity = $uptime['pct'];
    $errorScore = $n > 0 ? max(0, 100 - (100 * ($errors + $crashes) / $n)) : ($class === 'operational' ? 100 : 0);
    $latScore = $lat === null ? ($class === 'empty' ? 0 : 100) : max(0, min(100, round(100 * (1 - ((int) $lat / 4000)))));
    $fluxScore = $class === 'outage' ? 0 : ($flux > 0 ? 100 : 40);

    return [
        'class' => $class,
        'http_code' => $latest['http_code'] ?? null,
        'latency_ms' => $lat,
        'latency_avg_24h' => $latAvg,
        'latency_max_24h' => $latMax ?: $lat,
        'error' => $latest['error'] ?? null,
        'ip' => $latest['ip'] ?? null,
        'tls_days' => $latest['tls_days'] ?? null,
        'checked_at' => isset($latest['ts']) ? (int) $latest['ts'] : null,
        'crashes_24h' => $crashes,
        'errors_24h' => $errors,
        'probes_24h' => $n,
        'beacons_24h' => $beacons,
        'flux_24h' => $flux,
        'bytes_24h' => $bytes,
        'incident' => $incident ?: null,
        'uptime' => $uptime,
        'diag' => [
            'crash' => $integrity,
            'erreur' => round($errorScore, 1),
            'latence' => $latScore,
            'flux' => $fluxScore,
        ],
        'spark' => stt_sparkline($siteId),
    ];
}

function stt_sparkline(int $siteId, int $points = 36): array
{
    $since = time() - 6 * 3600;
    $rows = stt_checks_since($siteId, $since);
    $lat = [];
    $flux = [];
    foreach ($rows as $row) {
        $lat[] = $row['latency_ms'] !== null ? (int) $row['latency_ms'] : null;
        $flux[] = ($row['status_class'] ?? '') === 'outage' ? 0 : 1;
    }
    return [
        'latency' => stt_downsample($lat, $points),
        'flux' => stt_downsample($flux, $points),
    ];
}

function stt_downsample(array $values, int $points): array
{
    $n = count($values);
    if ($n === 0) {
        return array_fill(0, $points, null);
    }
    if ($n <= $points) {
        return array_pad($values, $points, null);
    }
    $out = [];
    $size = $n / $points;
    for ($i = 0; $i < $points; $i++) {
        $slice = array_slice($values, (int) floor($i * $size), max(1, (int) ceil($size)));
        $nums = array_values(array_filter($slice, static fn($v) => $v !== null));
        $out[] = $nums === [] ? null : (int) round(array_sum($nums) / count($nums));
    }
    return $out;
}

function stt_fleet_snapshot(): array
{
    stt_discover();
    $sites = stt_sites();
    $latest = stt_latest_checks();
    $incidents = stt_open_incidents();
    $cards = [];
    $counts = ['operational' => 0, 'degraded' => 0, 'outage' => 0, 'empty' => 0];
    $latencies = [];
    $crashes = 0;
    $errors = 0;
    $flux = 0;
    $diagAcc = ['crash' => [], 'erreur' => [], 'latence' => [], 'flux' => []];
    $fleetDaily = [];

    foreach ($sites as $site) {
        $id = (int) $site['id'];
        $metrics = stt_site_metrics($id, $latest[$id] ?? [], $incidents[$id] ?? []);
        $class = $metrics['class'];
        $counts[$class] = ($counts[$class] ?? 0) + 1;
        if ($metrics['latency_ms'] !== null) {
            $latencies[] = (int) $metrics['latency_ms'];
        }
        $crashes += $metrics['crashes_24h'];
        $errors += $metrics['errors_24h'];
        $flux += $metrics['flux_24h'];
        foreach ($diagAcc as $key => $_) {
            $diagAcc[$key][] = $metrics['diag'][$key];
        }
        foreach ($metrics['uptime']['days'] as $i => $dayClass) {
            $fleetDaily[$i][] = $dayClass;
        }
        $cards[] = $site + $metrics;
    }

    $fleetBar = [];
    $ok = 0;
    $known = 0;
    $nDays = (int) stt_config('history_days', 90);
    for ($i = 0; $i < $nDays; $i++) {
        $day = $fleetDaily[$i] ?? [];
        if ($day === [] || !in_array('operational', $day, true) && !in_array('degraded', $day, true) && !in_array('outage', $day, true)) {
            $fleetBar[] = 'empty';
            continue;
        }
        $known++;
        if (in_array('outage', $day, true)) {
            $class = count(array_filter($day, static fn($c) => $c === 'outage')) >= max(1, (int) ceil(count($day) * 0.35)) ? 'outage' : 'degraded';
            $fleetBar[] = $class;
            if ($class === 'degraded') {
                $ok += 0.5;
            }
        } elseif (in_array('degraded', $day, true)) {
            $fleetBar[] = 'degraded';
            $ok += 0.5;
        } else {
            $fleetBar[] = 'operational';
            $ok++;
        }
    }

    $avgLat = $latencies === [] ? null : (int) round(array_sum($latencies) / count($latencies));
    $up = $counts['operational'];
    $total = max(1, count($sites));
    $overall = $counts['outage'] > 0 ? 'outage' : ($counts['degraded'] > 0 ? 'degraded' : ($counts['empty'] === count($sites) ? 'empty' : 'operational'));

    $avg = static fn(array $vals): float => $vals === [] ? 0.0 : round(array_sum($vals) / count($vals), 1);

    $openIncidents = [];
    foreach ($cards as $card) {
        if (!empty($card['incident'])) {
            $openIncidents[] = [
                'host' => $card['host'],
                'label' => $card['label'],
                'kind' => $card['incident']['kind'],
                'detail' => $card['incident']['detail'],
                'started_at' => (int) $card['incident']['started_at'],
            ];
        }
    }

    return [
        'generated_at' => time(),
        'last_probe_at' => (int) (stt_meta_get('last_probe_at', '0') ?? '0'),
        'overall' => $overall,
        'counts' => $counts,
        'total' => count($sites),
        'up' => $up,
        'avg_latency_ms' => $avgLat,
        'crashes_24h' => $crashes,
        'errors_24h' => $errors,
        'flux_24h' => $flux,
        'uptime_pct' => $known > 0 ? round(100 * $ok / $known, 2) : 0.0,
        'diag' => [
            'crash' => $avg($diagAcc['crash']),
            'erreur' => $avg($diagAcc['erreur']),
            'latence' => $avg($diagAcc['latence']),
            'flux' => $avg($diagAcc['flux']),
        ],
        'fleet_bar' => $fleetBar,
        'sites' => $cards,
        'incidents' => $openIncidents,
        'hourly' => stt_hourly_fleet(),
    ];
}

function stt_hourly_fleet(): array
{
    return stt_hourly_since(time() - 24 * 3600);
}

function stt_site_detail(string $host): ?array
{
    $site = stt_site_by_host($host);
    if (!$site) {
        return null;
    }
    $id = (int) $site['id'];
    $latest = stt_latest_checks();
    $incidents = stt_open_incidents();
    $metrics = stt_site_metrics($id, $latest[$id] ?? [], $incidents[$id] ?? []);

    return $site + $metrics + [
        'history' => stt_recent_checks($id, 40),
        'incident_log' => stt_incidents_for($id, 12),
    ];
}
