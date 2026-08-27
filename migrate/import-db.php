<?php
/**
 * Import du dump déjà présent sur le disque du VPS (pas d’upload navigateur).
 * 1. FileZilla : envoyer athena-full.sql à /var/www/athena.ttrd.fr/athena-full.sql
 * 2. Copier ce fichier dans public/import-db.php
 * 3. Changer IMPORT_KEY
 * 4. Ouvrir http://72.62.22.55/import-db.php?k=TA_CLE  (Host: athena.ttrd.fr si vhost prêt)
 * 5. Cliquer Importer — SUPPRIMER ce fichier après.
 */
declare(strict_types=1);

const IMPORT_KEY = 'ChangeMoiImport2026';

header('Content-Type: text/html; charset=utf-8');
set_time_limit(0);
ini_set('memory_limit', '1024M');
ini_set('max_execution_time', '0');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function find_env(): ?string
{
    foreach ([__DIR__, dirname(__DIR__), dirname(__DIR__, 2)] as $dir) {
        $path = $dir . DIRECTORY_SEPARATOR . '.env';
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
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

function find_sql(): ?string
{
    $cands = [
        dirname(__DIR__) . '/athena-full.sql',
        dirname(__DIR__) . '/athena.sql',
        __DIR__ . '/athena-full.sql',
        '/var/www/athena.ttrd.fr/athena-full.sql',
        '/root/athena-full.sql',
        '/root/athena.sql',
    ];
    foreach ($cands as $p) {
        if (is_readable($p) && filesize($p) > 1000) {
            return $p;
        }
    }
    return null;
}

if (($_GET['k'] ?? '') !== IMPORT_KEY) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$envPath = find_env();
$env = $envPath ? parse_env($envPath) : [];
$sqlFile = find_sql();
$log = '';
$ok = null;

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = (int) ($env['DB_PORT'] ?? 3306);
$name = $env['DB_DATABASE'] ?? 'athena';
$user = $env['DB_USERNAME'] ?? 'athena';
$pass = $env['DB_PASSWORD'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['go'])) {
    if (!$sqlFile) {
        $ok = false;
        $log = 'Fichier SQL introuvable. Envoie athena-full.sql à /var/www/athena.ttrd.fr/athena-full.sql';
    } elseif ($pass === '') {
        $ok = false;
        $log = '.env introuvable ou DB_PASSWORD vide.';
    } else {
        $tmpCnf = sys_get_temp_dir() . '/athena-import.cnf';
        file_put_contents($tmpCnf, "[client]\nuser={$user}\npassword=\"{$pass}\"\nhost={$host}\nport={$port}\n");
        chmod($tmpCnf, 0600);

        $drop = sprintf(
            'mysql --defaults-extra-file=%s -e %s',
            escapeshellarg($tmpCnf),
            escapeshellarg("DROP DATABASE IF EXISTS `{$name}`; CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")
        );
        $import = sprintf(
            'mysql --defaults-extra-file=%s --init-command=%s %s < %s',
            escapeshellarg($tmpCnf),
            escapeshellarg('SET SESSION FOREIGN_KEY_CHECKS=0; SET SESSION UNIQUE_CHECKS=0;'),
            escapeshellarg($name),
            escapeshellarg($sqlFile)
        );

        $out = [];
        $code = 0;
        exec($drop . ' 2>&1', $out, $code);
        if ($code === 0) {
            $out = [];
            exec($import . ' 2>&1', $out, $code);
        }
        @unlink($tmpCnf);
        $log = implode("\n", $out);
        $ok = $code === 0;
        if ($ok && $log === '') {
            $log = 'Import OK.';
        }
    }
}

$size = $sqlFile && is_file($sqlFile) ? filesize($sqlFile) : 0;
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Import Athena</title>
<style>
body{font-family:Georgia,serif;background:#111;color:#eee;max-width:42rem;margin:3rem auto;padding:0 1.2rem}
button{background:#c9a227;border:0;padding:.7rem 1.2rem;font:inherit;cursor:pointer}
code,pre{background:#1c1c1c;padding:.6rem;display:block;white-space:pre-wrap}
.ok{color:#8d8}.err{color:#e88}
</style>
</head>
<body>
<h1>Import Athena</h1>
<p>SQL : <?= $sqlFile ? '<code>' . h($sqlFile) . '</code> (' . number_format($size / 1048576, 1, ',', ' ') . ' Mo)' : '<strong class="err">aucun fichier trouvé</strong>' ?></p>
<p>Base cible : <code><?= h($name) ?></code> @ <code><?= h($host) ?></code></p>
<?php if ($ok === true): ?>
<p class="ok">Terminé. Supprime <code>import-db.php</code> et le .sql.</p>
<?php elseif ($ok === false): ?>
<p class="err">Échec</p>
<pre><?= h($log) ?></pre>
<?php endif; ?>
<form method="post">
<button name="go" value="1" <?= $sqlFile ? '' : 'disabled' ?>>Vider la base et importer</button>
</form>
<p>Le fichier doit déjà être sur le VPS (FileZilla / SFTP). Ce bouton ne téléverse rien.</p>
</body>
</html>
