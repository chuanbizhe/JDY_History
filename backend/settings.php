<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

function previewText(mixed $value, ?string $field = null): string {
    $text = $field === null ? valueText($value) : fieldValueText($field, $value);
    if ($text !== '') return mb_strlen($text) > 120 ? mb_substr($text, 0, 120) . '...' : $text;
    if (is_array($value)) return '空数组 / 空对象';
    return '空值';
}
function fieldType(mixed $value): string {
    if (is_array($value)) {
        $isRows = isListArray($value) && isset($value[0]) && is_array($value[0]);
        return $isRows ? '子表单' : '对象';
    }
    if (is_bool($value)) return '布尔';
    if (is_numeric($value)) return '数字';
    if ($value === null || $value === '') return '空值';
    return '文本';
}
function displayFieldType(string $field, mixed $value): string {
    if (in_array($field, ['creator', 'updater', 'deleter'], true)) return '成员';
    if (in_array($field, ['createTime', 'updateTime', 'deleteTime'], true)) return '时间';
    if (in_array($field, ['_id', 'id', 'appId', 'entryId'], true)) return '标识';
    if ($field === 'flowState') return '流程';
    return fieldType($value);
}
function fieldGroup(string $field, mixed $value): string {
    if (isPlatformInternalField($field)) return '平台内部字段';
    if (in_array($field, ['_id', 'appId', 'entryId', 'creator', 'deleter', 'updater', 'formName', 'flowState', 'createTime', 'deleteTime', 'updateTime', 'serial_no'], true)) return '系统字段';
    if (is_array($value) && isListArray($value) && isset($value[0]) && is_array($value[0])) return '子表字段';
    return '主表字段';
}
function subtableColumns(array $rows): array {
    $columns = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        foreach ($row as $key => $_) {
            if ((string)$key === '_id') continue;
            if (!in_array((string)$key, $columns, true)) $columns[] = (string)$key;
        }
    }
    return $columns;
}
function formatStorageSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB', 'MB', 'GB', 'TB'];
    $size = (float)$bytes;
    foreach ($units as $unit) {
        $size /= 1024;
        if ($size < 1024 || $unit === 'TB') return rtrim(rtrim(number_format($size, $size < 10 ? 1 : 0, '.', ''), '0'), '.') . ' ' . $unit;
    }
    return $bytes . ' B';
}

$account = requireUser();
$db = pdo($config);
ensureRuntimeSchema($db);
$pluginId = (int)($_GET['plugin'] ?? $_POST['plugin'] ?? 0);
$q = $db->prepare('SELECT * FROM plugins WHERE id=? AND user_id=?');
$q->execute([$pluginId, $account['id']]);
$plugin = $q->fetch();
if (!$plugin) exit('插件不存在。');
if (!empty($plugin['app_id']) && !empty($plugin['entry_id']) && !empty($plugin['api_key_cipher']) && empty($plugin['field_map'])) {
    tryRefreshWidgetMap($db, $plugin, $config);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string)($_POST['action'] ?? 'save_fields');
    if ($action === 'refresh_widgets') {
        if (empty($plugin['app_id']) || empty($plugin['entry_id'])) throw new RuntimeException('还没有 appId / entryId，请先让简道云推送一条数据。');
        if (empty($plugin['mcp_url']) && empty($plugin['api_key_cipher'])) throw new RuntimeException('请先配置 MCP URL 或 API Key，才能读取表单结构。');
        refreshWidgetMap($db, $plugin, $config);
        flash('ok', !empty($plugin['mcp_url']) ? '已通过 MCP 读取表单结构，字段名映射已更新。' : '已通过 API 读取表单结构，字段名映射已更新。');
    } elseif ($action === 'save_mcp') {
        $mcpUrl = trim((string)($_POST['mcp_url'] ?? ''));
        if ($mcpUrl !== '' && !preg_match('~^https?://~i', $mcpUrl)) throw new RuntimeException('MCP URL 必须以 http:// 或 https:// 开头。');
        $db->prepare('UPDATE plugins SET mcp_url=?,updated_at=NOW() WHERE id=?')->execute([$mcpUrl !== '' ? $mcpUrl : null, $pluginId]);
        flash('ok', $mcpUrl !== '' ? 'MCP URL 已保存。' : 'MCP URL 已清空。');
    } elseif ($action === 'reset_binding') {
        $db->prepare('UPDATE plugins SET app_id="",entry_id="",field_map=NULL,updated_at=NOW() WHERE id=?')->execute([$pluginId]);
        flash('ok', '已清空表单绑定。下一次正确表单推送会重新绑定 appId / entryId。');
    } elseif ($action === 'save_rollback') {
        $apiKey = trim((string)($_POST['api_key'] ?? ''));
        $cipher = $apiKey === '' ? ($plugin['api_key_cipher'] ?? null) : encryptSecret($apiKey, $config);
        $enabled = !empty($_POST['fillback_enabled']) ? 1 : 0;
        if ($enabled && empty($cipher)) throw new RuntimeException('开启回滚功能需要先配置简道云 API Key。');
        $db->prepare('UPDATE plugins SET api_key_cipher=?,fillback_enabled=?,updated_at=NOW() WHERE id=?')->execute([$cipher, $enabled, $pluginId]);
        flash('ok', $enabled ? '回滚功能已开启。' : '回滚功能已关闭。');
    } else {
        $fields = array_values(array_filter(
            array_unique(array_map('strval', $_POST['fields'] ?? [])),
            fn($field) => !isPlatformInternalField((string)$field)
        ));
        $serialField = trim((string)($_POST['serial_field'] ?? ''));
        // 留空表示仍交给首次推送 / API 自动识别；填写时可完全脱离 API 手动定位。
        if ($serialField === '') $serialField = 'auto';
        $labels = [];
        foreach (($_POST['field_labels'] ?? []) as $field => $label) {
            $label = trim((string)$label);
            if ($label !== '') $labels[(string)$field] = $label;
        }
        $subtableColumns = [];
        foreach (($_POST['subtable_columns'] ?? []) as $tableField => $columnsText) {
            $columns = array_values(array_filter(array_unique(array_map('trim', explode(',', (string)$columnsText))), fn($v) => $v !== ''));
            if ($columns) $subtableColumns[(string)$tableField] = $columns;
        }
        $db->prepare('UPDATE plugins SET serial_field=?,display_config=?,updated_at=NOW() WHERE id=?')->execute([
            $serialField,
            json_encode(['fields' => $fields, 'labels' => $labels, 'subtable_columns' => $subtableColumns], JSON_UNESCAPED_UNICODE),
            $pluginId,
        ]);
        flash('ok', '展示字段已保存。');
    }
    header('Location: settings.php?plugin=' . $pluginId);
    exit;
}

$q = $db->prepare('SELECT payload, serial_no FROM records WHERE plugin_id=? ORDER BY updated_at DESC LIMIT 1');
$q->execute([$pluginId]);
$latest = $q->fetch() ?: [];
$payload = stripPlatformInternalFields(json_decode((string)($latest['payload'] ?? ''), true) ?: []);
$displayConfig = json_decode($plugin['display_config'] ?? '', true) ?: [];
$selected = array_values(array_filter(
    array_map('strval', $displayConfig['fields'] ?? array_keys($payload)),
    fn($field) => !isPlatformInternalField((string)$field)
));
$manualLabels = is_array($displayConfig['labels'] ?? null) ? $displayConfig['labels'] : [];
$configuredSubtableColumns = is_array($displayConfig['subtable_columns'] ?? null) ? $displayConfig['subtable_columns'] : [];
$q = $db->prepare('SELECT COUNT(*) AS record_count, COALESCE(SUM(OCTET_LENGTH(payload)), 0) AS payload_bytes FROM records WHERE plugin_id=?');
$q->execute([$pluginId]);
$recordStats = $q->fetch() ?: ['record_count' => 0, 'payload_bytes' => 0];
$q = $db->prepare('SELECT COUNT(*) AS version_count, COALESCE(SUM(OCTET_LENGTH(rv.payload)), 0) AS payload_bytes FROM record_versions rv INNER JOIN records r ON r.id=rv.record_id WHERE r.plugin_id=?');
$q->execute([$pluginId]);
$versionStats = $q->fetch() ?: ['version_count' => 0, 'payload_bytes' => 0];
$storageBytes = (int)$recordStats['payload_bytes'] + (int)$versionStats['payload_bytes'];
$baseUrl = currentBaseUrl($config);
$historyUrl = $baseUrl . '/history.php?token=' . $plugin['receiver_token'] . '&serial_no=' . rawurlencode((string)($latest['serial_no'] ?? '{{流水号}}'));
$groups = ['主表字段' => [], '子表字段' => [], '系统字段' => []];
foreach ($payload as $field => $value) $groups[fieldGroup((string)$field, $value)][$field] = $value;
$selectedCount = count(array_intersect(array_map('strval', $selected), array_map('strval', array_keys($payload))));
$flash = takeFlash();
$assetVersion = file_exists(__DIR__ . '/style.css') ? (string)filemtime(__DIR__ . '/style.css') : (string)time();
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($plugin['name']) ?> · Dingdanduo.net</title>
    <link rel="stylesheet" href="style.css?v=<?= h($assetVersion) ?>">
    <style>
        .settings-body{background:#f4f7fb;color:#172033}.settings-page{max-width:1440px;margin:0 auto;padding:28px 28px 56px}.settings-hero{display:grid;grid-template-columns:minmax(240px,auto) minmax(0,1fr) auto;align-items:center;gap:26px;padding:18px 2px 22px;border-bottom:1px solid #dfe6f1}.brand-line{display:flex;align-items:center;gap:12px;min-width:0}.brand-icon{width:42px;height:42px;border-radius:10px;background:#172033;color:#fff;display:grid;place-items:center;font-weight:900;font-size:18px;flex:0 0 auto}.brand-text strong{display:block;font-size:22px;line-height:1.05}.brand-text span{display:block;color:#667085;font-size:12px;font-weight:650;margin-top:3px}.settings-heading{min-width:0;border-left:1px solid #dfe6f1;padding-left:24px}.settings-heading .eyebrow{display:block;color:#356dff;font-size:11px;font-weight:850;letter-spacing:.08em}.settings-hero h1{margin:3px 0 3px;font-size:26px;letter-spacing:0;line-height:1.15}.settings-hero p{margin:0;color:#667085}.top-actions{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.ghost-link,.primary-link{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 14px;border-radius:8px;text-decoration:none;font-weight:750}.ghost-link{color:#172033;background:#fff;border:1px solid #dfe6f1}.primary-link{color:#fff;background:#356dff}.plugin-data-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:20px}.plugin-data-stat{min-width:0;padding:15px 16px;border:1px solid #dfe6f1;border-radius:10px;background:#fff;box-shadow:0 8px 20px rgba(31,41,55,.035)}.plugin-data-stat span{display:block;color:#7b8494;font-size:12px;font-weight:700}.plugin-data-stat b{display:block;margin-top:5px;color:#172033;font-size:22px;line-height:1.15;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.plugin-data-stat small{display:block;margin-top:5px;color:#98a2b3;font-size:12px}.settings-panel.pro-settings{display:grid;grid-template-columns:286px minmax(0,1fr);gap:22px;align-items:start;margin-top:22px}.pro-settings .settings-side{position:sticky;top:22px;align-self:start;padding:20px;background:#fff;border:1px solid #dfe6f1;border-radius:10px;box-shadow:0 10px 26px rgba(31,41,55,.05)}.pro-settings .settings-side h2{margin:6px 0 8px;font-size:20px}.pro-settings .settings-side p{margin:0 0 14px;color:#667085}.setting-stats{display:grid;gap:8px;margin:15px 0}.setting-stats div{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#f8fafc;border:1px solid #e6edf6;border-radius:8px}.setting-stats b{font-size:15px;color:#172033}.setting-stats span{font-size:12px;color:#7b8494}.rollback-box{margin:16px 0;padding:14px;border:1px solid #e6edf6;border-radius:10px;background:#f8fafc}.rollback-box h3{margin:0 0 5px;font-size:16px}.rollback-box p{margin:0 0 12px;color:#667085;font-size:12px}.rollback-box label{display:block;margin-top:10px;color:#344054;font-weight:700}.rollback-box input[type=password]{height:38px;margin-top:6px}.toggle-line{display:flex!important;align-items:center;gap:8px;margin-top:0!important}.toggle-line input{width:auto;margin:0}.rollback-box button{width:100%;margin-top:12px;border-radius:8px}.pro-settings .field-tools{display:grid;grid-template-columns:1fr 56px 56px;gap:8px;margin:14px 0}.pro-settings .field-tools input{margin:0;height:38px}.pro-settings .small{margin:0;padding:8px 4px;font-size:13px}.pro-settings .refresh-button,.pro-settings .save-button{width:100%;border-radius:8px}.pro-settings .refresh-button{margin:12px 0 2px;background:#fff;color:#1e293b;border:1px solid #d8dfeb}.pro-settings .save-button{margin-top:8px}.field-workspace{display:grid;gap:18px;min-width:0}.field-section{background:#fff;border:1px solid #dfe6f1;border-radius:10px;overflow:hidden;box-shadow:0 10px 26px rgba(31,41,55,.04)}.field-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 16px;background:#f8fafc;border-bottom:1px solid #e7edf5}.field-section-head h2{margin:0;font-size:17px}.field-section-head span{color:#8a94a6;font-size:13px}.field-section .field-grid{display:grid!important;grid-template-columns:repeat(3,minmax(220px,1fr));gap:10px;padding:12px}.pro-settings label.field-card{position:relative;display:block!important;min-height:104px;margin:0!important;padding:14px!important;border:1px solid #e2e8f0!important;border-radius:8px!important;background:#fff!important;box-shadow:none!important;cursor:pointer;color:#334155!important;font-weight:500!important;overflow:hidden;transition:background .15s,border-color .15s,box-shadow .15s}.pro-settings label.field-card:hover{border-color:#cbd7ea!important;background:#fbfdff!important}.pro-settings label.field-card:has(.field-check-input:checked){border-color:#bcd0ff!important;background:#f7faff!important;box-shadow:0 0 0 1px rgba(53,109,255,.08)!important}.pro-settings .field-check-input{position:absolute!important;inline-size:1px!important;block-size:1px!important;opacity:0!important;pointer-events:none!important;margin:0!important}.pro-settings .field-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;min-width:0}.pro-settings .field-card strong{display:block;min-width:0;font-size:15px;line-height:1.35;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pro-settings .field-card em{flex:0 0 auto;display:inline-flex;align-items:center;height:22px;margin:0 0 0 8px;font-style:normal;color:#3262bc;background:#edf4ff;border-radius:999px;font-size:12px;padding:0 8px}.pro-settings .field-preview{display:block;margin:10px 0 0;font-size:13px;color:#667085;line-height:1.4;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pro-settings .field-id{display:block;margin:9px 0 0;font-size:11px;color:#a6afbf;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}@media(max-width:1180px){.field-section .field-grid{grid-template-columns:repeat(2,minmax(220px,1fr))}}@media(max-width:820px){.settings-page{padding:20px 14px 44px}.settings-hero{grid-template-columns:1fr;align-items:start}.top-actions{justify-content:flex-start}.plugin-data-stats{grid-template-columns:1fr}.settings-heading{border-left:0;padding-left:0}.settings-panel.pro-settings{grid-template-columns:1fr}.pro-settings .settings-side{position:static}.field-section .field-grid{grid-template-columns:1fr}}
        .subtable-sort{grid-column:1/-1;margin:0 12px 12px;padding:14px;border:1px solid #e2e8f0;border-radius:10px;background:#fbfdff}.subtable-sort h3{margin:0 0 4px;font-size:15px}.subtable-sort p{margin:0 0 10px;color:#667085;font-size:12px}.column-sort-list{display:grid;gap:8px}.column-sort-item{display:grid;grid-template-columns:1fr auto;align-items:center;gap:10px;padding:9px 10px;border:1px solid #e6edf6;border-radius:8px;background:#fff}.column-sort-item strong{display:block;font-size:13px}.column-sort-item small{display:block;color:#98a2b3;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.column-sort-actions{display:flex;gap:6px}.column-sort-actions button{margin:0;padding:5px 8px;border:1px solid #d8dfeb;border-radius:7px;background:#fff;color:#344054}.field-label-editor{display:block;margin-top:10px}.field-label-editor span{display:block;margin-bottom:4px;color:#98a2b3;font-size:11px;font-weight:700}.field-label-editor input{width:100%;height:30px;margin:0;padding:0 8px;border:1px solid #dfe6f1;border-radius:6px;background:#fff;color:#344054;font-size:12px}.field-label-editor input:focus{outline:0;border-color:#8eb0ff;box-shadow:0 0 0 3px rgba(53,109,255,.1)}
        /* 设置页采用更轻的概览式布局，避免字段配置区显得拥挤。 */
        .settings-body{background:linear-gradient(145deg,#f8faff,#f3f6fb)}.settings-page{max-width:1500px;padding:32px 32px 64px}.settings-hero{grid-template-columns:minmax(0,1fr) auto;gap:20px;padding:6px 0 24px}.brand-line{display:none}.settings-heading{border-left:0;padding-left:0}.settings-hero h1{font-size:30px;letter-spacing:-.03em}.top-actions a{min-height:40px;border-radius:9px}.primary-link{box-shadow:0 8px 18px rgba(53,109,255,.2)}.plugin-data-stats{gap:14px}.plugin-data-stat{padding:17px 18px;border-radius:13px;background:rgba(255,255,255,.88);box-shadow:0 12px 28px rgba(31,41,55,.045)}.plugin-data-stat:before{content:"";display:block;width:3px;height:100%;position:absolute;left:0;top:0;background:#356dff}.plugin-data-stat{position:relative}.plugin-data-stat b{font-size:24px}.settings-tabs{display:inline-flex;gap:4px;margin-top:22px;padding:4px;border:1px solid #dfe6f1;border-radius:10px;background:#eef3fa}.settings-tab{min-height:34px;padding:0 14px;border:0;border-radius:7px;background:transparent;color:#667085;font-weight:750;cursor:pointer}.settings-tab.active{background:#fff;color:#1d4ed8;box-shadow:0 2px 6px rgba(31,41,55,.08)}.settings-panel.pro-settings{grid-template-columns:300px minmax(0,1fr)}.settings-panel.tab-basic{display:block;height:auto!important;max-height:none!important}.settings-panel.tab-basic .settings-side{position:static;max-width:720px;max-height:none;overflow:visible;padding:24px}.settings-panel.tab-mapping{display:block;height:calc(100vh - 260px);min-height:520px;max-height:900px}.settings-panel.tab-mapping .field-workspace{height:100%;overflow:auto;padding-right:6px}.pro-settings .settings-side,.field-section{border-radius:13px}.pro-settings .settings-side{box-shadow:0 12px 32px rgba(31,41,55,.06)}.field-section{box-shadow:0 12px 28px rgba(31,41,55,.045)}.field-section .field-grid{gap:11px;padding:13px}.pro-settings label.field-card{border-radius:10px!important}.mapping-save{display:flex;justify-content:flex-end;padding:6px 0 2px}.mapping-save .save-button{width:auto!important;min-width:150px;padding:0 18px}
        @media(min-width:821px){.settings-panel.pro-settings{height:calc(100vh - 260px);min-height:520px;max-height:900px}.pro-settings .settings-side{position:static;top:auto;height:100%;max-height:none;overflow:auto;padding-right:14px;scrollbar-gutter:stable}.field-workspace{height:100%;overflow:auto;padding:0 6px 4px 0;scrollbar-gutter:stable}.pro-settings .settings-side::-webkit-scrollbar,.field-workspace::-webkit-scrollbar{width:8px}.pro-settings .settings-side::-webkit-scrollbar-thumb,.field-workspace::-webkit-scrollbar-thumb{border:2px solid transparent;border-radius:999px;background:#cbd5e1;background-clip:padding-box}.pro-settings .settings-side::-webkit-scrollbar-track,.field-workspace::-webkit-scrollbar-track{background:transparent}}
        @media(max-width:820px){.settings-page{padding:20px 14px 44px}.settings-hero{grid-template-columns:1fr}.top-actions{justify-content:flex-start}.plugin-data-stats{grid-template-columns:1fr}.settings-panel.pro-settings{height:auto;max-height:none}.field-workspace{height:auto;overflow:visible}}
        /* Settings Center redesign */
        :root{--set-ink:#172033;--set-muted:#65738a;--set-line:#e4eaf3;--set-blue:#356dff;--set-soft:#f6f8fc}
        .settings-body{background:#f5f7fb}.settings-page{max-width:1480px;padding:36px 34px 72px}.settings-hero{align-items:center;padding:0 0 26px;border-bottom:0}.settings-heading .eyebrow{letter-spacing:.14em;font-size:10px}.settings-hero h1{font-size:32px;font-weight:800;letter-spacing:-.045em}.settings-hero p{font-size:14px;color:var(--set-muted)}.top-actions{gap:9px}.ghost-link,.primary-link{height:42px;min-height:42px;padding:0 16px;border-radius:10px}.ghost-link{background:#fff;border-color:#dfe6f1;color:#354052}.primary-link{background:linear-gradient(135deg,#3f78ff,#2f63e9);box-shadow:0 9px 20px rgba(53,109,255,.22)}
        .plugin-data-stats{grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:0}.plugin-data-stat{padding:18px 20px;border:1px solid var(--set-line);border-radius:14px;background:#fff;box-shadow:0 7px 22px rgba(18,38,63,.04)}.plugin-data-stat:before{display:none}.plugin-data-stat span{font-size:12px;letter-spacing:.03em}.plugin-data-stat b{margin-top:8px;font-size:26px;letter-spacing:-.03em}.plugin-data-stat small{margin-top:6px}
        .settings-tabs{display:flex;width:max-content;max-width:100%;margin:26px 0 0;padding:4px;border:1px solid #e0e7f2;border-radius:12px;background:#edf2f9;gap:3px}.settings-tab{min-width:112px;height:38px;border-radius:8px;font-size:13px;transition:background .18s,color .18s,box-shadow .18s}.settings-tab.active{color:#1f56d7;background:#fff;box-shadow:0 2px 8px rgba(35,57,92,.13)}
        .settings-panel.tab-basic{margin-top:16px}.settings-panel.tab-basic .settings-side{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;max-width:940px;padding:22px;border:1px solid var(--set-line);border-radius:16px;background:#fff;box-shadow:0 12px 32px rgba(20,40,70,.055)}.settings-side>.pill,.settings-side>h2,.settings-side>p,.settings-side>.setting-stats,.settings-side>.refresh-button,.settings-side>.save-button{grid-column:1/-1}.settings-side>.pill{margin:0;color:#356dff;background:#edf3ff}.settings-side>h2{margin:0;font-size:22px;letter-spacing:-.025em}.settings-side>p{margin:-7px 0 2px;font-size:13px;color:var(--set-muted)}.setting-stats{grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:4px 0}.setting-stats div{display:block;padding:13px;border:1px solid #e9eef6;border-radius:10px;background:#fafcff}.setting-stats b{display:block;font-size:17px}.setting-stats span{display:block;margin-top:3px}.rollback-box{margin:0;padding:16px;border:1px solid #e6ebf3;border-radius:12px;background:#fbfcfe}.rollback-box h3{font-size:15px}.rollback-box p{min-height:36px;font-size:12px;line-height:1.5}.rollback-box label{font-size:12px}.rollback-box input{border-radius:8px}.refresh-button{height:40px;margin:2px 0 0!important;border-radius:9px!important}.save-button{height:42px;margin:0!important;border-radius:10px!important;background:linear-gradient(135deg,#3f78ff,#2f63e9)!important;box-shadow:0 8px 16px rgba(53,109,255,.18)}
        .settings-panel.tab-mapping{margin-top:16px;height:calc(100vh - 310px);min-height:520px;border:1px solid var(--set-line);border-radius:16px;background:#fff;box-shadow:0 12px 32px rgba(20,40,70,.055);overflow:hidden}.settings-panel.tab-mapping .field-workspace{padding:18px 18px 22px;scrollbar-color:#cbd5e1 transparent}.mapping-toolbar{position:sticky;top:-18px;z-index:8;display:flex;align-items:center;justify-content:space-between;gap:16px;margin:-18px -18px 18px;padding:18px;background:rgba(255,255,255,.94);border-bottom:1px solid #edf1f6;backdrop-filter:blur(10px)}.mapping-toolbar strong{display:block;font-size:16px}.mapping-toolbar span{display:block;margin-top:3px;color:var(--set-muted);font-size:12px}.field-tools{width:min(520px,100%);grid-template-columns:minmax(160px,1fr) 58px 58px!important;margin:0!important}.field-tools input{height:36px!important;border-radius:8px}.field-tools .small{height:36px;border-radius:8px}.field-section{border:1px solid var(--set-line);border-radius:13px;background:#fff;box-shadow:none}.field-section-head{padding:14px 16px;background:#fafbfe}.field-section-head h2{font-size:16px}.field-section .field-grid{gap:12px;padding:14px}.pro-settings label.field-card{min-height:148px!important;padding:15px!important;border-radius:11px!important;background:#fff!important}.pro-settings label.field-card:hover{border-color:#aac3ff!important;box-shadow:0 8px 18px rgba(53,109,255,.08)!important}.pro-settings label.field-card:has(.field-check-input:checked){border-color:#78a1ff!important;background:#f7faff!important;box-shadow:inset 3px 0 0 #356dff,0 8px 18px rgba(53,109,255,.07)!important}.pro-settings .field-card strong{font-size:15px}.pro-settings .field-card em{height:23px;background:#eef4ff;color:#3568ca}.pro-settings .field-preview{margin-top:9px}.field-label-editor{margin-top:11px}.field-label-editor input{height:31px;border-radius:7px}.field-id{margin-top:8px!important}.subtable-sort{margin:0 0 2px;padding:15px;border-radius:11px;background:#f8faff}.mapping-save{position:sticky;bottom:-22px;z-index:6;margin:18px -18px -22px;padding:14px 18px;background:rgba(255,255,255,.94);border-top:1px solid #edf1f6;backdrop-filter:blur(10px)}.mapping-save .save-button{height:40px!important}
        @media(max-width:900px){.settings-page{padding:24px 16px 52px}.settings-hero{align-items:flex-start}.plugin-data-stats{grid-template-columns:1fr}.settings-tabs{width:100%}.settings-tab{flex:1}.settings-panel.tab-basic .settings-side{grid-template-columns:1fr;padding:16px}.setting-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.settings-panel.tab-mapping{height:auto;min-height:0;overflow:visible}.settings-panel.tab-mapping .field-workspace{height:auto;overflow:visible;padding:0}.mapping-toolbar{position:static;margin:0 0 14px;padding:14px;display:block;border:1px solid var(--set-line);border-radius:12px;background:#fff}.field-tools{margin-top:12px!important;width:100%}.mapping-save{position:static;margin:16px 0 0;padding:0;border:0;background:transparent}.field-section .field-grid{grid-template-columns:1fr}}
        /* Final compact mobile layout for settings tabs */
        .settings-panel [data-settings-pane][hidden]{display:none!important}
        .settings-tabs{display:flex;width:auto;margin:22px 0 0;padding:0;gap:26px;border:0;border-radius:0;background:transparent;border-bottom:1px solid #dfe6f1}.settings-tab{position:relative;min-width:0;height:42px;padding:0 2px;border-radius:0;color:#7a8799;background:transparent;font-weight:700}.settings-tab.active{color:#245ed9;background:transparent;box-shadow:none}.settings-tab.active:after{content:"";position:absolute;right:0;bottom:-1px;left:0;height:2px;border-radius:2px;background:#356dff}.settings-tab:hover{color:#245ed9}
        .settings-panel.tab-basic{margin-top:18px}.settings-panel.tab-basic .settings-side{display:block;max-width:760px;padding:0;border:0;border-radius:0;background:transparent;box-shadow:none}.settings-side>h2{margin:0 0 6px;font-size:22px}.settings-side>p{margin:0 0 16px}.settings-side>.setting-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:0 0 18px}.setting-stats div{padding:12px;border-radius:10px;background:#fff}.rollback-box{margin:0 0 12px;padding:16px;border-color:#e3e9f2;border-radius:12px;background:#fff;box-shadow:0 6px 16px rgba(31,41,55,.025)}.rollback-box p{min-height:0;margin-bottom:12px}.refresh-button{margin-top:4px!important}.save-button{margin-top:10px!important}
        .settings-panel.tab-mapping{margin-top:18px;border:0;border-radius:0;background:transparent;box-shadow:none;overflow:visible}.settings-panel.tab-mapping .field-workspace{height:auto;overflow:visible;padding:0}.mapping-toolbar{position:static;display:block;margin:0 0 14px;padding:0;background:transparent;border:0;backdrop-filter:none}.mapping-toolbar strong{font-size:18px}.mapping-toolbar span{margin-top:4px}.field-tools{width:100%;margin-top:12px!important}.field-section{margin-bottom:14px;border-radius:12px;background:#fff;box-shadow:0 6px 16px rgba(31,41,55,.025)}.field-section-head{padding:13px 16px}.field-section .field-grid{grid-template-columns:repeat(auto-fill,minmax(280px,1fr))!important;gap:10px;padding:12px}.pro-settings label.field-card{display:grid!important;grid-template-columns:minmax(0,1fr) auto;grid-template-areas:"head badge" "preview preview" "editor editor" "id id";align-content:start;min-height:0!important;padding:14px!important;border:1px solid #e3e9f2!important;border-radius:10px!important;background:#fff!important;box-shadow:none!important}.pro-settings label.field-card:has(.field-check-input:checked){border-color:#9dbbff!important;background:#f8fbff!important;box-shadow:inset 3px 0 0 #356dff!important}.pro-settings .field-card-head{display:contents}.pro-settings .field-card strong{grid-area:head;display:block;padding-right:8px;font-size:14px}.pro-settings .field-card em{grid-area:badge;align-self:start;margin:0;height:21px;font-size:11px}.pro-settings .field-preview{grid-area:preview;margin:7px 0 0;font-size:12px;color:#667085}.field-label-editor{grid-area:editor;display:grid;grid-template-columns:82px minmax(0,1fr);align-items:center;gap:8px;margin-top:10px}.field-label-editor span{margin:0;font-size:11px}.field-label-editor input{height:30px;border-radius:6px}.pro-settings .field-id{grid-area:id;margin:8px 0 0!important;font-size:10px}.mapping-save{position:static;margin:16px 0 0;padding:0;border:0;background:transparent;backdrop-filter:none}.mapping-save .save-button{width:100%!important;margin:0!important}
        .subtable-sort{margin:2px 0 0;padding:12px;border:1px solid #e3e9f2;border-radius:10px;background:#fafcff}.column-sort-list{gap:7px}.column-sort-item{grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:10px 11px;border-radius:8px}.column-sort-name{min-width:0}.column-sort-name strong{font-size:13px}.column-sort-name small{margin-top:2px}.column-label-editor{display:grid;grid-template-columns:62px minmax(0,1fr);align-items:center;gap:7px;margin-top:7px;color:#8a94a6;font-size:11px}.column-label-editor input{width:100%;height:28px;margin:0;padding:0 7px;border:1px solid #dfe6f1;border-radius:6px;background:#fff;color:#344054;font-size:12px}.column-sort-actions button{height:30px;margin:0;padding:0 9px;border-radius:7px;font-size:12px}
        @media(max-width:820px){.settings-page{padding:20px 16px 44px}.plugin-data-stats{gap:10px}.plugin-data-stat{padding:14px 16px}.settings-tabs{gap:22px}.settings-tab{height:40px}.field-tools{grid-template-columns:minmax(0,1fr) 54px 54px!important}.field-tools input{min-width:0}.field-section .field-grid{grid-template-columns:1fr!important}.column-sort-item{align-items:start}.column-sort-actions{padding-top:2px}}
        /* Responsive field mapping: 4 / 3 / 2 / 1 columns without oversized cards. */
        .settings-page{width:100%;max-width:1680px;margin:0 auto;padding-inline:clamp(16px,2.4vw,40px)}
        .settings-panel.tab-basic .settings-side{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr));max-width:1180px;margin:0 auto}.settings-side>h2,.settings-side>p,.settings-side>.setting-stats,.settings-side>.refresh-button,.settings-side>.save-button{grid-column:1/-1}.settings-side>.setting-stats{grid-template-columns:repeat(3,minmax(0,1fr))}
        .settings-panel.tab-mapping .field-workspace{width:100%;max-width:1680px;margin:0 auto}.field-section .field-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important;align-items:stretch}.pro-settings .field-card{display:grid!important;grid-template-columns:minmax(0,1fr);grid-template-rows:auto auto auto auto;gap:0;min-width:0;min-height:132px!important;padding:13px 14px!important;border:1px solid #e3e9f2!important;border-radius:10px!important;background:#fff!important;box-shadow:none!important}.pro-settings .field-card:hover{border-color:#b9cbef!important;box-shadow:0 6px 16px rgba(39,78,142,.06)!important}.pro-settings .field-card:has(.field-check-input:checked){border-color:#7ea4ff!important;background:#fbfdff!important;box-shadow:inset 3px 0 0 #356dff!important}.field-select{display:block!important;min-width:0;margin:0!important;cursor:pointer}.pro-settings .field-check-input{position:absolute!important;width:1px!important;height:1px!important;opacity:0!important;pointer-events:none!important}.pro-settings .field-card-head{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:8px!important}.pro-settings .field-card strong{min-width:0;font-size:14px!important;line-height:1.35!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pro-settings .field-card em{height:21px!important;margin:0!important;padding:0 7px!important;font-size:11px!important}.pro-settings .field-preview{display:block!important;margin:6px 0 0!important;min-height:17px;font-size:12px!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pro-settings .field-label-editor{display:grid!important;grid-template-columns:76px minmax(0,1fr)!important;align-items:center!important;gap:7px!important;margin:9px 0 0!important;min-width:0}.pro-settings .field-label-editor span{display:block!important;margin:0!important;color:#8b97aa!important;font-size:11px!important;white-space:nowrap}.pro-settings .field-label-editor input{display:block!important;position:static!important;width:100%!important;min-width:0!important;height:29px!important;margin:0!important;padding:0 8px!important;border:1px solid #dfe6f1!important;border-radius:6px!important;background:#fff!important;color:#344054!important;font-size:12px!important;line-height:29px!important;box-shadow:none!important}.pro-settings .field-id{display:block!important;margin:7px 0 0!important;color:#a2aec0!important;font-size:10px!important;line-height:1.2!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        @media(max-width:1400px){.settings-panel.tab-basic .settings-side{max-width:100%}.field-section .field-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important}}
        @media(max-width:980px){.field-section .field-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.settings-panel.tab-basic .settings-side{grid-template-columns:1fr!important;max-width:760px}.settings-side>.setting-stats{grid-template-columns:repeat(3,minmax(0,1fr))!important}}
        @media(max-width:620px){.settings-page{padding:16px 12px 40px}.settings-hero h1{font-size:25px}.plugin-data-stats{grid-template-columns:1fr!important}.settings-tabs{gap:12px;width:100%}.settings-tab{flex:1}.settings-panel.tab-basic .settings-side{display:block!important}.setting-stats div{padding:10px 8px}.setting-stats b{font-size:15px}.setting-stats span{font-size:11px}.rollback-box{padding:14px}.field-section .field-grid{grid-template-columns:1fr!important;gap:8px;padding:9px}.pro-settings .field-card{min-height:0!important;padding:12px!important}.pro-settings .field-label-editor{grid-template-columns:1fr!important;gap:4px!important}.pro-settings .field-label-editor input{height:28px!important}.mapping-toolbar{padding:0}.field-tools{grid-template-columns:minmax(0,1fr) 52px 52px!important}.plugin-data-stat b{font-size:22px}}
        /* Isolate mapping cards from the legacy global .field-card input rules. */
        .settings-panel.tab-mapping .field-card{position:relative!important;display:flex!important;flex-direction:column!important;align-items:stretch!important;justify-content:flex-start!important;min-height:156px!important;overflow:hidden!important}
        .settings-panel.tab-mapping .field-card>.field-select{display:block!important;width:100%!important;min-width:0!important;flex:0 0 auto!important;position:static!important}
        .settings-panel.tab-mapping .field-card>.field-select>.field-card-head{display:flex!important;width:100%!important;min-width:0!important;align-items:center!important;justify-content:space-between!important}
        .settings-panel.tab-mapping .field-card>.field-select>.field-card-head>strong{display:block!important;min-width:0!important;max-width:none!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important}
        .settings-panel.tab-mapping .field-card>.field-preview{width:100%!important;min-width:0!important;flex:0 0 auto!important}
        .settings-panel.tab-mapping .field-card>.field-label-editor{display:grid!important;width:100%!important;min-width:0!important;flex:0 0 auto!important;position:static!important}
        .settings-panel.tab-mapping .field-card>.field-label-editor input{position:static!important;inset:auto!important;transform:none!important;float:none!important;width:100%!important;min-width:0!important;max-width:none!important}
        .settings-panel.tab-mapping .field-card>.field-id{width:100%!important;min-width:0!important;flex:0 0 auto!important;position:static!important}
    </style>
</head>
<body class="settings-body">
<main class="settings-page">
    <header class="settings-hero">
        <div class="settings-heading">
            <span class="eyebrow">DISPLAY SETTINGS</span>
            <h1><?= h($plugin['name']) ?></h1>
            <p>配置历史页展示字段，字段结构来自最近一次推送和简道云表单 API。</p>
        </div>
        <div class="top-actions">
            <a class="ghost-link" href="index.php">返回插件列表</a>
            <a class="primary-link" href="<?= h($historyUrl) ?>" target="_blank">预览历史页</a>
        </div>
    </header>

    <?php if ($flash): ?><div class="notice <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div><?php endif; ?>

    <section class="plugin-data-stats" aria-label="插件数据统计">
        <div class="plugin-data-stat"><span>数据记录</span><b><?= h((int)$recordStats['record_count']) ?></b><small>按流水号保存的当前记录</small></div>
        <div class="plugin-data-stat"><span>历史版本</span><b><?= h((int)$versionStats['version_count']) ?></b><small>含推送、定时获取与回滚快照</small></div>
        <div class="plugin-data-stat"><span>数据占用</span><b><?= h(formatStorageSize($storageBytes)) ?></b><small>当前记录与版本 JSON 内容合计</small></div>
    </section>

    <nav class="settings-tabs" aria-label="设置分类">
        <button class="settings-tab active" type="button" data-settings-tab="basic">基础设置</button>
        <button class="settings-tab" type="button" data-settings-tab="mapping">字段映射</button>
    </nav>

    <?php if (!$payload): ?>
        <section class="empty-state">
            <h2>还没有可配置字段</h2>
            <p>先让简道云向该插件推送一条数据，平台会自动读取字段并生成可视化配置。</p>
        </section>
    <?php else: ?>
        <form class="settings-panel pro-settings tab-basic" method="post" id="settingsForm">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
            <input type="hidden" name="plugin" value="<?= h($pluginId) ?>">
            <aside class="settings-side" data-settings-pane="basic">
                <h2>基础配置</h2>
                <p>管理流水号定位、表单结构读取与版本回滚。字段展示请在“字段映射”标签中维护。</p>
                <div class="setting-stats">
                    <div><b><?= h(count($payload)) ?></b><span>全部字段</span></div>
                    <div><b id="selectedCount"><?= h($selectedCount) ?></b><span>已选择</span></div>
                    <div><b><?= h($plugin['field_map'] ? '已匹配' : '待匹配') ?></b><span>表单结构</span></div>
                </div>
                <div class="rollback-box">
                    <h3>流水号字段</h3>
                    <p>没有 API 时可直接填写推送数据中的字段 ID，例如 <code>_widget_xxx</code>。留空则保持自动识别。</p>
                    <label>字段 ID
                        <input type="text" name="serial_field" value="<?= h($plugin['serial_field'] === 'auto' ? '' : $plugin['serial_field']) ?>" placeholder="_widget_xxx">
                    </label>
                </div>
                <div class="rollback-box">
                    <h3>表单绑定</h3>
                    <p>当前 Token 只允许接收同一个简道云表单的数据，防止相同结构表单互相串数据。</p>
                    <label>当前 appId
                        <input type="text" value="<?= h($plugin['app_id'] ?: '未绑定') ?>" readonly>
                    </label>
                    <label>当前 entryId
                        <input type="text" value="<?= h($plugin['entry_id'] ?: '未绑定') ?>" readonly>
                    </label>
                    <button class="ghost" name="action" value="reset_binding" onclick="return confirm('确认清空当前 Token 的表单绑定吗？下一次推送会重新绑定。')">清空表单绑定</button>
                </div>
                <div class="rollback-box">
                    <h3>表单结构读取</h3>
                    <p>MCP URL 优先级高于 API Key；读取字段时会优先通过 MCP 获取表单字段。</p>
                    <label>MCP URL
                        <input type="url" name="mcp_url" value="<?= h($plugin['mcp_url'] ?? '') ?>" placeholder="https://mcp.jiandaoyun.com/mcp/...">
                    </label>
                    <button class="ghost" name="action" value="save_mcp">保存 MCP URL</button>
                </div>
                <div class="rollback-box">
                    <h3>版本回滚</h3>
                    <p>开启后，历史页可将选定版本的整条数据完整回写到简道云。</p>
                    <label class="toggle-line"><input type="checkbox" name="fillback_enabled" <?= !empty($plugin['fillback_enabled']) ? 'checked' : '' ?>> 开启回滚功能</label>
                    <label>API Key
                        <input type="password" name="api_key" placeholder="<?= empty($plugin['api_key_cipher']) ? '未配置，请填写' : '已配置；留空则不修改' ?>">
                    </label>
                    <button class="ghost" name="action" value="save_rollback">保存回滚设置</button>
                </div>
                <button class="ghost refresh-button" name="action" value="refresh_widgets">读取表单结构</button>
                <button class="save-button" name="action" value="save_fields">保存基础设置</button>
            </aside>

            <section class="field-workspace" id="fieldGrid" data-settings-pane="mapping" hidden>
                <div class="mapping-toolbar">
                    <div><strong>选择历史页展示字段</strong><span>可同时维护字段显示名称与子表列顺序</span></div>
                    <div class="field-tools">
                        <input id="fieldSearch" type="search" placeholder="搜索字段名称、ID 或预览值">
                        <button type="button" class="ghost small" id="selectAll">全选</button>
                        <button type="button" class="ghost small" id="selectNone">清空</button>
                    </div>
                </div>
                <?php foreach ($groups as $groupName => $items): if (!$items) continue; ?>
                    <section class="field-section">
                        <div class="field-section-head">
                            <h2><?= h($groupName) ?></h2>
                            <span><?= h(count($items)) ?> 个字段</span>
                        </div>
                        <div class="field-grid">
                            <?php foreach ($items as $field => $value): $checked = in_array((string)$field, $selected, true); $label = fieldLabel($plugin, (string)$field); $preview = previewText($value, (string)$field); ?>
                                <div class="field-card" data-search="<?= h(mb_strtolower((string)$field . ' ' . $label . ' ' . $preview)) ?>">
                                    <label class="field-select">
                                        <input class="field-check-input" type="checkbox" name="fields[]" value="<?= h($field) ?>" <?= $checked ? 'checked' : '' ?>>
                                        <span class="field-card-head">
                                            <strong><?= h($label) ?></strong>
                                            <em><?= h(displayFieldType((string)$field, $value)) ?></em>
                                        </span>
                                    </label>
                                    <span class="field-preview"><?= h($preview) ?></span>
                                    <label class="field-label-editor">
                                        <span>自定义展示名称</span>
                                        <input type="text" name="field_labels[<?= h($field) ?>]" value="<?= h($manualLabels[(string)$field] ?? '') ?>" placeholder="<?= h($label) ?>">
                                    </label>
                                    <span class="field-id"><?= h($field) ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($groupName === '子表字段'): foreach ($items as $tableField => $rows): ?>
                                <?php
                                    $columns = subtableColumns(is_array($rows) ? $rows : []);
                                    $savedColumns = array_values(array_filter(array_map('strval', $configuredSubtableColumns[(string)$tableField] ?? [])));
                                    $orderedColumns = array_values(array_unique(array_merge(array_values(array_intersect($savedColumns, $columns)), $columns)));
                                ?>
                                <?php if ($orderedColumns): ?>
                                    <div class="subtable-sort" data-subtable-sort>
                                        <h3><?= h(fieldLabel($plugin, (string)$tableField)) ?> · 列标题顺序</h3>
                                        <p>用上移/下移调整该子表在历史页里的列展示顺序。</p>
                                        <input type="hidden" name="subtable_columns[<?= h($tableField) ?>]" value="<?= h(implode(',', $orderedColumns)) ?>">
                                        <div class="column-sort-list">
                                            <?php foreach ($orderedColumns as $column): ?>
                                                <div class="column-sort-item" data-column-id="<?= h($column) ?>">
                                                    <div class="column-sort-name">
                                                        <strong><?= h(fieldLabel($plugin, (string)$column)) ?></strong>
                                                        <small><?= h($column) ?></small>
                                                        <label class="column-label-editor">展示名称
                                                            <input type="text" name="field_labels[<?= h($column) ?>]" value="<?= h($manualLabels[(string)$column] ?? '') ?>" placeholder="<?= h(fieldLabel($plugin, (string)$column)) ?>">
                                                        </label>
                                                    </div>
                                                    <div class="column-sort-actions">
                                                        <button type="button" data-move="up">上移</button>
                                                        <button type="button" data-move="down">下移</button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
                <div class="mapping-save"><button class="save-button" name="action" value="save_fields">保存字段映射</button></div>
            </section>
        </form>
    <?php endif; ?>
</main>
<script>
const search = document.getElementById('fieldSearch');
const cards = [...document.querySelectorAll('.field-card')];
const selectedCount = document.getElementById('selectedCount');
const syncCount = () => {
    if (selectedCount) selectedCount.textContent = String(document.querySelectorAll('.field-card input:checked').length);
};
search?.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    cards.forEach(card => card.hidden = q && !card.dataset.search.includes(q));
});
cards.forEach(card => card.querySelector('input')?.addEventListener('change', syncCount));
document.getElementById('selectAll')?.addEventListener('click', () => { cards.forEach(card => card.querySelector('input').checked = true); syncCount(); });
document.getElementById('selectNone')?.addEventListener('click', () => { cards.forEach(card => card.querySelector('input').checked = false); syncCount(); });
document.querySelectorAll('[data-subtable-sort]').forEach(box => {
    const input = box.querySelector('input[type="hidden"]');
    const list = box.querySelector('.column-sort-list');
    const sync = () => {
        input.value = [...list.querySelectorAll('[data-column-id]')].map(item => item.dataset.columnId).join(',');
    };
    box.addEventListener('click', event => {
        const button = event.target.closest('[data-move]');
        if (!button) return;
        const item = button.closest('[data-column-id]');
        if (button.dataset.move === 'up' && item.previousElementSibling) {
            list.insertBefore(item, item.previousElementSibling);
        }
        if (button.dataset.move === 'down' && item.nextElementSibling) {
            list.insertBefore(item.nextElementSibling, item);
        }
        sync();
    });
});
const settingsForm = document.getElementById('settingsForm');
const settingTabs = [...document.querySelectorAll('[data-settings-tab]')];
const settingPanes = [...document.querySelectorAll('[data-settings-pane]')];
const switchSettingsTab = tab => {
    if (!settingsForm) return;
    settingsForm.classList.toggle('tab-basic', tab === 'basic');
    settingsForm.classList.toggle('tab-mapping', tab === 'mapping');
    settingTabs.forEach(button => {
        const active = button.dataset.settingsTab === tab;
        button.classList.toggle('active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    settingPanes.forEach(pane => { pane.hidden = pane.dataset.settingsPane !== tab; });
};
settingTabs.forEach(button => button.addEventListener('click', () => switchSettingsTab(button.dataset.settingsTab)));
</script>
</body>
</html>
