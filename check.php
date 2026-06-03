<?php
header('Content-Type: text/plain; charset=utf-8');
echo "=== فحص البوت ===\n\n";

// 1. فحص وجود الملفات الأساسية
echo "1. ملفات البوت:\n";
echo "madeline.php: " . (file_exists('madeline.php') ? '✅ موجود' : '❌ مفقود') . "\n";
echo "config.php: " . (file_exists('config.php') ? '✅ موجود' : '❌ مفقود') . "\n";
echo "numbers.db: " . (file_exists('numbers.db') ? '✅ موجود' : '❌ غير موجود (سيتم إنشاؤه)') . "\n";

// 2. فحص ملفات MadelineProto phar
$phar_local = 'madeline-8.6.5.phar';
$phar_tmp = sys_get_temp_dir() . '/madeline-8.6.5.phar';
echo "\n2. ملفات MadelineProto phar:\n";
echo "local: " . (file_exists($phar_local) ? '✅ موجود (حجم: '.round(filesize($phar_local)/1024).' كيلوبايت)' : '❌ مفقود') . "\n";
echo "tmp: " . (file_exists($phar_tmp) ? '✅ موجود (حجم: '.round(filesize($phar_tmp)/1024).' كيلوبايت)' : '❌ مفقود') . "\n";

// 3. اختبار تحميل madeline.php
echo "\n3. محاولة تحميل madeline.php:\n";
try {
    require_once 'madeline.php';
    echo "✅ تم تحميل madeline.php بنجاح\n";
} catch (Exception $e) {
    echo "❌ فشل التحميل: " . $e->getMessage() . "\n";
    exit;
}

// 4. اختبار الاتصال بـ API تيليجرام باستخدام التوكن من config.php
if (file_exists('config.php')) {
    require_once 'config.php';
    echo "\n4. اختبار الاتصال بـ Telegram API:\n";
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getMe";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http == 200) {
        $data = json_decode($resp, true);
        if ($data['ok']) {
            echo "✅ الاتصال ناجح. البوت: @" . $data['result']['username'] . "\n";
        } else {
            echo "❌ استجابة غير صالحة: " . $resp . "\n";
        }
    } else {
        echo "❌ فشل الاتصال (HTTP $http)\n";
    }
}

echo "\n=== انتهى الفحص ===\n";