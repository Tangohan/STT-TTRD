<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';

$fleet = stt_fleet_snapshot();
$stale = (time() - (int) $fleet['last_probe_at']) > 90 || $fleet['last_probe_at'] === 0;

$kicker = match ($fleet['overall']) {
    'operational' => 'Présence détectée · flotte nominale',
    'degraded' => 'Dégradation · latence ou erreurs',
    'outage' => 'Incident · crash détecté',
    default => 'Sondage en cours · première lecture',
};

stt_head('Statut', 'home', ['stale' => $stale ? '1' : '0']);
?>
<div class="shell">
<?php stt_topbar('home'); ?>
<main>
    <section class="hero">
        <div class="hero-inner">
            <p class="hero-kicker <?= stt_h($fleet['overall']) ?>"><?= stt_h($kicker) ?></p>
            <h1 class="hero-title">Statut</h1>
            <?php stt_diag_box($fleet['diag'], $fleet['fleet_bar'], $fleet['overall']); ?>
            <p class="hero-panel">
                Tableau de bord des hôtes suivis : crash, erreurs HTTP, latence et flux.
            </p>
            <div class="actions">
                <a class="btn btn-primary" href="/stats">Statistiques</a>
                <a class="btn" href="https://ttrd.fr">Retour au site</a>
            </div>
        </div>
    </section>

    <div class="page">
        <div class="kpi-grid">
            <div class="kpi-item">
                <div class="kpi-value"><?= (int) $fleet['up'] ?>/<?= (int) $fleet['total'] ?></div>
                <div class="kpi-label">Sites en ligne</div>
            </div>
            <div class="kpi-item">
                <div class="kpi-value"><?= stt_h(stt_ms($fleet['avg_latency_ms'])) ?></div>
                <div class="kpi-label">Latence moyenne</div>
            </div>
            <div class="kpi-item">
                <div class="kpi-value"><?= (int) $fleet['crashes_24h'] + (int) $fleet['errors_24h'] ?></div>
                <div class="kpi-label">Crash & erreurs · 24 h</div>
            </div>
            <div class="kpi-item">
                <div class="kpi-value"><?= stt_h(stt_flux((int) $fleet['flux_24h'])) ?></div>
                <div class="kpi-label">Flux · 24 h</div>
            </div>
        </div>

        <?php if ($fleet['incidents'] !== []): ?>
        <h2 class="section-title">Incidents ouverts</h2>
        <?php foreach ($fleet['incidents'] as $inc): ?>
        <a class="incident <?= $inc['kind'] === 'crash' ? '' : 'degraded' ?>" href="/site/<?= stt_h($inc['host']) ?>">
            <div>
                <div class="kind"><?= $inc['kind'] === 'crash' ? 'Crash' : 'Erreur' ?></div>
                <strong><?= stt_h($inc['label']) ?></strong>
                <div class="desc"><?= stt_h($inc['host']) ?> · <?= stt_h($inc['detail'] ?? '') ?></div>
            </div>
            <span class="mono"><?= stt_h(stt_ago($inc['started_at'])) ?></span>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>

        <div class="toolbar">
            <div class="filters">
                <button class="chip is-on" type="button" data-filter="all">Tous</button>
                <button class="chip" type="button" data-filter="operational">En ligne</button>
                <button class="chip" type="button" data-filter="degraded">Dégradé</button>
                <button class="chip" type="button" data-filter="outage">Crash</button>
                <button class="chip" type="button" data-filter="ttrd">ttrd.fr</button>
                <button class="chip" type="button" data-filter="externe">Externes</button>
            </div>
            <input class="search" data-filter-search type="search" placeholder="Filtrer un hôte…">
        </div>

        <section class="status-section">
            <h2>Disponibilité · 90 jours</h2>
            <?php foreach ($fleet['sites'] as $site): ?>
            <article class="status-card" data-site-card data-group="<?= stt_h($site['site_group']) ?>" data-status="<?= stt_h($site['class']) ?>" data-hay="<?= stt_h($site['label'] . ' ' . $site['host']) ?>">
                <div class="status-card-head">
                    <div>
                        <h3><a href="/site/<?= stt_h($site['host']) ?>"><?= stt_h($site['label']) ?></a></h3>
                        <div class="desc"><?= stt_h($site['host']) ?></div>
                    </div>
                    <span class="status-pill <?= stt_h($site['class']) ?>"><?= stt_h(stt_class_label($site['class'])) ?></span>
                </div>
                <?= stt_render_bar($site['uptime']['days'], $site['host'] . ' — 90 jours') ?>
                <div class="meta-row">
                    <div><b><?= $site['http_code'] ? 'HTTP ' . (int) $site['http_code'] : '—' ?></b>réponse</div>
                    <div><b><?= stt_h(stt_ms($site['latency_ms'])) ?></b>latence</div>
                    <div><b><?= (int) $site['crashes_24h'] ?> / <?= (int) $site['errors_24h'] ?></b>crash / erreurs</div>
                    <div><b><?= stt_h(stt_flux((int) $site['flux_24h'])) ?></b>flux 24 h</div>
                </div>
                <div class="uptime-footer">
                    <span>90 jours</span>
                    <span class="uptime-pct"><?= stt_h(stt_pct((float) $site['uptime']['pct'])) ?> uptime</span>
                    <span><?= stt_h(stt_ago($site['checked_at'])) ?></span>
                </div>
            </article>
            <?php endforeach; ?>
        </section>
    </div>
</main>
</div>
<?php stt_footer(); ?>
