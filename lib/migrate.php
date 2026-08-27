<?php

declare(strict_types=1);

function stt_mig_config(): array
{
    return stt_config('migration', []);
}

function stt_mig_flagship(): array
{
    return stt_mig_config()['flagship'] ?? ['ttrd.fr', 'athena.ttrd.fr', 'la-popotte.ttrd.fr'];
}

function stt_mig_paths(string $host): array
{
    $cfg = stt_mig_config();
    $domains = rtrim((string) ($cfg['domains'] ?? '/home/u416380327/domains'), '/');
    $host = stt_canonical_host($host);
    $folder = $domains . '/' . $host;
    $paths = [
        'ftp_root' => $domains,
        'domain' => $folder,
        'public' => $folder . '/public_html',
    ];
    if ($host !== 'ttrd.fr') {
        $paths['nested'] = $domains . '/ttrd.fr/' . $host;
        $paths['nested_public'] = $domains . '/ttrd.fr/public_html/' . explode('.', $host)[0];
    } else {
        $paths['public'] = (string) ($cfg['apex_public'] ?? $paths['public']);
    }
    return $paths;
}

function stt_mig_pipeline_def(): array
{
    return [
        ['id' => 'inventory', 'label' => 'Inventaire des sous-domaines', 'hint' => 'Recenser les services réellement rattachés à ttrd.fr.'],
        ['id' => 'copy', 'label' => 'Copie des fichiers et bases', 'hint' => 'FTP du dossier domains + export SQL. Pas seulement public_html.'],
        ['id' => 'install', 'label' => 'Installation des services', 'hint' => 'PHP / Node, vhosts, cron, .env, dépendances API sur le VPS.'],
        ['id' => 'test', 'label' => 'Tests sur 72.62.22.55', 'hint' => 'Valider chaque hôte via l’IP VPS, sans toucher au DNS.'],
        ['id' => 'dns', 'label' => 'Basculement DNS', 'hint' => 'Pointer vers 72.62.22.55 seulement après tests verts. Ancien hébergement conservé.'],
        ['id' => 'validate', 'label' => 'Validation finale', 'hint' => 'HTTPS, cron, API, mails à part. Recréer les certificats SSL.'],
    ];
}

function stt_mig_blank_host(string $host, string $label): array
{
    $flagship = in_array($host, stt_mig_flagship(), true);
    return [
        'host' => $host,
        'label' => $label,
        'flagship' => $flagship,
        'attached' => $flagship ? true : null,
        'stack' => '',
        'php' => '',
        'node' => '',
        'db_name' => '',
        'has_api' => false,
        'files' => false,
        'hidden' => false,
        'db_export' => false,
        'db_import' => false,
        'cron' => false,
        'env' => false,
        'deps' => false,
        'vps' => false,
        'notes' => '',
        'vps_probe' => null,
    ];
}

function stt_mig_load(): array
{
    $cfg = stt_mig_config();
    $stored = stt_read_json('migration.json', []);
    if (!is_array($stored)) {
        $stored = [];
    }

    $pipeline = [];
    $savedPipe = [];
    foreach ($stored['pipeline'] ?? [] as $row) {
        if (isset($row['id'])) {
            $savedPipe[$row['id']] = $row;
        }
    }
    foreach (stt_mig_pipeline_def() as $step) {
        $step['done'] = !empty($savedPipe[$step['id']]['done']);
        $pipeline[] = $step;
    }

    stt_discover();
    $hosts = [];
    foreach (stt_sites(false) as $site) {
        if (($site['site_group'] ?? '') !== 'ttrd') {
            continue;
        }
        $host = $site['host'];
        $blank = stt_mig_blank_host($host, $site['label'] ?? stt_site_label($host));
        $prev = $stored['hosts'][$host] ?? [];
        $hosts[$host] = array_merge($blank, is_array($prev) ? $prev : [], [
            'host' => $host,
            'label' => $blank['label'],
            'flagship' => $blank['flagship'],
            'url' => $site['url'] ?? stt_site_url($host),
            'paths' => stt_mig_paths($host),
        ]);
    }

    uasort($hosts, static function ($a, $b) {
        if ($a['flagship'] !== $b['flagship']) {
            return $a['flagship'] ? -1 : 1;
        }
        return strcasecmp((string) $a['label'], (string) $b['label']);
    });

    return [
        'source' => [
            'provider' => $cfg['source'] ?? 'Premium Web Hosting',
            'account' => $cfg['account'] ?? 'u416380327',
            'home' => $cfg['home'] ?? '/home/u416380327',
            'domains' => $cfg['domains'] ?? '/home/u416380327/domains',
            'wordpress' => !empty($cfg['wordpress']),
        ],
        'target' => [
            'vps_id' => $cfg['vps_id'] ?? '1934687',
            'ip' => $cfg['vps_ip'] ?? '72.62.22.55',
            'empty' => true,
        ],
        'dns_locked' => empty($savedPipe['test']['done']),
        'pipeline' => $pipeline,
        'hosts' => $hosts,
        'updated_at' => $stored['updated_at'] ?? null,
    ];
}

function stt_mig_save(array $state): void
{
    $out = [
        'pipeline' => [],
        'hosts' => [],
        'updated_at' => time(),
    ];
    foreach ($state['pipeline'] ?? [] as $step) {
        $out['pipeline'][] = [
            'id' => $step['id'],
            'done' => !empty($step['done']),
        ];
    }
    foreach ($state['hosts'] ?? [] as $host => $row) {
        unset($row['paths'], $row['label'], $row['flagship'], $row['url']);
        $out['hosts'][$host] = $row;
    }
    stt_write_json('migration.json', $out);
}

function stt_mig_host_progress(array $host): array
{
    if ($host['attached'] === false) {
        return ['pct' => 100, 'label' => 'Hors périmètre', 'class' => 'skip'];
    }
    if ($host['attached'] === null) {
        return ['pct' => 0, 'label' => 'À recenser', 'class' => 'wait'];
    }
    $keys = ['files', 'hidden', 'env', 'vps'];
    $needDb = trim((string) ($host['db_name'] ?? '')) !== '' || !empty($host['has_api']);
    if ($needDb) {
        $keys[] = 'db_export';
        $keys[] = 'db_import';
    }
    if (!empty($host['has_api'])) {
        $keys[] = 'deps';
    }
    $keys[] = 'cron';
    $done = 0;
    foreach ($keys as $key) {
        if (!empty($host[$key])) {
            $done++;
        }
    }
    $pct = (int) round(100 * $done / max(1, count($keys)));
    $class = $pct === 100 ? 'operational' : ($pct > 0 ? 'degraded' : 'empty');
    return ['pct' => $pct, 'label' => $pct . ' %', 'class' => $class, 'done' => $done, 'total' => count($keys)];
}

function stt_mig_summary(array $state): array
{
    $total = 0;
    $attached = 0;
    $unknown = 0;
    $ready = 0;
    $vps = 0;
    foreach ($state['hosts'] as $host) {
        $total++;
        if ($host['attached'] === null) {
            $unknown++;
            continue;
        }
        if ($host['attached'] === false) {
            continue;
        }
        $attached++;
        $p = stt_mig_host_progress($host);
        if ($p['pct'] === 100) {
            $ready++;
        }
        if (!empty($host['vps'])) {
            $vps++;
        }
    }
    $pipeDone = count(array_filter($state['pipeline'], static fn($s) => !empty($s['done'])));
    return [
        'hosts' => $total,
        'attached' => $attached,
        'unknown' => $unknown,
        'ready' => $ready,
        'vps_ok' => $vps,
        'pipeline' => $pipeDone,
        'pipeline_total' => count($state['pipeline']),
        'can_dns' => $pipeDone >= 4 && $attached > 0 && $vps >= $attached,
    ];
}

function stt_mig_apply_patch(array $patch): array
{
    $state = stt_mig_load();
    if (isset($patch['pipeline']) && is_array($patch['pipeline'])) {
        $testDone = false;
        foreach ($state['pipeline'] as &$step) {
            if (array_key_exists($step['id'], $patch['pipeline'])) {
                $step['done'] = (bool) $patch['pipeline'][$step['id']];
            }
            if ($step['id'] === 'test') {
                $testDone = !empty($step['done']);
            }
        }
        unset($step);
        foreach ($state['pipeline'] as &$step) {
            if ($step['id'] === 'dns' && !$testDone) {
                $step['done'] = false;
            }
        }
        unset($step);
    }
    if (isset($patch['host'], $patch['fields']) && is_string($patch['host']) && is_array($patch['fields'])) {
        $host = stt_canonical_host($patch['host']);
        if (isset($state['hosts'][$host])) {
            $bools = ['files', 'hidden', 'db_export', 'db_import', 'cron', 'env', 'deps', 'vps', 'has_api'];
            $texts = ['stack', 'php', 'node', 'db_name', 'notes'];
            foreach ($bools as $key) {
                if (array_key_exists($key, $patch['fields'])) {
                    $state['hosts'][$host][$key] = (bool) $patch['fields'][$key];
                }
            }
            foreach ($texts as $key) {
                if (array_key_exists($key, $patch['fields'])) {
                    $state['hosts'][$host][$key] = trim((string) $patch['fields'][$key]);
                }
            }
            if (array_key_exists('attached', $patch['fields'])) {
                $val = $patch['fields']['attached'];
                $state['hosts'][$host]['attached'] = $val === null || $val === '' ? null : (bool) $val;
            }
        }
    }
    stt_mig_save($state);
    return stt_mig_load();
}

function stt_mig_vps_probe(string $host): array
{
    $host = stt_canonical_host($host);
    $ip = (string) (stt_mig_config()['vps_ip'] ?? '72.62.22.55');
    $url = 'http://' . $ip . '/';
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl init', 'ip' => $ip, 'host' => $host];
    }
    $opts = stt_curl_opts();
    $opts[CURLOPT_URL] = $url;
    $opts[CURLOPT_HTTPHEADER] = array_merge($opts[CURLOPT_HTTPHEADER] ?? [], [
        'Host: ' . $host,
    ]);
    $opts[CURLOPT_SSL_VERIFYPEER] = false;
    $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    $opts[CURLOPT_TIMEOUT] = 8;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $result = stt_probe_result($ch, $body);
    curl_close($ch);
    $result['ip'] = $ip;
    $result['host'] = $host;
    $result['via'] = 'Host: ' . $host . ' → ' . $ip;

    $state = stt_mig_load();
    if (isset($state['hosts'][$host])) {
        $state['hosts'][$host]['vps_probe'] = [
            'ts' => time(),
            'class' => $result['status_class'],
            'http_code' => $result['http_code'],
            'latency_ms' => $result['latency_ms'],
            'error' => $result['error'],
        ];
        if (($result['status_class'] ?? '') === 'operational' || (int) ($result['http_code'] ?? 0) >= 200) {
            $okCode = (int) ($result['http_code'] ?? 0);
            if ($okCode > 0 && $okCode < 500) {
                $state['hosts'][$host]['vps'] = true;
            }
        }
        stt_mig_save($state);
    }
    return $result;
}

function stt_mig_runbook(array $state): string
{
    $ip = $state['target']['ip'];
    $acc = $state['source']['account'];
    $domains = $state['source']['domains'];
    $lines = [
        '# Migration ttrd.fr → VPS ' . $state['target']['vps_id'] . ' (' . $ip . ')',
        '',
        'Périmètre : ttrd.fr + sous-domaines rattachés. WordPress : non détecté.',
        'Source : ' . $state['source']['provider'] . ' / ' . $acc,
        'Ne pas basculer le DNS avant tests verts sur ' . $ip . '.',
        '',
        '## 1. FTP — fichiers',
        'Récupérer TOUT le dossier domains, pas seulement public_html :',
        $domains . '/ttrd.fr/',
        $domains . '/<sous-domaine.ttrd.fr>/',
        'Inclure .env, .htaccess, .git si présent, configs API.',
        'Exclure caches, tmp, logs, node_modules (réinstaller), mails, certificats SSL.',
        '',
        '## 2. Bases',
        'Export SQL via phpMyAdmin (ou mysqldump) pour chaque base listée ci-dessous.',
        'Import sur le VPS après création des users MySQL.',
        '',
        '## 3. VPS',
        'Nginx/Caddy + PHP-FPM / Node selon la stack. Recréer SSL (Let’s Encrypt) après DNS.',
        'Cron : recréer crontab, ne pas copier celle de l’hébergeur tel quel.',
        '',
        '## Hôtes rattachés',
    ];
    foreach ($state['hosts'] as $h) {
        if ($h['attached'] !== true) {
            continue;
        }
        $lines[] = '- ' . $h['host'] . ' [' . ($h['stack'] ?: 'stack ?') . ']  FTP ' . $h['paths']['public']
            . ($h['db_name'] ? '  DB ' . $h['db_name'] : '');
    }
    $lines[] = '';
    $lines[] = '## Hors périmètre (non migrés)';
    foreach ($state['hosts'] as $h) {
        if ($h['attached'] === false) {
            $lines[] = '- ' . $h['host'];
        }
    }
    $lines[] = '';
    $lines[] = 'Ordre : inventaire → fichiers+SQL → services → tests ' . $ip . ' → DNS → validation.';
    return implode("\n", $lines);
}
