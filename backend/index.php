<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
try { $db = pdo($config); ensureRuntimeSchema($db); } catch (Throwable $e) { exit('<meta charset="utf-8"><h2>数据库尚未初始化</h2><p>请先访问 <a href="init.php">init.php</a>。</p>'); }
try {
    $action = $_POST['action'] ?? '';
    if ($action === 'register') {
        verifyCsrf();
        $db->prepare('INSERT INTO users(username,email,password_hash,created_at) VALUES(?,?,?,NOW())')->execute([trim($_POST['username']), trim($_POST['email']), password_hash($_POST['password'], PASSWORD_DEFAULT)]);
        flash('ok', '注册成功，请登录。');
    } elseif ($action === 'login') {
        verifyCsrf(); $q = $db->prepare('SELECT * FROM users WHERE email=?'); $q->execute([trim($_POST['email'])]); $account = $q->fetch();
        if (!$account || !password_verify($_POST['password'], $account['password_hash'])) throw new RuntimeException('邮箱或密码错误。');
        $_SESSION['user'] = ['id' => $account['id'], 'username' => $account['username']];
    } elseif ($action === 'logout') { session_destroy(); header('Location: index.php'); exit;
    } elseif ($action === 'plugin_save') {
        verifyCsrf(); $account = requireUser(); $key = trim($_POST['api_key'] ?? '');
        $db->prepare('INSERT INTO plugins(user_id,name,app_id,entry_id,serial_field,api_key_cipher,pull_enabled,fillback_enabled,receiver_token,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,NOW(),NOW())')->execute([
            $account['id'], trim($_POST['name']), '', '', 'auto',
            $key === '' ? null : encryptSecret($key, $config), !empty($_POST['pull_enabled']) ? 1 : 0, !empty($_POST['fillback_enabled']) ? 1 : 0, bin2hex(random_bytes(24)),
        ]);
        flash('ok', '插件已创建。');
    } elseif ($action === 'test_push') {
        verifyCsrf(); $account = requireUser(); $q = $db->prepare('SELECT * FROM plugins WHERE id=? AND user_id=?'); $q->execute([(int)$_POST['id'], $account['id']]); $plugin = $q->fetch();
        $serial = trim($_POST['serial_no']); if (!$plugin || $serial === '') throw new RuntimeException('插件或流水号无效。');
        $testData = ['_id' => 'test_' . bin2hex(random_bytes(10)), 'serial_no' => $serial, 'updateTime' => date('c'), 'source' => 'manual_test'];
        if ($plugin['serial_field'] !== 'auto') $testData[$plugin['serial_field']] = $serial;
        $saved = saveIncoming($db, $plugin, $testData, 'test');
        flash('ok', '模拟推送成功：已保存 ' . $saved['serial_no'] . ' 的 v' . $saved['version']);
    } elseif ($action === 'plugin_delete') {
        verifyCsrf(); $account = requireUser(); $db->prepare('DELETE FROM plugins WHERE id=? AND user_id=?')->execute([(int)$_POST['id'], $account['id']]); flash('ok', '插件已删除。');
    }
} catch (Throwable $e) { flash('error', $e->getMessage()); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Location: index.php'); exit; }
$account = user(); $plugins = [];
if ($account) {
    $q = $db->prepare('
        SELECT
            p.*,
            COUNT(DISTINCT r.id) serial_count,
            COUNT(rv.id) version_count
        FROM plugins p
        LEFT JOIN records r ON r.plugin_id=p.id
        LEFT JOIN record_versions rv ON rv.record_id=r.id
        WHERE p.user_id=?
        GROUP BY p.id
        ORDER BY p.id DESC
    ');
    $q->execute([$account['id']]);
    $plugins = $q->fetchAll();
}
$flash = takeFlash();
$authMode = ($_GET['auth'] ?? 'login') === 'register' ? 'register' : 'login';
$baseUrl = currentBaseUrl($config);
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dingdanduo.net · 语落订单多应用平台</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body{background:#f4f7fb;color:#172033}.app-page{max-width:1240px;margin:0 auto;padding:34px 28px 64px}.app-top{display:grid;grid-template-columns:minmax(240px,auto) minmax(0,1fr) auto;align-items:center;gap:26px;padding-bottom:22px;border-bottom:1px solid #dfe6f1}.brand-mark{display:flex;align-items:center;gap:12px;min-width:0}.brand-icon{width:42px;height:42px;border-radius:10px;background:#172033;color:#fff;display:grid;place-items:center;font-weight:900;font-size:18px;flex:0 0 auto}.brand-name{font-size:22px;font-weight:850;letter-spacing:0;line-height:1.05}.brand-subtitle{margin-top:3px;color:#667085;font-weight:650;font-size:12px}.page-heading{min-width:0;border-left:1px solid #dfe6f1;padding-left:24px}.page-heading .eyebrow{display:block;color:#356dff;font-size:11px;font-weight:850;letter-spacing:.08em}.page-heading h1{margin:3px 0 2px;font-size:26px;line-height:1.15}.page-heading p{margin:0;color:#667085}.app-actions{display:flex;justify-content:flex-end;align-items:center}.app-actions form{margin:0}.app-top p{margin:0;color:#667085}.text-link{color:#356dff;text-decoration:none;font-weight:700}.auth-shell{min-height:calc(100vh - 160px);display:grid;place-items:center}.auth-card{width:min(420px,100%);background:#fff;border:1px solid #dfe6f1;border-radius:12px;box-shadow:0 18px 48px rgba(31,41,55,.08);padding:26px}.auth-tabs{display:grid;grid-template-columns:1fr 1fr;padding:4px;background:#f1f5f9;border-radius:9px;margin-bottom:22px}.auth-tabs a{min-height:36px;display:flex;align-items:center;justify-content:center;border-radius:7px;color:#667085;text-decoration:none;font-weight:750}.auth-tabs a.active{background:#fff;color:#172033;box-shadow:0 1px 3px rgba(31,41,55,.08)}.auth-card h2{margin:0 0 4px;font-size:22px}.auth-card p{margin:0 0 18px;color:#667085}.auth-card label{margin-top:13px;font-weight:650;color:#344054}.auth-card button{width:100%;margin-top:20px;border-radius:8px}.notice{max-width:760px}.dashboard-grid{display:grid;grid-template-columns:360px minmax(0,1fr);gap:22px;margin-top:24px}.panel{background:#fff;border:1px solid #dfe6f1;border-radius:12px;box-shadow:0 12px 32px rgba(31,41,55,.05);padding:22px}.panel h2{margin:5px 0 8px}.panel p{color:#667085}.plugin-list{display:grid;gap:14px}.plugin-card{background:#fff;border:1px solid #dfe6f1;border-radius:12px;padding:18px;box-shadow:0 12px 32px rgba(31,41,55,.04)}.plugin-card-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.plugin-card h2{margin:3px 0 0}.url-grid{display:grid;gap:10px;margin:14px 0}.url-box b{display:block;font-size:12px;color:#667085;margin-bottom:5px}.url-box code{display:block;padding:10px 11px;background:#f8fafc;border:1px solid #e6edf6;border-radius:8px;color:#40506a;overflow-wrap:anywhere}.token-copy{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 12px;border:1px solid #dce6f5;border-radius:9px;background:#f8fbff}.token-copy div{min-width:0}.token-copy b{display:block;color:#667085;font-size:12px}.token-copy code{display:block;margin-top:4px;color:#344054;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.token-copy button{flex:0 0 auto;min-height:32px;margin:0;padding:0 11px;border:1px solid #c9d8f2;border-radius:7px;background:#fff;color:#285fc9;font-weight:750;cursor:pointer}.token-copy button:hover{background:#edf4ff}.inline-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px}.inline-actions input{flex:1;min-width:180px;margin:0}.inline-actions button{margin:0}.danger-lite{background:#fff!important;color:#b42318!important;border:1px solid #f1c4c4!important}.empty-panel{text-align:center;padding:48px 18px;color:#667085}@media(max-width:900px){.dashboard-grid{grid-template-columns:1fr}.app-top{grid-template-columns:1fr;align-items:start}.page-heading{border-left:0;padding-left:0}.app-actions{justify-content:flex-start}.auth-shell{place-items:start}.auth-card{margin-top:28px}}
    </style>
</head>
<body>
<main class="app-page">
    <header class="app-top">
        <div class="brand-mark">
            <div class="brand-icon">D</div>
            <div>
                <div class="brand-name">Dingdanduo.net</div>
                <div class="brand-subtitle">语落订单多应用平台</div>
            </div>
        </div>
        <div class="page-heading">
            <span class="eyebrow"><?= $account ? 'PLUGIN WORKBENCH' : 'ACCOUNT ACCESS' ?></span>
            <h1><?= $account ? '插件管理工作台' : '登录 / 注册' ?></h1>
            <p><?= $account ? '管理简道云推送、字段配置和版本历史链接。' : '进入语落订单多应用平台。' ?></p>
        </div>
        <div class="app-actions">
            <?php if ($account): ?>
                <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><button class="ghost" name="action" value="logout">退出 <?= h($account['username']) ?></button></form>
            <?php endif; ?>
        </div>
    </header>
    <?php if ($flash): ?><div class="notice <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div><?php endif; ?>

    <?php if (!$account): ?>
        <section class="auth-shell">
            <form class="auth-card" method="post">
                <nav class="auth-tabs">
                    <a class="<?= $authMode === 'login' ? 'active' : '' ?>" href="index.php?auth=login">登录</a>
                    <a class="<?= $authMode === 'register' ? 'active' : '' ?>" href="index.php?auth=register">注册</a>
                </nav>
                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                <?php if ($authMode === 'register'): ?>
                    <h2>创建管理员账号</h2>
                    <p>首次使用时创建账号，之后进入插件管理工作台。</p>
                    <label>用户名<input name="username" placeholder="显示名称" required></label>
                    <label>邮箱<input name="email" type="email" placeholder="name@company.com" required></label>
                    <label>密码<input name="password" type="password" minlength="8" placeholder="至少 8 位" required></label>
                    <button name="action" value="register">注册</button>
                <?php else: ?>
                    <h2>登录工作台</h2>
                    <p>进入插件管理、字段配置和数据接收设置。</p>
                    <label>邮箱<input name="email" type="email" placeholder="name@company.com" required></label>
                    <label>密码<input name="password" type="password" placeholder="请输入密码" required></label>
                    <button name="action" value="login">登录</button>
                <?php endif; ?>
            </form>
        </section>
    <?php else: ?>
        <section class="dashboard-grid">
            <aside class="panel">
                <span class="pill">PLUGIN BUILDER</span>
                <h2>新建插件</h2>
                <p>创建后把“首次识别推送地址”配置到简道云；第一次推送会记录 App ID、表单 ID 和流水号字段。</p>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <label>插件名称<input name="name" placeholder="例如：资产负债表" required></label>
                    <label>API Key<input name="api_key" placeholder="用于读取表单结构、定时同步或回填"></label>
                    <label class="check"><input type="checkbox" name="pull_enabled"> 启用定时 API 获取</label>
                    <label class="check"><input type="checkbox" name="fillback_enabled"> 需要 API 回填</label>
                    <button name="action" value="plugin_save">创建插件</button>
                </form>
            </aside>
            <section class="plugin-list">
                <?php if (!$plugins): ?>
                    <div class="panel empty-panel">还没有插件。先在左侧创建一个，然后配置简道云推送。</div>
                <?php endif; ?>
                <?php foreach ($plugins as $plugin): $receiveUrl = $baseUrl . '/receive.php?token=' . $plugin['receiver_token']; $detectUrl = $receiveUrl . '&serial_no=0000'; $serialField = $plugin['serial_field'] === 'auto' ? '流水号' : $plugin['serial_field']; $historyUrl = $baseUrl . '/history.php?token=' . $plugin['receiver_token'] . '&serial_no={{' . $serialField . '}}'; ?>
                    <article class="plugin-card">
                        <div class="plugin-card-head">
                            <div><span class="pill">PLUGIN</span><h2><?= h($plugin['name']) ?></h2></div>
                            <span class="status"><?= h($plugin['version_count'] ?? 0) ?> 条数据 · <?= h($plugin['serial_count'] ?? 0) ?> 个流水号 · <?= $plugin['pull_enabled'] ? '定时同步' : '等待推送' ?></span>
                        </div>
                        <div class="url-grid">
                            <div class="url-box"><b>首次识别推送地址（POST，流水号设为 0000 后推送一次）</b><code><?= h($detectUrl) ?></code></div>
                            <div class="url-box"><b>日常数据推送地址（POST）</b><code><?= h($receiveUrl) ?></code></div>
                            <div class="url-box"><b>公开版本查看链接</b><code><?= h($historyUrl) ?></code></div>
                        </div>
                        <div class="token-copy">
                            <div><b>插件 Token</b><code><?= h($plugin['receiver_token']) ?></code></div>
                            <button type="button" data-copy-token="<?= h($plugin['receiver_token']) ?>">复制 Token</button>
                        </div>
                        <p><a class="text-link" href="settings.php?plugin=<?= h($plugin['id']) ?>">配置展示字段</a> · 流水号字段：<?= h($plugin['serial_field'] === 'auto' ? '待首次推送识别' : $plugin['serial_field']) ?></p>
                        <form method="post" class="inline-actions">
                            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                            <input type="hidden" name="id" value="<?= h($plugin['id']) ?>">
                            <input name="serial_no" placeholder="输入流水号，模拟一次推送测试">
                            <button name="action" value="test_push">测试</button>
                        </form>
                        <form method="post" class="inline-actions" onsubmit="return confirm('确定删除插件及其本地记录？')">
                            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                            <input type="hidden" name="id" value="<?= h($plugin['id']) ?>">
                            <button class="danger-lite" name="action" value="plugin_delete">删除插件</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </section>
        </section>
    <?php endif; ?>
</main>
<script>
document.querySelectorAll('[data-copy-token]').forEach(button => {
    button.addEventListener('click', async () => {
        const token = button.dataset.copyToken || '';
        try { await navigator.clipboard.writeText(token); }
        catch (_) {
            const textarea = document.createElement('textarea');
            textarea.value = token; document.body.appendChild(textarea); textarea.select(); document.execCommand('copy'); textarea.remove();
        }
        const label = button.textContent;
        button.textContent = '已复制';
        setTimeout(() => { button.textContent = label; }, 1400);
    });
});
</script>
</body>
</html>
