<?php

/**
 * Sondage de la flotte TTRD.
 * Cron recommandé, toutes les minutes :
 *   * * * * * php /chemin/stt.ttrd.fr/cron.php >/dev/null 2>&1
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if (PHP_SAPI !== 'cli' && ($_GET['key'] ?? '') !== stt_meta_get('cron_key', '')) {
    $cliOnly = PHP_SAPI !== 'cli';
    if ($cliOnly && stt_meta_get('cron_key') !== null && stt_meta_get('cron_key') !== '') {
        http_response_code(403);
        echo "interdit\n";
        exit;
    }
}

$result = stt_probe_all(null, true);
$line = date('c') . ' probed=' . ($result['probed'] ?? 0) . ' ok=' . (!empty($result['ok']) ? '1' : '0') . PHP_EOL;

if (PHP_SAPI === 'cli') {
    echo $line;
    exit($result['ok'] ? 0 : 1);
}

header('Content-Type: text/plain; charset=utf-8');
echo $line;
