<?php

declare(strict_types=1);

function stt_render_bar(array $days, string $aria, string $extraClass = ''): string
{
    $html = '<div class="uptime-bar ' . $extraClass . '" role="img" aria-label="' . stt_h($aria) . '">';
    foreach ($days as $class) {
        $html .= '<span class="uptime-day ' . stt_h((string) $class) . '"></span>';
    }
    return $html . '</div>';
}

function stt_spark_svg(array $values, int $w = 220, int $h = 56): string
{
    $nums = array_values(array_filter($values, static fn($v) => $v !== null));
    if ($nums === []) {
        return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true"></svg>';
    }
    $min = min($nums);
    $max = max($nums);
    $span = max(1, $max - $min);
    $n = count($values);
    $pts = [];
    foreach ($values as $i => $v) {
        if ($v === null) {
            continue;
        }
        $x = $n === 1 ? $w / 2 : ($i / ($n - 1)) * $w;
        $y = $h - 4 - ((($v - $min) / $span) * ($h - 8));
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }
    if ($pts === []) {
        return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true"></svg>';
    }
    $line = implode(' ', $pts);
    $first = explode(',', $pts[0]);
    $last = explode(',', $pts[count($pts) - 1]);
    $area = $first[0] . ',' . $h . ' ' . $line . ' ' . $last[0] . ',' . $h;
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<polygon class="spark-area" points="' . $area . '"></polygon>'
        . '<polyline class="spark-line" points="' . $line . '"></polyline>'
        . '</svg>';
}

function stt_head(string $title, string $page, array $bodyData = []): void
{
    $full = $title . ' — TTRD · STT';
    $data = '';
    foreach ($bodyData as $k => $v) {
        $data .= ' data-' . stt_h((string) $k) . '="' . stt_h((string) $v) . '"';
    }
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= stt_h($full) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Space+Grotesk:ital,wght@0,700;1,700;1,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body data-page="<?= stt_h($page) ?>"<?= $data ?>>
<div class="noise"></div>
<div class="gridlines"></div>
<div class="glow"></div>
    <?php
}

function stt_topbar(string $active = 'home'): void
{
    ?>
<header class="topbar">
    <a class="brand" href="/">
        <span class="brand-mark">T</span>
        <span class="brand-text">TTRD <span>· STT</span></span>
    </a>
    <nav class="nav">
        <a class="top-link<?= $active === 'home' ? ' is-active' : '' ?>" href="/">Flotte</a>
        <a class="top-link<?= $active === 'stats' ? ' is-active' : '' ?>" href="/stats">Statistiques</a>
        <a class="top-link<?= $active === 'admin' ? ' is-active' : '' ?>" href="/admin">Migration</a>
        <a class="top-link" href="https://ttrd.fr" target="_blank" rel="noopener">Site principal</a>
    </nav>
</header>
    <?php
}

function stt_footer(): void
{
    ?>
<footer class="footer">
    <span>TTRD · STT · noindex</span>
    <span>Sondes HTTP · crash · erreur · latence · flux</span>
</footer>
<script src="/assets/app.js"></script>
</body>
</html>
    <?php
}

function stt_diag_box(array $diag, array $bar, string $status, string $title = 'Diagnostic · flotte'): void
{
    $rows = [
        'crash' => 'Crash',
        'erreur' => 'Erreurs',
        'latence' => 'Latence',
        'flux' => 'Flux',
    ];
    ?>
    <section class="health-bars" aria-label="<?= stt_h($title) ?>">
        <div class="health-bars-head">
            <span class="health-bars-title"><?= stt_h($title) ?></span>
            <span class="health-bars-status status-<?= stt_h($status) ?>"><?= stt_h(stt_class_short($status)) ?></span>
        </div>
        <?php foreach ($rows as $key => $label): ?>
        <div class="diag-row">
            <span class="diag-label"><?= stt_h($label) ?></span>
            <?= stt_render_bar($bar, $label . ' — 90 jours', 'uptime-bar--diag') ?>
            <span class="diag-pct"><?= stt_h((string) round((float) ($diag[$key] ?? 0))) ?>%</span>
        </div>
        <?php endforeach; ?>
    </section>
    <?php
}
