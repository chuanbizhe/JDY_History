<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $token = $_GET['token'] ?? ''; $db = pdo($config); ensureRuntimeSchema($db); $q = $db->prepare('SELECT * FROM plugins WHERE receiver_token=?'); $q->execute([$token]); $plugin = $q->fetch(); if (!$plugin) throw new RuntimeException('接收链接无效。');
    // GET 仅用于打开对应流水号的版本展示页，真正的数据推送必须使用 POST。
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $serial = trim((string)($_GET['serial_no'] ?? ''));
        if ($serial === '') throw new RuntimeException('请在链接中传入 serial_no。');
        header('Location: history.php?token=' . rawurlencode($token) . '&serial_no=' . rawurlencode($serial));
        exit;
    }
    $body = parseIncomingPayload();
    $data = $body['data'] ?? $body['payload'] ?? $body;
    if (is_string($data)) $data = json_decode($data, true);
    if (!is_array($data)) throw new RuntimeException('未找到 data 对象。');
    // 简道云推送数据自带 appId / entryId：
    // - 首次推送时自动绑定插件对应表单；
    // - 已绑定后不再被其他表单覆盖，避免相同结构表单把插件绑定串掉；
    // - 但不拒收推送，避免热更新后生产数据断流。
    $appId = (string)($data['appId'] ?? $body['appId'] ?? '');
    $entryId = (string)($data['entryId'] ?? $body['entryId'] ?? '');
    if ($appId !== '' && $entryId !== '') {
        $boundAppId = trim((string)($plugin['app_id'] ?? ''));
        $boundEntryId = trim((string)($plugin['entry_id'] ?? ''));
        if ($boundAppId !== '' && $boundEntryId !== '' && ($boundAppId !== $appId || $boundEntryId !== $entryId)) {
            error_log('receive token/form mismatch plugin=' . $plugin['id'] . ' bound=' . $boundAppId . '/' . $boundEntryId . ' incoming=' . $appId . '/' . $entryId);
        } elseif ($boundAppId === '' || $boundEntryId === '') {
            $db->prepare('UPDATE plugins SET app_id=?,entry_id=?,updated_at=NOW() WHERE id=?')->execute([$appId, $entryId, $plugin['id']]);
            $plugin['app_id'] = $appId; $plugin['entry_id'] = $entryId;
            tryRefreshWidgetMap($db, $plugin, $config);
        }
    }
    $querySerial = trim((string)($_GET['serial_no'] ?? $_GET['sn'] ?? $_GET['serial'] ?? ''));
    if ($plugin['serial_field'] === 'auto' && $querySerial !== '') {
        $serialField = detectSerialFieldFromSample($data, $querySerial);
        $db->prepare('UPDATE plugins SET serial_field=?,updated_at=NOW() WHERE id=?')->execute([$serialField, $plugin['id']]);
        $plugin['serial_field'] = $serialField;
    } elseif ($plugin['serial_field'] === 'auto' && $plugin['api_key_cipher'] && $plugin['app_id'] && $plugin['entry_id']) {
        try {
            $serialField = detectSerialField($plugin, $config);
            $db->prepare('UPDATE plugins SET serial_field=?,updated_at=NOW() WHERE id=?')->execute([$serialField, $plugin['id']]);
            $plugin['serial_field'] = $serialField;
        } catch (Throwable $e) { error_log('serial field detect failed: ' . $e->getMessage()); }
    }
    if ($querySerial !== '') {
        $data['serial_no'] = $querySerial;
        if ($plugin['serial_field'] !== 'auto') $data[$plugin['serial_field']] = $querySerial;
    }
    $saved = saveIncoming($db, $plugin, $data, 'push'); echo json_encode(['ok' => true, 'message' => '数据已接收', 'record' => $saved], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) { http_response_code(400); echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
