<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/migrate.php';
require __DIR__ . '/lib/layout.php';

stt_session_start();

$flash = $_SESSION['stt_flash'] ?? null;
unset($_SESSION['stt_flash']);
$error = null;
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'setup') {
        try {
            if (stt_auth_configured()) {
                $error = 'Mot de passe déjà défini.';
            } else {
                stt_auth_set_password((string) ($_POST['password'] ?? ''));
                stt_admin_login((string) ($_POST['password'] ?? ''));
                header('Location: /admin');
                exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'login') {
        if (!stt_admin_login((string) ($_POST['password'] ?? ''))) {
            $error = 'Mot de passe incorrect.';
        } else {
            $next = (string) ($_POST['next'] ?? '/admin');
            if (!str_starts_with($next, '/admin')) {
                $next = '/admin';
            }
            header('Location: ' . $next);
            exit;
        }
    } elseif ($action === 'logout') {
        stt_admin_logout();
        header('Location: /admin');
        exit;
    } elseif (stt_admin_logged() && stt_admin_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
        if ($action === 'pipeline' || $action === 'host') {
            $patch = [];
            if ($action === 'pipeline') {
                $pipe = [];
                foreach (stt_mig_pipeline_def() as $step) {
                    $pipe[$step['id']] = isset($_POST['step'][$step['id']]);
                }
                $patch['pipeline'] = $pipe;
            }
            if ($action === 'host') {
                $attached = $_POST['attached'] ?? '';
                $patch = [
                    'host' => (string) ($_POST['host'] ?? ''),
                    'fields' => [
                        'attached' => $attached === '' ? null : $attached === '1',
                        'stack' => $_POST['stack'] ?? '',
                        'php' => $_POST['php'] ?? '',
                        'node' => $_POST['node'] ?? '',
                        'db_name' => $_POST['db_name'] ?? '',
                        'notes' => $_POST['notes'] ?? '',
                        'has_api' => isset($_POST['has_api']),
                        'files' => isset($_POST['files']),
                        'hidden' => isset($_POST['hidden']),
                        'db_export' => isset($_POST['db_export']),
                        'db_import' => isset($_POST['db_import']),
                        'cron' => isset($_POST['cron']),
                        'env' => isset($_POST['env']),
                        'deps' => isset($_POST['deps']),
                        'vps' => isset($_POST['vps']),
                    ],
                ];
            }
            stt_mig_apply_patch($patch);
            $_SESSION['stt_flash'] = 'Enregistré.';
            $redir = $action === 'host' ? '/admin?host=' . rawurlencode((string) ($_POST['host'] ?? '')) : '/admin';
            header('Location: ' . $redir);
            exit;
        }
        if ($action === 'vps-probe') {
            $host = stt_canonical_host((string) ($_POST['host'] ?? ''));
            stt_mig_vps_probe($host);
            header('Location: /admin?host=' . rawurlencode($host));
            exit;
        }
        if ($action === 'attached') {
            stt_mig_apply_patch([
                'host' => (string) ($_POST['host'] ?? ''),
                'fields' => ['attached' => ($_POST['attached'] ?? '') === '' ? null : $_POST['attached'] === '1'],
            ]);
            header('Location: /admin');
            exit;
        }
    }
}

function stt_admin_gate(?string $error, mixed $flash): bool
{
    if (stt_admin_logged()) {
        return true;
    }
    $setup = !stt_auth_configured();
    stt_head($setup ? 'Créer l’accès' : 'Admin', 'admin', ['stale' => '0']);
    echo '<div class="shell">';
    stt_topbar('admin');
    echo '<main class="page admin-auth">';
    echo '<p class="hero-kicker">Gestionnaire · migration</p>';
    echo '<h1 class="admin-title">' . ($setup ? 'Premier accès' : 'Admin') . '</h1>';
    if ($error) {
        echo '<p class="admin-error">' . stt_h($error) . '</p>';
    }
    echo '<form method="post" class="admin-card admin-form">';
    echo '<input type="hidden" name="action" value="' . ($setup ? 'setup' : 'login') . '">';
    echo '<input type="hidden" name="next" value="' . stt_h((string) ($_GET['next'] ?? '/admin')) . '">';
    echo '<label>Mot de passe' . ($setup ? ' (8 caractères min., stocké en hash local)' : '') . '</label>';
    echo '<input type="password" name="password" required minlength="8" autocomplete="' . ($setup ? 'new-password' : 'current-password') . '">';
    echo '<button class="btn btn-primary" type="submit">' . ($setup ? 'Créer l’accès' : 'Entrer') . '</button>';
    echo '</form>';
    echo '<p class="hero-whisper" style="margin-top:1.5rem">Cockpit de migration Premium Web Hosting → VPS. Le DNS ne bouge pas d’ici.</p>';
    echo '</main></div>';
    stt_footer();
    return false;
}

if (!stt_admin_gate($error, $flash)) {
    exit;
}

$state = stt_mig_load();
$summary = stt_mig_summary($state);
$csrf = stt_admin_csrf();
$focus = stt_canonical_host((string) ($_GET['host'] ?? ''));
$hostRow = $focus !== '' ? ($state['hosts'][$focus] ?? null) : null;

stt_head($hostRow ? $hostRow['label'] : 'Migration', 'admin', ['stale' => '0']);
?>
<div class="shell">
<?php stt_topbar('admin'); ?>
<main class="page">
    <div class="admin-head">
        <div>
            <p class="hero-kicker">Premium Web Hosting · <?= stt_h($state['source']['account']) ?> → VPS <?= stt_h($state['target']['vps_id']) ?></p>
            <h1 class="admin-title"><?= $hostRow ? stt_h($hostRow['label']) : 'Migration ttrd.fr' ?></h1>
            <p class="admin-sub">Périmètre confirmé : site principal et sous-domaines. WordPress non détecté. Le VPS <?= stt_h($state['target']['ip']) ?> est vide — on ne copie pas encore le DNS.</p>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="logout">
            <button class="btn" type="submit">Quitter</button>
        </form>
    </div>

    <?php if ($flash): ?><p class="admin-ok"><?= stt_h((string) $flash) ?></p><?php endif; ?>

<?php if ($hostRow): ?>
    <p class="crumb"><a href="/admin">← Inventaire</a> · <?= stt_h($hostRow['host']) ?></p>
    <div class="kpi-grid">
        <div class="kpi-item"><div class="kpi-value"><?= (int) stt_mig_host_progress($hostRow)['pct'] ?>%</div><div class="kpi-label">Avancement</div></div>
        <div class="kpi-item"><div class="kpi-value"><?= $hostRow['attached'] === true ? 'Oui' : ($hostRow['attached'] === false ? 'Non' : '?') ?></div><div class="kpi-label">Rattaché au domaine</div></div>
        <div class="kpi-item"><div class="kpi-value"><?= stt_h($hostRow['stack'] ?: '—') ?></div><div class="kpi-label">Stack</div></div>
        <div class="kpi-item"><div class="kpi-value"><?= !empty($hostRow['vps']) ? 'OK' : '—' ?></div><div class="kpi-label">Test VPS</div></div>
    </div>

    <section class="admin-card">
        <h2 class="section-title">Chemins FTP (Hostinger)</h2>
        <p class="admin-note">Copier le dossier du domaine entier, y compris les fichiers cachés. Un dump de <code>public_html</code> seul ne suffit pas.</p>
        <div class="snippet"><?php foreach ($hostRow['paths'] as $k => $p): ?><?= stt_h($k) ?>  <?= stt_h($p) . "\n" ?><?php endforeach; ?></div>
    </section>

    <form method="post" class="admin-card admin-form">
        <input type="hidden" name="action" value="host">
        <input type="hidden" name="csrf" value="<?= stt_h($csrf) ?>">
        <input type="hidden" name="host" value="<?= stt_h($hostRow['host']) ?>">

        <h2 class="section-title">Rattachement</h2>
        <label>Ce service est-il réellement rattaché à ttrd.fr ?</label>
        <select name="attached">
            <option value="" <?= $hostRow['attached'] === null ? 'selected' : '' ?>>À recenser</option>
            <option value="1" <?= $hostRow['attached'] === true ? 'selected' : '' ?>>Oui — à migrer</option>
            <option value="0" <?= $hostRow['attached'] === false ? 'selected' : '' ?>>Non — hors périmètre</option>
        </select>

        <div class="admin-grid">
            <div>
                <label>Stack</label>
                <select name="stack">
                    <?php foreach (['' => 'Inconnue', 'php' => 'PHP', 'node' => 'Node.js', 'static' => 'Statique', 'api' => 'API'] as $val => $lab): ?>
                    <option value="<?= stt_h($val) ?>" <?= ($hostRow['stack'] ?? '') === $val ? 'selected' : '' ?>><?= stt_h($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>PHP</label>
                <input type="text" name="php" value="<?= stt_h($hostRow['php']) ?>" placeholder="8.3">
            </div>
            <div>
                <label>Node</label>
                <input type="text" name="node" value="<?= stt_h($hostRow['node']) ?>" placeholder="20">
            </div>
            <div>
                <label>Base MySQL</label>
                <input type="text" name="db_name" value="<?= stt_h($hostRow['db_name']) ?>" placeholder="u416380327_…">
            </div>
        </div>

        <label class="check"><input type="checkbox" name="has_api" <?= !empty($hostRow['has_api']) ? 'checked' : '' ?>> API / dépendances à réinstaller</label>

        <h2 class="section-title">À transférer</h2>
        <label class="check"><input type="checkbox" name="files" <?= !empty($hostRow['files']) ? 'checked' : '' ?>> Fichiers (dossier domain via FTP)</label>
        <label class="check"><input type="checkbox" name="hidden" <?= !empty($hostRow['hidden']) ? 'checked' : '' ?>> Fichiers cachés (.env, .htaccess, configs)</label>
        <label class="check"><input type="checkbox" name="db_export" <?= !empty($hostRow['db_export']) ? 'checked' : '' ?>> Export SQL (phpMyAdmin / mysqldump)</label>
        <label class="check"><input type="checkbox" name="db_import" <?= !empty($hostRow['db_import']) ? 'checked' : '' ?>> Import SQL sur le VPS</label>
        <label class="check"><input type="checkbox" name="env" <?= !empty($hostRow['env']) ? 'checked' : '' ?>> Variables d’environnement recréées</label>
        <label class="check"><input type="checkbox" name="deps" <?= !empty($hostRow['deps']) ? 'checked' : '' ?>> Dépendances (composer / npm) installées</label>
        <label class="check"><input type="checkbox" name="cron" <?= !empty($hostRow['cron']) ? 'checked' : '' ?>> Tâches cron recréées</label>
        <label class="check"><input type="checkbox" name="vps" <?= !empty($hostRow['vps']) ? 'checked' : '' ?>> Test OK sur <?= stt_h($state['target']['ip']) ?></label>

        <label>Notes techniques</label>
        <textarea name="notes" rows="4" placeholder="Nom de l’API, ports, secrets à recréer…"><?= stt_h($hostRow['notes']) ?></textarea>

        <div class="actions" style="justify-content:flex-start;margin-top:1.25rem">
            <button class="btn btn-primary" type="submit">Enregistrer</button>
            <a class="btn" href="<?= stt_h($hostRow['url'] ?? ('https://' . $hostRow['host'])) ?>" target="_blank" rel="noopener">Site actuel</a>
        </div>
    </form>

    <form method="post" class="admin-card">
        <input type="hidden" name="action" value="vps-probe">
        <input type="hidden" name="csrf" value="<?= stt_h($csrf) ?>">
        <input type="hidden" name="host" value="<?= stt_h($hostRow['host']) ?>">
        <h2 class="section-title">Test VPS sans DNS</h2>
        <p class="admin-note">Requête HTTP vers <?= stt_h($state['target']['ip']) ?> avec l’en-tête <code>Host: <?= stt_h($hostRow['host']) ?></code>. Ne change rien au DNS.</p>
        <?php if (!empty($hostRow['vps_probe'])): $vp = $hostRow['vps_probe']; ?>
        <p class="status-pill <?= stt_h($vp['class'] ?? '') ?>">
            <?= $vp['http_code'] ? 'HTTP ' . (int) $vp['http_code'] : 'sans réponse' ?>
            · <?= stt_h(stt_ms($vp['latency_ms'] ?? null)) ?>
            · <?= stt_h($vp['error'] ?? 'ok') ?>
            · <?= stt_h(stt_ago($vp['ts'] ?? null)) ?>
        </p>
        <?php endif; ?>
        <button class="btn" type="submit" style="margin-top:1rem">Sonder le VPS</button>
    </form>

<?php else: ?>

    <div class="kpi-grid">
        <div class="kpi-item"><div class="kpi-value"><?= (int) $summary['attached'] ?>/<?= (int) $summary['hosts'] ?></div><div class="kpi-label">Rattachés / recensés</div></div>
        <div class="kpi-item"><div class="kpi-value"><?= (int) $summary['unknown'] ?></div><div class="kpi-label">Encore à recenser</div></div>
        <div class="kpi-item"><div class="kpi-value"><?= (int) $summary['vps_ok'] ?></div><div class="kpi-label">Tests VPS OK</div></div>
        <div class="kpi-item"><div class="kpi-value"><?= (int) $summary['pipeline'] ?>/<?= (int) $summary['pipeline_total'] ?></div><div class="kpi-label">Étapes pipeline</div></div>
    </div>

    <div class="split-two">
        <section class="admin-card">
            <h2 class="section-title">À récupérer</h2>
            <ul class="guide">
                <li><code><?= stt_h($state['source']['domains']) ?>/ttrd.fr/</code> et le dossier de chaque sous-domaine</li>
                <li>Fichiers cachés : <code>.env</code>, <code>.htaccess</code>, configs API</li>
                <li>Bases via phpMyAdmin / export SQL — pas via FTP</li>
                <li>Cron, variables d’environnement, versions PHP/Node, dépendances</li>
            </ul>
        </section>
        <section class="admin-card">
            <h2 class="section-title">À ne pas copier tel quel</h2>
            <ul class="guide">
                <li>Boîtes e-mail — migration séparée</li>
                <li>Certificats SSL — à recréer sur le VPS</li>
                <li>DNS — pointer vers <?= stt_h($state['target']['ip']) ?> seulement après tests</li>
                <li>Caches, tmp, logs, <code>node_modules</code></li>
            </ul>
        </section>
    </div>

    <section class="admin-card ftp-answer">
        <h2 class="section-title">FTP du dossier domains</h2>
        <p>Oui, tu récupères les fichiers via FTP depuis le Premium Web Hosting vers le VPS. <strong>Copier uniquement <code>domains</code> ne suffit pas</strong> pour une migration complète : les bases, cron, .env et stacks se traitent à part. Conserve l’ancien hébergement jusqu’à ce que chaque site/API réponde sur <?= stt_h($state['target']['ip']) ?>.</p>
    </section>

    <form method="post" class="admin-card">
        <input type="hidden" name="action" value="pipeline">
        <input type="hidden" name="csrf" value="<?= stt_h($csrf) ?>">
        <h2 class="section-title">Ordre de migration</h2>
        <ol class="pipeline">
            <?php foreach ($state['pipeline'] as $i => $step): ?>
            <li class="<?= !empty($step['done']) ? 'is-done' : '' ?> <?= $step['id'] === 'dns' && !$summary['can_dns'] ? 'is-locked' : '' ?>">
                <label class="check">
                    <input type="checkbox" name="step[<?= stt_h($step['id']) ?>]" <?= !empty($step['done']) ? 'checked' : '' ?>
                        <?= $step['id'] === 'dns' && !$summary['can_dns'] && empty($step['done']) ? 'disabled' : '' ?>>
                    <span><b><?= ($i + 1) ?>. <?= stt_h($step['label']) ?></b><small><?= stt_h($step['hint']) ?></small></span>
                </label>
            </li>
            <?php endforeach; ?>
        </ol>
        <p class="admin-note">L’étape DNS reste verrouillée tant que les tests VPS ne sont pas cochés. C’est volontaire.</p>
        <button class="btn btn-primary" type="submit">Mettre à jour le pipeline</button>
    </form>

    <div class="toolbar">
        <div class="filters">
            <button class="chip is-on" type="button" data-mig-filter="all">Tous</button>
            <button class="chip" type="button" data-mig-filter="flagship">Flagship</button>
            <button class="chip" type="button" data-mig-filter="unknown">À recenser</button>
            <button class="chip" type="button" data-mig-filter="attached">Rattachés</button>
            <button class="chip" type="button" data-mig-filter="skip">Hors périmètre</button>
        </div>
        <input class="search" data-mig-search type="search" placeholder="Filtrer un hôte…">
    </div>

    <div class="mig-list">
        <?php foreach ($state['hosts'] as $h): $p = stt_mig_host_progress($h); ?>
        <article class="status-card mig-card" data-mig-card
            data-flagship="<?= $h['flagship'] ? '1' : '0' ?>"
            data-attached="<?= $h['attached'] === true ? '1' : ($h['attached'] === false ? '0' : 'x') ?>"
            data-hay="<?= stt_h($h['label'] . ' ' . $h['host']) ?>">
            <div class="status-card-head">
                <div>
                    <h3><a href="/admin?host=<?= stt_h($h['host']) ?>"><?= stt_h($h['label']) ?></a><?php if ($h['flagship']): ?> <span class="flag">★</span><?php endif; ?></h3>
                    <div class="desc"><?= stt_h($h['host']) ?> · <?= stt_h($h['paths']['public']) ?></div>
                </div>
                <span class="status-pill <?= stt_h($p['class']) ?>"><?= stt_h($p['label']) ?></span>
            </div>
            <form method="post" class="attach-inline">
                <input type="hidden" name="action" value="attached">
                <input type="hidden" name="csrf" value="<?= stt_h($csrf) ?>">
                <input type="hidden" name="host" value="<?= stt_h($h['host']) ?>">
                <button class="chip <?= $h['attached'] === true ? 'is-on' : '' ?>" name="attached" value="1" type="submit">Rattaché</button>
                <button class="chip <?= $h['attached'] === false ? 'is-on' : '' ?>" name="attached" value="0" type="submit">Hors</button>
                <button class="chip <?= $h['attached'] === null ? 'is-on' : '' ?>" name="attached" value="" type="submit">?</button>
                <a class="chip" href="/admin?host=<?= stt_h($h['host']) ?>">Fiche technique</a>
            </form>
        </article>
        <?php endforeach; ?>
    </div>

    <section class="admin-card">
        <h2 class="section-title">Runbook</h2>
        <div class="snippet"><?= stt_h(stt_mig_runbook($state)) ?></div>
    </section>
<?php endif; ?>
</main>
</div>
<?php stt_footer(); ?>
