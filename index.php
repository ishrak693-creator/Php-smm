<?php
/*
 * DATABASE MIGRATION REQUIRED:
 * Run this SQL once on your database:
 * ALTER TABLE users ADD COLUMN api_key VARCHAR(64) DEFAULT NULL UNIQUE;
 */
require_once 'db.php';

// ====================================================
// PUBLIC REST API — /index.php?api=1
// Supports GET and POST methods
// Authentication: api_key parameter required
// ====================================================
if (isset($_GET['api'])) {

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST');
    header('Access-Control-Allow-Headers: Content-Type');

    // Merge GET and POST params
    $params = array_merge($_GET, $_POST);
    if (empty($params) && file_get_contents('php://input')) {
        $jsonBody = json_decode(file_get_contents('php://input'), true);
        if (is_array($jsonBody)) $params = array_merge($params, $jsonBody);
    }

    $apiKey = $params['key'] ?? '';
    $action = $params['action'] ?? '';

    if (!$apiKey) {
        echo json_encode(['status'=>'error','message'=>'API key is required. Pass key=YOUR_API_KEY']);
        exit;
    }

    // Validate API key against users table
    $safeKey = $conn->real_escape_string($apiKey);

    // Auto-create api_key column if missing
    $colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'api_key'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN api_key VARCHAR(80) DEFAULT NULL");
    }

    $userRes = $conn->query("SELECT * FROM users WHERE api_key='$safeKey' LIMIT 1");
    if ($userRes->num_rows === 0) {
        echo json_encode(['status'=>'error','message'=>'Invalid API key']);
        exit;
    }
    $apiUser = $userRes->fetch_assoc();
    if ($apiUser['blocked']) {
        echo json_encode(['status'=>'error','message'=>'Account is blocked']);
        exit;
    }

    // ----- API: Get Services List -----
    if ($action === 'services' || $action === '' || !$action) {
        $svcs = $conn->query("SELECT service_key as service, name, cat as category, rate, min_order as min, max_order as max, description FROM services WHERE hidden=0 ORDER BY id ASC");
        $list = [];
        while ($r = $svcs->fetch_assoc()) $list[] = $r;
        echo json_encode($list);
        exit;
    }

    // ----- API: Get Balance -----
    if ($action === 'balance') {
        echo json_encode(['status'=>'success','balance'=>(float)$apiUser['balance'],'currency'=>'BDT']);
        exit;
    }

    // ----- API: Place Order -----
    if ($action === 'add') {
        $serviceKey = $conn->real_escape_string($params['service'] ?? '');
        $link       = $conn->real_escape_string($params['link']    ?? '');
        $quantity   = (int)($params['quantity'] ?? 0);

        if (!$serviceKey || !$link || !$quantity) {
            echo json_encode(['status'=>'error','message'=>'Required: service, link, quantity']);
            exit;
        }

        $svcRes = $conn->query("SELECT * FROM services WHERE (service_key='$serviceKey' OR id='$serviceKey') AND hidden=0 LIMIT 1");
        if ($svcRes->num_rows === 0) {
            echo json_encode(['status'=>'error','message'=>'Service not found']);
            exit;
        }
        $svc = $svcRes->fetch_assoc();

        if ($quantity < (int)$svc['min_order'] || $quantity > (int)$svc['max_order']) {
            echo json_encode(['status'=>'error','message'=>"Quantity must be between {$svc['min_order']} and {$svc['max_order']}"]);
            exit;
        }

        $charge = round((float)$svc['rate'] / 1000 * $quantity, 2);
        if ((float)$apiUser['balance'] < $charge) {
            echo json_encode(['status'=>'error','message'=>'Insufficient balance','balance'=>(float)$apiUser['balance'],'required'=>$charge]);
            exit;
        }

        // Forward to upstream API
        $sets = $conn->query("SELECT api_url,api_key,auto_order_enabled FROM admin_settings LIMIT 1")->fetch_assoc();
        if (!$sets['auto_order_enabled'] || !$sets['api_key']) {
            echo json_encode(['status'=>'error','message'=>'Auto-order system is disabled']);
            exit;
        }

        $apiOrderId = '';
        // airsmm API: POST to base URL with key+action+service+link+quantity
        $apiBase = $sets['api_url']; // e.g. https://airsmm.site/?api=1
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $apiBase,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'key'      => $sets['api_key'],
                'action'   => 'add',
                'service'  => $svc['provider_id'],
                'link'     => $link,
                'quantity' => $quantity,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $apiResp  = curl_exec($ch);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $apiResult = json_decode($apiResp, true);
        $isSuccess = false;
        if (!$curlErr && $apiResult) {
            // airsmm returns: {"order":12345,"status":"Pending","charge":2.50,...}
            if (!empty($apiResult['order']))  { $isSuccess=true; $apiOrderId=$apiResult['order']; }
            elseif (!empty($apiResult['request'])) { $isSuccess=true; $apiOrderId=$apiResult['request']; }
            elseif (!empty($apiResult['status']) && strtolower($apiResult['status'])==='success') { $isSuccess=true; $apiOrderId=$apiResult['order_id']??('API'.rand(10000,99999)); }
        } elseif (!$curlErr) { $isSuccess=true; }

        if (!$isSuccess) {
            $errMsg = $apiResult['error'] ?? $apiResult['message'] ?? ($curlErr ?: 'Upstream API failed');
            echo json_encode(['status'=>'error','message'=>$errMsg]);
            exit;
        }

        $newBal  = round((float)$apiUser['balance'] - $charge, 2);
        $orderId = rand(4000, 9999); // 4-digit order ID (e.g. 4041)
        $apiOrdId = $conn->real_escape_string($apiOrderId);
        $svcName  = $conn->real_escape_string($svc['name']);
        $provId   = $conn->real_escape_string($svc['provider_id']);
        $linkEsc  = $conn->real_escape_string($link);
        $uid      = $apiUser['uid'];

        $conn->query("UPDATE users SET balance=$newBal WHERE uid='$uid'");
        $conn->query("INSERT INTO orders (uid,order_id,api_order_id,service_name,service_id,link,quantity,amount,status) VALUES ('$uid','$orderId','$apiOrdId','$svcName','$provId','$linkEsc',$quantity,$charge,'Completed')");

        echo json_encode(['order'=>$orderId,'status'=>'Completed','charge'=>$charge,'currency'=>'BDT','balance'=>$newBal]);
        exit;
    }

    // ----- API: Order Status -----
    if ($action === 'status') {
        $orderId = $conn->real_escape_string($params['order'] ?? '');
        if (!$orderId) { echo json_encode(['status'=>'error','message'=>'order parameter required']); exit; }
        $res = $conn->query("SELECT order_id,api_order_id,service_name,link,quantity,amount,status,created_at FROM orders WHERE order_id='$orderId' AND uid='{$apiUser['uid']}' LIMIT 1");
        if ($res->num_rows === 0) { echo json_encode(['status'=>'error','message'=>'Order not found']); exit; }
        $o = $res->fetch_assoc();
        echo json_encode(['order'=>$o['order_id'],'api_order_id'=>$o['api_order_id'],'service'=>$o['service_name'],'link'=>$o['link'],'quantity'=>$o['quantity'],'charge'=>$o['amount'],'status'=>$o['status'],'created_at'=>$o['created_at']]);
        exit;
    }

    // ----- API: Order History -----
    if ($action === 'orders') {
        $uid  = $apiUser['uid'];
        $res  = $conn->query("SELECT order_id,api_order_id,service_name,link,quantity,amount,status,created_at FROM orders WHERE uid='$uid' ORDER BY id DESC LIMIT 50");
        $list = [];
        while ($r = $res->fetch_assoc()) $list[] = $r;
        echo json_encode($list);
        exit;
    }

    echo json_encode(['status'=>'error','message'=>"Unknown action: $action. Valid: services, balance, add, status, orders"]);
    exit;
}


// ====================================================
// FAST ASYNC TELEGRAM SYSTEM
// - Direct (admin/user): parallel cURL multi AFTER response sent
// - Broadcast 1000+ users: DB queue + background worker
// ====================================================

define('TG_BOT_TOKEN', '8752277413:AAFtrhBfFPvlmRB08-kIZEG0uCC2xAwd5d8');
$GLOBALS['_PENDING_TG'] = [];

function queueTelegramNotification($botToken, $chatId, $text, $inlineKeyboard = null) {
    if (!$chatId) return;
    $GLOBALS['_PENDING_TG'][] = [
        'chatId'         => (string)$chatId,
        'text'           => $text,
        'inlineKeyboard' => $inlineKeyboard,
    ];
}

// Broadcast: save to DB queue + spawn background worker (user never waits)
function broadcastToAllUsers($conn, $text, $inlineKeyboard = null) {
    // Save job to DB queue
    $conn->query("CREATE TABLE IF NOT EXISTS tg_broadcast_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message TEXT NOT NULL,
        keyboard TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        done TINYINT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $msg   = $conn->real_escape_string($text);
    $kbVal = $inlineKeyboard ? ("'". $conn->real_escape_string(json_encode($inlineKeyboard)) ."'") : 'NULL';
    $conn->query("INSERT INTO tg_broadcast_queue (message, keyboard) VALUES ('$msg', $kbVal)");

    // Build self-contained inline PHP worker script — no external file needed
    $dbFile   = addslashes(__DIR__ . '/db.php');
    $botToken = TG_BOT_TOKEN;
    $workerCode = <<<'PHPWORKER'
<?php
ignore_user_abort(true);
set_time_limit(0);
ini_set('memory_limit','128M');
require_once 'DBFILE_PLACEHOLDER';
define('_TG_TOKEN','TOKEN_PLACEHOLDER');
define('_BATCH',25);
$job=$conn->query("SELECT id,message,keyboard FROM tg_broadcast_queue WHERE done=0 ORDER BY id ASC LIMIT 1")->fetch_assoc();
if(!$job)exit(0);
$conn->query("UPDATE tg_broadcast_queue SET done=1 WHERE id=".(int)$job['id']);
$text=$job['message'];
$kb=$job['keyboard']?json_decode($job['keyboard'],true):null;
$res=$conn->query("SELECT tg_id FROM users WHERE tg_id IS NOT NULL AND tg_id!='' AND blocked=0 ORDER BY id ASC");
if(!$res||$res->num_rows===0)exit(0);
$ids=[];while($r=$res->fetch_assoc())$ids[]=$r['tg_id'];
$url='https://api.telegram.org/bot'._TG_TOKEN.'/sendMessage';
foreach(array_chunk($ids,_BATCH) as $batch){
  $mh=curl_multi_init();$hs=[];
  foreach($batch as $cid){
    $p=['chat_id'=>$cid,'text'=>$text,'parse_mode'=>'Markdown','disable_web_page_preview'=>true];
    if($kb)$p['reply_markup']=json_encode(['inline_keyboard'=>$kb]);
    $ch=curl_init();
    curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($p),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_NOSIGNAL=>1]);
    curl_multi_add_handle($mh,$ch);$hs[]=$ch;
  }
  $run=null;do{curl_multi_exec($mh,$run);curl_multi_select($mh,0.3);}while($run>0);
  foreach($hs as $ch){
    $r=curl_multi_getcontent($ch);
    if($r){$d=json_decode($r,true);if(!empty($d['error_code'])&&$d['error_code']==429)sleep((int)($d['parameters']['retry_after']??5)+1);}
    curl_multi_remove_handle($mh,$ch);curl_close($ch);
  }
  curl_multi_close($mh);
  usleep(50000);
}
exit(0);
PHPWORKER;

    $workerCode = str_replace('DBFILE_PLACEHOLDER', $dbFile, $workerCode);
    $workerCode = str_replace('TOKEN_PLACEHOLDER',  $botToken, $workerCode);

    // Write temp worker file, execute in background, will self-delete after run
    $tmpFile = sys_get_temp_dir() . '/tgw_' . uniqid() . '.php';
    file_put_contents($tmpFile, $workerCode);

    if (strtoupper(substr(PHP_OS,0,3)) === 'WIN') {
        pclose(popen("start /B php \"$tmpFile\"", "r"));
    } else {
        shell_exec("php \"$tmpFile\" > /dev/null 2>&1 & sleep 60 && rm -f \"$tmpFile\" &");
    }
}

// After response sent: fire direct messages in parallel
register_shutdown_function(function() {
    $pending = $GLOBALS['_PENDING_TG'] ?? [];
    if (empty($pending)) return;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ignore_user_abort(true);
        while (ob_get_level() > 0) ob_end_flush();
        flush();
    }

    $mh      = curl_multi_init();
    $handles = [];
    $url     = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage';

    foreach ($pending as $tg) {
        $params = [
            'chat_id'                  => $tg['chatId'],
            'text'                     => $tg['text'],
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
        ];
        if (!empty($tg['inlineKeyboard'])) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $tg['inlineKeyboard']]);
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_NOSIGNAL       => 1,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.5); } while ($running > 0);
    foreach ($handles as $ch) { curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
});

// ====================================================
// AJAX / API Handler (POST Requests)
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = clean($conn, $_POST['action']);

    // ---- AUTO MIGRATE: add Telegram columns if missing ----
    static $tgMigrated = false;
    if (!$tgMigrated) {
        $tgMigrated = true;
        $cols = $conn->query("SHOW COLUMNS FROM users");
        $existing = [];
        while ($c = $cols->fetch_assoc()) $existing[] = $c['Field'];
        if (!in_array('tg_id',    $existing)) $conn->query("ALTER TABLE users ADD COLUMN tg_id VARCHAR(30) DEFAULT NULL");
        if (!in_array('tg_photo', $existing)) $conn->query("ALTER TABLE users ADD COLUMN tg_photo TEXT DEFAULT NULL");
        if (!in_array('tg_name',  $existing)) $conn->query("ALTER TABLE users ADD COLUMN tg_name VARCHAR(150) DEFAULT NULL");
        if (!in_array('tg_username', $existing)) $conn->query("ALTER TABLE users ADD COLUMN tg_username VARCHAR(100) DEFAULT NULL");
        if (!in_array('last_seen', $existing)) $conn->query("ALTER TABLE users ADD COLUMN last_seen DATETIME DEFAULT NULL");
    }

    // ---------- TELEGRAM LOGIN ----------
    if ($action === 'tg_login') {
        $tgId       = clean($conn, $_POST['tg_id']       ?? '');
        $tgName     = clean($conn, $_POST['tg_name']     ?? '');
        $tgUsername = clean($conn, $_POST['tg_username'] ?? '');
        $tgPhoto    = clean($conn, $_POST['tg_photo']    ?? '');
        $refCode    = clean($conn, $_POST['ref_code']    ?? '');

        if (!$tgId) jsonResponse(['success'=>false,'message'=>'Telegram ID required']);

        // Auto-add referral columns if missing
        $colsChk = $conn->query("SHOW COLUMNS FROM users");
        $existingCols = [];
        while ($c = $colsChk->fetch_assoc()) $existingCols[] = $c['Field'];
        if (!in_array('referral_code', $existingCols)) $conn->query("ALTER TABLE users ADD COLUMN referral_code VARCHAR(50) DEFAULT NULL");
        if (!in_array('referral_bonus', $existingCols)) $conn->query("ALTER TABLE users ADD COLUMN referral_bonus DECIMAL(10,2) DEFAULT 0");
        if (!in_array('referrals_count', $existingCols)) $conn->query("ALTER TABLE users ADD COLUMN referrals_count INT DEFAULT 0");
        if (!in_array('referred_by', $existingCols)) $conn->query("ALTER TABLE users ADD COLUMN referred_by VARCHAR(50) DEFAULT NULL");

        // Check if user exists by tg_id
        $res = $conn->query("SELECT * FROM users WHERE tg_id='$tgId' LIMIT 1");
        if ($res->num_rows > 0) {
            // Update photo/name on each login
            $conn->query("UPDATE users SET tg_photo='$tgPhoto', tg_name='$tgName', tg_username='$tgUsername', last_seen=NOW() WHERE tg_id='$tgId'");
            $user = $res->fetch_assoc();
        } else {
            // Create new user via Telegram
            $uid     = 'TG_' . $tgId;
            $safeMail = $tgId . '@telegram.user';
            $conn->query("INSERT INTO users (uid, username, email, phone, password, balance, profile_pic, tg_id, tg_name, tg_photo, tg_username)
                          VALUES ('$uid','$tgName','$safeMail','','', 0.00,'$tgPhoto','$tgId','$tgName','$tgPhoto','$tgUsername')");
            $res2 = $conn->query("SELECT * FROM users WHERE tg_id='$tgId' LIMIT 1");
            $user = $res2->fetch_assoc();

            // Handle referral bonus
            if ($refCode) {
                $refUserRes = $conn->query("SELECT uid FROM users WHERE referral_code='$refCode' LIMIT 1");
                if ($refUserRes && $refUserRes->num_rows > 0) {
                    $refUser = $refUserRes->fetch_assoc();
                    $refUid = $refUser['uid'];
                    // Get referral bonus amount from settings
                    $setsRef = $conn->query("SELECT referral_bonus_per_ref FROM admin_settings LIMIT 1")->fetch_assoc();
                    // Auto-add column if missing
                    $settingsCols = $conn->query("SHOW COLUMNS FROM admin_settings");
                    $setExisting = [];
                    while($sc=$settingsCols->fetch_assoc()) $setExisting[]=$sc['Field'];
                    if (!in_array('referral_bonus_per_ref', $setExisting)) $conn->query("ALTER TABLE admin_settings ADD COLUMN referral_bonus_per_ref DECIMAL(10,2) DEFAULT 5");
                    $bonusAmt = (float)($setsRef['referral_bonus_per_ref'] ?? 5);
                    // Credit bonus and increment count
                    $conn->query("UPDATE users SET balance=balance+$bonusAmt, referral_bonus=referral_bonus+$bonusAmt, referrals_count=referrals_count+1 WHERE uid='$refUid'");
                    // Mark referred_by
                    $conn->query("UPDATE users SET referred_by='$refCode' WHERE tg_id='$tgId'");
                }
            }

            // Auto-generate referral code for new user
            $newRefCode = 'REF' . strtoupper(substr(md5($uid . time()), 0, 8));
            $conn->query("UPDATE users SET referral_code='$newRefCode' WHERE uid='$uid'");

            // Send Telegram notification for new user (async, non-blocking)
            $userDisplay = $tgUsername ? "@$tgUsername ($tgName)" : $tgName;

            // 🎉 Welcome message directly to the new user's Telegram
            if ($tgId) {
                $welcomeMsg = "🎉 *SMMGem তে স্বাগতম!*\n\n"
                    . "👤 *নাম:* $tgName\n"
                    . ($tgUsername ? "💁 *Username:* @$tgUsername\n" : "")
                    . "🆔 *আপনার UID:* $uid\n"
                    . "🕐 *সময়:* " . date('d-m-Y H:i:s') . "\n\n"
                    . "💎 আমাদের সাথে থাকুন এবং সেরা SMM সেবা উপভোগ করুন\!\n"
                    . "━━━━━━━━━━━━━━━━━━\n"
                    . "🚀 *Powered by @SMMGemBot*";
                $welcomeBtn = [[['text' => '🛍️ অর্ডার করুন', 'url' => 'https://t.me/SMMGemBot/app?startapp=' . $newRefCode]]];
                queueTelegramNotification(TG_BOT_TOKEN, $tgId, $welcomeMsg, $welcomeBtn);
            }

            // 📢 নতুন user join → শুধু group এ পাঠাও

            $newUserGrpMsg = "🆕 *নতুন ইউজার যোগ দিয়েছে!*\n\n"
                . "👤 *User:* $userDisplay\n"
                . "🕐 *Time:* " . date('d-m-Y H:i:s') . "\n"
                . "━━━━━━━━━━━━━━━━━━\n"
                . "🚀 *Powered by @SMMGemBot*";
            $newUserGrpBtn = [[['text' => '🛍️ অর্ডার করুন', 'url' => 'https://t.me/SMMGemBot/app?startapp=' . $newRefCode]]];
            queueTelegramNotification(TG_BOT_TOKEN, '-1003760050398', $newUserGrpMsg, $newUserGrpBtn);
        }

        if ($user['blocked']) jsonResponse(['success'=>false,'message'=>'account_blocked']);

        $_SESSION['user_uid']   = $user['uid'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_id']    = $user['id'];

        // Get referral code
        $rcRes = $conn->query("SELECT referral_code FROM users WHERE uid='{$user['uid']}' LIMIT 1");
        $rcRow = $rcRes->fetch_assoc();
        $userRefCode = $rcRow['referral_code'] ?? '';

        jsonResponse([
            'success'      => true,
            'uid'          => $user['uid'],
            'email'        => $user['email'],
            'username'     => $user['tg_name'] ?: $user['username'],
            'phone'        => $user['phone'],
            'balance'      => $user['balance'],
            'profilePic'   => $user['tg_photo'] ?: $user['profile_pic'],
            'tgPhoto'      => $user['tg_photo'],
            'tgName'       => $user['tg_name'],
            'tgUsername'   => $user['tg_username'],
            'tgId'         => $user['tg_id'],
            'joined'       => $user['created_at'],
            'isTgUser'     => true,
            'referralCode' => $userRefCode
        ]);
    }

    // ---------- FIREBASE AUTO DEPOSIT ----------
    if ($action === 'firebase_auto_deposit') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in']);
        $uid    = clean($conn, $_SESSION['user_uid']);
        $method = clean($conn, $_POST['method']    ?? '');
        $trxId  = clean($conn, $_POST['trx_id']    ?? '');
        $amount = (float)($_POST['amount']          ?? 0);

        if (!$method || !$trxId || $amount <= 0) jsonResponse(['success'=>false,'message'=>'Invalid data']);

        // Check duplicate
        $chk = $conn->query("SELECT id FROM deposits WHERE trx_id='$trxId' LIMIT 1");
        if ($chk->num_rows > 0) jsonResponse(['success'=>false,'message'=>'এই ট্রানজেকশন আইডি ইতিমধ্যে ব্যবহার করা হয়েছে!']);

        // Insert approved deposit & update balance
        $conn->query("INSERT INTO deposits (uid,method,trx_id,amount,status) VALUES ('$uid','$method','$trxId',$amount,'Completed')");
        $conn->query("UPDATE users SET balance=balance+$amount WHERE uid='$uid'");

        // Return new balance
        $balRes = $conn->query("SELECT balance FROM users WHERE uid='$uid' LIMIT 1");
        $newBal = (float)$balRes->fetch_assoc()['balance'];

        // Get user info for Telegram notification
        // ── User info + Telegram notifications (async, non-blocking) ──
        $userInfoDep = $conn->query("SELECT tg_username, tg_name, username, tg_id, referral_code FROM users WHERE uid='$uid' LIMIT 1");
        $uiDep = $userInfoDep->fetch_assoc();
        $tgUsername_dep  = $uiDep['tg_username'] ?? '';
        $tgName_dep      = $uiDep['tg_name'] ?? ($uiDep['username'] ?? 'User');
        $tgId_dep        = $uiDep['tg_id'] ?? '';
        $refCode_dep     = $uiDep['referral_code'] ?? '';
        $tgDispDep       = $tgUsername_dep ? "@$tgUsername_dep" : $tgName_dep;

        $setsTg_dep = $conn->query("SELECT tg_chat_id FROM admin_settings LIMIT 1")->fetch_assoc();

        // 🔋 Deposit confirmation message to the user (exact format)
        $maskedTrxId = str_repeat('×', min(5, strlen($trxId))) . substr($trxId, 5);
        $depConfirmMsg = "??*New Deposit Confirmed* 🔋\n\n"
            . "👤 *Client:*  $tgDispDep\n"
            . "💰 *Amount:* " . number_format($amount, 2) . " ৳\n"
            . "💳 *Method:* $method\n"
            . "🧾 *Transaction ID:* $maskedTrxId\n\n"
            . "🔐 *Status:* SUCCESS 🟢\n"
            . "━━━━━━━━━━━━━━━━━━\n"
            . "🚀 Powered by @SMMGemBot";
        $depBtn = [[['text' => '🛍️ অর্ডার করুন', 'url' => 'https://t.me/SMMGemBot/app?startapp=REFD317B939']]];

        // Send to the user's own Telegram
        if ($tgId_dep) {
            queueTelegramNotification(TG_BOT_TOKEN, $tgId_dep, $depConfirmMsg, $depBtn);
        }

        // ✅ Response আগে পাঠাও — screen hang হবে না
        jsonResponse(['success'=>true,'amount'=>$amount,'newBalance'=>$newBal]);
        // 📢 Deposit confirmed → শুধু group এ পাঠাও
        queueTelegramNotification(TG_BOT_TOKEN, '-1003760050398', $depConfirmMsg, $depBtn);
    }

    // ---------- REGISTER ----------
    if ($action === 'register') {
        $name  = clean($conn, $_POST['name']  ?? '');
        $phone = clean($conn, $_POST['phone'] ?? '');
        $email = clean($conn, $_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        if (!$name || !$email || !$pass) jsonResponse(['success'=>false,'message'=>'All fields required']);
        $check = $conn->query("SELECT id FROM users WHERE email='$email' LIMIT 1");
        if ($check->num_rows > 0) jsonResponse(['success'=>false,'message'=>'Email already registered!']);
        $hash  = password_hash($pass, PASSWORD_BCRYPT);
        $uid   = 'UID_' . strtoupper(substr(md5($email . time()), 0, 12));
        $defPic = "https://cdn-icons-png.flaticon.com/512/3135/3135715.png";
        $conn->query("INSERT INTO users (uid,username,email,phone,password,balance,profile_pic) VALUES ('$uid','$name','$email','$phone','$hash',0.00,'$defPic')");
        // 📢 নতুন user register → শুধু group এ পাঠাও
        $regGrpMsg = "🆕 *নতুন ইউজার যোগ দিয়েছে!*\n\n"
            . "👤 *Name:* $name\n"
            . "📧 *Email:* $email\n"
            . "📱 *Phone:* $phone\n"
            . "🕐 *Time:* " . date('d-m-Y H:i:s') . "\n"
            . "━━━━━━━━━━━━━━━━━━\n"
            . "🚀 Powered by @SMMGemBot";
        queueTelegramNotification(TG_BOT_TOKEN, '-1003760050398', $regGrpMsg);
        jsonResponse(['success'=>true,'message'=>'Account Created!']);
    }

    // ---------- LOGIN ----------
    if ($action === 'login') {
        $email = clean($conn, $_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $res   = $conn->query("SELECT * FROM users WHERE email='$email' LIMIT 1");
        if ($res->num_rows === 0) jsonResponse(['success'=>false,'message'=>'User account not found!']);
        $user = $res->fetch_assoc();
        if (!password_verify($pass, $user['password'])) jsonResponse(['success'=>false,'message'=>'Wrong Email or Password!']);
        if ($user['blocked']) jsonResponse(['success'=>false,'message'=>'account_blocked']);
        // 🔐 নতুন Telegram লগইনে session পরিষ্কার করে নতুন session শুরু
        session_unset();
        session_regenerate_id(true);
        $_SESSION['user_uid']   = $user['uid'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_id']    = $user['id'];
        jsonResponse(['success'=>true,'uid'=>$user['uid'],'email'=>$user['email'],'username'=>$user['username'],'phone'=>$user['phone'],'balance'=>$user['balance'],'profilePic'=>$user['profile_pic'],'joined'=>$user['created_at']]);
    }

    // ---------- GOOGLE LOGIN ----------
    if ($action === 'google_login') {
        $accessToken = trim($_POST['access_token'] ?? '');
        if (!$accessToken) jsonResponse(['success'=>false,'message'=>'Google access token পাওয়া যায়নি']);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://www.googleapis.com/oauth2/v3/userinfo',
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $apiResp = curl_exec($ch);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) jsonResponse(['success'=>false,'message'=>'Google সার্ভারে সংযোগ ব্যর্থ: '.$curlErr]);

        $gUser = json_decode($apiResp, true);
        if (!$gUser || empty($gUser['sub']) || !empty($gUser['error'])) {
            $errDetail = is_array($gUser['error'] ?? null) ? ($gUser['error']['message'] ?? '') : ($gUser['error'] ?? 'invalid');
            jsonResponse(['success'=>false,'message'=>'Google যাচাই ব্যর্থ: '.$errDetail]);
        }

        $googleId = $conn->real_escape_string($gUser['sub']);
        $email    = $conn->real_escape_string($gUser['email'] ?? '');
        $name     = $conn->real_escape_string($gUser['name'] ?? 'Google User');
        $photo    = $conn->real_escape_string($gUser['picture'] ?? '');

        // Auto-add google_id column if missing
        $colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'google_id'");
        if ($colCheck && $colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN google_id VARCHAR(120) DEFAULT NULL");
        }

        // Find existing user by google_id, then by email
        $res = $conn->query("SELECT * FROM users WHERE google_id='$googleId' LIMIT 1");
        if ($res->num_rows === 0 && $email) {
            $res = $conn->query("SELECT * FROM users WHERE email='$email' LIMIT 1");
        }

        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            if (empty($user['google_id'])) {
                $conn->query("UPDATE users SET google_id='$googleId' WHERE uid='{$user['uid']}'");
            }
            $conn->query("UPDATE users SET last_seen=NOW() WHERE uid='{$user['uid']}'");
        } else {
            // New user via Google — create account
            $uid    = 'GGL_' . strtoupper(substr(md5($googleId . time()), 0, 12));
            $defPic = $photo ?: 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png';
            $conn->query("INSERT INTO users (uid,username,email,phone,password,balance,profile_pic,google_id) VALUES ('$uid','$name','$email','','', 0.00,'$defPic','$googleId')");
            $res2 = $conn->query("SELECT * FROM users WHERE uid='$uid' LIMIT 1");
            $user = $res2->fetch_assoc();
            $newRefCode = 'REF' . strtoupper(substr(md5($uid . time()), 0, 8));
            $conn->query("UPDATE users SET referral_code='$newRefCode' WHERE uid='$uid'");
            $regGrpMsg = "🆕 *নতুন Google ইউজার!*\n\n👤 *Name:* $name\n📧 *Email:* $email\n🕐 *Time:* " . date('d-m-Y H:i:s') . "\n━━━━━━━━━━━━━━━━━━\n🚀 Powered by @SMMGemBot";
            queueTelegramNotification(TG_BOT_TOKEN, '-1003760050398', $regGrpMsg);
        }

        if ($user['blocked']) jsonResponse(['success'=>false,'message'=>'account_blocked']);

        session_unset();
        session_regenerate_id(true);
        $_SESSION['user_uid']   = $user['uid'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_id']    = $user['id'];

        jsonResponse([
            'success'    => true,
            'uid'        => $user['uid'],
            'email'      => $user['email'],
            'username'   => $user['username'],
            'phone'      => $user['phone'],
            'balance'    => $user['balance'],
            'profilePic' => $user['profile_pic'],
            'joined'     => $user['created_at']
        ]);
    }

    // ---------- LOGOUT ----------
    if ($action === 'logout') {
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);
        jsonResponse(['success'=>true]);
    }

    // ---------- FORGOT PASSWORD ----------
    if ($action === 'forgot_password') {
        $email = clean($conn, $_POST['email'] ?? '');
        $res   = $conn->query("SELECT id FROM users WHERE email='$email' LIMIT 1");
        if ($res->num_rows === 0) jsonResponse(['success'=>false,'message'=>'No account found with this email.']);
        jsonResponse(['success'=>true,'message'=>'If this email exists, a reset link has been sent.']);
    }

    // ---------- GET PROFILE ----------
    if ($action === 'get_profile') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in']);
        $uid = clean($conn, $_SESSION['user_uid']);
        $conn->query("UPDATE users SET last_seen=NOW() WHERE uid='$uid'");
        $res = $conn->query("SELECT * FROM users WHERE uid='$uid' LIMIT 1");
        if ($res->num_rows === 0) jsonResponse(['success'=>false,'message'=>'User not found']);
        $user = $res->fetch_assoc();
        if ($user['blocked']) jsonResponse(['success'=>false,'message'=>'account_blocked']);
        jsonResponse([
            'success'    => true,
            'uid'        => $user['uid'],
            'email'      => $user['email'],
            'username'   => $user['tg_name'] ?: $user['username'],
            'phone'      => $user['phone'],
            'balance'    => $user['balance'],
            'profilePic' => $user['tg_photo'] ?: $user['profile_pic'],
            'tgPhoto'    => $user['tg_photo']    ?? '',
            'tgName'     => $user['tg_name']     ?? '',
            'tgUsername' => $user['tg_username'] ?? '',
            'tgId'       => $user['tg_id']       ?? '',
            'isTgUser'   => !empty($user['tg_id']),
            'joined'     => $user['created_at'],
            'totalOrders'  => (int)($conn->query("SELECT COUNT(*) as c FROM orders WHERE uid='{$user["uid"]}'") ->fetch_assoc()['c'] ?? 0),
            'totalDeposit' => (float)($conn->query("SELECT COALESCE(SUM(amount),0) as t FROM deposits WHERE uid='{$user["uid"]}' AND status='Completed'")->fetch_assoc()['t'] ?? 0)
        ]);
    }

    // ---------- UPDATE PROFILE ----------
    if ($action === 'update_profile') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in']);
        $uid  = clean($conn, $_SESSION['user_uid']);
        $name = clean($conn, $_POST['username'] ?? '');
        // profile_pic may be a large base64 string — escape directly without clean()
        $rawPic = $_POST['profile_pic'] ?? '';
        $pic  = $conn->real_escape_string($rawPic);
        // Auto-ensure profile_pic column is TEXT (supports large base64 images)
        $conn->query("ALTER TABLE users MODIFY COLUMN profile_pic TEXT DEFAULT NULL");
        $sets = [];
        if ($name) $sets[] = "username='$name'";
        if ($pic)  $sets[] = "profile_pic='$pic'";
        if (empty($sets)) jsonResponse(['success'=>false,'message'=>'Nothing to update']);
        $conn->query("UPDATE users SET ".implode(',',$sets)." WHERE uid='$uid'");
        // Return saved values so frontend can update navbar instantly
        jsonResponse(['success'=>true,'message'=>'Profile Updated!','username'=>$name,'profilePic'=>$rawPic?:null]);
    }

    // ---------- GET SETTINGS ----------
    if ($action === 'get_settings') {
        $sets = $conn->query("SELECT * FROM admin_settings LIMIT 1")->fetch_assoc();
        $cats = $conn->query("SELECT * FROM categories WHERE hidden=0 ORDER BY sort_order ASC, id ASC");
        $catArr = [];
        while ($r = $cats->fetch_assoc()) $catArr[] = $r;
        $svcs = $conn->query("SELECT * FROM services WHERE hidden=0 ORDER BY sort_order ASC, id ASC");
        $svcArr = [];
        while ($r = $svcs->fetch_assoc()) $svcArr[] = $r;
        $pays = $conn->query("SELECT * FROM payment_methods WHERE hidden=0 ORDER BY sort_order ASC, id ASC");
        $payArr = [];
        while ($r = $pays->fetch_assoc()) $payArr[] = $r;
        // slider items
        $sliderItems = [];
        if (!empty($sets['slider_items'])) {
            $sliderItems = json_decode($sets['slider_items'], true) ?: [];
        }
        jsonResponse(['success'=>true,'settings'=>$sets,'categories'=>$catArr,'services'=>$svcArr,'payment_methods'=>$payArr,'slider_items'=>$sliderItems]);
    }

    // ---------- GET BALANCE ----------
    if ($action === 'get_balance') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'balance'=>0]);
        $uid = clean($conn, $_SESSION['user_uid']);
        $res = $conn->query("SELECT balance FROM users WHERE uid='$uid' LIMIT 1");
        $row = $res->fetch_assoc();
        jsonResponse(['success'=>true,'balance'=>$row['balance']]);
    }

    // ---------- PLACE ORDER ----------
    if ($action === 'place_order') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in']);
        $uid        = clean($conn, $_SESSION['user_uid']);
        $serviceId  = clean($conn, $_POST['service_id']  ?? '');
        $providerId = clean($conn, $_POST['provider_id'] ?? '');
        $svcName    = clean($conn, $_POST['service_name']?? '');
        $link       = clean($conn, $_POST['link']        ?? '');
        $qty        = (int)($_POST['quantity']   ?? 0);
        $charge     = (float)($_POST['charge']   ?? 0);

        if (!$link || !$qty || !$charge) jsonResponse(['success'=>false,'message'=>'Invalid order data']);

        $balRes = $conn->query("SELECT balance FROM users WHERE uid='$uid' LIMIT 1");
        $balRow = $balRes->fetch_assoc();
        if ((float)$balRow['balance'] < $charge) jsonResponse(['success'=>false,'message'=>'insufficient_balance','balance'=>$balRow['balance']]);

        $sets = $conn->query("SELECT api_url,api_key,auto_order_enabled FROM admin_settings LIMIT 1")->fetch_assoc();
        if (!$sets['auto_order_enabled'] || !$sets['api_key']) jsonResponse(['success'=>false,'message'=>'Auto-order system is disabled. Contact admin.']);

        $apiOrderId = '';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $sets['api_url'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['key'=>$sets['api_key'],'action'=>'add','service'=>$providerId,'link'=>$link,'quantity'=>$qty]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $apiResp = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $apiResult = json_decode($apiResp, true);
        $isSuccess = false;
        if (!$curlErr && $apiResult) {
            if (!empty($apiResult['order'])) { $isSuccess = true; $apiOrderId = $apiResult['order']; }
            elseif (!empty($apiResult['request'])) { $isSuccess = true; $apiOrderId = $apiResult['request']; }
            elseif (!empty($apiResult['status']) && strtolower($apiResult['status']) === 'success') { $isSuccess = true; $apiOrderId = $apiResult['order_id'] ?? ('API'.rand(10000,99999)); }
        } elseif (!$curlErr) { $isSuccess = true; }

        if (!$isSuccess) {
            $errMsg = $apiResult['error'] ?? $apiResult['message'] ?? ($curlErr ?: 'API request failed');
            jsonResponse(['success'=>false,'message'=>'Order Failed: '.$errMsg]);
        }

        $newBal   = round((float)$balRow['balance'] - $charge, 2);
        $orderId  = rand(10000, 99999);
        $apiOrdId = $conn->real_escape_string($apiOrderId);
        $svcEsc   = $conn->real_escape_string($svcName);
        $provEsc  = $conn->real_escape_string($providerId);
        $linkEsc  = $conn->real_escape_string($link);

        $conn->query("UPDATE users SET balance=$newBal WHERE uid='$uid'");
        $conn->query("INSERT INTO orders (uid,order_id,api_order_id,service_name,service_id,link,quantity,amount,status) VALUES ('$uid','$orderId','$apiOrdId','$svcEsc','$provEsc','$linkEsc',$qty,$charge,'Completed')");

        // ── Telegram notifications (async, non-blocking) ──
        $userInfoOrd = $conn->query("SELECT tg_username, tg_name, username, tg_id, referral_code FROM users WHERE uid='$uid' LIMIT 1");
        $uiOrd = $userInfoOrd->fetch_assoc();
        $tgUname_ord    = $uiOrd['tg_username'] ?? '';
        $tgId_ord       = $uiOrd['tg_id'] ?? '';
        $tgDisplay_ord  = $tgUname_ord ? "@$tgUname_ord" : ($uiOrd['tg_name'] ?: ($uiOrd['username'] ?? 'User'));
        $refCode_ord    = $uiOrd['referral_code'] ?? '';

        $setsTg2 = $conn->query("SELECT tg_chat_id FROM admin_settings LIMIT 1")->fetch_assoc();
        $tgAdminChat2 = $setsTg2['tg_chat_id'] ?? '';

        // 📝 Order notification message (exact format)
        $orderNotifMsg = "📝 *New Order Received By*\n"
            . "💁 $tgDisplay_ord\n"
            . "ℹ️ *Order ID :* $orderId\n"
            . "✨ *Service :* $svcName\n"
            . "🆔 *Service ID :* $providerId\n"
            . "📈 *Quantity :* $qty\n"
            . "💰 *Charge :* " . number_format($charge, 8) . " ৳\n"
            . "🔗 *Link :* $link";
        $orderBtn = [[['text' => '🛍️ অর্ডার করুন', 'url' => 'https://t.me/SMMGemBot/app?startapp=REFD317B939']]];

        // 📢 Order notification → group এ পাঠাও
        $totalOrdRes2 = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE uid='$uid'");
        $totalOrd2 = (int)($totalOrdRes2->fetch_assoc()['cnt'] ?? 0);
        $adminOrdMsg = "🛒 *নতুন অর্ডার হয়েছে!*\n\n"
            . "🕐 *Time:* " . date('d-m-Y H:i:s') . "\n"
            . "👤 *Username:* $tgDisplay_ord\n"
            . "🛒 *Total Orders:* $totalOrd2\n"
            . "📋 *Service:* $svcName\n"
            . "🆔 *Service ID:* $providerId\n"
            . "📈 *Quantity:* $qty\n"
            . "💰 *Amount:* ৳$charge\n"
            . "🔗 *Link:* $link\n"
            . "🤖 *Bot:* @SMMGemBot";
        queueTelegramNotification(TG_BOT_TOKEN, '-1003760050398', $adminOrdMsg);

        jsonResponse(['success'=>true,'orderId'=>$orderId,'charge'=>$charge,'service'=>$svcName,'newBalance'=>$newBal]);
    }

    // ---------- SUBMIT DEPOSIT ----------
    if ($action === 'submit_deposit') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in']);
        $uid    = clean($conn, $_SESSION['user_uid']);
        $method = clean($conn, $_POST['method'] ?? '');
        $trxId  = clean($conn, $_POST['trx_id'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);

        if (!$method || !$trxId || !$amount) jsonResponse(['success'=>false,'message'=>'All fields required']);

        $chk = $conn->query("SELECT id FROM deposits WHERE trx_id='$trxId' LIMIT 1");
        if ($chk->num_rows > 0) jsonResponse(['success'=>false,'message'=>'This Transaction ID already submitted!']);

        $sets = $conn->query("SELECT min_deposit FROM admin_settings LIMIT 1")->fetch_assoc();
        $payMin = $conn->query("SELECT min_deposit FROM payment_methods WHERE name='$method' LIMIT 1")->fetch_assoc();
        $minDep = $payMin ? (float)$payMin['min_deposit'] : (float)$sets['min_deposit'];
        if ($amount < $minDep) jsonResponse(['success'=>false,'message'=>"Minimum deposit for $method is ৳$minDep"]);

        $conn->query("INSERT INTO deposits (uid,method,trx_id,amount,status) VALUES ('$uid','$method','$trxId',$amount,'Pending')");

        // 🔔 Notify user about pending deposit (async)
        $setsTgDep = $conn->query("SELECT tg_chat_id FROM admin_settings LIMIT 1")->fetch_assoc();
        {
            $uiDep2 = $conn->query("SELECT tg_username, tg_name, username, tg_id, referral_code FROM users WHERE uid='$uid' LIMIT 1")->fetch_assoc();
            $tgDispDep2 = ($uiDep2['tg_username'] ?? '') ? "@{$uiDep2['tg_username']}" : ($uiDep2['tg_name'] ?? ($uiDep2['username'] ?? 'User'));
            $tgIdDep2   = $uiDep2['tg_id'] ?? '';
            $refCodeDep2 = $uiDep2['referral_code'] ?? '';

            // Notify user that deposit is received and under review
            if ($tgIdDep2) {
                $userPendingMsg = "⏳ *ডিপোজিট গৃহীত হয়েছে!*\n\n"
                    . "👤 *Client:* $tgDispDep2\n"
                    . "💰 *Amount:* " . number_format($amount, 2) . " ৳\n"
                    . "💳 *Method:* $method\n"
                    . "🧾 *Transaction ID:* $trxId\n\n"
                    . "🔐 *Status:* PENDING ⏳\n"
                    . "_অ্যাডমিন রিভিউ করার পর ব্যালেন্স যোগ হবে।_\n"
                    . "━━━━━━━━━━━━━━━━━━\n"
                    . "🚀 Powered by @SMMGemBot";
                $pendingBtn = [[['text' => '🛍️ অর্ডার করুন', 'url' => 'https://t.me/SMMGemBot/app?startapp=REFD317B939']]];
                queueTelegramNotification(TG_BOT_TOKEN, $tgIdDep2, $userPendingMsg, $pendingBtn);
            }

            // 📢 Deposit submit → শুধু group এ পাঠাও
            $depBroadcastMsg = "🔋*New Deposit Confirmed* 🔋\n\n"
                . "👤 *Client:*  $tgDispDep2\n"
                . "💰 *Amount:* " . number_format($amount, 2) . " ৳\n"
                . "💳 *Method:* $method\n"
                . "🧾 *Transaction ID:* $trxId\n\n"
                . "🔐 *Status:* PENDING ⏳\n"
                . "━━━━━━━━━━━━━━━━━━\n"
                . "🚀 Powered by @SMMGemBot";
            $depBroadcastBtn = [[['text' => '🛍️ অর্ডার করুন', 'url' => 'https://t.me/SMMGemBot/app?startapp=REFD317B939']]];
            queueTelegramNotification(TG_BOT_TOKEN, '-1003760050398', $depBroadcastMsg, $depBroadcastBtn);
        }

        jsonResponse(['success'=>true,'message'=>'Deposit submitted!','amount'=>$amount,'method'=>$method,'trxId'=>$trxId]);
    }

    // ---------- GET HISTORY ----------
    if ($action === 'get_history') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in','history'=>[]]);
        $uid = clean($conn, $_SESSION['user_uid']);

        $orders = $conn->query("SELECT order_id,api_order_id,service_name AS service,link,quantity,amount,status,created_at,'Order' AS type FROM orders WHERE uid='$uid' ORDER BY id DESC LIMIT 50");
        $history = [];
        while ($r = $orders->fetch_assoc()) {
            $r['orderId']     = $r['order_id'];
            $r['apiOrderId']  = $r['api_order_id'];
            $r['date']        = date('d M Y', strtotime($r['created_at']));
            $r['timestamp']   = strtotime($r['created_at']) * 1000;
            $history[] = $r;
        }

        $deps = $conn->query("SELECT id,method,trx_id,amount,status,created_at,'Deposit' AS type FROM deposits WHERE uid='$uid' ORDER BY id DESC LIMIT 50");
        while ($r = $deps->fetch_assoc()) {
            $r['orderId']    = 'DEP';
            $r['service']    = "Add Money (" . $r['method'] . ")";
            $r['link']       = "Trx: " . $r['trx_id'];
            $r['trxId']      = $r['trx_id'];
            $r['date']       = date('d M Y', strtotime($r['created_at']));
            $r['timestamp']  = strtotime($r['created_at']) * 1000;
            $history[] = $r;
        }

        usort($history, fn($a,$b) => $b['timestamp'] - $a['timestamp']);
        jsonResponse(['success'=>true,'history'=>$history]);
    }

    // ---------- GET REFERRAL STATS ----------
    if ($action === 'get_referral_stats') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in']);
        $uid = clean($conn, $_SESSION['user_uid']);

        // Get referral bonus amount from settings
        $sets = $conn->query("SELECT * FROM admin_settings LIMIT 1")->fetch_assoc();
        $referralBonusAmount = (float)($sets['referral_bonus_per_ref'] ?? 5);

        // Get user referral info
        $userRes = $conn->query("SELECT referral_code, referral_bonus, referrals_count FROM users WHERE uid='$uid' LIMIT 1");
        if (!$userRes || $userRes->num_rows === 0) jsonResponse(['success'=>false,'message'=>'User not found']);
        $user = $userRes->fetch_assoc();

        // Auto-add referral columns if missing
        $cols = $conn->query("SHOW COLUMNS FROM users");
        $existing = [];
        while ($c = $cols->fetch_assoc()) $existing[] = $c['Field'];
        if (!in_array('referral_code', $existing)) $conn->query("ALTER TABLE users ADD COLUMN referral_code VARCHAR(50) DEFAULT NULL");
        if (!in_array('referral_bonus', $existing)) $conn->query("ALTER TABLE users ADD COLUMN referral_bonus DECIMAL(10,2) DEFAULT 0");
        if (!in_array('referrals_count', $existing)) $conn->query("ALTER TABLE users ADD COLUMN referrals_count INT DEFAULT 0");
        if (!in_array('referred_by', $existing)) $conn->query("ALTER TABLE users ADD COLUMN referred_by VARCHAR(50) DEFAULT NULL");

        // Get referral code
        $refCodeRes = $conn->query("SELECT referral_code FROM users WHERE uid='$uid' LIMIT 1");
        $refRow = $refCodeRes->fetch_assoc();
        $referralCode = $refRow['referral_code'] ?? null;
        if (!$referralCode) {
            $referralCode = 'REF' . strtoupper(substr(md5($uid . time()), 0, 8));
            $conn->query("UPDATE users SET referral_code='$referralCode' WHERE uid='$uid'");
        }

        // Get referred users
        $refUsersRes = $conn->query("SELECT id, username, tg_name, tg_username, created_at FROM users WHERE referred_by='$referralCode' ORDER BY id DESC");
        $referredUsers = [];
        $totalDeposits = 0;
        while ($ru = $refUsersRes->fetch_assoc()) {
            $ruUid = $ru['id'];
            // Sum deposits of referred user
            $depRes = $conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM deposits WHERE uid=(SELECT uid FROM users WHERE id=$ruUid) AND status='Completed'");
            $depRow = $depRes->fetch_assoc();
            $userDep = (float)($depRow['total'] ?? 0);
            $totalDeposits += $userDep;
            $referredUsers[] = [
                'name' => $ru['tg_name'] ?: $ru['username'],
                'joined' => $ru['created_at'],
                'deposits' => $userDep
            ];
        }

        // Get updated referral_bonus and referrals_count
        $freshRes = $conn->query("SELECT referral_bonus, referrals_count FROM users WHERE uid='$uid' LIMIT 1");
        $fresh = $freshRes->fetch_assoc();

        jsonResponse([
            'success' => true,
            'referralCode' => $referralCode,
            'referralsCount' => (int)($fresh['referrals_count'] ?? 0),
            'totalReferralBonus' => (float)($fresh['referral_bonus'] ?? 0),
            'totalDepositsFromRefs' => $totalDeposits,
            'referralBonusPerRef' => $referralBonusAmount,
            'referredUsers' => $referredUsers
        ]);
    }

    // ---------- GET LEADERBOARD ----------
    if ($action === 'get_leaderboard') {
        // Top referrers
        $topReferrers = [];
        $refRes = $conn->query("SELECT uid, username, tg_name, tg_username, tg_photo, profile_pic, referrals_count FROM users WHERE referrals_count > 0 ORDER BY referrals_count DESC LIMIT 50");
        if ($refRes) {
            while ($r = $refRes->fetch_assoc()) {
                $topReferrers[] = [
                    'name'   => $r['tg_name'] ?: $r['username'],
                    'photo'  => $r['tg_photo'] ?: $r['profile_pic'],
                    'count'  => (int)$r['referrals_count'],
                    'uid'    => $r['uid']
                ];
            }
        }

        // Top depositors
        $topDepositors = [];
        $depRes = $conn->query("SELECT u.uid, u.username, u.tg_name, u.tg_username, u.tg_photo, u.profile_pic, COALESCE(SUM(d.amount),0) AS total_deposit FROM users u LEFT JOIN deposits d ON u.uid=d.uid AND d.status='Completed' GROUP BY u.uid HAVING total_deposit > 0 ORDER BY total_deposit DESC LIMIT 50");
        if ($depRes) {
            while ($r = $depRes->fetch_assoc()) {
                $topDepositors[] = [
                    'name'    => $r['tg_name'] ?: $r['username'],
                    'photo'   => $r['tg_photo'] ?: $r['profile_pic'],
                    'amount'  => (float)$r['total_deposit'],
                    'uid'     => $r['uid']
                ];
            }
        }

        jsonResponse(['success' => true, 'topReferrers' => $topReferrers, 'topDepositors' => $topDepositors]);
    }

    // ---------- GET ALL USER LOCATIONS ----------
    if ($action === 'get_all_locations') {
        // Auto-add columns if missing
        $locColsChk = $conn->query("SHOW COLUMNS FROM users");
        $locColsEx  = [];
        while ($c = $locColsChk->fetch_assoc()) $locColsEx[] = $c['Field'];
        if (!in_array('loc_lat',         $locColsEx)) $conn->query("ALTER TABLE users ADD COLUMN loc_lat DECIMAL(11,7) DEFAULT NULL");
        if (!in_array('loc_lng',         $locColsEx)) $conn->query("ALTER TABLE users ADD COLUMN loc_lng DECIMAL(11,7) DEFAULT NULL");
        if (!in_array('loc_country',     $locColsEx)) $conn->query("ALTER TABLE users ADD COLUMN loc_country VARCHAR(100) DEFAULT NULL");
        if (!in_array('loc_district',    $locColsEx)) $conn->query("ALTER TABLE users ADD COLUMN loc_district VARCHAR(100) DEFAULT NULL");
        if (!in_array('loc_thana',       $locColsEx)) $conn->query("ALTER TABLE users ADD COLUMN loc_thana VARCHAR(100) DEFAULT NULL");
        if (!in_array('loc_verified',    $locColsEx)) $conn->query("ALTER TABLE users ADD COLUMN loc_verified TINYINT DEFAULT 0");
        if (!in_array('loc_verified_at', $locColsEx)) $conn->query("ALTER TABLE users ADD COLUMN loc_verified_at DATETIME DEFAULT NULL");

        $locRes = $conn->query("SELECT uid, username, tg_name, tg_username, tg_photo, profile_pic, loc_lat, loc_lng, loc_country, loc_district, loc_thana, loc_verified_at FROM users WHERE loc_verified=1 AND loc_lat IS NOT NULL AND loc_lng IS NOT NULL ORDER BY loc_verified_at DESC");
        $locations = [];
        if ($locRes) {
            while ($r = $locRes->fetch_assoc()) {
                $locations[] = [
                    'name'        => $r['tg_name'] ?: ($r['username'] ?: 'User'),
                    'photo'       => $r['tg_photo'] ?: ($r['profile_pic'] ?: ''),
                    'tg_username' => $r['tg_username'] ?? '',
                    'lat'         => (float)$r['loc_lat'],
                    'lng'         => (float)$r['loc_lng'],
                    'country'     => $r['loc_country']   ?: 'অজানা',
                    'district'    => $r['loc_district']  ?: 'অজানা',
                    'thana'       => $r['loc_thana']     ?: 'অজানা',
                    'verified_at' => $r['loc_verified_at'] ?? '',
                ];
            }
        }
        jsonResponse(['success' => true, 'total' => count($locations), 'locations' => $locations]);
    }

    // ---------- GENERATE / GET API KEY ----------
    if ($action === 'get_api_key') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in']);
        $uid = clean($conn, $_SESSION['user_uid']);

        // Auto-create api_key column if it doesn't exist
        $colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'api_key'");
        if ($colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN api_key VARCHAR(80) DEFAULT NULL");
        }

        $res = $conn->query("SELECT api_key FROM users WHERE uid='$uid' LIMIT 1");
        if (!$res) jsonResponse(['success'=>false,'message'=>'DB error: '.$conn->error]);
        $row = $res->fetch_assoc();

        if (empty($row['api_key'])) {
            $newKey = 'SMM_' . strtoupper(bin2hex(random_bytes(16)));
            $conn->query("UPDATE users SET api_key='$newKey' WHERE uid='$uid'");
            jsonResponse(['success'=>true,'api_key'=>$newKey,'generated'=>true]);
        } else {
            jsonResponse(['success'=>true,'api_key'=>$row['api_key'],'generated'=>false]);
        }
    }

    // ---------- REGENERATE API KEY ----------
    if ($action === 'regenerate_api_key') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in']);
        $uid = clean($conn, $_SESSION['user_uid']);

        // Auto-create api_key column if it doesn't exist
        $colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'api_key'");
        if ($colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN api_key VARCHAR(80) DEFAULT NULL");
        }

        $newKey = 'SMM_' . strtoupper(bin2hex(random_bytes(16)));
        $conn->query("UPDATE users SET api_key='$newKey' WHERE uid='$uid'");
        jsonResponse(['success'=>true,'api_key'=>$newKey]);
    }

    // ---------- GET ALL USER HISTORY (admin-style for History tab) ----------
    if ($action === 'get_all_user_history') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in','history'=>[]]);
        $orders = $conn->query("SELECT o.order_id, o.service_name AS service, o.amount, o.status, o.created_at, u.username, u.tg_username, u.profile_pic, u.tg_photo, 'Order' AS type FROM orders o LEFT JOIN users u ON o.uid=u.uid ORDER BY o.id DESC LIMIT 100");
        $history = [];
        while($r=$orders->fetch_assoc()) { $r['timestamp']=strtotime($r['created_at'])*1000; $history[]=$r; }
        $deps = $conn->query("SELECT d.trx_id, d.method, d.amount, d.status, d.created_at, u.username, u.tg_username, u.profile_pic, u.tg_photo, 'Deposit' AS type FROM deposits d LEFT JOIN users u ON d.uid=u.uid ORDER BY d.id DESC LIMIT 100");
        while($r=$deps->fetch_assoc()) { $r['timestamp']=strtotime($r['created_at'])*1000; $history[]=$r; }
        $regs = $conn->query("SELECT u.username, u.tg_username, u.profile_pic, u.tg_photo, u.created_at, 'NewUser' AS type FROM users u ORDER BY u.id DESC LIMIT 30");
        while($r=$regs->fetch_assoc()) { $r['timestamp']=strtotime($r['created_at'])*1000; $history[]=$r; }
        usort($history, fn($a,$b) => $b['timestamp'] - $a['timestamp']);
        jsonResponse(['success'=>true,'history'=>array_slice($history,0,150)]);
    }

    // ---------- GET LOGGED USERS ----------
    if ($action === 'get_logged_users') {
        $loggedRes = $conn->query("SELECT uid, username, tg_name, tg_username, tg_photo, profile_pic, last_seen FROM users WHERE last_seen IS NOT NULL ORDER BY last_seen DESC LIMIT 50");
        $loggedUsers = [];
        if ($loggedRes) {
            while ($r = $loggedRes->fetch_assoc()) $loggedUsers[] = $r;
        }
        // Top 3 by total orders (most active users)
        $top3Res = $conn->query("SELECT u.uid, u.username, u.tg_name, u.tg_username, u.tg_photo, u.profile_pic, u.last_seen, COUNT(o.id) as total_orders FROM users u LEFT JOIN orders o ON u.uid=o.uid WHERE u.last_seen IS NOT NULL GROUP BY u.uid ORDER BY total_orders DESC, u.last_seen DESC LIMIT 3");
        $top3 = [];
        if ($top3Res) {
            while ($r = $top3Res->fetch_assoc()) $top3[] = $r;
        }
        jsonResponse(['success' => true, 'loggedUsers' => $loggedUsers, 'top3' => $top3]);
    }

    // ---------- SAVE LOCATION ----------
    if ($action === 'save_location') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in']);
        $uid      = clean($conn, $_SESSION['user_uid']);
        $lat      = (float)($_POST['lat']      ?? 0);
        $lng      = (float)($_POST['lng']      ?? 0);
        $country  = clean($conn, $_POST['country']  ?? '');
        $district = clean($conn, $_POST['district'] ?? '');
        $thana    = clean($conn, $_POST['thana']    ?? '');

        if (!$lat || !$lng) jsonResponse(['success'=>false,'message'=>'Invalid coordinates']);

        // Auto-migrate location columns
        $locCols = $conn->query("SHOW COLUMNS FROM users");
        $locExisting = [];
        while ($lc = $locCols->fetch_assoc()) $locExisting[] = $lc['Field'];
        if (!in_array('loc_country',     $locExisting)) $conn->query("ALTER TABLE users ADD COLUMN loc_country VARCHAR(150) DEFAULT NULL");
        if (!in_array('loc_district',    $locExisting)) $conn->query("ALTER TABLE users ADD COLUMN loc_district VARCHAR(150) DEFAULT NULL");
        if (!in_array('loc_thana',       $locExisting)) $conn->query("ALTER TABLE users ADD COLUMN loc_thana VARCHAR(150) DEFAULT NULL");
        if (!in_array('loc_lat',         $locExisting)) $conn->query("ALTER TABLE users ADD COLUMN loc_lat DECIMAL(11,7) DEFAULT NULL");
        if (!in_array('loc_lng',         $locExisting)) $conn->query("ALTER TABLE users ADD COLUMN loc_lng DECIMAL(11,7) DEFAULT NULL");
        if (!in_array('loc_verified',    $locExisting)) $conn->query("ALTER TABLE users ADD COLUMN loc_verified TINYINT(1) DEFAULT 0");
        if (!in_array('loc_verified_at', $locExisting)) $conn->query("ALTER TABLE users ADD COLUMN loc_verified_at DATETIME DEFAULT NULL");

        $conn->query("UPDATE users SET loc_country='$country', loc_district='$district', loc_thana='$thana', loc_lat=$lat, loc_lng=$lng, loc_verified=1, loc_verified_at=NOW() WHERE uid='$uid'");

        // Get user info for Telegram notification
        $locUserRes = $conn->query("SELECT tg_username, tg_name, username, tg_id FROM users WHERE uid='$uid' LIMIT 1");
        $locUser    = $locUserRes->fetch_assoc();
        $locDisplay = $locUser['tg_username'] ? '@'.$locUser['tg_username'] : ($locUser['tg_name'] ?: $locUser['username']);

        $mapLink = "https://maps.google.com/?q=$lat,$lng";
        $locMsg = "📍 *নতুন লোকেশন ভেরিফাইড!*\n\n"
            . "👤 *User:* $locDisplay\n"
            . "🌍 *Country:* $country\n"
            . "🏙️ *District:* $district\n"
            . "🏘️ *Thana/Upazila:* $thana\n"
            . "📍 *Map:* `$lat, $lng`\n"
            . "🗺️ *Map link:* [Google Maps]($mapLink)\n"
            . "\n"
            . "🕐 *Time:* " . date('d-m-Y H:i:s') . "\n"
            . "━━━━━━━━━━━━━━━━━━\n"
            . "🚀 Powered by @SMMGemBot";

        // Notify channel (non-blocking, after response)
        queueTelegramNotification(TG_BOT_TOKEN, '-1003760050398', $locMsg);
        jsonResponse(['success'=>true,'message'=>'Location verified successfully!']);
    }

    // ---------- GET LOCATION ----------
    if ($action === 'get_location') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in']);
        $uid = clean($conn, $_SESSION['user_uid']);
        // Check if columns exist
        $chkCol = $conn->query("SHOW COLUMNS FROM users LIKE 'loc_verified'");
        if (!$chkCol || $chkCol->num_rows === 0) {
            jsonResponse(['success'=>true,'verified'=>false]);
        }
        $locRes = $conn->query("SELECT loc_country, loc_district, loc_thana, loc_verified, loc_verified_at FROM users WHERE uid='$uid' LIMIT 1");
        $locRow = $locRes->fetch_assoc();
        if ($locRow && $locRow['loc_verified']) {
            jsonResponse(['success'=>true,'verified'=>true,'country'=>$locRow['loc_country'],'district'=>$locRow['loc_district'],'thana'=>$locRow['loc_thana'],'verifiedAt'=>$locRow['loc_verified_at']]);
        } else {
            jsonResponse(['success'=>true,'verified'=>false]);
        }
    }

    // ---------- SEARCH ORDER ----------
    if ($action === 'search_order') {
        if (empty($_SESSION['user_uid'])) jsonResponse(['success'=>false,'message'=>'Not logged in']);
        $uid   = clean($conn, $_SESSION['user_uid']);
        $query = clean($conn, $_POST['query'] ?? '');

        $item = null;
        $ord  = $conn->query("SELECT * FROM orders WHERE uid='$uid' AND (order_id='$query' OR api_order_id='$query') LIMIT 1");
        if ($ord->num_rows > 0) {
            $r    = $ord->fetch_assoc();
            $item = ['type'=>'Order','orderId'=>$r['order_id'],'trxId'=>$r['api_order_id'],'service'=>$r['service_name'],'amount'=>$r['amount'],'status'=>$r['status'],'date'=>date('d M Y',strtotime($r['created_at']))];
        } else {
            $dep = $conn->query("SELECT * FROM deposits WHERE uid='$uid' AND trx_id='$query' LIMIT 1");
            if ($dep->num_rows > 0) {
                $r    = $dep->fetch_assoc();
                $item = ['type'=>'Deposit','orderId'=>'DEP','trxId'=>$r['trx_id'],'service'=>"Add Money (".$r['method'].")",'amount'=>$r['amount'],'status'=>$r['status'],'date'=>date('d M Y',strtotime($r['created_at']))];
            }
        }

        if ($item) jsonResponse(['success'=>true,'item'=>$item]);
        else jsonResponse(['success'=>false,'message'=>'Not Found']);
    }

    exit;
}

// ====================================================
// Page Load: Check Auth State
// ====================================================
$loggedIn = !empty($_SESSION['user_uid']);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SMMGem</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js"></script>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
<!-- Telegram WebApp SDK -->
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<!-- Google Identity Services -->
<script src="https://accounts.google.com/gsi/client" async defer></script>
<!-- Payment Verify API (GET method, no Firebase SDK needed) -->
<style>
/* =============================================
   GLOBAL / BASE
   ============================================= */
/* =============================================
   GLOBAL / BASE — THEME SYSTEM
   ============================================= */
:root {
  --bg-body: #f8f9fa;
  --primary-blue: #1877f2;
  --nav-bg: #ffffff;
  --card-bg: #ffffff;
  --text-primary: #1e293b;
  --text-secondary: #64748b;
  --border-color: #e2e8f0;
  --input-bg: #f8fafc;
  --btn-radius: 8px;
}
/* DARK THEME */
body.theme-dark {
  --bg-body: #0f0f1a;
  --nav-bg: #1a1a2e;
  --card-bg: #1e1e30;
  --text-primary: #e2e8f0;
  --text-secondary: #94a3b8;
  --border-color: #2d2d4e;
  --input-bg: #2a2a3e;
  --primary-blue: #5b9bd5;
}
body.theme-dark { background-color: var(--bg-body) !important; color: var(--text-primary); }
body.theme-dark .card, body.theme-dark .profile-info-card, body.theme-dark .history-card, body.theme-dark .am-app-container,
body.theme-dark .bottom-nav, body.theme-dark .auth-card, body.theme-dark .am-page { background: var(--card-bg) !important; color: var(--text-primary); }
body.theme-dark .bottom-nav { border-top-color: var(--border-color); }
body.theme-dark .bottom-nav .nav-item { color: #94a3b8; }
body.theme-dark .bottom-nav .nav-item.active { color: #5b9bd5; }
body.theme-dark .form-control, body.theme-dark .form-select { background: var(--input-bg) !important; color: var(--text-primary) !important; border-color: var(--border-color) !important; }
body.theme-dark .bg-light { background: var(--input-bg) !important; }
body.theme-dark .text-muted { color: var(--text-secondary) !important; }
body.theme-dark .border { border-color: var(--border-color) !important; }
body.theme-dark .info-row { border-bottom-color: var(--border-color); }
body.theme-dark .all-user-history-card { background: var(--card-bg); }
body.theme-dark .am-top-nav { background: var(--card-bg); border-bottom-color: var(--border-color); }
body.theme-dark .am-nav-icon { background: var(--input-bg); border-color: var(--border-color); color: var(--text-secondary); }
body.theme-dark .am-history-section { background: var(--card-bg); border-color: var(--border-color); }
body.theme-dark .am-history-header { background: var(--input-bg); border-bottom-color: var(--border-color); }
body.theme-dark .am-history-item { border-bottom-color: var(--border-color); }
body.theme-dark .am-history-item:hover { background: var(--input-bg); }
body.theme-dark .modal-content { background: var(--card-bg) !important; color: var(--text-primary); }

/* PURPLE THEME */
body.theme-purple {
  --bg-body: #1a0530;
  --nav-bg: #2d0a5e;
  --card-bg: #21063a;
  --text-primary: #e9d8ff;
  --text-secondary: #b39ddb;
  --border-color: #4a1a7a;
  --input-bg: #2d0a5e;
  --primary-blue: #b57bee;
}
body.theme-purple { background-color: var(--bg-body) !important; color: var(--text-primary); }
body.theme-purple .card, body.theme-purple .profile-info-card, body.theme-purple .history-card,
body.theme-purple .bottom-nav, body.theme-purple .all-user-history-card { background: var(--card-bg) !important; color: var(--text-primary); }
body.theme-purple .bottom-nav { border-top-color: var(--border-color); }
body.theme-purple .bottom-nav .nav-item { color: #b39ddb; }
body.theme-purple .bottom-nav .nav-item.active { color: #b57bee; }
body.theme-purple .form-control, body.theme-purple .form-select { background: var(--input-bg) !important; color: var(--text-primary) !important; border-color: var(--border-color) !important; }
body.theme-purple .bg-light { background: var(--input-bg) !important; }
body.theme-purple .text-muted { color: var(--text-secondary) !important; }

/* BLUE THEME */
body.theme-blue {
  --bg-body: #021a3a;
  --nav-bg: #042d6b;
  --card-bg: #032352;
  --text-primary: #d4e9ff;
  --text-secondary: #7fb3e8;
  --border-color: #0d4a9b;
  --input-bg: #042d6b;
  --primary-blue: #4da6ff;
}
body.theme-blue { background-color: var(--bg-body) !important; color: var(--text-primary); }
body.theme-blue .card, body.theme-blue .profile-info-card, body.theme-blue .history-card,
body.theme-blue .bottom-nav, body.theme-blue .all-user-history-card { background: var(--card-bg) !important; color: var(--text-primary); }
body.theme-blue .bottom-nav { border-top-color: var(--border-color); }
body.theme-blue .bottom-nav .nav-item { color: #7fb3e8; }
body.theme-blue .bottom-nav .nav-item.active { color: #4da6ff; }
body.theme-blue .form-control, body.theme-blue .form-select { background: var(--input-bg) !important; color: var(--text-primary) !important; border-color: var(--border-color) !important; }
body.theme-blue .bg-light { background: var(--input-bg) !important; }
body.theme-blue .text-muted { color: var(--text-secondary) !important; }

/* GREEN THEME */
body.theme-green {
  --bg-body: #031a0e;
  --nav-bg: #052e17;
  --card-bg: #042314;
  --text-primary: #d4ffe4;
  --text-secondary: #6ec492;
  --border-color: #0d5c2a;
  --input-bg: #052e17;
  --primary-blue: #3dba6f;
}
body.theme-green { background-color: var(--bg-body) !important; color: var(--text-primary); }
body.theme-green .card, body.theme-green .profile-info-card, body.theme-green .history-card,
body.theme-green .bottom-nav, body.theme-green .all-user-history-card { background: var(--card-bg) !important; color: var(--text-primary); }
body.theme-green .bottom-nav { border-top-color: var(--border-color); }
body.theme-green .bottom-nav .nav-item { color: #6ec492; }
body.theme-green .bottom-nav .nav-item.active { color: #3dba6f; }
body.theme-green .form-control, body.theme-green .form-select { background: var(--input-bg) !important; color: var(--text-primary) !important; border-color: var(--border-color) !important; }
body.theme-green .bg-light { background: var(--input-bg) !important; }
body.theme-green .text-muted { color: var(--text-secondary) !important; }

/* SQUARE BUTTONS — override Bootstrap round classes globally */
.btn, button, .btn-primary, .btn-danger, .btn-success, .btn-dark, .btn-light, .btn-outline-secondary,
.auth-btn, .am-verify-btn, .am-copy-btn, .am-qr-btn, .nav-item {
  border-radius: var(--btn-radius) !important;
}
.rounded-pill { border-radius: var(--btn-radius) !important; }
.rounded-4 { border-radius: 10px !important; }
.rounded-circle { border-radius: 50% !important; }

/* SETTINGS CARD in Profile */
.settings-card {
  background: var(--card-bg, #fff);
  border-radius: 14px;
  padding: 16px;
  margin-bottom: 15px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.05);
  border: 1px solid var(--border-color, #e2e8f0);
}
.settings-card .settings-title {
  font-weight: 700; font-size: 13px; color: var(--text-secondary, #64748b);
  text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px;
  display: flex; align-items: center; gap: 6px;
}
.settings-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 0; border-bottom: 1px solid var(--border-color, #f0f0f0);
}
.settings-row:last-child { border-bottom: none; padding-bottom: 0; }
.settings-row-info { display: flex; align-items: center; gap: 10px; }
.settings-row-icon { font-size: 20px; width: 32px; text-align: center; }
.settings-row-label { font-size: 14px; font-weight: 600; color: var(--text-primary, #333); }
/* Custom toggle */
.toggle-switch { position: relative; width: 50px; height: 26px; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
  position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
  background: #ccc; border-radius: 26px; transition: .3s;
}
.toggle-slider:before {
  position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px;
  background: white; border-radius: 50%; transition: .3s;
}
.toggle-switch input:checked + .toggle-slider { background: #667eea; }
.toggle-switch input:checked + .toggle-slider:before { transform: translateX(24px); }
/* Theme selector */
.theme-grid {
  display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-top: 10px;
}
.theme-btn {
  aspect-ratio: 1; border-radius: 8px !important; border: 3px solid transparent;
  cursor: pointer; transition: all 0.2s; position: relative; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
}
.theme-btn.active { border-color: #fff; box-shadow: 0 0 0 2px #667eea; }
.theme-btn-label { font-size: 9px; font-weight: 700; color: white; text-shadow: 0 1px 2px rgba(0,0,0,0.5); text-align: center; }
/* Top 3 Podium for Leaderboard */
.lb-podium { display: flex; align-items: flex-end; justify-content: center; gap: 8px; margin-bottom: 24px; }
.lb-podium-item { display: flex; flex-direction: column; align-items: center; flex: 1; }
.lb-podium-crown { font-size: 22px; margin-bottom: 4px; }
.lb-podium-img { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 3px solid; }
.lb-podium-name { font-size: 11px; font-weight: 700; margin-top: 5px; text-align: center; max-width: 70px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lb-podium-val { font-size: 11px; font-weight: 600; margin-top: 2px; }
.lb-podium-base { border-radius: 8px 8px 0 0; width: 100%; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800; color: white; }
.lb-podium-1 .lb-podium-base { background: linear-gradient(135deg, #f6a623, #f04e23); height: 70px; }
.lb-podium-2 .lb-podium-base { background: linear-gradient(135deg, #a0a0a0, #707070); height: 55px; }
.lb-podium-3 .lb-podium-base { background: linear-gradient(135deg, #cd7f32, #a05a2c); height: 45px; }
.lb-podium-1 .lb-podium-img { border-color: #f6a623; width: 68px; height: 68px; }

body { font-family: 'Inter', sans-serif; background-color: var(--bg-body, #f8f9fa); padding-bottom: 90px; user-select: none; overflow-x: hidden; transition: background-color 0.3s; }

/* SPLASH */
#splash-screen { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: var(--bg-body, #ffffff); z-index: 20000; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.splash-logo { width: 100px; height: 100px; margin-bottom: 15px; border-radius: 15px; object-fit: contain; animation: bounceLogo 2.5s ease-in-out infinite; }
.splash-title { font-weight: 800; font-size: 24px; color: var(--primary-blue); margin-bottom: 20px; }
@keyframes bounceLogo { 0% { transform: translateY(0); } 50% { transform: translateY(-12px); } 100% { transform: translateY(0); } }
.loading-dots { display: flex; justify-content: center; align-items: center; gap: 8px; height: 40px; }
.loading-dots .dot { width: 12px; height: 12px; background: var(--primary-blue); border-radius: 50%; animation: wave 1.4s infinite ease-in-out; opacity: 0.6; }
.loading-dots .dot:nth-child(1) { animation-delay: -0.32s; }
.loading-dots .dot:nth-child(2) { animation-delay: -0.16s; }
.loading-dots .dot:nth-child(3) { animation-delay: 0s; }
@keyframes wave { 0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; } 40% { transform: scale(1); opacity: 1; } }

/* NAVBAR */
.navbar { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 40%, #0f3460 70%, #533483 100%); box-shadow: 0 4px 20px rgba(83,52,131,0.45); padding: 5px 0; border-radius: 0 0 18px 18px; transition: transform 0.35s cubic-bezier(0.4,0,0.2,1), box-shadow 0.3s; }
.navbar.nav-hidden { transform: translateY(-110%); box-shadow: none; }
.navbar::after { content: ''; display: block; height: 3px; background: linear-gradient(90deg, #e94560, #f5a623, #00d4ff, #667eea, #e94560); background-size: 200% 100%; border-radius: 0 0 18px 18px; animation: navbarRainbow 3s linear infinite; }
@keyframes navbarRainbow { 0%{background-position:0% 50%} 100%{background-position:200% 50%} }
.tg-channel-btn:hover { transform: scale(1.1); }
.navbar .container { display: flex; justify-content: space-between; align-items: center; }
.navbar-brand { font-weight: 700; color: #ffffff; font-size: 20px; display: flex; align-items: center; gap: 10px; white-space: nowrap; text-shadow: 0 1px 3px rgba(0,0,0,0.3); }
.nav-logo-img { width: 36px; height: 36px; object-fit: contain; flex-shrink: 0; }
.app-section { display: none; animation: fadeIn 0.3s; }
.app-section.active { display: block; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.balance-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 8px 16px; border-radius: 50px; font-size: 13px; cursor: pointer; min-width: 120px; text-align: center; display: flex; justify-content: center; align-items: center; gap: 8px; font-weight: 600; box-shadow: 0 4px 10px rgba(118, 75, 162, 0.3); }

/* STATS GRID HOME — old.php style */
.stats-grid-home { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 18px; }
.stat-card-home { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 14px; padding: 15px; box-shadow: 0 4px 14px rgba(102,126,234,0.3); transition: transform 0.2s; animation: fadeInUp 0.5s ease; }
.stat-card-home:hover { transform: translateY(-3px); }
.stat-card-home .stat-label-home { font-size: 12px; color: rgba(255,255,255,0.8); margin-bottom: 4px; }
.stat-card-home .stat-value-home { font-size: 20px; font-weight: 700; color: white; }
.stat-card-home-add { background: linear-gradient(135deg, #22c55e, #16a34a); cursor: pointer; display: flex; flex-direction: column; justify-content: center; box-shadow: 0 4px 14px rgba(34,197,94,0.3); padding: 10px 15px; }
.stat-card-home-add .stat-value-home { font-size: 15px; display: flex; align-items: center; }
/* Single balance card — image style */
.balance-main-card { background: linear-gradient(120deg, #4f6ef7 0%, #7c3aed 55%, #9333ea 100%); border-radius: 18px; padding: 20px 18px 20px 20px; margin-bottom: 15px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 6px 20px rgba(102,126,234,0.35); animation: fadeInUp 0.5s ease; min-height: 110px; }
.balance-main-card .bmc-left { display: flex; flex-direction: column; justify-content: flex-start; }
.balance-main-card .bmc-left .bmc-label { font-size: 13px; color: rgba(255,255,255,0.78); font-weight: 500; margin-bottom: 10px; letter-spacing: 0.1px; }
.balance-main-card .bmc-left .bmc-value { font-size: 28px; font-weight: 800; color: #fff; letter-spacing: -1px; line-height: 1; }
.balance-main-card .bmc-add-btn { background: #22c55e; color: white; border: none; border-radius: 12px; padding: 15px 22px; font-size: 15px; font-weight: 700; cursor: pointer; white-space: nowrap; box-shadow: 0 4px 14px rgba(34,197,94,0.40); transition: transform 0.15s, box-shadow 0.15s; flex-shrink: 0; }
.balance-main-card .bmc-add-btn:active { transform: scale(0.96); box-shadow: none; }
/* NOTICE BOARD — image 1 style */
.notice-board { background: #fff0f3; color: #222; padding: 0; border-radius: 10px; margin-bottom: 15px; display: flex; align-items: stretch; box-shadow: none; overflow: hidden; min-height: 40px; max-height: 40px; border: 1px solid #ffd6de; }
.notice-board .notice-label { background: #e11d48; color: #fff; padding: 0 12px; font-size: 11.5px; font-weight: 800; white-space: nowrap; letter-spacing: 0.4px; z-index: 2; flex-shrink: 0; display: flex; align-items: center; gap: 5px; border-radius: 8px 0 0 8px; min-width: 86px; justify-content: center; }
.notice-board .notice-label .nb-icon { font-size: 12px; }
@keyframes nbPulse { 0%,100%{opacity:1;} 50%{opacity:0.7;} }
.notice-board .notice-scroll { flex: 1; overflow: hidden; white-space: nowrap; font-size: 12px; font-weight: 600; position: relative; display: flex; align-items: center; background: transparent; color: #e11d48; padding-left: 6px; }
.notice-board .notice-scroll-inner { display: inline-block; padding-left: 100%; animation: nbMarquee 22s linear infinite; }
@keyframes nbMarquee { 0%{transform:translate(0,0);} 100%{transform:translate(-100%,0);} }

/* CATEGORY */
.category-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin: 12px 0 14px 0; min-height: 50px; }
.cat-card { background: transparent; border: none !important; border-radius: 0 !important; padding: 4px 2px 8px; text-align: center; cursor: pointer; transition: transform 0.2s; animation: popIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards; opacity: 0; transform: scale(0.5); box-shadow: none !important; }
@keyframes popIn { 0% { opacity: 0; transform: scale(0.5); } 100% { opacity: 1; transform: scale(1); } }
.cat-card:nth-child(1) { animation-delay: 0.05s; } .cat-card:nth-child(2) { animation-delay: 0.1s; }
.cat-card:nth-child(3) { animation-delay: 0.15s; } .cat-card:nth-child(4) { animation-delay: 0.2s; }
.cat-card.active { background: transparent !important; border: none !important; box-shadow: none !important; transform: scale(1.04); }
.cat-card.active span { color: #111; font-weight: 700; }
.cat-card:not(.active) .all-inner-box { border-color: #d1d5db !important; }
.cat-card.active img.cat-logo { border-color: #111 !important; }
.cat-card img.cat-logo { width: 52px; height: 52px; object-fit: cover; margin: 0 auto 5px; display: block; border-radius: 14px; border: 2px solid #d1d5db; padding: 1px; background: #fff; }
.cat-card i { font-size: 24px; margin-bottom: 5px; display: block; color: #64748b; }
.cat-card span { font-size: 10.5px; font-weight: 600; display: block; color: #222; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding: 0 2px; line-height: 1.3; }

/* SEARCH */
.search-wrapper { position: relative; margin-bottom: 20px; }
.search-wrapper i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #666; }
.search-wrapper input { padding-left: 40px; border-radius: 50px; }
.btn-click-effect { transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1); }
.btn-click-effect:hover { transform: translateY(-50%) scale(1.1) !important; box-shadow: 0 4px 15px rgba(24, 119, 242, 0.3); }
.btn-click-effect:active { transform: translateY(-50%) scale(0.9) !important; box-shadow: none; }

/* HISTORY */
.history-card { background: white; border-radius: 12px; padding: 15px; margin-bottom: 10px; border-left: 5px solid #ddd; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
.status-badge { padding: 4px 12px; border-radius: 50px; font-size: 11px; color: white; align-self: center; margin-top: auto; margin-bottom: auto; }
.filter-btn.active { background-color: #343a40; color: white; border-color: #343a40; }

/* BOTTOM NAV */
.bottom-nav { position: fixed; bottom: 0; left: 0; width: 100%; background: var(--nav-bg); box-shadow: 0 -2px 10px rgba(0,0,0,0.05); display: flex; justify-content: space-around; padding: 10px 0; z-index: 1000; border-top: 1px solid #eee; }
.nav-item { text-align: center; color: #888; text-decoration: none; font-size: 11px; flex: 1; cursor: pointer; }
.nav-item i { display: block; font-size: 20px; margin-bottom: 4px; }
.nav-item.active { color: var(--primary-blue); }

/* AUTH */
#auth-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #f4f7fa; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; overflow-y: auto; touch-action: pan-y; -webkit-overflow-scrolling: touch; }
.auth-card { background: #ffffff; width: 100%; max-width: 380px; padding: 45px 30px; border-radius: 35px; box-shadow: 0 20px 60px rgba(0,0,0,0.08); text-align: center; position: relative; animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes slideUp { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }
.auth-logo-img { width: 100px; height: 100px; object-fit: contain; margin-bottom: 30px; border-radius: 20px; animation: bounceLogo 2.5s ease-in-out infinite; }
.auth-input-group { margin-bottom: 20px; position: relative; text-align: left; }
.auth-input-group label { font-size: 11px; font-weight: 700; color: #8898aa; text-transform: uppercase; letter-spacing: 0.5px; margin-left: 5px; margin-bottom: 6px; display: block; }
.auth-input-group .icon-left { position: absolute; left: 18px; top: 40px; color: #adb5bd; font-size: 16px; transition: 0.3s; }
.auth-input-group input { padding-left: 50px; padding-right: 45px; height: 55px; border-radius: 16px; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 15px; font-weight: 600; color: #333; transition: all 0.3s; width: 100%; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); user-select: text; -webkit-user-select: text; touch-action: manipulation; }
.auth-input-group input:focus { border-color: var(--primary-blue); background: #fff; box-shadow: 0 5px 20px rgba(24, 119, 242, 0.15); outline: none; }
.password-toggle { position: absolute; right: 18px; top: 40px; color: #adb5bd; cursor: pointer; font-size: 16px; padding: 5px; transition: color 0.3s; z-index: 10; }
.password-toggle:hover { color: var(--primary-blue); }
.auth-btn { width: 100%; height: 55px; border-radius: 16px; font-weight: 700; font-size: 16px; margin-top: 10px; background: var(--primary-blue); border: none; box-shadow: 0 10px 25px rgba(24, 119, 242, 0.3); transition: all 0.2s; letter-spacing: 0.5px; }
.auth-btn:active { transform: scale(0.97); box-shadow: 0 5px 15px rgba(24, 119, 242, 0.2); }
.auth-btn:disabled { background: #b0c4de; box-shadow: none; cursor: not-allowed; }
.switch-link { margin-top: 30px; text-align: center; font-size: 14px; color: #8898aa; font-weight: 500; }
.switch-link span { color: #333; font-weight: 800; cursor: pointer; transition: color 0.2s; margin-left: 5px; }
.switch-link span:hover { color: var(--primary-blue); text-decoration: underline; }

/* PROFILE */
.profile-header { text-align: center; margin-bottom: 25px; margin-top: 20px; }
.profile-img-container { position: relative; width: 110px; height: 110px; margin: 0 auto 15px; }
.profile-img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 4px solid white; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
.cam-btn { position: absolute; bottom: 5px; right: 5px; background: var(--primary-blue); color: white; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
.profile-info-card { background: white; border-radius: 15px; padding: 20px; margin-bottom: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
.info-row { margin-bottom: 12px; border-bottom: 1px solid #f0f0f0; padding-bottom: 12px; }
.info-row:last-child { border: none; padding-bottom: 0; }
.info-label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.info-value { font-size: 14px; font-weight: 500; color: #333; display: flex; align-items: center; justify-content: space-between; }
.info-value input { border: none; background: transparent; width: 100%; font-weight: 500; color: #333; outline: none; }
#editUid { cursor: pointer; transition: color 0.2s; }
#editUid:active { color: var(--primary-blue) !important; transform: scale(0.98); }

/* SLIDER */
.slider-slide { position:absolute; top:0; left:0; width:100%; height:100%; opacity:0; transition:opacity 0.01s; border-radius:12px; overflow:hidden; pointer-events:none; }
.slider-slide.active { opacity:1; position:relative; pointer-events:auto; animation: slideFromRight 0.55s cubic-bezier(0.25,0.46,0.45,0.94); }
@keyframes slideFromRight { from { transform: translateX(100%); opacity:0; } to { transform: translateX(0); opacity:1; } }
.slider-slide img { width:100%; height:auto; display:block; border-radius:12px; object-fit:cover; max-height:220px; }
.slider-slide iframe, .slider-slide video { width:100%; height:200px; display:block; border:none; border-radius:12px; }
.slider-dot { width:8px; height:8px; border-radius:50%; background:rgba(255,255,255,0.5); cursor:pointer; transition:0.3s; }
.slider-dot.active { background:#fff; transform:scale(1.3); }

/* HISTORY ALL USER */
.all-user-history-card { background:white; border-radius:12px; padding:13px; margin-bottom:8px; box-shadow:0 2px 5px rgba(0,0,0,0.04); display:flex; align-items:center; gap:10px; }
.all-user-history-card .ahu-avatar { width:38px; height:38px; border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid #e2e8f0; }
.all-user-history-card .ahu-icon { width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
.ahu-order { background:#dbeafe; }
.ahu-deposit { background:#dcfce7; }
.ahu-newuser { background:#fef3c7; }
#main-app-wrapper { display: none; }
.swal-details-box { background: #f8f9fa; padding: 15px; border-radius: 10px; font-size: 13px; color: #555; margin-top: 10px; }
.swal-row { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-bottom: 5px; }
.swal-label { font-weight: 600; color: #333; }
.swal-val { font-weight: 500; text-align: right; }
.swal-status { color: #f6921e; font-weight: 700; text-align: right; }
.api-status-badge { font-size: 11px; padding: 4px 8px; border-radius: 6px; background: #e2e8f0; color: #64748b; white-space: nowrap; font-weight: 600; }
.api-status-badge.connected { background: #dcfce7; color: #166534; }
.api-status-badge.error { background: #fee2e2; color: #991b1b; }
#serviceSelect:disabled { background-color: #e9ecef; cursor: not-allowed; opacity: 0.7; }

@media (max-width: 576px) {
    .navbar-brand { font-size: 18px; }
    .nav-logo-img { width: 32px; height: 32px; }
    .balance-box { padding: 7px 14px; font-size: 12px; min-width: 110px; }
    .api-status-badge { font-size: 10px; padding: 3px 6px; }
}
@media (max-width: 400px) {
    .navbar-brand { font-size: 16px; }
    .nav-logo-img { width: 30px; height: 30px; }
    .balance-box { padding: 6px 12px; font-size: 11px; min-width: 100px; }
    .api-status-badge { font-size: 9px; padding: 2px 6px; }
}

/* =============================================
   ADD MONEY SECTION — Full design from Add_Money.html
   ============================================= */
#addmoney-section {
    font-family: 'Roboto', sans-serif;
    background: #fff;
    min-height: 100vh;
    padding: 0;
}

/* Inner app container for add money */
.am-app-container {
    width: 100%;
    background: #ffffff;
    position: relative;
    display: flex;
    flex-direction: column;
    overflow-x: hidden;
}

/* Add Money Pages */
.am-page { display: none; flex-direction: column; background: var(--card-bg, #fff); animation: amFadeIn 0.2s ease-in-out; }
.am-page.active { display: flex; }
@keyframes amFadeIn { from { opacity: 0; transform: scale(0.98); } to { opacity: 1; transform: scale(1); } }

.am-top-nav {
    display: flex; justify-content: space-between; align-items: center;
    padding: 15px 20px; background: #fff; border-bottom: 1px solid #f0f0f0;
}
.am-nav-icon {
    cursor: pointer; font-size: 16px; color: #888;
    width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;
    border-radius: 10px; background: #f8f9fa; border: 1px solid #eee; transition: 0.2s;
}
.am-nav-icon:hover { background: #e9ecef; }

/* Home Page Body */
.am-home-body { text-align: center; padding: 20px; flex: 1; overflow-y: auto; }

/* Logo */
.am-logo-wrapper {
    position: relative; display: inline-flex; justify-content: center; align-items: center;
    width: 96px; height: 96px; border-radius: 50%; padding: 3px;
    margin-top: 20px; margin-bottom: 15px; background: transparent; box-shadow: none;
}
.am-logo-wrapper::before {
    content: ''; position: absolute; inset: 0; border-radius: 50%;
    background: conic-gradient(from 0deg, #ffd700, #ff6b6b, #9b59b6, #00d4ff, #ff1493, #ffd700);
    animation: amRotateBorder 3s linear infinite; z-index: 1;
}
.am-logo-wrapper img {
    width: 90px; height: 90px; border-radius: 50%; object-fit: cover;
    position: relative; z-index: 2; border: none; background: transparent;
}
@keyframes amRotateBorder { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

.am-brand-title { font-size: 22px; font-weight: 700; color: #555; margin-bottom: 20px; letter-spacing: 0.5px; }

/* Action Icons */
.am-action-icons { display: flex; justify-content: center; gap: 15px; margin-bottom: 30px; }
.am-action-icons .am-icon-btn {
    width: 45px; height: 45px; border: 1px solid #f0f0f0; border-radius: 12px;
    display: flex; justify-content: center; align-items: center; font-size: 18px; color: #666;
    cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.02); text-decoration: none; transition: 0.2s;
    background: #fff;
}
.am-action-icons .am-icon-btn:hover { background: #f8f9fa; transform: translateY(-2px); }

.am-blue-btn {
    background: #0046b8; color: white; padding: 16px; border-radius: 8px;
    font-weight: 500; font-size: 15px; margin-bottom: 25px;
    border: none; width: 100%; cursor: default;
}

/* Method Row — vertical list (one below another, image style) */
.am-method-row { display: flex; flex-direction: column; gap: 12px; margin-bottom: 25px; }
.am-method-row.single-method { max-width: 100%; margin-left: 0; margin-right: 0; }
.am-card {
    width: 100%; border: 1px solid #e5e7eb; border-radius: 14px; padding: 14px 18px;
    cursor: pointer; text-align: left; transition: 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    display: flex; align-items: center; gap: 16px;
}
.am-card:hover { border-color: #d1d5db; box-shadow: 0 4px 12px rgba(0,0,0,0.08); transform: translateY(-1px); }
.am-card img { height: 48px; width: 48px; object-fit: contain; border-radius: 10px; flex-shrink: 0; }
.am-card p { font-size: 15px; color: #1e293b; font-weight: 700; margin: 0; flex: 1; }
.am-card .am-card-arrow { color: #94a3b8; font-size: 14px; flex-shrink: 0; }
.am-card-sub { font-size: 12px; color: #94a3b8; font-weight: 400; margin-top: 2px; }

/* Deposit History Section */
.am-history-section { background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; overflow: hidden; margin-top: 10px; }
.am-history-header { padding: 15px 20px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
.am-history-header h4 { font-size: 14px; font-weight: 600; color: #333; display: flex; align-items: center; gap: 6px; margin: 0; }
.am-history-header .am-filter-select { font-size: 11px; padding: 4px 10px; border-radius: 20px; border: 1px solid #ddd; background: #fff; color: #555; cursor: pointer; }
.am-history-list { max-height: 220px; overflow-y: auto; }
.am-history-item { padding: 12px 20px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; transition: 0.2s; }
.am-history-item:hover { background: #f9fafb; }
.am-history-item:last-child { border-bottom: none; }
.am-history-info { display: flex; flex-direction: column; gap: 3px; text-align: left; }
.am-history-info .am-trx-id { font-size: 12px; font-weight: 500; color: #333; }
.am-history-info .am-meta { font-size: 10px; color: #888; display: flex; gap: 8px; align-items: center; }
.am-history-info .am-method-badge { background: #eef2f7; padding: 2px 8px; border-radius: 4px; font-size: 9px; font-weight: 600; }
.am-history-info .am-time { color: #999; }
.am-history-amount { font-size: 13px; font-weight: 600; text-align: right; }
.am-status-success { color: #22c55e; }
.am-status-pending { color: #f59e0b; }
.am-status-failed  { color: #ef4444; }
.am-empty-history  { padding: 25px; text-align: center; color: #888; font-size: 12px; }
.am-empty-history i { font-size: 24px; margin-bottom: 8px; opacity: 0.5; display: block; }

/* Payment Pages */
.am-payment-body { flex: 1; padding: 20px; overflow-y: auto; padding-bottom: 100px; }
.am-invoice-box { border: 1px solid #f0f0f0; border-radius: 12px; padding: 15px; display: flex; align-items: center; gap: 15px; margin-bottom: 20px; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.01); }
.am-invoice-logo { width: 45px; height: 45px; border-radius: 50%; display: flex; justify-content: center; align-items: center; overflow: hidden; border: 2px solid #fff; box-shadow: 0 0 0 1px #eee; }
.am-invoice-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.am-invoice-text h4 { font-size: 14px; color: #111; margin-bottom: 4px; }
.am-invoice-text p { font-size: 11px; color: #888; text-transform: uppercase; margin: 0; }
.am-invoice-text span { font-size: 12px; color: #888; }

.am-yellow-note { background: #fdfaf2; color: #bca474; border: 1px solid #f5ebd5; padding: 10px; text-align: center; font-size: 12px; border-radius: 5px; margin-bottom: 20px; }
.am-invoice-box { display: flex; align-items: center; justify-content: center; padding: 15px 0 10px; margin-bottom: 15px; }

.am-instruction-card { border-radius: 15px; padding: 20px; color: white; }
.am-nagad-bg  { background: #da1b23; }
.am-bkash-bg  { background: #e2136e; }
.am-rocket-bg { background: #7B2CBF; }

.am-trx-title { text-align: center; font-size: 16px; font-weight: 500; margin-bottom: 15px; }
.am-trx-input-area { background: #fff; border-radius: 8px; padding: 12px 15px; margin-bottom: 20px; }
.am-trx-input-area input { width: 100%; border: none; background: transparent; color: #333; text-align: center; font-size: 15px; outline: none; }
.am-trx-input-area input::placeholder { color: #aaa; }

.am-inst-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
.am-inst-title { font-size: 13px; font-weight: bold; letter-spacing: 0.5px; }

.am-qr-btn { color: #ffffff !important; border: none; padding: 6px 12px; border-radius: 6px; font-size: 11px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: 0.2s; box-shadow: 0 2px 6px rgba(0,0,0,0.25); }
.am-qr-btn:active { transform: scale(0.95); }
.am-qr-btn.am-bkash  { background: #e2136e !important; }
.am-qr-btn.am-nagad  { background: #c4121b !important; }
.am-qr-btn.am-rocket { background: #5e179e !important; }

.am-inst-list { list-style: none; font-size: 13px; line-height: 1.6; padding-left: 0; }
.am-inst-list li { position: relative; padding-left: 15px; margin-bottom: 15px; }
.am-inst-list li::before { content: "•"; position: absolute; left: 0; top: 0; font-size: 18px; line-height: 1.4; }

.am-copy-box { background: rgba(0,0,0,0.25); border-radius: 6px; padding: 8px 12px; display: flex; justify-content: space-between; align-items: center; margin: 10px 0; }
.am-copy-box span { font-size: 13px; }
.am-copy-box b { font-size: 15px; margin-left: 5px; }
.am-copy-btn { background: #222; color: #fff; border: none; padding: 6px 10px; border-radius: 5px; font-size: 11px; cursor: pointer; display: flex; align-items: center; gap: 5px; }

.am-qr-container { text-align: center; margin: 15px 0; padding: 15px; background: rgba(255,255,255,0.3); border: 1px solid rgba(255,255,255,0.5); border-radius: 10px; display: none; animation: amFadeIn 0.3s ease; backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px); }
.am-qr-container.show { display: block; animation: amFadeIn 0.3s ease; }
.am-qr-container img { max-width: 180px; height: auto; border-radius: 8px; background: #fff; padding: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.am-qr-container p { font-size: 11px; margin-top: 10px; opacity: 0.9; }

.am-bottom-fixed { position: fixed; bottom: 60px; left: 0; width: 100%; display: flex; justify-content: center; background: #fff; padding: 10px 20px; z-index: 10; border-top: 1px solid #f0f0f0; }
.am-bottom-fixed .am-container-inner { width: 100%; max-width: 400px; }
.am-verify-btn { width: 100%; padding: 16px; border: none; border-radius: 10px; color: white; font-weight: bold; font-size: 15px; cursor: pointer; text-transform: uppercase; transition: 0.2s; }
.am-verify-btn:active { transform: scale(0.98); }
.am-verify-btn:disabled { opacity: 0.7; cursor: not-allowed; }

/* Toast for add money */
.am-toast { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #333; color: #fff; padding: 12px 20px; border-radius: 8px; font-size: 13px; z-index: 10000; display: none; animation: amSlideDown 0.3s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.2); text-align: center; max-width: 90%; }
.am-toast.success { background: #22c55e; }
.am-toast.error   { background: #ef4444; }
@keyframes amSlideDown { from { top: -50px; opacity: 0; } to { top: 20px; opacity: 1; } }

/* Add Money Modals */
.am-modal-overlay {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 5000; justify-content: center; align-items: center;
    backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
}
.am-modal-overlay.active { display: flex; animation: amFadeIn 0.2s ease; }
.am-modal-box {
    background: #fff; width: 90%; max-width: 360px; border-radius: 16px;
    overflow: hidden; box-shadow: 0 12px 35px rgba(0,0,0,0.25); transform: scale(0.95); transition: 0.2s;
}
.am-modal-overlay.active .am-modal-box { transform: scale(1); }
.am-modal-header {
    padding: 16px 18px; background: linear-gradient(135deg, #0046b8, #003388);
    color: #fff; display: flex; justify-content: space-between; align-items: center;
}
.am-modal-header h3 { font-size: 15px; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 8px; }
.am-modal-close {
    background: rgba(255,255,255,0.2); border: none; color: #fff; width: 28px; height: 28px;
    border-radius: 50%; cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center; transition: 0.2s;
}
.am-modal-close:hover { background: rgba(255,255,255,0.35); }
.am-modal-body { padding: 18px; max-height: 65vh; overflow-y: auto; }
.am-modal-body h4 { font-size: 13px; color: #0046b8; margin: 14px 0 8px; border-bottom: 1px solid #eee; padding-bottom: 6px; }
.am-modal-body p, .am-modal-body li { font-size: 13px; color: #444; line-height: 1.65; margin: 0 0 10px; }
.am-modal-body ul { padding-left: 18px; margin: 0; }
.am-modal-body .am-badge-row { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.am-modal-body .am-info-badge { background: #f0f4ff; color: #0046b8; padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 500; }
.am-modal-body .am-contact-link {
    display: inline-flex; align-items: center; gap: 6px; background: #0046b8; color: #fff;
    padding: 9px 14px; border-radius: 8px; text-decoration: none; font-size: 12px; font-weight: 500; margin-top: 5px;
}

/* LOCATION VERIFICATION CARD */
.loc-verify-card { border-radius:16px; overflow:hidden; margin-bottom:15px; box-shadow:0 4px 15px rgba(14,165,233,0.15); }
.loc-card-header { background:linear-gradient(135deg,#0ea5e9,#2563eb); padding:14px 18px; display:flex; align-items:center; gap:10px; }
.loc-card-icon { width:38px; height:38px; background:rgba(255,255,255,0.2); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.loc-card-info { flex:1; }
.loc-card-title { color:white; font-weight:700; font-size:14px; }
.loc-card-sub { color:rgba(255,255,255,0.75); font-size:11px; margin-top:2px; }
.loc-badge-verified { background:rgba(255,255,255,0.2); color:white; font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; white-space:nowrap; }
.loc-info-box { padding:14px 16px; background:#f0fdf4; border-bottom:1px solid #dcfce7; }
.loc-info-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
.loc-info-row:last-child { margin-bottom:0; }
.loc-info-label { font-size:10px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:0.4px; }
.loc-info-value { font-size:13px; font-weight:700; color:#166534; }
.loc-verify-time { font-size:10px; color:#94a3b8; margin-top:10px; display:flex; align-items:center; gap:4px; }
.loc-btn-area { padding:14px 16px; }
.loc-verify-btn { width:100%; padding:11px; border:none; border-radius:10px; background:linear-gradient(135deg,#0ea5e9,#2563eb); color:white; font-weight:700; font-size:14px; cursor:pointer; transition:all 0.2s; letter-spacing:0.3px; }
.loc-verify-btn:active { transform:scale(0.97); opacity:0.9; }
.loc-verify-btn:disabled { opacity:0.6; cursor:not-allowed; }
body.theme-dark .loc-info-box { background:#052e16; border-bottom-color:#14532d; }
body.theme-dark .loc-info-value { color:#4ade80; }

</style>
</head>
<body>

<!-- SPLASH SCREEN -->
<div id="splash-screen">
  <img src="https://i.ibb.co/r2q5RcBg/x.jpg" class="splash-logo" alt="Logo">
  <div class="splash-title">SMMGem</div>
  <div class="loading-dots"><div class="dot"></div><div class="dot"></div><div class="dot"></div></div>
</div>

<!-- AUTH OVERLAY -->
<div id="auth-overlay" style="display:none;">
  <div class="auth-card">
    <div class="text-center">
      <img src="https://i.ibb.co/r2q5RcBg/x.jpg" class="auth-logo-img" alt="SMMGem Logo">
    </div>

    <!-- Telegram Login (auto-shown if opened via Telegram) -->
    <div id="tgLoginSection" style="display:none; margin-bottom:20px;">
      <div class="alert alert-info rounded-3 py-2 px-3 small mb-3 text-center">
        <i class="fa-brands fa-telegram me-1"></i> আপনি Telegram এর মাধ্যমে লগইন করতে পারেন
      </div>
      <button class="btn btn-primary auth-btn" id="tgLoginBtn" onclick="loginWithTelegram()"
              style="background:linear-gradient(135deg,#229ED9,#0088cc);border:none;">
        <i class="fa-brands fa-telegram me-2"></i> Telegram দিয়ে লগইন
      </button>
      <div class="switch-link">অথবা <span onclick="document.getElementById('normalLoginSection').style.display='block'; document.getElementById('tgLoginSection').style.display='none';">Email দিয়ে লগইন</span></div>
    </div>

    <!-- Normal Login/Register Section -->
    <div id="normalLoginSection">
    <!-- Login Form -->
    <form id="loginForm">
      <div class="auth-input-group">
        <label>Email Address</label>
        <input type="email" id="loginEmail" placeholder="Gmail Account" required>
        <i class="far fa-envelope icon-left"></i>
      </div>
      <div class="auth-input-group">
        <label>Password</label>
        <input type="password" id="loginPass" placeholder="Password" required>
        <i class="fas fa-lock icon-left"></i>
        <i class="far fa-eye password-toggle" onclick="togglePass('loginPass', this)"></i>
      </div>
      <div class="text-end mb-2">
        <a href="#" class="small text-decoration-none text-muted fw-bold" onclick="toggleAuth('forgot')">Forgot Password?</a>
      </div>
      <button type="submit" class="btn btn-primary auth-btn">Sign In</button>
      <div class="switch-link">New here? <span onclick="toggleAuth('signup')">Create Account</span></div>
    </form>

    <!-- Signup Form -->
    <form id="signupForm" style="display:none;">
      <div class="auth-input-group">
        <label>Full Name</label>
        <input type="text" id="regName" placeholder="Your Name" required>
        <i class="far fa-user icon-left"></i>
      </div>
      <div class="auth-input-group">
        <label>Phone Number</label>
        <input type="tel" id="regPhone" placeholder="01xxxxxxxxx" required>
        <i class="fas fa-phone icon-left"></i>
      </div>
      <div class="auth-input-group">
        <label>Email Address</label>
        <input type="email" id="regEmail" placeholder="Gmail Account" required>
        <i class="far fa-envelope icon-left"></i>
      </div>
      <div class="auth-input-group">
        <label>Password</label>
        <input type="password" id="regPass" placeholder="Password" required>
        <i class="fas fa-lock icon-left"></i>
        <i class="far fa-eye password-toggle" onclick="togglePass('regPass', this)"></i>
      </div>
      <button type="submit" class="btn btn-primary auth-btn">Create Account</button>
      <div class="switch-link">Have an account? <span onclick="toggleAuth('login')">Sign In</span></div>
    </form>

    <!-- Forgot Password Form -->
    <form id="forgotForm" style="display:none;">
      <div class="alert alert-info small rounded-3 border-0 bg-opacity-10 bg-primary text-primary fw-bold mb-4">Enter email to reset password.</div>
      <div class="auth-input-group">
        <label>Email Address</label>
        <input type="email" id="resetEmail" placeholder="Gmail Account" required>
        <i class="far fa-envelope icon-left"></i>
      </div>
      <button type="submit" class="btn btn-dark auth-btn" style="background: #333;">Send Link</button>
      <div class="switch-link">Back to <span onclick="toggleAuth('login')">Sign In</span></div>
    </form>

    <!-- Google Login Button -->
    <div style="margin-top:18px;">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
        <hr style="flex:1;border:none;border-top:1px solid #e2e8f0;">
        <span style="font-size:12px;color:#adb5bd;font-weight:500;white-space:nowrap;">অথবা</span>
        <hr style="flex:1;border:none;border-top:1px solid #e2e8f0;">
      </div>
      <button type="button" id="googleSignInBtn" onclick="triggerGoogleLogin()"
        style="width:100%;height:55px;border-radius:16px;border:1.5px solid #e2e8f0;background:#ffffff;
               display:flex;align-items:center;justify-content:center;gap:12px;
               font-size:15px;font-weight:600;color:#3c4043;cursor:pointer;
               box-shadow:0 2px 8px rgba(0,0,0,0.06);transition:all 0.2s;"
        onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,0.12)';this.style.borderColor='#c5c5c5';"
        onmouseout="this.style.boxShadow='0 2px 8px rgba(0,0,0,0.06)';this.style.borderColor='#e2e8f0';">
        <svg width="20" height="20" viewBox="0 0 48 48">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.31-8.16 2.31-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        Continue with Google
      </button>
    </div>
    </div><!-- /#normalLoginSection -->
  </div>
</div>

<!-- MAIN APP -->
<div id="main-app-wrapper" style="display:none;">
  <nav class="navbar fixed-top">
    <div class="container">
      <a class="navbar-brand" href="#" style="gap:10px;">
        <img id="navLogoImg" src="https://i.ibb.co/tGDQQgy/x.jpg"
             alt="Logo"
             style="height:54px;width:auto;max-width:240px;object-fit:contain;mix-blend-mode:screen;filter:brightness(1.1) contrast(1.05);">
      </a>
      <div onclick="showSection('profile-section')" title="প্রোফাইল"
           style="display:inline-flex;align-items:center;gap:9px;cursor:pointer;background:rgba(255,255,255,0.12);border:1.5px solid rgba(255,255,255,0.25);border-radius:50px;padding:5px 14px 5px 6px;transition:background 0.2s;flex-shrink:0;"
           onmouseenter="this.style.background='rgba(255,255,255,0.22)'" onmouseleave="this.style.background='rgba(255,255,255,0.12)'">
        <img id="navUserPhoto" src="https://cdn-icons-png.flaticon.com/512/3135/3135715.png"
             alt="Profile"
             style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.6);flex-shrink:0;">
        <span id="navUserName" style="color:#fff;font-size:13px;font-weight:600;max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">প্রোফাইল</span>
      </div>
    </div>
  </nav>

  <div class="container" style="margin-top: 68px; padding-top: 8px;">

    <!-- HOME SECTION -->
    <div id="home-section" class="app-section active">
      <!-- SLIDER / BANNER -->
      <div id="heroSliderWrapper" style="width:100%;margin-top:6px;margin-bottom:12px;border-radius:12px;overflow:hidden;position:relative;background:#000;min-height:160px;">
        <div id="heroSlider" style="position:relative;width:100%;"></div>
        <div id="sliderDots" style="position:absolute;bottom:8px;left:50%;transform:translateX(-50%);display:flex;gap:6px;z-index:10;"></div>
      </div>
      <div class="notice-board">
        <div class="notice-label"><span class="nb-icon">📢</span> NOTICE</div>
        <div class="notice-scroll"><div class="notice-scroll-inner" id="adminNotice">Welcome to SMMGem! Auto-Order System Active 🔥</div></div>
      </div>


      <!-- Balance + Add Fund — single card (image style) -->
      <div class="balance-main-card">
        <div class="bmc-left">
          <div class="bmc-label">Total Balance</div>
          <div class="bmc-value" id="homeBalanceDisplay">৳0.00</div>
        </div>
        <button class="bmc-add-btn" onclick="switchTab('addmoney', null)">+ Add Fund</button>
      </div>
      <div class="alert alert-info small rounded-3 mb-3 py-2 d-none" id="minDepositNotice" style="display:none!important;">
        <i class="fas fa-info-circle me-1"></i> Minimum deposit: <b>৳<span id="minDepositValue">10</span></b>
      </div>
      <div style="font-size:16px;font-weight:800;color:var(--text-primary,#111);margin:4px 0 2px 0;letter-spacing:-0.2px;">Select Category</div>
      <div class="category-grid" id="categoryGrid"></div>
      <div class="search-wrapper">
        <i class="fas fa-search"></i>
        <input type="text" class="form-control" id="searchInput" placeholder="Order ID or TrxID..." autocomplete="off">
        <button class="btn btn-sm btn-primary position-absolute end-0 top-50 translate-middle-y me-1 rounded-pill btn-click-effect" onclick="searchOrder()">Check</button>
      </div>
      <div class="card border-0 shadow-sm p-3 rounded-4">
        <form id="orderForm" autocomplete="off">
          <div class="mb-3">
            <label class="form-label small fw-bold">Service</label>
            <select class="form-select bg-light" id="serviceSelect" disabled>
              <option disabled selected>Choose Service</option>
            </select>
            <small class="text-muted" id="serviceHint" style="font-size:10px;">Please select a category first to view services</small>
          </div>
          <div class="mb-3" id="descContainer" style="display:none;">
            <div class="p-3 bg-light rounded border small text-secondary" id="descText" style="line-height: 1.8;"></div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold">URL/Link...</label>
            <input type="text" class="form-control bg-light" id="link" placeholder="https://smm.elite.panel.com">
          </div>
          <div class="mb-3">
            <div class="d-flex gap-2 align-items-start">
              <div style="flex:3;">
                <label class="form-label small fw-bold">Quantity</label>
                <input type="number" class="form-control bg-light" id="quantity" value="100" oninput="calcPrice()">
              </div>
              <div style="flex:2;">
                <label class="form-label small fw-bold">Charge</label>
                <input type="text" class="form-control fw-bold text-primary" id="charge" readonly placeholder="৳ 0.00" style="font-size:13px;">
              </div>
            </div>
            <span class="text-muted" style="font-size:10px;" id="minMaxInfo">Min: 0 Max: 0</span>
          </div>
          <button type="button" class="btn btn-primary w-100 rounded-4 py-2 fw-bold shadow-sm" onclick="placeOrder()">
            <i class="fas fa-cart-plus me-2"></i> Place Order
          </button>
        </form>
      </div>
    </div>

    <!-- HISTORY SECTION -->
    <div id="history-section" class="app-section">
      <h5 class="fw-bold mb-3 ps-2">📦 All History</h5>
      <div class="d-flex gap-2 overflow-auto mb-3 pb-2">
        <button class="btn btn-sm btn-dark rounded-pill px-3 filter-btn active" id="btnAll" onclick="filterHistory('All')">All</button>
        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3 filter-btn" id="btnCompleted" onclick="filterHistory('Completed')">Completed</button>
        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3 filter-btn" id="btnAllUserHistory" onclick="loadAndShowAllUserHistory()">All User History</button>
      </div>
      <div id="historyList">
        <div class="text-center text-muted mt-5"><i class="fas fa-spinner fa-spin fa-2x mb-3"></i><p>Loading History...</p></div>
      </div>
    </div>

    <!-- ADD MONEY SECTION — Full Add_Money.html Design -->
    <div id="addmoney-section" class="app-section">
      <div class="am-app-container">

        <!-- AM Toast -->
        <div id="amToast" class="am-toast"></div>

        <!-- Home Page (Method Selection) -->
        <div id="amHomePage" class="am-page active">
          <div class="am-top-nav">
            <div class="am-nav-icon"><i class="fa-solid fa-house"></i></div>
            <div class="am-nav-icon" onclick="amShowPage('amHomePage')"><i class="fa-solid fa-xmark"></i></div>
          </div>
          <div class="am-home-body">
            <div class="am-logo-wrapper">
              <img src="https://i.ibb.co/r2q5RcBg/x.jpg" alt="SMM Elite Logo">
            </div>
            <div class="am-brand-title">SMMGem</div>

            <div class="am-action-icons">
              <a href="https://t.me/BB_Bot_Creator" target="_blank" class="am-icon-btn" title="সহায়তা"><i class="fa-solid fa-headset"></i></a>
              <a href="https://t.me/+XbUNOnl2n2ZjYzZl" target="_blank" class="am-icon-btn" title="পেমেন্ট তথ্য"><i class="fa-solid fa-circle-info"></i></a>
              <a href="https://t.me/BLUEFREENET" target="_blank" class="am-icon-btn" title="সার্ভিসসমূহ"><i class="fa-solid fa-bars-staggered"></i></a>
            </div>

            <button class="am-blue-btn">Mobile Banking</button>

            <div class="am-method-row" id="amMethodRow">
              <!-- Dynamically filled from DB payment_methods -->
            </div>

            <div class="am-history-section">
              <div class="am-history-header">
                <h4><i class="fa-solid fa-clock-rotate-left"></i> Deposit History</h4>
                <select class="am-filter-select" id="amHistoryFilter" onchange="amRenderHistory(this.value)">
                  <option value="all">All Methods</option>
                </select>
              </div>
              <div class="am-history-list" id="amHomeHistoryList"></div>
            </div>
          </div>
        </div>

        <!-- bKash Page -->
        <div id="amBkashPage" class="am-page">
          <div class="am-top-nav">
            <div class="am-nav-icon" onclick="amShowPage('amHomePage')"><i class="fa-solid fa-arrow-left"></i></div>
            <div class="am-nav-icon" onclick="amShowPage('amHomePage')"><i class="fa-solid fa-xmark"></i></div>
          </div>
          <div class="am-payment-body">
            <div class="am-invoice-box" style="justify-content:center;padding:20px 0 10px;">
              <div class="am-invoice-logo" style="width:80px;height:80px;border-radius:16px;overflow:hidden;box-shadow:0 4px 15px rgba(0,0,0,0.15);">
                <img src="https://i.ibb.co/r2q5RcBg/x.jpg" alt="bKash" style="width:100%;height:100%;object-fit:cover;">
              </div>
            </div>
            <div class="am-yellow-note">নোটঃ টাকা পাঠানোর ৫-১০ সেকেন্ড পর ভেরিফাই করবেন।</div>
            <div class="am-instruction-card am-bkash-bg">
              <div class="am-trx-title">ট্রানজেকশন আইডি দিন</div>
              <div class="am-trx-input-area">
                <input type="text" placeholder="ট্রানজেকশন আইডি দিন" id="amBkashTrxInput" autocomplete="off">
              </div>
              <div class="am-inst-header">
                <div class="am-inst-title">INSTRUCTIONS</div>
                <button class="am-qr-btn am-bkash" onclick="amToggleQR('bkash')">
                  <i class="fa-solid fa-qrcode"></i> <span id="amBkashQrBtnText">Show QR</span>
                </button>
              </div>
              <div class="am-qr-container" id="amBkashQrContainer">
                <img id="amBkashQrImage" src="" alt="bKash QR">
                <p>📱 স্ক্যান করুন অথবা নাম্বার কপি করুন</p>
              </div>
              <ul class="am-inst-list">
                <li><b>*247#</b> ডায়াল করে আপনার <b>BKASH</b> মোবাইল মেনুতে যান অথবা <b>BKASH</b> অ্যাপে যান।</li>
                <li><b>"Send Money"</b> অপশন টি সিলেক্ট করুন।</li>
                <li>প্রাপক নম্বর হিসেবে এই নম্বর টি লিখুন:
                  <div class="am-copy-box">
                    <span>প্রাপক নম্বর: <b id="amBNum">018XXXXXXXX</b></span>
                    <button class="am-copy-btn" onclick="amCopyToClipboard('amBNum', this)"><i class="fa-regular fa-copy"></i> Copy</button>
                  </div>
                </li>
                <li>টাকার পরিমাণ: <b>আপনার পরিমাণ</b></li>
                <li>নিশ্চিত করতে এখন আপনার <b>BKASH</b> মোবাইল মেনু পিন লিখুন।</li>
                <li>সফল লেনদেনের পর আপনি একটি কনফার্মেশন <b>SMS</b> পাবেন।</li>
                <li>এখন উপরের বক্সে আপনার <b>Transaction ID</b> দিন এবং <b>VERIFY</b> করুন।</li>
              </ul>
            </div>
          </div>
          <div class="am-bottom-fixed">
            <div class="am-container-inner">
              <button class="am-verify-btn am-bkash-bg" onclick="amVerifyTransaction('bkash')">VERIFY TRANSACTION</button>
            </div>
          </div>
        </div>

        <!-- Nagad Page -->
        <div id="amNagadPage" class="am-page">
          <div class="am-top-nav">
            <div class="am-nav-icon" onclick="amShowPage('amHomePage')"><i class="fa-solid fa-arrow-left"></i></div>
            <div class="am-nav-icon" onclick="amShowPage('amHomePage')"><i class="fa-solid fa-xmark"></i></div>
          </div>
          <div class="am-payment-body">
            <div class="am-invoice-box" style="justify-content:center;padding:20px 0 10px;">
              <div class="am-invoice-logo" style="width:80px;height:80px;border-radius:16px;overflow:hidden;box-shadow:0 4px 15px rgba(0,0,0,0.15);">
                <img src="https://i.ibb.co/r2q5RcBg/x.jpg" alt="Nagad" style="width:100%;height:100%;object-fit:cover;">
              </div>
            </div>
            <div class="am-yellow-note">নোটঃ টাকা পাঠানোর ৫-১০ সেকেন্ড পর ভেরিফাই করবেন।</div>
            <div class="am-instruction-card am-nagad-bg">
              <div class="am-trx-title">ট্রানজেকশন আইডি দিন</div>
              <div class="am-trx-input-area">
                <input type="text" placeholder="ট্রানজেকশন আইডি দিন" id="amNagadTrxInput" autocomplete="off">
              </div>
              <div class="am-inst-header">
                <div class="am-inst-title">INSTRUCTIONS</div>
                <button class="am-qr-btn am-nagad" onclick="amToggleQR('nagad')">
                  <i class="fa-solid fa-qrcode"></i> <span id="amNagadQrBtnText">Show QR</span>
                </button>
              </div>
              <div class="am-qr-container" id="amNagadQrContainer">
                <img id="amNagadQrImage" src="" alt="Nagad QR">
                <p>📱 স্ক্যান করুন অথবা নাম্বার কপি করুন</p>
              </div>
              <ul class="am-inst-list">
                <li><b>*167#</b> ডায়াল করে আপনার <b>NAGAD</b> মোবাইল মেনুতে যান অথবা <b>NAGAD</b> অ্যাপে যান।</li>
                <li><b>"Send Money"</b> অপশন টি সিলেক্ট করুন।</li>
                <li>প্রাপক নম্বর হিসেবে এই নম্বর টি লিখুন:
                  <div class="am-copy-box">
                    <span>প্রাপক নম্বর: <b id="amNNum">018XXXXXXXX</b></span>
                    <button class="am-copy-btn" onclick="amCopyToClipboard('amNNum', this)"><i class="fa-regular fa-copy"></i> Copy</button>
                  </div>
                </li>
                <li>টাকার পরিমাণ: <b>আপনার পরিমাণ</b></li>
                <li>নিশ্চিত করতে এখন আপনার <b>NAGAD</b> মোবাইল মেনু পিন লিখুন।</li>
                <li>সফল লেনদেনের পর আপনি একটি কনফার্মেশন <b>SMS</b> পাবেন।</li>
                <li>এখন উপরের বক্সে আপনার <b>Transaction ID</b> দিন এবং <b>VERIFY</b> করুন।</li>
              </ul>
            </div>
          </div>
          <div class="am-bottom-fixed">
            <div class="am-container-inner">
              <button class="am-verify-btn am-nagad-bg" onclick="amVerifyTransaction('nagad')">VERIFY TRANSACTION</button>
            </div>
          </div>
        </div>

        <!-- Rocket Page -->
        <div id="amRocketPage" class="am-page">
          <div class="am-top-nav">
            <div class="am-nav-icon" onclick="amShowPage('amHomePage')"><i class="fa-solid fa-arrow-left"></i></div>
            <div class="am-nav-icon" onclick="amShowPage('amHomePage')"><i class="fa-solid fa-xmark"></i></div>
          </div>
          <div class="am-payment-body">
            <div class="am-invoice-box" style="justify-content:center;padding:20px 0 10px;">
              <div class="am-invoice-logo" style="width:80px;height:80px;border-radius:16px;overflow:hidden;box-shadow:0 4px 15px rgba(0,0,0,0.15);">
                <img src="https://i.ibb.co/r2q5RcBg/x.jpg" alt="Rocket" style="width:100%;height:100%;object-fit:cover;">
              </div>
            </div>
            <div class="am-yellow-note">নোটঃ টাকা পাঠানোর ৫-১০ সেকেন্ড পর ভেরিফাই করবেন।</div>
            <div class="am-instruction-card am-rocket-bg">
              <div class="am-trx-title">ট্রানজেকশন আইডি দিন</div>
              <div class="am-trx-input-area">
                <input type="text" placeholder="ট্রানজেকশন আইডি দিন" id="amRocketTrxInput" autocomplete="off">
              </div>
              <div class="am-inst-header">
                <div class="am-inst-title">INSTRUCTIONS</div>
                <button class="am-qr-btn am-rocket" onclick="amToggleQR('rocket')">
                  <i class="fa-solid fa-qrcode"></i> <span id="amRocketQrBtnText">Show QR</span>
                </button>
              </div>
              <div class="am-qr-container" id="amRocketQrContainer">
                <img id="amRocketQrImage" src="" alt="Rocket QR">
                <p>📱 স্ক্যান করুন অথবা নাম্বার কপি করুন</p>
              </div>
              <ul class="am-inst-list">
                <li><b>*322#</b> ডায়াল করে আপনার <b>ROCKET</b> মোবাইল মেনুতে যান অথবা <b>ROCKET</b> অ্যাপে যান।</li>
                <li><b>"Send Money"</b> অপশন টি সিলেক্ট করুন।</li>
                <li>প্রাপক নম্বর হিসেবে এই নম্বর টি লিখুন:
                  <div class="am-copy-box">
                    <span>প্রাপক নম্বর: <b id="amRNum">018XXXXXXXX</b></span>
                    <button class="am-copy-btn" onclick="amCopyToClipboard('amRNum', this)"><i class="fa-regular fa-copy"></i> Copy</button>
                  </div>
                </li>
                <li>টাকার পরিমাণ: <b>আপনার পরিমাণ</b></li>
                <li>নিশ্চিত করতে এখন আপনার <b>ROCKET</b> মোবাইল মেনু পিন লিখুন।</li>
                <li>সফল লেনদেনের পর আপনি একটি কনফার্মেশন <b>SMS</b> পাবেন।</li>
                <li>এখন উপরের বক্সে আপনার <b>Transaction ID</b> দিন এবং <b>VERIFY</b> করুন।</li>
              </ul>
            </div>
          </div>
          <div class="am-bottom-fixed">
            <div class="am-container-inner">
              <button class="am-verify-btn am-rocket-bg" onclick="amVerifyTransaction('rocket')">VERIFY TRANSACTION</button>
            </div>
          </div>
        </div>

      </div><!-- /.am-app-container -->

      <!-- Add Money Modals -->
      <div id="amHelpModal" class="am-modal-overlay" onclick="if(event.target===this) amCloseModal('amHelpModal')">
        <div class="am-modal-box">
          <div class="am-modal-header">
            <h3><i class="fa-solid fa-headset"></i> সহায়তা ও যোগাযোগ</h3>
            <button class="am-modal-close" onclick="amCloseModal('amHelpModal')">&times;</button>
          </div>
          <div class="am-modal-body">
            <h4>📞 সাপোর্ট চ্যানেল</h4>
            <p>যেকোনো সমস্যায় আমাদের অফিসিয়াল টেলিগ্রামে মেসেজ দিন। দ্রুত রেসপন্স পেতে নিচের লিংকে ক্লিক করুন:</p>
            <a href="https://t.me/BB_Bot_Creator" target="_blank" class="am-contact-link"><i class="fa-brands fa-telegram"></i> Telegram Support</a>
            <h4>⏰ সাপোর্ট সময়</h4>
            <p>সকাল ৯:০০ AM – রাত ১২:০০ AM (প্রতিদিন)</p>
            <h4>🛠️ দ্রুত সমাধান</h4>
            <ul>
              <li>ডিপোজিট পেন্ডিং থাকলে ৫ মিনিট অপেক্ষা করুন</li>
              <li>সঠিক TrxID দিয়েছেন কিনা চেক করুন</li>
              <li>ইন্টারনেট কানেকশন স্থিতিশীল আছে কিনা দেখুন</li>
              <li>পেজ রিফ্রেশ বা ক্যাশ ক্লিয়ার করে আবার চেষ্টা করুন</li>
            </ul>
          </div>
        </div>
      </div>

      <div id="amInfoModal" class="am-modal-overlay" onclick="if(event.target===this) amCloseModal('amInfoModal')">
        <div class="am-modal-box">
          <div class="am-modal-header">
            <h3><i class="fa-solid fa-circle-info"></i> পেমেন্ট ও ডিপোজিট তথ্য</h3>
            <button class="am-modal-close" onclick="amCloseModal('amInfoModal')">&times;</button>
          </div>
          <div class="am-modal-body">
            <h4>💰 ডিপোজিট নিয়মাবলী</h4>
            <div class="am-badge-row">
              <span class="am-info-badge">ন্যূনতম: ৫০৳</span>
              <span class="am-info-badge">প্রসেসিং: ১-৫ মিনিট</span>
              <span class="am-info-badge">অটো ভেরিফিকেশন</span>
            </div>
            <h4>📱 সমর্থিত পেমেন্ট মাধ্যম</h4>
            <ul>
              <li>বিকাশ (পার্সোনাল / এজেন্ট)</li>
              <li>নগদ (পার্সোনাল)</li>
              <li>রকেট (পার্সোনাল)</li>
            </ul>
            <h4>🔒 নিরাপত্তা ও নোট</h4>
            <ul>
              <li>টাকা পাঠানোর ৫-১০ সেকেন্ড পর VERIFY করুন</li>
              <li>TrxID তে ছোট হাতের অক্ষর বা স্পেশাল ক্যারেক্টার ব্যবহার করবেন না</li>
              <li>ভুল নম্বরে টাকা পাঠালে দায়ী থাকবেন না</li>
              <li>সব লেনদেন ম্যানুয়ালি চেক করা হয় ও এনক্রিপ্টেড</li>
            </ul>
          </div>
        </div>
      </div>

      <div id="amServicesModal" class="am-modal-overlay" onclick="if(event.target===this) amCloseModal('amServicesModal')">
        <div class="am-modal-box">
          <div class="am-modal-header">
            <h3><i class="fa-solid fa-bars-staggered"></i> আমাদের সার্ভিসসমূহ</h3>
            <button class="am-modal-close" onclick="amCloseModal('amServicesModal')">&times;</button>
          </div>
          <div class="am-modal-body">
            <h4>📱 সোশ্যাল মিডিয়া সার্ভিস</h4>
            <div class="am-badge-row">
              <span class="am-info-badge">Facebook</span>
              <span class="am-info-badge">Instagram</span>
              <span class="am-info-badge">YouTube</span>
              <span class="am-info-badge">TikTok</span>
              <span class="am-info-badge">Telegram</span>
              <span class="am-info-badge">Twitter/X</span>
            </div>
            <ul>
              <li>লাইক, ফলোয়ার, সাবস্ক্রাইবার, ভিউ, শেয়ার</li>
              <li>কমেন্ট, সেভ, রিচ, ইমপ্রেশন, ওয়াচটাইম</li>
              <li>১০০% রিয়েল ও অটো ডেলিভারি সার্ভার</li>
            </ul>
            <h4>🛒 প্যানেল ফিচার</h4>
            <ul>
              <li>API অ্যাক্সেস ও রেজেলার প্যানেল</li>
              <li>বাল্ক অর্ডার ও কাস্টম প্যাকেজ</li>
              <li>অটো রিফিল ও রিপ্লেস গ্যারান্টি</li>
              <li>২৪/৭ অটো অর্ডার প্রসেসিং</li>
            </ul>
            <p style="font-size:12px;color:#888;margin-top:10px;">* নির্দিষ্ট সার্ভিসের রেট ও স্ট্যাটাস ড্যাশবোর্ডে চেক করুন।</p>
          </div>
        </div>
      </div>

    </div><!-- /#addmoney-section -->

    <!-- REFERRAL SECTION -->
    <div id="referral-section" class="app-section">
      <!-- Referral Link Box (old.php style) -->
      <div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:16px;padding:20px;margin-bottom:20px;color:white;box-shadow:0 10px 25px rgba(102,126,234,0.3);">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:15px;">
          <div style="width:40px;height:40px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;"><i class="fas fa-link"></i></div>
          <div>
            <div style="font-weight:700;font-size:16px;">আপনার রেফারেল লিংক</div>
            <div style="font-size:12px;opacity:0.8;">বন্ধুদের শেয়ার করুন ও বোনাস পান!</div>
          </div>
        </div>
        <div style="background:rgba(255,255,255,0.15);border-radius:10px;padding:12px 15px;margin-bottom:12px;word-break:break-all;font-size:13px;font-family:monospace;border:1px dashed rgba(255,255,255,0.4);" id="refLinkBox">লোড হচ্ছে...</div>
        <button onclick="copyRefLink()" style="width:100%;padding:12px;background:white;color:#667eea;border:none;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:0.2s;" onmouseover="this.style.background='#f0f4ff'" onmouseout="this.style.background='white'">
          <i class="fas fa-copy"></i> লিংক কপি করুন
        </button>
      </div>

      <!-- Referral Stats Cards -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
        <div style="background:white;border-radius:14px;padding:15px;box-shadow:0 4px 12px rgba(0,0,0,0.06);border-left:4px solid #667eea;text-align:center;">
          <div style="font-size:28px;font-weight:800;color:#667eea;" id="refStatCount">0</div>
          <div style="font-size:12px;color:#64748b;font-weight:600;">মোট রেফার</div>
        </div>
        <div style="background:white;border-radius:14px;padding:15px;box-shadow:0 4px 12px rgba(0,0,0,0.06);border-left:4px solid #22c55e;text-align:center;">
          <div style="font-size:28px;font-weight:800;color:#22c55e;" id="refStatBonus">৳0</div>
          <div style="font-size:12px;color:#64748b;font-weight:600;">মোট রেফার বোনাস</div>
        </div>
        <div style="background:white;border-radius:14px;padding:15px;box-shadow:0 4px 12px rgba(0,0,0,0.06);border-left:4px solid #f59e0b;text-align:center;grid-column:span 2;">
          <div style="font-size:28px;font-weight:800;color:#f59e0b;" id="refStatDeposits">৳0</div>
          <div style="font-size:12px;color:#64748b;font-weight:600;">রেফার করা ইউজারদের মোট ডিপোজিট</div>
        </div>
      </div>

      <!-- Bonus Info -->
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
        <div style="width:36px;height:36px;background:#22c55e;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-coins" style="color:white;font-size:16px;"></i></div>
        <div>
          <div style="font-weight:700;font-size:14px;color:#166534;">প্রতি রেফারে বোনাস</div>
          <div style="font-size:13px;color:#15803d;font-weight:600;" id="refBonusPerRef">৳5 বোনাস</div>
        </div>
      </div>

      <!-- Referred Users List -->
      <div style="background:white;border-radius:14px;padding:18px;box-shadow:0 4px 12px rgba(0,0,0,0.06);">
        <div style="font-weight:700;font-size:15px;color:#1e293b;margin-bottom:15px;display:flex;align-items:center;gap:8px;">
          <i class="fas fa-users" style="color:#667eea;"></i> রেফার করা ইউজারগণ
        </div>
        <div id="refUsersList">
          <div style="text-align:center;padding:30px;color:#94a3b8;">
            <i class="fas fa-spinner fa-spin fa-2x" style="margin-bottom:10px;display:block;"></i>
            লোড হচ্ছে...
          </div>
        </div>
      </div>
    </div>

    <!-- PROFILE SECTION -->
    <div id="profile-section" class="app-section">
      <div class="profile-header">
        <div class="profile-img-container">
          <img id="displayProfilePic" src="https://cdn-icons-png.flaticon.com/512/3135/3135715.png" class="profile-img">
          <label for="updatePicInput" class="cam-btn"><i class="fas fa-camera small"></i></label>
          <input type="file" id="updatePicInput" hidden accept="image/*" onchange="previewUpdateImage(this)">
        </div>
        <h5 class="fw-bold m-0" id="profileDisplayName">User Name</h5>
        <span class="badge bg-primary rounded-pill mt-1" id="profileBadgePhone">01xxxxxxxxx</span>
      </div>
      <div class="profile-info-card">
        <div class="info-row">
          <div class="info-label">Full Name (Editable)</div>
          <div class="info-value"><input type="text" id="editName" value="User Name"><i class="fas fa-pen text-secondary small"></i></div>
        </div>
<div class="info-row">
          <div class="info-label">User ID</div>
          <div class="info-value text-secondary font-monospace small" id="editUid" onclick="copyUid(this.innerText)">UID_12345 <i class="far fa-copy ms-2"></i></div>
        </div>
        <div class="info-row">
          <div class="info-label">Joined On</div>
          <div class="info-value text-secondary" id="editJoinDate">01 Jan 2024</div>
        </div>
      </div>
      <!-- PROFILE STATS CARDS -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
        <div style="background:linear-gradient(135deg,#667eea,#764ba2);border-radius:14px;padding:14px;text-align:center;color:white;box-shadow:0 4px 12px rgba(102,126,234,0.3);">
          <div style="font-size:11px;opacity:0.85;margin-bottom:4px;font-weight:600;letter-spacing:0.4px;">মোট অর্ডার</div>
          <div style="font-size:26px;font-weight:800;" id="profileTotalOrders">0</div>
          <div style="font-size:10px;opacity:0.7;margin-top:2px;"><i class="fas fa-shopping-cart"></i> Orders</div>
        </div>
        <div style="background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:14px;padding:14px;text-align:center;color:white;box-shadow:0 4px 12px rgba(34,197,94,0.3);">
          <div style="font-size:11px;opacity:0.85;margin-bottom:4px;font-weight:600;letter-spacing:0.4px;">মোট ডিপোজিট</div>
          <div style="font-size:22px;font-weight:800;" id="profileTotalDeposit">৳0.00</div>
          <div style="font-size:10px;opacity:0.7;margin-top:2px;"><i class="fas fa-wallet"></i> Deposit</div>
        </div>
      </div>

      <!-- LOCATION VERIFICATION CARD REMOVED -->

      <!-- SETTINGS CARD -->
      <div class="settings-card mb-3">
        <div class="settings-title"><i class="fas fa-palette text-primary"></i> থিম / অ্যাপিয়ারেন্স</div>
        <div class="theme-grid" id="themeGrid">
          <button class="theme-btn active" data-theme="light" style="background:linear-gradient(135deg,#f8f9fa,#e2e8f0);" onclick="setTheme('light')"><div class="theme-btn-label" style="color:#333;">☀️<br>Light</div></button>
          <button class="theme-btn" data-theme="dark" style="background:linear-gradient(135deg,#0f0f1a,#1a1a2e);" onclick="setTheme('dark')"><div class="theme-btn-label">🌙<br>Dark</div></button>
          <button class="theme-btn" data-theme="purple" style="background:linear-gradient(135deg,#1a0530,#5e0b9e);" onclick="setTheme('purple')"><div class="theme-btn-label">💜<br>Purple</div></button>
          <button class="theme-btn" data-theme="blue" style="background:linear-gradient(135deg,#021a3a,#0d4a9b);" onclick="setTheme('blue')"><div class="theme-btn-label">💙<br>Blue</div></button>
          <button class="theme-btn" data-theme="green" style="background:linear-gradient(135deg,#031a0e,#0d5c2a);" onclick="setTheme('green')"><div class="theme-btn-label">💚<br>Green</div></button>
        </div>
      </div>

      <div class="settings-card mb-3">
        <div class="settings-title"><i class="fas fa-sliders-h text-secondary"></i> সেটিংস</div>
        <div class="settings-row">
          <div class="settings-row-info">
            <span class="settings-row-icon">🎵</span>
            <span class="settings-row-label">সাউন্ড ইফেক্ট</span>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" id="soundToggle" checked onchange="toggleSound(this.checked)">
            <span class="toggle-slider"></span>
          </label>
        </div>
        <div class="settings-row">
          <div class="settings-row-info">
            <span class="settings-row-icon">📳</span>
            <span class="settings-row-label">ভাইব্রেশন (Haptic)</span>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" id="vibrationToggle" checked onchange="toggleVibration(this.checked)">
            <span class="toggle-slider"></span>
          </label>
        </div>
      </div>

      <div class="d-grid gap-2">
        <a href="https://t.me/xadminbd" target="_blank" class="btn py-2 fw-bold shadow-sm" style="background:linear-gradient(135deg,#0ea5e9,#2563eb);color:white;border:none;border-radius:12px;text-decoration:none;display:block;text-align:center;">🛒 Web Developer</a>
        <button class="btn py-2 fw-bold shadow-sm" style="background:linear-gradient(135deg,#f59e0b,#ef4444);color:white;border:none;" onclick="openLeaderboard()"><i class="fas fa-trophy me-2"></i> লিডার বোর্ড</button>
        <button class="btn py-2 fw-bold" style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;border:none;" onclick="openApiPage()"><i class="fas fa-plug me-2"></i> API</button>
        <button class="btn btn-danger py-2 fw-bold mt-2" onclick="logoutUser()"><i class="fas fa-sign-out-alt me-2"></i> Logout</button>
      </div>
    </div>

    <!-- API SECTION -->
    <div id="api-section" class="app-section">
      <!-- API Header -->
      <div style="background:linear-gradient(135deg,#1a1a2e,#16213e,#0f3460);border-radius:0 0 24px 24px;padding:24px 20px 28px;margin:-0px -12px 20px;color:white;position:relative;overflow:hidden;">
        <div style="position:absolute;top:-30px;right:-30px;width:120px;height:120px;background:rgba(255,255,255,0.05);border-radius:50%;"></div>
        <div style="position:absolute;bottom:-20px;left:-20px;width:80px;height:80px;background:rgba(255,255,255,0.04);border-radius:50%;"></div>
        <button onclick="switchTab('profile', document.querySelector('.nav-item:nth-child(4)'))" style="background:rgba(255,255,255,0.15);border:none;color:white;width:36px;height:36px;border-radius:50%;margin-bottom:12px;cursor:pointer;"><i class="fas fa-arrow-left"></i></button>
        <div style="display:flex;align-items:center;gap:12px;">
          <div style="width:48px;height:48px;background:linear-gradient(135deg,#667eea,#764ba2);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 4px 15px rgba(102,126,234,0.4);">🔌</div>
          <div>
            <div style="font-weight:800;font-size:20px;letter-spacing:0.5px;">API Access</div>
            <div style="font-size:12px;opacity:0.7;margin-top:2px;">Integrate SMMGem into your app</div>
          </div>
        </div>
      </div>

      <!-- API Key Card -->
      <div style="background:linear-gradient(135deg,#667eea,#764ba2);border-radius:18px;padding:20px;margin-bottom:16px;box-shadow:0 8px 25px rgba(102,126,234,0.35);position:relative;overflow:hidden;">
        <div style="position:absolute;top:-20px;right:-20px;width:100px;height:100px;background:rgba(255,255,255,0.08);border-radius:50%;"></div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
          <div style="width:32px;height:32px;background:rgba(255,255,255,0.2);border-radius:8px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-key" style="color:white;font-size:14px;"></i></div>
          <span style="color:white;font-weight:700;font-size:14px;">Your API Key</span>
          <span style="background:rgba(255,255,255,0.25);color:white;font-size:10px;padding:2px 8px;border-radius:20px;font-weight:600;margin-left:auto;">PRIVATE</span>
        </div>
        <div style="background:rgba(0,0,0,0.25);border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px;margin-bottom:14px;border:1px solid rgba(255,255,255,0.15);">
          <code style="flex:1;color:#e2e8f0;font-size:11px;word-break:break-all;font-family:monospace;line-height:1.5;" id="apiKeyDisplay">Loading...</code>
          <button onclick="copyApiKey()" style="background:rgba(255,255,255,0.2);border:none;color:white;width:34px;height:34px;border-radius:8px;cursor:pointer;flex-shrink:0;transition:0.2s;" title="Copy"><i class="fas fa-copy" style="font-size:13px;"></i></button>
        </div>
        <div style="display:flex;gap:8px;">
          <button onclick="loadApiKey()" style="flex:1;background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.3);color:white;padding:9px;border-radius:10px;font-weight:600;font-size:12px;cursor:pointer;transition:0.2s;"><i class="fas fa-sync me-1"></i> Refresh</button>
          <button onclick="regenerateApiKey()" style="flex:1;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.25);color:white;padding:9px;border-radius:10px;font-weight:600;font-size:12px;cursor:pointer;transition:0.2s;"><i class="fas fa-redo me-1"></i> Regenerate</button>
        </div>
      </div>

      <!-- Base URL -->
      <div style="background:white;border-radius:14px;padding:15px;margin-bottom:16px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e2e8f0;">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
          <div style="width:8px;height:8px;background:#22c55e;border-radius:50%;"></div>
          <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Base URL</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <code style="flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:11px;color:#334155;word-break:break-all;" id="apiBaseUrl">Loading...</code>
          <button onclick="copyBaseUrl()" style="background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b;width:34px;height:34px;border-radius:8px;cursor:pointer;flex-shrink:0;"><i class="fas fa-copy" style="font-size:12px;"></i></button>
        </div>
      </div>

      <!-- Endpoints Card -->
      <div style="background:white;border-radius:18px;padding:18px;margin-bottom:16px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e2e8f0;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
          <div style="width:32px;height:32px;background:#dbeafe;border-radius:8px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-list-ul" style="color:#3b82f6;font-size:13px;"></i></div>
          <span style="font-weight:700;font-size:15px;color:#1e293b;">API Endpoints</span>
        </div>

        <!-- Services -->
        <div style="border:1px solid #e2e8f0;border-radius:12px;margin-bottom:10px;overflow:hidden;">
          <div style="background:#f8fafc;padding:10px 14px;display:flex;align-items:center;gap:8px;">
            <span style="background:#22c55e;color:white;font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px;">GET</span>
            <span style="background:#3b82f6;color:white;font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px;">POST</span>
            <code style="flex:1;font-size:11px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" id="ep1">?api=1&key=KEY&action=services</code>
            <button onclick="copyEndpoint('ep1')" style="background:#e2e8f0;border:none;color:#64748b;width:28px;height:28px;border-radius:6px;cursor:pointer;flex-shrink:0;"><i class="fas fa-copy" style="font-size:10px;"></i></button>
          </div>
          <div style="padding:8px 14px;font-size:12px;color:#64748b;">📋 সকল সার্ভিসের তালিকা দেখতে</div>
        </div>

        <!-- Balance -->
        <div style="border:1px solid #e2e8f0;border-radius:12px;margin-bottom:10px;overflow:hidden;">
          <div style="background:#f8fafc;padding:10px 14px;display:flex;align-items:center;gap:8px;">
            <span style="background:#22c55e;color:white;font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px;">GET</span>
            <span style="background:#3b82f6;color:white;font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px;">POST</span>
            <code style="flex:1;font-size:11px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" id="ep2">?api=1&key=KEY&action=balance</code>
            <button onclick="copyEndpoint('ep2')" style="background:#e2e8f0;border:none;color:#64748b;width:28px;height:28px;border-radius:6px;cursor:pointer;flex-shrink:0;"><i class="fas fa-copy" style="font-size:10px;"></i></button>
          </div>
          <div style="padding:8px 14px;font-size:12px;color:#64748b;">💰 বর্তমান অ্যাকাউন্ট ব্যালেন্স দেখতে</div>
        </div>

        <!-- Add Order -->
        <div style="border:1px solid #e2e8f0;border-radius:12px;margin-bottom:10px;overflow:hidden;">
          <div style="background:#f8fafc;padding:10px 14px;display:flex;align-items:center;gap:8px;">
            <span style="background:#22c55e;color:white;font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px;">GET</span>
            <span style="background:#3b82f6;color:white;font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px;">POST</span>
            <code style="flex:1;font-size:11px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" id="ep3">?api=1&key=KEY&action=add</code>
            <button onclick="copyEndpoint('ep3')" style="background:#e2e8f0;border:none;color:#64748b;width:28px;height:28px;border-radius:6px;cursor:pointer;flex-shrink:0;"><i class="fas fa-copy" style="font-size:10px;"></i></button>
          </div>
          <div style="padding:8px 14px;font-size:12px;color:#64748b;">
            🛒 অর্ডার করতে (ব্যালেন্স কাটা হবে)
            <div style="margin-top:5px;display:flex;gap:5px;flex-wrap:wrap;">
              <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:600;">service=ID</span>
              <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:600;">link=URL</span>
              <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:600;">quantity=N</span>
            </div>
          </div>
        </div>

        <!-- Order Status -->
        <div style="border:1px solid #e2e8f0;border-radius:12px;margin-bottom:10px;overflow:hidden;">
          <div style="background:#f8fafc;padding:10px 14px;display:flex;align-items:center;gap:8px;">
            <span style="background:#22c55e;color:white;font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px;">GET</span>
            <span style="background:#3b82f6;color:white;font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px;">POST</span>
            <code style="flex:1;font-size:11px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" id="ep4">?api=1&key=KEY&action=status</code>
            <button onclick="copyEndpoint('ep4')" style="background:#e2e8f0;border:none;color:#64748b;width:28px;height:28px;border-radius:6px;cursor:pointer;flex-shrink:0;"><i class="fas fa-copy" style="font-size:10px;"></i></button>
          </div>
          <div style="padding:8px 14px;font-size:12px;color:#64748b;">
            🔍 অর্ডার স্ট্যাটাস দেখতে
            <div style="margin-top:5px;"><span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:600;">order=ORDER_ID</span></div>
          </div>
        </div>

        <!-- Orders List -->
        <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
          <div style="background:#f8fafc;padding:10px 14px;display:flex;align-items:center;gap:8px;">
            <span style="background:#22c55e;color:white;font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px;">GET</span>
            <span style="background:#3b82f6;color:white;font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px;">POST</span>
            <code style="flex:1;font-size:11px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" id="ep5">?api=1&key=KEY&action=orders</code>
            <button onclick="copyEndpoint('ep5')" style="background:#e2e8f0;border:none;color:#64748b;width:28px;height:28px;border-radius:6px;cursor:pointer;flex-shrink:0;"><i class="fas fa-copy" style="font-size:10px;"></i></button>
          </div>
          <div style="padding:8px 14px;font-size:12px;color:#64748b;">📦 সব অর্ডারের তালিকা দেখতে (সর্বশেষ ৫০টি)</div>
        </div>
      </div>

      <!-- Code Examples -->
      <div style="background:white;border-radius:18px;padding:18px;margin-bottom:16px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e2e8f0;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
          <div style="width:32px;height:32px;background:#fef3c7;border-radius:8px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-code" style="color:#f59e0b;font-size:13px;"></i></div>
          <span style="font-weight:700;font-size:15px;color:#1e293b;">Code Examples</span>
        </div>

        <div style="margin-bottom:14px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <div style="display:flex;align-items:center;gap:6px;">
              <span style="width:10px;height:10px;background:#22c55e;border-radius:50%;display:inline-block;"></span>
              <span style="font-size:12px;font-weight:600;color:#475569;">GET Request (Browser / cURL)</span>
            </div>
            <button onclick="copyById('getExampleCode')" style="background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b;padding:4px 10px;border-radius:6px;font-size:10px;cursor:pointer;"><i class="fas fa-copy me-1"></i>Copy</button>
          </div>
          <div style="background:#0f172a;border-radius:10px;padding:12px 14px;overflow-x:auto;" id="getExampleCode">
            <code style="color:#4ade80;font-size:10px;font-family:monospace;white-space:nowrap;">Loading...</code>
          </div>
        </div>

        <div style="margin-bottom:14px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <div style="display:flex;align-items:center;gap:6px;">
              <span style="width:10px;height:10px;background:#3b82f6;border-radius:50%;display:inline-block;"></span>
              <span style="font-size:12px;font-weight:600;color:#475569;">POST Request (PHP)</span>
            </div>
            <button onclick="copyById('postExampleCode')" style="background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b;padding:4px 10px;border-radius:6px;font-size:10px;cursor:pointer;"><i class="fas fa-copy me-1"></i>Copy</button>
          </div>
          <div style="background:#0f172a;border-radius:10px;padding:12px 14px;overflow-x:auto;">
            <pre style="color:#67e8f9;font-size:10px;font-family:monospace;margin:0;white-space:pre;" id="postExampleCode">Loading...</pre>
          </div>
        </div>

        <div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <div style="display:flex;align-items:center;gap:6px;">
              <span style="width:10px;height:10px;background:#f59e0b;border-radius:50%;display:inline-block;"></span>
              <span style="font-size:12px;font-weight:600;color:#475569;">Successful Response</span>
            </div>
            <button onclick="copyById('successResponseCode')" style="background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b;padding:4px 10px;border-radius:6px;font-size:10px;cursor:pointer;"><i class="fas fa-copy me-1"></i>Copy</button>
          </div>
          <div style="background:#0f172a;border-radius:10px;padding:12px 14px;">
            <code style="color:#fbbf24;font-size:10px;font-family:monospace;" id="successResponseCode">{"order":12345,"status":"Pending","charge":2.50,"currency":"BDT","balance":97.50}</code>
          </div>
        </div>
      </div>

      <!-- Note -->
      <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #bfdbfe;border-radius:14px;padding:14px 16px;margin-bottom:24px;display:flex;gap:10px;align-items:flex-start;">
        <div style="width:28px;height:28px;background:#3b82f6;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;"><i class="fas fa-info" style="color:white;font-size:11px;"></i></div>
        <div style="font-size:12px;color:#1e40af;line-height:1.6;"><strong>নোট:</strong> API দিয়ে অর্ডার করলে আপনার একাউন্ট ব্যালেন্স থেকে চার্জ কাটা হবে। API key কাউকে শেয়ার করবেন না। সব request এ <code style="background:#dbeafe;padding:1px 5px;border-radius:3px;">key</code> parameter বাধ্যতামূলক।</div>
      </div>
    </div>

    <!-- TUTORIAL SECTION -->
<div id="tutorial-section" class="app-section">
  <h5 class="fw-bold mb-4 ps-1" style="color:var(--text-primary,#1e293b);">
    <i class="fas fa-play-circle me-2" style="color:#e94560;"></i> Tutorial
  </h5>
  
  <div style="background:var(--card-bg,#fff);border-radius:18px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);border:1px solid var(--border-color,#e2e8f0);margin-bottom:18px;">
    
    <div style="background:linear-gradient(135deg,#1a1a2e,#e94560);padding:14px 18px;display:flex;align-items:center;gap:10px;">
      <div style="width:36px;height:36px;background:rgba(255,255,255,0.2);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fab fa-youtube" style="color:white;font-size:18px;"></i>
      </div>
      <div>
        <div style="color:white;font-weight:700;font-size:14px;">SMMGem টিউটোরিয়াল</div>
        <div style="color:rgba(255,255,255,0.75);font-size:11px;">কিভাবে অর্ডার করবেন দেখুন</div>
      </div>
    </div>

    <div style="padding:0px; display: flex; justify-content: center; background: #0f172a; min-height: 200px; align-items: center;">
      <div id="video-wrapper" style="width: 100%; max-width: 100%; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);">
        <div id="aspect-ratio-box" style="position:relative; width:100%; padding-bottom:56.25%; height:0; overflow:hidden; background:#000;">
          <iframe
            id="tutorial-iframe"
            style="position:absolute;top:0;left:0;width:100%;height:100%;border:none;"
            src="https://www.youtube.com/embed/yXAlTjemyUU?si=n7MbGjR4cuonz3fX"
            title="YouTube video player"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowfullscreen>
          </iframe>
        </div>
      </div>
    </div>

    <div style="padding: 15px; display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; background: #f8fafc; border-top: 1px solid var(--border-color,#e2e8f0);">
      <button onclick="updateVideoSize('320px', '177.77%', this)" style="background:#1e293b; color:white; border:none; padding:8px 12px; border-radius:5px; font-size:11px; cursor:pointer; flex: 1 1 auto; min-width: 80px;">Mobile View</button>

      <button onclick="updateVideoSize('75%', '56.25%', this)" style="background:#1e293b; color:white; border:none; padding:8px 12px; border-radius:8px; font-size:11px; cursor:pointer; flex: 1 1 auto; min-width: 60px;">Medium</button>
      <button onclick="updateVideoSize('100%', '56.25%', this)" id="default-btn" style="background:#e94560; color:white; border:none; padding:8px 12px; border-radius:8px; font-size:11px; cursor:pointer; flex: 1 1 auto; min-width: 60px;">Standard</button>

    </div>
    
  </div>
</div>

<script>
  function updateVideoSize(width, padding, btn) {
    const wrapper = document.getElementById('video-wrapper');
    const aspectBox = document.getElementById('aspect-ratio-box');
    
    // সাইজ পরিবর্তন
    wrapper.style.width = width;
    aspectBox.style.paddingBottom = padding;

    // বাটন কালার অ্যাক্টিভ করা
    const buttons = btn.parentElement.querySelectorAll('button');
    buttons.forEach(b => b.style.background = '#1e293b');
    btn.style.background = '#e94560';
  }
</script>




    <div id="leaderboard-section" class="app-section">
      <!-- Header -->
      <div class="d-flex align-items-center mb-4 pt-1">
        <button class="btn btn-sm btn-light rounded-circle me-3" style="width:40px;height:40px;" onclick="switchTab('profile', document.querySelector('.nav-item:nth-child(5)'))"><i class="fas fa-arrow-left"></i></button>
        <h4 class="fw-bold mb-0 text-uppercase" style="letter-spacing:1px;">Leaderboard</h4>
      </div>

      <!-- Tab Switcher -->
      <div style="background:#f1f3f6;border-radius:14px;padding:4px;display:flex;margin-bottom:22px;gap:2px;">
        <button id="lbTabRef" onclick="switchLbTab('ref')" style="flex:1;padding:9px 4px;border:none;border-radius:10px;font-weight:700;font-size:11px;cursor:pointer;transition:all 0.2s;background:white;color:#667eea;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
          🏆 রেফারার
        </button>
        <button id="lbTabDep" onclick="switchLbTab('dep')" style="flex:1;padding:9px 4px;border:none;border-radius:10px;font-weight:700;font-size:11px;cursor:pointer;transition:all 0.2s;background:transparent;color:#94a3b8;box-shadow:none;">
          💰 ডিপোজিট
        </button>
        <button id="lbTabUsers" onclick="switchLbTab('users')" style="flex:1;padding:9px 4px;border:none;border-radius:10px;font-weight:700;font-size:11px;cursor:pointer;transition:all 0.2s;background:transparent;color:#94a3b8;box-shadow:none;">
          👥 অল ইউজার
        </button>
      </div>

      <!-- Top Referrers Panel -->
      <div id="lbPanelRef">
        <div id="topReferrersList">
          <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x" style="margin-bottom:10px;display:block;"></i>Loading...</div>
        </div>
      </div>

      <!-- Top Depositors Panel -->
      <div id="lbPanelDep" style="display:none;">
        <div id="topDepositorsList">
          <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x" style="margin-bottom:10px;display:block;"></i>Loading...</div>
        </div>
      </div>

      <!-- All Logged Users Panel -->
      <div id="lbPanelUsers" style="display:none;">
        <!-- Podium Top 3 -->
        <div id="lbTop3Podium" style="margin-bottom:20px;"></div>
        <!-- Users List -->
        <div style="background:var(--card-bg,#fff);border-radius:14px;overflow:hidden;border:1px solid var(--border-color,#e2e8f0);">
          <div style="padding:13px 16px;font-weight:700;font-size:13px;color:var(--text-secondary,#64748b);border-bottom:1px solid var(--border-color,#f0f0f0);display:flex;align-items:center;gap:6px;background:var(--input-bg,#f8f9fa);">
            <i class="fas fa-clock"></i> সর্বশেষ লগইন করা ইউজার (৫০ জন)
          </div>
          <div id="lbLoggedUsersList">
            <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x" style="margin-bottom:10px;display:block;"></i>Loading...</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================
         ALL USER LOCATION SECTION
    ============================================ -->
    <div id="alluserloc-section" class="app-section">
      <!-- Header -->
      <div style="background:linear-gradient(135deg,#0ea5e9,#2563eb);border-radius:0 0 24px 24px;padding:20px 16px 24px;margin:-0px -12px 18px;color:white;position:relative;overflow:hidden;">
        <div style="position:absolute;top:-30px;right:-30px;width:120px;height:120px;background:rgba(255,255,255,0.06);border-radius:50%;"></div>
        <div style="position:absolute;bottom:-20px;left:-20px;width:80px;height:80px;background:rgba(255,255,255,0.04);border-radius:50%;"></div>
        <button onclick="switchTab('profile', document.querySelector('.nav-item:nth-child(5)'))" style="background:rgba(255,255,255,0.18);border:none;color:white;width:36px;height:36px;border-radius:50%;margin-bottom:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;"><i class="fas fa-arrow-left"></i></button>
        <div style="display:flex;align-items:center;gap:12px;">
          <div style="width:48px;height:48px;background:rgba(255,255,255,0.2);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 4px 15px rgba(0,0,0,0.2);">🗺️</div>
          <div>
            <div style="font-weight:800;font-size:20px;letter-spacing:0.5px;">অল ইউজার লোকেশন</div>
            <div id="aulSubtitle" style="font-size:12px;opacity:0.8;margin-top:2px;">লোকেশন ভেরিফাইড ইউজার</div>
          </div>
        </div>
      </div>

      <!-- Stats Bar -->
      <div style="display:flex;gap:10px;margin-bottom:16px;">
        <div style="flex:1;background:var(--card-bg,#fff);border-radius:14px;padding:12px 16px;border:1px solid var(--border-color,#e2e8f0);text-align:center;">
          <div id="aulTotalCount" style="font-size:22px;font-weight:800;color:#0ea5e9;">—</div>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px;">মোট ভেরিফাইড</div>
        </div>
        <div style="flex:1;background:var(--card-bg,#fff);border-radius:14px;padding:12px 16px;border:1px solid var(--border-color,#e2e8f0);text-align:center;">
          <div id="aulCountryCount" style="font-size:22px;font-weight:800;color:#22c55e;">—</div>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px;">দেশ</div>
        </div>
        <div style="flex:1;background:var(--card-bg,#fff);border-radius:14px;padding:12px 16px;border:1px solid var(--border-color,#e2e8f0);text-align:center;">
          <div id="aulDistrictCount" style="font-size:22px;font-weight:800;color:#f59e0b;">—</div>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px;">জেলা</div>
        </div>
      </div>

      <!-- Map Container -->
      <div style="background:var(--card-bg,#fff);border-radius:18px;overflow:hidden;border:1px solid var(--border-color,#e2e8f0);margin-bottom:16px;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
        <div style="padding:12px 16px;border-bottom:1px solid var(--border-color,#f0f0f0);display:flex;align-items:center;justify-content:space-between;">
          <div style="font-weight:700;font-size:13px;color:var(--text-primary,#1e293b);display:flex;align-items:center;gap:6px;">
            <i class="fas fa-globe" style="color:#0ea5e9;"></i> Google Maps ভিউ
          </div>
          <button onclick="aulRefreshMap()" style="background:#f1f5f9;border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600;color:#0ea5e9;cursor:pointer;">
            <i class="fas fa-sync-alt me-1"></i> রিফ্রেশ
          </button>
        </div>
        <div id="aulMapContainer" style="height:340px;width:100%;position:relative;background:#e8f4fd;">
          <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;" id="aulMapLoading">
            <i class="fas fa-spinner fa-spin fa-2x" style="color:#0ea5e9;"></i>
            <div style="font-size:13px;color:#94a3b8;">ম্যাপ লোড হচ্ছে...</div>
          </div>
        </div>
      </div>

      <!-- User List -->
      <div style="background:var(--card-bg,#fff);border-radius:18px;overflow:hidden;border:1px solid var(--border-color,#e2e8f0);margin-bottom:80px;">
        <div style="padding:13px 16px;font-weight:700;font-size:13px;color:var(--text-secondary,#64748b);border-bottom:1px solid var(--border-color,#f0f0f0);display:flex;align-items:center;gap:6px;background:var(--input-bg,#f8f9fa);">
          <i class="fas fa-list"></i> ইউজার লিস্ট
        </div>
        <div id="aulUserList">
          <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x" style="margin-bottom:10px;display:block;"></i>লোড হচ্ছে...</div>
        </div>
      </div>
    </div>

  </div>
  <nav class="bottom-nav">
    <div class="nav-item active" onclick="switchTab('home', this)"><i class="fas fa-home"></i> Home</div>
    <div class="nav-item" onclick="switchTab('history', this)"><i class="fas fa-history"></i> History</div>
    <div class="nav-item" onclick="switchTab('addmoney', this)"><i class="fas fa-wallet"></i> Add Money</div>
    <div class="nav-item" onclick="switchTab('tutorial', this)"><i class="fas fa-play-circle"></i> Tutorial</div>
    <div class="nav-item" onclick="switchTab('referral', this)"><i class="fas fa-gift"></i> Referral</div>
    <div class="nav-item" onclick="switchTab('profile', this)"><i class="fas fa-user-circle"></i> Profile</div>
  </nav>
</div>

<!-- STATUS MODAL -->
<div class="modal fade" id="statusModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4 rounded-4">
      <div id="modalIcon" class="mb-3 display-4"></div>
      <h5 id="modalTitle" class="fw-bold"></h5>
      <p id="modalMsg" class="text-secondary small mb-3"></p>
      <button class="btn btn-primary w-100 rounded-pill" data-bs-dismiss="modal">OK</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// =============================================
// PAYMENT VERIFY API (GET Method — DB থেকে URL লোড হয়)
// =============================================
window.paymentVerifyApiUrl = ''; // DB থেকে loadSettings() এ সেট হবে

// Helper: fetch payment data from configured API URL
async function fetchPaymentData() {
  const url = window.paymentVerifyApiUrl;
  if (!url) throw new Error('Payment API URL সেট করা হয়নি। Admin প্যানেল থেকে সেট করুন।');
  const res = await fetch(url);
  if (!res.ok) throw new Error('API request failed: ' + res.status);
  return await res.json();
}

// =============================================
// TELEGRAM WEBAPP
// =============================================
const tgApp = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
let tgUser = null;

async function getTelegramUser() {
  if (!tgApp) return null;
  tgApp.ready(); tgApp.expand();
  if (tgApp.initDataUnsafe && tgApp.initDataUnsafe.user) return tgApp.initDataUnsafe.user;
  for (let i = 0; i < 10; i++) {
    await new Promise(r => setTimeout(r, 200));
    if (tgApp.initDataUnsafe && tgApp.initDataUnsafe.user) return tgApp.initDataUnsafe.user;
  }
  return null;
}

async function loginWithTelegram() {
  if (!tgUser) { showModal('error', 'Telegram user data not found'); return; }
  const btn = document.getElementById('tgLoginBtn');
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> লগইন হচ্ছে...'; btn.disabled = true;
  const startParam = tgApp && tgApp.initDataUnsafe ? (tgApp.initDataUnsafe.start_param || '') : '';
  const res = await apiCall({
    action:       'tg_login',
    tg_id:        String(tgUser.id),
    tg_name:      (tgUser.first_name || '') + (tgUser.last_name ? ' ' + tgUser.last_name : ''),
    tg_username:  tgUser.username || '',
    tg_photo:     tgUser.photo_url || '',
    ref_code:     startParam
  });
  btn.innerHTML = '<i class="fa-brands fa-telegram me-2"></i> Telegram দিয়ে লগইন'; btn.disabled = false;
  if (res.success) {
    window.currentUser = res;
    showApp(res); loadSettings(); loadHistory();
  } else {
    if (res.message === 'account_blocked') {
      Swal.fire({ html:`<h4 class="text-danger fw-bold">Account Blocked!</h4><p class="small text-muted">Contact support.</p>`, confirmButtonColor:'#dc3545', customClass:{popup:'rounded-4'} });
    } else { showModal('error', res.message || 'Login Failed'); }
  }
}

// =============================================
// GLOBAL STATE
// =============================================
window.currentBalance = 0.00;
window.minDeposit     = 10;
window.allServices    = [];
window.allCategories  = [];
window.allMethods     = [];
window.apiConfig      = { url: '', key: '', enabled: true };
window.fullHistory    = [];
window.currentUser    = null;
window.currentMethod  = '';
let newUpdateImageBase64 = '';
let isBalLoading = false;

const modalEl = new bootstrap.Modal(document.getElementById('statusModal'));

// =============================================
// SCROLL — Hide navbar on scroll down, show on scroll up
// =============================================
(function() {
  let lastY = 0;
  window.addEventListener('scroll', function() {
    const nav = document.querySelector('.navbar');
    if (!nav || nav.style.display === 'none') return;
    const y = window.scrollY;
    if (y > lastY && y > 70) nav.classList.add('nav-hidden');
    else nav.classList.remove('nav-hidden');
    lastY = y;
  }, { passive: true });
})();

// =============================================
// INIT
// =============================================
window.addEventListener('load', async () => {
  // Initialize settings
  initTheme();
  // Restore sound/vibration toggles
  const soundToggle = document.getElementById('soundToggle');
  const vibToggle   = document.getElementById('vibrationToggle');
  if (soundToggle) soundToggle.checked = soundEnabled;
  if (vibToggle)   vibToggle.checked   = vibrationEnabled;

  // Try Telegram WebApp detection
  tgUser = await getTelegramUser();
  document.getElementById('splash-screen').style.display = 'none';
  checkSession();
});

function checkSession() {
  apiCall({ action: 'get_profile' }).then(async res => {
    // যদি session-এ ইউজার থাকে কিন্তু Telegram-এ অন্য ইউজার থেকে খোলা হয়েছে
    // তাহলে TG user দিয়ে নতুন করে লগইন করাও
    if (res.success && tgUser && res.tgId && String(res.tgId) !== String(tgUser.id)) {
      // TG ID মিলছে না — force logout করে current TG user দিয়ে login
      await apiCall({ action: 'logout' });
      res = { success: false };
    }
    if (res.success) {
      window.currentUser = res;
      showApp(res);
      loadSettings();
      loadHistory();
    } else {
      // Not logged in
      if (tgUser) {
        // Telegram WebApp থেকে খোলা হয়েছে — অটোমেটিক সাইলেন্ট লগইন
        const startParam = tgApp && tgApp.initDataUnsafe ? (tgApp.initDataUnsafe.start_param || '') : '';
        const loginRes = await apiCall({
          action:      'tg_login',
          tg_id:       String(tgUser.id),
          tg_name:     (tgUser.first_name || '') + (tgUser.last_name ? ' ' + tgUser.last_name : ''),
          tg_username: tgUser.username || '',
          tg_photo:    tgUser.photo_url || '',
          ref_code:    startParam
        });
        if (loginRes.success) {
          window.currentUser = loginRes;
          showApp(loginRes);
          loadSettings();
          loadHistory();
        } else {
          if (loginRes.message === 'account_blocked') {
            document.getElementById('splash-screen').style.display = 'none';
            Swal.fire({ html:`<h4 class="text-danger fw-bold">Account Blocked!</h4><p class="small text-muted">Contact support.</p>`, confirmButtonColor:'#dc3545', customClass:{popup:'rounded-4'} });
          } else {
            showAuthOverlay();
          }
        }
      } else {
        showAuthOverlay();
      }
    }
  }).catch(async () => {
    if (tgUser) {
      const startParam = tgApp && tgApp.initDataUnsafe ? (tgApp.initDataUnsafe.start_param || '') : '';
      const loginRes = await apiCall({
        action:      'tg_login',
        tg_id:       String(tgUser.id),
        tg_name:     (tgUser.first_name || '') + (tgUser.last_name ? ' ' + tgUser.last_name : ''),
        tg_username: tgUser.username || '',
        tg_photo:    tgUser.photo_url || '',
        ref_code:    startParam
      });
      if (loginRes.success) {
        window.currentUser = loginRes;
        showApp(loginRes);
        loadSettings();
        loadHistory();
      } else {
        showAuthOverlay();
      }
    } else {
      showAuthOverlay();
    }
  });
}

function showApp(user) {
  document.getElementById('auth-overlay').style.display   = 'none';
  document.getElementById('main-app-wrapper').style.display = 'block';
  applyUserData(user);
  // Check location verification popup
  if (typeof checkAndShowLocPopup === 'function') checkAndShowLocPopup();
}

function showAuthOverlay() {
  document.getElementById('splash-screen').style.display    = 'none';
  document.getElementById('auth-overlay').style.display     = 'flex';
  document.getElementById('main-app-wrapper').style.display = 'none';
}

function applyUserData(user) {
  window.currentBalance = parseFloat(user.balance || 0);

  // Update home balance display
  const homeBalEl = document.getElementById('homeBalanceDisplay');
  if (homeBalEl) homeBalEl.innerText = '৳' + window.currentBalance.toFixed(2);

  // Navbar: update profile photo & short name
  const navPhoto = user.tgPhoto || user.profilePic || 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png';
  const navName  = user.tgName  || user.username   || 'প্রোফাইল';
  const navPhotoEl = document.getElementById('navUserPhoto');
  const navNameEl  = document.getElementById('navUserName');
  if (navPhotoEl) { navPhotoEl.src = navPhoto; navPhotoEl.onerror = function(){ this.src='https://cdn-icons-png.flaticon.com/512/3135/3135715.png'; }; }
  if (navNameEl)  navNameEl.innerText = navName.length > 10 ? navName.slice(0, 10) + '\u2026' : navName;

  // Profile section
  document.getElementById('profileBadgePhone').innerText  = user.phone    || (user.tgUsername ? '@'+user.tgUsername : 'Telegram User');
  document.getElementById('editName').value               = user.username  || user.tgName || 'User';
  document.getElementById('profileDisplayName').innerText = user.tgName    || user.username || 'User';
  document.getElementById('editUid').innerHTML            = `${user.uid} <i class="far fa-copy ms-2"></i>`;
  document.getElementById('displayProfilePic').src        = user.tgPhoto   || user.profilePic || 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png';
  if (user.joined) {
    const d = new Date(user.joined);
    document.getElementById('editJoinDate').innerText = d.toLocaleDateString('en-GB', { year:'numeric', month:'short', day:'numeric' });
  }
  // Profile stats: total orders and total deposit
  const totOrdEl = document.getElementById('profileTotalOrders');
  const totDepEl = document.getElementById('profileTotalDeposit');
  if (totOrdEl) totOrdEl.innerText = (user.totalOrders !== undefined) ? user.totalOrders : 0;
  if (totDepEl) totDepEl.innerText = '\u09f3' + parseFloat(user.totalDeposit || 0).toFixed(2);
}

// =============================================
// LOAD SETTINGS (Categories, Services, Methods)
// =============================================
function loadSettings() {
  apiCall({ action: 'get_settings' }).then(res => {
    if (!res.success) return;
    const sets = res.settings || {};

    const badge = document.getElementById('apiStatusBadge');
    if (sets.api_key && sets.auto_order_enabled == 1) {
      if (badge) { badge.className = 'api-status-badge connected'; badge.innerHTML = '✓ API'; }
      window.apiConfig = { url: sets.api_url, key: sets.api_key, enabled: true };
    } else {
      if (badge) { badge.className = 'api-status-badge error'; badge.innerHTML = '⚠ API'; }
      window.apiConfig = { url: sets.api_url, key: sets.api_key, enabled: false };
    }

    if (sets.notice) document.getElementById('adminNotice').innerText = sets.notice;

    if (sets.min_deposit) {
      window.minDeposit = parseFloat(sets.min_deposit);
      document.getElementById('minDepositValue').innerText = window.minDeposit;
      document.getElementById('minDepositNotice').classList.remove('d-none');
    }

    // Load payment verify API URL
    if (sets.payment_verify_api_url) {
      window.paymentVerifyApiUrl = sets.payment_verify_api_url;
    }

    if (sets.app_logo) {
      const logoEl = document.getElementById('navLogoImg');
      if (logoEl) logoEl.src = sets.app_logo;
    }

    // Render slider
    const sliderItems = res.slider_items || [];
    initHeroSlider(sliderItems);

    window.allCategories = res.categories || [];
    renderCategoryGrid(window.allCategories);

    window.allServices = (res.services || []).map(s => ({
      id:         s.service_key,
      name:       s.name,
      cat:        s.cat,
      rate:       s.rate,
      min:        s.min_order,
      max:        s.max_order,
      providerId: s.provider_id,
      desc:       s.description
    }));

    window.allMethods = res.payment_methods || [];
    renderPaymentMethodsAM(window.allMethods);
  });
}

// =============================================
// HERO SLIDER
// =============================================
let _sliderTimer = null;
let _sliderIdx   = 0;
let _sliderData  = [];

function initHeroSlider(items) {
  clearInterval(_sliderTimer);
  _sliderData = items || [];
  _sliderIdx  = 0;

  const wrapper = document.getElementById('heroSliderWrapper');
  const slider  = document.getElementById('heroSlider');
  const dots    = document.getElementById('sliderDots');

  if (!_sliderData.length) {
    // Fallback to default banner
    slider.innerHTML = `<div class="slider-slide active"><img src="https://i.ibb.co/sGMv0Zq/x.jpg" alt="Banner"></div>`;
    dots.innerHTML = '';
    wrapper.style.minHeight = '';
    return;
  }

  // Build slides
  slider.innerHTML = '';
  dots.innerHTML   = '';
  _sliderData.forEach((item, idx) => {
    const slide = document.createElement('div');
    slide.className = 'slider-slide' + (idx === 0 ? ' active' : '');
    slide.innerHTML = buildSlideContent(item.url || '');
    slider.appendChild(slide);

    const dot = document.createElement('div');
    dot.className = 'slider-dot' + (idx === 0 ? ' active' : '');
    dot.onclick = () => goToSlide(idx);
    dots.appendChild(dot);
  });

  if (_sliderData.length > 1) startSliderAuto();
}

function buildSlideContent(url) {
  if (!url) return `<img src="https://i.ibb.co/sGMv0Zq/x.jpg" alt="Banner">`;
  // YouTube
  if (url.includes('youtube.com') || url.includes('youtu.be')) {
    let vid = '';
    try {
      if (url.includes('youtu.be/')) vid = url.split('youtu.be/')[1].split('?')[0];
      else vid = new URL(url).searchParams.get('v') || '';
    } catch(e) { vid = ''; }
    if (vid) return `<iframe src="https://www.youtube.com/embed/${vid}?autoplay=0&mute=0&controls=1&rel=0" allowfullscreen allow="autoplay; encrypted-media"></iframe>`;
  }
  // MP4/video
  if (url.match(/\.(mp4|webm|ogg)(\?|$)/i)) {
    return `<video src="${url}" controls style="width:100%;height:200px;border-radius:12px;object-fit:cover;"></video>`;
  }
  // Image
  return `<img src="${url}" alt="Slide" onerror="this.src='https://i.ibb.co/sGMv0Zq/x.jpg'">`;
}

function goToSlide(idx) {
  const slides = document.querySelectorAll('.slider-slide');
  const dots   = document.querySelectorAll('.slider-dot');
  slides.forEach((s,i) => { s.classList.toggle('active', i===idx); });
  dots.forEach((d,i)   => { d.classList.toggle('active', i===idx); });
  _sliderIdx = idx;
}

function startSliderAuto() {
  clearInterval(_sliderTimer);
  function next() {
    const nextIdx = (_sliderIdx + 1) % _sliderData.length;
    goToSlide(nextIdx);
    const delay = (_sliderData[nextIdx]?.seconds || 5) * 1000;
    _sliderTimer = setTimeout(next, delay);
  }
  const firstDelay = (_sliderData[0]?.seconds || 5) * 1000;
  _sliderTimer = setTimeout(next, firstDelay);
}

// =============================================
// ALL USER HISTORY
// =============================================
window.loadAndShowAllUserHistory = async function() {
  document.querySelectorAll('.filter-btn').forEach(btn => { btn.classList.remove('active','btn-dark'); btn.classList.add('btn-outline-secondary'); });
  const activeBtn = document.getElementById('btnAllUserHistory');
  if (activeBtn) { activeBtn.classList.remove('btn-outline-secondary'); activeBtn.classList.add('btn-dark','active'); }

  const list = document.getElementById('historyList');
  list.innerHTML = `<div class="text-center text-muted mt-4"><i class="fas fa-spinner fa-spin fa-2x mb-3"></i><p>Loading All User History...</p></div>`;

  const res = await apiCall({ action: 'get_all_user_history' });
  if (!res.success || !res.history) {
    list.innerHTML = `<div class="text-center text-muted mt-5"><i class="fas fa-box-open fa-3x mb-3 opacity-25"></i><p>No history found.</p></div>`;
    return;
  }

  list.innerHTML = '';
  res.history.forEach(item => {
    const avatar = item.tg_photo || item.profile_pic || 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png';
    const name   = item.tg_username ? '@' + item.tg_username : (item.username || 'User');
    const time   = item.created_at ? new Date(item.created_at).toLocaleString('en-BD') : '';

    if (item.type === 'Order') {
      list.innerHTML += `<div class="all-user-history-card">
        <img src="${avatar}" class="ahu-avatar" onerror="this.src='https://cdn-icons-png.flaticon.com/512/3135/3135715.png'">
        <div class="flex-grow-1">
          <div class="fw-bold small">${name} <span class="badge bg-primary ms-1" style="font-size:10px;">Order</span></div>
          <div class="small text-muted">${item.service||''}</div>
          <div class="d-flex justify-content-between mt-1">
            <small class="text-muted">${time}</small>
            <span class="fw-bold text-primary small">৳${parseFloat(item.amount||0).toFixed(2)}</span>
          </div>
        </div>
        <span class="badge ${item.status==='Completed'?'bg-success':item.status==='Pending'?'bg-warning text-dark':'bg-danger'}">${item.status}</span>
      </div>`;
    } else if (item.type === 'Deposit') {
      list.innerHTML += `<div class="all-user-history-card">
        <img src="${avatar}" class="ahu-avatar" onerror="this.src='https://cdn-icons-png.flaticon.com/512/3135/3135715.png'">
        <div class="flex-grow-1">
          <div class="fw-bold small">${name} <span class="badge bg-success ms-1" style="font-size:10px;">Deposit</span></div>
          <div class="small text-muted">${item.method||''} — TrxID: ${item.trx_id||''}</div>
          <div class="d-flex justify-content-between mt-1">
            <small class="text-muted">${time}</small>
            <span class="fw-bold text-success small">+৳${parseFloat(item.amount||0).toFixed(2)}</span>
          </div>
        </div>
        <span class="badge ${item.status==='Completed'?'bg-success':item.status==='Pending'?'bg-warning text-dark':'bg-danger'}">${item.status}</span>
      </div>`;
    } else if (item.type === 'NewUser') {
      list.innerHTML += `<div class="all-user-history-card">
        <img src="${avatar}" class="ahu-avatar" onerror="this.src='https://cdn-icons-png.flaticon.com/512/3135/3135715.png'">
        <div class="flex-grow-1">
          <div class="fw-bold small">${name} <span class="badge bg-warning text-dark ms-1" style="font-size:10px;">New User</span></div>
          <div class="small text-muted">নতুন ইউজার যোগ দিয়েছে</div>
          <small class="text-muted">${time}</small>
        </div>
      </div>`;
    }
  });
};
function renderCategoryGrid(categories) {
  const grid = document.getElementById('categoryGrid');
  let html = `<div class="cat-card active" data-cat="All" onclick="handleIconClick('All', this)"><div class="all-inner-box" style="width:52px;height:52px;border-radius:14px;background:#f5f6ff;border:2px solid #111;display:flex;align-items:center;justify-content:center;margin:0 auto 5px;overflow:hidden;"><img src="https://i.ibb.co/TDdR7JXR/x.jpg" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none';this.parentElement.innerHTML='<span style=\'font-size:14px;font-weight:800;color:#444;\'>All</span>'"></div><span>All</span></div>`;
  categories.forEach(c => {
    html += `<div class="cat-card" data-cat="${c.name}" onclick="handleIconClick('${c.name.replace(/'/g,"\\'")}', this)"><img src="${c.logo}" class="cat-logo" onerror="this.style.display='none'"><span>${c.name}</span></div>`;
  });
  grid.innerHTML = html;
  // Default: "All" সিলেক্ট থাকবে এবং সব সার্ভিস দেখাবে
  const allCard = grid.querySelector('.cat-card[data-cat="All"]');
  if (allCard) handleIconClick('All', allCard);
}

// =============================================
// ADD MONEY — PAYMENT METHODS FROM DB
// =============================================
function renderPaymentMethodsAM(methods) {
  if (!methods.length) return; // keep default 3 cards if no DB methods

  // Build method-row from DB data
  const row = document.getElementById('amMethodRow');
  let html = '';
  const filterSelect = document.getElementById('amHistoryFilter');
  filterSelect.innerHTML = '<option value="all">All Methods</option>';

  // Dynamic pages container
  const amContainer = document.querySelector('.am-app-container');

  // Remove old dynamic pages (keep amHomePage, amBkashPage, amNagadPage, amRocketPage)
  amContainer.querySelectorAll('.am-dynamic-page').forEach(p => p.remove());

  methods.forEach((m, idx) => {
    const mKey    = m.name.toLowerCase().replace(/\s+/g, '');
    const logo    = m.logo  || '';
    const label   = m.name;
    const number  = m.number || '';
    const pageId  = 'amDynPage_' + idx;

    // Detect color
    let bgClass = 'am-bkash-bg';
    let bgColor = '#e2136e';
    if (mKey.includes('nagad'))  { bgClass = 'am-nagad-bg';  bgColor = '#da1b23'; }
    if (mKey.includes('rocket')) { bgClass = 'am-rocket-bg'; bgColor = '#7B2CBF'; }
    if (mKey.includes('upay'))   { bgClass = ''; bgColor = '#0081d0'; }

    // Method card
    html += `<div class="am-card" onclick="amShowPage('${pageId}')">
               <img src="${logo}" onerror="this.src='https://cdn-icons-png.flaticon.com/512/3135/3135715.png'" alt="${m.name}">
               <div style="flex:1;">
                 <p style="margin:0;">${label}</p>
                 <div class="am-card-sub">Personal Account — ${number}</div>
               </div>
               <i class="fas fa-chevron-right am-card-arrow"></i>
             </div>`;

    filterSelect.innerHTML += `<option value="${mKey}">${m.name}</option>`;

    // Create dynamic payment page
    const bgStyle = bgClass ? `class="am-instruction-card ${bgClass}"` : `class="am-instruction-card" style="background:${bgColor};"`;
    const pageEl = document.createElement('div');
    pageEl.id = pageId;
    pageEl.className = 'am-page am-dynamic-page';
    pageEl.innerHTML = `
      <div class="am-top-nav">
        <div class="am-nav-icon" onclick="amShowPage('amHomePage')"><i class="fa-solid fa-arrow-left"></i></div>
        <div class="am-nav-icon" onclick="amShowPage('amHomePage')"><i class="fa-solid fa-xmark"></i></div>
      </div>
      <div class="am-payment-body">
        <div class="am-invoice-box" style="justify-content:center;padding:20px 0 10px;">
          <div class="am-invoice-logo" style="width:80px;height:80px;border-radius:16px;overflow:hidden;box-shadow:0 4px 15px rgba(0,0,0,0.15);">
            <img src="${logo || 'https://i.ibb.co/r2q5RcBg/x.jpg'}" alt="${m.name}" style="width:100%;height:100%;object-fit:cover;" onerror="this.src='https://i.ibb.co/r2q5RcBg/x.jpg'">
          </div>
        </div>
        <div class="am-yellow-note">নোটঃ টাকা পাঠানোর ৫-১০ সেকেন্ড পর ভেরিফাই করবেন।</div>
        <div ${bgStyle}>
          <div class="am-trx-title">ট্রানজেকশন আইডি দিন</div>
          <div class="am-trx-input-area">
            <input type="text" placeholder="ট্রানজেকশন আইডি দিন" id="amDynTrxInput_${idx}" autocomplete="off">
          </div>
          <div class="am-inst-header">
            <div class="am-inst-title">INSTRUCTIONS</div>
            <button class="am-qr-btn" style="background:${bgColor}80 !important;" onclick="amToggleDynQR(${idx}, '${number}', this)">
              <i class="fa-solid fa-qrcode"></i> <span id="amDynQrBtnText_${idx}">Show QR</span>
            </button>
          </div>
          <div class="am-qr-container" id="amDynQrContainer_${idx}">
            <img id="amDynQrImage_${idx}" src="" alt="${m.name} QR">
            <p>📱 স্ক্যান করুন অথবা নাম্বার কপি করুন</p>
          </div>
          <ul class="am-inst-list">
            <li><b>${m.name}</b> অ্যাপে বা মোবাইল মেনুতে যান।</li>
            <li><b>"${label} Send Money"</b> অপশনটি সিলেক্ট করুন।</li>
            <li>প্রাপক নম্বর হিসেবে এই নম্বর টি লিখুন:
              <div class="am-copy-box">
                <span>প্রাপক নম্বর: <b id="amDynNum_${idx}">${number}</b></span>
                <button class="am-copy-btn" onclick="amCopyToClipboard('amDynNum_${idx}', this)"><i class="fa-regular fa-copy"></i> Copy</button>
              </div>
            </li>
            <li>টাকার পরিমাণ: <b>আপনার পরিমাণ</b></li>
            <li>পিন দিয়ে নিশ্চিত করুন।</li>
            <li>সফল লেনদেনের পর SMS পাবেন।</li>
            <li>উপরের বক্সে <b>Transaction ID</b> দিন এবং <b>VERIFY</b> করুন।</li>
          </ul>
        </div>
      </div>
      <div class="am-bottom-fixed">
        <div class="am-container-inner">
          <button class="am-verify-btn" style="background:${bgColor};" onclick="amVerifyDynTransaction(${idx}, '${mKey}')">VERIFY TRANSACTION</button>
        </div>
      </div>`;
    amContainer.appendChild(pageEl);
  });

  row.innerHTML = html;

  // Single method হলে মাঝখানে দেখাও
  if (methods.length === 1) {
    row.classList.add('single-method');
  } else {
    row.classList.remove('single-method');
  }
}

// Dynamic QR toggle for dynamic pages
window.amToggleDynQR = function(idx, number, btn) {
  const container = document.getElementById(`amDynQrContainer_${idx}`);
  const qrImage   = document.getElementById(`amDynQrImage_${idx}`);
  const btnText   = document.getElementById(`amDynQrBtnText_${idx}`);
  if (!container._qrShown) {
    qrImage.src = amGenerateQRUrl(number);
    container.classList.add('show');
    if (btnText) btnText.innerText = 'Hide QR';
    container._qrShown = true;
  } else {
    container.classList.remove('show');
    if (btnText) btnText.innerText = 'Show QR';
    container._qrShown = false;
  }
};

// Dynamic verify for dynamic pages
window.amVerifyDynTransaction = function(idx, method) {
  const inputEl = document.getElementById(`amDynTrxInput_${idx}`);
  let trxId = inputEl.value.trim().toUpperCase();
  inputEl.value = trxId;
  if (!trxId) { amShowToast('অনুগ্রহ করে একটি ট্রানজেকশন আইডি দিন!', 'error'); inputEl.focus(); return; }
  const validFormat = /^[A-Z0-9]+$/;
  if (!validFormat.test(trxId)) { amShowToast('ত্রুটি: শুধুমাত্র বড় হাতের অক্ষর (A-Z) এবং সংখ্যা (0-9) দিন।', 'error'); inputEl.focus(); return; }

  const btn = event.currentTarget;
  const origText = btn.innerHTML;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying...';
  btn.disabled = true;

  fetchPaymentData().then(async allData => {
    btn.innerHTML = origText; btn.disabled = false;
    if (!allData) { amShowToast('ট্রানজেকশন পাওয়া যায়নি। সঠিক আইডি দিন।', 'error'); return; }
    let found = null;
    for (const key in allData) { if (allData[key].txid === trxId) { found = allData[key]; break; } }
    if (!found || parseFloat(found.amount) <= 0) { amShowToast('ট্রানজেকশন পাওয়া যায়নি। সঠিক আইডি দিন।', 'error'); return; }
    const amount = parseFloat(found.amount);
    const res = await apiCall({ action: 'firebase_auto_deposit', method, trx_id: trxId, amount });
    if (res.success) {
      window.currentBalance = parseFloat(res.newBalance);
      inputEl.value = '';
      loadHistory();
      // ✅ Balance সাথে সাথে update
      const homeBalEl2 = document.getElementById('homeBalanceDisplay');
      if (homeBalEl2) homeBalEl2.innerText = '৳' + window.currentBalance.toFixed(2);
      const mbal2 = document.getElementById('marqueeBalanceAmt');
      if (mbal2) mbal2.innerText = '৳' + window.currentBalance.toFixed(2);
      amShowToast(`✅ ${amount}৳ আপনার একাউন্টে যোগ হয়েছে!`, 'success');
      inputEl.value = '';
      fireConfetti();
      // ✅ await করা হচ্ছে — popup দেখানোর পর page switch
      await Swal.fire({
        html: `<div style="text-align:center;"><lottie-player src="https://assets2.lottiefiles.com/packages/lf20_rc5d0f61.json" background="transparent" speed="1" style="width:120px;height:120px;margin:0 auto;" loop autoplay></lottie-player>
               <h4 style="font-weight:700;margin-bottom:5px;">✅ ডিপোজিট সফল!</h4></div>
               <div class="swal-details-box">
                 <div class="swal-row"><span class="swal-label">Method:</span><span class="swal-val">${method.toUpperCase()}</span></div>
                 <div class="swal-row"><span class="swal-label">TrxID:</span><span class="swal-val">${trxId}</span></div>
                 <div class="swal-row"><span class="swal-label">Amount:</span><span class="swal-val">৳${amount}</span></div>
                 <div class="swal-row"><span class="swal-label">New Balance:</span><span class="swal-val" style="color:#198754;font-weight:700;">৳${parseFloat(res.newBalance).toFixed(2)}</span></div>
               </div>`,
        confirmButtonText: 'OK',
        confirmButtonColor: '#198754',
        buttonsStyling: false,
        customClass: { popup: 'rounded-4 p-0 pb-3', confirmButton: 'btn btn-success w-100 rounded-pill fw-bold my-2 shadow-sm' }
      });
      amShowPage('amHomePage'); amRenderHistory('all');
    } else {
      amShowToast(res.message || 'Deposit failed!', 'error');
    }
  }).catch(err => {
    btn.innerHTML = origText; btn.disabled = false;
    amShowToast('API error: ' + err.message, 'error');
  });
};

// =============================================
// ADD MONEY SECTION — JAVASCRIPT
// =============================================
const amQrStates = { bkash: false, nagad: false, rocket: false };

// Phone numbers (updated from DB, fallback defaults)
window.amBkashNumber  = '018XXXXXXXX';
window.amNagadNumber  = '018XXXXXXXX';
window.amRocketNumber = '018XXXXXXXX';

function amShowPage(pageId) {
  document.querySelectorAll('.am-page').forEach(p => p.classList.remove('active'));
  document.getElementById(pageId).classList.add('active');

  if (['amBkashPage', 'amNagadPage', 'amRocketPage'].includes(pageId)) {
    const method = pageId.replace('amPage','').replace('am','').replace('Page','').toLowerCase();
    const input = document.getElementById(`am${capitalize(method)}TrxInput`);
    if (input) input.value = '';

    const container = document.getElementById(`am${capitalize(method)}QrContainer`);
    const btnText   = document.getElementById(`am${capitalize(method)}QrBtnText`);
    if (container) container.classList.remove('show');
    if (btnText) btnText.innerText = 'Show QR';
    amQrStates[method] = false;
  }

  if (pageId === 'amHomePage') amRenderHistory('all');
}

function capitalize(str) { return str.charAt(0).toUpperCase() + str.slice(1); }

function amCopyToClipboard(elementId, btn) {
  const textToCopy = document.getElementById(elementId).innerText;
  navigator.clipboard.writeText(textToCopy).then(() => {
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    btn.style.background = "#28a745";
    setTimeout(() => { btn.innerHTML = orig; btn.style.background = "#222"; }, 2000);
    amShowToast("নম্বর কপি হয়েছে!", "success");
  });
}

function amShowToast(message, type = 'success') {
  const toast = document.getElementById('amToast');
  toast.textContent = message;
  toast.className = `am-toast ${type}`;
  toast.style.display = 'block';
  setTimeout(() => { toast.style.display = 'none'; }, 3500);
}

function amGenerateQRUrl(phoneNumber) {
  return `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(phoneNumber)}`;
}

function amToggleQR(method) {
  const container = document.getElementById(`am${capitalize(method)}QrContainer`);
  const qrImage   = document.getElementById(`am${capitalize(method)}QrImage`);
  const btnText   = document.getElementById(`am${capitalize(method)}QrBtnText`);
  const numMap    = { bkash: window.amBkashNumber, nagad: window.amNagadNumber, rocket: window.amRocketNumber };
  if (!amQrStates[method]) {
    qrImage.src = amGenerateQRUrl(numMap[method]);
    container.classList.add('show');
    btnText.innerText = 'Hide QR';
    amQrStates[method] = true;
  } else {
    container.classList.remove('show');
    btnText.innerText = 'Show QR';
    amQrStates[method] = false;
  }
}

function amRenderHistory(filter = 'all') {
  const container = document.getElementById('amHomeHistoryList');
  // Use fullHistory from DB (deposits only)
  const deposits  = (window.fullHistory || []).filter(h => h.type === 'Deposit');
  const filtered  = filter === 'all' ? deposits : deposits.filter(d => (d.service || '').toLowerCase().includes(filter));

  if (filtered.length === 0) {
    container.innerHTML = `<div class="am-empty-history"><i class="fa-solid fa-receipt"></i>কোন ডিপোজিট হিস্টোরি পাওয়া যায়নি</div>`;
    return;
  }
  container.innerHTML = filtered.map(item => {
    const rawMethod = (item.service || '').toLowerCase().includes('bkash')  ? 'bkash'  :
                      (item.service || '').toLowerCase().includes('nagad')  ? 'nagad'  :
                      (item.service || '').toLowerCase().includes('rocket') ? 'rocket' : 'other';
    const sc  = item.status === 'Completed' ? 'am-status-success' : item.status === 'Pending' ? 'am-status-pending' : 'am-status-failed';
    const icon = item.status === 'Completed' ? '✓' : item.status === 'Pending' ? '⏳' : '✗';
    const ml  = rawMethod === 'bkash' ? 'bKash' : rawMethod === 'nagad' ? 'Nagad' : rawMethod === 'rocket' ? 'Rocket' : 'Other';
    const mc  = rawMethod === 'bkash' ? '#e2136e' : rawMethod === 'nagad' ? '#da1b23' : rawMethod === 'rocket' ? '#7B2CBF' : '#555';
    const diff = Math.floor((Date.now() - item.timestamp) / 1000);
    const time = diff < 60 ? 'এখনই' : diff < 3600 ? `${Math.floor(diff/60)} মিনিট আগে` : diff < 86400 ? `${Math.floor(diff/3600)} ঘণ্টা আগে` : `${Math.floor(diff/86400)} দিন আগে`;
    return `<div class="am-history-item">
      <div class="am-history-info">
        <span class="am-trx-id">TrxID: ${item.trxId || item.link || ''}</span>
        <div class="am-meta">
          <span class="am-method-badge" style="background:${mc}20;color:${mc}">${ml}</span>
          <span class="am-time">${time}</span>
        </div>
      </div>
      <span class="am-history-amount ${sc}">+৳${item.amount} ${icon}</span>
    </div>`;
  }).join('');
}

function amVerifyTransaction(method) {
  const inputEl = document.getElementById(`am${capitalize(method)}TrxInput`);
  let trxId = inputEl.value.trim().toUpperCase();
  inputEl.value = trxId;

  if (!trxId) { amShowToast('অনুগ্রহ করে একটি ট্রানজেকশন আইডি দিন!', 'error'); inputEl.focus(); return; }

  const validFormat = /^[A-Z0-9]+$/;
  if (!validFormat.test(trxId)) { amShowToast('ত্রুটি: শুধুমাত্র বড় হাতের অক্ষর (A-Z) এবং সংখ্যা (0-9) দিন।', 'error'); inputEl.focus(); return; }

  const btn = event.currentTarget;
  const origText = btn.innerHTML;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying...';
  btn.disabled = true;

  // ---- GET API Auto-Verify (payment_verify_api_url থেকে ডেটা নেওয়া হচ্ছে) ----
  fetchPaymentData().then(async allData => {
    btn.innerHTML = origText; btn.disabled = false;

    if (!allData) {
      amShowToast('ট্রানজেকশন পাওয়া যায়নি। সঠিক আইডি দিন।', 'error'); return;
    }

    let found = null;
    for (const key in allData) {
      if (allData[key].txid === trxId) { found = allData[key]; break; }
    }

    if (!found || parseFloat(found.amount) <= 0) {
      amShowToast('ট্রানজেকশন পাওয়া যায়নি। সঠিক আইডি দিন।', 'error'); return;
    }

    const amount = parseFloat(found.amount);
    // Send to PHP to save & update balance
    const res = await apiCall({ action: 'firebase_auto_deposit', method, trx_id: trxId, amount });

    if (res.success) {
      window.currentBalance = parseFloat(res.newBalance);
      const homeBalEl = document.getElementById('homeBalanceDisplay');
      if (homeBalEl) homeBalEl.innerText = '৳' + window.currentBalance.toFixed(2);
      inputEl.value = '';
      loadHistory();
      // ✅ Balance সাথে সাথে update
      const homeBalDyn = document.getElementById('homeBalanceDisplay');
      if (homeBalDyn) homeBalDyn.innerText = '৳' + window.currentBalance.toFixed(2);
      const mbalDyn = document.getElementById('marqueeBalanceAmt');
      if (mbalDyn) mbalDyn.innerText = '৳' + window.currentBalance.toFixed(2);
      amShowToast(`✅ ${amount}৳ আপনার একাউন্টে যোগ হয়েছে!`, 'success');
      fireConfetti();
      // ✅ await — popup দেখানোর পর page switch
      await Swal.fire({
        html: `<div style="text-align:center;"><lottie-player src="https://assets2.lottiefiles.com/packages/lf20_rc5d0f61.json" background="transparent" speed="1" style="width:120px;height:120px;margin:0 auto;" loop autoplay></lottie-player>
               <h4 style="font-weight:700;margin-bottom:5px;">✅ ডিপোজিট সফল!</h4></div>
               <div class="swal-details-box">
                 <div class="swal-row"><span class="swal-label">Method:</span><span class="swal-val">${method.toUpperCase()}</span></div>
                 <div class="swal-row"><span class="swal-label">TrxID:</span><span class="swal-val">${trxId}</span></div>
                 <div class="swal-row"><span class="swal-label">Amount:</span><span class="swal-val">৳${amount}</span></div>
                 <div class="swal-row"><span class="swal-label">New Balance:</span><span class="swal-val" style="color:#198754;font-weight:700;">৳${parseFloat(res.newBalance).toFixed(2)}</span></div>
               </div>`,
        confirmButtonText: 'OK',
        confirmButtonColor: '#198754',
        buttonsStyling: false,
        customClass: { popup: 'rounded-4 p-0 pb-3', confirmButton: 'btn btn-success w-100 rounded-pill fw-bold my-2 shadow-sm' }
      });
      amShowPage('amHomePage'); amRenderHistory('all');
    } else {
      amShowToast(res.message || 'Deposit failed!', 'error');
    }
  }).catch(err => {
    btn.innerHTML = origText; btn.disabled = false;
    amShowToast('API error: ' + err.message, 'error');
  });
}

function amOpenModal(id)  { document.getElementById(id).classList.add('active'); }
function amCloseModal(id) { document.getElementById(id).classList.remove('active'); }

// =============================================
// AUTH FORMS
// =============================================
document.getElementById('loginForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const email = document.getElementById('loginEmail').value;
  const pass  = document.getElementById('loginPass').value;
  const btn   = document.querySelector('#loginForm .auth-btn');
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...'; btn.disabled = true;
  const res = await apiCall({ action:'login', email, password: pass });
  btn.innerHTML = 'Sign In'; btn.disabled = false;
  if (res.success) {
    window.currentUser = res;
    showApp(res); loadSettings(); loadHistory();
  } else {
    if (res.message === 'account_blocked') {
      Swal.fire({ html:`<lottie-player src="https://assets10.lottiefiles.com/packages/lf20_MKS7fe.json" background="transparent" speed="1" style="width:180px;height:180px;margin:0 auto;" loop autoplay></lottie-player><h4 class="text-danger fw-bold mb-2">Account Blocked!</h4><p class="small text-muted">Your account has been blocked. Contact support for help.</p>`, allowOutsideClick:false, confirmButtonText:'OK', confirmButtonColor:'#dc3545', customClass:{popup:'rounded-4'} });
    } else {
      // ✅ FIX: Bootstrap modal এর পরিবর্তে Swal ব্যবহার করো — backdrop block করে না
      Swal.fire({ icon:'error', title:'Login Failed', text: res.message || 'Wrong Email or Password!', confirmButtonColor:'#dc3545', customClass:{popup:'rounded-4'} });
    }
  }
});

document.getElementById('signupForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const name  = document.getElementById('regName').value;
  const phone = document.getElementById('regPhone').value;
  const email = document.getElementById('regEmail').value;
  const pass  = document.getElementById('regPass').value;
  const btn   = document.querySelector('#signupForm .auth-btn');
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...'; btn.disabled = true;
  const res = await apiCall({ action:'register', name, phone, email, password: pass });
  btn.innerHTML = 'Create Account'; btn.disabled = false;
  if (res.success) {
    // Auto-login after registration and go directly to home
    const loginRes = await apiCall({ action:'login', email, password: pass });
    if (loginRes.success) {
      window.currentUser = loginRes;
      showApp(loginRes); loadSettings(); loadHistory();
    } else {
      showModal('success', 'Account Created! Please login.');
      toggleAuth('login');
    }
  }
  else showModal('error', res.message || 'Registration failed');
});

document.getElementById('forgotForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const email = document.getElementById('resetEmail').value;
  const res = await apiCall({ action:'forgot_password', email });
  showModal(res.success ? 'success' : 'error', res.message);
  if (res.success) toggleAuth('login');
});

// =============================================
// PLACE ORDER
// =============================================
window.placeOrder = async function() {
  const svcSel  = document.getElementById('serviceSelect');
  if (svcSel.selectedIndex === 0 || svcSel.disabled) return showModal('warning', 'Please choose a service first');
  const opt        = svcSel.options[svcSel.selectedIndex];
  const providerId = opt.dataset.providerId;
  if (!providerId) return showModal('error', 'Service ID not configured. Contact admin.');
  const link     = document.getElementById('link').value.trim();
  const quantity = parseInt(document.getElementById('quantity').value);
  const rate     = parseFloat(opt.value);
  const charge   = parseFloat((rate / 1000 * quantity).toFixed(2));
  if (!link || !quantity || quantity <= 0) return showModal('warning', 'Please enter valid link and quantity');
  if (charge > window.currentBalance) {
    return Swal.fire({ html:`<lottie-player src="https://assets10.lottiefiles.com/packages/lf20_usmfx6bp.json" background="transparent" speed="1" style="width:150px;height:150px;margin:0 auto;" loop autoplay></lottie-player><h4 class="fw-bold">Insufficient Balance!</h4><p class="small text-muted">You need ৳${charge} but you have ৳${window.currentBalance.toFixed(2)}.</p>`, confirmButtonText:'Add Funds', confirmButtonColor:'#dc3545', customClass:{popup:'rounded-4'} }).then(r => { if (r.isConfirmed) switchTab('addmoney', document.querySelector('.nav-item:nth-child(3)')); });
  }
  if (!window.apiConfig.enabled || !window.apiConfig.key) return showModal('error', 'Auto-order system is disabled. Contact admin.');

  Swal.fire({ title:'Processing Order...', html:'<div class="loading-dots" style="margin:20px auto;"><div class="dot"></div><div class="dot"></div><div class="dot"></div></div>', allowOutsideClick:false, showConfirmButton:false, customClass:{popup:'rounded-4'} });

  const res = await apiCall({ action:'place_order', service_id: opt.dataset.id, provider_id: providerId, service_name: opt.text, link, quantity, charge });
  Swal.close();
  if (res.success) {
    window.currentBalance = parseFloat(res.newBalance);
    // Update marquee balance
    const mbal = document.getElementById('marqueeBalanceAmt');
    if (mbal) mbal.innerText = '৳' + window.currentBalance.toFixed(2);
    const homeBalEl = document.getElementById('homeBalanceDisplay');
    if (homeBalEl) homeBalEl.innerText = '৳' + window.currentBalance.toFixed(2);
    fireConfetti();
    Swal.fire({
      html: `<lottie-player src="https://assets5.lottiefiles.com/packages/lf20_jbrw3hcz.json" background="transparent" speed="1" style="width:120px;height:120px;margin:0 auto;" loop autoplay></lottie-player>
             <h3 style="color:#198754;font-weight:800;margin-top:8px;">✅ অর্ডার সফল!</h3>
             <div class="swal-details-box text-start mt-3">
               <div class="swal-row"><span class="swal-label">Order ID:</span><span class="swal-val fw-bold">#${res.orderId}</span></div>
               <div class="swal-row"><span class="swal-label">Service:</span><span class="swal-val text-truncate" style="max-width:160px;">${opt.text}</span></div>
               <div class="swal-row"><span class="swal-label">Quantity:</span><span class="swal-val">${quantity}</span></div>
               <div class="swal-row"><span class="swal-label">Cost:</span><span class="swal-val fw-bold text-danger">৳${charge}</span></div>
               <div class="swal-row"><span class="swal-label">Balance:</span><span class="swal-val fw-bold text-success">৳${window.currentBalance.toFixed(2)}</span></div>
             </div>`,
      confirmButtonText: 'Continue Shopping 🛍️',
      confirmButtonColor: '#198754',
      customClass: { popup: 'rounded-4', confirmButton: 'btn btn-success w-100 rounded-pill fw-bold shadow-sm' },
      buttonsStyling: false
    });
    resetOrderForm();
    loadHistory();
  } else {
    if (res.message === 'insufficient_balance') {
      window.currentBalance = parseFloat(res.balance);
      Swal.fire({
        icon: 'error',
        title: '❌ ব্যালেন্স কম!',
        html: `আপনার ব্যালেন্স অপর্যাপ্ত।<br><b>প্রয়োজন:</b> ৳${charge}<br><b>আপনার ব্যালেন্স:</b> ৳${parseFloat(res.balance).toFixed(2)}`,
        confirmButtonText: 'Add Funds',
        confirmButtonColor: '#dc3545',
        customClass: { popup: 'rounded-4' }
      }).then(r => { if (r.isConfirmed) switchTab('addmoney', document.querySelector('.nav-item:nth-child(3)')); });
    } else {
      // বিস্তারিত error message ইউজারকে দেখাও
      const errMsg = res.message || 'Order failed. Please try again.';
      Swal.fire({
        icon: 'error',
        title: '❌ অর্ডার ব্যর্থ হয়েছে!',
        html: `<div style="text-align:left;background:#fff5f5;padding:12px;border-radius:8px;border:1px solid #fecaca;font-size:13px;color:#dc2626;margin-top:8px;"><i class="fas fa-exclamation-circle me-1"></i> ${errMsg}</div>`,
        confirmButtonText: 'ঠিক আছে',
        confirmButtonColor: '#dc3545',
        customClass: { popup: 'rounded-4' }
      });
    }
  }
};

function resetOrderForm() {
  document.getElementById('orderForm').reset();
  document.getElementById('descContainer').style.display = 'none';
  document.getElementById('serviceSelect').selectedIndex = 0;
  document.getElementById('serviceSelect').disabled      = true;
  document.getElementById('serviceHint').style.display   = 'block';
  document.getElementById('quantity').value  = '100';
  document.getElementById('charge').value    = '৳ 0.00';
}

// =============================================
// HISTORY
// =============================================
function loadHistory() {
  apiCall({ action:'get_history' }).then(res => {
    window.fullHistory = res.history || [];
    renderHistoryList(window.fullHistory);
    amRenderHistory('all');
  });
}

function renderHistoryList(items) {
  const list = document.getElementById('historyList');
  if (!list) return;
  if (!items.length) { list.innerHTML = `<div class="text-center text-muted mt-5"><i class="fas fa-box-open fa-3x mb-3 opacity-25"></i><p>No history.</p></div>`; return; }
  list.innerHTML = '';
  items.forEach(item => {
    const badge      = item.status === 'Pending' ? 'bg-warning text-dark' : (item.status === 'Completed' ? 'bg-success' : 'bg-danger');
    let brandColor   = '#667eea';
    const sName      = (item.service || '').toLowerCase();
    if (sName.includes('bkash'))       brandColor = '#e2136e';
    else if (sName.includes('nagad'))  brandColor = '#f6921e';
    else if (sName.includes('rocket')) brandColor = '#8c3494';
    else if (sName.includes('upay'))   brandColor = '#0081d0';
    const idDisplay  = item.type === 'Deposit' ? '<i class="fas fa-arrow-down text-success"></i>' : `ID: ${item.orderId}`;
    const apiInfo    = item.apiOrderId ? `<br><small class="text-muted">API: ${item.apiOrderId}</small>` : '';
    list.innerHTML  += `<div class="history-card" style="border-left-color:${brandColor};"><div class="d-flex justify-content-between" style="min-height:25px;"><span class="fw-bold">${idDisplay}</span><span class="badge ${badge} status-badge">${item.status}</span></div><div class="small fw-bold mb-1" style="color:${brandColor};"><i class="fas fa-play-circle me-1"></i> ${item.service}${apiInfo}</div><div class="small text-secondary mb-1"><i class="fas fa-link me-1"></i> ${item.link}</div><div class="d-flex justify-content-between mt-2 border-top pt-2"><small class="text-muted">${item.date}</small><span class="fw-bold text-primary">৳${item.amount}</span></div></div>`;
  });
}

window.filterHistory = function(status) {
  document.querySelectorAll('.filter-btn').forEach(btn => { btn.classList.remove('active','btn-dark'); btn.classList.add('btn-outline-secondary'); });
  const activeBtn = document.getElementById('btn' + status);
  if (activeBtn) { activeBtn.classList.remove('btn-outline-secondary'); activeBtn.classList.add('btn-dark','active'); }
  const filtered = status === 'All' ? window.fullHistory : window.fullHistory.filter(o => o.status === status);
  renderHistoryList(filtered);
};

// =============================================
// SEARCH ORDER
// =============================================
window.searchOrder = async function() {
  const inputVal = document.getElementById('searchInput').value.trim();
  document.getElementById('searchInput').value = '';
  if (!inputVal) return Swal.fire({ title:'Search Empty', text:'Please enter Order ID or TrxID.', icon:'warning', confirmButtonColor:'#333', customClass:{popup:'rounded-4'} });
  const res = await apiCall({ action:'search_order', query: inputVal });
  if (res.success && res.item) {
    const item = res.item;
    let statusColor = '#dc3545', iconHtml = '<i class="fas fa-times-circle text-danger display-1"></i>';
    if (item.status === 'Pending')   { statusColor='#ffc107'; iconHtml='<i class="fas fa-clock text-warning display-1"></i>'; }
    if (item.status === 'Completed') { statusColor='#198754'; iconHtml='<i class="fas fa-check-circle text-success display-1"></i>'; }
    Swal.fire({ html:`<div class="mb-3">${iconHtml}</div><h4 class="fw-bold mb-3" style="color:${statusColor}">${item.status.toUpperCase()}</h4><div class="swal-details-box text-start"><div class="swal-row"><span class="swal-label">Type:</span><span class="swal-val">${item.type}</span></div><div class="swal-row"><span class="swal-label">ID/Trx:</span><span class="swal-val">${item.trxId || item.orderId}</span></div><div class="swal-row"><span class="swal-label">Amount:</span><span class="swal-val fw-bold">৳${item.amount}</span></div><div class="swal-row"><span class="swal-label">Date:</span><span class="swal-val">${item.date}</span></div></div>`, confirmButtonText:'Close', confirmButtonColor:'#333', customClass:{popup:'rounded-4'} });
  } else { Swal.fire({ title:'Not Found', text:'No Order or Transaction found with this ID.', icon:'error', confirmButtonColor:'#333', customClass:{popup:'rounded-4'} }); }
};

// =============================================
// API PAGE
// =============================================
let currentApiKey = '';

window.openApiPage = function() {
  document.querySelectorAll('.app-section').forEach(e => e.classList.remove('active'));
  document.getElementById('api-section').classList.add('active');
  window.scrollTo({ top:0, behavior:'instant' });
  loadApiKey();
};

window.loadApiKey = async function() {
  const display = document.getElementById('apiKeyDisplay');
  display.innerText = 'Loading...';
  const res = await apiCall({ action: 'get_api_key' });
  if (res.success) {
    currentApiKey = res.api_key;
    display.innerText = res.api_key;
    updateApiExamples(res.api_key);
  } else {
    display.innerText = 'Error loading key';
  }
};

window.regenerateApiKey = async function() {
  const confirmed = await Swal.fire({
    title: 'API Key Regenerate করবেন?',
    text: 'পুরনো key আর কাজ করবে না!',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#764ba2',
    cancelButtonColor: '#6c757d',
    confirmButtonText: 'হ্যাঁ, Regenerate করুন',
    cancelButtonText: 'না',
    customClass: { popup: 'rounded-4' }
  });
  if (!confirmed.isConfirmed) return;
  const res = await apiCall({ action: 'regenerate_api_key' });
  if (res.success) {
    currentApiKey = res.api_key;
    document.getElementById('apiKeyDisplay').innerText = res.api_key;
    updateApiExamples(res.api_key);
    showModal('success', 'নতুন API Key তৈরি হয়েছে!');
  }
};

window.copyApiKey = function() {
  if (!currentApiKey) return;
  navigator.clipboard.writeText(currentApiKey).then(() => showModal('success', 'API Key কপি হয়েছে!'));
};

window.copyBaseUrl = function() {
  const url = document.getElementById('apiBaseUrl').innerText;
  navigator.clipboard.writeText(url).then(() => showModal('success', 'URL কপি হয়েছে!'));
};

window.copyEndpoint = function(id) {
  const text = document.getElementById(id).innerText;
  navigator.clipboard.writeText(text).then(() => {
    const btn = document.querySelector(`[onclick="copyEndpoint('${id}')"]`);
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check" style="font-size:11px;color:#198754;"></i>';
    setTimeout(() => { btn.innerHTML = orig; }, 2000);
    showModal('success', 'কপি হয়েছে!');
  });
};

window.copyById = function(id) {
  const el = document.getElementById(id);
  const text = el.innerText || el.textContent;
  navigator.clipboard.writeText(text).then(() => {
    const btn = document.querySelector(`[onclick="copyById('${id}')"]`);
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
    btn.style.color = '#198754';
    setTimeout(() => { btn.innerHTML = orig; btn.style.color = ''; }, 2000);
  });
};

function updateApiExamples(key) {
  const base = window.location.origin + window.location.pathname;
  document.getElementById('apiBaseUrl').innerText = base + '?api=1';

  document.getElementById('getExampleCode').innerText =
    base + '?api=1&key=' + key + '&action=add&service=1&link=https://example.com&quantity=100';

  document.getElementById('postExampleCode').innerHTML =
    `$ch = curl_init();\ncurl_setopt_array($ch, [\n  CURLOPT_URL => '${base}?api=1',\n  CURLOPT_POST => true,\n  CURLOPT_POSTFIELDS => http_build_query([\n    'key' => '${key}',\n    'action' => 'add',\n    'service' => '1',\n    'link' => 'https://example.com',\n    'quantity' => 100\n  ]),\n  CURLOPT_RETURNTRANSFER => true\n]);\n$result = json_decode(curl_exec($ch), true);`;
}


// =============================================
// LOCATION VERIFICATION
// =============================================
window.loadLocationStatus = async function() {
  const res = await apiCall({ action: 'get_location' });
  if (!res.success) return;
  if (res.verified) {
    _showLocVerified(res.country, res.district, res.thana, res.verifiedAt);
  }
};

function _showLocVerified(country, district, thana, verifiedAt) {
  const badge = document.getElementById('locVerifiedBadge');
  const sub   = document.getElementById('locStatusText');
  const box   = document.getElementById('locInfoBox');
  const btn   = document.getElementById('locVerifyBtn');

  if (badge) { badge.style.display = 'block'; }
  if (sub)   { sub.textContent = 'সফলভাবে ভেরিফাই হয়েছে'; }
  if (box)   { box.style.display = 'block'; }
  if (btn)   { btn.innerHTML = '<i class="fas fa-redo me-2"></i> আবার আপডেট করুন'; }

  const setEl = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val || '—'; };
  setEl('locCountry',  country);
  setEl('locDistrict', district);
  setEl('locThana',    thana);

  if (verifiedAt) {
    const d = new Date(verifiedAt);
    const formatted = d.toLocaleDateString('bn-BD', { day:'numeric', month:'short', year:'numeric' }) + ' ' + d.toLocaleTimeString('bn-BD', { hour:'2-digit', minute:'2-digit' });
    const timeEl = document.getElementById('locVerifiedTimeSpan');
    if (timeEl) timeEl.textContent = 'ভেরিফাই হয়েছে: ' + formatted;
  }
}

window.verifyLocation = async function() {
  const btn = document.getElementById('locVerifyBtn');
  if (!btn) return;

  // Check browser support
  if (!navigator.geolocation) {
    showModal('error', 'আপনার ব্রাউজার লোকেশন সাপোর্ট করে না।');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> লোকেশন নিচ্ছে...';

  navigator.geolocation.getCurrentPosition(
    async (pos) => {
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;

      btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> তথ্য যাচাই হচ্ছে...';

      try {
        // Reverse geocode using OpenStreetMap Nominatim (free, no key needed)
        const geoRes = await fetch(
          `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&accept-language=bn,en`,
          { headers: { 'Accept-Language': 'bn,en' } }
        );
        const geoData = await geoRes.json();
        const addr = geoData.address || {};

        const country  = addr.country  || addr.country_code?.toUpperCase() || 'অজানা';
        const district = addr.state_district || addr.district || addr.county || addr.state || 'অজানা';
        const thana    = addr.suburb || addr.subdistrict || addr.city_district || addr.city || addr.town || addr.village || addr.municipality || 'অজানা';

        // Save to DB
        const saveRes = await apiCall({
          action:   'save_location',
          lat:      lat,
          lng:      lng,
          country:  country,
          district: district,
          thana:    thana
        });

        btn.disabled = false;

        if (saveRes.success) {
          _showLocVerified(country, district, thana, new Date().toISOString().slice(0,19).replace('T',' '));
          playSound && playSound('success');
          doVibrate && doVibrate([50, 50, 100]);
          showModal('success', '✅ লোকেশন সফলভাবে ভেরিফাই হয়েছে!\n🌍 ' + country + ' | 🏙️ ' + district + ' | 🏘️ ' + thana);
        } else {
          btn.innerHTML = '<i class="fas fa-crosshairs me-2"></i> লোকেশন ভেরিফাই করুন';
          showModal('error', saveRes.message || 'সেভ করতে সমস্যা হয়েছে।');
        }

      } catch (err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-crosshairs me-2"></i> লোকেশন ভেরিফাই করুন';
        showModal('error', 'জিওকোডিং এ সমস্যা হয়েছে: ' + err.message);
      }
    },
    (err) => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-crosshairs me-2"></i> লোকেশন ভেরিফাই করুন';
      const msgs = {
        1: '❌ লোকেশন পারমিশন দেওয়া হয়নি। অনুগ্রহ করে ব্রাউজার সেটিং থেকে লোকেশন চালু করুন।',
        2: '❌ লোকেশন পাওয়া যাচ্ছে না। একটু পরে চেষ্টা করুন।',
        3: '❌ লোকেশন নিতে সময় বেশি লাগছে। পুনরায় চেষ্টা করুন।'
      };
      showModal('error', msgs[err.code] || '❌ লোকেশন পাওয়া যায়নি।');
    },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
  );
};

// =============================================
// PROFILE
// =============================================
window.triggerSaveProfile = function() {
  saveProfileChanges();
};
window.saveProfileChanges = async function() {
  const newName = document.getElementById('editName').value.trim();
  const savedPic = newUpdateImageBase64; // save before clearing
  const data    = { action:'update_profile', username: newName };
  if (newUpdateImageBase64) data.profile_pic = newUpdateImageBase64;
  const res = await apiCall(data);
  if (res.success) {
    document.getElementById('profileDisplayName').innerText = newName;

    // ✅ FIX: Navbar name আপডেট করো
    const navNameEl = document.getElementById('navUserName');
    if (navNameEl) navNameEl.innerText = newName.length > 10 ? newName.slice(0, 10) + '\u2026' : newName;

    // ✅ FIX: নতুন Profile Pic থাকলে navbar ও currentUser আপডেট করো
    if (savedPic) {
      const navPhotoEl = document.getElementById('navUserPhoto');
      if (navPhotoEl) { navPhotoEl.src = savedPic; }
      if (window.currentUser) {
        window.currentUser.profilePic = savedPic;
        window.currentUser.tgPhoto    = ''; // tgPhoto override করো না
      }
    }
    // username ও currentUser এ রাখো
    if (window.currentUser) window.currentUser.username = newName;

    newUpdateImageBase64 = '';
    Swal.fire({ title:'Profile Updated!', text:'Your profile details have been saved successfully.', icon:'success', confirmButtonColor:'#1877f2', confirmButtonText:'Okay', customClass:{popup:'rounded-4'} });
  } else { showModal('error', res.message || 'Update failed'); }
};

window.triggerPasswordReset = function() { showModal('info', 'To change your password, please contact admin or use the forgot password option from logout.'); };

// =============================================
// LEADERBOARD
// =============================================
let _lbData = null;
let _lbActiveTab = 'ref';

window.openLeaderboard = function() {
  document.querySelectorAll('.app-section').forEach(e => e.classList.remove('active'));
  document.getElementById('leaderboard-section').classList.add('active');
  window.scrollTo({ top:0, behavior:'instant' });
  _lbData = null;
  _lbActiveTab = 'ref';
  // Reset tab buttons styling
  ['lbTabRef','lbTabDep','lbTabUsers'].forEach(id => {
    const el = document.getElementById(id);
    if (el) { el.style.background='transparent'; el.style.color='#94a3b8'; el.style.boxShadow='none'; }
  });
  switchLbTab('ref');
  loadLeaderboard();
};

// switchLbTab — replaced by new version above with 3-tab support

window.loadLeaderboard = async function() {
  const loadHtml = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x" style="margin-bottom:10px;display:block;"></i>Loading...</div>';
  document.getElementById('topReferrersList').innerHTML = loadHtml;
  document.getElementById('topDepositorsList').innerHTML = loadHtml;

  const res = await apiCall({ action: 'get_leaderboard' });
  if (!res.success) {
    const err = '<div style="text-align:center;padding:30px;color:#ef4444;font-size:13px;"><i class="fas fa-exclamation-circle fa-2x" style="display:block;margin-bottom:8px;"></i>Failed to load data.</div>';
    document.getElementById('topReferrersList').innerHTML = err;
    document.getElementById('topDepositorsList').innerHTML = err;
    return;
  }
  _lbData = res;

  const defaultPic = 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png';

  function rankBadge(i) {
    const colors = ['#F6A623','#A0A0A0','#CD7F32'];
    const color  = colors[i] || '#e2e8f0';
    const textC  = i < 3 ? 'white' : '#64748b';
    return `<div style="width:36px;height:36px;border-radius:50%;background:${color};color:${textC};display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0;">#${i+1}</div>`;
  }

  function buildRow(u, i, valueHtml, isLast) {
    const photo = u.photo || defaultPic;
    const rowBg = i === 0 ? 'background:#fffbeb;' : (i % 2 === 0 ? 'background:#fafafa;' : 'background:white;');
    return `<div style="display:flex;align-items:center;gap:12px;padding:13px 12px;border-bottom:${isLast?'none':'1px solid #f1f5f9'};${rowBg}border-radius:10px;margin-bottom:4px;">
      ${rankBadge(i)}
      <img src="${photo}" onerror="this.src='${defaultPic}'" style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;flex-shrink:0;">
      <div style="flex:1;min-width:0;">
        <div style="font-weight:700;font-size:14px;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;">${u.name || 'User'}</div>
      </div>
      ${valueHtml}
    </div>`;
  }

  // Referrers
  const refs = res.topReferrers || [];
  if (!refs.length) {
    document.getElementById('topReferrersList').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;"><i class="fas fa-user-plus fa-2x" style="display:block;margin-bottom:8px;opacity:0.3;"></i>No referral data yet.</div>';
  } else {
    document.getElementById('topReferrersList').innerHTML = refs.map((u, i) =>
      buildRow(u, i,
        `<div style="text-align:right;flex-shrink:0;"><div style="font-size:18px;font-weight:800;color:#667eea;">${u.count}</div><div style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;">REFERRALS</div></div>`,
        i === refs.length - 1)
    ).join('');
  }

  // Depositors
  const deps = res.topDepositors || [];
  if (!deps.length) {
    document.getElementById('topDepositorsList').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;"><i class="fas fa-coins fa-2x" style="display:block;margin-bottom:8px;opacity:0.3;"></i>No deposit data yet.</div>';
  } else {
    document.getElementById('topDepositorsList').innerHTML = deps.map((u, i) =>
      buildRow(u, i,
        `<div style="text-align:right;flex-shrink:0;"><div style="font-size:18px;font-weight:800;color:#ef4444;">৳${parseFloat(u.amount).toFixed(0)}</div><div style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;">DEPOSIT</div></div>`,
        i === deps.length - 1)
    ).join('');
  }
};


window.logoutUser = async function() {
  await apiCall({ action:'logout' });
  window.currentUser = null;
  // লগআউটের পর বর্তমান Telegram ID দিয়ে নতুনভাবে লগইন করাও
  const freshTgUser = await getTelegramUser();
  if (freshTgUser) {
    const startParam = tgApp && tgApp.initDataUnsafe ? (tgApp.initDataUnsafe.start_param || '') : '';
    const loginRes = await apiCall({
      action:      'tg_login',
      tg_id:       String(freshTgUser.id),
      tg_name:     (freshTgUser.first_name || '') + (freshTgUser.last_name ? ' ' + freshTgUser.last_name : ''),
      tg_username: freshTgUser.username || '',
      tg_photo:    freshTgUser.photo_url || '',
      ref_code:    startParam
    });
    if (loginRes.success) {
      window.currentUser = loginRes;
      showApp(loginRes); loadSettings(); loadHistory();
      return;
    }
  }
  window.location.reload();
};
window.previewUpdateImage = function(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      const img = new Image(); img.src = e.target.result;
      img.onload = function() {
        const canvas = document.createElement('canvas'), ctx = canvas.getContext('2d');
        const maxSize = 300; let w = img.width, h = img.height;
        if (w > h) { if (w > maxSize) { h *= maxSize/w; w = maxSize; } } else { if (h > maxSize) { w *= maxSize/h; h = maxSize; } }
        canvas.width = w; canvas.height = h; ctx.drawImage(img, 0, 0, w, h);
        const dataUrl = canvas.toDataURL('image/jpeg', 0.7);
        document.getElementById('displayProfilePic').src = dataUrl;
        newUpdateImageBase64 = dataUrl;
        // ✅ FIX: Navbar এও real-time preview দেখাও
        const navPhotoEl = document.getElementById('navUserPhoto');
        if (navPhotoEl) navPhotoEl.src = dataUrl;
      };
    };
    reader.readAsDataURL(input.files[0]);
  }
};

// =============================================
// CATEGORY / SERVICE
// =============================================
window._currentCategory = 'All';

window.handleIconClick = function(c, b) {
  playSound('click');
  doVibrate([20]);
  document.querySelectorAll('.cat-card').forEach(x => {
    x.classList.remove('active');
    const box = x.querySelector('.all-inner-box');
    if (box) box.style.borderColor = '#d1d5db';
  });
  if (b) {
    b.classList.add('active');
    const box = b.querySelector('.all-inner-box');
    if (box) box.style.borderColor = '#111';
  }
  window._currentCategory = c;
  const svcSel  = document.getElementById('serviceSelect');
  const svcHint = document.getElementById('serviceHint');
  if (c && c !== 'All') {
    svcSel.disabled = false; svcHint.style.display = 'none';
    populateServiceDropdown(c);
  } else {
    svcSel.disabled = false; svcHint.style.display = 'none';
    populateServiceDropdown('All');
  }
  document.getElementById('descContainer').style.display = 'none';
};

window.manualCategoryChange = function() {
  // kept for compatibility — not used anymore
};

function populateServiceDropdown(category) {
  const svcSel = document.getElementById('serviceSelect');
  svcSel.innerHTML = '<option disabled selected>Choose Service</option>';
  const filtered = category === 'All' ? window.allServices : window.allServices.filter(x => x.cat === category);
  filtered.forEach(svc => {
    const opt = document.createElement('option');
    opt.value              = svc.rate;
    opt.dataset.id         = svc.id;
    opt.dataset.providerId = svc.providerId;
    opt.dataset.min        = svc.min;
    opt.dataset.max        = svc.max;
    opt.dataset.desc       = svc.desc;
    opt.text = `${svc.name} - ৳${parseFloat(svc.rate).toFixed(2)} per 1000`;
    svcSel.appendChild(opt);
  });
  calcPrice();
}

document.getElementById('serviceSelect').addEventListener('change', function() {
  const s = this.options[this.selectedIndex];
  if (this.disabled || this.selectedIndex === 0) return;
  document.getElementById('descContainer').style.display = 'block';
  document.getElementById('descText').innerHTML = `<b>Service ID:</b> <span onclick="copySvcId('${s.dataset.id}')" style="cursor:pointer;color:#1877f2;font-family:monospace;" title="Click to copy">${s.dataset.id} <i class="far fa-copy" style="font-size:11px;"></i></span><br><b>API ID:</b> ${s.dataset.providerId || 'N/A'}<br><b>Price per 1000:</b> ৳${parseFloat(s.value).toFixed(2)}<br><b>Description:</b> ${s.dataset.desc}`;
  document.getElementById('minMaxInfo').innerText = `Min: ${s.dataset.min} - Max: ${s.dataset.max}`;
  calcPrice();
});

window.calcPrice = function() {
  const q = document.getElementById('quantity').value, r = document.getElementById('serviceSelect').value;
  if (q > 0 && r > 0) document.getElementById('charge').value = '৳ ' + (r/1000*q).toFixed(2);
  else document.getElementById('charge').value = '৳ 0.00';
};

// =============================================
// TAB SWITCHING
// =============================================
window.switchTab = function(t, b) {
  playSound('tab');
  doVibrate([25]);
  document.querySelectorAll('.app-section').forEach(e => e.classList.remove('active'));
  document.getElementById(t + '-section').classList.add('active');
  window.scrollTo({ top:0, behavior:'instant' });
  if (b) { document.querySelectorAll('.nav-item').forEach(e => e.classList.remove('active')); b.classList.add('active'); }
  // Deposit page — hide navbar
  const navbar = document.querySelector('.navbar');
  if (t === 'addmoney') { if (navbar) { navbar.style.display='none'; } }
  else { if (navbar) { navbar.style.display=''; } }
  if (t === 'home')     resetHomeState();
  if (t === 'addmoney') { amShowPage('amHomePage'); amRenderHistory('all'); }
  if (t === 'history')  { loadHistory(); filterHistory('All'); }
  if (t === 'profile')  { document.querySelector('.nav-item:nth-child(6)').classList.add('active'); }
  if (t === 'referral') { loadReferralData(); }
  if (t === 'leaderboard') { loadLeaderboard(); }
  if (t === 'profile') {
    loadLocationStatus();
    // Sync theme buttons
    const savedTheme = localStorage.getItem('smmgem_theme') || 'light';
    document.querySelectorAll('.theme-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.theme === savedTheme);
    });
    const soundToggle = document.getElementById('soundToggle');
    const vibToggle   = document.getElementById('vibrationToggle');
    if (soundToggle) soundToggle.checked = soundEnabled;
    if (vibToggle)   vibToggle.checked   = vibrationEnabled;
  }
};

function resetHomeState() {
  document.getElementById('orderForm').reset();
  document.getElementById('searchInput').value = '';
  document.getElementById('quantity').value    = '100';
  document.getElementById('serviceSelect').disabled      = false;
  document.getElementById('serviceSelect').selectedIndex = 0;
  document.getElementById('serviceHint').style.display   = 'none';
  document.getElementById('descContainer').style.display = 'none';
  document.getElementById('minMaxInfo').innerText        = 'Min: 0 Max: 0';
  document.getElementById('charge').value                = '৳ 0.00';
  // Re-select "All" category
  const allCard = document.querySelector('.cat-card[data-cat="All"]');
  if (allCard) handleIconClick('All', allCard);
}

// =============================================
// UI HELPERS
// =============================================
window.toggleAuth = function(type) {
  // ✅ FIX: Bootstrap modal backdrop সরাও — নইলে registration form এ টাইপ করা যায় না
  try { modalEl.hide(); } catch(e) {}
  document.querySelectorAll('.modal-backdrop').forEach(function(el){ el.remove(); });
  document.body.classList.remove('modal-open');
  document.body.style.removeProperty('overflow');
  document.body.style.removeProperty('padding-right');

  document.querySelectorAll('.auth-input-group input').forEach(function(input){ input.value = ''; });
  document.getElementById('loginForm').style.display  = 'none';
  document.getElementById('signupForm').style.display = 'none';
  document.getElementById('forgotForm').style.display = 'none';
  if (type === 'login')       document.getElementById('loginForm').style.display  = 'block';
  else if (type === 'signup') document.getElementById('signupForm').style.display = 'block';
  else                        document.getElementById('forgotForm').style.display = 'block';

  // নতুন form এর প্রথম input এ auto-focus দাও
  setTimeout(function() {
    var formId = type === 'login' ? 'loginForm' : (type === 'signup' ? 'signupForm' : 'forgotForm');
    var firstInput = document.querySelector('#' + formId + ' input');
    if (firstInput) firstInput.focus();
  }, 80);
};

window.togglePass = function(fieldId, icon) {
  const input = document.getElementById(fieldId);
  if (input.type === 'password') { input.type='text'; icon.classList.replace('fa-eye','fa-eye-slash'); }
  else { input.type='password'; icon.classList.replace('fa-eye-slash','fa-eye'); }
};

window.showModal = function(t, m) {
  const i = document.getElementById('modalIcon'), h = document.getElementById('modalTitle');
  if (t==='success'){ i.innerHTML='<i class="fas fa-check-circle text-success"></i>'; h.innerText='Success'; }
  else if (t==='info'){ i.innerHTML='<i class="fas fa-info-circle text-primary"></i>'; h.innerText='Details'; }
  else if (t==='warning'){ i.innerHTML='<i class="fas fa-exclamation-triangle text-warning"></i>'; h.innerText='Warning'; }
  else { i.innerHTML='<i class="fas fa-times-circle text-danger"></i>'; h.innerText='Error'; }
  document.getElementById('modalMsg').innerHTML = m; modalEl.show();
};

window.showBalance = function() {
  if (isBalLoading) return; isBalLoading = true;
  document.getElementById('balText').innerText = 'Checking...'; document.getElementById('balIcon').className = 'fas fa-spinner fa-spin';
  apiCall({ action:'get_balance' }).then(res => {
    if (res.success) {
      window.currentBalance = parseFloat(res.balance);
      const homeBalEl = document.getElementById('homeBalanceDisplay');
      if (homeBalEl) homeBalEl.innerText = '৳' + window.currentBalance.toFixed(2);
      const mbal = document.getElementById('marqueeBalanceAmt');
      if (mbal) mbal.innerText = '৳' + window.currentBalance.toFixed(2);
    }
    document.getElementById('balText').innerText = `৳ ${window.currentBalance.toFixed(2)}`; document.getElementById('balIcon').className = 'fas fa-coins';
    setTimeout(() => { document.getElementById('balText').innerText='My Balance'; document.getElementById('balIcon').className='fas fa-wallet'; isBalLoading=false; }, 3000);
  });
};

// Also auto-refresh tg user photo if tgPhoto updated in session
function refreshNavUser() {
  if (!window.currentUser) return;
  const p = window.currentUser.tgPhoto || window.currentUser.profilePic || 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png';
  const n = window.currentUser.tgName  || window.currentUser.username   || 'User';
  const el = document.getElementById('navUserPhoto'); if (el) el.src = p;
  const nl = document.getElementById('navUserName');  if (nl) nl.innerText = n;
}

window.copyNumber = function() { navigator.clipboard.writeText(document.getElementById('adminNumber').innerText).then(() => showModal('success', 'Copied!')); };
window.copyUid = function(text) { const clean = text.replace('UID_','').trim(); navigator.clipboard.writeText(clean).then(() => showModal('success', 'User ID Copied!')); };
window.copySvcId = function(id) { navigator.clipboard.writeText(id).then(() => showModal('success', 'Service ID Copied: ' + id)); };

function fireConfetti() {
  const duration = 3*1000, animationEnd = Date.now()+duration, defaults = {startVelocity:30,spread:360,ticks:60,zIndex:10000};
  function randomInRange(min,max){return Math.random()*(max-min)+min;}
  const interval = setInterval(function(){
    const timeLeft = animationEnd-Date.now();
    if(timeLeft<=0)return clearInterval(interval);
    const particleCount=50*(timeLeft/duration);
    confetti(Object.assign({},defaults,{particleCount,origin:{x:randomInRange(0.1,0.3),y:Math.random()-0.2}}));
    confetti(Object.assign({},defaults,{particleCount,origin:{x:randomInRange(0.7,0.9),y:Math.random()-0.2}}));
  },250);
}

// =============================================
// AJAX HELPER
// =============================================
// =============================================
// THEME SYSTEM
// =============================================
const THEMES = ['light','dark','purple','blue','green'];

function setTheme(theme) {
  THEMES.forEach(t => document.body.classList.remove('theme-' + t));
  if (theme !== 'light') document.body.classList.add('theme-' + theme);
  localStorage.setItem('smmgem_theme', theme);
  // Update active button
  document.querySelectorAll('.theme-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.theme === theme);
  });
}

function initTheme() {
  const saved = localStorage.getItem('smmgem_theme') || 'light';
  setTheme(saved);
}

// =============================================
// SOUND EFFECTS (Web Audio API)
// =============================================
let soundEnabled = localStorage.getItem('smmgem_sound') !== 'false';
let vibrationEnabled = localStorage.getItem('smmgem_vibration') !== 'false';

let _audioCtx = null;
function getAudioCtx() {
  if (!_audioCtx) {
    try { _audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) {}
  }
  return _audioCtx;
}

function playSound(type = 'click') {
  if (!soundEnabled) return;
  const ctx = getAudioCtx();
  if (!ctx) return;
  try {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);
    if (type === 'click') {
      osc.type = 'sine';
      osc.frequency.setValueAtTime(880, ctx.currentTime);
      osc.frequency.exponentialRampToValueAtTime(440, ctx.currentTime + 0.08);
      gain.gain.setValueAtTime(0.15, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.1);
      osc.start(ctx.currentTime);
      osc.stop(ctx.currentTime + 0.1);
    } else if (type === 'tab') {
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(660, ctx.currentTime);
      osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.12);
      gain.gain.setValueAtTime(0.12, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15);
      osc.start(ctx.currentTime);
      osc.stop(ctx.currentTime + 0.15);
    } else if (type === 'success') {
      osc.type = 'sine';
      osc.frequency.setValueAtTime(523, ctx.currentTime);
      osc.frequency.setValueAtTime(659, ctx.currentTime + 0.1);
      osc.frequency.setValueAtTime(784, ctx.currentTime + 0.2);
      gain.gain.setValueAtTime(0.15, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
      osc.start(ctx.currentTime);
      osc.stop(ctx.currentTime + 0.35);
    } else if (type === 'error') {
      osc.type = 'square';
      osc.frequency.setValueAtTime(200, ctx.currentTime);
      osc.frequency.exponentialRampToValueAtTime(100, ctx.currentTime + 0.2);
      gain.gain.setValueAtTime(0.1, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.2);
      osc.start(ctx.currentTime);
      osc.stop(ctx.currentTime + 0.2);
    } else if (type === 'key') {
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(1400, ctx.currentTime);
      osc.frequency.exponentialRampToValueAtTime(900, ctx.currentTime + 0.035);
      gain.gain.setValueAtTime(0.055, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.04);
      osc.start(ctx.currentTime);
      osc.stop(ctx.currentTime + 0.04);
    }
  } catch(e) {}
}

let _lastKeyTime = 0;
document.addEventListener('keydown', function(e) {
  if (!soundEnabled) return;
  const skip = ['Shift','Control','Alt','Meta','CapsLock','Escape','ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Tab','F1','F2','F3','F4','F5','F6','F7','F8','F9','F10','F11','F12'];
  if (skip.includes(e.key)) return;
  const now = Date.now();
  if (now - _lastKeyTime < 30) return;
  _lastKeyTime = now;
  playSound('key');
}, { passive: true });

function doVibrate(pattern = [30]) {
  if (!vibrationEnabled) return;
  if (navigator.vibrate) navigator.vibrate(pattern);
}

function toggleSound(val) {
  soundEnabled = val;
  localStorage.setItem('smmgem_sound', val ? 'true' : 'false');
  if (val) playSound('success');
}

function toggleVibration(val) {
  vibrationEnabled = val;
  localStorage.setItem('smmgem_vibration', val ? 'true' : 'false');
  if (val) doVibrate([50, 30, 50]);
}

// Global click handler for sound + vibration
document.addEventListener('click', function(e) {
  const target = e.target.closest('button, .nav-item, .cat-card, .am-card, .am-nav-icon, .am-copy-btn, .am-qr-btn, [onclick]');
  if (target) {
    playSound('click');
    doVibrate([20]);
  }
}, { passive: true });

// =============================================
// LEADERBOARD — ALL LOGGED USERS TAB
// =============================================
window.switchLbTab = function(tab) {
  _lbActiveTab = tab;
  const refBtn   = document.getElementById('lbTabRef');
  const depBtn   = document.getElementById('lbTabDep');
  const usersBtn = document.getElementById('lbTabUsers');
  const refPanel   = document.getElementById('lbPanelRef');
  const depPanel   = document.getElementById('lbPanelDep');
  const usersPanel = document.getElementById('lbPanelUsers');

  playSound('tab');
  doVibrate([25]);

  const allBtns = [refBtn, depBtn, usersBtn];
  allBtns.forEach(b => { if(b){ b.style.background='transparent'; b.style.color='#94a3b8'; b.style.boxShadow='none'; } });
  [refPanel, depPanel, usersPanel].forEach(p => { if(p) p.style.display='none'; });

  if (tab === 'ref') {
    refBtn.style.background='white'; refBtn.style.color='#667eea'; refBtn.style.boxShadow='0 2px 8px rgba(0,0,0,0.1)';
    refPanel.style.display='block';
  } else if (tab === 'dep') {
    depBtn.style.background='white'; depBtn.style.color='#ef4444'; depBtn.style.boxShadow='0 2px 8px rgba(0,0,0,0.1)';
    depPanel.style.display='block';
  } else if (tab === 'users') {
    usersBtn.style.background='white'; usersBtn.style.color='#22c55e'; usersBtn.style.boxShadow='0 2px 8px rgba(0,0,0,0.1)';
    usersPanel.style.display='block';
    loadLoggedUsers();
  }
};

window.loadLoggedUsers = async function() {
  const podiumEl = document.getElementById('lbTop3Podium');
  const listEl   = document.getElementById('lbLoggedUsersList');
  if (!listEl) return;
  listEl.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x" style="display:block;margin-bottom:8px;"></i>Loading...</div>';
  if (podiumEl) podiumEl.innerHTML = '';

  const res = await apiCall({ action: 'get_logged_users' });
  if (!res.success) {
    listEl.innerHTML = '<div style="text-align:center;padding:30px;color:#ef4444;">ডেটা লোড হয়নি।</div>';
    return;
  }

  const defaultPic = 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png';
  const top3 = res.top3 || [];
  const all  = res.loggedUsers || [];

  // PODIUM — order: 2nd left, 1st center, 3rd right
  if (podiumEl && top3.length >= 1) {
    const podiumOrder = [top3[1], top3[0], top3[2]]; // 2nd, 1st, 3rd
    const positions = ['2nd', '1st', '3rd'];
    const crowns = ['🥈', '🥇', '🥉'];
    const borderColors = ['#a0a0a0', '#f6a623', '#cd7f32'];
    const baseClasses = ['lb-podium-2', 'lb-podium-1', 'lb-podium-3'];
    const heights = ['55', '70', '45'];

    let podiumHtml = '<div class="lb-podium">';
    [0,1,2].forEach(i => {
      const u = podiumOrder[i];
      if (!u) return;
      const name = u.tg_name || u.username || 'User';
      const photo = u.tg_photo || u.profile_pic || defaultPic;
      const orders = parseInt(u.total_orders) || 0;
      podiumHtml += `
        <div class="lb-podium-item ${baseClasses[i]}">
          <div class="lb-podium-crown">${crowns[i]}</div>
          <img src="${photo}" onerror="this.src='${defaultPic}'" class="lb-podium-img" style="border-color:${borderColors[i]};">
          <div class="lb-podium-name" style="color:var(--text-primary,#1e293b);">${name}</div>
          <div class="lb-podium-val" style="color:${borderColors[i]};">${orders} অর্ডার</div>
          <div class="lb-podium-base" style="height:${heights[i]}px;">${positions[i]}</div>
        </div>`;
    });
    podiumHtml += '</div>';
    podiumEl.innerHTML = podiumHtml;
  }

  // LIST of all logged users
  if (!all.length) {
    listEl.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">কোন ইউজার পাওয়া যায়নি।</div>';
    return;
  }

  listEl.innerHTML = all.map((u, i) => {
    const name   = u.tg_name || u.username || 'User';
    const photo  = u.tg_photo || u.profile_pic || defaultPic;
    const uname  = u.tg_username ? '@' + u.tg_username : '';
    const lastSeen = u.last_seen ? formatLastSeen(u.last_seen) : 'অজানা';
    return `<div style="display:flex;align-items:center;gap:10px;padding:11px 14px;border-bottom:1px solid var(--border-color,#f0f0f0);${i===all.length-1?'border-bottom:none;':''}">
      <div style="width:28px;height:28px;border-radius:50%;background:#e2e8f0;color:#64748b;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">${i+1}</div>
      <img src="${photo}" onerror="this.src='${defaultPic}'" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid var(--border-color,#e2e8f0);flex-shrink:0;">
      <div style="flex:1;min-width:0;">
        <div style="font-weight:700;font-size:13px;color:var(--text-primary,#1e293b);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${name}</div>
        <div style="font-size:11px;color:var(--text-secondary,#94a3b8);">${uname}</div>
      </div>
      <div style="text-align:right;flex-shrink:0;">
        <div style="font-size:10px;color:var(--text-secondary,#94a3b8);white-space:nowrap;"><i class="fas fa-clock me-1"></i>${lastSeen}</div>
      </div>
    </div>`;
  }).join('');
};

function formatLastSeen(dateStr) {
  const now  = new Date();
  const then = new Date(dateStr);
  const diff = Math.floor((now - then) / 1000);
  if (diff < 60) return 'এইমাত্র';
  if (diff < 3600) return Math.floor(diff/60) + ' মিনিট আগে';
  if (diff < 86400) return Math.floor(diff/3600) + ' ঘণ্টা আগে';
  return Math.floor(diff/86400) + ' দিন আগে';
}


// =============================================
// GOOGLE LOGIN
// =============================================
const GOOGLE_CLIENT_ID = '743562714695-6hq1slk4h5r5cs2nteonibonb165920v.apps.googleusercontent.com';

// ─── Google Login: OAuth2 Popup Flow ─────────────────────────────
// One Tap (google.accounts.id.prompt) কাজ করে না বেশিরভাগ ক্ষেত্রে।
// oauth2.initTokenClient → requestAccessToken() সত্যিকারের popup খোলে।
// PHP-এ access_token পাঠিয়ে Google userinfo API দিয়ে verify করা হয়।
// ──────────────────────────────────────────────────────────────────
let _gOrigBtnHTML = '';

function triggerGoogleLogin() {
  const btn = document.getElementById('googleSignInBtn');

  if (typeof google === 'undefined' || !google?.accounts?.oauth2) {
    // Library এখনো লোড হয়নি — ১.৫ সেকেন্ড পরে আবার চেষ্টা
    if (btn) btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> লোড হচ্ছে...';
    setTimeout(() => {
      if (btn) btn.innerHTML = _gOrigBtnHTML || btn.innerHTML;
      if (typeof google !== 'undefined' && google?.accounts?.oauth2) {
        triggerGoogleLogin();
      } else {
        showModal('error', 'Google Sign-In লোড ব্যর্থ। পেজ রিলোড করুন।');
      }
    }, 1500);
    return;
  }

  if (btn) {
    _gOrigBtnHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Google খুলছে...';
    btn.disabled  = true;
  }

  try {
    const client = google.accounts.oauth2.initTokenClient({
      client_id   : GOOGLE_CLIENT_ID,
      scope       : 'openid email profile',
      callback    : _gHandleToken,
      error_callback: (err) => {
        _gResetBtn();
        // popup_closed = ইউজার নিজে বন্ধ করেছে, error দেখানো দরকার নেই
        if (err?.type !== 'popup_closed') {
          showModal('error', 'Google লগইন বাতিল হয়েছে।');
        }
      }
    });
    // prompt:'select_account' → সবসময় account picker দেখাবে
    client.requestAccessToken({ prompt: 'select_account' });
  } catch(e) {
    _gResetBtn();
    showModal('error', 'Google Login শুরু করা যায়নি: ' + e.message);
  }
}

async function _gHandleToken(tokenResponse) {
  if (tokenResponse?.error) {
    _gResetBtn();
    if (tokenResponse.error !== 'access_denied') {
      showModal('error', 'Google Error: ' + (tokenResponse.error_description || tokenResponse.error));
    }
    return;
  }

  const btn = document.getElementById('googleSignInBtn');
  if (btn) {
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> লগইন হচ্ছে...';
    btn.disabled  = true;
  }

  // access_token → PHP → Google userinfo API দিয়ে verify → login/register
  const res = await apiCall({
    action      : 'google_login',
    access_token: tokenResponse.access_token
  });

  _gResetBtn();

  if (res.success) {
    window.currentUser = res;
    showApp(res); loadSettings(); loadHistory();
  } else {
    if (res.message === 'account_blocked') {
      Swal.fire({
        html            : `<h4 class="text-danger fw-bold">Account Blocked!</h4><p class="small text-muted">সাপোর্টে যোগাযোগ করুন।</p>`,
        confirmButtonColor: '#dc3545',
        customClass     : { popup: 'rounded-4' }
      });
    } else {
      showModal('error', res.message || 'Google Login ব্যর্থ হয়েছে।');
    }
  }
}

function _gResetBtn() {
  const btn = document.getElementById('googleSignInBtn');
  if (btn) {
    btn.innerHTML = _gOrigBtnHTML || btn.innerHTML;
    btn.disabled  = false;
    btn.style.opacity = '1';
  }
}

async function apiCall(data) {
  try {
    const formData = new FormData();
    Object.keys(data).forEach(k => formData.append(k, data[k]));
    const res = await fetch('index.php', { method:'POST', body: formData });
    return await res.json();
  } catch(e) { return { success:false, message:'Network error' }; }
}
// =============================================
// REFERRAL
// =============================================
window._referralCode = '';

window.loadReferralData = async function() {
  // Show loading
  document.getElementById('refUsersList').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x" style="margin-bottom:10px;display:block;"></i>লোড হচ্ছে...</div>';

  const res = await apiCall({ action: 'get_referral_stats' });
  if (!res.success) {
    document.getElementById('refUsersList').innerHTML = '<div style="text-align:center;padding:20px;color:#ef4444;">ডেটা লোড করতে সমস্যা হয়েছে।</div>';
    return;
  }

  window._referralCode = res.referralCode || '';

  // Build referral link using Telegram bot username
  const botUsername = 'SMMGemBot';
  const refLink = `https://t.me/${botUsername}/app?startapp=${res.referralCode}`;
  document.getElementById('refLinkBox').textContent = refLink;

  // Stats
  document.getElementById('refStatCount').textContent = res.referralsCount || 0;
  document.getElementById('refStatBonus').textContent = '৳' + parseFloat(res.totalReferralBonus || 0).toFixed(2);
  document.getElementById('refStatDeposits').textContent = '৳' + parseFloat(res.totalDepositsFromRefs || 0).toFixed(2);
  document.getElementById('refBonusPerRef').textContent = '৳' + parseFloat(res.referralBonusPerRef || 5).toFixed(0) + ' বোনাস প্রতি রেফারে';

  // Referred users list
  const list = res.referredUsers || [];
  if (!list.length) {
    document.getElementById('refUsersList').innerHTML = '<div style="text-align:center;padding:25px;color:#94a3b8;"><i class="fas fa-user-plus" style="font-size:32px;margin-bottom:10px;display:block;opacity:0.4;"></i><p style="font-size:14px;">এখনো কাউকে রেফার করা হয়নি।<br>আপনার লিংক শেয়ার করুন!</p></div>';
    return;
  }

  let html = '';
  list.forEach((u, i) => {
    const joined = u.joined ? new Date(u.joined).toLocaleDateString('en-BD', { day:'numeric', month:'short', year:'numeric' }) : '';
    const initials = (u.name || 'U').charAt(0).toUpperCase();
    html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid #f1f5f9;${i===list.length-1?'border-bottom:none;':''}">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;">${initials}</div>
        <div>
          <div style="font-weight:600;font-size:14px;color:#1e293b;">${u.name || 'User'}</div>
          <div style="font-size:11px;color:#94a3b8;">${joined}</div>
        </div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:13px;font-weight:700;color:#22c55e;">+৳${parseFloat(u.deposits||0).toFixed(2)}</div>
        <div style="font-size:10px;color:#94a3b8;">ডিপোজিট</div>
      </div>
    </div>`;
  });
  document.getElementById('refUsersList').innerHTML = html;
};

window.copyRefLink = function() {
  const refLink = document.getElementById('refLinkBox').textContent.trim();
  if (!refLink || refLink === 'লোড হচ্ছে...') { showModal('warning', 'লিংক লোড হয়নি, একটু অপেক্ষা করুন।'); return; }
  navigator.clipboard.writeText(refLink).then(() => {
    showModal('success', '✅ রেফারেল লিংক কপি হয়েছে!');
  }).catch(() => {
    const ta = document.createElement('textarea');
    ta.value = refLink;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    showModal('success', '✅ রেফারেল লিংক কপি হয়েছে!');
  });
};
</script>

<!-- Location Verification Popup removed -->

<!-- Location popup JS removed -->

<!-- Leaflet.js for map -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// ============================================================
// ALL USER LOCATION — Map + List
// ============================================================
let _aulMap      = null;
let _aulMarkers  = [];
let _aulLoaded   = false;

window.openAllUserLocations = function() {
  // Hide all sections, show alluserloc-section
  document.querySelectorAll('.app-section').forEach(s => s.classList.remove('active'));
  const sec = document.getElementById('alluserloc-section');
  if (sec) sec.classList.add('active');
  // Load data
  loadAllUserLocations();
};

window.aulRefreshMap = function() {
  _aulLoaded = false;
  // Reset lists
  document.getElementById('aulUserList').innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x" style="margin-bottom:10px;display:block;"></i>লোড হচ্ছে...</div>';
  document.getElementById('aulTotalCount').textContent  = '—';
  document.getElementById('aulCountryCount').textContent = '—';
  document.getElementById('aulDistrictCount').textContent = '—';
  if (_aulMap) { _aulMap.remove(); _aulMap = null; }
  document.getElementById('aulMapLoading').style.display = 'flex';
  loadAllUserLocations();
};

async function loadAllUserLocations() {
  if (_aulLoaded) return;
  _aulLoaded = true;
  try {
    const res = await apiCall({ action: 'get_all_locations' });
    if (!res || !res.success) {
      document.getElementById('aulUserList').innerHTML = '<div style="text-align:center;padding:30px;color:#ef4444;">ডেটা লোড করতে সমস্যা হয়েছে।</div>';
      return;
    }
    const locs = res.locations || [];
    const total = locs.length;

    // Stats
    const countries = [...new Set(locs.map(l => l.country).filter(Boolean))];
    const districts = [...new Set(locs.map(l => l.district).filter(Boolean))];
    document.getElementById('aulTotalCount').textContent   = total;
    document.getElementById('aulCountryCount').textContent  = countries.length;
    document.getElementById('aulDistrictCount').textContent = districts.length;
    document.getElementById('aulSubtitle').textContent = total + ' জন ইউজার ভেরিফাইড';

    // Init Map
    initAulMap(locs);

    // Render user list
    renderAulUserList(locs);
  } catch(e) {
    _aulLoaded = false;
    document.getElementById('aulUserList').innerHTML = '<div style="text-align:center;padding:30px;color:#ef4444;">Error: ' + e.message + '</div>';
  }
}

function initAulMap(locs) {
  const container = document.getElementById('aulMapContainer');
  const loading   = document.getElementById('aulMapLoading');

  if (_aulMap) { _aulMap.remove(); _aulMap = null; }

  if (locs.length === 0) {
    loading.innerHTML = '<div style="font-size:13px;color:#94a3b8;text-align:center;"><i class="fas fa-map-marked-alt fa-2x" style="color:#cbd5e1;display:block;margin-bottom:8px;"></i>কোনো ভেরিফাইড লোকেশন নেই</div>';
    return;
  }

  loading.style.display = 'none';

  // Center: average of all points
  const avgLat = locs.reduce((s, l) => s + l.lat, 0) / locs.length;
  const avgLng = locs.reduce((s, l) => s + l.lng, 0) / locs.length;

  _aulMap = L.map('aulMapContainer', { zoomControl: true, scrollWheelZoom: true }).setView([avgLat, avgLng], 5);

  // OpenStreetMap tiles (free, no API key)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 19
  }).addTo(_aulMap);

  // Custom marker icon
  function makeIcon(color) {
    return L.divIcon({
      className: '',
      html: `<div style="width:28px;height:28px;background:${color};border:3px solid white;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,0.35);display:flex;align-items:center;justify-content:center;font-size:12px;color:white;font-weight:700;">📍</div>`,
      iconSize: [28, 28],
      iconAnchor: [14, 28],
      popupAnchor: [0, -30]
    });
  }

  const colors = ['#0ea5e9','#22c55e','#f59e0b','#ef4444','#8b5cf6','#ec4899'];
  _aulMarkers = [];

  locs.forEach((loc, idx) => {
    if (!loc.lat || !loc.lng) return;
    const color   = colors[idx % colors.length];
    const initial = (loc.name || 'U')[0].toUpperCase();

    // Marker: profile photo থাকলে ছবি, না থাকলে initial
    const markerInner = loc.photo
      ? `<img src="${loc.photo}" style="width:36px;height:36px;border-radius:50%;object-fit:cover;" onerror="this.outerHTML='<div style=width:36px;height:36px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:white;>${initial}</div>'">`
      : `<div style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:white;">${initial}</div>`;

    const icon = L.divIcon({
      className: '',
      html: `<div style="position:relative;display:inline-block;"><div style="width:40px;height:40px;border-radius:50%;border:3px solid ${color};background:${color};overflow:hidden;box-shadow:0 3px 12px rgba(0,0,0,0.35);">${markerInner}</div><div style="position:absolute;bottom:-7px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:7px solid transparent;border-right:7px solid transparent;border-top:8px solid ${color};"></div></div>`,
      iconSize:    [40, 48],
      iconAnchor:  [20, 48],
      popupAnchor: [0, -50]
    });

    const tgBtn = loc.tg_username
      ? `<a href="https://t.me/${loc.tg_username}" target="_blank"
            style="display:inline-block;background:linear-gradient(135deg,#229ED9,#0088cc);color:white;font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;text-decoration:none;margin-top:4px;width:100%;box-sizing:border-box;text-align:center;">
           <svg style="width:12px;height:12px;vertical-align:middle;margin-right:3px;" viewBox="0 0 24 24" fill="white"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.562 8.248-2.04 9.613c-.154.677-.554.843-1.123.524l-3.1-2.284-1.496 1.44c-.165.165-.303.303-.622.303l.222-3.164 5.76-5.202c.25-.222-.054-.346-.388-.124L7.08 14.338l-3.064-.957c-.666-.208-.68-.666.14-.986l11.97-4.614c.554-.203 1.038.124.436.467z"/></svg>
           Telegram খুলুন
         </a>`
      : `<div style="font-size:10px;color:#94a3b8;margin-top:4px;text-align:center;">Telegram লিংক নেই</div>`;

    const popupHtml = `
      <div style="min-width:180px;font-family:sans-serif;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          ${loc.photo
            ? `<img src="${loc.photo}" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid #0ea5e9;" onerror="this.style.display='none'">`
            : `<div style="width:36px;height:36px;border-radius:50%;background:${color};display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:white;">${initial}</div>`}
          <div>
            <div style="font-weight:700;font-size:13px;color:#1e293b;">${loc.name || 'User'}</div>
            ${loc.tg_username ? `<div style="font-size:10px;color:#229ED9;">@${loc.tg_username}</div>` : ''}
          </div>
        </div>
        <div style="font-size:11px;color:#555;margin-bottom:2px;">🌍 ${loc.country || 'অজানা'}</div>
        <div style="font-size:11px;color:#555;margin-bottom:2px;">🏙️ ${loc.district || 'অজানা'}</div>
        <div style="font-size:11px;color:#555;margin-bottom:6px;">📍 ${loc.thana || 'অজানা'}</div>
        <a href="https://www.google.com/maps?q=${loc.lat},${loc.lng}" target="_blank"
           style="display:inline-block;background:#0ea5e9;color:white;font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;text-decoration:none;width:100%;box-sizing:border-box;text-align:center;">
          🗺️ Google Maps এ দেখুন ↗
        </a>
        ${tgBtn}
      </div>`;

    const marker = L.marker([loc.lat, loc.lng], { icon })
      .addTo(_aulMap)
      .bindPopup(popupHtml, { maxWidth: 220 });
    _aulMarkers.push({ marker, loc });
  });

  // Fit all markers in view
  if (_aulMarkers.length > 0) {
    const group = L.featureGroup(_aulMarkers.map(m => m.marker));
    _aulMap.fitBounds(group.getBounds().pad(0.15));
  }
}

function renderAulUserList(locs) {
  const el = document.getElementById('aulUserList');
  if (!locs.length) {
    el.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-map-marker-alt fa-2x" style="display:block;margin-bottom:8px;color:#cbd5e1;"></i>এখনো কোনো ইউজার লোকেশন ভেরিফাই করেননি।</div>';
    return;
  }
  let html = '';
  locs.forEach((u, idx) => {
    const initial = (u.name || 'U')[0].toUpperCase();
    const colors  = ['#0ea5e9','#22c55e','#f59e0b','#ef4444','#8b5cf6','#ec4899'];
    const color   = colors[idx % colors.length];
    const gmLink  = `https://www.google.com/maps?q=${u.lat},${u.lng}`;
    const tgLink  = u.tg_username ? `https://t.me/${u.tg_username}` : '';

    const avatarHtml = u.photo
      ? `<img src="${u.photo}" style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid ${color};flex-shrink:0;" onerror="this.outerHTML='<div style=width:44px;height:44px;border-radius:50%;background:${color};color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:17px;flex-shrink:0;>${initial}</div>'">`
      : `<div style="width:44px;height:44px;border-radius:50%;background:${color};color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:17px;flex-shrink:0;">${initial}</div>`;

    html += `
    <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border-color,#f1f5f9);cursor:pointer;"
         onclick="aulFocusMarker(${idx})" title="ম্যাপে দেখুন">
      ${avatarHtml}
      <div style="flex:1;min-width:0;">
        <div style="font-weight:700;font-size:13px;color:var(--text-primary,#1e293b);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${u.name || 'User'}</div>
        ${u.tg_username ? `<div style="font-size:10px;color:#229ED9;margin-bottom:1px;">@${u.tg_username}</div>` : ''}
        <div style="font-size:11px;color:#94a3b8;margin-top:1px;">🌍 ${u.country || '—'} &nbsp;|&nbsp; 🏙️ ${u.district || '—'} &nbsp;|&nbsp; 📍 ${u.thana || '—'}</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0;">
        <a href="${gmLink}" target="_blank" onclick="event.stopPropagation()"
           style="background:linear-gradient(135deg,#0ea5e9,#2563eb);color:white;font-size:10px;font-weight:700;padding:5px 9px;border-radius:8px;text-decoration:none;white-space:nowrap;text-align:center;">
          <i class="fas fa-map-marker-alt me-1"></i>Map
        </a>
        ${tgLink ? `<a href="${tgLink}" target="_blank" onclick="event.stopPropagation()"
           style="background:linear-gradient(135deg,#229ED9,#0088cc);color:white;font-size:10px;font-weight:700;padding:5px 9px;border-radius:8px;text-decoration:none;white-space:nowrap;text-align:center;">
          <svg style="width:11px;height:11px;vertical-align:middle;margin-right:2px;" viewBox="0 0 24 24" fill="white"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.562 8.248-2.04 9.613c-.154.677-.554.843-1.123.524l-3.1-2.284-1.496 1.44c-.165.165-.303.303-.622.303l.222-3.164 5.76-5.202c.25-.222-.054-.346-.388-.124L7.08 14.338l-3.064-.957c-.666-.208-.68-.666.14-.986l11.97-4.614c.554-.203 1.038.124.436.467z"/></svg>
          Telegram
        </a>` : `<div style="font-size:9px;color:#cbd5e1;text-align:center;padding:3px 0;">No TG</div>`}
      </div>
    </div>`;
  });
  el.innerHTML = html;
}

window.aulFocusMarker = function(idx) {
  if (!_aulMap || !_aulMarkers[idx]) return;
  const { marker, loc } = _aulMarkers[idx];
  _aulMap.flyTo([loc.lat, loc.lng], 13, { duration: 1.2 });
  marker.openPopup();
  // Scroll map into view
  document.getElementById('aulMapContainer').scrollIntoView({ behavior: 'smooth', block: 'center' });
};
</script>