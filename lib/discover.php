<?php

declare(strict_types=1);

function stt_sync_watch(array $watch): array
{
    foreach ($watch as $host => $meta) {
        stt_upsert_site($host, $meta['label'], 'config', $meta['group']);
    }
    $kept = [];
    foreach (stt_read_json('sites.json', []) as $site) {
        $host = (string) ($site['host'] ?? '');
        if (isset($watch[$host])) {
            $kept[] = $site;
        }
    }
    stt_write_json('sites.json', $kept);
    return array_keys($watch);
}

function stt_discover(bool $force = false): array
{
    $watch = stt_watch_map();
    $hosts = stt_sync_watch($watch);
    stt_meta_set('discover_at', (string) time());
    return $hosts;
}
