<?php
// ═══════════════════════════════════════════════════════════════
// 🔥 بوت إدارة حسابات تيلجرام - الإعدادات المصححة 🔥
// ═══════════════════════════════════════════════════════════════

// ========== 🤖 إعدادات البوت الأساسية ==========
define('BOT_TOKEN', '8501024342:AAEtqi187eXY7Pjd7OwkGhaMgaEzp4hyUfM'); // 👈 ضع توكن البوت هنا
define('ADMIN_ID', 8043134426); // 👈 ضع معرف التلجرام الخاص بك (رقم فقط)

// ========== 🔑 إعدادات منصة MadelineProto ==========
define('API_ID', 39864167);    // 👈 من my.telegram.org
define('API_HASH', 'bbfe245db54333aeeaca4d13a3444bec'); // 👈 من my.telegram.org

// ========== 📂 مسارات تخزين الملفات ==========
define('DB_PATH', __DIR__ . '/database/numbers.db');
define('SESSIONS_PATH', __DIR__ . '/sessions/');
define('SERIALIZED_PATH', __DIR__ . '/serialized/');

// ========== 🎨 إعدادات واجهة المستخدم الفخمة ==========
define('BOT_NAME', '👑 TELEGRAM KING 👑');
define('BOT_VERSION', 'v3.5.0-Stable');
define('FOOTER_TEXT', '⚡ مدعوم من نظام 2099 المتطور ⚡');

// إنشاء المجلدات بصلاحيات قياسية آمنة لتفادي حظر الاستضافة (0755 بدلاً من 0777)
$folders = [__DIR__ . '/database', SESSIONS_PATH, SERIALIZED_PATH];
foreach ($folders as $folder) {
    if (!is_dir($folder)) {
        mkdir($folder, 0755, true);
    }
}

// تشغيل تتبع الأخطاء البرمجية الهامة للمطور لرصد أي توقفات فورية من السيرفر
error_reporting(E_ALL);
ini_set('display_errors', '1');
?>