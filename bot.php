
<?php
// bot.php - النسخة النهائية (بدون مكتبات خارجية)
require __DIR__ . '/madeline.php';
require __DIR__ . '/config.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings;

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// إنشاء الجداول الأساسية
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS pending_orders (
    account_id INTEGER PRIMARY KEY,
    buyer_id INTEGER,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
");

// قائمة رموز الدول الأساسية (يمكنك إضافة المزيد)
$defaultCountries = [
    '93' => ['name' => 'أفغانستان', 'flag' => '🇦🇫'],
    '355' => ['name' => 'ألبانيا', 'flag' => '🇦🇱'],
    '213' => ['name' => 'الجزائر', 'flag' => '🇩🇿'],
    '376' => ['name' => 'أندورا', 'flag' => '🇦🇩'],
    '244' => ['name' => 'أنغولا', 'flag' => '🇦🇴'],
    '54' => ['name' => 'الأرجنتين', 'flag' => '🇦🇷'],
    '374' => ['name' => 'أرمينيا', 'flag' => '🇦🇲'],
    '61' => ['name' => 'أستراليا', 'flag' => '🇦🇺'],
    '43' => ['name' => 'النمسا', 'flag' => '🇦🇹'],
    '994' => ['name' => 'أذربيجان', 'flag' => '🇦🇿'],
    '973' => ['name' => 'البحرين', 'flag' => '🇧🇭'],
    '880' => ['name' => 'بنغلاديش', 'flag' => '🇧🇩'],
    '375' => ['name' => 'بيلاروس', 'flag' => '🇧🇾'],
    '32' => ['name' => 'بلجيكا', 'flag' => '🇧🇪'],
    '229' => ['name' => 'بنين', 'flag' => '🇧🇯'],
    '975' => ['name' => 'بوتان', 'flag' => '🇧🇹'],
    '591' => ['name' => 'بوليفيا', 'flag' => '🇧🇴'],
    '387' => ['name' => 'البوسنة والهرسك', 'flag' => '🇧🇦'],
    '267' => ['name' => 'بوتسوانا', 'flag' => '🇧🇼'],
    '55' => ['name' => 'البرازيل', 'flag' => '🇧🇷'],
    '673' => ['name' => 'بروناي', 'flag' => '🇧🇳'],
    '359' => ['name' => 'بلغاريا', 'flag' => '🇧🇬'],
    '226' => ['name' => 'بوركينا فاسو', 'flag' => '🇧🇫'],
    '257' => ['name' => 'بوروندي', 'flag' => '🇧🇮'],
    '855' => ['name' => 'كمبوديا', 'flag' => '🇰🇭'],
    '237' => ['name' => 'الكاميرون', 'flag' => '🇨🇲'],
    '1' => ['name' => 'كندا/الولايات المتحدة', 'flag' => '🇨🇦🇺🇸'],
    '238' => ['name' => 'الرأس الأخضر', 'flag' => '🇨🇻'],
    '236' => ['name' => 'جمهورية أفريقيا الوسطى', 'flag' => '🇨🇫'],
    '235' => ['name' => 'تشاد', 'flag' => '🇹🇩'],
    '56' => ['name' => 'تشيلي', 'flag' => '🇨🇱'],
    '86' => ['name' => 'الصين', 'flag' => '🇨🇳'],
    '57' => ['name' => 'كولومبيا', 'flag' => '🇨🇴'],
    '269' => ['name' => 'جزر القمر', 'flag' => '🇰🇲'],
    '242' => ['name' => 'الكونغو', 'flag' => '🇨🇬'],
    '243' => ['name' => 'الكونغو الديمقراطية', 'flag' => '🇨🇩'],
    '506' => ['name' => 'كوستاريكا', 'flag' => '🇨🇷'],
    '225' => ['name' => 'كوت ديفوار', 'flag' => '🇨🇮'],
    '385' => ['name' => 'كرواتيا', 'flag' => '🇭🇷'],
    '53' => ['name' => 'كوبا', 'flag' => '🇨🇺'],
    '357' => ['name' => 'قبرص', 'flag' => '🇨🇾'],
    '420' => ['name' => 'جمهورية التشيك', 'flag' => '🇨🇿'],
    '45' => ['name' => 'الدنمارك', 'flag' => '🇩🇰'],
    '253' => ['name' => 'جيبوتي', 'flag' => '🇩🇯'],
    '1767' => ['name' => 'دومينيكا', 'flag' => '🇩🇲'],
    '1849' => ['name' => 'جمهورية الدومينيكان', 'flag' => '🇩🇴'],
    '593' => ['name' => 'الإكوادور', 'flag' => '🇪🇨'],
    '20' => ['name' => 'مصر', 'flag' => '🇪🇬'],
    '503' => ['name' => 'السلفادور', 'flag' => '🇸🇻'],
    '240' => ['name' => 'غينيا الاستوائية', 'flag' => '🇬🇶'],
    '291' => ['name' => 'إريتريا', 'flag' => '🇪🇷'],
    '372' => ['name' => 'إستونيا', 'flag' => '🇪🇪'],
    '251' => ['name' => 'إثيوبيا', 'flag' => '🇪🇹'],
    '679' => ['name' => 'فيجي', 'flag' => '🇫🇯'],
    '358' => ['name' => 'فنلندا', 'flag' => '🇫🇮'],
    '33' => ['name' => 'فرنسا', 'flag' => '🇫🇷'],
    '241' => ['name' => 'الغابون', 'flag' => '🇬🇦'],
    '220' => ['name' => 'غامبيا', 'flag' => '🇬🇲'],
    '995' => ['name' => 'جورجيا', 'flag' => '🇬🇪'],
    '49' => ['name' => 'ألمانيا', 'flag' => '🇩🇪'],
    '233' => ['name' => 'غانا', 'flag' => '🇬🇭'],
    '30' => ['name' => 'اليونان', 'flag' => '🇬🇷'],
    '299' => ['name' => 'جرينلاند', 'flag' => '🇬🇱'],
    '1473' => ['name' => 'غرينادا', 'flag' => '🇬🇩'],
    '502' => ['name' => 'غواتيمالا', 'flag' => '🇬🇹'],
    '224' => ['name' => 'غينيا', 'flag' => '🇬🇳'],
    '245' => ['name' => 'غينيا بيساو', 'flag' => '🇬🇼'],
    '592' => ['name' => 'غيانا', 'flag' => '🇬🇾'],
    '509' => ['name' => 'هايتي', 'flag' => '🇭🇹'],
    '504' => ['name' => 'هندوراس', 'flag' => '🇭🇳'],
    '852' => ['name' => 'هونغ كونغ', 'flag' => '🇭🇰'],
    '36' => ['name' => 'المجر', 'flag' => '🇭🇺'],
    '354' => ['name' => 'أيسلندا', 'flag' => '🇮🇸'],
    '91' => ['name' => 'الهند', 'flag' => '🇮🇳'],
    '62' => ['name' => 'إندونيسيا', 'flag' => '🇮🇩'],
    '98' => ['name' => 'إيران', 'flag' => '🇮🇷'],
    '964' => ['name' => 'العراق', 'flag' => '🇮🇶'],
    '353' => ['name' => 'أيرلندا', 'flag' => '🇮🇪'],
    '972' => ['name' => 'إسرائيل', 'flag' => '🇮🇱'],
    '39' => ['name' => 'إيطاليا', 'flag' => '🇮🇹'],
    '1876' => ['name' => 'جامايكا', 'flag' => '🇯🇲'],
    '81' => ['name' => 'اليابان', 'flag' => '🇯🇵'],
    '962' => ['name' => 'الأردن', 'flag' => '🇯🇴'],
    '7' => ['name' => 'كازاخستان/روسيا', 'flag' => '🇰🇿🇷🇺'],
    '254' => ['name' => 'كينيا', 'flag' => '🇰🇪'],
    '686' => ['name' => 'كيريباتي', 'flag' => '🇰🇮'],
    '850' => ['name' => 'كوريا الشمالية', 'flag' => '🇰🇵'],
    '82' => ['name' => 'كوريا الجنوبية', 'flag' => '🇰🇷'],
    '965' => ['name' => 'الكويت', 'flag' => '🇰🇼'],
    '996' => ['name' => 'قيرغيزستان', 'flag' => '🇰🇬'],
    '856' => ['name' => 'لاوس', 'flag' => '🇱🇦'],
    '371' => ['name' => 'لاتفيا', 'flag' => '🇱🇻'],
    '961' => ['name' => 'لبنان', 'flag' => '🇱🇧'],
    '266' => ['name' => 'ليسوتو', 'flag' => '🇱🇸'],
    '231' => ['name' => 'ليبيريا', 'flag' => '🇱🇷'],
    '218' => ['name' => 'ليبيا', 'flag' => '🇱🇾'],
    '423' => ['name' => 'ليختنشتاين', 'flag' => '🇱🇮'],
    '370' => ['name' => 'ليتوانيا', 'flag' => '🇱🇹'],
    '352' => ['name' => 'لوكسمبورغ', 'flag' => '🇱🇺'],
    '853' => ['name' => 'ماكاو', 'flag' => '🇲🇴'],
    '389' => ['name' => 'مقدونيا', 'flag' => '🇲🇰'],
    '261' => ['name' => 'مدغشقر', 'flag' => '🇲🇬'],
    '265' => ['name' => 'مالاوي', 'flag' => '🇲🇼'],
    '60' => ['name' => 'ماليزيا', 'flag' => '🇲🇾'],
    '960' => ['name' => 'جزر المالديف', 'flag' => '🇲🇻'],
    '223' => ['name' => 'مالي', 'flag' => '🇲🇱'],
    '356' => ['name' => 'مالطا', 'flag' => '🇲🇹'],
    '692' => ['name' => 'جزر مارشال', 'flag' => '🇲🇭'],
    '222' => ['name' => 'موريتانيا', 'flag' => '🇲🇷'],
    '230' => ['name' => 'موريشيوس', 'flag' => '🇲🇺'],
    '52' => ['name' => 'المكسيك', 'flag' => '🇲🇽'],
    '691' => ['name' => 'ميكرونيسيا', 'flag' => '🇫🇲'],
    '373' => ['name' => 'مولدوفا', 'flag' => '🇲🇩'],
    '377' => ['name' => 'موناكو', 'flag' => '🇲🇨'],
    '976' => ['name' => 'منغوليا', 'flag' => '🇲🇳'],
    '382' => ['name' => 'الجبل الأسود', 'flag' => '🇲🇪'],
    '212' => ['name' => 'المغرب', 'flag' => '🇲🇦'],
    '258' => ['name' => 'موزمبيق', 'flag' => '🇲🇿'],
    '95' => ['name' => 'ميانمار', 'flag' => '🇲🇲'],
    '264' => ['name' => 'ناميبيا', 'flag' => '🇳🇦'],
    '674' => ['name' => 'ناورو', 'flag' => '🇳🇷'],
    '977' => ['name' => 'نيبال', 'flag' => '🇳🇵'],
    '31' => ['name' => 'هولندا', 'flag' => '🇳🇱'],
    '687' => ['name' => 'كاليدونيا الجديدة', 'flag' => '🇳🇨'],
    '64' => ['name' => 'نيوزيلندا', 'flag' => '🇳🇿'],
    '505' => ['name' => 'نيكاراغوا', 'flag' => '🇳🇮'],
    '227' => ['name' => 'النيجر', 'flag' => '🇳🇪'],
    '234' => ['name' => 'نيجيريا', 'flag' => '🇳🇬'],
    '47' => ['name' => 'النرويج', 'flag' => '🇳🇴'],
    '968' => ['name' => 'عمان', 'flag' => '🇴🇲'],
    '92' => ['name' => 'باكستان', 'flag' => '🇵🇰'],
    '680' => ['name' => 'بالاو', 'flag' => '🇵🇼'],
    '970' => ['name' => 'فلسطين', 'flag' => '🇵🇸'],
    '507' => ['name' => 'بنما', 'flag' => '🇵🇦'],
    '675' => ['name' => 'بابوا غينيا الجديدة', 'flag' => '🇵🇬'],
    '595' => ['name' => 'باراغواي', 'flag' => '🇵🇾'],
    '51' => ['name' => 'بيرو', 'flag' => '🇵🇪'],
    '63' => ['name' => 'الفلبين', 'flag' => '🇵🇭'],
    '48' => ['name' => 'بولندا', 'flag' => '🇵🇱'],
    '351' => ['name' => 'البرتغال', 'flag' => '🇵🇹'],
    '974' => ['name' => 'قطر', 'flag' => '🇶🇦'],
    '40' => ['name' => 'رومانيا', 'flag' => '🇷🇴'],
    '250' => ['name' => 'رواندا', 'flag' => '🇷🇼'],
    '290' => ['name' => 'سانت هيلينا', 'flag' => '🇸🇭'],
    '1869' => ['name' => 'سانت كيتس ونيفيس', 'flag' => '🇰🇳'],
    '1758' => ['name' => 'سانت لوسيا', 'flag' => '🇱🇨'],
    '508' => ['name' => 'سان بيير وميكلون', 'flag' => '🇵🇲'],
    '1784' => ['name' => 'سانت فينسنت والغرينادين', 'flag' => '🇻🇨'],
    '685' => ['name' => 'ساموا', 'flag' => '🇼🇸'],
    '378' => ['name' => 'سان مارينو', 'flag' => '🇸🇲'],
    '239' => ['name' => 'ساو تومي وبرينسيب', 'flag' => '🇸🇹'],
    '966' => ['name' => 'السعودية', 'flag' => '🇸🇦'],
    '221' => ['name' => 'السنغال', 'flag' => '🇸🇳'],
    '381' => ['name' => 'صربيا', 'flag' => '🇷🇸'],
    '248' => ['name' => 'سيشل', 'flag' => '🇸🇨'],
    '232' => ['name' => 'سيراليون', 'flag' => '🇸🇱'],
    '65' => ['name' => 'سنغافورة', 'flag' => '🇸🇬'],
    '421' => ['name' => 'سلوفاكيا', 'flag' => '🇸🇰'],
    '386' => ['name' => 'سلوفينيا', 'flag' => '🇸🇮'],
    '677' => ['name' => 'جزر سليمان', 'flag' => '🇸🇧'],
    '252' => ['name' => 'الصومال', 'flag' => '🇸🇴'],
    '27' => ['name' => 'جنوب أفريقيا', 'flag' => '🇿🇦'],
    '34' => ['name' => 'إسبانيا', 'flag' => '🇪🇸'],
    '94' => ['name' => 'سريلانكا', 'flag' => '🇱🇰'],
    '249' => ['name' => 'السودان', 'flag' => '🇸🇩'],
    '597' => ['name' => 'سورينام', 'flag' => '🇸🇷'],
    '268' => ['name' => 'سوازيلاند', 'flag' => '🇸🇿'],
    '46' => ['name' => 'السويد', 'flag' => '🇸🇪'],
    '41' => ['name' => 'سويسرا', 'flag' => '🇨🇭'],
    '963' => ['name' => 'سوريا', 'flag' => '🇸🇾'],
    '886' => ['name' => 'تايوان', 'flag' => '🇹🇼'],
    '992' => ['name' => 'طاجيكستان', 'flag' => '🇹🇯'],
    '255' => ['name' => 'تنزانيا', 'flag' => '🇹🇿'],
    '66' => ['name' => 'تايلاند', 'flag' => '🇹🇭'],
    '670' => ['name' => 'تيمور الشرقية', 'flag' => '🇹🇱'],
    '228' => ['name' => 'توغو', 'flag' => '🇹🇬'],
    '676' => ['name' => 'تونغا', 'flag' => '🇹🇴'],
    '1868' => ['name' => 'ترينيداد وتوباغو', 'flag' => '🇹🇹'],
    '216' => ['name' => 'تونس', 'flag' => '🇹🇳'],
    '90' => ['name' => 'تركيا', 'flag' => '🇹🇷'],
    '993' => ['name' => 'تركمانستان', 'flag' => '🇹🇲'],
    '688' => ['name' => 'توفالو', 'flag' => '🇹🇻'],
    '256' => ['name' => 'أوغندا', 'flag' => '🇺🇬'],
    '380' => ['name' => 'أوكرانيا', 'flag' => '🇺🇦'],
    '971' => ['name' => 'الإمارات العربية المتحدة', 'flag' => '🇦🇪'],
    '44' => ['name' => 'المملكة المتحدة', 'flag' => '🇬🇧'],
    '598' => ['name' => 'أوروغواي', 'flag' => '🇺🇾'],
    '998' => ['name' => 'أوزبكستان', 'flag' => '🇺🇿'],
    '678' => ['name' => 'فانواتو', 'flag' => '🇻🇺'],
    '58' => ['name' => 'فنزويلا', 'flag' => '🇻🇪'],
    '84' => ['name' => 'فيتنام', 'flag' => '🇻🇳'],
    '967' => ['name' => 'اليمن', 'flag' => '🇾🇪'],
    '260' => ['name' => 'زامبيا', 'flag' => '🇿🇲'],
    '263' => ['name' => 'زيمبابوي', 'flag' => '🇿🇼']
];

// إضافة الدول الافتراضية إلى قاعدة البيانات
foreach ($defaultCountries as $code => $info) {
    $stmt = $db->prepare("INSERT OR IGNORE INTO countries (code, name, flag) VALUES (?, ?, ?)");
    $stmt->execute([$code, $info['name'], $info['flag']]);
}

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

// دالة التعرف على الدولة من الرقم (بدون مكتبات خارجية)
function getCountryByPhone($phone, $db) {
    if (preg_match('/^\+(\d{1,4})/', $phone, $matches)) {
        $code = $matches[1];
        // البحث في قاعدة البيانات عن رمز مطابق
        $stmt = $db->prepare("SELECT code, name, flag FROM countries WHERE code = ?");
        $stmt->execute([$code]);
        $country = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($country) {
            return $country;
        }
        // إذا لم يعثر على رمز بالضبط، نحاول البحث عن رمز أقصر (مثل 966 يطابق 96؟ لا نريد ذلك)
        // سنعيد null للرموز غير المعروفة
    }
    return null;
}

// ========== باقي كود البوت (الأزرار، التخزين، الشراء) يبقى كما هو ==========
// ... (أضف هنا كامل الأزرار ومعالجة الرسائل من النسخة السابقة، لكن احذف أي مرجع لـ libphonenumber)

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

// باقي الكود (معالجة الأزرار والرسائل) يجب إضافته هنا بنفس الطريقة السابقة
// ولكن تجنب استخدام أي شيء يتطلب libphonenumber

// ... (أضف بقية الكود من النسخة السابقة، مع التأكد من أن دالة getCountryByPhone تستخدم المعامل $db فقط)
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
        $country = getCountryByPhone($phone);
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