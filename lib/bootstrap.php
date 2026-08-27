<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config.php';
date_default_timezone_set('Europe/Paris');

define('STT_ROOT', dirname(__DIR__));
define('STT_DATA', STT_ROOT . '/data');

if (!is_dir(STT_DATA)) {
    mkdir(STT_DATA, 0755, true);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/discover.php';
require_once __DIR__ . '/probe.php';
require_once __DIR__ . '/aggregate.php';

function stt_config(?string $key = null, mixed $default = null): mixed
{
    global $config;
    if ($key === null) {
        return $config;
    }
    return $config[$key] ?? $default;
}

function stt_json(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function stt_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function stt_canonical_host(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('#^https?://#', '', $host) ?? $host;
    $host = explode('/', $host)[0];
    $host = explode(':', $host)[0];
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }
    return $host;
}

function stt_site_url(string $host): string
{
    return 'https://' . $host . '/';
}

function stt_watch_map(): array
{
    $map = [];
    foreach (stt_config('watch', []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $host = stt_canonical_host((string) ($row['host'] ?? ''));
        if ($host === '') {
            continue;
        }
        $map[$host] = [
            'label' => (string) ($row['label'] ?? $host),
            'group' => (string) ($row['group'] ?? stt_site_group($host)),
        ];
    }
    return $map;
}

function stt_site_label(string $host): string
{
    foreach (stt_config('watch', []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (stt_canonical_host((string) ($row['host'] ?? '')) === $host && !empty($row['label'])) {
            return (string) $row['label'];
        }
    }
    $short = preg_replace('/\.ttrd\.fr$/', '', $host) ?? $host;
    return $short === $host ? $host : ucfirst(str_replace('-', ' ', $short));
}

function stt_site_group(string $host): string
{
    $domain = stt_config('domain', 'ttrd.fr');
    if ($host === $domain || str_ends_with($host, '.' . $domain)) {
        return 'ttrd';
    }
    return 'externe';
}

function stt_class_label(string $class): string
{
    return match ($class) {
        'operational' => 'Opérationnel',
        'degraded' => 'Dégradé',
        'outage' => 'Hors ligne',
        default => 'Inconnu',
    };
}

function stt_class_short(string $class): string
{
    return match ($class) {
        'operational' => 'En ligne',
        'degraded' => 'Dégradé',
        'outage' => 'Crash',
        default => 'Attente',
    };
}

function stt_pct(float $value, int $digits = 2): string
{
    return number_format($value, $digits, ',', ' ') . ' %';
}

function stt_ms(int|float|null $ms): string
{
    if ($ms === null) {
        return '—';
    }
    $ms = (int) round((float) $ms);
    if ($ms >= 1000) {
        return number_format($ms / 1000, 2, ',', ' ') . ' s';
    }
    return $ms . ' ms';
}

function stt_flux(int $value): string
{
    if ($value >= 1_000_000) {
        return number_format($value / 1_000_000, 1, ',', ' ') . ' M';
    }
    if ($value >= 1_000) {
        return number_format($value / 1_000, 1, ',', ' ') . ' k';
    }
    return (string) $value;
}

function stt_bytes(int $bytes): string
{
    if ($bytes >= 1_048_576) {
        return number_format($bytes / 1_048_576, 1, ',', ' ') . ' Mo';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', ' ') . ' Ko';
    }
    return $bytes . ' o';
}

function stt_ago(?int $ts): string
{
    if (!$ts) {
        return 'jamais';
    }
    $delta = time() - $ts;
    if ($delta < 10) {
        return 'à l’instant';
    }
    if ($delta < 60) {
        return 'il y a ' . $delta . ' s';
    }
    if ($delta < 3600) {
        return 'il y a ' . (int) floor($delta / 60) . ' min';
    }
    if ($delta < 86400) {
        return 'il y a ' . (int) floor($delta / 3600) . ' h';
    }
    return date('d/m H:i', $ts);
}
