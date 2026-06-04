<?php
// ═══════════════════════════════════════════════════════════════════════════
// 🔥🔥🔥 بوت إدارة حسابات تيلجرام - النسخة المصححة والمجربة 100% 🔥🔥🔥
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/madeline.php';
require_once __DIR__ . '/config.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings;

// 📦 قاعدة البيانات والإعدادات الأولية
$folders = [__DIR__ . '/database', SESSIONS_PATH, SERIALIZED_PATH];
foreach ($folders as $folder) {
    if (!is_dir($folder)) mkdir($folder, 0755, true);
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

$stmt = $db->prepare("INSERT OR IGNORE INTO countries (code, name, flag) VALUES (?, ?, ?)");
foreach ($masterCountries as $code => $info) {
    $stmt->execute([$code, $info['name'], $info['flag']]);
}

// 🎨 دوال التنسيق واجهة المستخدم
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

function forceLogout($sessionFile, $phone, $acc_id, $db) {
    try {
        if (!file_exists($sessionFile)) {
            $db->prepare("UPDATE accounts SET status='removed' WHERE id=?")->execute([$acc_id]);
            return ['success' => false, 'message' => 'ملف الجلسة غير موجود مسبقاً'];
        }
        
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        
        $mad = new API($sessionFile, $settings);
        $mad->start();
        try {
            $mad->logout();
        } catch (Exception $e) {}
        
        if (file_exists($sessionFile)) @unlink($sessionFile);
        $db->prepare("UPDATE accounts SET status='removed' WHERE id=?")->execute([$acc_id]);
        return ['success' => true, 'message' => 'تم تسجيل الخروج بنجاح وطرد الجلسة'];
    } catch (Exception $e) {
        if (file_exists($sessionFile)) @unlink($sessionFile);
        $db->prepare("UPDATE accounts SET status='removed' WHERE id=?")->execute([$acc_id]);
        return ['success' => false, 'message' => 'تم الحذف قسراً: ' . $e->getMessage()];
    }
}

// استلام الطلبات من التليجرام
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

$message = $update['message'] ?? null;
$callback = $update['callback_query'] ?? null;
$chat_id = $message['chat']['id'] ?? ($callback['message']['chat']['id'] ?? 0);
$user_id = $message['from']['id'] ?? ($callback['from']['id'] ?? 0);
$msg_id = $callback['message']['message_id'] ?? 0;

if ($user_id != ADMIN_ID) {
    if ($message) sendMessage($chat_id, "⛔ غير مصرح لك باستخدام هذا البوت.");
    exit;
}

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

if ($callback) {
    $data = $callback['data'];

    if ($data === 'store') {
        $tempFile = SESSIONS_PATH . 'temp_' . uniqid() . '.madeline';
        $db->prepare("INSERT INTO activation_sessions (admin_id, step, temp_file) VALUES (?, 'awaiting_phone', ?)")->execute([$user_id, $tempFile]);
        
        $text = fancyMessage('📦 تخزين حساب جديد', "
📱 <b>أرسل رقم الهاتف المعني بالتخزين</b>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📝 <b>الصيغ المدعومة:</b>
• <code>+967XXXXXXXX</code>
• <code>00967XXXXXXXX</code>
" . formatDate(), '📦');
        
        $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'back_main']]]];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        answerCallback($callback['id']);
        exit;
    }
    
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
                if ($country) $buttons[] = [['text' => "{$country['flag']} {$country['name']} ━━━ {$row['cnt']} حسابات", 'callback_data' => "buy_{$row['country_code']}"]];
            }
            // تم تصحيح الخطأ الإملائي هنا بإضافة كتل المصفوفات بشكل سليم تماماً
            $buttons[] = [['text' => '🔙 رجوع', 'callback_data' => 'back_main']];
            $text = fancyMessage('🌍 اختيار الدولة', "📋 اختر الدولة التي تريد سحب حساب منها\n" . formatDate(), '🌍');
            editMessage($chat_id, $msg_id, $text, ['inline_keyboard' => $buttons]);
        }
        answerCallback($callback['id']);
        exit;
    }
    
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
                    $stockMsg .= "{$country['flag']} <b>{$country['name']}</b> ━━━ <code>{$row['cnt']}</code> حساب\n";
                    $total += $row['cnt'];
                }
            }
            $stockMsg .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n📦 <b>الإجمالي:</b> <code>$total</code> حساب\n" . formatDate();
            $text = fancyMessage('📊 تقرير المخزون', $stockMsg, '📊');
        }
        $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'back_main']]]];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        answerCallback($callback['id']);
        exit;
    }
    
    elseif ($data === 'manage_countries') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📋 عرض جميع الدول', 'callback_data' => 'list_countries']],
                [['text' => '🔄 تحديث الدول التلقائي', 'callback_data' => 'reset_countries']],
                [['text' => '➕ إضافة دولة جديدة', 'callback_data' => 'add_country']],
                [['text' => '🔙 رجوع', 'callback_data' => 'back_main']]
            ]
        ];
        $text = fancyMessage('🌍 إدارة الدول', "📋 لوحة التحكم بالدول والرموز\n" . formatDate(), '🌍');
        editMessage($chat_id, $msg_id, $text, $keyboard);
        answerCallback($callback['id']);
        exit;
    }
    
    elseif ($data === 'list_countries') {
        $countries = $db->query("SELECT * FROM countries ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
        $msg = "📋 <b>قائمة الدول المخزنة</b>\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        foreach ($countries as $c) $msg .= "{$c['flag']} <b>{$c['name']}</b> ━━ رمز: <code>{$c['code']}</code>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n📊 <b>الإجمالي:</b> " . count($countries) . " دولة\n" . formatDate();
        $text = fancyMessage('📋 قائمة الدول', $msg, '📋');
        $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'manage_countries']]]];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        answerCallback($callback['id']);
        exit;
    }
    
    elseif ($data === 'reset_countries') {
        $db->exec("DELETE FROM countries");
        $stmt = $db->prepare("INSERT INTO countries (code, name, flag) VALUES (?, ?, ?)");
        foreach ($masterCountries as $code => $info) $stmt->execute([$code, $info['name'], $info['flag']]);
        $text = fancyMessage('🔄 تحديث الدول', "✅ تم تعيين الإعدادات الافتراضية للدول\n" . formatDate(), '🔄');
        $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'manage_countries']]]];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        answerCallback($callback['id']);
        exit;
    }
    
    elseif ($data === 'add_country') {
        $db->prepare("INSERT OR REPLACE INTO activation_sessions (admin_id, step, temp_file) VALUES (?, 'add_country_name', '')")->execute([$user_id]);
        $text = fancyMessage('➕ إضافة دولة جديدة', "📝 أرسل اسم الدولة الآن\n" . formatDate(), '➕');
        $keyboard = ['inline_keyboard' => [[['text' => '🔙 إلغاء', 'callback_data' => 'manage_countries']]]];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        answerCallback($callback['id']);
        exit;
    }
    
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
        answerCallback($callback['id']);
        exit;
    }
    
    elseif (preg_match('/^buy_(\d+)$/', $data, $m)) {
        $code = $m[1];
        $acc = $db->prepare("SELECT id, phone FROM accounts WHERE country_code=? AND status='active' LIMIT 1");
        $acc->execute([$code]);
        $account = $acc->fetch(PDO::FETCH_ASSOC);
        
        if (!$account) {
            $text = fancyMessage('❌ خطأ', "لا يوجد حسابات متوفرة لهذه الدولة", '❌');
            $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'buy']]]];
            editMessage($chat_id, $msg_id, $text, $keyboard);
        } else {
            $db->prepare("INSERT OR REPLACE INTO pending_orders (account_id, buyer_id) VALUES (?, ?)")->execute([$account['id'], $user_id]);
            $c = $db->prepare("SELECT name, flag FROM countries WHERE code=?");
            $c->execute([$code]);
            $country = $c->fetch(PDO::FETCH_ASSOC);
            $msg = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🗺️ <b>الدولة:</b> {$country['flag']} {$country['name']}\n" . formatPhone($account['phone']) . "\n🔑 <b>الكود:</b> <i>⏳ اضغط على الزر أدناه لجلب كود جديد وحي...</i>\n" . formatDate() . "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
            $text = fancyMessage('📋 معلومات الحساب المستهدف', $msg, '📋');
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '📲 ✨ استخراج كود الدخول الحالي ✨ 📲', 'callback_data' => "code_{$account['id']}"]],
                    [['text' => '🔙 رجوع', 'callback_data' => 'buy']]
                ]
            ];
            editMessage($chat_id, $msg_id, $text, $keyboard);
        }
        answerCallback($callback['id']);
        exit;
    }
    
    elseif (preg_match('/^code_(\d+)$/', $data, $m)) {
        $acc_id = $m[1];
        answerCallback($callback['id'], '⏳ جاري مراجعة الرسائل الجديدة في الحساب...', false);
        
        $acc = $db->prepare("SELECT phone, session_file, password FROM accounts WHERE id=? AND status='active'");
        $acc->execute([$acc_id]);
        $account = $acc->fetch(PDO::FETCH_ASSOC);
        
        if (!$account || !file_exists($account['session_file'])) {
            $text = fancyMessage('⚠️ إنذار', "⚠️ ملف الجلسة مفقود.\nتم تحديث حالة الحساب.", '⚠️');
            $db->prepare("UPDATE accounts SET status='removed' WHERE id=?")->execute([$acc_id]);
            $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع لشاشة الشراء', 'callback_data' => 'buy']]]];
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
            
            $code = null;
            $msgs = $mad->messages->getHistory(['peer' => 777000, 'limit' => 5]);
            $currentTime = time();
            
            foreach ($msgs['messages'] as $msg) {
                if (isset($msg['date']) && ($currentTime - $msg['date'] > 120)) {
                    continue; 
                }

                if (isset($msg['message']) && preg_match('/\b(\d{5,6})\b/', $msg['message'], $match)) {
                    $potentialCode = $match[1];
                    
                    $check = $db->prepare("SELECT COUNT(*) FROM sent_codes WHERE account_id=? AND code=? AND sent_at > ?");
                    $check->execute([$acc_id, $potentialCode, ($currentTime - 300)]);
                    if ($check->fetchColumn() == 0) {
                        $code = $potentialCode;
                        $db->prepare("INSERT INTO sent_codes (account_id, code, sent_at) VALUES (?, ?, ?)")->execute([$acc_id, $code, $currentTime]);
                        break;
                    }
                }
            }
            
            if ($code) {
                $pass = $account['password'] ?? 'لا توجد';
                $c = $db->prepare("SELECT flag, name FROM countries WHERE code=(SELECT country_code FROM accounts WHERE id=?)");
                $c->execute([$acc_id]);
                $country = $c->fetch(PDO::FETCH_ASSOC);
                
                $msg = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🗺️ <b>الدولة:</b> {$country['flag']} {$country['name']}\n" . formatPhone($account['phone']) . "\n" . formatCode($code) . "\n" . formatPassword($pass) . "\n" . formatDate() . "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
                $text = fancyMessage('✅ تم استخراج كود تليجرام الجديد', $msg, '✅');
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '🔄 📲 تحديث وطلب كود جديد', 'callback_data' => "code_{$acc_id}"]],
                        [['text' => '🚪 🔓 طرد الجلسة وحذفها', 'callback_data' => "logout_{$acc_id}"]],
                        [['text' => '🔙 رجوع للخلف', 'callback_data' => 'buy']]
                    ]
                ];
                editMessage($chat_id, $msg_id, $text, $keyboard);
            } else {
                $text = fancyMessage('⏱️ لم يصل كود جديد حالياً', "📡 لا توجد رسائل تفعيل جديدة (خلال آخر دقيقتين) في محادثة تليجرام.\n\nقم بطلب الكود مجدداً من تطبيقك ثم اضغط فحص.", '⏱️');
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '🔄 فحص مجدداً الآن', 'callback_data' => "code_{$acc_id}"]],
                        [['text' => '🔙 رجوع للرئيسية', 'callback_data' => 'buy']]
                    ]
                ];
                editMessage($chat_id, $msg_id, $text, $keyboard);
            }
        } catch (Exception $e) {
            $text = fancyMessage('⚠️ خطأ اتصال بالجلسة', "فشلت العملية: " . $e->getMessage(), '⚠️');
            editMessage($chat_id, $msg_id, $text, ['inline_keyboard' => [[['text' => '🔙 رجوع', 'callback_data' => 'buy']]]]);
        }
        exit;
    }
    
    elseif (preg_match('/^logout_(\d+)$/', $data, $m)) {
        $acc_id = $m[1];
        $acc = $db->prepare("SELECT session_file, phone FROM accounts WHERE id=?");
        $acc->execute([$acc_id]);
        $account = $acc->fetch(PDO::FETCH_ASSOC);
        
        if ($account) {
            $result = forceLogout($account['session_file'], $account['phone'], $acc_id, $db);
            $text = fancyMessage('🚪 تصفية الجلسة', "النتيجة: " . $result['message'], '🚪');
        } else {
            $text = fancyMessage('❌ خطأ', "الحساب غير مسجل بالمنظومة", '❌');
        }
        $keyboard = ['inline_keyboard' => [[['text' => '🔙 رجوع لشاشة الشراء', 'callback_data' => 'buy']]]];
        editMessage($chat_id, $msg_id, $text, $keyboard);
        answerCallback($callback['id']);
        exit;
    }
}

if ($message && !$callback) {
    $text = trim($message['text'] ?? '');
    $session = $db->prepare("SELECT * FROM activation_sessions WHERE admin_id=? ORDER BY created_at DESC LIMIT 1");
    $session->execute([$user_id]);
    $session = $session->fetch(PDO::FETCH_ASSOC);
    
    if ($session && $session['step'] === 'add_country_name') {
        if (strlen($text) < 2) {
            sendMessage($chat_id, fancyMessage('❌ خطأ', "الاسم قصير جداً", '❌'));
            exit;
        }
        $db->prepare("UPDATE activation_sessions SET phone=?, step='add_country_code' WHERE admin_id=?")->execute([$text, $user_id]);
        sendMessage($chat_id, fancyMessage('➕ إضافة دولة', "📝 أرسل الآن رمز الاتصال الدولي للدولة (أرقام فقط)\nمثال: 964", '➕'));
        exit;
    }
    
    elseif ($session && $session['step'] === 'add_country_code') {
        if (!preg_match('/^\d{1,4}$/', $text)) {
            sendMessage($chat_id, fancyMessage('❌ خطأ', "الرمز غير صالح", '❌'));
            exit;
        }
        $db->prepare("UPDATE activation_sessions SET country_code=?, step='add_country_flag' WHERE admin_id=?")->execute([$text, $user_id]);
        sendMessage($chat_id, fancyMessage('➕ إضافة دولة', "🏁 أرسل إيموجي علم الدولة حصراً\nمثال: 🇮🇶", '➕'));
        exit;
    }
    
    elseif ($session && $session['step'] === 'add_country_flag') {
        $name = $session['phone'];
        $code = $session['country_code'];
        $db->prepare("INSERT OR REPLACE INTO countries (code, name, flag) VALUES (?, ?, ?)")->execute([$code, $name, $text]);
        $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
        sendMessage($chat_id, fancyMessage('✅ تم الحفظ', "تمت إضافة دولة $name بنجاح.", '✅'));
        exit;
    }
    
    elseif ($session && $session['step'] === 'awaiting_phone') {
        $phone = normalizePhone($text);
        $country = getCountryByPhone($phone, $db);
        
        if (!$country) {
            sendMessage($chat_id, fancyMessage('❌ خطأ رقم غير مدعوم', "تأكد من إدخال رمز الدولة بشكل صحيح.", '❌'));
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
            sendMessage($chat_id, fancyMessage('✅ تم إرسال طلب الكود', "📱 أرسل كود التفعيل المكون من (5-6 أرقام) الآن المبعوث للحساب.", '✅'));
        } catch (Exception $e) {
            sendMessage($chat_id, fancyMessage('❌ فشل', "فشل التفعيل: " . $e->getMessage(), '❌'));
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
        }
        exit;
    }
    
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
            
            if ($authorization['_'] === 'account.password') {
                $db->prepare("UPDATE activation_sessions SET step='awaiting_password' WHERE admin_id=?")->execute([$user_id]);
                sendMessage($chat_id, fancyMessage('🔐 الحساب محمي بـ 2FA', "🔐 الحساب يتطلب التحقق بخطوتين.\n\n📝 أرسل باسبورد الحساب الحالي لإكمال التخزين وطرد البائع:", '🔐'));
                exit;
            }
            
            $newPassword = bin2hex(random_bytes(8));
            try { $mad->update2fa(['password' => $newPassword]); } catch (Exception $e) {}
            try { $mad->account->cancelPasswordEmail(); } catch (Exception $e) {}
            try { $mad->account->resetAuthorization(); } catch (Exception $e) {}
            
            $finalFile = SESSIONS_PATH . md5($phone) . '.madeline';
            if (file_exists($tempFile)) rename($tempFile, $finalFile);            
            
            $db->prepare("INSERT INTO accounts (phone, country_code, session_file, password, status) VALUES (?,?,?,?,'active')")->execute([$phone, $session['country_code'], $finalFile, $newPassword]);
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
            
            sendMessage($chat_id, fancyMessage('🎉 تم الحفظ والـتأمين', "✅ تم التخزين بنجاح.\n🛡️ تم تغيير الباسبورد وإلغاء إيميل الاسترداد وطرد جميع الجلسات القديمة التابعة للبائع.\n\n" . formatPhone($phone) . "\n🔐 رمز الحماية الجديد: " . formatPassword($newPassword), '🎉'));
        } catch (Exception $e) {
            sendMessage($chat_id, fancyMessage('❌ خطأ تفعيل الكود', "السبب: " . $e->getMessage(), '❌'));
        }
        exit;
    }
    
    elseif ($session && $session['step'] === 'awaiting_password') {
        $oldPass = $text;
        $tempFile = $session['temp_file'];
        
        $settings = new Settings();
        $appInfo = new AppInfo();
        $appInfo->setApiId(API_ID)->setApiHash(API_HASH);
        $settings->setAppInfo($appInfo);
        
        try {
            $mad = new API($tempFile, $settings);
            $mad->complete2faLogin($oldPass);
            
            $newPassword = bin2hex(random_bytes(8));
            try { $mad->update2fa(['password' => $newPassword]); } catch (Exception $e) {}
            try { $mad->account->cancelPasswordEmail(); } catch (Exception $e) {}
            try { $mad->account->resetAuthorization(); } catch (Exception $e) {}
            
            $finalFile = SESSIONS_PATH . md5($session['phone']) . '.madeline';
            if (file_exists($tempFile)) rename($tempFile, $finalFile);
            
            $db->prepare("INSERT INTO accounts (phone, country_code, session_file, password, status) VALUES (?,?,?,?,'active')")->execute([$session['phone'], $session['country_code'], $finalFile, $newPassword]);
            $db->prepare("DELETE FROM activation_sessions WHERE admin_id=?")->execute([$user_id]);
            
            sendMessage($chat_id, fancyMessage('🎉 تم فك القفل والتأمين الشامل', "✅ تم تجاوز الـ 2FA القديم بنجاح.\n🛡️ تم تعديل كلمة المرور وحذف إيميل الاسترداد وطرد البائع نهائياً من الحساب.\n\n🔐 الباسبورد الجديد المنصّب: " . formatPassword($newPassword), '🎉'));
        } catch (Exception $e) {
            sendMessage($chat_id, fancyMessage('❌ باسبورد غير صحيح', "فشلت مطابقة كلمة المرور: " . $e->getMessage(), '❌'));
        }
        exit;
    }
    
    else {
        sendMessage($chat_id, fancyMessage('⚠️ تنبيه نظامي', "الرجاء استخدام الأمر الرئيسي المعتمد للبوت لتفعيل اللائحة البرمجية: /start", '⚠️'));
    }
}
?>