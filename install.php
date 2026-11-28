<?php
/**
 * idceasy 一键安装向导
 * 安装完成后请删除本文件，或保留（install.lock.php 会阻止重复安装）。
 */
declare(strict_types=1);
session_start();

const ICE_VERSION = '1.0.0';
$root = __DIR__;
$lockFile = $root . '/install.lock.php';
$installed = is_file($lockFile);
$errors = [];
$done = false;
$post = $_POST;

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function check_env(string $root): array
{
    $checks = [];
    $checks[] = ['PHP 版本 ≥ 8.1', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='), true];
    foreach (['pdo_mysql' => 'PDO MySQL 扩展', 'curl' => 'cURL 扩展', 'mbstring' => 'mbstring 扩展',
                 'openssl' => 'OpenSSL 扩展', 'json' => 'JSON 扩展'] as $ext => $label) {
        $checks[] = [$label, extension_loaded($ext) ? '已安装' : '未安装', extension_loaded($ext), true];
    }
    foreach (['config', 'data', 'data/logs', 'data/cache', 'template', 'plugins'] as $dir) {
        $path = $root . '/' . $dir;
        $ok = is_dir($path) ? is_writable($path) : is_writable(dirname($path));
        $checks[] = ['目录可写：/' . $dir, $ok ? '可写' : '不可写', $ok, true];
    }
    $checks[] = ['安装锁', is_file($root . '/install.lock.php') ? '已锁定' : '未安装', true, false];
    return $checks;
}

$checks = check_env($root);
$envOk = true;
foreach ($checks as $c) if ($c[3] && !$c[2]) $envOk = false;
if (empty($_SESSION['ice_install_token'])) $_SESSION['ice_install_token'] = bin2hex(random_bytes(16));

$defaultUrl = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$defaultUrl = rtrim($defaultUrl, '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    if (!hash_equals($_SESSION['ice_install_token'], (string)($_POST['_token'] ?? ''))) {
        $errors[] = '页面已过期，请刷新后重试。';
    } else {
        $db = [
            'hostname' => trim((string)($_POST['db_host'] ?? '127.0.0.1')),
            'hostport' => trim((string)($_POST['db_port'] ?? '3306')),
            'database' => trim((string)($_POST['db_name'] ?? '')),
            'username' => trim((string)($_POST['db_user'] ?? '')),
            'password' => (string)($_POST['db_pass'] ?? ''),
        ];
        $siteName = trim((string)($_POST['site_name'] ?? 'idceasy')) ?: 'idceasy';
        $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? $defaultUrl)), '/');
        $adminUser = trim((string)($_POST['admin_user'] ?? 'admin'));
        $adminPass = (string)($_POST['admin_pass'] ?? '');
        $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
        $createDb = !empty($_POST['create_db']);

        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $db['database'])) $errors[] = '数据库名只能包含字母、数字、下划线和短横线。';
        if ($adminUser === '' || strlen($adminUser) < 3) $errors[] = '管理员用户名至少 3 个字符。';
        if (strlen($adminPass) < 8) $errors[] = '管理员密码至少 8 位。';
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = '请填写有效的管理员邮箱。';
        if (!$envOk) $errors[] = '环境检测未通过，请先解决红色项目。';

        if (!$errors) {
            try {
                if ($createDb) {
                    $pdo0 = new PDO('mysql:host=' . $db['hostname'] . ';port=' . $db['hostport'] . ';charset=utf8mb4',
                        $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $pdo0->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $db['database'])
                        . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                }
                $pdo = new PDO('mysql:host=' . $db['hostname'] . ';port=' . $db['hostport']
                    . ';dbname=' . $db['database'] . ';charset=utf8mb4', $db['username'], $db['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $exists = $pdo->query("SHOW TABLES LIKE 'ice_config'")->fetchColumn();
                if ($exists) {
                    $count = (int)$pdo->query('SELECT COUNT(*) FROM ice_config')->fetchColumn();
                    if ($count > 0) throw new RuntimeException('检测到已有的 idceasy 数据表，为避免覆盖数据，安装已中止。');
                }
                $pdo->exec("SET NAMES utf8mb4");
                foreach (['install.sql', 'install.data.sql'] as $file) {
                    $sql = file_get_contents($root . '/' . $file);
                    if ($sql === false) throw new RuntimeException('无法读取 ' . $file);
                    foreach (preg_split('/;\s*\n/', $sql) as $statement) {
                        $statement = preg_replace('/^\s*--.*$/m', '', $statement);
                        $statement = trim($statement);
                        if ($statement === '') continue;
                        $pdo->exec($statement);
                    }
                }
                $appKey = bin2hex(random_bytes(24));
                $cronToken = bin2hex(random_bytes(16));
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO ice_admin (username,password,name,email,role_id,status,created_at)
                    VALUES (?,?,?,?,1,1,NOW())');
                $stmt->execute([$adminUser, $hash, '超级管理员', $adminEmail]);

                $configs = [
                    'site_name' => $siteName, 'site_url' => $siteUrl, 'company_name' => $siteName,
                    'company_email' => $adminEmail, 'smtp_from' => $adminEmail,
                    'cron_token' => $cronToken, 'app_key' => $appKey,
                    'url_rewrite' => empty($_POST['url_rewrite']) ? '0' : '1',
                    'debug' => '0',
                ];
                $stmt = $pdo->prepare('INSERT INTO ice_config (`k`,`v`) VALUES (?,?)
                    ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)');
                foreach ($configs as $k => $v) $stmt->execute([$k, $v]);

                $dbConfig = "<?php\n// idceasy 数据库配置（安装时自动生成）\nreturn [\n"
                    . "    'type'     => 'mysql',\n"
                    . "    'hostname' => " . var_export($db['hostname'], true) . ",\n"
                    . "    'hostport' => " . var_export($db['hostport'], true) . ",\n"
                    . "    'database' => " . var_export($db['database'], true) . ",\n"
                    . "    'username' => " . var_export($db['username'], true) . ",\n"
                    . "    'password' => " . var_export($db['password'], true) . ",\n"
                    . "    'prefix'   => 'ice_',\n"
                    . "    'charset'  => 'utf8mb4',\n"
                    . "    'app_key'  => " . var_export($appKey, true) . ",\n"
                    . "];\n";
                if (file_put_contents($root . '/config/database.php', $dbConfig, LOCK_EX) === false) {
                    throw new RuntimeException('无法写入 config/database.php，请检查目录权限。');
                }
                @chmod($root . '/config/database.php', 0640);
                file_put_contents($lockFile, "<?php\n// idceasy installed at " . date('c') . "\nreturn true;\n", LOCK_EX);
                @unlink($root . '/data/cache/install.lock');
                $_SESSION['ice_install_done'] = ['url' => $siteUrl, 'admin' => $adminUser, 'cron' => $cronToken];
                $done = true;
            } catch (Throwable $e) {
                $errors[] = '安装失败：' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装 idceasy</title>
<style>
:root{--primary:#2563eb;--primary-dark:#1d4ed8;--bg:#f5f7fb;--card:#fff;--text:#1b2430;--muted:#6b7787;--line:#e3e8ef;--ok:#0f9d58;--err:#dc2626}
*{box-sizing:border-box}body{margin:0;font:15px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:var(--bg);color:var(--text)}
.wrap{max-width:1080px;margin:0 auto;padding:36px 20px 60px}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:26px}
.logo{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#2563eb,#0ea5e9);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:20px}
.brand h1{font-size:22px;margin:0;letter-spacing:.2px}
.brand p{margin:0;color:var(--muted);font-size:13px}
.grid{display:grid;grid-template-columns:1.05fr .95fr;gap:22px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.card h2{font-size:16px;margin:0 0 16px;display:flex;align-items:center;gap:8px}
.card h2 .num{width:22px;height:22px;border-radius:50%;background:#eef4ff;color:var(--primary);font-size:12px;display:flex;align-items:center;justify-content:center}
.checks{list-style:none;margin:0;padding:0}
.checks li{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px dashed #eef1f6;font-size:13.5px}
.checks li:last-child{border-bottom:0}
.badge{font-size:12px;padding:1px 8px;border-radius:20px;white-space:nowrap}
.badge.ok{background:#e8f7ee;color:var(--ok)}.badge.bad{background:#fdecec;color:var(--err)}
label{display:block;font-size:13px;color:#41505f;margin:14px 0 6px}
input[type=text],input[type=password],input[type=email],input[type=number]{width:100%;height:42px;border:1px solid #d7dee8;border-radius:10px;padding:0 12px;font-size:14px;outline:none;background:#fff;transition:.15s}
input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.row{display:flex;gap:12px}.row>*{flex:1}
.hint{font-size:12px;color:var(--muted);margin-top:6px}
.checkline{display:flex;align-items:center;gap:8px;margin-top:12px;font-size:13px;color:#41505f}
button{width:100%;height:46px;border:0;border-radius:11px;background:var(--primary);color:#fff;font-size:15px;font-weight:600;cursor:pointer;margin-top:20px;transition:.15s}
button:hover{background:var(--primary-dark)}button:disabled{background:#9db4e8;cursor:not-allowed}
.alert{border-radius:11px;padding:12px 14px;font-size:13.5px;margin-bottom:16px}
.alert.err{background:#fdecec;color:#a12828;border:1px solid #f6cccc}
.alert.ok{background:#e8f7ee;color:#146c39;border:1px solid #bfe6ce}
.done h2{font-size:20px}
.done .kv{display:grid;grid-template-columns:110px 1fr;gap:8px 12px;margin:14px 0 4px;font-size:14px}
.done .kv span:first-child{color:var(--muted)}
code{background:#f1f4f9;border-radius:6px;padding:2px 6px;font-size:12.5px}
.foot{margin-top:26px;color:#98a2b3;font-size:12px;text-align:center}
.warn{background:#fff8e6;border:1px solid #f5e0a3;color:#7a5b00;border-radius:10px;padding:10px 12px;font-size:13px;margin-top:14px}
@media(max-width:820px){.grid{grid-template-columns:1fr}.wrap{padding:20px 14px 40px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <div class="logo">i</div>
    <div>
      <h1>idceasy <?= ICE_VERSION ?></h1>
      <p>开源 IDC 计费与云服务系统 · AGPL-3.0 · 安装向导</p>
    </div>
  </div>

<?php if ($installed): ?>
  <div class="card done">
    <h2>系统已完成安装</h2>
    <p>检测到 <code>install.lock.php</code>，为防止数据被覆盖，安装程序已锁定。</p>
    <p>如需重装，请先备份并删除 <code>install.lock.php</code> 与 <code>config/database.php</code>，并清空数据库。</p>
    <p><a href="index.php">进入前台</a> · <a href="index.php/admin/login">进入后台</a></p>
  </div>
<?php elseif ($done): $info = $_SESSION['ice_install_done'] ?? []; ?>
  <div class="card done">
    <h2>安装完成</h2>
    <div class="alert ok">数据库已初始化，管理员账户已创建。建议立即删除 install.php。</div>
    <div class="kv">
      <span>前台地址</span><span><a href="<?= h($info['url'] ?? $defaultUrl) ?>/" target="_blank"><?= h($info['url'] ?? $defaultUrl) ?>/</a></span>
      <span>后台地址</span><span><a href="<?= h($info['url'] ?? $defaultUrl) ?>/admin/login" target="_blank"><?= h($info['url'] ?? $defaultUrl) ?>/admin/login</a></span>
      <span>管理员</span><span><code><?= h($info['admin'] ?? '') ?></code>（密码为安装时填写）</span>
      <span>定时任务</span><span><code><?= h($info['url'] ?? '') ?>/cron.php?token=<?= h($info['cron'] ?? '') ?></code></span>
    </div>
    <div class="warn">请配置服务器计划任务，每分钟访问一次上面的定时任务地址；并在后台「设置」中配置 SMTP 与支付网关。</div>
    <p style="margin-top:18px"><a href="<?= h(($info['url'] ?? '') . '/admin/login') ?>">前往后台登录 →</a></p>
  </div>
<?php else: ?>
  <?php if ($errors): ?>
    <div class="alert err"><?php foreach ($errors as $e) echo '• ' . h($e) . '<br>'; ?></div>
  <?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="_token" value="<?= h($_SESSION['ice_install_token']) ?>">
    <div class="grid">
      <div class="card">
        <h2><span class="num">1</span>环境检测</h2>
        <ul class="checks">
          <?php foreach ($checks as [$label, $value, $ok, $required]): ?>
          <li><span><?= h($label) ?></span>
            <span class="badge <?= $ok ? 'ok' : ($required ? 'bad' : 'ok') ?>"><?= h($value) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <div class="hint">需要 PHP 8.1+、MySQL 5.7+ / MariaDB 10.3+，并启用 PDO MySQL 扩展。</div>

        <h2 style="margin-top:24px"><span class="num">2</span>数据库</h2>
        <div class="row">
          <div><label>主机</label><input type="text" name="db_host" value="<?= h($post['db_host'] ?? '127.0.0.1') ?>" required></div>
          <div><label>端口</label><input type="text" name="db_port" value="<?= h($post['db_port'] ?? '3306') ?>" required></div>
        </div>
        <label>数据库名</label>
        <input type="text" name="db_name" value="<?= h($post['db_name'] ?? 'idceasy') ?>" required>
        <div class="row">
          <div><label>用户名</label><input type="text" name="db_user" value="<?= h($post['db_user'] ?? 'root') ?>" required></div>
          <div><label>密码</label><input type="password" name="db_pass" value="<?= h($post['db_pass'] ?? '') ?>"></div>
        </div>
        <label class="checkline"><input type="checkbox" name="create_db" value="1" <?= isset($post['create_db']) ? 'checked' : '' ?>> 数据库不存在时自动创建</label>
      </div>

      <div class="card">
        <h2><span class="num">3</span>站点与管理员</h2>
        <label>站点名称</label>
        <input type="text" name="site_name" value="<?= h($post['site_name'] ?? 'idceasy') ?>" required>
        <label>站点地址</label>
        <input type="text" name="site_url" value="<?= h($post['site_url'] ?? $defaultUrl) ?>" required>
        <div class="hint">用于邮件链接与支付回调，需带 http(s)://，不要以 / 结尾。</div>
        <label class="checkline"><input type="checkbox" name="url_rewrite" value="1" <?= isset($post['url_rewrite']) || !$post ? 'checked' : '' ?>> 启用 URL 重写（Apache / Nginx）</label>

        <h2 style="margin-top:24px"><span class="num">4</span>管理员账户</h2>
        <label>用户名</label>
        <input type="text" name="admin_user" value="<?= h($post['admin_user'] ?? 'admin') ?>" required>
        <label>邮箱</label>
        <input type="email" name="admin_email" value="<?= h($post['admin_email'] ?? '') ?>" required>
        <label>密码</label>
        <input type="password" name="admin_pass" required minlength="8">
        <div class="hint">至少 8 位，建议使用大小写字母、数字与符号组合。</div>

        <button type="submit" <?= $envOk ? '' : 'disabled' ?>>开始安装</button>
        <div class="hint" style="text-align:center;margin-top:10px">安装过程不会修改服务器配置，仅写入数据库与 config/database.php</div>
      </div>
    </div>
  </form>
<?php endif; ?>

  <div class="foot">idceasy · 官网 idceasy.site · AGPL-3.0</div>
</div>
</body>
</html>
