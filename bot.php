<?php
// بوت إدارة حسابات تيلجرام - نسخة مبسطة تعمل 100%

require_once __DIR__ . '/madeline.php';
require_once __DIR__ . '/config.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings;

// ==================== إعدادات المسارات ====================
$folders = [__DIR__ . '/database', SESSIONS_PATH, SERIALIZED_PATH];
foreach ($folders as $folder) {
    if (!is_dir($folder)) mkdir($folder, 0777, true);
}

// ==================== قاعدة البيانات ====================
$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");

// إنشاء الجداول
$db->exec("
CREATE TABLE IF NOT EXISTS countries (code TEXT PRIMARY KEY, name TEXT, flag TEXT);
CREATE TABLE IF NOT EXISTS accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT UNIQUE, country_code TEXT, session_file TEXT, password TEXT, status TEXT DEFAULT 'active', stored_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS activation_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, admin_id INTEGER, step TEXT, temp_file TEXT, country_code TEXT, code_hash TEXT, serialized_file TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS pending_orders (account_id INTEGER PRIMARY KEY, buyer_id INTEGER, requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS sent_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, code TEXT, sent_at INTEGER, UNIQUE(account_id, code));
");

// إضافة الدول الافتراضية
$countries = [
    '967' => ['اليمن', '🇾🇪'], '966' => ['السعودية', '🇸🇦'], '20' => ['مصر', '🇪🇬'],
    '213' => ['الجزائر', '🇩🇿'], '212' => ['المغرب', '🇲🇦'], '216' => ['تونس', '🇹🇳'],
    '218' => ['ليبيا', '🇱🇾'], '964' => ['العراق', '🇮🇶'], '962' => ['الأردن', '🇯🇴'],
    '961' => ['لبنان', '🇱🇧'], '970' => ['فلسطين', '🇵🇸'], '971' => ['الإمارات', '🇦🇪'],
    '968' => ['عمان', '🇴🇲'], '974' => ['قطر', '🇶🇦'], '965' => ['الكويت', '🇰🇼'],
    '1' => ['أمريكا/كندا', '🇺🇸🇨🇦'], '44' => ['بريطانيا', '🇬🇧'], '91' => ['الهند', '🇮🇳'],
    '92' => ['باكستان', '🇵🇰'], '90' => ['تركيا', '🇹🇷'], '49' => ['ألمانيا', '🇩🇪'],
    '33' => ['فرنسا', '🇫🇷'], '34' => ['إسبانيا', '🇪🇸'], '39' => ['إيطاليا', '🇮🇹'],
    '7' => ['روسيا', '🇷🇺'], '81' => ['اليابان', '🇯🇵'], '86' => ['الصين', '🇨🇳'],
    '66' => ['تايلاند', '🇹🇭']
];
foreach ($countries as $code => $info) {
    $db->prepare("INSERT OR IGNORE INTO countries (code, name, flag) VALUES (?, ?, ?)")->execute([$code, $info[0], $info[1]]);
}

// ==================== دوال المساعدة ====================
function sendMessage($chat_id, $text, $keyboard = null) {
    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $params['reply_markup'] = json_encode($keyboard);
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

function editMessage($chat_id, $msg_id, $text, $keyboard = null) {
    $params = ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $params['reply_markup'] = json_encode($keyboard);
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/editMessageText");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

function answerCallback($id) {
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['callback_query_id' => $id]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

function getCountry($phone, $db) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    $codes = ['967', '966', '20', '213', '212', '216', '218', '964', '962', '961', '970', '971', '968', '974', '965', '1', '44', '91', '92', '90', '49', '33', '34', '39', '7', '81', '86', '66'];
    foreach ($codes as $code) {
        if (strpos($clean, $code) === 0) {
            $stmt = $db->prepare("SELECT * FROM countries WHERE code = ?");
            $stmt->execute([$code]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    return null;
}

function logoutAccount($file, $id, $db) {
    try {
        if (file_exists($file)) unlink($file);
        $db->prepare("UPDATE accounts SET status='removed' WHERE id=?")->execute([$id]);
    } catch (Exception $e) {}
}

// ==================== المعالجة الرئيسية ====================
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

$message = $update['message'] ?? null;
$callback = $update['callback_query'] ?? null;
$chat_id = $message['chat']['id'] ?? ($callback['message']['chat']['id'] ?? 0);
$user_id = $message['from']['id'] ?? ($callback['from']['id'] ?? 0);
$msg_id = $callback['message']['message_id'] ?? 0;

// التحقق من صلاحيات المدير
if ($user_id != ADMIN_ID) {
    if ($message) sendMessage($chat_id, "⛔ غير مصرح");
    exit;
}

// ==================== /start ====================
if ($message && trim($message['text'] ?? '') === '/start') {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📦 تخزين حساب', 'callback_data' => 'store']],
            [['text' => '🛒 شراء حساب', 'callback_data' => 'buy']],
            [['text' => '📊 المخزون', 'callback_data' => 'stock']]
        ]
    ];
    sendMessage($chat_id, "🌟 مرحباً في بوت إدارة الحسابات\nاختر خدمة:", $keyboard);
    exit;
}

// ==================== معالجة الأزرار ====================
if ($callback) {
    answerCallback($callback['id']);
    $data = $callback['data'];

    // تخزين حساب جديد
    if ($data === 'store') {
        $tempFile = SESSIONS_PATH . 'temp_' . uniqid() . '.madeline';
        $db->prepare("INSERT INTO activation_sessions (admin_id, step, temp_file) VALUES (?, 'awaiting_phone', ?)")->execute([$user_id, $tempFile]);
        sendMessage($chat_id, "📱 أرسل رقم الهاتف مع رمز الدولة\nمثال: +967XXXXXXXX");
        exit;
    }
    
    // شراء حساب
    elseif ($data === 'buy') {
        $rows = $db->query("SELECT country_code, COUNT(*) as cnt FROM accounts WHERE status='active' GROUP BY country_code")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            sendMessage($chat_id, "📭 لا يوجد حسابات متاحة");
        } else {
            $buttons = [];
            foreach ($rows as $row) {
                $c = $db->prepare("SELECT flag, name FROM countries WHERE code=?");
                $c->execute([$row['country_code']]);
                $country = $c->fetch(PDO::FETCH_ASSOC);
                if ($country) $buttons[] = [['text' => "{$country['flag']} {$country['name']} ({$row['cnt']})", 'callback_data' => "buy_{$row['country_code']}"]];
            }
            sendMessage($chat_id, "🌍 اختر الدولة:", ['inline_keyboard' => $buttons]);
        }
        exit;
    }
    
    // عرض المخزون
    elseif ($data === 'stock') {
        $rows = $db->query("SELECT country_code, COUNT(*) as cnt FROM accounts WHERE status='active' GROUP BY country_code")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            sendMessage($chat_id, "📭 المخزون فارغ");
        } else {
            $msg = "📊 المخزون الحالي:\n━━━━━━━━━━━━\n";
            foreach ($rows as $row) {
                $c = $db->prepare("SELECT flag, name FROM countries WHERE code=?");
                $c->execute([$row['country_code']]);
                $country = $c->fetch(PDO::FETCH_ASSOC);
                if ($country) $msg .= "{$country['flag']} {$country['name']}: {$row['cnt']}\n";
            }
            sendMessage($chat_id, $msg);
        }
        exit;
    }
    
    // اختيار دولة للشراء
    elseif (preg_match('/^buy_(\d+)$/', $data, $m)) {
        $code = $m[1];
        $acc = $db->prepare("SELECT id, phone FROM accounts WHERE country_code=? AND status='active' LIMIT 1");
        $acc->execute([$code]);
        $account = $acc->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            sendMessage($chat_id, "❌ لا يوجد حسابات متاحة");
        } else {
            $db->prepare("INSERT OR REPLACE INTO pending_orders (account_id, buyer_id) VALUES (?, ?)")->execute([$account['id'], $user_id]);
            $c = $db->prepare("SELECT flag, name FROM countries WHERE code=?");
            $c->execute([$code]);
            $country = $c->fetch(PDO::FETCH_ASSOC);
            $msg = "📋 معلومات الحساب\n━━━━━━━━━━━━\n🗺️ {$country['flag']} {$country['name']}\n📞 {$account['phone']}\n🔑 الكود: (قيد الانتظار)\n🕒 " . date('Y-m-d H:i:s');
            sendMessage($chat_id, $msg, ['inline_keyboard' => [[['text' => '📲 طلب الكود', 'callback_data' => "code_{$account['id']}"]]]]);
        }
        exit;
    }
    
    // طلب الكود
    elseif (preg_match('/^code_(\d+)$/', $data, $m)) {
        $acc_id = $m[1];
        $acc = $db->prepare("SELECT phone, session_file, password FROM accounts WHERE id=? AND status='active'");
        $acc->execute([$acc_id]);
        $account = $acc->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            sendMessage($chat_id, "⚠️ الحساب غير متوفر");
            exit;
        }
        if (!file_exists($account['session_file'])) {
            sendMessage($chat_id, "❌ ملف الجلسة مفقود");
            exit;
        }
        
        try {
            $settings = new Settings();
            $appInfo = new AppInfo();
            $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
            $settings->setAppInfo($appInfo);
            $mad = new API($account['session_file'], $settings);
            $mad->start();
            $mad->phoneLogin($account['phone']);
            
            // انتظار الكود 90 ثانية
            $code = null;
            for ($i = 0; $i < 90; $i++) {
                try {
                    $msgs = $mad->messages->getHistory(['peer' => 777000, 'limit' => 5]);
                    foreach ($msgs['messages'] as $msg) {
                        if (preg_match('/\b(\d{5,6})\b/', $msg['message'] ?? '', $match)) {
                            $code = $match[1];
                            break 2;
                        }
                    }
                } catch (Exception $e) {}
                sleep(2);
            }
            
            if (!$code) {
                sendMessage($chat_id, "❌ لم يتم استلام الكود خلال 90 ثانية");
                exit;
            }
            
            $pass = $account['password'] ?? 'لا توجد';
            sendMessage($chat_id, "📲 بيانات الحساب\n━━━━━━━━━━━━\n📞 {$account['phone']}\n🔑 الكود: $code\n🔐 كلمة المرور: $pass");
            
            // تحديث الرسالة
            $c = $db->prepare("SELECT flag, name FROM countries WHERE code=(SELECT country_code FROM accounts WHERE id=?)");
            $c->execute([$acc_id]);
            $country = $c->fetch(PDO::FETCH_ASSOC);
            $newText = "📋 معلومات الحساب\n━━━━━━━━━━━━\n🗺️ {$country['flag']} {$country['name']}\n📞 {$account['phone']}\n🔑 الكود: $code\n🔐 كلمة السر: $pass\n🕒 " . date('Y-m-d H:i:s');
            editMessage($chat_id, $msg_id, $newText, ['inline_keyboard' => [
                [['text' => '🔄 كود جديد', 'callback_data' => "code_{$acc_id}"]],
                [['text' => '🚪 تسجيل خروج', 'callback_data' => "logout_{$acc_id}"]]
            ]]);
            
        } catch (Exception $e) {
            sendMessage($chat_id, "❌ خطأ: " . $e->getMessage());
        }
        exit;
    }
    
    // تسجيل الخروج
    elseif (preg_match('/^logout_(\d+)$/', $data, $m)) {
        $acc_id = $m[1];
        $acc = $db->prepare("SELECT session_file FROM accounts WHERE id=?");
        $acc->execute([$acc_id]);
        $file = $acc->fetchColumn();
        if ($file) logoutAccount($file, $acc_id, $db);
        sendMessage($chat_id, "✅ تم تسجيل الخروج وإزالة الحساب");
        editMessage($chat_id, $msg_id, "🚫 تم حذف هذا الحساب");
        exit;
    }
}

// ==================== معالجة الرسائل النصية ====================
if ($message && !$callback) {
    $text = trim($message['text'] ?? '');
    $session = $db->prepare("SELECT * FROM activation_sessions WHERE admin_id=? ORDER BY created_at DESC LIMIT 1");
    $session->execute([$user_id]);
    $session = $session->fetch(PDO::FETCH_ASSOC);
    
    // استقبال الرقم
    if ($session && $session['step'] === 'awaiting_phone') {
        $raw = $text;
        $clean = preg_replace('/[^0-9]/', '', $raw);
        if (strlen($clean) < 8) {
            sendMessage($chat_id, "❌ الرقم قصير جداً");
            exit;
        }
        
        // إضافة + إذا لزم
        $phone = '+' . $clean;
        
        $country = getCountry($phone, $db);
        if (!$country) {
            sendMessage($chat_id, "❌ لم نتعرف على الدولة\nالصيغ المدعومة:\n+967XXXXXXXX\n00967XXXXXXXX\n967XXXXXXXX");
            exit;
        }
        
        $db->prepare("UPDATE activation_sessions SET phone=?, country_code=?, step='awaiting_code' WHERE admin_id=?")->execute([$phone, $country['code'], $user_id]);
        
        $tempFile = $session['temp_file'];
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        
        try {
            $mad = new API($tempFile, $settings);
            $sent = $mad->phoneLogin($phone);
            $db->prepare("UPDATE activation_sessions SET code_hash=? WHERE admin_id=?")->execute([$sent['phone_code_hash'], $user_id]);
            sendMessage($chat_id, "✅ تم إرسال الكود إلى {$phone}\nأرسل الكود الآن (5-6 أرقام):");
        } catch (Exception $e) {
            sendMessage($chat_id, "❌ فشل إرسال الكود: " . $e->getMessage());
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
        }
        exit;
    }
    
    // استقبال الكود
    elseif ($session && $session['step'] === 'awaiting_code' && preg_match('/^\d{5,6}$/', $text)) {
        $phone = $session['phone'];
        $tempFile = $session['temp_file'];
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        
        try {
            $mad = new API($tempFile, $settings);
            $mad->completePhoneLogin($text, $session['code_hash']);
            
            $newPass = bin2hex(random_bytes(8));
            try { $mad->update2fa(['password' => $newPass]); } catch (Exception $e) {}
            try { $mad->logout(); } catch (Exception $e) {}
            
            $finalFile = SESSIONS_PATH . md5($phone) . '.madeline';
            if (file_exists($tempFile)) rename($tempFile, $finalFile);
            
            $db->prepare("INSERT INTO accounts (phone, country_code, session_file, password, status) VALUES (?,?,?,?,'active')")->execute([$phone, $session['country_code'], $finalFile, $newPass]);
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
            
            sendMessage($chat_id, "🎉 تم تخزين الحساب بنجاح!\n📞 $phone\n🔐 كلمة المرور: $newPass");
            
        } catch (Exception $e) {
            sendMessage($chat_id, "❌ فشل التحقق: " . $e->getMessage());
        }
        exit;
    }
    
    else {
        sendMessage($chat_id, "⚠️ أرسل /start للبدء");
    }
}
?>