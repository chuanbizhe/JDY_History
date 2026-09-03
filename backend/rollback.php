<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://your-domain.example');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Accept, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function rollbackFail(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') rollbackFail('仅支持 POST 请求。', 405);

    $token = (string)($_POST['token'] ?? '');
    $serial = trim((string)($_POST['serial_no'] ?? ''));
    $versionNo = filter_input(INPUT_POST, 'version', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || $serial === '' || !$versionNo) rollbackFail('回滚参数无效。');
    verifyRollbackToken($config, $token, $serial, (int)$versionNo, $csrfToken);

    $db = pdo($config);
    ensureRuntimeSchema($db);

    $q = $db->prepare('SELECT * FROM plugins WHERE receiver_token=?');
    $q->execute([$token]);
    $plugin = $q->fetch();
    if (!$plugin) rollbackFail('接收链接无效。', 404);

    mergeLegacyRecordsForSerial($db, (int)$plugin['id'], $serial);
    $q = $db->prepare('SELECT * FROM records WHERE plugin_id=? AND serial_no=? ORDER BY updated_at DESC, id DESC LIMIT 1');
    $q->execute([$plugin['id'], $serial]);
    $record = $q->fetch();
    if (!$record) rollbackFail('尚未收到该流水号的数据。', 404);

    $q = $db->prepare('SELECT * FROM record_versions WHERE record_id=? AND version_no=?');
    $q->execute([$record['id'], (int)$versionNo]);
    $version = $q->fetch();
    if (!$version) rollbackFail('找不到指定版本。', 404);

    $payload = stripPlatformInternalFields(json_decode((string)$version['payload'], true) ?: []);
    $dataId = (string)($payload['_id'] ?? $payload['id'] ?? $record['external_id'] ?? '');
    $rollbackResult = rollbackJdyData($plugin, $config, $payload, $dataId);

    $returnedPayload = is_array($rollbackResult['data'] ?? null) ? $rollbackResult['data'] : $payload;
    $returnedPayload['serial_no'] = $serial;
    if (($plugin['serial_field'] ?? 'auto') !== 'auto' && !array_key_exists((string)$plugin['serial_field'], $returnedPayload)) {
        $returnedPayload[(string)$plugin['serial_field']] = $serial;
    }
    $saved = saveIncoming($db, $plugin, $returnedPayload, 'fillback');

    echo json_encode([
        'ok' => true,
        'version' => $saved['version'],
        'serial_no' => $saved['serial_no'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('rollback failed: ' . $e->getMessage());
    rollbackFail($e->getMessage(), 500);
}
