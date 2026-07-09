<?php
require_once 'db.php';

// ====================================================
// ONE-TIME SCHEMA ENSURE (runs once per request, cached)
// ====================================================
function ensureSchema($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    // Check users columns
    $colsRef = $conn->query("SHOW COLUMNS FROM users");
    $existRef = [];
    while ($c = $colsRef->fetch_assoc()) $existRef[] = $c['Field'];
    if (!in_array('referral_code',   $existRef)) $conn->query("ALTER TABLE users ADD COLUMN referral_code VARCHAR(50) DEFAULT NULL");
    if (!in_array('referral_bonus',  $existRef)) $conn->query("ALTER TABLE users ADD COLUMN referral_bonus DECIMAL(10,2) DEFAULT 0");
    if (!in_array('referrals_count', $existRef)) $conn->query("ALTER TABLE users ADD COLUMN referrals_count INT DEFAULT 0");
    // Check admin_settings columns
    $colsAdm = $conn->query("SHOW COLUMNS FROM admin_settings");
    $existAdm = [];
    while ($c = $colsAdm->fetch_assoc()) $existAdm[] = $c['Field'];
    if (!in_array('slider_items',          $existAdm)) $conn->query("ALTER TABLE admin_settings ADD COLUMN slider_items TEXT DEFAULT NULL");
    if (!in_array('tg_bot_token',          $existAdm)) $conn->query("ALTER TABLE admin_settings ADD COLUMN tg_bot_token VARCHAR(200) DEFAULT NULL");
    if (!in_array('tg_chat_id',            $existAdm)) $conn->query("ALTER TABLE admin_settings ADD COLUMN tg_chat_id VARCHAR(100) DEFAULT NULL");
    if (!in_array('referral_bonus_per_ref',$existAdm)) $conn->query("ALTER TABLE admin_settings ADD COLUMN referral_bonus_per_ref DECIMAL(10,2) DEFAULT 5");
    if (!in_array('payment_verify_api_url',$existAdm)) $conn->query("ALTER TABLE admin_settings ADD COLUMN payment_verify_api_url VARCHAR(500) DEFAULT 'https://smmgemphp-default-rtdb.firebaseio.com/XNXANIKPAY/.json'");
}

// ====================================================
// AJAX / API Handler (POST Requests)
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = clean($conn, $_POST['action']);

    // ---------- ADMIN LOGIN ----------
    if ($action === 'admin_login') {
        $email = clean($conn, $_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $res   = $conn->query("SELECT * FROM admin_users WHERE email='$email' LIMIT 1");
        if ($res->num_rows === 0) jsonResponse(['success'=>false,'message'=>'Invalid credentials']);
        $admin = $res->fetch_assoc();
        if (!password_verify($pass, $admin['password'])) jsonResponse(['success'=>false,'message'=>'Invalid credentials']);
        $_SESSION['admin_email'] = $admin['email'];
        $_SESSION['admin_id']    = $admin['id'];
        jsonResponse(['success'=>true,'email'=>$admin['email']]);
    }

    // ---------- ADMIN LOGOUT ----------
    if ($action === 'admin_logout') {
        session_destroy(); jsonResponse(['success'=>true]);
    }

    // Check admin auth for all other actions
    if (empty($_SESSION['admin_email'])) jsonResponse(['success'=>false,'message'=>'Unauthorized']);

    // ---------- GET DASHBOARD DATA ----------
    if ($action === 'get_dashboard') {
        $users     = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
        $active    = $conn->query("SELECT COUNT(*) AS c FROM users WHERE blocked=0")->fetch_assoc()['c'];
        $totalBal  = $conn->query("SELECT COALESCE(SUM(balance),0) AS b FROM users")->fetch_assoc()['b'];
        $pending   = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status='Pending'")->fetch_assoc()['c'];
        $completed = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status='Completed'")->fetch_assoc()['c'];
        $cancelled = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status='Cancelled'")->fetch_assoc()['c'];
        $totalOrd  = $conn->query("SELECT COUNT(*) AS c FROM orders")->fetch_assoc()['c'];

        // Referral stats — use COALESCE, avoid SHOW COLUMNS every call
        $totalReferrals = 0;
        $totalRefBonus  = 0;
        try {
            $totalReferrals = (int)($conn->query("SELECT COALESCE(SUM(referrals_count),0) AS c FROM users")->fetch_assoc()['c']);
            $totalRefBonus  = (float)($conn->query("SELECT COALESCE(SUM(referral_bonus),0) AS b FROM users")->fetch_assoc()['b']);
        } catch (Exception $e) { /* columns may not exist yet */ }

        // Category analytics
        $catRes = $conn->query("SELECT c.name, COUNT(s.id) AS svc_count FROM categories c LEFT JOIN services s ON s.cat=c.name WHERE c.hidden=0 GROUP BY c.name ORDER BY c.sort_order ASC");
        $catAnalytics = [];
        while ($r = $catRes->fetch_assoc()) $catAnalytics[] = $r;

        jsonResponse(['success'=>true,'users'=>$users,'active'=>$active,'totalBal'=>$totalBal,'pending'=>$pending,'completed'=>$completed,'cancelled'=>$cancelled,'totalOrders'=>$totalOrd,'catAnalytics'=>$catAnalytics,'totalReferrals'=>$totalReferrals,'totalRefBonus'=>$totalRefBonus]);
    }

    // ---------- CATEGORIES ----------
    if ($action === 'get_categories') {
        $res = $conn->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        jsonResponse(['success'=>true,'categories'=>$rows]);
    }
    if ($action === 'add_category') {
        $name = clean($conn, $_POST['name'] ?? '');
        $logo = clean($conn, $_POST['logo'] ?? '');
        if (!$name) jsonResponse(['success'=>false,'message'=>'Name required']);
        $conn->query("INSERT INTO categories (name,logo,sort_order,hidden) VALUES ('$name','$logo',99,0)");
        jsonResponse(['success'=>true,'id'=>$conn->insert_id]);
    }
    if ($action === 'edit_category') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = clean($conn, $_POST['name'] ?? '');
        $conn->query("UPDATE categories SET name='$name' WHERE id=$id");
        jsonResponse(['success'=>true]);
    }
    if ($action === 'toggle_category') {
        $id     = (int)($_POST['id']     ?? 0);
        $hidden = (int)($_POST['hidden'] ?? 0);
        $conn->query("UPDATE categories SET hidden=$hidden WHERE id=$id");
        jsonResponse(['success'=>true]);
    }
    if ($action === 'delete_category') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM categories WHERE id=$id");
        jsonResponse(['success'=>true]);
    }
    if ($action === 'sort_category') {
        $id   = (int)($_POST['id']   ?? 0);
        $sort = (int)($_POST['sort'] ?? 0);
        $conn->query("UPDATE categories SET sort_order=$sort WHERE id=$id");
        jsonResponse(['success'=>true]);
    }

    // ---------- SERVICES ----------
    if ($action === 'get_services') {
        $res = $conn->query("SELECT * FROM services ORDER BY sort_order ASC, id ASC");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        jsonResponse(['success'=>true,'services'=>$rows]);
    }
    if ($action === 'add_service') {
        $name  = clean($conn, $_POST['name']        ?? '');
        $cat   = clean($conn, $_POST['cat']         ?? '');
        $rate  = (float)($_POST['rate']             ?? 0);
        $min   = (int)($_POST['min']                ?? 10);
        $max   = (int)($_POST['max']                ?? 10000);
        $pid   = clean($conn, $_POST['provider_id'] ?? '');
        $desc  = clean($conn, $_POST['description'] ?? '');
        $key   = 'SVC'.strtoupper(substr(md5($name.time()), 0, 8));
        $conn->query("INSERT INTO services (service_key,cat,name,rate,min_order,max_order,provider_id,description,sort_order,hidden) VALUES ('$key','$cat','$name',$rate,$min,$max,'$pid','$desc',99,0)");
        jsonResponse(['success'=>true,'id'=>$conn->insert_id]);
    }
    if ($action === 'edit_service') {
        $id   = (int)($_POST['id']          ?? 0);
        $name = clean($conn, $_POST['name'] ?? '');
        $cat  = clean($conn, $_POST['cat']  ?? '');
        $rate = (float)($_POST['rate']      ?? 0);
        $min  = (int)($_POST['min']         ?? 10);
        $max  = (int)($_POST['max']         ?? 10000);
        $pid  = clean($conn, $_POST['provider_id']  ?? '');
        $desc = clean($conn, $_POST['description']  ?? '');
        $conn->query("UPDATE services SET name='$name',cat='$cat',rate=$rate,min_order=$min,max_order=$max,provider_id='$pid',description='$desc' WHERE id=$id");
        jsonResponse(['success'=>true]);
    }
    if ($action === 'toggle_service') {
        $id     = (int)($_POST['id']     ?? 0);
        $hidden = (int)($_POST['hidden'] ?? 0);
        $conn->query("UPDATE services SET hidden=$hidden WHERE id=$id");
        jsonResponse(['success'=>true]);
    }
    if ($action === 'delete_service') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM services WHERE id=$id");
        jsonResponse(['success'=>true]);
    }
    if ($action === 'sort_service') {
        $id   = (int)($_POST['id']   ?? 0);
        $sort = (int)($_POST['sort'] ?? 0);
        $conn->query("UPDATE services SET sort_order=$sort WHERE id=$id");
        jsonResponse(['success'=>true]);
    }

    // ---------- PAYMENT METHODS ----------
    if ($action === 'get_payments') {
        $res = $conn->query("SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        jsonResponse(['success'=>true,'methods'=>$rows]);
    }
    if ($action === 'add_payment') {
        $name   = clean($conn, $_POST['name']        ?? '');
        $number = clean($conn, $_POST['number']      ?? '');
        $type   = clean($conn, $_POST['type']        ?? 'Personal (Send Money)');
        $minDep = (float)($_POST['min_deposit']      ?? 10);
        $logo   = clean($conn, $_POST['logo']        ?? '');
        $conn->query("INSERT INTO payment_methods (name,number,type,min_deposit,logo,hidden) VALUES ('$name','$number','$type',$minDep,'$logo',0)");
        jsonResponse(['success'=>true,'id'=>$conn->insert_id]);
    }
    if ($action === 'edit_payment') {
        $id     = (int)($_POST['id']           ?? 0);
        $name   = clean($conn, $_POST['name']  ?? '');
        $number = clean($conn, $_POST['number']?? '');
        $type   = clean($conn, $_POST['type']  ?? 'Personal (Send Money)');
        $minDep = (float)($_POST['min_deposit']?? 10);
        $conn->query("UPDATE payment_methods SET name='$name',number='$number',type='$type',min_deposit=$minDep WHERE id=$id");
        jsonResponse(['success'=>true]);
    }
    if ($action === 'toggle_payment') {
        $id     = (int)($_POST['id']     ?? 0);
        $hidden = (int)($_POST['hidden'] ?? 0);
        $conn->query("UPDATE payment_methods SET hidden=$hidden WHERE id=$id");
        jsonResponse(['success'=>true]);
    }
    if ($action === 'delete_payment') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM payment_methods WHERE id=$id");
        jsonResponse(['success'=>true]);
    }

    // ---------- GET REFERRALS ----------
    // SHOW COLUMNS এখানে নয়, ensureSchema() একবারই চলে
    if ($action === 'get_referrals') {
        ensureSchema($conn);
        $page    = max(1, (int)($_POST['page']     ?? 1));
        $perPage = 50;
        $offset  = ($page - 1) * $perPage;

        $totalRow = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
        $res = $conn->query("SELECT uid, username, tg_name, tg_username, referral_code, referrals_count, referral_bonus, created_at FROM users ORDER BY referrals_count DESC, id DESC LIMIT $perPage OFFSET $offset");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;

        $totalReferrals = (int)($conn->query("SELECT COALESCE(SUM(referrals_count),0) AS c FROM users")->fetch_assoc()['c']);
        $totalBonus     = (float)($conn->query("SELECT COALESCE(SUM(referral_bonus),0) AS b FROM users")->fetch_assoc()['b']);

        jsonResponse(['success'=>true,'referrals'=>$rows,'totalReferrals'=>$totalReferrals,'totalBonus'=>$totalBonus,'total'=>$totalRow,'page'=>$page,'perPage'=>$perPage]);
    }

    // ---------- USERS — Server-side pagination + search ----------
    if ($action === 'get_users') {
        $page    = max(1, (int)($_POST['page']     ?? 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;
        $search  = clean($conn, $_POST['search'] ?? '');

        $where = '';
        if ($search !== '') {
            $where = "WHERE username LIKE '%$search%' OR email LIKE '%$search%' OR phone LIKE '%$search%' OR uid LIKE '%$search%'";
        }

        $totalRes = $conn->query("SELECT COUNT(*) AS c FROM users $where");
        $total    = (int)$totalRes->fetch_assoc()['c'];

        $res  = $conn->query("SELECT id,uid,username,email,phone,balance,blocked,profile_pic,tg_username,tg_photo,created_at FROM users $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;

        jsonResponse(['success'=>true,'users'=>$rows,'total'=>$total,'page'=>$page,'perPage'=>$perPage]);
    }
    if ($action === 'edit_user') {
        $id    = (int)($_POST['id']            ?? 0);
        $name  = clean($conn, $_POST['username']?? '');
        $email = clean($conn, $_POST['email']  ?? '');
        $phone = clean($conn, $_POST['phone']  ?? '');
        $bal   = (float)($_POST['balance']     ?? 0);
        $newPass = $_POST['password'] ?? '';
        $sets = "username='$name',email='$email',phone='$phone',balance=$bal";
        if ($newPass) { $hash = password_hash($newPass, PASSWORD_BCRYPT); $sets .= ",password='$hash'"; }
        $conn->query("UPDATE users SET $sets WHERE id=$id");
        jsonResponse(['success'=>true]);
    }
    if ($action === 'toggle_user_block') {
        $id      = (int)($_POST['id']      ?? 0);
        $blocked = (int)($_POST['blocked'] ?? 0);
        $conn->query("UPDATE users SET blocked=$blocked WHERE id=$id");
        jsonResponse(['success'=>true]);
    }
    if ($action === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM users WHERE id=$id");
        jsonResponse(['success'=>true]);
    }

    // ---------- ORDERS ----------
    if ($action === 'get_orders') {
        $res = $conn->query("SELECT * FROM orders ORDER BY id DESC LIMIT 200");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        jsonResponse(['success'=>true,'orders'=>$rows]);
    }
    if ($action === 'set_order_status') {
        $id     = (int)($_POST['id']     ?? 0);
        $status = clean($conn, $_POST['status'] ?? '');
        $conn->query("UPDATE orders SET status='$status' WHERE id=$id");
        jsonResponse(['success'=>true]);
    }
    if ($action === 'cancel_and_refund') {
        $id  = (int)($_POST['id']  ?? 0);
        $uid = clean($conn, $_POST['uid']    ?? '');
        $amt = (float)($_POST['amount']      ?? 0);
        $balRes = $conn->query("SELECT balance FROM users WHERE uid='$uid' LIMIT 1");
        if ($balRes->num_rows === 0) jsonResponse(['success'=>false,'message'=>'User not found']);
        $curBal = (float)$balRes->fetch_assoc()['balance'];
        $newBal = round($curBal + $amt, 2);
        $conn->query("UPDATE orders SET status='Cancelled' WHERE id=$id");
        $conn->query("UPDATE users SET balance=$newBal WHERE uid='$uid'");
        jsonResponse(['success'=>true,'newBalance'=>$newBal]);
    }
    if ($action === 'delete_order') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM orders WHERE id=$id");
        jsonResponse(['success'=>true]);
    }

    // ---------- DEPOSITS ----------
    if ($action === 'get_deposits') {
        $res = $conn->query("SELECT * FROM deposits ORDER BY id DESC LIMIT 200");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        jsonResponse(['success'=>true,'deposits'=>$rows]);
    }
    if ($action === 'approve_deposit') {
        $id  = (int)($_POST['id']  ?? 0);
        $uid = clean($conn, $_POST['uid']    ?? '');
        $amt = (float)($_POST['amount']      ?? 0);
        $balRes = $conn->query("SELECT balance FROM users WHERE uid='$uid' LIMIT 1");
        if ($balRes->num_rows === 0) jsonResponse(['success'=>false,'message'=>'User not found']);
        $curBal = (float)$balRes->fetch_assoc()['balance'];
        $newBal = round($curBal + $amt, 2);
        $conn->query("UPDATE users SET balance=$newBal WHERE uid='$uid'");
        $conn->query("UPDATE deposits SET status='Completed' WHERE id=$id");
        jsonResponse(['success'=>true,'newBalance'=>$newBal]);
    }
    if ($action === 'reject_deposit') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("UPDATE deposits SET status='Cancelled' WHERE id=$id");
        jsonResponse(['success'=>true]);
    }
    if ($action === 'delete_deposit') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM deposits WHERE id=$id");
        jsonResponse(['success'=>true]);
    }

    // ---------- API SETTINGS ----------
    if ($action === 'get_admin_settings') {
        $res = $conn->query("SELECT * FROM admin_settings LIMIT 1");
        $row = $res->fetch_assoc() ?: [];
        jsonResponse(['success'=>true,'settings'=>$row]);
    }
    if ($action === 'save_api_settings') {
        $url       = $conn->real_escape_string($_POST['api_url']               ?? '');
        $key       = $conn->real_escape_string($_POST['api_key']               ?? '');
        $enabled   = (int)($_POST['auto_order_enabled']                        ?? 1);
        $payApiUrl = $conn->real_escape_string($_POST['payment_verify_api_url'] ?? '');

        // admin_settings row না থাকলে তৈরি করো
        $chk = $conn->query("SELECT id FROM admin_settings WHERE id=1 LIMIT 1");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("INSERT INTO admin_settings (id) VALUES (1)");
        }

        // payment_verify_api_url column আছে কিনা চেক করো, না থাকলে যোগ করো
        $colChk = $conn->query("SHOW COLUMNS FROM admin_settings LIKE 'payment_verify_api_url'");
        if ($colChk && $colChk->num_rows === 0) {
            $conn->query("ALTER TABLE admin_settings ADD COLUMN payment_verify_api_url VARCHAR(500) DEFAULT NULL");
        }

        // auto_order_enabled column আছে কিনা চেক করো
        $colChk2 = $conn->query("SHOW COLUMNS FROM admin_settings LIKE 'auto_order_enabled'");
        if ($colChk2 && $colChk2->num_rows === 0) {
            $conn->query("ALTER TABLE admin_settings ADD COLUMN auto_order_enabled TINYINT DEFAULT 1");
        }

        $ok = $conn->query(
            "UPDATE admin_settings SET
                api_url='$url',
                api_key='$key',
                auto_order_enabled=$enabled,
                payment_verify_api_url='$payApiUrl'
             WHERE id=1"
        );

        if ($ok) {
            jsonResponse(['success' => true]);
        } else {
            jsonResponse(['success' => false, 'message' => $conn->error]);
        }
        exit;
    }

    // ---------- GENERAL SETTINGS ----------
    if ($action === 'save_settings') {
        ensureSchema($conn); // SHOW COLUMNS শুধু একবার চলে
        $logo    = clean($conn, $_POST['app_logo']    ?? '');
        $notice  = clean($conn, $_POST['notice']      ?? '');
        $minDep  = (float)($_POST['min_deposit']      ?? 10);
        $tgBot   = clean($conn, $_POST['tg_bot_token'] ?? '');
        $tgChat  = clean($conn, $_POST['tg_chat_id']  ?? '');
        $refBonus = (float)($_POST['referral_bonus_per_ref'] ?? 5);
        $sliderItems = clean($conn, $_POST['slider_items'] ?? '');
        $conn->query("UPDATE admin_settings SET app_logo='$logo',notice='$notice',min_deposit=$minDep,slider_items='$sliderItems',tg_bot_token='$tgBot',tg_chat_id='$tgChat',referral_bonus_per_ref=$refBonus WHERE id=1");
        jsonResponse(['success'=>true]);
    }

    // ---------- GET ALL USER HISTORY ----------
    if ($action === 'get_all_user_history') {
        $orders = $conn->query("SELECT o.order_id, o.service_name, o.amount, o.status, o.created_at, u.username, u.tg_username, u.profile_pic, u.tg_photo, 'Order' as type FROM orders o LEFT JOIN users u ON o.uid=u.uid ORDER BY o.id DESC LIMIT 100");
        $history = [];
        while($r=$orders->fetch_assoc()) $history[]=$r;
        $deps = $conn->query("SELECT d.trx_id, d.method, d.amount, d.status, d.created_at, u.username, u.tg_username, u.profile_pic, u.tg_photo, 'Deposit' as type FROM deposits d LEFT JOIN users u ON d.uid=u.uid ORDER BY d.id DESC LIMIT 100");
        while($r=$deps->fetch_assoc()) $history[]=$r;
        $regs = $conn->query("SELECT u.username, u.tg_username, u.profile_pic, u.tg_photo, u.created_at, 'NewUser' as type FROM users u ORDER BY u.id DESC LIMIT 50");
        while($r=$regs->fetch_assoc()) $history[]=$r;
        usort($history, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
        jsonResponse(['success'=>true,'history'=>array_slice($history,0,150)]);
    }

    exit;
}

// ====================================================
// Check Admin Session
// ====================================================
$adminLoggedIn = !empty($_SESSION['admin_email']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Smm Elite Panel - Admin Master</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root {
    --primary: #4f46e5; --primary-dark: #4338ca; --bg-body: #f1f5f9;
    --sidebar-bg: #0f172a; --sidebar-w: 280px;
    --card-shadow: 0 10px 15px -3px rgba(0,0,0,0.1),0 4px 6px -2px rgba(0,0,0,0.05);
    --radius: 16px;
    --gradient-1: linear-gradient(135deg,#667eea 0%,#764ba2 100%);
    --gradient-2: linear-gradient(135deg,#f093fb 0%,#f5576c 100%);
    --gradient-3: linear-gradient(135deg,#4facfe 0%,#00f2fe 100%);
    --gradient-4: linear-gradient(135deg,#43e97b 0%,#38f9d7 100%);
    --gradient-5: linear-gradient(135deg,#fa709a 0%,#fee140 100%);
    --gradient-6: linear-gradient(135deg,#a8edea 0%,#fed6e3 100%);
    --gradient-7: linear-gradient(135deg,#ff9a9e 0%,#fecfef 100%);
}
body { font-family:'Inter',sans-serif; background:var(--bg-body); font-size:14px; color:#334155; overflow-x:hidden; }
#login-overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); z-index:9999; display:flex; align-items:center; justify-content:center; }
.login-box { width:90%; max-width:420px; padding:40px 30px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); border-radius:24px; text-align:center; background:white; }
.login-box h3 { background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
#admin-panel { display:none; }
.sidebar-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1040; transition:opacity 0.3s; backdrop-filter:blur(4px); }
.sidebar { width:var(--sidebar-w); height:100vh; position:fixed; background:var(--sidebar-bg); color:#fff; top:0; left:0; padding-top:0; transition:0.3s; z-index:1050; overflow-y:auto; border-right:1px solid rgba(255,255,255,0.1); }
.sidebar-header { height:70px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(255,255,255,0.05); border-bottom:1px solid rgba(255,255,255,0.1); }
.sidebar-header h5 { margin:0; font-weight:700; color:#fff; letter-spacing:0.5px; font-size:16px; }
.app-logo-img { max-height:40px; width:auto; object-fit:contain; }
.sidebar a { color:#94a3b8; padding:14px 20px; display:flex; align-items:center; text-decoration:none; border-left:3px solid transparent; font-weight:500; transition:0.2s; font-size:14px; }
.sidebar a:hover,.sidebar a.active { background:rgba(255,255,255,0.08); color:#fff; border-left-color:var(--primary); }
.sidebar i { width:28px; font-size:16px; opacity:0.8; }
.close-sidebar-btn { cursor:pointer; display:none; font-size:20px; color:#fff; }
.top-bar { height:70px; background:#fff; position:fixed; top:0; left:var(--sidebar-w); right:0; box-shadow:0 1px 3px 0 rgba(0,0,0,0.1); z-index:999; display:flex; align-items:center; justify-content:space-between; padding:0 25px; transition:0.3s; }
.top-bar-title { display:flex; align-items:center; gap:15px; }
.three-dot-menu { position:relative; }
.three-dot-btn { width:40px; height:40px; border-radius:10px; border:1px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:18px; transition:0.2s; }
.three-dot-btn:hover { background:#f1f5f9; color:var(--primary); border-color:var(--primary); }
.three-dot-dropdown { position:absolute; top:50px; right:0; background:#fff; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.15); border:1px solid #e2e8f0; min-width:180px; display:none; z-index:1000; overflow:hidden; }
.three-dot-dropdown.show { display:block; animation:slideDown 0.2s ease; }
@keyframes slideDown { from{opacity:0;transform:translateY(-10px);}to{opacity:1;transform:translateY(0);} }
.three-dot-dropdown a { display:flex; align-items:center; gap:10px; padding:12px 16px; color:#334155; text-decoration:none; font-size:14px; font-weight:500; transition:0.2s; }
.three-dot-dropdown a:hover { background:#f1f5f9; color:var(--primary); }
.three-dot-dropdown a.logout { color:#ef4444; border-top:1px solid #e2e8f0; }
.three-dot-dropdown a.logout:hover { background:#fee2e2; }
.main-content { margin-left:var(--sidebar-w); margin-top:70px; padding:30px; transition:0.3s; }
.card-custom { background:#fff; border-radius:var(--radius); box-shadow:var(--card-shadow); border:1px solid #e2e8f0; margin-bottom:20px; padding:25px; position:relative; }
.page-title { font-weight:700; font-size:22px; color:#1e293b; margin-bottom:25px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:15px; }
.form-label-custom { font-weight:600; font-size:13px; color:#475569; margin-bottom:8px; display:block; }
.form-control,.form-select { padding:12px 16px; border-radius:10px; border:1px solid #cbd5e1; font-size:14px; box-shadow:none; transition:0.2s; }
.form-control:focus,.form-select:focus { border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,0.1); }
.password-input-group { position:relative; }
.password-toggle { position:absolute; right:15px; top:50%; transform:translateY(-50%); color:#94a3b8; cursor:pointer; font-size:16px; transition:0.2s; z-index:10; }
.password-toggle:hover { color:var(--primary); }
.cursor-pointer { cursor:pointer; }
.btn { padding:10px 20px; border-radius:10px; font-weight:600; font-size:13px; transition:0.2s; }
.btn:hover { transform:translateY(-2px); }
.action-btn-group { display:flex; gap:6px; }
.action-btn { width:34px; height:34px; display:flex; align-items:center; justify-content:center; border-radius:8px; border:1px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer; transition:0.2s; }
.action-btn:hover { background:var(--primary); color:#fff; border-color:var(--primary); transform:translateY(-2px); }
.action-btn.delete:hover { background:#ef4444; color:#fff; border-color:#ef4444; }
.dep-action-box { display:inline-flex; border-radius:10px; overflow:hidden; border:1px solid #dc3545; }
.dep-btn { border:none; padding:8px 16px; font-size:14px; cursor:pointer; display:flex; align-items:center; justify-content:center; height:38px; transition:0.2s; }
.dep-btn-check { background:#198754; color:white; border-right:1px solid #146c43; }
.dep-btn-check:hover { background:#146c43; }
.dep-btn-cross { background:#dc3545; color:white; border-right:1px solid #b02a37; }
.dep-btn-cross:hover { background:#b02a37; }
.dep-btn-trash { background:white; color:#dc3545; }
.dep-btn-trash:hover { background:#f8d7da; }
.badge-method { font-size:12px; padding:6px 12px; border-radius:8px; color:white; font-weight:600; letter-spacing:0.5px; }
.service-id-badge { font-size:11px; font-family:monospace; padding:5px 10px; background:#f8fafc; color:#64748b; border:1px solid #e2e8f0; border-radius:8px; font-weight:600; display:inline-block; margin:2px 0; }
.api-id-badge { background:#dbeafe; color:#1e40af; border-color:#93c5fd; }
.stat-box { background:#fff; border-radius:var(--radius); padding:18px 20px; box-shadow:var(--card-shadow); border:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; height:100%; transition:transform 0.3s,box-shadow 0.3s; position:relative; overflow:hidden; }
.stat-box:hover { transform:translateY(-5px); box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04); }
.stat-box::before { content:''; position:absolute; top:0; left:0; width:5px; height:100%; border-radius:4px 0 0 4px; }
.stat-box.stat-1::before { background:var(--gradient-1); }
.stat-box.stat-2::before { background:var(--gradient-2); }
.stat-box.stat-3::before { background:var(--gradient-3); }
.stat-box.stat-4::before { background:var(--gradient-4); }
.stat-box.stat-5::before { background:var(--gradient-5); }
.stat-box.stat-6::before { background:var(--gradient-6); }
.stat-box.stat-7::before { background:var(--gradient-7); }
.stat-icon { width:55px; height:55px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; }
.stat-1 .stat-icon { background:linear-gradient(135deg,rgba(102,126,234,0.1) 0%,rgba(118,75,162,0.1) 100%); color:#667eea; }
.stat-2 .stat-icon { background:linear-gradient(135deg,rgba(240,147,251,0.1) 0%,rgba(245,87,108,0.1) 100%); color:#f5576c; }
.stat-3 .stat-icon { background:linear-gradient(135deg,rgba(79,172,254,0.1) 0%,rgba(0,242,254,0.1) 100%); color:#4facfe; }
.stat-4 .stat-icon { background:linear-gradient(135deg,rgba(67,233,123,0.1) 0%,rgba(56,249,215,0.1) 100%); color:#43e97b; }
.stat-5 .stat-icon { background:linear-gradient(135deg,rgba(250,112,154,0.1) 0%,rgba(254,225,64,0.1) 100%); color:#fa709a; }
.stat-6 .stat-icon { background:linear-gradient(135deg,rgba(168,237,234,0.1) 0%,rgba(254,214,227,0.1) 100%); color:#a8edea; }
.stat-7 .stat-icon { background:linear-gradient(135deg,rgba(255,154,158,0.1) 0%,rgba(254,207,239,0.1) 100%); color:#ff9a9e; }
.stat-box h6 { font-size:11px; color:#64748b; font-weight:600; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px; }
.stat-box h4 { font-size:24px; font-weight:800; color:#1e293b; margin:0; }
.user-avatar { width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); color:#fff; font-weight:bold; display:flex; align-items:center; justify-content:center; font-size:16px; }
.table-responsive { overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:var(--radius); }
.table-custom { width:100%; border-collapse:separate; border-spacing:0; white-space:nowrap; }
.table-custom th { background:#f8fafc; color:#64748b; font-weight:700; font-size:11px; padding:15px; border-bottom:2px solid #e2e8f0; text-transform:uppercase; letter-spacing:0.5px; }
.table-custom td { padding:15px; border-bottom:1px solid #f1f5f9; vertical-align:middle; color:#334155; }
.table-custom tr:hover { background:#f8fafc; }
.info-box-blue { background-color:#e0f2fe; border:1px solid #bae6fd; color:#0369a1; padding:15px; border-radius:10px; font-size:13px; margin-bottom:20px; }
.api-test-box { background:#f1f5f9; padding:20px; border-radius:10px; margin:20px 0; border-left:4px solid var(--primary); }
.api-test-result { margin-top:15px; padding:15px; border-radius:10px; font-size:13px; }
.api-test-result.success { background:#dcfce7; color:#166534; }
.api-test-result.error { background:#fee2e2; color:#991b1b; }
.min-deposit-badge { font-size:11px; padding:4px 10px; border-radius:6px; background:#fef3c7; color:#92400e; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
/* Pagination styles */
.usr-pagination { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-top:15px; padding:10px 0; }
.usr-pagination .pg-info { font-size:12px; color:#64748b; }
.usr-pagination .pg-btns { display:flex; gap:6px; align-items:center; }
.pg-btn { padding:6px 14px; border-radius:8px; border:1px solid #e2e8f0; background:#fff; cursor:pointer; font-size:12px; font-weight:600; color:#475569; transition:0.2s; }
.pg-btn:hover:not(:disabled) { background:var(--primary); color:#fff; border-color:var(--primary); }
.pg-btn:disabled { opacity:0.4; cursor:not-allowed; }
.pg-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
/* Loading skeleton */
.skeleton-row td { background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%); background-size:200% 100%; animation:shimmer 1.5s infinite; border-radius:4px; height:20px; }
@keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
@media(max-width:992px){
    .sidebar{left:-100%;width:300px;}.sidebar.active{left:0;}.sidebar-overlay.active{display:block;}
    .top-bar{left:0;margin-left:0;}.main-content{left:0;margin-left:0;padding:20px 15px;}
    .close-sidebar-btn{display:block;}.page-title{font-size:18px;}
    .page-title button,.page-title input{width:100%;margin-top:10px;}
    .card-custom{padding:20px;border-radius:12px;}.stat-box{margin-bottom:12px;padding:16px 18px;}
    .stat-box h4{font-size:22px;}.stat-icon{width:50px;height:50px;font-size:20px;}
    .form-control{padding:10px 14px;}.table-custom th,.table-custom td{padding:10px 6px;font-size:12px;}
    .action-btn{width:32px;height:32px;}
}
@media(max-width:576px){
    .stat-box{padding:14px 16px;}.stat-icon{width:45px;height:45px;font-size:18px;}.stat-box h4{font-size:20px;}
    .login-box{padding:30px 20px;}.top-bar{padding:0 15px;}.main-content{padding:15px 10px;}
}
</style>
</head>
<body>

<!-- AUTH SCREEN -->
<div id="login-overlay">
  <div class="login-box">
    <i class="fas fa-shield-halved fa-3x mb-3" style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;"></i>
    <h3 class="fw-bold mb-2">ADMIN LOGIN</h3>
    <p class="text-muted mb-4 small">Secure Access for Smm Elite Panel</p>
    <form id="loginForm">
      <input type="email" id="admEmail" class="form-control mb-3" placeholder="Email Address" required>
      <div class="password-input-group mb-4">
        <input type="password" id="admPass" class="form-control" placeholder="Password" required>
        <i class="far fa-eye password-toggle" onclick="toggleLoginPass()"></i>
      </div>
      <button class="btn btn-primary w-100 py-3 fw-bold" style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border:none;">
        <i class="fas fa-right-to-bracket me-2"></i> Login to Console
      </button>
    </form>
  </div>
</div>

<!-- ADMIN PANEL -->
<div id="admin-panel">
  <div class="sidebar-overlay" onclick="closeMenu()"></div>

  <!-- Sidebar -->
  <div class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <h5 id="appLogoDisplay">Smm Elite Panel</h5>
      <i class="fas fa-times close-sidebar-btn" onclick="closeMenu()"></i>
    </div>
    <div class="mt-3">
      <a href="#" class="active" onclick="tab('dash',this)"><i class="fas fa-chart-pie"></i> Dashboard</a>
      <a href="#" onclick="tab('usr',this)"><i class="fas fa-users-gear"></i> Users Manager</a>
      <a href="#" onclick="tab('ref',this)"><i class="fas fa-gift"></i> Referrals</a>
      <a href="#" onclick="tab('ord',this)"><i class="fas fa-cart-shopping"></i> Orders</a>
      <a href="#" onclick="tab('cats',this)"><i class="fas fa-layer-group"></i> Categories</a>
      <a href="#" onclick="tab('serv',this)"><i class="fas fa-list-check"></i> Services</a>
      <a href="#" onclick="tab('pay',this)"><i class="fas fa-wallet"></i> Payments</a>
      <a href="#" onclick="tab('dep',this)"><i class="fas fa-money-bill-transfer"></i> Deposits</a>
      <a href="#" onclick="tab('api',this)"><i class="fas fa-link"></i> API Settings</a>
      <a href="#" onclick="tab('set',this)"><i class="fas fa-sliders"></i> Settings</a>
    </div>
  </div>

  <!-- Topbar -->
  <div class="top-bar">
    <div class="top-bar-title">
      <i class="fas fa-bars fs-4 d-lg-none cursor-pointer" onclick="openMenu()" style="color:#667eea;"></i>
      <span class="fw-bold text-secondary d-none d-lg-block" style="font-size:16px;">Admin Panel</span>
    </div>
    <div class="three-dot-menu">
      <button class="three-dot-btn" onclick="toggleThreeDot()"><i class="fas fa-ellipsis-v"></i></button>
      <div class="three-dot-dropdown" id="threeDotDropdown">
        <a href="#" onclick="showAdminInfo()"><i class="fas fa-user-shield"></i> Admin Info</a>
        <a href="#" class="logout" onclick="logout()"><i class="fas fa-right-from-bracket"></i> Logout</a>
      </div>
    </div>
  </div>

  <!-- Content -->
  <div class="main-content">

    <!-- 1. DASHBOARD -->
    <div id="dash-sec" class="app-sec">
      <div class="page-title">
        <span><i class="fas fa-chart-pie me-2" style="color:#667eea;"></i>Dashboard Overview</span>
        <span class="badge bg-primary rounded-pill px-3 py-2" id="currentTime"></span>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6 col-md-6"><div class="stat-box stat-1"><div><h6 class="text-muted small fw-bold mb-2">Total Users</h6><h4 class="mb-0 fw-bold" id="d-user">0</h4></div><div class="stat-icon"><i class="fas fa-users"></i></div></div></div>
        <div class="col-6 col-md-6"><div class="stat-box stat-2"><div><h6 class="text-muted small fw-bold mb-2">Active Users</h6><h4 class="mb-0 fw-bold text-success" id="d-active">0</h4></div><div class="stat-icon"><i class="fas fa-user-check"></i></div></div></div>
        <div class="col-6 col-md-6"><div class="stat-box stat-3"><div><h6 class="text-muted small fw-bold mb-2">User Balance</h6><h4 class="mb-0 fw-bold text-primary" id="d-bal">0</h4></div><div class="stat-icon"><i class="fas fa-wallet"></i></div></div></div>
        <div class="col-6 col-md-6"><div class="stat-box stat-4"><div><h6 class="text-muted small fw-bold mb-2">Pending Orders</h6><h4 class="mb-0 fw-bold text-warning" id="d-pen">0</h4></div><div class="stat-icon"><i class="fas fa-clock"></i></div></div></div>
        <div class="col-6 col-md-6"><div class="stat-box stat-5"><div><h6 class="text-muted small fw-bold mb-2">Completed</h6><h4 class="mb-0 fw-bold text-success" id="d-comp">0</h4></div><div class="stat-icon"><i class="fas fa-circle-check"></i></div></div></div>
        <div class="col-6 col-md-6"><div class="stat-box stat-6"><div><h6 class="text-muted small fw-bold mb-2">Cancelled</h6><h4 class="mb-0 fw-bold text-danger" id="d-can">0</h4></div><div class="stat-icon"><i class="fas fa-circle-xmark"></i></div></div></div>
        <div class="col-6 col-md-6"><div class="stat-box stat-7"><div><h6 class="text-muted small fw-bold mb-2">Total Orders</h6><h4 class="mb-0 fw-bold text-info" id="d-ord">0</h4></div><div class="stat-icon"><i class="fas fa-bag-shopping"></i></div></div></div>
        <div class="col-6 col-md-6"><div class="stat-box stat-1"><div><h6 class="text-muted small fw-bold mb-2">Total Referrals</h6><h4 class="mb-0 fw-bold text-primary" id="d-ref">0</h4></div><div class="stat-icon"><i class="fas fa-gift"></i></div></div></div>
        <div class="col-6 col-md-6"><div class="stat-box stat-4"><div><h6 class="text-muted small fw-bold mb-2">Referral Bonus Paid</h6><h4 class="mb-0 fw-bold text-success" id="d-refbon">৳0</h4></div><div class="stat-icon"><i class="fas fa-coins"></i></div></div></div>
      </div>
      <div class="page-title fs-6 mt-4 border-bottom pb-3">
        <span><i class="fas fa-layer-group me-2" style="color:#667eea;"></i>Category Analytics</span>
      </div>
      <div class="row g-3" id="cat-analytics"></div>
    </div>

    <!-- 2. API SETTINGS -->
    <div id="api-sec" class="app-sec" style="display:none;">
      <div class="card-custom">
        <div class="d-flex justify-content-between border-bottom pb-3 mb-4">
          <h5 class="m-0"><i class="fas fa-link me-2" style="color:#667eea;"></i>airsmm API Configuration</h5>
          <button class="btn btn-sm btn-outline-primary" onclick="testApiConnection()"><i class="fas fa-plug me-1"></i> Test Connection</button>
        </div>
        <div class="info-box-blue"><i class="fas fa-info-circle me-1"></i><strong>How it works:</strong> When a user places an order, it will automatically send a request to this API. Service ID (providerId) must match the API's service ID for auto-ordering to work.</div>
        <label class="form-label-custom">API URL</label>
        <input type="text" id="apiUrl" class="form-control mb-3" placeholder="https://airsmm.site/?api=1" value="https://airsmm.site/?api=1">
        <label class="form-label-custom">API Key</label>
        <div class="password-input-group mb-3">
          <input type="password" id="apiKey" class="form-control" placeholder="Enter your API key">
          <i class="far fa-eye password-toggle" onclick="toggleApiKey()"></i>
        </div>
        <label class="form-label-custom">Enable Auto-Order</label>
        <select id="apiEnabled" class="form-select mb-3">
          <option value="1">✅ Yes - Auto-send orders to API</option>
          <option value="0">❌ No - Manual order processing</option>
        </select>

        <!-- Payment Verification API -->
        <div class="info-box-blue" style="background:linear-gradient(135deg,rgba(14,165,233,0.08),rgba(37,99,235,0.08));border-color:#0ea5e9;margin-bottom:12px;">
          <i class="fas fa-shield-alt me-1" style="color:#0ea5e9;"></i><strong>Payment Verify API URL</strong> — ব্যবহারকারীরা পেমেন্ট ভেরিফাই করার সময় এই GET API থেকে ট্রানজেকশন ডেটা নেওয়া হয়।
        </div>
        <label class="form-label-custom">🔗 Payment Verify API URL (GET Method)</label>
        <div class="input-group mb-1">
          <input type="text" id="payVerifyApiUrl" class="form-control" placeholder="https://example-rtdb.firebaseio.com/PATH/.json" value="https://smmgemphp-default-rtdb.firebaseio.com/XNXANIKPAY/.json">
          <button class="btn btn-outline-secondary" type="button" onclick="testPayVerifyApi()" title="Test API"><i class="fas fa-flask"></i></button>
        </div>
        <small class="text-muted mb-3 d-block" style="font-size:11px;">এই URL টি GET request করলে JSON response আসতে হবে যেখানে প্রতিটি entry তে <code>txid</code> ও <code>amount</code> ফিল্ড আছে।</small>
        <div id="payApiTestResult" style="display:none;margin-bottom:12px;border-radius:8px;padding:10px;font-size:12px;"></div>
        <div class="api-test-box">
          <h6 class="fw-bold mb-2"><i class="fas fa-flask me-1"></i> Quick Test</h6>
          <p class="small mb-2">Test your API credentials by fetching available services:</p>
          <button class="btn btn-sm btn-outline-secondary" onclick="fetchApiServices()"><i class="fas fa-sync me-1"></i> Fetch Services</button>
          <div id="apiTestResult" class="api-test-result" style="display:none;"></div>
        </div>
        <button class="btn btn-primary w-100 mt-3 py-2" onclick="saveApiSettings()" style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border:none;">
          <i class="fas fa-save me-2"></i> Save API Settings
        </button>
      </div>
    </div>

    <!-- 3. PAYMENTS -->
    <div id="pay-sec" class="app-sec" style="display:none;">
      <div class="card-custom">
        <div class="page-title"><span><i class="fas fa-wallet me-2" style="color:#667eea;"></i>Payment Gateways</span></div>
        <div class="bg-light p-4 rounded border mb-4" style="border-radius:12px!important;background:linear-gradient(135deg,rgba(102,126,234,0.05) 0%,rgba(118,75,162,0.05) 100%)!important;">
          <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-circle-plus me-2"></i>Add New Method</h6>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label-custom">Method Name</label><input id="pName" class="form-control" placeholder="Ex: Bkash"></div>
            <div class="col-md-6"><label class="form-label-custom">Wallet/Number</label><input id="pNum" class="form-control" placeholder="017XXXXXXXX"></div>
            <div class="col-md-6"><label class="form-label-custom">Account Type</label><select id="pType" class="form-select"><option value="Personal (Send Money)">Personal (Send Money)</option><option value="Agent (Cash Out)">Agent (Cash Out)</option><option value="Merchant (Payment)">Merchant (Payment)</option></select></div>
            <div class="col-md-6"><label class="form-label-custom">Minimum Deposit (৳)</label><input id="pMinDep" type="number" class="form-control" placeholder="e.g., 50" value="10" min="1"><small class="text-muted" style="font-size:10px;">Users cannot deposit less than this amount</small></div>
            <div class="col-md-6"><label class="form-label-custom">Logo URL</label><input id="pLogo" class="form-control" placeholder="https://..."></div>
            <div class="col-12"><button class="btn btn-success w-100 py-2" onclick="addPay()"><i class="fas fa-plus me-2"></i> Add Payment Method</button></div>
          </div>
        </div>
        <div class="row g-3" id="payList"></div>
      </div>
    </div>

    <!-- 4. CATEGORIES -->
    <div id="cats-sec" class="app-sec" style="display:none;">
      <div class="card-custom">
        <div class="page-title"><span><i class="fas fa-layer-group me-2" style="color:#667eea;"></i>Category Management</span></div>
        <div class="bg-light p-3 rounded border mb-3" style="border-radius:12px!important;">
          <div class="row g-2">
            <div class="col-md-5"><input id="newCat" class="form-control" placeholder="New Category Name"></div>
            <div class="col-md-5"><input id="newCatImg" class="form-control" placeholder="Icon URL (Optional)"></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" onclick="addCat()"><i class="fas fa-plus me-1"></i> Add</button></div>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table-custom"><thead><tr><th>Name</th><th>Status</th><th>Actions</th><th>Sort</th></tr></thead><tbody id="catTbl"></tbody></table>
        </div>
      </div>
    </div>

    <!-- 5. SERVICES -->
    <div id="serv-sec" class="app-sec" style="display:none;">
      <div class="card-custom">
        <div class="page-title">
          <span><i class="fas fa-list-check me-2" style="color:#667eea;"></i>Services Management</span>
          <button class="btn btn-primary btn-sm" onclick="toggleServForm()"><i class="fas fa-plus me-1"></i> Add New</button>
        </div>
        <div class="info-box-blue mb-3"><i class="fas fa-lightbulb me-1"></i><strong>Important:</strong> The <b>Provider ID (API)</b> field must contain the exact Service ID from airsmm API. This ID is used for auto-ordering. Without it, orders will fail.</div>
        <div id="servForm" style="display:none;" class="bg-light p-3 rounded mb-3 border">
          <h6 class="fw-bold mb-3">New Service</h6>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label-custom">Category</label><select id="sCat" class="form-select"></select></div>
            <div class="col-md-6"><label class="form-label-custom">Provider ID (API Service ID) ⚠️</label><input id="sProvId" class="form-control" placeholder="Enter API Service ID (e.g., 4567)" required><small class="text-muted">This ID must match airsmm API's service ID</small></div>
            <div class="col-md-12"><label class="form-label-custom">Service Name</label><input id="sName" class="form-control" placeholder="Facebook Likes - High Quality"></div>
            <div class="col-4"><label class="form-label-custom">Rate (per 1k)</label><input id="sRate" type="number" step="0.01" class="form-control"></div>
            <div class="col-4"><label class="form-label-custom">Min Order</label><input id="sMin" type="number" class="form-control" value="10"></div>
            <div class="col-4"><label class="form-label-custom">Max Order</label><input id="sMax" type="number" class="form-control" value="10000"></div>
            <div class="col-12"><label class="form-label-custom">Description</label><textarea id="sDesc" class="form-control" rows="2" placeholder="Service description for users"></textarea></div>
            <div class="col-12"><button class="btn btn-success w-100 mt-2" onclick="addServ()"><i class="fas fa-save me-2"></i> Save Service</button></div>
          </div>
        </div>
        <div id="servEditForm" style="display:none;" class="bg-light p-3 rounded mb-4 border border-primary">
          <h6 class="fw-bold text-primary mb-3">Edit Service</h6>
          <input type="hidden" id="editServId">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label-custom">Category</label><select id="editSCat" class="form-select"></select></div>
            <div class="col-md-6"><label class="form-label-custom">Provider ID (API) ⚠️</label><input id="editSProvId" class="form-control" placeholder="API Service ID"></div>
            <div class="col-md-12"><label class="form-label-custom">Name</label><input id="editSName" class="form-control"></div>
            <div class="col-4"><label class="form-label-custom">Rate</label><input id="editSRate" type="number" step="0.01" class="form-control"></div>
            <div class="col-4"><label class="form-label-custom">Min</label><input id="editSMin" type="number" class="form-control"></div>
            <div class="col-4"><label class="form-label-custom">Max</label><input id="editSMax" type="number" class="form-control"></div>
            <div class="col-12"><label class="form-label-custom">Description</label><textarea id="editSDesc" class="form-control" rows="2"></textarea></div>
            <div class="col-12 d-flex gap-2 mt-2">
              <button class="btn btn-primary flex-fill" onclick="saveServChanges()"><i class="fas fa-check me-2"></i> Update Service</button>
              <button class="btn btn-secondary flex-fill" onclick="document.getElementById('servEditForm').style.display='none'"><i class="fas fa-xmark me-2"></i> Cancel</button>
            </div>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table-custom"><thead><tr><th>IDs</th><th>Service Name</th><th>Rate</th><th>Category</th><th>Status</th><th>Actions</th><th>Sort</th></tr></thead><tbody id="servTbl"></tbody></table>
        </div>
      </div>
    </div>

    <!-- 6. USERS -->
    <div id="usr-sec" class="app-sec" style="display:none;">
      <div class="card-custom">
        <div class="page-title">
          <span><i class="fas fa-users-gear me-2" style="color:#667eea;"></i>Users Manager</span>
          <input type="text" id="usrSearchInput" class="form-control w-50 form-control-sm" placeholder="নাম / ইমেইল / ফোন দিয়ে খুঁজুন..." oninput="debounceUsrSearch(this.value)">
        </div>
        <div id="userEditForm" style="display:none;" class="bg-light p-3 rounded mb-4 border border-primary">
          <h6 class="fw-bold text-primary mb-3">Edit User</h6>
          <input type="hidden" id="editUserId">
          <div class="row g-2">
            <div class="col-md-6"><input id="editName" class="form-control" placeholder="Name"></div>
            <div class="col-md-6"><input id="editPhone" class="form-control" placeholder="Phone"></div>
            <div class="col-md-6"><input id="editEmail" class="form-control" placeholder="Email"></div>
            <div class="col-md-6"><input id="editBal" type="number" step="0.01" class="form-control" placeholder="Balance"></div>
            <div class="col-12"><input id="editPass" class="form-control" placeholder="New Password (Optional)"></div>
            <div class="col-12 d-flex gap-2 mt-2">
              <button class="btn btn-primary flex-fill" onclick="saveUserChanges()"><i class="fas fa-check me-2"></i> Update</button>
              <button class="btn btn-secondary flex-fill" onclick="document.getElementById('userEditForm').style.display='none'"><i class="fas fa-xmark me-2"></i> Cancel</button>
            </div>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table-custom"><thead><tr><th>User</th><th>Contact</th><th>Balance</th><th>Status</th><th>Action</th></tr></thead><tbody id="usrTbl"></tbody></table>
        </div>
        <!-- Pagination -->
        <div class="usr-pagination" id="usrPagination" style="display:none;">
          <span class="pg-info" id="usrPgInfo"></span>
          <div class="pg-btns" id="usrPgBtns"></div>
        </div>
      </div>
    </div>

    <!-- REFERRAL SECTION -->
    <div id="ref-sec" class="app-sec" style="display:none;">
      <div class="card-custom">
        <div class="page-title"><span><i class="fas fa-gift me-2" style="color:#667eea;"></i>Referral Management</span></div>
        <div class="row g-2 mb-4">
          <div class="col-6"><div class="stat-box stat-1"><div><h6 class="text-muted small fw-bold mb-2">Total Referrals</h6><h4 class="mb-0 fw-bold text-primary" id="ref-total">0</h4></div><div class="stat-icon"><i class="fas fa-users"></i></div></div></div>
          <div class="col-6"><div class="stat-box stat-4"><div><h6 class="text-muted small fw-bold mb-2">Bonus Paid</h6><h4 class="mb-0 fw-bold text-success" id="ref-bonus">৳0</h4></div><div class="stat-icon"><i class="fas fa-coins"></i></div></div></div>
        </div>
        <div class="info-box-blue mb-3">
          <i class="fas fa-info-circle me-1"></i>
          প্রতি রেফারে বোনাস পরিমাণ Settings সেকশন থেকে পরিবর্তন করতে পারবেন।
        </div>
        <div class="table-responsive">
          <table class="table-custom">
            <thead>
              <tr>
                <th>User</th>
                <th>Referral Code</th>
                <th>Referrals</th>
                <th>Bonus Earned</th>
                <th>Joined</th>
              </tr>
            </thead>
            <tbody id="refTbl"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- 7. ORDERS -->
    <div id="ord-sec" class="app-sec" style="display:none;">
      <div class="card-custom">
        <div class="page-title">
          <span><i class="fas fa-cart-shopping me-2" style="color:#667eea;"></i>Orders</span>
          <input type="text" class="form-control w-25 form-control-sm" placeholder="ID..." oninput="debounceFilter('ordTbl',this.value)">
        </div>
        <div class="info-box-blue mb-3"><i class="fas fa-info-circle me-1"></i><strong>Auto-Order:</strong> Orders with <span class="badge bg-primary">API ID</span> were sent automatically to airsmm API.</div>
        <div class="table-responsive">
          <table class="table-custom"><thead><tr><th>Order ID</th><th>Link</th><th>Service</th><th>API ID</th><th>Charge</th><th>Status</th><th>Action</th></tr></thead><tbody id="ordTbl"></tbody></table>
        </div>
      </div>
    </div>

    <!-- 8. DEPOSITS -->
    <div id="dep-sec" class="app-sec" style="display:none;">
      <div class="card-custom">
        <div class="page-title"><span><i class="fas fa-money-bill-transfer me-2" style="color:#667eea;"></i>Deposits</span></div>
        <div class="table-responsive">
          <table class="table-custom"><thead><tr><th>Date & Time</th><th>TrxID</th><th>Method</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead><tbody id="depTbl"></tbody></table>
        </div>
      </div>
    </div>

    <!-- 9. SETTINGS -->
    <div id="set-sec" class="app-sec" style="display:none;">
      <div class="card-custom">
        <div class="page-title"><span><i class="fas fa-sliders me-2" style="color:#667eea;"></i>Settings</span></div>

        <label class="form-label-custom">App Logo URL</label>
        <input id="setLogo" class="form-control mb-3" placeholder="https://example.com/logo.png">

        <label class="form-label-custom">App Notice</label>
        <textarea id="setNotice" class="form-control mb-3" rows="3" placeholder="Notice displayed to users..."></textarea>

        <label class="form-label-custom">Global Minimum Deposit (৳)</label>
        <input id="setMinDep" type="number" class="form-control mb-3" value="10" min="1">

        <hr class="my-3">
        <h6 class="fw-bold mb-3" style="color:#667eea;"><i class="fas fa-gift me-2"></i>Referral Settings</h6>
        <label class="form-label-custom">প্রতি রেফারে বোনাস পরিমাণ (৳)</label>
        <input id="setRefBonus" type="number" step="0.01" class="form-control mb-1" value="5" min="0" placeholder="e.g., 5">
        <small class="text-muted d-block mb-3" style="font-size:11px;">একজন নতুন ইউজার রেফার লিংক দিয়ে যোগ দিলে রেফারার কত টাকা বোনাস পাবে</small>

        <hr class="my-4">
        <h6 class="fw-bold mb-3" style="color:#667eea;"><i class="fas fa-images me-2"></i>Slider / Banner Management</h6>
        <div class="info-box-blue mb-3">
          <i class="fas fa-info-circle me-1"></i>
          প্রতিটি স্লাইডে ইমেজ URL বা ভিডিও URL (YouTube/MP4) এবং কত সেকেন্ড পর পরের স্লাইডে যাবে তা দিন।
          ভিডিও লিংক দিলে ভিডিও প্লে হবে।
        </div>
        <div id="sliderItems"></div>
        <button class="btn btn-outline-primary btn-sm mb-3" onclick="addSliderItem()">
          <i class="fas fa-plus me-1"></i> নতুন স্লাইড যোগ করুন
        </button>

        <hr class="my-4">
        <h6 class="fw-bold mb-3" style="color:#667eea;"><i class="fab fa-telegram me-2"></i>Telegram Notification Settings</h6>
        <label class="form-label-custom">Bot Token</label>
        <input id="setTgBot" class="form-control mb-3" placeholder="8752277413:AAFtrhBfFPvlmRB08-kIZEG0uCC2xAwd5d8">
        <label class="form-label-custom">Channel/Group Chat ID</label>
        <input id="setTgChat" class="form-control mb-3" placeholder="-1003605988586">

        <button class="btn btn-primary py-2 w-100" onclick="saveSettings()" style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border:none;">
          <i class="fas fa-save me-2"></i> Save All Changes
        </button>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// =============================================
// GLOBAL STATE
// =============================================
let ADMIN_EMAIL = '';
let catsData    = [];
let servsData   = [];

// Users pagination state
let usrState = { page: 1, perPage: 25, total: 0, search: '', loading: false };

// Time display
setInterval(() => {
  const now = new Date();
  const timeStr = now.toLocaleString('en-US', { hour:'2-digit', minute:'2-digit', hour12:true });
  const dateStr = now.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
  const el = document.getElementById('currentTime');
  if (el) el.innerText = `${dateStr} ${timeStr}`;
}, 1000);

// =============================================
// INIT — শুধু Dashboard লোড করে, বাকিগুলো lazy
// =============================================
window.addEventListener('load', () => {
  <?php if ($adminLoggedIn): ?>
  ADMIN_EMAIL = '<?= htmlspecialchars($_SESSION['admin_email']) ?>';
  showPanel();
  init();
  <?php else: ?>
  document.getElementById('login-overlay').style.display = 'flex';
  document.getElementById('admin-panel').style.display   = 'none';
  <?php endif; ?>
});

function showPanel() {
  document.getElementById('login-overlay').style.display = 'none';
  document.getElementById('admin-panel').style.display   = 'block';
}

// =============================================
// LOGIN / LOGOUT
// =============================================
document.getElementById('loginForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const email = document.getElementById('admEmail').value;
  const pass  = document.getElementById('admPass').value;
  const res   = await apiCall({ action:'admin_login', email, password: pass });
  if (res.success) {
    ADMIN_EMAIL = res.email;
    showPanel();
    init();
  } else { Swal.fire('Error', res.message || 'Login Failed', 'error'); }
});

window.logout = async () => { await apiCall({ action:'admin_logout' }); location.reload(); };

// =============================================
// INIT DATA — শুধু Dashboard, বাকি tab-এ গেলে লোড হবে
// =============================================
function init() {
  loadDashboard();
  // অন্য সব tab lazy — ক্লিক করলে লোড হবে
}

// =============================================
// DASHBOARD
// =============================================
async function loadDashboard() {
  const res = await apiCall({ action:'get_dashboard' });
  if (!res.success) return;
  document.getElementById('d-user').innerText   = res.users;
  document.getElementById('d-active').innerText = res.active;
  document.getElementById('d-bal').innerText    = '৳' + parseFloat(res.totalBal).toFixed(2);
  document.getElementById('d-pen').innerHTML    = `<span class="badge bg-warning text-dark rounded-pill px-3 py-2">${res.pending}</span>`;
  document.getElementById('d-comp').innerHTML   = `<span class="badge bg-success rounded-pill px-3 py-2">${res.completed}</span>`;
  document.getElementById('d-can').innerHTML    = `<span class="badge bg-danger rounded-pill px-3 py-2">${res.cancelled}</span>`;
  document.getElementById('d-ord').innerHTML    = `<span class="badge bg-info rounded-pill px-3 py-2">${res.totalOrders}</span>`;
  const refEl    = document.getElementById('d-ref');
  const refBonEl = document.getElementById('d-refbon');
  if (refEl)    refEl.innerText    = res.totalReferrals || 0;
  if (refBonEl) refBonEl.innerText = '৳' + parseFloat(res.totalRefBonus || 0).toFixed(2);
  const div = document.getElementById('cat-analytics');
  const catHtml = (res.catAnalytics || []).map(c =>
    `<div class="col-6 col-md-6"><div class="stat-box h-100 stat-1"><div><h6 class="text-muted small fw-bold mb-1">${c.name}</h6><h5 class="mb-0 fw-bold">${c.svc_count}</h5></div><div class="stat-icon"><i class="fas fa-layer-group"></i></div></div></div>`
  ).join('');
  div.innerHTML = catHtml;
}

// =============================================
// CATEGORIES
// =============================================
async function loadCats() {
  const res = await apiCall({ action:'get_categories' });
  if (!res.success) return;
  catsData = res.categories;
  renderCats(catsData);
}

function renderCats(list) {
  const t  = document.getElementById('catTbl');
  const s  = document.getElementById('sCat');
  const es = document.getElementById('editSCat');
  if (!list.length) {
    t.innerHTML = '<tr><td colspan="4" class="text-center">No categories</td></tr>';
    s.innerHTML = es.innerHTML = '<option disabled selected>Select Category</option>';
    return;
  }
  list.sort((a,b)=>(a.sort_order-b.sort_order));
  const tRows = [], sOpts = ['<option disabled selected>Select Category</option>'];
  list.forEach(c => {
    sOpts.push(`<option value="${c.name}">${c.name}</option>`);
    const imgHtml = c.logo ? `<img src="${c.logo}" width="25" height="25" class="rounded-circle me-2 border">` : '';
    tRows.push(`<tr><td><div class="d-flex align-items-center">${imgHtml}<span class="fw-bold">${c.name}</span></div></td><td><span class="badge ${c.hidden?'bg-secondary':'bg-success'}">${c.hidden?'Hidden':'Active'}</span></td><td><div class="action-btn-group"><div class="action-btn" onclick="editCat(${c.id},'${c.name.replace(/'/g,"\\'")}')"><i class="fas fa-edit"></i></div><div class="action-btn" onclick="toggleCat(${c.id},${c.hidden?0:1})"><i class="fas ${c.hidden?'fa-eye-slash':'fa-eye'}"></i></div><div class="action-btn delete" onclick="delCat(${c.id})"><i class="fas fa-trash-alt"></i></div></div></td><td><i class="fas fa-chevron-up cursor-pointer me-2 text-secondary" onclick="sortCat(${c.id},${(c.sort_order||0)-1})"></i><i class="fas fa-chevron-down cursor-pointer text-secondary" onclick="sortCat(${c.id},${(c.sort_order||0)+1})"></i></td></tr>`);
  });
  t.innerHTML  = tRows.join('');
  s.innerHTML  = sOpts.join('');
  es.innerHTML = sOpts.join('');
}

window.addCat = async () => {
  const name = document.getElementById('newCat').value;
  const logo = document.getElementById('newCatImg').value;
  if (!name) return;
  await apiCall({ action:'add_category', name, logo });
  document.getElementById('newCat').value = '';
  loadCats();
};

window.editCat = (id, oldName) => {
  Swal.fire({ title:'Rename Category', input:'text', inputValue:oldName, showCancelButton:true, confirmButtonText:'Update' }).then(async r => {
    if (r.isConfirmed && r.value) { await apiCall({ action:'edit_category', id, name:r.value }); loadCats(); Swal.fire('Updated','','success'); }
  });
};

window.toggleCat = async (id, hidden) => { await apiCall({ action:'toggle_category', id, hidden }); loadCats(); };
window.delCat    = async (id) => { if (confirm('Delete?')) { await apiCall({ action:'delete_category', id }); loadCats(); } };
window.sortCat   = async (id, sort) => { await apiCall({ action:'sort_category', id, sort }); loadCats(); };

// =============================================
// SERVICES
// =============================================
async function loadServs() {
  const res = await apiCall({ action:'get_services' });
  if (!res.success) return;
  servsData = res.services;
  renderServs(servsData);
}

function renderServs(list) {
  const t = document.getElementById('servTbl');
  if (!list.length) { t.innerHTML = '<tr><td colspan="7" class="text-center">Empty</td></tr>'; return; }
  list.sort((a,b)=>a.sort_order-b.sort_order);
  const rows = list.map(s => {
    let idHtml = `<span class="service-id-badge" title="App Internal ID">#${s.id}</span>`;
    idHtml += s.provider_id
      ? `<br><span class="service-id-badge api-id-badge" title="API Service ID">API: ${s.provider_id}</span>`
      : `<br><span class="badge bg-warning text-dark" style="font-size:10px;">⚠️ No API ID</span>`;
    const escapedName = s.name.replace(/"/g,'&quot;').replace(/'/g,"&#39;");
    const escapedDesc = (s.description||'').replace(/"/g,'&quot;').replace(/'/g,"&#39;");
    return `<tr><td style="width:1%;vertical-align:top;">${idHtml}</td><td><div class="fw-bold text-truncate" style="max-width:150px;">${s.name}</div><small class="text-muted">${s.description||''}</small></td><td class="fw-bold">৳${parseFloat(s.rate).toFixed(2)}</td><td><span class="badge bg-light text-dark border">${s.cat||'-'}</span></td><td><span class="badge ${s.hidden?'bg-secondary':'bg-success'}">${s.hidden?'Hidden':'Active'}</span></td><td><div class="action-btn-group"><div class="action-btn" onclick="openServEdit(${s.id},'${escapedName}','${s.cat||''}','${s.provider_id||''}','${s.rate}','${s.min_order}','${s.max_order}','${escapedDesc}')"><i class="fas fa-edit"></i></div><div class="action-btn" onclick="toggleServ(${s.id},${s.hidden?0:1})"><i class="fas ${s.hidden?'fa-eye-slash':'fa-eye'}"></i></div><div class="action-btn delete" onclick="delServ(${s.id})"><i class="fas fa-trash-alt"></i></div></div></td><td><div class="d-flex flex-column align-items-center"><i class="fas fa-caret-up cursor-pointer text-secondary" onclick="sortServ(${s.id},${(s.sort_order||0)-1})"></i><i class="fas fa-caret-down cursor-pointer text-secondary" onclick="sortServ(${s.id},${(s.sort_order||0)+1})"></i></div></td></tr>`;
  });
  t.innerHTML = rows.join('');
}

window.addServ = async () => {
  const res = await apiCall({ action:'add_service', name:document.getElementById('sName').value, cat:document.getElementById('sCat').value, rate:document.getElementById('sRate').value, min:document.getElementById('sMin').value, max:document.getElementById('sMax').value, provider_id:document.getElementById('sProvId').value, description:document.getElementById('sDesc').value });
  if (res.success) { document.getElementById('servForm').style.display='none'; loadServs(); Swal.fire('Added','','success'); }
};

window.openServEdit = (id,n,c,pid,r,min,max,desc) => {
  document.getElementById('servEditForm').style.display='block';
  document.getElementById('editServId').value    = id;
  document.getElementById('editSName').value     = n;
  document.getElementById('editSCat').value      = c;
  document.getElementById('editSProvId').value   = pid;
  document.getElementById('editSRate').value     = parseFloat(r).toFixed(2);
  document.getElementById('editSMin').value      = min;
  document.getElementById('editSMax').value      = max;
  document.getElementById('editSDesc').value     = desc||'';
  document.getElementById('servEditForm').scrollIntoView({behavior:'smooth'});
};

window.saveServChanges = async () => {
  const res = await apiCall({ action:'edit_service', id:document.getElementById('editServId').value, name:document.getElementById('editSName').value, cat:document.getElementById('editSCat').value, provider_id:document.getElementById('editSProvId').value, rate:document.getElementById('editSRate').value, min:document.getElementById('editSMin').value, max:document.getElementById('editSMax').value, description:document.getElementById('editSDesc').value });
  if (res.success) { document.getElementById('servEditForm').style.display='none'; loadServs(); Swal.fire('Saved','Service updated successfully','success'); }
};

window.toggleServ = async (id, hidden) => { await apiCall({ action:'toggle_service', id, hidden }); loadServs(); };
window.delServ    = async (id) => { if (confirm('Delete?')) { await apiCall({ action:'delete_service', id }); loadServs(); } };
window.sortServ   = async (id, sort) => { await apiCall({ action:'sort_service', id, sort }); loadServs(); };
window.toggleServForm = () => { const x=document.getElementById('servForm'); x.style.display=x.style.display==='none'?'block':'none'; };

// =============================================
// PAYMENT METHODS
// =============================================
async function loadPayments() {
  const res = await apiCall({ action:'get_payments' });
  if (!res.success) return;
  renderPays(res.methods);
}

function renderPays(list) {
  const c = document.getElementById('payList');
  const cards = list.map(v => {
    const minDep = v.min_deposit || 10;
    return `<div class="col-md-6 col-lg-4" style="opacity:${v.hidden?0.6:1}"><div class="card-custom p-3 h-100 position-relative"><div class="position-absolute top-0 end-0 p-3"><i class="fas fa-edit cursor-pointer text-muted" onclick="editPay(${v.id},'${v.name.replace(/'/g,"\\'")}','${v.number}','${(v.type||'').replace(/'/g,"\\'")}',${minDep})"></i></div><div class="d-flex align-items-center mb-3"><img src="${v.logo}" width="40" height="40" class="me-3 rounded bg-light"><div><div class="fw-bold">${v.name}</div><div class="small badge bg-light text-dark border">${v.type}</div></div></div><div class="bg-light p-2 text-center rounded fw-bold text-primary border mb-3 text-break">${v.number}</div><div class="d-flex align-items-center gap-2 mb-3"><span class="min-deposit-badge"><i class="fas fa-coins"></i> Min: ৳${minDep}</span></div><div class="d-flex gap-2"><button class="btn btn-sm btn-outline-dark flex-fill" onclick="togglePay(${v.id},${v.hidden?0:1})">${v.hidden?'Show':'Hide'}</button><button class="btn btn-sm btn-outline-danger flex-fill" onclick="delPay(${v.id})">Delete</button></div></div></div>`;
  });
  c.innerHTML = cards.join('');
}

window.addPay = async () => {
  await apiCall({ action:'add_payment', name:document.getElementById('pName').value, number:document.getElementById('pNum').value, type:document.getElementById('pType').value, min_deposit:document.getElementById('pMinDep').value, logo:document.getElementById('pLogo').value });
  loadPayments();
};

window.editPay = (id, oldName, oldNum, oldType, oldMinDep) => {
  Swal.fire({ title:'Edit Payment Method', html:`<input id="sw-pname" class="swal2-input" value="${oldName}" placeholder="Name"><input id="sw-pnum" class="swal2-input" value="${oldNum}" placeholder="Number"><select id="sw-ptype" class="swal2-select"><option value="Personal (Send Money)" ${oldType==='Personal (Send Money)'?'selected':''}>Personal (Send Money)</option><option value="Agent (Cash Out)" ${oldType==='Agent (Cash Out)'?'selected':''}>Agent (Cash Out)</option><option value="Merchant (Payment)" ${oldType==='Merchant (Payment)'?'selected':''}>Merchant (Payment)</option></select><input id="sw-pmindep" class="swal2-input" type="number" value="${oldMinDep||10}" placeholder="Min Deposit" min="1"><small style="font-size:11px;color:#64748b;">Minimum deposit amount for this method</small>`, showCancelButton:true, confirmButtonText:'Update' }).then(async r => {
    if (r.isConfirmed) {
      await apiCall({ action:'edit_payment', id, name:document.getElementById('sw-pname').value, number:document.getElementById('sw-pnum').value, type:document.getElementById('sw-ptype').value, min_deposit:document.getElementById('sw-pmindep').value });
      loadPayments(); Swal.fire('Updated','','success');
    }
  });
};

window.togglePay = async (id, hidden) => { await apiCall({ action:'toggle_payment', id, hidden }); loadPayments(); };
window.delPay    = async (id) => { if (confirm('Delete?')) { await apiCall({ action:'delete_payment', id }); loadPayments(); } };

// =============================================
// USERS — Server-side Pagination + Search
// =============================================
async function loadUsers(page, search) {
  if (usrState.loading) return;
  usrState.loading = true;
  if (page  !== undefined) usrState.page   = page;
  if (search !== undefined) usrState.search = search;

  // Show skeleton loader
  const t = document.getElementById('usrTbl');
  t.innerHTML = Array(5).fill(`<tr class="skeleton-row"><td><div style="height:16px;border-radius:4px;"></div></td><td><div style="height:16px;border-radius:4px;"></div></td><td><div style="height:16px;border-radius:4px;"></div></td><td><div style="height:16px;border-radius:4px;"></div></td><td><div style="height:16px;border-radius:4px;"></div></td></tr>`).join('');

  const res = await apiCall({ action:'get_users', page: usrState.page, search: usrState.search });
  usrState.loading = false;
  if (!res.success) return;

  usrState.total   = res.total;
  usrState.perPage = res.perPage;
  renderUsrs(res.users);
  renderUsrPagination();
}

function renderUsrs(list) {
  const t = document.getElementById('usrTbl');
  if (!list.length) {
    t.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">কোনো ইউজার পাওয়া যায়নি</td></tr>';
    return;
  }
  const rows = list.map(u => {
    const initial    = u.username ? u.username.charAt(0).toUpperCase() : 'U';
    const avatarHtml = u.profile_pic
      ? `<img src="${u.profile_pic}" class="user-avatar me-2 flex-shrink-0" style="object-fit:cover;">`
      : `<div class="user-avatar me-2 flex-shrink-0">${initial}</div>`;
    return `<tr><td><div class="d-flex align-items-center">${avatarHtml}<div style="min-width:150px;"><div class="fw-bold">${u.username||'User'}</div><div class="small text-muted" style="word-break:break-all;">${u.email}</div></div></div></td><td>${u.phone||'-'}</td><td class="fw-bold text-success">৳${parseFloat(u.balance||0).toFixed(2)}</td><td><span class="badge ${u.blocked?'bg-danger':'bg-success'}">${u.blocked?'Blocked':'Active'}</span></td><td><div class="action-btn-group"><div class="action-btn" onclick="openUserEdit(${u.id},'${(u.username||'').replace(/'/g,"\\'")}','${u.email}','${u.phone||''}','${u.balance}')"><i class="fas fa-edit"></i></div><div class="action-btn" onclick="toggleUserBlock(${u.id},${u.blocked?0:1})"><i class="fas ${u.blocked?'fa-lock-open':'fa-lock'}"></i></div><div class="action-btn delete" onclick="deleteUser(${u.id})"><i class="fas fa-trash-alt"></i></div></div></td></tr>`;
  });
  t.innerHTML = rows.join('');
}

function renderUsrPagination() {
  const totalPages = Math.ceil(usrState.total / usrState.perPage);
  const pg = document.getElementById('usrPagination');
  const info = document.getElementById('usrPgInfo');
  const btns = document.getElementById('usrPgBtns');
  if (totalPages <= 1) { pg.style.display = 'none'; return; }
  pg.style.display = 'flex';
  const from = (usrState.page - 1) * usrState.perPage + 1;
  const to   = Math.min(usrState.page * usrState.perPage, usrState.total);
  info.innerHTML = `মোট ${usrState.total} জন ইউজার — ${from}–${to} দেখাচ্ছে`;

  let btnHtml = `<button class="pg-btn" onclick="loadUsers(${usrState.page-1})" ${usrState.page<=1?'disabled':''}>&#8592; আগে</button>`;
  // Show up to 5 page numbers
  const start = Math.max(1, usrState.page - 2);
  const end   = Math.min(totalPages, start + 4);
  for (let i = start; i <= end; i++) {
    btnHtml += `<button class="pg-btn ${i===usrState.page?'active':''}" onclick="loadUsers(${i})">${i}</button>`;
  }
  btnHtml += `<button class="pg-btn" onclick="loadUsers(${usrState.page+1})" ${usrState.page>=totalPages?'disabled':''}>পরে &#8594;</button>`;
  btns.innerHTML = btnHtml;
}

// Debounced search — 400ms delay
let _usrSearchTimer = null;
window.debounceUsrSearch = (val) => {
  clearTimeout(_usrSearchTimer);
  _usrSearchTimer = setTimeout(() => { loadUsers(1, val); }, 400);
};

window.openUserEdit = (id,name,email,phone,bal) => {
  document.getElementById('userEditForm').style.display='block';
  document.getElementById('editUserId').value = id;
  document.getElementById('editName').value   = name;
  document.getElementById('editEmail').value  = email;
  document.getElementById('editPhone').value  = phone;
  document.getElementById('editBal').value    = bal;
  document.getElementById('editPass').value   = '';
};

window.saveUserChanges = async () => {
  const res = await apiCall({ action:'edit_user', id:document.getElementById('editUserId').value, username:document.getElementById('editName').value, email:document.getElementById('editEmail').value, phone:document.getElementById('editPhone').value, balance:document.getElementById('editBal').value, password:document.getElementById('editPass').value });
  if (res.success) { document.getElementById('userEditForm').style.display='none'; loadUsers(); Swal.fire('Saved','','success'); }
};

window.toggleUserBlock = async (id, blocked) => {
  await apiCall({ action:'toggle_user_block', id, blocked });
  loadUsers();
};
window.deleteUser = async (id) => {
  Swal.fire({ title:'Delete User?', text:'This will permanently delete the user!', icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes, Delete User' }).then(async r => {
    if (r.isConfirmed) { await apiCall({ action:'delete_user', id }); loadUsers(); Swal.fire('Deleted!','User has been deleted.','success'); }
  });
};

// =============================================
// ORDERS
// =============================================
async function loadOrders() {
  const res = await apiCall({ action:'get_orders' });
  if (!res.success) return;
  renderOrds(res.orders);
}

function renderOrds(list) {
  const t = document.getElementById('ordTbl');
  if (!list.length) { t.innerHTML = '<tr><td colspan="7" class="text-center">No orders</td></tr>'; return; }
  const rows = list.map(o => {
    const badge     = o.status==='Pending'?'bg-warning text-dark':(o.status==='Completed'?'bg-success':'bg-danger');
    const apiBadge  = o.api_order_id ? `<br><small class="text-muted">API: ${o.api_order_id}</small>` : '';
    const cancelBtn = o.status!=='Cancelled'
      ? `<button class="btn btn-danger btn-sm" onclick="cancelAndRefund(${o.id},'${o.uid}',${o.amount})" title="Cancel & Refund"><i class="fas fa-times"></i></button>`
      : `<button class="btn btn-secondary btn-sm" disabled><i class="fas fa-times"></i></button>`;
    return `<tr><td><span class="fw-bold">#${o.order_id}</span>${apiBadge}</td><td><a href="${o.link}" target="_blank" class="btn btn-sm btn-light border"><i class="fas fa-link"></i></a></td><td><div class="text-truncate" style="max-width:120px;">${o.service_name}</div></td><td><span class="badge bg-info text-dark">${o.service_id||'N/A'}</span></td><td class="fw-bold">৳${parseFloat(o.amount).toFixed(2)}</td><td><span class="badge ${badge}">${o.status}</span></td><td><div class="btn-group btn-group-sm"><button class="btn btn-success" onclick="setOrderStatus(${o.id},'Completed')"><i class="fas fa-check"></i></button>${cancelBtn}<button class="btn btn-outline-danger" onclick="delOrder(${o.id})"><i class="fas fa-trash"></i></button></div></td></tr>`;
  });
  t.innerHTML = rows.join('');
}

window.setOrderStatus = async (id, status) => { await apiCall({ action:'set_order_status', id, status }); loadOrders(); loadDashboard(); };
window.cancelAndRefund = async (id, uid, amount) => {
  Swal.fire({ title:'Cancel & Refund?', text:`Are you sure? This will refund ৳${amount} to the user's balance.`, icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes, Refund' }).then(async r => {
    if (r.isConfirmed) {
      const res = await apiCall({ action:'cancel_and_refund', id, uid, amount });
      if (res.success) { Swal.fire('Cancelled!',`Order cancelled. ৳${amount} refunded.`,'success'); loadOrders(); loadDashboard(); }
    }
  });
};
window.delOrder = async (id) => { if (confirm('Delete?')) { await apiCall({ action:'delete_order', id }); loadOrders(); } };

// =============================================
// DEPOSITS
// =============================================
async function loadDeposits() {
  const res = await apiCall({ action:'get_deposits' });
  if (!res.success) return;
  renderDeps(res.deposits);
}

function renderDeps(list) {
  const t = document.getElementById('depTbl');
  if (!list.length) { t.innerHTML = '<tr><td colspan="6" class="text-center">No deposits</td></tr>'; return; }
  const rows = list.map(dp => {
    const stClass  = dp.status==='Completed'?'bg-success':(dp.status==='Pending'?'bg-warning text-dark':'bg-danger');
    const m        = (dp.method||'').toLowerCase();
    let mStyle     = 'background:#6c757d';
    if (m.includes('bkash'))  mStyle = 'background:#E2136E';
    else if (m.includes('nagad'))  mStyle = 'background:#F7941D';
    else if (m.includes('rocket')) mStyle = 'background:#8C3494';
    else if (m.includes('upay'))   mStyle = 'background:#2E3192';
    const methodBadge = `<span class="badge-method" style="${mStyle}">${dp.method}</span>`;
    const dObj        = new Date(dp.created_at);
    const dateStr     = `${String(dObj.getDate()).padStart(2,'0')}/${String(dObj.getMonth()+1).padStart(2,'0')}/${dObj.getFullYear()} <br><span class="text-muted small">${dObj.toLocaleString('en-US',{hour:'numeric',minute:'numeric',hour12:true})}</span>`;
    const act         = `<div class="dep-action-box"><button class="dep-btn dep-btn-check" onclick="appDep(${dp.id},'${dp.uid}',${dp.amount})" title="Approve"><i class="fas fa-check"></i></button><button class="dep-btn dep-btn-cross" onclick="rejDep(${dp.id})" title="Reject"><i class="fas fa-times"></i></button><button class="dep-btn dep-btn-trash" onclick="delDep(${dp.id})" title="Delete"><i class="fas fa-trash-alt"></i></button></div>`;
    return `<tr><td><div class="fw-bold text-dark" style="font-size:13px;line-height:1.2;">${dateStr}</div></td><td><div class="d-flex align-items-center"><i class="fas fa-copy me-2 text-primary cursor-pointer" onclick="copyText('${dp.trx_id}')" title="Copy TrxID"></i><span class="font-monospace fw-bold small text-dark">${dp.trx_id}</span></div></td><td style="width:1%;white-space:nowrap;">${methodBadge}</td><td style="width:1%;white-space:nowrap;" class="fw-bold">৳${parseFloat(dp.amount).toFixed(2)}</td><td><span class="badge ${stClass} rounded-pill px-3">${dp.status}</span></td><td>${act}</td></tr>`;
  });
  t.innerHTML = rows.join('');
}

window.appDep = async (id, uid, amount) => {
  const res = await apiCall({ action:'approve_deposit', id, uid, amount });
  if (res.success) { Swal.fire('Approved!',`৳${amount} added to user balance.`,'success'); loadDeposits(); loadDashboard(); }
};
window.rejDep = async (id) => { await apiCall({ action:'reject_deposit', id }); loadDeposits(); };
window.delDep = async (id) => { if (confirm('Delete?')) { await apiCall({ action:'delete_deposit', id }); loadDeposits(); } };

// =============================================
// API SETTINGS
// =============================================
async function loadAdminSettings() {
  const res = await apiCall({ action:'get_admin_settings' });
  if (!res.success) return;
  const s = res.settings || {};
  document.getElementById('apiUrl').value      = s.api_url     || 'https://airsmm.site/?api=1';
  document.getElementById('apiKey').value      = s.api_key     || '';
  document.getElementById('apiEnabled').value  = s.auto_order_enabled == 1 ? '1' : '0';
  document.getElementById('payVerifyApiUrl').value = s.payment_verify_api_url || 'https://smmgemphp-default-rtdb.firebaseio.com/XNXANIKPAY/.json';
  document.getElementById('setLogo').value     = s.app_logo    || '';
  document.getElementById('setNotice').value   = s.notice      || '';
  document.getElementById('setMinDep').value   = s.min_deposit || 10;
  document.getElementById('setTgBot').value    = s.tg_bot_token || '';
  document.getElementById('setTgChat').value   = s.tg_chat_id  || '';
  document.getElementById('setRefBonus').value = s.referral_bonus_per_ref || 5;
  if (s.app_logo) {
    const el = document.getElementById('appLogoDisplay');
    el.innerHTML = `<img src="${s.app_logo}" class="app-logo-img" alt="Logo">`;
  }
  let items = [];
  try { items = JSON.parse(s.slider_items || '[]'); } catch(e) { items = []; }
  window._sliderItems = items;
  renderSliderItems();
}

// ---- SLIDER MANAGEMENT ----
window._sliderItems = [];

function renderSliderItems() {
  const container = document.getElementById('sliderItems');
  if (!container) return;
  const parts = (window._sliderItems || []).map((item, idx) => {
    const isVideo = isVideoUrl(item.url || '');
    return `<div class="card mb-2 p-3 border" style="border-radius:12px;">
      <div class="row g-2 align-items-center">
        <div class="col-12"><label class="form-label-custom">স্লাইড ${idx+1} — URL (ইমেজ বা ভিডিও লিংক)</label>
          <div class="input-group">
            <input class="form-control" id="slUrl_${idx}" value="${item.url||''}" placeholder="https://example.com/image.jpg বা YouTube/MP4 লিংক" oninput="updateSliderItem(${idx})">
            <span class="input-group-text bg-light" style="font-size:11px;">${isVideo?'🎥 ভিডিও':'🖼️ ইমেজ'}</span>
          </div>
        </div>
        <div class="col-8"><label class="form-label-custom">কত সেকেন্ড পর পরের স্লাইডে যাবে?</label>
          <input type="number" class="form-control" id="slSec_${idx}" value="${item.seconds||5}" min="1" max="60" oninput="updateSliderItem(${idx})">
        </div>
        <div class="col-4 d-flex align-items-end"><button class="btn btn-outline-danger w-100" onclick="removeSliderItem(${idx})"><i class="fas fa-trash"></i> মুছুন</button></div>
      </div>
    </div>`;
  });
  container.innerHTML = parts.join('');
}

function isVideoUrl(url) {
  if (!url) return false;
  return url.includes('youtube.com') || url.includes('youtu.be') || url.includes('.mp4') || url.includes('.webm') || url.includes('.ogg') || url.includes('vimeo.com');
}

window.addSliderItem = function() {
  window._sliderItems.push({ url: '', seconds: 5 });
  renderSliderItems();
};

window.removeSliderItem = function(idx) {
  window._sliderItems.splice(idx, 1);
  renderSliderItems();
};

window.updateSliderItem = function(idx) {
  const url = document.getElementById(`slUrl_${idx}`)?.value || '';
  const sec = parseInt(document.getElementById(`slSec_${idx}`)?.value) || 5;
  window._sliderItems[idx] = { url, seconds: sec };
  const badge = document.querySelector(`#slUrl_${idx}`)?.closest('.input-group')?.querySelector('.input-group-text');
  if (badge) badge.innerText = isVideoUrl(url) ? '🎥 ভিডিও' : '🖼️ ইমেজ';
};

window.testPayVerifyApi = async function() {
  const url = document.getElementById('payVerifyApiUrl').value.trim();
  const result = document.getElementById('payApiTestResult');
  if (!url) { Swal.fire({ title:'Error', text:'API URL দিন', icon:'warning' }); return; }
  result.style.display = 'block';
  result.style.background = '#f8f9fa';
  result.style.color = '#333';
  result.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Testing...';
  try {
    const res = await fetch(url);
    const data = await res.json();
    const count = Object.keys(data || {}).length;
    result.style.background = '#dcfce7';
    result.style.color = '#166534';
    result.innerHTML = `✅ সফল! ${count} টি ট্রানজেকশন রেকর্ড পাওয়া গেছে।<br><small>Sample keys: ${Object.keys(data||{}).slice(0,3).join(', ')}</small>`;
  } catch(e) {
    result.style.background = '#fee2e2';
    result.style.color = '#991b1b';
    result.innerHTML = '❌ ব্যর্থ: ' + e.message;
  }
};

window.saveApiSettings = async () => {
  const url     = document.getElementById('apiUrl').value.trim();
  const key     = document.getElementById('apiKey').value.trim();
  const enabled = document.getElementById('apiEnabled').value;
  const payVerifyEl = document.getElementById('payVerifyApiUrl');
  const payVerifyUrl = payVerifyEl ? payVerifyEl.value.trim() : '';
  if (!url || !key) return Swal.fire({ title:'Error', text:'API URL এবং API Key পূরণ করুন', icon:'error' });
  try {
    const res = await apiCall({
      action: 'save_api_settings',
      api_url: url,
      api_key: key,
      auto_order_enabled: enabled,
      payment_verify_api_url: payVerifyUrl
    });
    if (res.success) {
      Swal.fire({ title:'সেভ হয়েছে!', text:'API Settings সফলভাবে আপডেট হয়েছে', icon:'success' });
    } else {
      Swal.fire({ title:'Error', text: res.message || 'সেভ হয়নি', icon:'error' });
    }
  } catch(e) {
    Swal.fire({ title:'Error', text: 'Network error: ' + e.message, icon:'error' });
  }
};

window.testApiConnection = async () => {
  const url = document.getElementById('apiUrl').value.trim(), key = document.getElementById('apiKey').value.trim();
  if (!key) return Swal.fire({ title:'Error', text:'Please enter API Key first', icon:'warning' });
  const resultDiv = document.getElementById('apiTestResult');
  resultDiv.style.display = 'block'; resultDiv.className = 'api-test-result';
  resultDiv.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Testing connection...';
  try {
    const fd = new FormData(); fd.append('key',key); fd.append('action','services');
    const resp   = await fetch(url, { method:'POST', body:fd });
    const result = await resp.json();
    if (result && !result.error && Array.isArray(result)) {
      resultDiv.className = 'api-test-result success';
      resultDiv.innerHTML = `<i class="fas fa-check-circle me-1"></i> ✓ Connected! Found ${result.length} services.`;
      Swal.fire({ title:'Success!', text:`API connection successful! Found ${result.length} services.`, icon:'success' });
    } else {
      resultDiv.className = 'api-test-result error';
      resultDiv.innerHTML = `<i class="fas fa-times-circle me-1"></i> ✗ Failed: ${result?.error||'Unknown error'}`;
    }
  } catch(err) { resultDiv.className='api-test-result error'; resultDiv.innerHTML=`<i class="fas fa-times-circle me-1"></i> ✗ Error: ${err.message}`; }
};

window.fetchApiServices = async () => {
  const url = document.getElementById('apiUrl').value.trim(), key = document.getElementById('apiKey').value.trim();
  if (!key) return Swal.fire({ title:'Error', text:'Please enter API Key first', icon:'warning' });
  const resultDiv = document.getElementById('apiTestResult');
  resultDiv.style.display = 'block'; resultDiv.className = 'api-test-result';
  resultDiv.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Fetching services...';
  try {
    const fd = new FormData(); fd.append('key',key); fd.append('action','services');
    const services = await (await fetch(url,{method:'POST',body:fd})).json();
    if (services && !services.error && Array.isArray(services)) {
      const preview = services.slice(0,5).map(s=>`<li><b>ID: ${s.id}</b> - ${s.name} (৳${s.rate}/1k)</li>`).join('');
      const more    = services.length>5 ? `<li><i>...and ${services.length-5} more</i></li>` : '';
      resultDiv.className = 'api-test-result success';
      resultDiv.innerHTML = `<i class="fas fa-check-circle me-1"></i> ✓ Found ${services.length} services:<br><ul class="mb-0 mt-2 small">${preview}${more}</ul>`;
    } else { resultDiv.className='api-test-result error'; resultDiv.innerHTML=`<i class="fas fa-times-circle me-1"></i> ✗ Error: ${services?.error||'Unknown'}`; }
  } catch(err) { resultDiv.className='api-test-result error'; resultDiv.innerHTML=`<i class="fas fa-times-circle me-1"></i> ✗ Error: ${err.message}`; }
};

window.saveSettings = async () => {
  Swal.fire({ title:'Saving...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
  (window._sliderItems || []).forEach((item, idx) => {
    item.url     = document.getElementById(`slUrl_${idx}`)?.value || item.url;
    item.seconds = parseInt(document.getElementById(`slSec_${idx}`)?.value) || item.seconds || 5;
  });
  const res = await apiCall({
    action:      'save_settings',
    app_logo:    document.getElementById('setLogo').value,
    notice:      document.getElementById('setNotice').value,
    min_deposit: document.getElementById('setMinDep').value,
    slider_items: JSON.stringify(window._sliderItems || []),
    tg_bot_token: document.getElementById('setTgBot').value,
    tg_chat_id:  document.getElementById('setTgChat').value,
    referral_bonus_per_ref: document.getElementById('setRefBonus').value,
  });
  if (res.success) { Swal.fire({ icon:'success', title:'Saved Successfully!', timer:2000, showConfirmButton:false }); loadAdminSettings(); }
};

// =============================================
// REFERRALS
// =============================================
async function loadReferrals() {
  const res = await apiCall({ action:'get_referrals', page: 1 });
  if (!res.success) return;

  document.getElementById('ref-total').innerText = res.totalReferrals || 0;
  document.getElementById('ref-bonus').innerText = '৳' + parseFloat(res.totalBonus || 0).toFixed(2);

  const t = document.getElementById('refTbl');
  if (!res.referrals.length) {
    t.innerHTML = '<tr><td colspan="5" class="text-center">No referral data found</td></tr>';
    return;
  }

  const rows = res.referrals.map(u => {
    const name    = u.tg_name || u.username || 'User';
    const uname   = u.tg_username ? '@' + u.tg_username : '-';
    const code    = u.referral_code || '-';
    const count   = u.referrals_count || 0;
    const bonus   = parseFloat(u.referral_bonus || 0).toFixed(2);
    const joined  = u.created_at ? new Date(u.created_at).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'}) : '-';
    const countBadge = count > 0
      ? `<span class="badge bg-success rounded-pill">${count}</span>`
      : `<span class="badge bg-light text-secondary">0</span>`;
    return `<tr>
      <td><div class="fw-bold">${name}</div><div class="small text-muted">${uname}</div></td>
      <td><code style="background:#f1f5f9;padding:3px 8px;border-radius:6px;font-size:12px;">${code}</code></td>
      <td>${countBadge}</td>
      <td class="fw-bold text-success">৳${bonus}</td>
      <td class="small text-muted">${joined}</td>
    </tr>`;
  });
  t.innerHTML = rows.join('');
}

// =============================================
// UI HELPERS
// =============================================
window.tab = (id, btn) => {
  document.querySelectorAll('.app-sec').forEach(x=>x.style.display='none');
  document.getElementById(id+'-sec').style.display='block';
  document.querySelectorAll('.sidebar a').forEach(x=>x.classList.remove('active'));
  btn.classList.add('active');
  if (window.innerWidth < 992) closeMenu();
  // Lazy load — শুধু তখনই লোড হবে যখন ট্যাবে যাবে
  if (id === 'dash') loadDashboard();
  if (id === 'usr')  { usrState.page=1; usrState.search=''; document.getElementById('usrSearchInput').value=''; loadUsers(); }
  if (id === 'ref')  loadReferrals();
  if (id === 'ord')  loadOrders();
  if (id === 'cats') loadCats();
  if (id === 'serv') loadServs();
  if (id === 'pay')  loadPayments();
  if (id === 'dep')  loadDeposits();
  if (id === 'api')  loadAdminSettings();
  if (id === 'set')  loadAdminSettings();
};

// Debounced table filter (Orders, etc.)
let _filterTimers = {};
window.debounceFilter = (tableId, v) => {
  clearTimeout(_filterTimers[tableId]);
  _filterTimers[tableId] = setTimeout(() => {
    const rows = document.getElementById(tableId).rows;
    for (let i = 0; i < rows.length; i++) {
      rows[i].style.display = rows[i].innerText.toLowerCase().includes(v.toLowerCase()) ? '' : 'none';
    }
  }, 300);
};

window.openMenu  = () => { document.getElementById('sidebar').classList.add('active'); document.querySelector('.sidebar-overlay').classList.add('active'); };
window.closeMenu = () => { document.getElementById('sidebar').classList.remove('active'); document.querySelector('.sidebar-overlay').classList.remove('active'); };
window.copyText  = (t) => { navigator.clipboard.writeText(t).then(()=>{ const T=Swal.mixin({toast:true,position:'top-end',showConfirmButton:false,timer:1500}); T.fire({icon:'success',title:'Copied!'}); }); };

window.toggleThreeDot = () => document.getElementById('threeDotDropdown').classList.toggle('show');

window.showAdminInfo = () => {
  document.getElementById('threeDotDropdown').classList.remove('show');
  Swal.fire({ title:'Admin Information', html:`<div style="text-align:left;font-size:14px;"><div style="margin-bottom:10px;"><strong>Email:</strong> ${ADMIN_EMAIL}</div><div><strong>Status:</strong> <span class="badge bg-success">Active</span></div></div>`, icon:'info', confirmButtonText:'Close', confirmButtonColor:'#4f46e5' });
};

window.toggleLoginPass = () => {
  const input = document.getElementById('admPass'), icon = input.nextElementSibling;
  if (input.type==='password') { input.type='text'; icon.classList.replace('fa-eye','fa-eye-slash'); }
  else { input.type='password'; icon.classList.replace('fa-eye-slash','fa-eye'); }
};

window.toggleApiKey = () => {
  const input = document.getElementById('apiKey'), icon = input.nextElementSibling;
  if (input.type==='password') { input.type='text'; icon.classList.replace('fa-eye','fa-eye-slash'); }
  else { input.type='password'; icon.classList.replace('fa-eye-slash','fa-eye'); }
};

document.addEventListener('click', (e) => {
  const menu = document.querySelector('.three-dot-menu');
  if (menu && !menu.contains(e.target)) document.getElementById('threeDotDropdown').classList.remove('show');
});

// =============================================
// AJAX HELPER
// =============================================
async function apiCall(data) {
  try {
    const formData = new FormData();
    Object.keys(data).forEach(k => formData.append(k, data[k]));
    const res = await fetch('admin.php', { method:'POST', body: formData });
    return await res.json();
  } catch(e) { return { success:false, message:'Network error' }; }
}
</script>
</body>
</html>
