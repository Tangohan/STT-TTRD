<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';

$fleet = stt_fleet_snapshot();
$stale = (time() - (int) $fleet['last_probe_at']) > 90 || $fleet['last_probe_at'] === 0;
$hourly = $fleet['hourly'];

$maxLat = 1;
$maxFlux = 1;
foreach ($hourly as $row) {
    $maxLat = max($maxLat, (int) ($row['latency'] ?? 0));
    $maxFlux = max($maxFlux, (int) ($row['flux'] ?? 0));
}

function stt_chart_points(array $hourly, string $key, int $max, int $w = 640, int $h = 160): array
{
    $n = count($hourly);
    if ($n === 0) {
        return ['line' => '', 'area' => ''];
    }
    $pts = [];
    foreach ($hourly as $i => $row) {
        $v = (int) ($row[$key] ?? 0);
        $x = $n === 1 ? 0 : ($i / ($n - 1)) * $w;
        $y = $h - 8 - (($v / max(1, $max)) * ($h - 16));
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }
    $line = implode(' ', $pts);
    $first = explode(',', $pts[0]);
    $last = explode(',', $pts[count($pts) - 1]);
    $area = $first[0] . ',' . $h . ' ' . $line . ' ' . $last[0] . ',' . $h;
    return ['line' => $line, 'area' => $area];
}

$latChart = stt_chart_points($hourly, 'latency', $maxLat);
$fluxChart = stt_chart_points($hourly, 'flux', $maxFlux);

stt_head('Statistiques', 'stats', ['stale' => $stale ? '1' : '0']);
?>
<div class="shell">
<?php stt_topbar('stats'); ?>
<main class="page">
    <?php stt_diag_box($fleet['diag'], $fleet['fleet_bar'], $fleet['overall'], 'Diagnostic · soupirail'); ?>

    <div class="hero-grid-like" style="margin: 2.5rem 0 2rem;">
        <h1 style="font-size: clamp(1.75rem, 4vw, 2.35rem); font-weight: 600; letter-spacing: -.03em; margin-bottom: .75rem;">
            Le statut qui surveille <strong>ttrd.fr</strong> — crash, erreurs, latence et flux de toute la flotte.
        </h1>
        <p style="color: var(--muted); max-width: 36rem; line-height: 1.6;">
            Ce qui circule ici ne s’affiche nulle part ailleurs. Seuls les chiffres trahissent l’activité.
        </p>
    </div>

    <div class="kpi-grid">
        <div class="kpi-item">
            <div class="kpi-value"><?= (int) $fleet['total'] ?></div>
            <div class="kpi-label">Hôtes suivis</div>
        </div>
        <div class="kpi-item">
            <div class="kpi-value"><?= stt_h(stt_pct((float) $fleet['uptime_pct'])) ?></div>
            <div class="kpi-label">Uptime flotte</div>
        </div>
        <div class="kpi-item">
            <div class="kpi-value"><?= (int) $fleet['crashes_24h'] ?></div>
            <div class="kpi-label">Crash · 24 h</div>
        </div>
        <div class="kpi-item">
            <div class="kpi-value"><?= stt_h(stt_ms($fleet['avg_latency_ms'])) ?></div>
            <div class="kpi-label">Latence liaison</div>
        </div>
    </div>

    <div class="chart-wrap">
        <div class="health-bars-head">
            <span class="health-bars-title">Latence & flux — 24 dernières heures</span>
            <span class="health-bars-status status-<?= stt_h($fleet['overall']) ?>"><?= stt_h(stt_class_short($fleet['overall'])) ?></span>
        </div>
        <svg viewBox="0 0 640 160" preserveAspectRatio="none" aria-label="Latence et flux">
            <defs>
                <linearGradient id="chartGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#5eead4" stop-opacity=".35"></stop>
                    <stop offset="100%" stop-color="#5eead4" stop-opacity="0"></stop>
                </linearGradient>
            </defs>
            <g class="chart-grid">
                <line x1="0" y1="20" x2="640" y2="20"></line>
                <line x1="0" y1="80" x2="640" y2="80"></line>
                <line x1="0" y1="140" x2="640" y2="140"></line>
            </g>
            <?php if ($fluxChart['line'] !== ''): ?>
            <polygon class="chart-area" points="<?= stt_h($fluxChart['area']) ?>"></polygon>
            <polyline class="chart-line alt" points="<?= stt_h($fluxChart['line']) ?>"></polyline>
            <?php endif; ?>
            <?php if ($latChart['line'] !== ''): ?>
            <polyline class="chart-line" points="<?= stt_h($latChart['line']) ?>"></polyline>
            <?php endif; ?>
        </svg>
        <div class="uptime-footer">
            <span>Blanc = latence · teal = flux</span>
            <span class="uptime-pct"><?= (int) $fleet['flux_24h'] ?> évènements</span>
            <span><?= (int) $fleet['errors_24h'] ?> erreurs</span>
        </div>
    </div>

    <h2 class="section-title">Disponibilité · 90 jours</h2>
    <?php foreach ($fleet['sites'] as $site): ?>
    <article class="status-card">
        <div class="status-card-head">
            <div>
                <h3><a href="/site/<?= stt_h($site['host']) ?>"><?= stt_h($site['label']) ?></a></h3>
                <div class="desc"><?= stt_h($site['host']) ?> · <?= (int) $site['crashes_24h'] ?> crash · <?= (int) $site['errors_24h'] ?> erreurs · <?= stt_h(stt_ms($site['latency_ms'])) ?></div>
            </div>
            <span class="status-pill <?= stt_h($site['class']) ?>"><?= stt_h(stt_class_label($site['class'])) ?></span>
        </div>
        <?= stt_render_bar($site['uptime']['days'], $site['host'] . ' — 90 jours') ?>
        <div class="uptime-footer">
            <span>90 jours</span>
            <span class="uptime-pct"><?= stt_h(stt_pct((float) $site['uptime']['pct'])) ?> uptime</span>
            <span>Aujourd’hui</span>
        </div>
    </article>
    <?php endforeach; ?>
</main>
</div>
<?php stt_footer(); ?>
