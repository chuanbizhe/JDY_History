<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

function displayText(mixed $value, ?string $field = null): string {
    $text = $field === null ? valueText($value) : fieldValueText($field, $value);
    if ($text !== '') return $text;
    if ($value === null || $value === '') return '—';
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
}
function isRows(mixed $value): bool {
    return is_array($value) && isListArray($value) && isset($value[0]) && is_array($value[0]);
}
function rowColumns(array $rows): array {
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
function fieldHead(array $plugin, string $field): string {
    return fieldLabel($plugin, $field);
}
function changed(mixed $old, mixed $new): bool {
    return json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !== json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function compactChangeValue(mixed $value, ?string $field = null): string {
    if (isRows($value)) return count($value) . ' 行数据';
    $text = displayText($value, $field);
    return mb_strlen($text) > 120 ? mb_substr($text, 0, 120) . '...' : $text;
}
function rowKey(array $row, int $index): string {
    return isset($row['_id']) && is_scalar($row['_id']) && (string)$row['_id'] !== '' ? (string)$row['_id'] : '__row_' . $index;
}
function indexRows(array $rows): array {
    $indexed = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) continue;
        $indexed[rowKey($row, (int)$index)] = ['row' => $row, 'index' => (int)$index];
    }
    return $indexed;
}
function buildTableModel(array $plugin, string $tableField, array $newRows, array $oldRows = [], array $preferredColumns = []): array {
    $columns = rowColumns(array_merge($newRows, $oldRows));
    $preferredColumns = array_values(array_filter(array_map('strval', $preferredColumns), fn($column) => in_array($column, $columns, true)));
    if ($preferredColumns) $columns = array_values(array_unique(array_merge($preferredColumns, $columns)));
    $oldMap = indexRows($oldRows);
    $newMap = indexRows($newRows);
    $keys = array_keys($newMap);
    foreach (array_keys($oldMap) as $key) {
        if (!in_array($key, $keys, true)) $keys[] = $key;
    }
    $rows = [];
    $diffs = [];
    $seq = 1;
    foreach ($keys as $key) {
        $old = $oldMap[$key]['row'] ?? null;
        $new = $newMap[$key]['row'] ?? null;
        $rowType = $old === null ? 'added' : ($new === null ? 'removed' : 'normal');
        $cells = [];
        foreach ($columns as $column) {
            $oldValue = $old[$column] ?? null;
            $newValue = $new[$column] ?? null;
            $type = $rowType;
            if ($rowType === 'normal') $type = changed($oldValue, $newValue) ? 'changed' : 'normal';
            $cells[$column] = [
                'type' => $type,
                'value' => $new !== null ? $newValue : $oldValue,
                'old' => $oldValue,
                'new' => $newValue,
            ];
            if ($type !== 'normal') {
                $diffs[] = [
                    'table' => fieldHead($plugin, $tableField),
                    'row' => $seq,
                    'field' => fieldHead($plugin, $column),
                    'field_key' => $column,
                    'type' => $type,
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }
        $rows[] = ['seq' => $seq++, 'type' => $rowType, 'cells' => $cells];
    }
    return ['columns' => $columns, 'rows' => $rows, 'diffs' => $diffs];
}
function actorName(mixed $actor): string {
    if (is_array($actor)) {
        foreach (['name', 'username', 'nickname', '_id', 'id'] as $key) {
            if (!empty($actor[$key]) && is_scalar($actor[$key])) return (string)$actor[$key];
        }
        return '';
    }
    return is_scalar($actor) ? trim((string)$actor) : '';
}
function versionUpdaterName(array $version): string {
    $payload = json_decode((string)($version['payload'] ?? ''), true) ?: [];
    $name = actorName($payload['updater'] ?? null);
    if ($name === '') $name = actorName($payload['creator'] ?? null);
    return $name !== '' ? $name : '未知用户';
}
function versionSourceText(string $source): string {
    return [
        'push' => '推送',
        'poll' => '定时获取',
        'test' => '测试',
        'fillback' => '回滚',
    ][$source] ?? $source;
}

try {
    $token = (string)($_GET['token'] ?? '');
    $serial = trim((string)($_GET['serial_no'] ?? ''));
    if ($token === '') throw new RuntimeException('缺少 Token 参数。');
    if ($serial === '') throw new RuntimeException('缺少流水号参数。');

    $db = pdo($config);
    ensureRuntimeSchema($db);
    $q = $db->prepare('SELECT * FROM plugins WHERE receiver_token=?');
    $q->execute([$token]);
    $plugin = $q->fetch();
    if (!$plugin) throw new RuntimeException('Token 无效或该插件已不存在。');

    // 兼容早期无 _id 推送产生的重复主记录；打开历史页即可自动归并旧版本。
    mergeLegacyRecordsForSerial($db, (int)$plugin['id'], $serial);
    $q = $db->prepare('SELECT * FROM records WHERE plugin_id=? AND serial_no=? ORDER BY updated_at DESC, id DESC LIMIT 1');
    $q->execute([$plugin['id'], $serial]);
    $record = $q->fetch();
    if (!$record) throw new RuntimeException('尚未收到该流水号的数据。');

    $q = $db->prepare('SELECT * FROM record_versions WHERE record_id=? ORDER BY version_no DESC');
    $q->execute([$record['id']]);
    $versions = $q->fetchAll();
    if (!$versions) throw new RuntimeException('该记录暂无版本数据。');

    $selectedVersion = (int)($_GET['version'] ?? $versions[0]['version_no']);
    $current = $versions[0];
    foreach ($versions as $version) {
        if ((int)$version['version_no'] === $selectedVersion) $current = $version;
    }

    $payload = stripPlatformInternalFields(json_decode($current['payload'], true) ?: []);
    $display = json_decode($plugin['display_config'] ?? '', true) ?: [];
    $fields = array_values(array_filter(
        array_map('strval', $display['fields'] ?? array_values(array_filter(array_keys($payload), fn($key) => !in_array($key, ['_id', 'appId', 'entryId'], true)))),
        fn($field) => !isPlatformInternalField((string)$field)
    ));
    $subtableColumnsConfig = is_array($display['subtable_columns'] ?? null) ? $display['subtable_columns'] : [];

    $previous = null;
    foreach ($versions as $version) {
        if ((int)$version['version_no'] === ((int)$current['version_no'] - 1)) {
            $previous = stripPlatformInternalFields(json_decode($version['payload'], true) ?: []);
            break;
        }
    }

    $scalarFields = [];
    $tableFields = [];
    foreach ($fields as $field) {
        if (!array_key_exists((string)$field, $payload)) continue;
        if (isRows($payload[$field])) $tableFields[] = (string)$field;
        else $scalarFields[] = (string)$field;
    }
    if ($previous) {
        foreach ($fields as $field) {
            $field = (string)$field;
            if (array_key_exists($field, $payload) || !array_key_exists($field, $previous)) continue;
            if (isRows($previous[$field])) {
                if (!in_array($field, $tableFields, true)) $tableFields[] = $field;
            } elseif (!in_array($field, $scalarFields, true)) {
                $scalarFields[] = $field;
            }
        }
    }

    $changes = [];
    $scalarFieldStates = [];
    $tableModels = [];
    $tableDiffs = [];
    foreach ($scalarFields as $field) {
        $oldExists = is_array($previous) && array_key_exists($field, $previous);
        $newExists = array_key_exists($field, $payload);
        $type = 'normal';
        if ($previous) {
            if (!$oldExists && $newExists) $type = 'added';
            elseif ($oldExists && !$newExists) $type = 'removed';
            elseif (changed($previous[$field] ?? null, $payload[$field] ?? null)) $type = 'changed';
        }
        $scalarFieldStates[$field] = $type;
    }
    if ($previous) {
        foreach ($fields as $field) {
            $field = (string)$field;
            if (in_array($field, $tableFields, true)) continue;
            if (changed($previous[$field] ?? null, $payload[$field] ?? null)) {
                $changes[] = ['field' => $field, 'old' => $previous[$field] ?? null, 'new' => $payload[$field] ?? null];
            }
        }
    }
    foreach ($tableFields as $field) {
        $model = buildTableModel($plugin, $field, is_array($payload[$field] ?? null) ? $payload[$field] : [], is_array($previous[$field] ?? null) ? $previous[$field] : [], $subtableColumnsConfig[$field] ?? []);
        $tableModels[$field] = $model;
        array_push($tableDiffs, ...$model['diffs']);
    }
} catch (Throwable $e) {
    $message = $e->getMessage();
    $isParameterError = str_starts_with($message, '缺少 ') || str_starts_with($message, 'Token 无效');
    // 这是可渲染的业务提示页，不是资源不存在；尤其在简道云内嵌场景中必须返回 200，
    // 否则宿主会把参数错误当作 404 页面拦截掉。
    http_response_code(200);
    exit('<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>版本数据暂不可用</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f4f7fb;color:#172033;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC",sans-serif;box-sizing:border-box}.error-card{width:min(460px,100%);padding:32px;border:1px solid #e3e9f2;border-radius:16px;background:#fff;box-shadow:0 18px 50px rgba(25,42,70,.09)}.error-mark{width:36px;height:36px;display:grid;place-items:center;border-radius:50%;background:#fff4e5;color:#c56a00;font-size:20px;font-weight:800}.error-card h1{margin:16px 0 8px;font-size:22px}.error-card p{margin:0;color:#667085}.error-card .tip{margin-top:20px;padding:12px 14px;border-radius:10px;background:#f7f9fc;color:#7a8598;font-size:13px}</style></head><body><main class="error-card"><div class="error-mark">!</div><h1>' . ($isParameterError ? '当前链接参数错误' : '暂无版本数据') . '</h1><p>' . h($message) . '</p><div class="tip">请确认链接中包含有效的 token 与 serial_no 参数，然后重新打开。</div></main></body></html>');
}
$assetVersion = file_exists(__DIR__ . '/style.css') ? (string)filemtime(__DIR__ . '/style.css') : (string)time();
$flash = takeFlash();
$baseUrl = currentBaseUrl($config);
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($plugin['name']) ?> · Dingdanduo.net</title>
    <link rel="stylesheet" href="style.css?v=<?= h($assetVersion) ?>">
    <style>
        :root{--ink:#1e293b;--muted:#667085;--line:#e6ebf2;--blue:#356dff;--bg:#f5f7fb}*{box-sizing:border-box}body{margin:0;color:var(--ink);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC",system-ui,sans-serif}.eyebrow{color:var(--blue);font-size:11px;font-weight:800;letter-spacing:.09em}.status{padding:6px 10px;border-radius:999px;background:#edf4ff;color:#3262bc;font-size:12px}.history-body{height:100vh;overflow:hidden;background:#eef2f7}.history-app{height:100vh;display:flex;flex-direction:column;min-width:0}.history-header{height:106px;flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:16px 28px;background:#fff;border-bottom:1px solid var(--line)}.history-brand{display:flex;align-items:center;gap:10px;margin-bottom:5px}.history-brand-icon{width:32px;height:32px;border-radius:8px;background:#172033;color:#fff;display:grid;place-items:center;font-weight:900}.history-brand strong{display:block;font-size:16px;line-height:1.1}.history-brand span{display:block;color:#667085;font-size:12px;font-weight:650}.history-header h1{margin:3px 0 2px;font-size:24px}.history-header p{margin:0;color:var(--muted)}.history-meta{display:flex;align-items:center;gap:12px;color:#667085;white-space:nowrap}.history-meta strong{font-size:18px;color:var(--ink)}.history-board{flex:1;min-height:0;display:grid;grid-template-columns:280px minmax(520px,1fr) 360px;gap:0}.version-panel{min-height:0;overflow:auto;background:#fff;border-right:1px solid var(--line);padding:18px 14px}.version-panel h2{font-size:18px;margin:4px 8px 14px}.version-item{display:grid;grid-template-columns:auto 1fr;gap:4px 10px;padding:13px 12px;border-radius:10px;color:var(--ink);text-decoration:none}.version-item:hover{background:#f6f8fc}.version-item.active{background:#eaf1ff;color:#225ce5}.version-no{font-weight:800}.version-time{color:#667085;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.version-item em{grid-column:1/-1;font-style:normal;color:#8a94a6;font-size:12px}.record-panel{min-width:0;overflow:auto;padding:22px;background:#f7f9fc}.panel-title-row{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}.panel-title-row h2{margin:0 0 4px;font-size:22px}.panel-title-row p{margin:0;color:var(--muted)}.table-card{background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 28px #23385e0a;overflow:hidden;margin-bottom:18px}.table-caption{display:flex;justify-content:space-between;gap:10px;padding:13px 16px;background:#f4f7fb;border-bottom:1px solid var(--line);font-weight:800}.table-caption span{color:#8a94a6;font-weight:700}.table-scroll{overflow:auto}.data-table{width:100%;border-collapse:collapse}.data-table th,.data-table td{padding:13px 15px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top;overflow-wrap:anywhere}.data-table th{width:34%;background:#fafbfc}.child-table{min-width:720px}.child-table th{width:auto}.change-panel{min-width:0;overflow:auto;background:#fff;border-left:1px solid var(--line);padding:22px 18px}.change-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}.change-head h2{margin:0;font-size:20px}.change-head span{color:#8a94a6;font-weight:750}.change-item{padding:14px;border:1px solid var(--line);border-radius:12px;background:#fff;box-shadow:0 10px 20px #23385e0a;margin-bottom:12px}.change-item strong{display:block;margin-bottom:10px;color:#344054;overflow-wrap:anywhere}.change-values{display:grid;grid-template-columns:1fr auto 1fr;gap:8px;align-items:center}.old,.new{overflow-wrap:anywhere}.old{color:#c62828;text-decoration:line-through}.new{color:#168246;font-weight:750}.arrow{color:#98a2b3}.empty-change{padding:18px;border:1px dashed #d6deea;border-radius:12px;color:#667085;background:#fafcff}@media(max-width:1180px){.history-board{grid-template-columns:220px minmax(0,1fr)}.change-panel{grid-column:2;min-height:220px;border-left:0;border-top:1px solid var(--line)}}@media(max-width:900px){.history-body{height:auto;overflow:auto}.history-app{height:auto;min-height:100vh}.history-header{height:auto;align-items:flex-start;flex-direction:column}.history-board{display:block}.version-panel{border-right:0;border-bottom:1px solid var(--line);white-space:nowrap;overflow-x:auto}.version-panel h2{display:none}.version-item{display:inline-grid;width:190px;margin-right:8px;vertical-align:top}.record-panel,.change-panel{overflow:visible}.change-values{grid-template-columns:1fr}.arrow{display:none}}@media(max-width:560px){.history-header{padding:18px}.history-meta{flex-wrap:wrap}.record-panel,.change-panel{padding:14px}.data-table th,.data-table td{display:block;width:100%;padding:10px 12px}.data-table th{border-bottom:0}.data-table td{border-bottom:1px solid var(--line)}}
        .legend{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin:10px 0 0;color:#667085;font-size:12px;font-weight:750}.legend-item{display:inline-flex;align-items:center;gap:6px}.legend-dot{width:14px;height:14px;border-radius:4px;border:1px solid transparent}.legend-dot.changed{background:#fff7e6;border-color:#f3d58b}.legend-dot.added{background:#ecfdf3;border-color:#b7ebc6}.legend-dot.removed{background:#fff1f1;border-color:#ffc9c9}.main-field-changed th,.main-field-changed td{background:#fff7e6!important;box-shadow:inset 0 0 0 1px #f3d58b}.main-field-added th,.main-field-added td{background:#ecfdf3!important;color:#137a3a;box-shadow:inset 0 0 0 1px #b7ebc6}.main-field-removed th,.main-field-removed td{background:#fff1f1!important;color:#b42318;text-decoration:line-through;box-shadow:inset 0 0 0 1px #ffc9c9}.seq-col{width:64px!important;min-width:64px;text-align:center!important;color:#667085;background:#f8fafc!important}.cell-changed{background:#fff7e6!important;box-shadow:inset 0 0 0 1px #f3d58b}.cell-added{background:#ecfdf3!important;color:#137a3a;box-shadow:inset 0 0 0 1px #b7ebc6}.cell-removed{background:#fff1f1!important;color:#b42318;text-decoration:line-through;box-shadow:inset 0 0 0 1px #ffc9c9}.row-removed td{opacity:.82}.change-section{margin-bottom:18px}.change-section-title{display:flex;justify-content:space-between;gap:12px;margin:12px 0 10px;font-size:14px;font-weight:800;color:#1f2937}.change-section-title span{color:#8a94a6;font-weight:700}
        .table-caption-actions{align-items:center}.table-caption-actions>span{display:flex;align-items:center;gap:8px}.table-caption-actions em{font-style:normal;color:#8a94a6;font-weight:700}.expand-table-btn,.close-table-btn{border:0;background:#fff;color:#667085;cursor:pointer}.expand-table-btn{width:30px;height:30px;border-radius:7px;border:1px solid #dfe6f1;font-size:18px;line-height:1}.expand-table-btn:hover{color:#225ce5;border-color:#b9c8ff;background:#f7faff}.subtable-modal{position:fixed;inset:0;z-index:1000;display:none;background:rgba(15,23,42,.42);padding:22px}.subtable-modal.open{display:block}.subtable-modal-card{height:100%;display:flex;flex-direction:column;background:#fff;border-radius:12px;box-shadow:0 24px 70px rgba(15,23,42,.22);overflow:hidden}.subtable-modal-head{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 20px;border-bottom:1px solid var(--line);background:#f8fafc}.subtable-modal-head h2{margin:0;font-size:20px}.subtable-modal-head p{margin:3px 0 0;color:#667085}.close-table-btn{width:36px;height:36px;border-radius:8px;font-size:28px;line-height:1}.close-table-btn:hover{background:#eef2f7;color:#111827}.subtable-modal-body{flex:1;min-height:0;overflow:auto;padding:16px;background:#f3f6fb}.modal-child-table{min-width:max-content;background:#fff}.modal-child-table th{position:sticky;top:0;z-index:2}.modal-child-table .seq-col{position:sticky;left:0;z-index:3}.modal-child-table td.seq-col{z-index:1}.modal-child-table th,.modal-child-table td{white-space:nowrap}.modal-open{overflow:hidden}
        .expand-table-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:0!important;border-radius:50%;background:transparent!important;color:#8a94a6;font-size:17px;font-weight:500;transition:background .15s,color .15s,transform .15s}.expand-table-btn:hover{background:#eef4ff!important;color:#356dff;transform:translateY(-1px)}.subtable-modal{padding:18px;background:rgba(15,23,42,.35)}.subtable-modal-card{border-radius:10px}.subtable-modal-head{padding:14px 18px;background:#fff}.close-table-btn{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border:0!important;border-radius:50%;background:transparent!important;color:#8a94a6;font-size:26px;font-weight:300;transition:background .15s,color .15s}.close-table-btn:hover{background:#f1f5f9!important;color:#1f2937}
        .subtable-modal-body{padding:12px;background:#f3f6fb;overflow:hidden}.subtable-scroll-frame{height:100%;overflow:auto;background:#fff;border:1px solid var(--line);border-radius:8px;position:relative}.modal-child-table{border-collapse:separate!important;border-spacing:0;background:#fff}.modal-child-table th,.modal-child-table td{background:#fff;border-right:1px solid var(--line)}.modal-child-table th{top:0;background:#f8fafc!important;z-index:5;box-shadow:0 1px 0 var(--line),0 4px 10px rgba(15,23,42,.04)}.modal-child-table .seq-col{left:0;background:#f8fafc!important;z-index:6;box-shadow:1px 0 0 var(--line),4px 0 10px rgba(15,23,42,.04)}.modal-child-table th.seq-col{z-index:8}.modal-child-table td.seq-col{background:#fff!important;z-index:4}.modal-child-table td.cell-changed,.modal-child-table td.cell-added,.modal-child-table td.cell-removed{background-clip:padding-box}
        .history-header{height:auto!important;min-height:112px!important;padding:12px 28px 14px!important;align-items:flex-start!important;overflow:visible!important}.history-header>div:first-child{min-width:0}.history-brand{margin-bottom:4px!important}.history-brand-icon{width:28px!important;height:28px!important;border-radius:8px!important;font-size:15px!important}.history-brand strong{font-size:15px!important}.history-brand span{font-size:11px!important}.history-header .eyebrow{display:block;margin-top:2px;font-size:10px!important;line-height:1.2}.history-header h1{margin:5px 0 0!important;font-size:22px!important;line-height:1.15}.history-header p{margin-top:3px!important;line-height:1.2}.history-meta{padding-top:4px;flex-wrap:wrap;justify-content:flex-end}
        .history-header{display:grid!important;grid-template-columns:minmax(240px,auto) minmax(0,1fr) auto!important;align-items:center!important;gap:26px!important;min-height:78px!important;padding:14px 28px!important}.history-brand{margin:0!important}.history-brand-icon{width:42px!important;height:42px!important;border-radius:10px!important;font-size:18px!important}.history-brand strong{font-size:22px!important;line-height:1.05!important}.history-brand span{font-size:12px!important;margin-top:3px!important}.history-heading{min-width:0;border-left:1px solid var(--line);padding-left:24px}.history-heading .eyebrow{margin:0!important;font-size:11px!important}.history-heading h1{margin:3px 0 2px!important;font-size:24px!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.history-heading p{margin:0!important}.history-meta{padding:0!important;align-items:center!important;justify-content:flex-end!important}@media(max-width:900px){.history-header{grid-template-columns:1fr!important;align-items:start!important}.history-heading{border-left:0;padding-left:0}.history-meta{justify-content:flex-start!important}}
        .history-meta form{margin:0}.rollback-button{margin:0;padding:8px 12px;border:0;border-radius:8px;background:#172033;color:#fff;font-weight:800;cursor:pointer}.rollback-button:hover{background:#2b364a}.history-notice{margin:0;padding:10px 28px;border-bottom:1px solid #cdebd8;background:#ecfdf3;color:#137a3a;font-weight:700}.history-notice.error{background:#fff1f1;border-bottom-color:#ffd1d1;color:#b42318}
        .history-header{grid-template-columns:minmax(0,1fr) auto!important;min-height:72px!important;gap:18px!important}.history-brand{display:none!important}.history-heading{border-left:0!important;padding-left:0!important}.history-heading h1{font-size:24px!important}.history-meta{flex-wrap:wrap!important;row-gap:8px!important}
        @media(min-width:761px){.history-body{height:100vh!important;overflow:hidden!important}.history-app{height:100vh!important;min-height:0!important}.history-board{flex:1!important;min-height:0!important;overflow:hidden!important}.version-panel,.record-panel,.change-panel{height:100%!important;min-height:0!important;min-width:0!important;overflow:auto!important}.record-panel{padding:20px!important}.change-panel{grid-column:auto!important;border-left:1px solid var(--line)!important;border-top:0!important;padding:20px 22px!important}.panel-title-row{align-items:center!important}.table-card{margin-bottom:14px!important}}
        @media(min-width:761px) and (max-width:1280px){.history-header{position:relative;z-index:20;background:#fff;grid-template-columns:minmax(0,1fr) auto!important;padding:12px 22px!important}.history-board{display:grid!important;grid-template-columns:220px minmax(0,1fr) 300px!important}.change-panel{grid-column:auto!important;border-left:1px solid var(--line)!important;border-top:0!important}}
        @media(max-width:760px){.history-header{grid-template-columns:1fr!important;align-items:start!important}.history-heading h1{white-space:normal!important;font-size:22px!important}.history-meta{justify-content:flex-start!important}.history-board{display:block!important}.version-panel{border-right:0!important;border-bottom:1px solid var(--line)!important;white-space:nowrap!important;overflow-x:auto!important;padding:12px!important}.version-panel h2{display:none!important}.version-item{display:inline-grid!important;width:188px!important;margin-right:8px!important;vertical-align:top!important}.record-panel,.change-panel{padding:16px!important}.panel-title-row{display:block!important}.panel-title-row .status{display:inline-flex;margin-top:10px}.change-values{grid-template-columns:1fr!important}.arrow{display:none!important}}
        .rollback-modal{position:fixed;inset:0;z-index:2000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(15,23,42,.30);backdrop-filter:blur(2px)}.rollback-modal.visible{display:flex}.rollback-modal-card{width:min(320px,calc(100vw - 36px));background:#fff;border:1px solid rgba(226,232,240,.95);border-radius:12px;box-shadow:0 18px 50px rgba(15,23,42,.18);padding:18px}.rollback-modal-title{display:flex;align-items:center;gap:8px;margin:0 0 8px;font-size:18px!important;font-weight:850;color:#172033;letter-spacing:-.02em;line-height:1.25}.rollback-modal-title:before{content:"";display:block;width:7px;height:7px;border-radius:50%;background:#17a35b;flex:0 0 auto}.rollback-modal.error .rollback-modal-title:before{background:#d92d20}.rollback-modal-message{margin:0;color:#667085;font-size:13px!important;line-height:1.55}.rollback-modal-actions{display:flex;justify-content:flex-end;align-items:center;gap:8px;margin-top:16px}.rollback-modal .modal-button{display:inline-flex!important;align-items:center;justify-content:center;width:auto!important;height:30px!important;min-height:30px!important;margin:0!important;padding:0 12px!important;border-radius:7px!important;border:1px solid #d6deea!important;background:#fff!important;color:#172033!important;text-decoration:none!important;font-size:12px!important;font-weight:700!important;line-height:1!important;box-shadow:none!important;cursor:pointer;transition:background .15s,border-color .15s,box-shadow .15s}.rollback-modal .modal-button:hover{background:#f8fafc!important;border-color:#c8d2e0!important}.rollback-modal .modal-button.primary{height:30px!important;min-height:30px!important;padding:0 13px!important;border-color:#356dff!important;background:#356dff!important;color:#fff!important;box-shadow:0 4px 10px rgba(53,109,255,.14)!important}.rollback-modal .modal-button.primary:hover{background:#285ff0!important;border-color:#285ff0!important}.rollback-modal.error .modal-button.primary{display:none!important}
    </style>
</head>
<body class="history-body">
<main class="history-app">
    <header class="history-header">
        <div class="history-heading">
            <span class="eyebrow">VERSION HISTORY</span>
            <h1><?= h($plugin['name']) ?></h1>
            <p>流水号：<?= h($serial) ?></p>
        </div>
        <div class="history-meta">
            <strong>v<?= h($current['version_no']) ?></strong>
            <span><?= h($current['created_at']) ?></span>
            <span><?= count($versions) ?> 个版本</span>
            <?php if (!empty($plugin['fillback_enabled'])): ?>
                <form method="post" action="<?= h($config['rollback_url'] ?? ($baseUrl . '/rollback.php')) ?>" onsubmit="return rollbackVersion(event, this)">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <input type="hidden" name="serial_no" value="<?= h($serial) ?>">
                    <input type="hidden" name="version" value="<?= h($current['version_no']) ?>">
                    <input type="hidden" name="csrf_token" value="<?= h(rollbackToken($config, $token, $serial, (int)$current['version_no'])) ?>">
                    <button class="rollback-button" type="submit">回滚到此版本</button>
                </form>
            <?php endif; ?>
        </div>
    </header>
    <?php if ($flash): ?><div class="history-notice <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div><?php endif; ?>

    <section class="history-board">
        <aside class="version-panel">
            <h2>版本历史</h2>
            <?php foreach ($versions as $version): ?>
                <a class="version-item <?= (int)$version['version_no'] === (int)$current['version_no'] ? 'active' : '' ?>" href="?token=<?= urlencode($token) ?>&serial_no=<?= urlencode($serial) ?>&version=<?= h($version['version_no']) ?>">
                    <span class="version-no">v<?= h($version['version_no']) ?></span>
                    <span class="version-time"><?= h($version['created_at']) ?></span>
                    <em><?= h(versionSourceText((string)$version['source'])) ?> · <?= h(versionUpdaterName($version)) ?></em>
                </a>
            <?php endforeach; ?>
        </aside>

        <section class="record-panel">
            <div class="panel-title-row">
                <div>
                    <h2>数据表格</h2>
                    <p>当前版本数据内容</p>
                </div>
                <div>
                    <span class="status"><?= count($changes) + count($tableDiffs) ?> 项变化</span>
                    <div class="legend" aria-label="变化颜色图例">
                        <span class="legend-item"><i class="legend-dot changed"></i>修改</span>
                        <span class="legend-item"><i class="legend-dot added"></i>新增</span>
                        <span class="legend-item"><i class="legend-dot removed"></i>删除</span>
                    </div>
                </div>
            </div>

            <?php if ($scalarFields): ?>
                <div class="table-card">
                    <div class="table-caption">主表字段</div>
                    <table class="data-table">
                        <tbody>
                        <?php foreach ($scalarFields as $field): ?>
                            <tr class="main-field-<?= h($scalarFieldStates[$field] ?? 'normal') ?>">
                                <th><?= h(fieldHead($plugin, $field)) ?></th>
                                <td><?= h(displayText(array_key_exists($field, $payload) ? $payload[$field] : ($previous[$field] ?? null), $field)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php foreach ($tableFields as $field): $model = $tableModels[$field] ?? buildTableModel($plugin, $field, $payload[$field] ?? [], [], $subtableColumnsConfig[$field] ?? []); $modalId = 'subtable_' . md5($field); ?>
                <div class="table-card">
                    <div class="table-caption table-caption-actions">
                        <span><?= h(fieldHead($plugin, $field)) ?> <em><?= count($model['rows']) ?> 行</em></span>
                        <button class="expand-table-btn" type="button" onclick="openSubtableModal('<?= h($modalId) ?>')" title="放大查看">↗</button>
                    </div>
                    <div class="table-scroll">
                        <table class="data-table child-table">
                            <thead><tr><th class="seq-col">序号</th><?php foreach ($model['columns'] as $column): ?><th><?= h(fieldHead($plugin, $column)) ?></th><?php endforeach; ?></tr></thead>
                            <tbody>
                            <?php foreach ($model['rows'] as $row): ?>
                                <tr class="row-<?= h($row['type']) ?>">
                                    <td class="seq-col"><?= h($row['seq']) ?></td>
                                    <?php foreach ($model['columns'] as $column): $cell = $row['cells'][$column]; ?>
                                        <td class="cell-<?= h($cell['type']) ?>"><?= h(displayText($cell['value'], $column)) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="subtable-modal" id="<?= h($modalId) ?>" aria-hidden="true">
                    <div class="subtable-modal-card" role="dialog" aria-modal="true" aria-label="<?= h(fieldHead($plugin, $field)) ?>">
                        <div class="subtable-modal-head">
                            <div>
                                <h2><?= h(fieldHead($plugin, $field)) ?></h2>
                                <p><?= count($model['rows']) ?> 行 · <?= count($model['columns']) ?> 列</p>
                            </div>
                            <button class="close-table-btn" type="button" onclick="closeSubtableModal('<?= h($modalId) ?>')" title="关闭">×</button>
                        </div>
                        <div class="subtable-modal-body">
                            <div class="subtable-scroll-frame">
                            <table class="data-table child-table modal-child-table">
                                <thead><tr><th class="seq-col">序号</th><?php foreach ($model['columns'] as $column): ?><th><?= h(fieldHead($plugin, $column)) ?></th><?php endforeach; ?></tr></thead>
                                <tbody>
                                <?php foreach ($model['rows'] as $row): ?>
                                    <tr class="row-<?= h($row['type']) ?>">
                                        <td class="seq-col"><?= h($row['seq']) ?></td>
                                        <?php foreach ($model['columns'] as $column): $cell = $row['cells'][$column]; ?>
                                            <td class="cell-<?= h($cell['type']) ?>"><?= h(displayText($cell['value'], $column)) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <aside class="change-panel">
            <div class="change-head">
                <h2>变更内容</h2>
                <span><?= count($changes) + count($tableDiffs) ?> 项</span>
            </div>
            <?php if (!$changes && !$tableDiffs): ?>
                <div class="empty-change">该版本与上一版无展示字段变化。</div>
            <?php else: ?>
                <?php if ($tableDiffs): ?>
                    <section class="change-section">
                        <div class="change-section-title">表格变更 <span><?= count($tableDiffs) ?> 处</span></div>
                        <?php foreach ($tableDiffs as $change): ?>
                            <div class="change-item">
                                <strong><?= h($change['table']) ?> · 第 <?= h($change['row']) ?> 行 · <?= h($change['field']) ?></strong>
                                <div class="change-values">
                                    <span class="old"><?= h(compactChangeValue($change['old'], $change['field_key'] ?? null)) ?></span>
                                    <span class="arrow">→</span>
                                    <span class="new"><?= h(compactChangeValue($change['new'], $change['field_key'] ?? null)) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>
                <?php if ($changes): ?>
                    <section class="change-section">
                        <div class="change-section-title">其他状态变更 <span><?= count($changes) ?> 项</span></div>
                <?php foreach ($changes as $change): ?>
                    <div class="change-item">
                        <strong><?= h(fieldHead($plugin, $change['field'])) ?></strong>
                        <div class="change-values">
                            <span class="old"><?= h(compactChangeValue($change['old'], $change['field'])) ?></span>
                            <span class="arrow">→</span>
                            <span class="new"><?= h(compactChangeValue($change['new'], $change['field'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </aside>
    </section>
</main>
<div class="rollback-modal" id="rollbackModal" role="dialog" aria-modal="true" aria-labelledby="rollbackModalTitle">
    <div class="rollback-modal-card">
        <h2 class="rollback-modal-title" id="rollbackModalTitle"></h2>
        <p class="rollback-modal-message" id="rollbackModalMessage"></p>
        <div class="rollback-modal-actions">
            <button class="modal-button" type="button" onclick="closeRollbackModal()">关闭</button>
            <a class="modal-button primary" id="rollbackModalView" href="#" style="display:none">查看新版本</a>
        </div>
    </div>
</div>
<script>
const rollbackModal = document.getElementById('rollbackModal');
const rollbackModalTitle = document.getElementById('rollbackModalTitle');
const rollbackModalMessage = document.getElementById('rollbackModalMessage');
const rollbackModalView = document.getElementById('rollbackModalView');
function closeRollbackModal() {
    rollbackModal?.classList.remove('visible');
}
function showRollbackModal(title, message, viewUrl = '') {
    if (!rollbackModal) return;
    rollbackModalTitle.textContent = title;
    rollbackModalMessage.textContent = message;
    rollbackModal.classList.toggle('error', !viewUrl);
    if (viewUrl) {
        rollbackModalView.href = viewUrl;
        rollbackModalView.style.display = '';
    } else {
        rollbackModalView.style.display = 'none';
    }
    rollbackModal.classList.add('visible');
}
async function rollbackVersion(event, form) {
    event.preventDefault();
    if (!confirm('确认将当前记录回滚到选定版本吗？该操作会立即覆盖简道云数据。')) return false;
    const button = form.querySelector('button[type="submit"],button[name="action"]');
    const oldText = button ? button.textContent : '';
    if (button) {
        button.disabled = true;
        button.textContent = '正在回滚…';
    }
    try {
        const endpoint = new URL(form.getAttribute('action'), window.location.href).toString();
        const response = await fetch(endpoint, {
            method: 'POST',
            body: new FormData(form),
            cache: 'no-store',
            headers: { 'Accept': 'application/json' },
        });
        const raw = await response.text();
        let result = null;
        try {
            result = JSON.parse(raw);
        } catch (parseError) {
            const plain = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            throw new Error(plain ? `回滚接口未返回 JSON：HTTP ${response.status} · ${response.url || endpoint} · ${plain.slice(0, 160)}` : `回滚接口未返回 JSON：HTTP ${response.status} · ${response.url || endpoint}`);
        }
        if (!response.ok || !result.ok) throw new Error(result.message || '回滚失败');
        const viewUrl = new URL('history.php', window.location.href);
        viewUrl.searchParams.set('token', form.querySelector('[name="token"]').value);
        viewUrl.searchParams.set('serial_no', form.querySelector('[name="serial_no"]').value);
        viewUrl.searchParams.set('version', result.version);
        showRollbackModal('回滚成功', `简道云已确认写入，并已保存为本地 v${result.version}。`, viewUrl.toString());
    } catch (error) {
        showRollbackModal('回滚失败', error.message || '请求失败，请稍后重试。');
    } finally {
        if (button) {
            button.disabled = false;
            button.textContent = oldText;
        }
    }
    return false;
}
function openSubtableModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
}
function closeSubtableModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
}
document.addEventListener('click', event => {
    if (event.target.classList && event.target.classList.contains('subtable-modal')) {
        closeSubtableModal(event.target.id);
    }
    if (event.target === rollbackModal) closeRollbackModal();
});
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    closeRollbackModal();
    document.querySelectorAll('.subtable-modal.open').forEach(modal => closeSubtableModal(modal.id));
});
</script>
</body>
</html>
