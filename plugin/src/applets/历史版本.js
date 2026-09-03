var HISTORY_BASE_URL = 'https://your-domain.example/history.php';
var SERIAL_WIDGET = '_widget_17841878809131';
var TOKEN_WIDGET = '_widget_17841879265671';

function unwrapValue(value) {
  if (value === undefined || value === null) return '';
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') return value;
  if (Array.isArray(value)) return value.map(unwrapValue).filter(Boolean).join(',');
  if (typeof value === 'object') {
    if (value.value !== undefined) return unwrapValue(value.value);
    if (value.name !== undefined) return unwrapValue(value.name);
    if (value.text !== undefined) return unwrapValue(value.text);
    if (value.label !== undefined) return unwrapValue(value.label);
  }
  return String(value);
}

function getTriggerValue(widgetId) {
  try {
    if (typeof triggerConf !== 'undefined' && triggerConf && triggerConf[widgetId] !== undefined) {
      return unwrapValue(triggerConf[widgetId]);
    }
  } catch (e) {}
  return '';
}

var serialNo = String(getTriggerValue(SERIAL_WIDGET)).trim();
var token = String(getTriggerValue(TOKEN_WIDGET)).trim();

if (!serialNo || !token) {
  $g.utils.openModal({
    title: '历史版本查看',
    url: 'data:text/html;charset=utf-8,' + encodeURIComponent(
      '<!doctype html><meta charset="utf-8"><div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,PingFang SC,Microsoft YaHei,sans-serif;padding:28px;color:#172033"><h2 style="margin:0 0 12px">缺少参数</h2><p style="margin:0;color:#667085;line-height:1.7">请检查流水号和 Token 字段是否已传入前端扩展。</p></div>'
    )
  });
} else {
  var url = HISTORY_BASE_URL + '?token=' + encodeURIComponent(token) + '&serial_no=' + encodeURIComponent(serialNo);
  $g.utils.openModal({
    title: '历史版本查看',
    url: url
  });
}
