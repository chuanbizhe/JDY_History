<?php
declare(strict_types=1);
session_start();
$config = require __DIR__ . '/config.php';

function pdo(array $config, bool $withoutDb = false): PDO {
    $dsn = 'mysql:host=' . $config['db_host'] . ';port=' . $config['db_port'] . ';charset=utf8mb4' . ($withoutDb ? '' : ';dbname=' . $config['db_name']);
    return new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function ensureRuntimeSchema(PDO $db): void {
    static $done = false;
    if ($done) return;
    try { $db->exec('ALTER TABLE plugins ADD COLUMN display_config JSON NULL AFTER poll_cursor'); } catch (Throwable $ignored) {}
    try { $db->exec('ALTER TABLE plugins ADD COLUMN field_map JSON NULL AFTER display_config'); } catch (Throwable $ignored) {}
    try { $db->exec('ALTER TABLE plugins ADD COLUMN mcp_url TEXT NULL AFTER field_map'); } catch (Throwable $ignored) {}
    $done = true;
}
function csrf(): string { $_SESSION['csrf'] ??= bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function verifyCsrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) throw new RuntimeException('请求校验失败，请刷新页面重试。'); }
function rollbackToken(array $config, string $receiverToken, string $serialNo, int $version): string {
    return hash_hmac('sha256', $receiverToken . '|' . $serialNo . '|' . $version, (string)$config['app_key']);
}
function verifyRollbackToken(array $config, string $receiverToken, string $serialNo, int $version, string $token): void {
    if (!hash_equals(rollbackToken($config, $receiverToken, $serialNo, $version), $token)) {
        throw new RuntimeException('请求校验失败，请返回页面后重试。');
    }
}
function user(): ?array { return $_SESSION['user'] ?? null; }
function requireUser(): array { if (!user()) { header('Location: index.php'); exit; } return user(); }
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function flash(string $type, string $message): void { $_SESSION['flash'] = [$type, $message]; }
function takeFlash(): ?array { $v = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $v; }
function isListArray(array $value): bool { return $value === [] || array_keys($value) === range(0, count($value) - 1); }
function currentBaseUrl(array $config): string {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return rtrim((string)($config['base_url'] ?? ''), '/');
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto === '') {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }
    $proto = explode(',', $proto)[0] === 'https' ? 'https' : 'http';
    $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $path = $scriptDir === '/' || $scriptDir === '.' ? '' : rtrim($scriptDir, '/');
    return $proto . '://' . $host . $path;
}
function encryptSecret(string $plain, array $config): string {
    $key = hash('sha256', $config['app_key'], true); $iv = random_bytes(16);
    return base64_encode($iv . openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
}
function decryptSecret(?string $cipher, array $config): string {
    if (!$cipher) return ''; $raw = base64_decode($cipher, true); if ($raw === false || strlen($raw) < 17) return '';
    $key = hash('sha256', $config['app_key'], true); return openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16)) ?: '';
}
function jdyRequest(array $plugin, array $config, string $path, array $payload): array {
    $key = decryptSecret($plugin['api_key_cipher'], $config); if ($key === '') throw new RuntimeException('该插件尚未配置简道云 API Key。');
    $ch = curl_init('https://api.jiandaoyun.com/api/v5/app/entry/' . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']]);
    $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
    $body = json_decode($raw ?: '', true); if ($raw === false || $status < 200 || $status >= 300 || !is_array($body)) throw new RuntimeException('简道云请求失败：' . ($error ?: 'HTTP ' . $status));
    return $body;
}
function jdyTopLevelSystemFields(): array {
    return array_values(array_unique(array_merge(
        ['_id', 'id', 'appId', 'entryId', 'formName', 'creator', 'updater', 'deleter', 'createTime', 'updateTime', 'deleteTime', 'flowState', 'serial_no', 'serialNo', 'source'],
        platformInternalFields()
    )));
}
function platformInternalFields(): array {
    return ['_widget_17841879265671'];
}
function isPlatformInternalField(string $field): bool {
    return in_array($field, platformInternalFields(), true);
}
function stripPlatformInternalFields(array $data): array {
    foreach (platformInternalFields() as $field) unset($data[$field]);
    return $data;
}
function jdyWritableValue(mixed $value): mixed {
    if (is_array($value)) {
        if (isListArray($value)) {
            $items = [];
            foreach ($value as $item) {
                $items[] = jdyWritableValue($item);
            }
            return $items;
        }
        // 简道云 v2+：成员字段使用 username 为主键，部门字段使用 dept_no 为主键。
        // API 写入值应为 username/dept_no 本身；多选则是它们的数组。
        if (isset($value['username']) && is_scalar($value['username'])) return $value['username'];
        if (isset($value['dept_no']) && is_scalar($value['dept_no'])) return $value['dept_no'];
    }
    return $value;
}
function wrapJdyFieldValue(mixed $value): array {
    return ['value' => jdyWritableValue($value)];
}
function isJdySubtableField(array $plugin, string $field, mixed $value): bool {
    $meta = fieldMeta($plugin, $field);
    $type = strtolower((string)($meta['type'] ?? ''));
    if ($type !== '' && (str_contains($type, 'sub') || str_contains($type, 'table') || str_contains($type, '子表'))) return true;
    if (!is_array($value) || !isListArray($value)) return false;
    foreach ($value as $row) {
        if (!is_array($row)) continue;
        foreach (array_keys($row) as $childField) {
            if (str_starts_with((string)$childField, '_widget_')) return true;
        }
    }
    return false;
}
function writableJdyData(array $plugin, array $payload): array {
    $data = [];
    foreach ($payload as $field => $value) {
        if (in_array((string)$field, jdyTopLevelSystemFields(), true)) continue;
        if (isJdySubtableField($plugin, (string)$field, $value)) {
            $rows = [];
            foreach ((is_array($value) ? $value : []) as $row) {
                if (!is_array($row)) continue;
                $writeRow = [];
                foreach ($row as $childField => $childValue) {
                    $writeRow[(string)$childField] = wrapJdyFieldValue($childValue);
                }
                $rows[] = $writeRow;
            }
            $data[(string)$field] = ['value' => $rows];
            continue;
        }
        $data[(string)$field] = wrapJdyFieldValue($value);
    }
    return $data;
}
function rollbackJdyData(array $plugin, array $config, array $payload, string $dataId): array {
    if (empty($plugin['fillback_enabled'])) throw new RuntimeException('该插件未开启回滚功能，请先在字段显示配置页面开启。');
    if (empty($plugin['app_id']) || empty($plugin['entry_id'])) throw new RuntimeException('缺少 appId / entryId，请先让简道云推送一条数据。');
    if ($dataId === '') throw new RuntimeException('无法定位简道云数据 ID，不能回滚。');
    $data = writableJdyData($plugin, $payload);
    if (!$data) throw new RuntimeException('选中版本没有可回写的业务字段。');
    $result = jdyRequest($plugin, $config, 'data/update', [
        'app_id' => $plugin['app_id'],
        'entry_id' => $plugin['entry_id'],
        'data_id' => $dataId,
        'data' => $data,
        'is_start_trigger' => false,
    ]);
    if (empty($result['data']) || !is_array($result['data'])) {
        error_log('Jiandaoyun rollback business failure: ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        throw new RuntimeException('简道云未确认回滚成功，已停止新增本地版本。');
    }
    return $result;
}
function widgetId(array $widget): string {
    foreach (['widgetName', 'name', '_id', 'id'] as $key) {
        if (!empty($widget[$key]) && is_scalar($widget[$key])) return (string)$widget[$key];
    }
    return '';
}
function widgetLabel(array $widget): string {
    foreach (['label', 'title', 'text', 'widgetTitle', 'displayName', 'widgetLabel'] as $key) {
        if (!empty($widget[$key]) && is_scalar($widget[$key])) return (string)$widget[$key];
    }
    return widgetId($widget);
}
function collectWidgetMap(mixed $node, array &$map): void {
    if (!is_array($node)) return;
    $id = widgetId($node);
    if ($id !== '') {
        $label = widgetLabel($node);
        $map[$id] = [
            'label' => $label !== '' ? $label : $id,
            'type' => (string)($node['type'] ?? ''),
        ];
    }
    foreach ($node as $value) {
        if (is_array($value)) collectWidgetMap($value, $map);
    }
}
function parseMcpResponse(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];
    $json = json_decode($raw, true);
    if (is_array($json)) return $json;
    $events = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $line = trim($line);
        if (!str_starts_with($line, 'data:')) continue;
        $data = trim(substr($line, 5));
        if ($data === '' || $data === '[DONE]') continue;
        $decoded = json_decode($data, true);
        if (is_array($decoded)) $events[] = $decoded;
    }
    return $events[0] ?? [];
}
function mcpJsonRpc(string $url, string $method, array $params = [], ?string &$sessionId = null): array {
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
    ];
    if ($sessionId) $headers[] = 'Mcp-Session-Id: ' . $sessionId;
    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => json_encode([
            'jsonrpc' => '2.0',
            'id' => bin2hex(random_bytes(6)),
            'method' => $method,
            'params' => (object)$params,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$responseHeaders): int {
            $length = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            return $length;
        },
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (isset($responseHeaders['mcp-session-id'])) $sessionId = $responseHeaders['mcp-session-id'];
    if ($raw === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('MCP 请求失败：' . ($error ?: 'HTTP ' . $status));
    }
    $body = parseMcpResponse((string)$raw);
    if (!is_array($body)) throw new RuntimeException('MCP 返回内容无法解析。');
    if (isset($body['error'])) {
        $message = is_array($body['error']) ? ($body['error']['message'] ?? json_encode($body['error'], JSON_UNESCAPED_UNICODE)) : (string)$body['error'];
        throw new RuntimeException('MCP 错误：' . $message);
    }
    return $body['result'] ?? $body;
}
function mcpInitialize(string $url, ?string &$sessionId = null): void {
    try {
        mcpJsonRpc($url, 'initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => (object)[],
            'clientInfo' => ['name' => 'dingdanduo-history-server', 'version' => '1.0.0'],
        ], $sessionId);
        try { mcpJsonRpc($url, 'notifications/initialized', [], $sessionId); } catch (Throwable $ignored) {}
    } catch (Throwable $e) {
        // 有些 MCP 网关允许直接 tools/list；初始化失败时继续尝试后续请求。
        error_log('mcp initialize skipped: ' . $e->getMessage());
    }
}
function mcpContentToData(array $result): mixed {
    if (isset($result['content']) && is_array($result['content'])) {
        $parts = [];
        foreach ($result['content'] as $content) {
            if (!is_array($content)) continue;
            if (isset($content['json'])) return $content['json'];
            if (isset($content['text']) && is_scalar($content['text'])) {
                $text = trim((string)$content['text']);
                $decoded = json_decode($text, true);
                $parts[] = is_array($decoded) ? $decoded : $text;
            }
        }
        return count($parts) === 1 ? $parts[0] : $parts;
    }
    return $result;
}
function mcpToolScore(array $tool): int {
    $name = strtolower((string)($tool['name'] ?? ''));
    $desc = strtolower((string)($tool['description'] ?? ''));
    $text = $name . ' ' . $desc;
    $score = 0;
    foreach (['widget', 'field', 'schema', 'form', 'entry'] as $word) {
        if (str_contains($text, $word)) $score += 3;
    }
    foreach (['字段', '表单', '控件', '结构'] as $word) {
        if (str_contains($text, $word)) $score += 4;
    }
    if (str_contains($text, 'list')) $score++;
    return $score;
}
function fetchWidgetMapByMcp(array $plugin, string $mcpUrl): array {
    $mcpUrl = trim($mcpUrl);
    if ($mcpUrl === '') return [];
    if (empty($plugin['app_id']) || empty($plugin['entry_id'])) {
        throw new RuntimeException('还没有 appId / entryId，请先让简道云推送一条数据。');
    }
    $sessionId = null;
    mcpInitialize($mcpUrl, $sessionId);
    $list = mcpJsonRpc($mcpUrl, 'tools/list', [], $sessionId);
    $tools = is_array($list['tools'] ?? null) ? $list['tools'] : [];
    if (!$tools) throw new RuntimeException('MCP 未返回可用工具列表。');
    usort($tools, fn($a, $b) => mcpToolScore((array)$b) <=> mcpToolScore((array)$a));
    $errors = [];
    foreach ($tools as $tool) {
        $name = (string)($tool['name'] ?? '');
        if ($name === '' || mcpToolScore((array)$tool) <= 0) continue;
        foreach ([
            ['app_id' => $plugin['app_id'], 'entry_id' => $plugin['entry_id']],
            ['appId' => $plugin['app_id'], 'entryId' => $plugin['entry_id']],
            ['app' => $plugin['app_id'], 'entry' => $plugin['entry_id']],
        ] as $arguments) {
            try {
                $result = mcpJsonRpc($mcpUrl, 'tools/call', ['name' => $name, 'arguments' => $arguments], $sessionId);
                $data = mcpContentToData($result);
                $map = [];
                collectWidgetMap($data, $map);
                if ($map) return $map;
            } catch (Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }
    }
    throw new RuntimeException('MCP 已连接，但未能解析到表单字段。' . ($errors ? ' 详情：' . implode('；', array_slice($errors, 0, 3)) : ''));
}
function fetchWidgetMap(array $plugin, array $config): array {
    if (!empty($plugin['mcp_url'])) return fetchWidgetMapByMcp($plugin, (string)$plugin['mcp_url']);
    if (empty($plugin['app_id']) || empty($plugin['entry_id']) || empty($plugin['api_key_cipher'])) return [];
    $result = jdyRequest($plugin, $config, 'widget/list', ['app_id' => $plugin['app_id'], 'entry_id' => $plugin['entry_id']]);
    $map = [];
    collectWidgetMap($result['widgets'] ?? $result, $map);
    return $map;
}
function refreshWidgetMap(PDO $db, array &$plugin, array $config): void {
    $map = fetchWidgetMap($plugin, $config);
    if (!$map) throw new RuntimeException('表单结构读取成功，但未解析到字段。');
    $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $db->prepare('UPDATE plugins SET field_map=?,updated_at=NOW() WHERE id=?')->execute([$json, $plugin['id']]);
    $plugin['field_map'] = $json;
}
function tryRefreshWidgetMap(PDO $db, array &$plugin, array $config): void {
    try { refreshWidgetMap($db, $plugin, $config); }
    catch (Throwable $e) { error_log('widget map refresh failed: ' . $e->getMessage()); }
}
function fieldMeta(array $plugin, string $field): array {
    $map = json_decode((string)($plugin['field_map'] ?? ''), true);
    $meta = is_array($map) ? ($map[$field] ?? null) : null;
    return is_array($meta) ? $meta : ['label' => $field, 'type' => ''];
}
function systemFieldLabels(): array {
    return [
        '_id' => '数据ID',
        'id' => '数据ID',
        'appId' => '应用ID',
        'entryId' => '表单ID',
        'formName' => '表单名称',
        'creator' => '创建人',
        'updater' => '更新人',
        'deleter' => '删除人',
        'createTime' => '创建时间',
        'updateTime' => '更新时间',
        'deleteTime' => '删除时间',
        'flowState' => '流程状态',
        'serial_no' => '流水号',
        'serialNo' => '流水号',
    ];
}
function fieldLabel(array $plugin, string $field): string {
    if (isPlatformInternalField($field)) return '平台 Token';
    // 人工展示名保存在 display_config，不会被重新读取简道云表单结构时覆盖。
    $display = json_decode((string)($plugin['display_config'] ?? ''), true);
    $customLabel = is_array($display) && is_array($display['labels'] ?? null) ? trim((string)($display['labels'][$field] ?? '')) : '';
    if ($customLabel !== '') return $customLabel;
    $system = systemFieldLabels();
    if (isset($system[$field])) return $system[$field];
    $meta = fieldMeta($plugin, $field);
    return (string)($meta['label'] ?? $field);
}
function fieldTitle(array $plugin, string $field): string {
    $label = fieldLabel($plugin, $field);
    return $label === $field ? $field : $label . '（' . $field . '）';
}
function detectSerialField(array $plugin, array $config): string {
    $result = jdyRequest($plugin, $config, 'widget/list', ['app_id' => $plugin['app_id'], 'entry_id' => $plugin['entry_id']]);
    foreach (($result['widgets'] ?? []) as $widget) {
        if (($widget['type'] ?? '') === 'sn') return (string)($widget['widgetName'] ?? $widget['name']);
    }
    throw new RuntimeException('未识别到简道云“流水号”控件，请手动填写字段 ID。');
}
function normalizeValue(mixed $value): mixed {
    if (is_array($value)) {
        foreach (['value', 'name', 'id', '_id'] as $key) {
            if (array_key_exists($key, $value) && !is_array($value[$key])) return $value[$key];
        }
    }
    return $value;
}
function valueText(mixed $value): string {
    $value = normalizeValue($value);
    if ($value === null || $value === '') return '';
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_scalar($value)) return trim((string)$value);
    return trim(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
}
function flowStateText(mixed $value): string {
    $normalized = normalizeValue($value);
    if ($normalized === null || $normalized === '') return '';
    $key = is_scalar($normalized) ? trim((string)$normalized) : '';
    $map = [
        '0' => '流程进行中',
        '1' => '流程已完成',
        '2' => '流程手动结束',
    ];
    return $map[$key] ?? valueText($value);
}
function fieldValueText(string $field, mixed $value): string {
    if ($field === 'flowState') return flowStateText($value);
    return valueText($value);
}
function looksLikeSerialField(string $field, mixed $value): bool {
    $text = valueText($value);
    if ($text === '' || mb_strlen($text) > 80) return false;
    $lower = strtolower($field);
    return str_contains($lower, 'serial') || str_contains($lower, 'sn') || str_contains($field, '流水') || str_contains($field, '编号') || str_contains($field, '单号');
}
function detectSerialFieldFromSample(array $data, string $sampleSerial): string {
    $sampleSerial = trim($sampleSerial);
    if ($sampleSerial === '') throw new RuntimeException('流水号样本不能为空。');
    $ignore = ['_id', 'id', 'appId', 'entryId', 'formName', 'creator', 'updater', 'createTime', 'updateTime', 'deleteTime', 'flowState'];
    foreach ($data as $field => $value) {
        $field = (string)$field;
        if (in_array($field, $ignore, true) || (is_array($value) && isListArray($value))) continue;
        if (valueText($value) === $sampleSerial) return $field;
    }
    throw new RuntimeException('已收到数据，但没有找到值为 ' . $sampleSerial . ' 的字段，无法自动确定流水号字段 ID。');
}
function autoSerialValue(array $data): string {
    foreach (['serial_no', 'serialNo', 'serial', 'sn', '流水号', '编号', '单号'] as $field) {
        if (array_key_exists($field, $data)) {
            $text = valueText($data[$field]);
            if ($text !== '') return $text;
        }
    }
    foreach ($data as $field => $value) {
        if (looksLikeSerialField((string)$field, $value)) return valueText($value);
    }
    return valueText($data['_id'] ?? $data['id'] ?? '');
}
function serialValue(array $data, string $field): string {
    if ($field === 'auto') return autoSerialValue($data);
    return valueText($data[$field] ?? '');
}
function parseIncomingPayload(): array {
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (is_array($body)) return $body;
    foreach (['data', 'payload', 'body'] as $key) {
        if (isset($_POST[$key])) {
            $decoded = json_decode((string)$_POST[$key], true);
            if (is_array($decoded)) return $decoded;
        }
    }
    if (!empty($_POST)) return $_POST;
    parse_str($raw, $parsed);
    foreach (['data', 'payload', 'body'] as $key) {
        if (isset($parsed[$key])) {
            $decoded = json_decode((string)$parsed[$key], true);
            if (is_array($decoded)) return $decoded;
        }
    }
    if (!empty($parsed)) return $parsed;
    throw new RuntimeException('请求体必须是 JSON，或包含 data/payload 的表单 POST。');
}

/**
 * 修复早期「同一插件 + 同一流水号」被拆成多条 records 的数据。
 *
 * 旧逻辑以 external_id 为主键，而无 _id 的推送会使用 payload 哈希作为 external_id；
 * 数据内容一变，哈希也变，因而同一流水号会出现多条 records，版本也被拆散。
 * 此函数保留最近一条主记录，合并缺失字段，并按原创建时间重建连续版本号。
 */
function mergeLegacyRecordsForSerial(PDO $db, int $pluginId, string $serial): ?array {
    if ($serial === '') return null;
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT * FROM records WHERE plugin_id=? AND serial_no=? ORDER BY updated_at ASC, id ASC FOR UPDATE');
        $q->execute([$pluginId, $serial]);
        $records = $q->fetchAll();
        if (!$records) {
            if ($ownTransaction) $db->commit();
            return null;
        }
        if (count($records) === 1) {
            if ($ownTransaction) $db->commit();
            return $records[0];
        }

        // 最后更新时间的记录就是当前内容；同时用较早记录补齐不完整推送漏掉的字段。
        $keeper = $records[count($records) - 1];
        $mergedPayload = [];
        foreach ($records as $legacyRecord) {
            $legacyPayload = json_decode((string)$legacyRecord['payload'], true);
            if (is_array($legacyPayload)) $mergedPayload = array_replace($mergedPayload, stripPlatformInternalFields($legacyPayload));
        }
        $payloadJson = json_encode($mergedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) throw new RuntimeException('旧记录内容无法编码为 JSON。');

        $recordIds = array_map(static fn(array $row): int => (int)$row['id'], $records);
        $marks = implode(',', array_fill(0, count($recordIds), '?'));
        $q = $db->prepare('SELECT source, payload, created_at FROM record_versions WHERE record_id IN (' . $marks . ') ORDER BY created_at ASC, id ASC');
        $q->execute($recordIds);
        $versions = $q->fetchAll();

        // 先删后按时间重建，避免唯一键 (record_id, version_no) 冲突。
        $q = $db->prepare('DELETE FROM record_versions WHERE record_id IN (' . $marks . ')');
        $q->execute($recordIds);
        $q = $db->prepare('INSERT INTO record_versions(record_id, version_no, source, payload, created_at) VALUES(?,?,?,?,?)');
        foreach ($versions as $index => $legacyVersion) {
            $q->execute([(int)$keeper['id'], $index + 1, $legacyVersion['source'], $legacyVersion['payload'], $legacyVersion['created_at']]);
        }

        $q = $db->prepare('UPDATE records SET serial_no=?, payload=?, updated_at=? WHERE id=?');
        $q->execute([$serial, $payloadJson, $keeper['updated_at'], $keeper['id']]);
        $duplicateIds = array_values(array_filter($recordIds, static fn(int $id): bool => $id !== (int)$keeper['id']));
        $marks = implode(',', array_fill(0, count($duplicateIds), '?'));
        $q = $db->prepare('DELETE FROM records WHERE id IN (' . $marks . ')');
        $q->execute($duplicateIds);

        if ($ownTransaction) $db->commit();
        $keeper['payload'] = $payloadJson;
        return $keeper;
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function saveIncoming(PDO $db, array $plugin, array $data, string $source): array {
    $data = stripPlatformInternalFields($data);
    $db->beginTransaction();
    try {
        // 优先使用简道云 _id；但一些推送/测试数据没有 _id。此时必须以流水号复用
        // 已有主记录，不能用 payload 哈希当 ID，否则每次内容变化都会生成另一条 records。
        $externalIdFromData = trim((string)($data['_id'] ?? $data['id'] ?? ''));
        $serial = serialValue($data, (string)$plugin['serial_field']);
        if ($serial === '') $serial = $externalIdFromData;

        // 新数据到达时顺带修复此前已拆开的同流水号历史记录。
        if ($serial !== '') mergeLegacyRecordsForSerial($db, (int)$plugin['id'], $serial);

        $existing = false;
        if ($externalIdFromData !== '') {
            $q = $db->prepare('SELECT id, external_id, payload FROM records WHERE plugin_id=? AND external_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$plugin['id'], $externalIdFromData]);
            $existing = $q->fetch();
        }
        // 兼容旧数据和没有 _id 的推送：同一插件 + 同一流水号只维护一条当前记录。
        if (!$existing && $serial !== '') {
            $q = $db->prepare('SELECT id, external_id, payload FROM records WHERE plugin_id=? AND serial_no=? ORDER BY updated_at DESC, id DESC LIMIT 1 FOR UPDATE');
            $q->execute([$plugin['id'], $serial]);
            $existing = $q->fetch();
        }

        $existingPayload = $existing ? json_decode((string)$existing['payload'], true) : null;
        if (is_array($existingPayload)) {
            // 简道云推送/接口返回偶尔不是全量。缺失字段沿用当前本地完整数据；
            // 明确传入 null / 空数组 / 空字符串 的字段仍会覆盖旧值。
            $data = array_replace(stripPlatformInternalFields($existingPayload), $data);
        }
        $data = stripPlatformInternalFields($data);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('数据无法编码为 JSON。');

        if ($existing) {
            // 有新的真实 _id 时顺便修正过去由流水号兜底建立的 external_id。
            $externalId = $externalIdFromData !== '' ? $externalIdFromData : (string)$existing['external_id'];
            $q = $db->prepare('UPDATE records SET external_id=?, serial_no=?, payload=?, updated_at=NOW() WHERE id=?');
            $q->execute([$externalId, $serial, $json, $existing['id']]);
            $recordId = (int)$existing['id'];
        } else {
            $externalId = $externalIdFromData !== '' ? $externalIdFromData : hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($serial === '') $serial = $externalId;
            $q = $db->prepare('INSERT INTO records(plugin_id, external_id, serial_no, payload, updated_at) VALUES(?,?,?,?,NOW())');
            $q->execute([$plugin['id'], $externalId, $serial, $json]);
            $recordId = (int)$db->lastInsertId();
        }
        $version = (int)$db->query('SELECT COALESCE(MAX(version_no),0)+1 FROM record_versions WHERE record_id=' . $recordId)->fetchColumn();
        $q = $db->prepare('INSERT INTO record_versions(record_id, version_no, source, payload, created_at) VALUES(?,?,?,?,NOW())'); $q->execute([$recordId, $version, $source, $json]);
        $db->commit(); return ['record_id' => $recordId, 'version' => $version, 'serial_no' => $serial];
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}
