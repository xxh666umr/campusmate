<?php
declare(strict_types=1);

use App\Auth;
use App\ChatService;
use App\Database;
use App\GuidedFlow;
use App\KnowledgeBase;
use App\Logger;
use App\StatsService;

require dirname(__DIR__) . '/app/bootstrap.php';

$config = $GLOBALS['APP_CONFIG'];

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = base_path();
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . trim($path, '/');
if ($path === '/') {
    $path = Auth::check() ? '/chat' : '/login';
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 静态文件（PHP 内置服务器）
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

/* ---------------------------------------------------------------- 登录 / 注册 */

if ($path === '/login' && $method === 'GET') {
    if (Auth::check()) {
        redirect('/chat');
    }
    view('login', ['title' => '登录', 'bodyClass' => 'auth-body', '__bare' => false]);
    exit;
}

if ($path === '/login' && $method === 'POST') {
    require_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (($_SESSION['_login_lock'] ?? 0) > time()) {
        flash('error', '尝试次数过多，请 ' . (($_SESSION['_login_lock'] - time())) . ' 秒后再试');
        redirect('/login');
    }

    $user = Auth::attempt($username, $password);
    if (!$user) {
        $_SESSION['_login_fail'] = ($_SESSION['_login_fail'] ?? 0) + 1;
        if ($_SESSION['_login_fail'] >= 5) {
            $_SESSION['_login_lock'] = time() + 60;
            $_SESSION['_login_fail'] = 0;
        }
        Logger::log('login.failed', '账号：' . $username, 'warning');
        flash('error', '用户名或密码不正确，或账号已被停用');
        remember_old(['username' => $username]);
        redirect('/login');
    }

    unset($_SESSION['_login_fail'], $_SESSION['_login_lock']);
    Auth::login($user);
    clear_old();
    Logger::log('login', '登录成功：' . $user['username']);
    flash('success', '欢迎回来，' . $user['display_name']);
    redirect(Auth::intended());
}

if ($path === '/register' && $method === 'GET') {
    if (Auth::check()) {
        redirect('/chat');
    }
    view('register', ['title' => '注册', 'bodyClass' => 'auth-body']);
    exit;
}

if ($path === '/register' && $method === 'POST') {
    require_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $display = trim((string) ($_POST['display_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    $errors = [];
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{3,19}$/', $username)) {
        $errors[] = '用户名需以字母开头，4-20 位字母、数字或下划线';
    }
    if (mb_strlen($display, 'UTF-8') < 2 || mb_strlen($display, 'UTF-8') > 20) {
        $errors[] = '昵称需为 2-20 个字符';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = '邮箱格式不正确';
    }
    if (strlen($password) < 6) {
        $errors[] = '密码至少 6 位';
    }
    if ($password !== $confirm) {
        $errors[] = '两次输入的密码不一致';
    }
    if (Database::value('SELECT COUNT(*) FROM users WHERE username = ?', [$username])) {
        $errors[] = '该用户名已被注册';
    }

    if ($errors) {
        flash('error', implode('；', $errors));
        remember_old($_POST);
        redirect('/register');
    }

    $id = Database::insert('users', [
        'username'      => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'display_name'  => $display,
        'email'         => $email ?: null,
        'role'          => 'user',
        'status'        => 'active',
        'created_at'    => now(),
    ]);
    clear_old();
    Logger::log('register', '新用户注册：' . $username, 'info', $id);
    flash('success', '注册成功，请登录');
    redirect('/login');
}

if ($path === '/logout') {
    if (Auth::check()) {
        Logger::log('logout', '退出登录');
    }
    Auth::logout();
    redirect('/login');
}

/* ---------------------------------------------------------------- 需要登录的页面 */

Auth::requireLogin();
$me = Auth::user();
$chatService = new ChatService($config);

if ($path === '/chat' && $method === 'GET') {
    if (Auth::isStaff()) {
        $recent = Database::all(
            'SELECT c.*, u.display_name FROM conversations c LEFT JOIN users u ON u.id = c.user_id ORDER BY c.updated_at DESC LIMIT 8'
        );
    } else {
        $recent = Database::all(
            'SELECT * FROM conversations WHERE user_id = ? ORDER BY updated_at DESC LIMIT 8',
            [$me['id']]
        );
    }
    view('chat', [
        'title'       => '智能客服',
        'active'      => 'chat',
        'me'          => $me,
        'provider'    => $chatService->providerStatus(),
        'guided'      => GuidedFlow::tree(),
        'recent'      => $recent,
        'categories'  => KnowledgeBase::categories(),
    ]);
    exit;
}

if ($path === '/history' && $method === 'GET') {
    view('history', ['title' => '咨询历史', 'active' => 'history', 'me' => $me, 'showUser' => Auth::isAdmin()]);
    exit;
}

if ($path === '/tickets' && $method === 'GET') {
    $tickets = Database::all(
        'SELECT t.*, u.display_name FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.user_id = ? ORDER BY t.id DESC',
        [$me['id']]
    );
    view('tickets', ['title' => '我的工单', 'active' => 'tickets', 'me' => $me, 'tickets' => $tickets]);
    exit;
}

if ($path === '/profile') {
    $stats = [
        'conversations' => (int) Database::value('SELECT COUNT(*) FROM conversations WHERE user_id = ?', [$me['id']]),
        'messages'      => (int) Database::value('SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.user_id = ?', [$me['id']]),
        'tickets'       => (int) Database::value('SELECT COUNT(*) FROM tickets WHERE user_id = ?', [$me['id']]),
    ];
    if ($method === 'POST') {
        require_csrf();
        $action = $_POST['action'] ?? 'profile';
        if ($action === 'profile') {
            $display = trim((string) ($_POST['display_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            if (mb_strlen($display, 'UTF-8') < 2) {
                flash('error', '昵称至少 2 个字符');
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', '邮箱格式不正确');
            } else {
                Database::run('UPDATE users SET display_name = ?, email = ? WHERE id = ?', [$display, $email ?: null, $me['id']]);
                Logger::log('profile.update', '更新个人资料');
                flash('success', '个人资料已更新');
            }
        } else {
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['new_password_confirm'] ?? '');
            if (!password_verify($current, $me['password_hash'])) {
                flash('error', '当前密码不正确');
            } elseif (strlen($new) < 6) {
                flash('error', '新密码至少 6 位');
            } elseif ($new !== $confirm) {
                flash('error', '两次输入的新密码不一致');
            } else {
                Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
                Logger::log('profile.password', '修改登录密码', 'warning');
                flash('success', '密码已更新，请重新登录');
                Auth::logout();
                redirect('/login');
            }
        }
        redirect('/profile');
    }
    view('profile', ['title' => '个人中心', 'active' => 'profile', 'me' => $me, 'stats' => $stats]);
    exit;
}

/* ---------------------------------------------------------------- 管理端页面 */

if ($path === '/admin/users' && $method === 'GET') {
    Auth::requireRole(['admin']);
    $q = trim((string) ($_GET['q'] ?? ''));
    $sql = 'SELECT * FROM users';
    $params = [];
    if ($q !== '') {
        $sql .= ' WHERE username LIKE ? OR display_name LIKE ? OR email LIKE ?';
        $params = ["%{$q}%", "%{$q}%", "%{$q}%"];
    }
    $sql .= ' ORDER BY id';
    view('admin_users', ['title' => '用户管理', 'active' => 'users', 'me' => $me, 'users' => Database::all($sql, $params), 'q' => $q]);
    exit;
}

if ($path === '/admin/users/save' && $method === 'POST') {
    Auth::requireRole(['admin']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $username = trim((string) ($_POST['username'] ?? ''));
    $display = trim((string) ($_POST['display_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $role = (string) ($_POST['role'] ?? 'user');
    $status = (string) ($_POST['status'] ?? 'active');
    $password = (string) ($_POST['password'] ?? '');

    if (!in_array($role, ['admin', 'agent', 'user'], true) || !in_array($status, ['active', 'disabled'], true)) {
        flash('error', '角色或状态不合法');
        redirect('/admin/users');
    }
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{3,19}$/', $username) || mb_strlen($display, 'UTF-8') < 2) {
        flash('error', '用户名或昵称格式不正确');
        redirect('/admin/users');
    }

    if ($id > 0) {
        if ($id === (int) $me['id'] && ($role !== 'admin' || $status !== 'active')) {
            flash('error', '不能取消自己的管理员权限或停用自己');
            redirect('/admin/users');
        }
        Database::run('UPDATE users SET username = ?, display_name = ?, email = ?, role = ?, status = ? WHERE id = ?', [
            $username, $display, $email ?: null, $role, $status, $id,
        ]);
        if ($password !== '') {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
        }
        Logger::log('admin.user.update', "更新用户 #{$id} {$username} 角色={$role} 状态={$status}", 'warning');
        flash('success', '用户已更新');
    } else {
        if (strlen($password) < 6) {
            flash('error', '新用户密码至少 6 位');
            redirect('/admin/users');
        }
        if (Database::value('SELECT COUNT(*) FROM users WHERE username = ?', [$username])) {
            flash('error', '用户名已存在');
            redirect('/admin/users');
        }
        Database::insert('users', [
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name'  => $display,
            'email'         => $email ?: null,
            'role'          => $role,
            'status'        => $status,
            'created_at'    => now(),
        ]);
        Logger::log('admin.user.create', "新增用户 {$username} 角色={$role}", 'warning');
        flash('success', '用户已创建');
    }
    redirect('/admin/users');
}

if ($path === '/admin/users/delete' && $method === 'POST') {
    Auth::requireRole(['admin']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id === (int) $me['id']) {
        flash('error', '不能删除当前登录账号');
        redirect('/admin/users');
    }
    $target = Database::one('SELECT * FROM users WHERE id = ?', [$id]);
    if ($target) {
        Database::run('DELETE FROM users WHERE id = ?', [$id]);
        Logger::log('admin.user.delete', '删除用户 ' . $target['username'], 'danger');
        flash('success', '用户已删除');
    }
    redirect('/admin/users');
}

if ($path === '/admin/logs' && $method === 'GET') {
    Auth::requireRole(['admin']);
    $level = (string) ($_GET['level'] ?? '');
    $q = trim((string) ($_GET['q'] ?? ''));
    $date = (string) ($_GET['date'] ?? '');
    $regex = isset($_GET['regex']) && $_GET['regex'] === '1';

    $sql = 'SELECT l.*, u.username FROM logs l LEFT JOIN users u ON u.id = l.user_id WHERE 1=1';
    $params = [];
    if ($level !== '' && in_array($level, ['info', 'warning', 'error', 'danger'], true)) {
        $sql .= ' AND l.level = ?';
        $params[] = $level;
    }
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $sql .= ' AND l.created_at >= ? AND l.created_at <= ?';
        $params[] = $date . ' 00:00:00';
        $params[] = $date . ' 23:59:59';
    }
    $sql .= ' ORDER BY l.id DESC LIMIT 300';
    $logs = Database::all($sql, $params);

    $regexError = '';
    if ($q !== '') {
        if ($regex) {
            $pattern = '/' . str_replace('/', '\/', $q) . '/iu';
            if (@preg_match($pattern, '') === false) {
                $regexError = '正则表达式不合法，已回退为普通包含搜索';
                $regex = false;
            }
        }
        $logs = array_values(array_filter($logs, static function ($row) use ($q, $regex) {
            $haystack = $row['action'] . ' ' . $row['detail'] . ' ' . $row['username'] . ' ' . $row['ip'];
            if ($regex) {
                return @preg_match('/' . str_replace('/', '\/', $q) . '/iu', $haystack) === 1;
            }
            return mb_stripos($haystack, $q) !== false;
        }));
    }

    view('admin_logs', [
        'title' => '日志管理', 'active' => 'logs', 'me' => $me, 'logs' => $logs,
        'q' => $q, 'level' => $level, 'date' => $date, 'regex' => $regex, 'regexError' => $regexError,
        'fileLines' => array_reverse(Logger::tailFile(30)),
    ]);
    exit;
}

if ($path === '/admin/tickets' && $method === 'GET') {
    Auth::requireRole(['admin', 'agent']);
    $status = (string) ($_GET['status'] ?? '');
    $sql = 'SELECT t.*, u.display_name, a.display_name AS assignee_name
            FROM tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN users a ON a.id = t.assignee_id';
    $params = [];
    if (in_array($status, ['open', 'processing', 'resolved', 'closed'], true)) {
        $sql .= ' WHERE t.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY FIELD?';
    $sql = str_replace(' ORDER BY FIELD?', ' ORDER BY CASE t.status WHEN \'open\' THEN 0 WHEN \'processing\' THEN 1 ELSE 2 END, t.id DESC', $sql);
    view('admin_tickets', [
        'title' => '工单管理', 'active' => 'tickets_admin', 'me' => $me,
        'tickets' => Database::all($sql, $params), 'status' => $status,
    ]);
    exit;
}

if ($path === '/admin/tickets/update' && $method === 'POST') {
    Auth::requireRole(['admin', 'agent']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    if (!in_array($status, ['open', 'processing', 'resolved', 'closed'], true)) {
        flash('error', '工单状态不合法');
        redirect('/admin/tickets');
    }
    Database::run('UPDATE tickets SET status = ?, assignee_id = COALESCE(assignee_id, ?), updated_at = ? WHERE id = ?', [
        $status, $me['id'], now(), $id,
    ]);
    Logger::log('admin.ticket.update', "工单 #{$id} 状态更新为 {$status}", 'warning');
    flash('success', '工单状态已更新');
    redirect('/admin/tickets');
}

if ($path === '/admin/stats' && $method === 'GET') {
    Auth::requireRole(['admin', 'agent']);
    view('admin_stats', [
        'title' => '数据看板', 'active' => 'stats', 'me' => $me,
        'overview' => StatsService::overview(),
        'daily' => StatsService::daily(7),
        'modes' => StatsService::modes(),
        'ratings' => StatsService::ratings(),
        'tickets' => StatsService::tickets(),
        'topics' => StatsService::topics(),
        'recent' => StatsService::recentConversations(),
    ]);
    exit;
}

/* ---------------------------------------------------------------- API */

if (str_starts_with($path, '/api/')) {
    if ($method === 'POST') {
        require_csrf();
    }
    $input = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        if ($raw !== '' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $input = json_decode($raw, true) ?: [];
        } else {
            $input = $_POST;
        }
    }

    if ($path === '/api/chat' && $method === 'POST') {
        $mode = (string) ($input['mode'] ?? 'single');
        $message = (string) ($input['message'] ?? '');
        $conversationId = isset($input['conversation_id']) && $input['conversation_id'] !== '' ? (int) $input['conversation_id'] : null;
        $guidedIssue = null;

        if ($mode === 'guided') {
            $categoryId = (string) ($input['category'] ?? '');
            $issueId = (string) ($input['issue'] ?? '');
            $guidedIssue = GuidedFlow::issue($categoryId, $issueId);
            if (!$guidedIssue) {
                json_out(['ok' => false, 'error' => '请选择具体问题'], 422);
            }
        }

        try {
            $result = $chatService->reply((int) $me['id'], $mode, $message, $conversationId, $guidedIssue);
            json_out($result);
        } catch (\InvalidArgumentException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Logger::log('chat.error', $e->getMessage(), 'error');
            json_out(['ok' => false, 'error' => '服务暂时不可用，请稍后重试'], 500);
        }
    }

    if ($path === '/api/guided/tree' && $method === 'GET') {
        json_out(['ok' => true, 'categories' => GuidedFlow::tree()]);
    }

    if ($path === '/api/history' && $method === 'GET') {
        $mode = (string) ($_GET['mode'] ?? '');
        $keyword = trim((string) ($_GET['q'] ?? ''));
        $date = (string) ($_GET['date'] ?? '');
        $regex = isset($_GET['regex']) && $_GET['regex'] === '1';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 8;

        $sql = 'SELECT c.*, u.display_name, (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS msg_count,
                (SELECT content FROM messages m2 WHERE m2.conversation_id = c.id ORDER BY m2.id DESC LIMIT 1) AS last_message
                FROM conversations c LEFT JOIN users u ON u.id = c.user_id WHERE 1=1';
        $params = [];
        if (!Auth::isAdmin()) {
            $sql .= ' AND c.user_id = ?';
            $params[] = $me['id'];
        }
        if (in_array($mode, ['single', 'multi', 'guided'], true)) {
            $sql .= ' AND c.mode = ?';
            $params[] = $mode;
        }
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $sql .= ' AND c.created_at >= ? AND c.created_at <= ?';
            $params[] = $date . ' 00:00:00';
            $params[] = $date . ' 23:59:59';
        }
        $sql .= ' ORDER BY c.updated_at DESC';
        $rows = Database::all($sql, $params);

        $regexError = '';
        if ($keyword !== '') {
            if ($regex) {
                if (@preg_match('/' . str_replace('/', '\/', $keyword) . '/iu', '') === false) {
                    $regexError = '正则表达式不合法，已按普通关键词搜索';
                    $regex = false;
                }
            }
            $rows = array_values(array_filter($rows, static function ($row) use ($keyword, $regex) {
                $hay = $row['title'] . ' ' . $row['last_message'];
                if ($regex) {
                    return @preg_match('/' . str_replace('/', '\/', $keyword) . '/iu', $hay) === 1;
                }
                return mb_stripos($hay, $keyword) !== false;
            }));
        }

        $total = count($rows);
        $rows = array_slice($rows, ($page - 1) * $per, $per);
        json_out([
            'ok' => true, 'total' => $total, 'page' => $page, 'per' => $per,
            'pages' => max(1, (int) ceil($total / $per)), 'items' => $rows, 'regexError' => $regexError,
        ]);
    }

    if ($path === '/api/conversation' && $method === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        $conv = Database::one('SELECT * FROM conversations WHERE id = ? AND user_id = ?', [$id, $me['id']]);
        if (!$conv && Auth::isStaff()) {
            $conv = Database::one('SELECT * FROM conversations WHERE id = ?', [$id]);
        }
        if (!$conv) {
            json_out(['ok' => false, 'error' => '会话不存在'], 404);
        }
        $messages = Database::all('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id', [$id]);
        foreach ($messages as &$m) {
            $m['meta'] = $m['meta'] ? json_decode($m['meta'], true) : null;
            $m['mine'] = (int) ($m['user_id'] ?? 0) === (int) $me['id'];
        }
        unset($m);
        json_out(['ok' => true, 'conversation' => $conv, 'messages' => $messages]);
    }

    if ($path === '/api/feedback' && $method === 'POST') {
        $messageId = (int) ($input['message_id'] ?? 0);
        $rating = (int) ($input['rating'] ?? 0);
        $comment = trim((string) ($input['comment'] ?? ''));
        if ($rating < 1 || $rating > 5) {
            json_out(['ok' => false, 'error' => '评分需在 1-5 之间'], 422);
        }
        $msg = Database::one('SELECT * FROM messages WHERE id = ? AND role = ?', [$messageId, 'assistant']);
        if (!$msg) {
            json_out(['ok' => false, 'error' => '消息不存在'], 404);
        }
        $exists = Database::one('SELECT id FROM feedback WHERE message_id = ? AND user_id = ?', [$messageId, $me['id']]);
        if ($exists) {
            Database::run('UPDATE feedback SET rating = ?, comment = ? WHERE id = ?', [$rating, $comment ?: null, $exists['id']]);
        } else {
            Database::insert('feedback', [
                'message_id' => $messageId, 'user_id' => $me['id'], 'rating' => $rating,
                'comment' => $comment ?: null, 'created_at' => now(),
            ]);
        }
        Logger::log('feedback', "消息 #{$messageId} 评分 {$rating}", 'info');
        json_out(['ok' => true, 'message' => '感谢你的评价']);
    }

    if ($path === '/api/ticket' && $method === 'POST') {
        $title = trim((string) ($input['title'] ?? ''));
        $detail = trim((string) ($input['detail'] ?? ''));
        $priority = (string) ($input['priority'] ?? 'normal');
        $conversationId = isset($input['conversation_id']) ? (int) $input['conversation_id'] : null;
        if (mb_strlen($title, 'UTF-8') < 4) {
            json_out(['ok' => false, 'error' => '请填写至少 4 个字的工单标题'], 422);
        }
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }
        $id = Database::insert('tickets', [
            'user_id' => $me['id'], 'conversation_id' => $conversationId ?: null,
            'title' => str_limit($title, 100), 'detail' => $detail,
            'priority' => $priority, 'status' => 'open', 'assignee_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Logger::log('ticket.create', "工单 #{$id}：{$title}", 'warning');
        json_out(['ok' => true, 'ticket_id' => $id, 'message' => '工单已提交，客服老师会尽快处理']);
    }

    if ($path === '/api/logs/file' && $method === 'GET') {
        Auth::requireRole(['admin']);
        json_out(['ok' => true, 'lines' => array_reverse(Logger::tailFile(80))]);
    }

    if ($path === '/api/stats' && $method === 'GET') {
        Auth::requireRole(['admin', 'agent']);
        json_out(['ok' => true, 'overview' => StatsService::overview(), 'daily' => StatsService::daily(), 'modes' => StatsService::modes()]);
    }

    json_out(['ok' => false, 'error' => '接口不存在'], 404);
}

/* ---------------------------------------------------------------- 导出（文件操作） */

if ($path === '/export' && $method === 'GET') {
    $type = (string) ($_GET['type'] ?? 'history');
    if ($type === 'logs') {
        Auth::requireRole(['admin']);
    }
    $dir = $config['export_path'];
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $filename = $type . '-' . date('Ymd-His') . '.csv';
    $file = $dir . '/' . $filename;
    $fh = fopen($file, 'w');
    if (!$fh) {
        abort(500, '无法创建导出文件');
    }
    fwrite($fh, "\xEF\xBB\xBF"); // Excel 识别 UTF-8

    if ($type === 'logs') {
        fputcsv($fh, ['ID', '时间', '用户', '级别', '动作', '详情', 'IP']);
        foreach (Database::all('SELECT l.*, u.username FROM logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 2000') as $row) {
            fputcsv($fh, [$row['id'], $row['created_at'], $row['username'], $row['level'], $row['action'], $row['detail'], $row['ip']]);
        }
    } else {
        fputcsv($fh, ['ID', '咨询方式', '标题', '消息数', '创建时间', '更新时间']);
        $rows = Auth::isAdmin()
            ? Database::all('SELECT c.*, u.username, (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS n FROM conversations c LEFT JOIN users u ON u.id = c.user_id ORDER BY c.id DESC LIMIT 2000')
            : Database::all('SELECT c.*, u.username, (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS n FROM conversations c LEFT JOIN users u ON u.id = c.user_id WHERE c.user_id = ? ORDER BY c.id DESC LIMIT 2000', [$me['id']]);
        foreach ($rows as $row) {
            fputcsv($fh, [$row['id'], $row['mode'], $row['title'], $row['n'], $row['created_at'], $row['updated_at']]);
        }
    }
    fclose($fh);
    Logger::log('export', '导出 ' . $type . ' 数据：' . $filename);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

/* ---------------------------------------------------------------- 404 */

abort(404);
