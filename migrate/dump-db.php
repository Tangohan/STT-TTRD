<?php
/**
 * Dump MySQL résumé table par table (Hostinger coupe à ~30s).
 * 1. Copier ce fichier à côté du .env Athena (ou dans public/).
 * 2. Changer DUMP_KEY ci-dessous.
 * 3. Ouvrir : https://athena.ttrd.fr/dump-db.php?k=TA_CLE
 * 4. Laisser l’onglet ouvert jusqu’à « Terminé ».
 * 5. Récupérer athena-full.sql via le Gestionnaire de fichiers (dossier du .env).
 * 6. SUPPRIMER ce fichier.
 */
declare(strict_types=1);

const DUMP_KEY = 'ChangeMoiDump2026';
const BATCH_ROWS = 400;
const MAX_SECONDS = 8;

header('Content-Type: text/html; charset=utf-8');
set_time_limit(25);
ini_set('memory_limit', '256M');

if (($_GET['k'] ?? '') !== DUMP_KEY) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function find_env(): string
{
    $dirs = [__DIR__, dirname(__DIR__), dirname(__DIR__, 2)];
    foreach ($dirs as $dir) {
        $path = $dir . DIRECTORY_SEPARATOR . '.env';
        if (is_file($path)) {
            return $path;
        }
    }
    throw new RuntimeException('.env introuvable. Place dump-db.php dans public/ ou à la racine du projet Athena.');
}

function parse_env(string $path): array
{
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"'))
            || (str_starts_with($v, "'") && str_ends_with($v, "'"))
        ) {
            $v = substr($v, 1, -1);
        }
        $out[trim($k)] = $v;
    }
    return $out;
}

function sql_value(mysqli $db, mixed $v): string
{
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v) || is_float($v)) {
        return (string) $v;
    }
    return "'" . $db->real_escape_string((string) $v) . "'";
}

try {
    $envPath = find_env();
    $env = parse_env($envPath);
    $root = dirname($envPath);
    $sqlFile = $root . DIRECTORY_SEPARATOR . 'athena-full.sql';
    $stateFile = $root . DIRECTORY_SEPARATOR . 'athena-dump-state.json';

    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = (int) ($env['DB_PORT'] ?? 3306);
    $name = $env['DB_DATABASE'] ?? '';
    $user = $env['DB_USERNAME'] ?? '';
    $pass = $env['DB_PASSWORD'] ?? '';
    if ($name === '' || $user === '') {
        throw new RuntimeException('DB_DATABASE / DB_USERNAME absents du .env');
    }

    $db = mysqli_init();
    if (!$db || !$db->real_connect($host, $user, $pass, $name, $port)) {
        throw new RuntimeException('MySQL: ' . mysqli_connect_error());
    }
    $db->set_charset('utf8mb4');
    $db->query('SET SESSION sql_mode=""');

    $tables = [];
    $res = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }

    $state = [
        'header' => false,
        'table_i' => 0,
        'offset' => 0,
        'created' => false,
        'done' => false,
        'rows' => 0,
    ];
    if (is_file($stateFile)) {
        $prev = json_decode((string) file_get_contents($stateFile), true);
        if (is_array($prev)) {
            $state = array_merge($state, $prev);
        }
    }

    $reset = isset($_GET['reset']);
    if ($reset) {
        @unlink($sqlFile);
        $state = ['header' => false, 'table_i' => 0, 'offset' => 0, 'created' => false, 'done' => false, 'rows' => 0];
    }

    $deadline = microtime(true) + MAX_SECONDS;
    $fh = fopen($sqlFile, $state['header'] ? 'ab' : 'wb');
    if (!$fh) {
        throw new RuntimeException('Impossible d’écrire ' . $sqlFile);
    }

    if (!$state['header']) {
        fwrite($fh, "-- Athena dump " . date('c') . "\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        $state['header'] = true;
    }

    $currentName = $tables[$state['table_i']] ?? null;
    while (!$state['done'] && microtime(true) < $deadline) {
        if ($state['table_i'] >= count($tables)) {
            fwrite($fh, "SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n");
            $state['done'] = true;
            break;
        }
        $table = $tables[$state['table_i']];
        $currentName = $table;
        $safe = '`' . str_replace('`', '``', $table) . '`';

        if (!$state['created']) {
            $cr = $db->query("SHOW CREATE TABLE {$safe}");
            $create = $cr->fetch_assoc();
            $ddl = $create['Create Table'] ?? '';
            fwrite($fh, "DROP TABLE IF EXISTS {$safe};\n{$ddl};\n\n");
            $state['created'] = true;
            $state['offset'] = 0;
        }

        $off = (int) $state['offset'];
        $q = $db->query("SELECT * FROM {$safe} LIMIT " . BATCH_ROWS . " OFFSET {$off}");
        $n = 0;
        $vals = [];
        $cols = null;
        while ($row = $q->fetch_assoc()) {
            if ($cols === null) {
                $cols = '`' . implode('`,`', array_map(static fn ($c) => str_replace('`', '``', $c), array_keys($row))) . '`';
            }
            $parts = [];
            foreach ($row as $v) {
                $parts[] = sql_value($db, $v);
            }
            $vals[] = '(' . implode(',', $parts) . ')';
            $n++;
        }
        if ($n > 0) {
            fwrite($fh, "INSERT INTO {$safe} ({$cols}) VALUES\n" . implode(",\n", $vals) . ";\n");
            $state['offset'] = $off + $n;
            $state['rows'] += $n;
        }
        if ($n < BATCH_ROWS) {
            fwrite($fh, "\n");
            $state['table_i']++;
            $state['created'] = false;
            $state['offset'] = 0;
        }
    }

    fclose($fh);
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));

    $size = is_file($sqlFile) ? filesize($sqlFile) : 0;
    $pct = count($tables) === 0 ? 100 : min(100, (int) round(($state['table_i'] / count($tables)) * 100));
    $refresh = $state['done'] ? '' : '<meta http-equiv="refresh" content="1">';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>' . h($e->getMessage()) . '</pre>';
    exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<?= $refresh ?>
<title>Dump Athena</title>
<style>
body{font-family:Georgia,serif;background:#111;color:#eee;max-width:42rem;margin:3rem auto;padding:0 1.2rem}
.bar{height:10px;background:#333;border-radius:6px;overflow:hidden;margin:1rem 0}
.bar span{display:block;height:100%;background:#c9a227;width:<?= (int) $pct ?>%}
a{color:#c9a227}
code{background:#1c1c1c;padding:.1rem .35rem}
</style>
</head>
<body>
<h1>Dump Athena</h1>
<p>Base <code><?= h($name) ?></code> — <?= count($tables) ?> tables — <?= (int) $state['rows'] ?> lignes écrites</p>
<div class="bar"><span></span></div>
<?php if ($state['done']): ?>
<p><strong>Terminé.</strong> Fichier : <code><?= h($sqlFile) ?></code> (<?= number_format($size / 1048576, 1, ',', ' ') ?> Mo)</p>
<p>Récupère-le dans le gestionnaire de fichiers Hostinger (même dossier que le <code>.env</code>), puis <strong>supprime dump-db.php</strong> et <code>athena-dump-state.json</code>.</p>
<?php else: ?>
<p>En cours<?= $currentName ? ' : table <code>' . h($currentName) . '</code>' : '' ?> (<?= $state['table_i'] ?>/<?= count($tables) ?>). Laisse cet onglet ouvert.</p>
<p><a href="?k=<?= h(DUMP_KEY) ?>&amp;reset=1">Recommencer de zéro</a></p>
<?php endif; ?>
</body>
</html>
