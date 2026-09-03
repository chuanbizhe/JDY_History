<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (PHP_SAPI !== 'cli' && !hash_equals((string)getenv('HISTORY_CRON_TOKEN'), (string)($_GET['token'] ?? ''))) { http_response_code(403); exit('Forbidden'); }
$db = pdo($config); $plugins = $db->query("SELECT * FROM plugins WHERE pull_enabled=1 AND api_key_cipher IS NOT NULL AND app_id<>'' AND entry_id<>''")->fetchAll(); $count = 0;
foreach ($plugins as $plugin) {
    try {
        $payload = ['app_id' => $plugin['app_id'], 'entry_id' => $plugin['entry_id'], 'limit' => 100]; if ($plugin['poll_cursor']) $payload['data_id'] = $plugin['poll_cursor'];
        $result = jdyRequest($plugin, $config, 'data/list', $payload); $rows = $result['data'] ?? [];
        foreach ($rows as $row) { saveIncoming($db, $plugin, $row, 'poll'); $plugin['poll_cursor'] = $row['_id'] ?? $plugin['poll_cursor']; $count++; }
        $db->prepare('UPDATE plugins SET poll_cursor=?,updated_at=NOW() WHERE id=?')->execute([$plugin['poll_cursor'], $plugin['id']]);
    } catch (Throwable $e) { error_log('poll plugin ' . $plugin['id'] . ': ' . $e->getMessage()); }
}
echo 'Saved ' . $count . " records\n";
