<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';

$host = stt_canonical_host((string) ($_GET['host'] ?? ''));
if ($host === '') {
    header('Location: /');
    exit;
}

$site = stt_site_detail($host);
if (!$site) {
    http_response_code(404);
    stt_head('Introuvable', 'site');
    echo '<div class="shell">';
    stt_topbar('home');
    echo '<main class="page"><p>Hôte inconnu.</p></main></div>';
    stt_footer();
    exit;
}

$stale = empty($site['checked_at']) || (time() - (int) $site['checked_at']) > 90;
$code = $site['http_code'] ? 'Réponse ' . $site['http_code'] : 'Sans réponse';
$kicker = $code . ' · ' . strtolower(stt_class_short($site['class']));
$beacon = rtrim((string) (isset($_SERVER['HTTP_HOST']) ? ('https://' . $_SERVER['HTTP_HOST']) : 'https://stt.ttrd.fr'), '/')
    . '/api/beacon?host=' . rawurlencode($site['host']);

stt_head($site['label'], 'site', ['stale' => $stale ? '1' : '0']);
?>
<div class="shell">
<?php stt_topbar('home'); ?>
<main>
    <section class="hero">
        <div class="hero-inner">
            <p class="hero-kicker <?= stt_h($site['class']) ?>"><?= stt_h($kicker) ?></p>
            <h1 class="hero-title"><?= stt_h($site['label']) ?></h1>
            <?php stt_diag_box($site['diag'], $site['uptime']['days'], $site['class'], 'Diagnostic · ' . $site['host']); ?>
            <p class="hero-panel">
                <strong><?= stt_h($site['host']) ?></strong> —
                crash <?= (int) $site['crashes_24h'] ?>,
                erreurs <?= (int) $site['errors_24h'] ?>,
                latence <?= stt_h(stt_ms($site['latency_ms'])) ?>,
                flux <?= stt_h(stt_flux((int) $site['flux_24h'])) ?> / 24 h.
                <?php if (!empty($site['error'])): ?>Dernier signal&nbsp;: <?= stt_h($site['error']) ?>.<?php endif; ?>
            </p>
            <div class="actions">
                <a class="btn btn-primary" href="<?= stt_h($site['url']) ?>" target="_blank" rel="noopener">Ouvrir le site</a>
                <a class="btn" href="/stats">Statistiques</a>
            </div>
        </div>
    </section>

    <div class="page">
        <div class="kpi-grid">
            <div class="kpi-item">
                <div class="kpi-value"><?= stt_h(stt_ms($site['latency_ms'])) ?></div>
                <div class="kpi-label">Latence actuelle</div>
            </div>
            <div class="kpi-item">
                <div class="kpi-value"><?= stt_h(stt_ms($site['latency_avg_24h'])) ?></div>
                <div class="kpi-label">Latence moyenne 24 h</div>
            </div>
            <div class="kpi-item">
                <div class="kpi-value"><?= (int) $site['crashes_24h'] ?></div>
                <div class="kpi-label">Crash · 24 h</div>
            </div>
            <div class="kpi-item">
                <div class="kpi-value"><?= stt_h(stt_flux((int) $site['flux_24h'])) ?></div>
                <div class="kpi-label">Flux · 24 h</div>
            </div>
        </div>

        <div class="chart-wrap">
            <div class="health-bars-head">
                <span class="health-bars-title">Latence — 6 dernières heures</span>
                <span class="diag-pct"><?= stt_h($site['ip'] ?? '') ?></span>
            </div>
            <?= stt_spark_svg($site['spark']['latency'] ?? []) ?>
        </div>

        <h2 class="section-title">Sondes récentes</h2>
        <table class="table">
            <thead>
                <tr><th>Heure</th><th>Classe</th><th>HTTP</th><th>Latence</th><th>Flux</th><th>Signal</th></tr>
            </thead>
            <tbody>
            <?php foreach ($site['history'] as $row): ?>
                <tr>
                    <td><?= stt_h(date('d/m H:i:s', (int) $row['ts'])) ?></td>
                    <td class="status-pill <?= stt_h($row['status_class']) ?>"><?= stt_h(stt_class_short($row['status_class'])) ?></td>
                    <td><?= $row['http_code'] !== null ? (int) $row['http_code'] : '—' ?></td>
                    <td><?= stt_h(stt_ms($row['latency_ms'])) ?></td>
                    <td><?= stt_h(stt_bytes((int) $row['bytes'])) ?></td>
                    <td><?= stt_h($row['error'] ?? 'ok') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($site['incident_log'] !== []): ?>
        <h2 class="section-title">Journal d’incidents</h2>
        <?php foreach ($site['incident_log'] as $inc): ?>
        <div class="incident <?= $inc['kind'] === 'crash' ? '' : 'degraded' ?>">
            <div>
                <div class="kind"><?= $inc['kind'] === 'crash' ? 'Crash' : 'Erreur' ?></div>
                <div class="desc"><?= stt_h($inc['detail'] ?? '') ?></div>
            </div>
            <span class="mono">
                <?= stt_h(date('d/m H:i', (int) $inc['started_at'])) ?>
                →
                <?= !empty($inc['ended_at']) ? stt_h(date('d/m H:i', (int) $inc['ended_at'])) : 'en cours' ?>
            </span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <h2 class="section-title">Balise de flux</h2>
        <p style="color: var(--muted); font-size: .9rem; margin-bottom: .75rem;">
            Pour mesurer le vrai trafic (pas seulement les sondes), collez ceci dans le site&nbsp;:
        </p>
        <div class="snippet">new Image().src = <?= json_encode($beacon, JSON_UNESCAPED_SLASHES) ?> + '&amp;code=' + (window.__sttCode || 200) + '&amp;ms=' + Math.round(performance.now());</div>
    </div>
</main>
</div>
<?php stt_footer(); ?>
