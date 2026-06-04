<?php
// config.php - مع دعم متغيرات البيئة (آمن للمستودع)

// ========== 🤖 إعدادات البوت من البيئة ==========
define('BOT_TOKEN', getenv('BOT_TOKEN'));
define('ADMIN_ID', getenv('ADMIN_ID'));
define('API_ID', getenv('API_ID'));
define('API_HASH', getenv('API_HASH'));

// ========== 📂 مسارات المجلدات ==========
define('DB_PATH', __DIR__ . '/database/numbers.db');
define('SESSIONS_PATH', __DIR__ . '/sessions/');
define('SERIALIZED_PATH', __DIR__ . '/serialized/');

// ========== ⏱️ إعدادات البحث ==========
define('CODE_SEARCH_TIMEOUT', 360); // 6 دقائق
define('CODE_SEARCH_INTERVAL', 2);  // ثانيتين

// ========== 🎨 إعدادات التصميم ==========
define('BOT_NAME', '👑 TELEGRAM KING 👑');
define('BOT_VERSION', 'v3.0.0');
define('FOOTER_TEXT', '⚡ مدعوم من نظام 2099 المتطور ⚡');

// إنشاء المجلدات تلقائياً
$folders = [__DIR__ . '/database', SESSIONS_PATH, SERIALIZED_PATH];
foreach ($folders as $folder) {
    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
    }
}

// التحقق من وجود المتغيرات الأساسية
if (!BOT_TOKEN || !API_ID || !API_HASH) {
    die('⚠️ يرجى تعيين BOT_TOKEN, API_ID, API_HASH في متغيرات البيئة');
}
?>