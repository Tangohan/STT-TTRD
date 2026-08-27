<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$action = trim((string) str_replace('/api', '', $path), '/');
if ($action === '') {
    $action = (string) ($_GET['action'] ?? 'status');
}

if ($action === 'status') {
    stt_json(stt_fleet_snapshot());
}

if ($action === 'probe') {
    $result = stt_maybe_probe() ?? ['ok' => true, 'reason' => 'fresh', 'last_probe_at' => (int) (stt_meta_get('last_probe_at', '0') ?? '0')];
    stt_json($result);
}

if ($action === 'discover') {
    $hosts = stt_discover(true);
    stt_json(['ok' => true, 'hosts' => $hosts, 'count' => count($hosts)]);
}

if ($action === 'beacon') {
    $host = stt_canonical_host((string) ($_GET['host'] ?? $_POST['host'] ?? ''));
    if ($host === '') {
        stt_json(['ok' => false, 'error' => 'host manquant'], 400);
    }
    $pathInfo = (string) ($_GET['path'] ?? $_POST['path'] ?? '/');
    $code = isset($_GET['code']) ? (int) $_GET['code'] : (isset($_POST['code']) ? (int) $_POST['code'] : null);
    $ms = isset($_GET['ms']) ? (int) $_GET['ms'] : (isset($_POST['ms']) ? (int) $_POST['ms'] : null);
    $result = stt_record_beacon($host, $pathInfo, $code, $ms);
    if (isset($_GET['gif'])) {
        header('Content-Type: image/gif');
        header('Cache-Control: no-store');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }
    stt_json($result);
}

if ($action === 'site') {
    $host = stt_canonical_host((string) ($_GET['host'] ?? ''));
    $detail = stt_site_detail($host);
    if (!$detail) {
        stt_json(['ok' => false, 'error' => 'inconnu'], 404);
    }
    stt_json($detail);
}

stt_json(['ok' => false, 'error' => 'route inconnue'], 404);
