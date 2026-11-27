<?php
declare(strict_types=1);

/**
 * Language options for Malaysia School Esports Portal.
 * English remains the default to preserve existing public HTML-cache behavior.
 * Only fixed interface copy is translated; school/user-supplied data is not.
 */
function portalLanguage(): string
{
    $cookie = $_COOKIE['portal_lang'] ?? 'en';
    $language = is_string($cookie) ? strtolower(trim($cookie)) : 'en';
    return in_array($language, ['en', 'ms', 'zh', 'ta'], true) ? $language : 'en';
}

function portalLanguageHtmlTag(): string
{
    return match (portalLanguage()) {
        'ms' => 'ms-MY',
        'zh' => 'zh-Hans-MY',
        'ta' => 'ta-MY',
        default => 'en-MY',
    };
}

/**
 * Texts are keyed by their original English UI copy.
 * Each value is [Bahasa Melayu, Simplified Chinese, Tamil].
 * Unknown/dynamic strings retain their original value.
 *
 * @return array<string,array{0:string,1:string,2:string}>
 */
function portalLanguageDictionary(): array
{
    return [
        'Language' => ['Bahasa', '语言', 'மொழி'],
        'Home' => ['Utama', '首页', 'முகப்பு'],
        'Programme' => ['Program', '计划', 'திட்டம்'],
        'About Programme' => ['Mengenai Program', '关于计划', 'திட்டம் பற்றி'],
        'Explore the Programme' => ['Terokai Program', '了解计划', 'திட்டத்தை அறிக'],
        'Register School' => ['Daftar Sekolah', '学校注册', 'பள்ளிப் பதிவு'],
        'Register Student' => ['Daftar Murid', '学生注册', 'மாணவர் பதிவு'],
        'Register Teacher' => ['Daftar Guru', '教师注册', 'ஆசிரியர் பதிவு'],
        'Manuals' => ['Panduan', '使用指南', 'வழிகாட்டிகள்'],
        'Contact Us' => ['Hubungi Kami', '联系我们', 'எங்களைத் தொடர்புகொள்க'],
        'Contact Support' => ['Hubungi Sokongan', '联系支持', 'ஆதரவைத் தொடர்புகொள்க'],
        'Support' => ['Sokongan', '支持', 'ஆதரவு'],
        'Login' => ['Log Masuk', '登录', 'உள்நுழை'],
        'Logout' => ['Log Keluar', '退出登录', 'வெளியேறு'],
        'Dashboard' => ['Papan Pemuka', '仪表板', 'முகப்புப் பலகை'],
        'Administration' => ['Pentadbiran', '管理', 'நிர்வாகம்'],
        'School Portal' => ['Portal Sekolah', '学校门户', 'பள்ளி இணையதளம்'],
        'Teacher Portal' => ['Portal Guru', '教师门户', 'ஆசிரியர் இணையதளம்'],
        'Parent Portal' => ['Portal Ibu Bapa', '家长门户', 'பெற்றோர் இணையதளம்'],
        'JPN Portal' => ['Portal JPN', '州教育局门户', 'மாநிலக் கல்வித் துறை இணையதளம்'],
        'JPN Education Department Portal' => ['Portal Jabatan Pendidikan Negeri', '州教育局门户', 'மாநிலக் கல்வித் துறை இணையதளம்'],
        'Test Role Views' => ['Uji Paparan Peranan', '测试角色视图', 'பாத்திரக் காட்சிகளைச் சோதிக்க'],
        'Return to Admin' => ['Kembali ke Pentadbir', '返回管理后台', 'நிர்வாகத்திற்குத் திரும்பு'],
        'ADMIN TEST MODE' => ['MOD UJIAN PENTADBIR', '管理员测试模式', 'நிர்வாகி சோதனை முறை'],
        'Schools' => ['Sekolah', '学校', 'பள்ளிகள்'],
        'School' => ['Sekolah', '学校', 'பள்ளி'],
        'Teachers' => ['Guru', '教师', 'ஆசிரியர்கள்'],
        'Teacher' => ['Guru', '教师', 'ஆசிரியர்'],
        'Students' => ['Murid', '学生', 'மாணவர்கள்'],
        'Student' => ['Murid', '学生', 'மாணவர்'],
        'Parents' => ['Ibu Bapa', '家长', 'பெற்றோர்கள்'],
        'Parent' => ['Ibu Bapa', '家长', 'பெற்றோர்'],
        'Children' => ['Anak-anak', '子女', 'குழந்தைகள்'],
        'Profile' => ['Profil', '个人资料', 'சுயவிவரம்'],
        'Membership' => ['Keahlian', '会员资格', 'உறுப்பினர் நிலை'],
        'Payments' => ['Bayaran', '付款', 'கட்டணங்கள்'],
        'Payment' => ['Bayaran', '付款', 'கட்டணம்'],
        'Equipment' => ['Peralatan', '设备', 'உபகரணங்கள்'],
        'Attendance' => ['Kehadiran', '出勤', 'வருகை'],
        'Learning Modules' => ['Modul Pembelajaran', '学习模块', 'கற்றல் தொகுதிகள்'],
        'Learning Progress' => ['Kemajuan Pembelajaran', '学习进度', 'கற்றல் முன்னேற்றம்'],
        'Certificates' => ['Sijil', '证书', 'சான்றிதழ்கள்'],
        'Events' => ['Acara', '活动', 'நிகழ்வுகள்'],
        'Competitions' => ['Pertandingan', '比赛', 'போட்டிகள்'],
        'Events & Competitions' => ['Acara & Pertandingan', '活动与比赛', 'நிகழ்வுகள் மற்றும் போட்டிகள்'],
        'Tournaments & Events' => ['Kejohanan & Acara', '锦标赛与活动', 'போட்டித் தொடர்கள் மற்றும் நிகழ்வுகள்'],
        'Featured Tournaments & Events' => ['Kejohanan & Acara Pilihan', '精选锦标赛与活动', 'சிறப்பு போட்டித் தொடர்கள் மற்றும் நிகழ்வுகள்'],
        'PlayStation Tournaments' => ['Kejohanan PlayStation', 'PlayStation 锦标赛', 'PlayStation போட்டித் தொடர்கள்'],
        'PlayStation Tournament' => ['Kejohanan PlayStation', 'PlayStation 锦标赛', 'PlayStation போட்டித் தொடர்'],
        'Reports' => ['Laporan', '报告', 'அறிக்கைகள்'],
        'Monthly / Yearly Report' => ['Laporan Bulanan / Tahunan', '月度／年度报告', 'மாதாந்திர / ஆண்டு அறிக்கை'],
        'Settings' => ['Tetapan', '设置', 'அமைப்புகள்'],
        'PSN Accounts' => ['Akaun PSN', 'PSN 账户', 'PSN கணக்குகள்'],
        'Coupons' => ['Kupon', '优惠券', 'வழங்குச்சீட்டுகள்'],
        'Search' => ['Cari', '搜索', 'தேடு'],
        'Filter' => ['Tapis', '筛选', 'வடிகட்டு'],
        'Clear' => ['Kosongkan', '清除', 'அழி'],
        'Reset' => ['Set Semula', '重置', 'மீட்டமை'],
        'Apply' => ['Mohon', '申请', 'விண்ணப்பி'],
        'Save' => ['Simpan', '保存', 'சேமி'],
        'Submit' => ['Hantar', '提交', 'சமர்ப்பி'],
        'Cancel' => ['Batal', '取消', 'ரத்து செய்'],
        'Close' => ['Tutup', '关闭', 'மூடு'],
        'Back' => ['Kembali', '返回', 'திரும்பு'],
        'Next' => ['Seterusnya', '下一步', 'அடுத்து'],
        'View' => ['Lihat', '查看', 'பார்க்க'],
        'Edit' => ['Sunting', '编辑', 'திருத்து'],
        'Delete' => ['Padam', '删除', 'நீக்கு'],
        'Download' => ['Muat Turun', '下载', 'பதிவிறக்கு'],
        'Print' => ['Cetak', '打印', 'அச்சிடு'],
        'Actions' => ['Tindakan', '操作', 'செயல்கள்'],
        'Status' => ['Status', '状态', 'நிலை'],
        'Name' => ['Nama', '姓名', 'பெயர்'],
        'Email' => ['E-mel', '电子邮件', 'மின்னஞ்சல்'],
        'Phone' => ['Telefon', '电话', 'தொலைபேசி'],
        'Address' => ['Alamat', '地址', 'முகவரி'],
        'Date' => ['Tarikh', '日期', 'தேதி'],
        'Total' => ['Jumlah', '总计', 'மொத்தம்'],
        'Active' => ['Aktif', '有效', 'செயலில்'],
        'Inactive' => ['Tidak Aktif', '未启用', 'செயலற்றது'],
        'Pending' => ['Menunggu', '待处理', 'நிலுவையில்'],
        'Approved' => ['Diluluskan', '已批准', 'அங்கீகரிக்கப்பட்டது'],
        'Rejected' => ['Ditolak', '已拒绝', 'நிராகரிக்கப்பட்டது'],
        'Open' => ['Dibuka', '开放', 'திறந்துள்ளது'],
        'Closed' => ['Ditutup', '已关闭', 'மூடப்பட்டது'],
        'Completed' => ['Selesai', '已完成', 'முடிந்தது'],
        'Cancelled' => ['Dibatalkan', '已取消', 'ரத்து செய்யப்பட்டது'],
        'Draft' => ['Draf', '草稿', 'வரைவு'],
        'Schedule' => ['Jadual', '日程', 'அட்டவணை'],
        'View / Join' => ['Lihat / Sertai', '查看／参加', 'பார்க்க / சேர'],
        'Open Official Page' => ['Buka Laman Rasmi', '打开官方网站', 'அதிகாரப்பூர்வப் பக்கத்தைத் திற'],
        'View PlayStation Tournaments' => ['Lihat Kejohanan PlayStation', '查看 PlayStation 锦标赛', 'PlayStation போட்டித் தொடர்களைப் பார்க்க'],
        'Date to be announced' => ['Tarikh akan diumumkan', '日期待公布', 'தேதி பின்னர் அறிவிக்கப்படும்'],
        'No PlayStation tournament is currently available from the tournament feed.' => [
            'Tiada kejohanan PlayStation tersedia buat masa ini.',
            '目前暂无可显示的 PlayStation 锦标赛。',
            'தற்போது PlayStation போட்டித் தொடர் எதுவும் இல்லை.'
        ],
        'No Events & Competitions item is currently available.' => [
            'Tiada acara atau pertandingan tersedia buat masa ini.',
            '目前暂无活动或比赛。',
            'தற்போது நிகழ்வுகள் அல்லது போட்டிகள் எதுவும் இல்லை.'
        ],
        'STEM, TVET & Digital Talent Programme' => [
            'Program STEM, TVET & Bakat Digital',
            'STEM、TVET 与数字人才计划',
            'STEM, TVET மற்றும் டிஜிட்டல் திறன் திட்டம்'
        ],
        'Transforming Students from Technology Users into Technology Creators.' => [
            'Mengubah Murid daripada Pengguna Teknologi kepada Pencipta Teknologi.',
            '让学生从科技使用者成长为科技创造者。',
            'தொழில்நுட்பத்தைப் பயன்படுத்தும் மாணவர்களை உருவாக்குபவர்களாக மாற்றுதல்.'
        ],
        'A structured school programme combining STEM, TVET, artificial intelligence, simulation, digital innovation, learning modules and esports to build future-ready skills.' => [
            'Program sekolah berstruktur yang menggabungkan STEM, TVET, kecerdasan buatan, simulasi, inovasi digital, modul pembelajaran dan e-sukan untuk membina kemahiran masa hadapan.',
            '融合 STEM、TVET、人工智能、模拟、数字创新、学习模块与电子竞技的学校计划，培养面向未来的技能。',
            'STEM, TVET, செயற்கை நுண்ணறிவு, உருவகப்படுத்தல், டிஜிட்டல் புத்தாக்கம், கற்றல் தொகுதிகள் மற்றும் மின்னணு விளையாட்டுகளை இணைக்கும் பள்ளித் திட்டம்.'
        ],
        'Student & Teacher Videos' => ['Video Murid & Guru', '学生与教师视频', 'மாணவர் மற்றும் ஆசிரியர் காணொளிகள்'],
        'One featured PlayStation tournament and one activity from Events & Competitions.' => [
            'Satu kejohanan PlayStation pilihan dan satu aktiviti daripada Acara & Pertandingan.',
            '一项精选 PlayStation 锦标赛及一项活动与比赛项目。',
            'ஒரு சிறப்பு PlayStation போட்டித் தொடரும் ஒரு நிகழ்வு அல்லது போட்டியும்.'
        ],
    ];
}

/** @return array<string,string> */
function portalLanguageCatalog(): array
{
    $language = portalLanguage();
    if ($language === 'en') {
        return [];
    }
    $index = match ($language) {
        'ms' => 0,
        'zh' => 1,
        'ta' => 2,
    };
    $messages = [];
    foreach (portalLanguageDictionary() as $key => $translations) {
        $messages[$key] = $translations[$index] ?? $key;
    }
    return $messages;
}

function portalT(string $english): string
{
    $catalog = portalLanguageCatalog();
    return $catalog[$english] ?? $english;
}

/**
 * Render a compact selector without submitting any enclosing form.
 * The client persists a strictly allowlisted language cookie and reloads
 * the original PHP route, including from cached /html/*.html snapshots.
 */
function portalLanguageSelector(): string
{
    $e = static fn($value): string =>
        htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $url = (string) ($_SERVER['REQUEST_URI'] ?? '/index.php');
    $path = parse_url($url, PHP_URL_PATH);
    // Never place a cross-origin URL into the control.
    if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        $url = '/index.php';
    }

    $options = [
        'ms' => 'Bahasa Melayu',
        'en' => 'English',
        'zh' => '中文',
        'ta' => 'தமிழ்',
    ];
    $html = '<div class="portal-language-select-wrap">'
        . '<label class="visually-hidden" for="portal-language-select">'
        . $e(portalT('Language')) . '</label>'
        . '<select id="portal-language-select" class="form-select form-select-sm portal-language-select"'
        . ' aria-label="' . $e(portalT('Language')) . '"'
        . ' data-portal-language-select data-return-url="' . $e($url) . '">';
    foreach ($options as $code => $name) {
        $selected = portalLanguage() === $code ? ' selected' : '';
        $html .= '<option value="' . $e($code) . '"' . $selected . '>'
            . $e($name) . '</option>';
    }
    return $html . '</select></div>';
}

function portalLanguageAssets(): string
{
    $json = json_encode(
        portalLanguageCatalog(),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if (!is_string($json)) {
        $json = '{}';
    }
    return '<link href="/assets/css/portal-language.css?v=1" rel="stylesheet">'
        . '<script>window.portalLanguageCatalog=' . $json . ';</script>'
        . '<script src="/assets/js/portal-language.js?v=1" defer></script>';
}
