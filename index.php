<?php
// Keep the page from hanging (a hang is what a gateway reports as "502 Bad Gateway").
@set_time_limit(25);
@ini_set('default_socket_timeout', '8');
@ini_set('mysqlnd.net_read_timeout', '15');
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) { http_response_code(500); }
        // Visible error box so a blank page can be diagnosed (remove this echo once everything works).
        echo '<div style="position:relative;z-index:99999;margin:20px;padding:16px;border:2px solid #dc3545;background:#fff5f5;color:#842029;font:14px/1.5 monospace;white-space:pre-wrap;word-break:break-word;">'
           . '<b>Server error (send this text to your developer):</b>' . "\n"
           . htmlspecialchars($e['message'] . ' | file: ' . basename($e['file']) . ' | line: ' . $e['line'])
           . '</div>';
    }
});
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

// --- DATABASE CONFIGURATION ---
$db_host = "sql308.infinityfree.com";
$db_user = "if0_42998089";
$db_pass = getenv("DB_PASSWORD") ?: "BeastSoni";
$db_name = "if0_42998089_tuition";

$db_connected = false;
$db_error = null;
$pdo = null;

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8          // give up quickly instead of hanging
    ]);
    $db_connected = true;
} catch (Throwable $e) {
    $db_error = $e->getMessage();
}

function initializeDatabaseSchema($pdo) {
    if (!$pdo) return;

    $queries = [
        "CREATE TABLE IF NOT EXISTS `settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `time_limit_minutes` INT DEFAULT 90,
            `test_expiry_date` DATETIME NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `admin` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `full_name` VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `fields` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `field_name` VARCHAR(100) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `classes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `class_name` VARCHAR(50) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `subjects` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `subject_name` VARCHAR(100) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `field_subjects` (
            `field_id` INT NOT NULL,
            `subject_id` INT NOT NULL,
            PRIMARY KEY (`field_id`, `subject_id`),
            FOREIGN KEY (`field_id`) REFERENCES `fields`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `tests_meta` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `test_name` VARCHAR(150) NOT NULL UNIQUE,
            `class` VARCHAR(50) NOT NULL,
            `stream` VARCHAR(100) NOT NULL,
            `time_limit_minutes` INT DEFAULT NULL,
            `test_expiry_date` DATETIME NULL DEFAULT NULL,
            `passing_percentage` DECIMAL(5,2) NOT NULL DEFAULT 50,
            `gender_target` ENUM('All', 'Male', 'Female') NOT NULL DEFAULT 'All',
            `is_live` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `students` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `full_name` VARCHAR(100) NOT NULL,
            `class` VARCHAR(50) NOT NULL,
            `stream` VARCHAR(100) NOT NULL,
            `gender` ENUM('Male', 'Female') NOT NULL DEFAULT 'Female',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `questions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `test_id` INT DEFAULT NULL,
            `class` VARCHAR(50) NOT NULL,
            `stream` VARCHAR(100) NOT NULL,
            `subject` VARCHAR(100) NOT NULL,
            `gender_target` ENUM('All', 'Male', 'Female') NOT NULL DEFAULT 'All',
            `question_text` TEXT NOT NULL,
            `option_a` TEXT NOT NULL,
            `option_b` TEXT NOT NULL,
            `option_c` TEXT NOT NULL,
            `option_d` TEXT NOT NULL,
            `correct_option` ENUM('A', 'B', 'C', 'D') NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`test_id`) REFERENCES `tests_meta`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `student_tests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `student_id` INT NOT NULL,
            `test_id` INT DEFAULT NULL,
            `class` VARCHAR(50) NOT NULL,
            `stream` VARCHAR(100) NOT NULL,
            `subject` VARCHAR(100) NOT NULL,
            `total_questions` INT NOT NULL,
            `correct_answers` INT NOT NULL,
            `score_percentage` DECIMAL(5,2) NOT NULL,
            `time_taken_seconds` INT NOT NULL,
            `test_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`test_id`) REFERENCES `tests_meta`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `test_answers` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `test_id` INT NOT NULL,
            `question_id` INT NOT NULL,
            `selected_option` ENUM('A', 'B', 'C', 'D') NULL,
            `correct_option` ENUM('A', 'B', 'C', 'D') NOT NULL,
            `is_correct` TINYINT(1) DEFAULT 0,
            FOREIGN KEY (`test_id`) REFERENCES `student_tests`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `fees` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `student_id` INT NOT NULL,
            `fee_month` CHAR(7) NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `paid_on` DATE NOT NULL,
            `method` VARCHAR(30) DEFAULT 'Cash',
            `note` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `fee_given` (
            `student_id` INT NOT NULL,
            `fee_month` CHAR(7) NOT NULL,
            `given` VARCHAR(30) NOT NULL,
            PRIMARY KEY (`student_id`, `fee_month`),
            FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `announcements` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(150) NOT NULL,
            `body` TEXT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        "CREATE TABLE IF NOT EXISTS `site_teachers` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `full_name` VARCHAR(120) NOT NULL,
            `gender` VARCHAR(10) NOT NULL DEFAULT 'Male',
            `grade` VARCHAR(80) DEFAULT NULL,
            `subject` VARCHAR(120) DEFAULT NULL,
            `qualification` VARCHAR(150) DEFAULT NULL,
            `experience` VARCHAR(60) DEFAULT NULL,
            `phone` VARCHAR(40) DEFAULT NULL,
            `email` VARCHAR(120) DEFAULT NULL,
            `bio` TEXT,
            `photo` VARCHAR(120) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ];

    foreach ($queries as $sql) {
        $pdo->exec($sql);
    }
    // Add optional question image support to existing installations too.
    $questionCols = $pdo->query("SHOW COLUMNS FROM `questions`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('image_path', $questionCols, true)) {
        $pdo->exec("ALTER TABLE `questions` ADD COLUMN `image_path` VARCHAR(255) NULL DEFAULT NULL");
    }

    // Migrate existing installations without overwriting any existing test settings.
    $testColumns = $pdo->query("SHOW COLUMNS FROM `tests_meta`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('time_limit_minutes', $testColumns, true)) {
        $pdo->exec("ALTER TABLE `tests_meta` ADD COLUMN `time_limit_minutes` INT DEFAULT NULL");
    }
    if (!in_array('test_expiry_date', $testColumns, true)) {
        $pdo->exec("ALTER TABLE `tests_meta` ADD COLUMN `test_expiry_date` DATETIME NULL DEFAULT NULL");
    }
    // Passing marks (percentage) per test - default 50%.
    if (!in_array('passing_percentage', $testColumns, true)) {
        $pdo->exec("ALTER TABLE `tests_meta` ADD COLUMN `passing_percentage` DECIMAL(5,2) NOT NULL DEFAULT 50");
    }
    // Per-test gender and Live switch. Existing tests stay Live; new tests start in the Restricted area (is_live = 0).
    if (!in_array('gender_target', $testColumns, true)) {
        $pdo->exec("ALTER TABLE `tests_meta` ADD COLUMN `gender_target` ENUM('All','Male','Female') NOT NULL DEFAULT 'All'");
        try {
            $pdo->exec("UPDATE `tests_meta` tm SET tm.gender_target = (SELECT MIN(q.gender_target) FROM `questions` q WHERE q.test_id = tm.id) WHERE (SELECT COUNT(DISTINCT q2.gender_target) FROM `questions` q2 WHERE q2.test_id = tm.id) = 1");
        } catch (Throwable $e) { /* optional: keeps 'All' if the host rejects this one-time copy */ }
    }
    if (!in_array('is_live', $testColumns, true)) {
        $pdo->exec("ALTER TABLE `tests_meta` ADD COLUMN `is_live` TINYINT(1) NOT NULL DEFAULT 1");
        $pdo->exec("ALTER TABLE `tests_meta` ALTER COLUMN `is_live` SET DEFAULT 0");
    }

    $stdCols = $pdo->query("SHOW COLUMNS FROM `students`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('monthly_fee', $stdCols, true)) {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `monthly_fee` DECIMAL(10,2) NOT NULL DEFAULT 0");
    }

    foreach (student_extra_fields() as $col => $def) {
        if (!in_array($col, $stdCols, true)) {
            $type = $def[1] === 'date' ? 'DATE NULL DEFAULT NULL' : ($def[1] === 'textarea' ? 'TEXT NULL' : 'VARCHAR(100) NULL DEFAULT NULL');
            $pdo->exec("ALTER TABLE `students` ADD COLUMN `$col` $type");
        }
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM `fields`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `fields` (`field_name`) VALUES ('Engineering'), ('Medical'), ('Computer Science')");
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM `classes`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `classes` (`class_name`) VALUES ('11th'), ('12th')");
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM `settings`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `settings` (`id`, `time_limit_minutes`) VALUES (1, 90)");
    }

    $stmtAdmin = $pdo->query("SELECT COUNT(*) FROM `admin`");
    if ($stmtAdmin->fetchColumn() == 0) {
        $stmtInsert = $pdo->prepare("INSERT INTO `admin` (`username`, `password`, `full_name`) VALUES (?, ?, ?)");
        $stmtInsert->execute(['admin', 'karankumar', 'Administrator']);
    }
}

if ($db_connected) {
    // Table setup used to run on EVERY page load (very slow on a remote DB). Run it once.
    $schemaMarker = __DIR__ . '/.schema_ready';
    if (empty($_SESSION['schema_ready']) && !is_file($schemaMarker)) {
        try {
            initializeDatabaseSchema($pdo);
            $_SESSION['schema_ready'] = true;
            @file_put_contents($schemaMarker, date('c'));
        } catch (Throwable $e) {
            $db_error = 'Database setup error: ' . $e->getMessage();
        }
    }
}
// Repair: add any column an older database is missing (the one-time setup above may have been skipped).
if ($db_connected && empty($_SESSION['cols_repaired_v2'])) {
    $repairOk = true;
    $fixes = [
        ['tests_meta', 'time_limit_minutes', "ALTER TABLE `tests_meta` ADD COLUMN `time_limit_minutes` INT DEFAULT NULL"],
        ['tests_meta', 'test_expiry_date', "ALTER TABLE `tests_meta` ADD COLUMN `test_expiry_date` DATETIME NULL DEFAULT NULL"],
        ['tests_meta', 'passing_percentage', "ALTER TABLE `tests_meta` ADD COLUMN `passing_percentage` DECIMAL(5,2) NOT NULL DEFAULT 50"],
        ['tests_meta', 'gender_target', "ALTER TABLE `tests_meta` ADD COLUMN `gender_target` ENUM('All','Male','Female') NOT NULL DEFAULT 'All'"],
        ['tests_meta', 'is_live', "ALTER TABLE `tests_meta` ADD COLUMN `is_live` TINYINT(1) NOT NULL DEFAULT 1"],
        ['questions', 'image_path', "ALTER TABLE `questions` ADD COLUMN `image_path` VARCHAR(255) NULL DEFAULT NULL"],
        ['students', 'monthly_fee', "ALTER TABLE `students` ADD COLUMN `monthly_fee` DECIMAL(10,2) NOT NULL DEFAULT 0"],
    ];
    $colCache = [];
    foreach ($fixes as $fx) {
        try {
            if (!isset($colCache[$fx[0]])) { $colCache[$fx[0]] = $pdo->query("SHOW COLUMNS FROM `" . $fx[0] . "`")->fetchAll(PDO::FETCH_COLUMN); }
            if (!in_array($fx[1], $colCache[$fx[0]], true)) {
                $pdo->exec($fx[2]);
                if ($fx[1] === 'is_live') { try { $pdo->exec("ALTER TABLE `tests_meta` ALTER COLUMN `is_live` SET DEFAULT 0"); } catch (Throwable $e2) {} }
            }
        } catch (Throwable $e) { $repairOk = false; }
    }
    if ($repairOk) { $_SESSION['cols_repaired_v2'] = true; }
}
// One-time cleanup of the old hidden "abcdefgh" field: copy it back into `stream` (the real field) where
// `stream` had been blanked for non-live tests, then drop the column. `stream` now always holds the field.
if ($db_connected && empty($_SESSION['abc_removed_v1'])) {
    try {
        $__tmCols = $pdo->query("SHOW COLUMNS FROM `tests_meta`")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('abcdefgh', $__tmCols, true)) {
            $pdo->exec("UPDATE `tests_meta` SET `stream` = `abcdefgh` WHERE (`stream` IS NULL OR `stream` = '') AND `abcdefgh` IS NOT NULL AND `abcdefgh` <> ''");
            $pdo->exec("UPDATE `questions` q JOIN `tests_meta` tm ON tm.id = q.test_id SET q.stream = tm.stream WHERE tm.stream <> '' AND q.stream <> tm.stream");
            $pdo->exec("ALTER TABLE `tests_meta` DROP COLUMN `abcdefgh`");
        }
        $_SESSION['abc_removed_v1'] = true;
    } catch (Throwable $e) { /* retried on the next request */ }
}
// RESTRICTED AREA: a test with is_live = 0 is Restricted (visible to the admin only). Any Live test whose
// expiry date has passed is moved to Restricted automatically. Runs on every request, so no cron is needed.
function restrict_expired_tests($pdo) {
    try {
        $st = $pdo->prepare("UPDATE `tests_meta` SET `is_live` = 0 WHERE `is_live` = 1 AND `test_expiry_date` IS NOT NULL AND `test_expiry_date` <= ?");
        $st->execute([date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { /* never block the page */ }
}
if ($db_connected) { restrict_expired_tests($pdo); }
// ===== TEACHERS (admin-managed, shown on the public site) =====
define('TEACHER_UPLOAD_DIR', __DIR__ . '/uploads_teachers');
function teacher_avatar_svg($gender) {
    $g = strtolower((string)$gender);
    if ($g === 'female') {
        $bg = '#ffe3f0'; $hair = '#4a2c2a'; $skin = '#f6c9a6'; $cloth = '#e0559a';
        $hairBack = '<path d="M22 46 C20 20 80 20 78 46 L82 84 C70 90 30 90 18 84 Z" fill="'.$hair.'"/>';
        $hairFront = '<path d="M26 44 C30 24 70 24 74 44 C62 36 40 34 26 44 Z" fill="'.$hair.'"/>';
        $extra = '<circle cx="50" cy="14" r="7" fill="'.$hair.'"/>';
    } elseif ($g === 'male') {
        $bg = '#dfe9ff'; $hair = '#2b2b33'; $skin = '#f1c19a'; $cloth = '#3b5bdb';
        $hairBack = '';
        $hairFront = '<path d="M27 44 C26 20 74 20 73 44 C68 33 58 29 50 29 C42 29 32 33 27 44 Z" fill="'.$hair.'"/>';
        $extra = '';
    } else {
        $bg = '#e6f7ea'; $hair = '#5a3b1f'; $skin = '#f3c8a2'; $cloth = '#2f9e6b';
        $hairBack = '';
        $hairFront = '<path d="M27 44 C28 24 72 24 73 44 C62 34 38 34 27 44 Z" fill="'.$hair.'"/>';
        $extra = '';
    }
    return '<svg class="t-avatar" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Teacher avatar">'
        . '<rect width="100" height="100" fill="'.$bg.'"/>'
        . '<g class="t-bob">'
        . $extra . $hairBack
        . '<path d="M14 100 C14 76 86 76 86 100 Z" fill="'.$cloth.'"/>'
        . '<rect x="44" y="64" width="12" height="14" rx="5" fill="'.$skin.'"/>'
        . '<ellipse cx="50" cy="48" rx="23" ry="25" fill="'.$skin.'"/>'
        . $hairFront
        . '<g class="t-eyes"><ellipse cx="41" cy="50" rx="3" ry="3.6" fill="#222"/><ellipse cx="59" cy="50" rx="3" ry="3.6" fill="#222"/></g>'
        . '<ellipse cx="35" cy="58" rx="4" ry="2.4" fill="#ff9aa2" opacity=".55"/><ellipse cx="65" cy="58" rx="4" ry="2.4" fill="#ff9aa2" opacity=".55"/>'
        . '<path class="t-smile" d="M42 60 Q50 68 58 60" stroke="#a33" stroke-width="2.4" fill="none" stroke-linecap="round"/>'
        . '</g></svg>';
}
function teacher_photo_html($t) {
    if (!empty($t['photo']) && is_file(TEACHER_UPLOAD_DIR . '/' . $t['photo'])) {
        return '<img class="t-avatar" src="uploads_teachers/' . htmlspecialchars($t['photo']) . '" alt="' . htmlspecialchars($t['full_name']) . '">';
    }
    return teacher_avatar_svg($t['gender'] ?? '');
}
function teacher_save_photo($file, &$error) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) { $error = 'Photo upload failed.'; return null; }
    if ($file['size'] > 2 * 1024 * 1024) { $error = 'Photo must be under 2 MB.'; return null; }
    $info = @getimagesize($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime'] ?? ''] ?? null;
    if (!$ext) { $error = 'Photo must be a JPG, PNG, WEBP or GIF image.'; return null; }
    if (!is_dir(TEACHER_UPLOAD_DIR)) { @mkdir(TEACHER_UPLOAD_DIR, 0755, true); }
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], TEACHER_UPLOAD_DIR . '/' . $name)) { $error = 'Could not save photo.'; return null; }
    return $name;
}
function teacher_delete_photo($name) {
    if ($name && preg_match('/^[a-f0-9]{16}\.(jpg|png|webp|gif)$/', $name)) { @unlink(TEACHER_UPLOAD_DIR . '/' . $name); }
}

// ===== EXTRA STUDENT INFORMATION (admin fills it, student sees it on login) =====
function student_extra_fields() {
    // column => [label, input type, icon, group]
    return [
        'roll_no'           => ['Roll / Registration No.', 'text',  'fa-id-badge',        'Student'],
        'date_of_birth'     => ['Date of Birth',           'date',  'fa-cake-candles',    'Student'],
        'student_phone'     => ['Student Phone Number',    'tel',   'fa-mobile-screen',   'Student'],
        'student_email'     => ['Student Email',           'email', 'fa-envelope',        'Student'],
        'school_name'       => ['School / College',        'text',  'fa-school',          'Student'],
        'admission_date'    => ['Admission Date',          'date',  'fa-calendar-check',  'Student'],
        'father_name'       => ['Father Name',             'text',  'fa-user-tie',        'Family'],
        'father_phone'      => ['Father Phone Number',     'tel',   'fa-phone',           'Family'],
        'emergency_contact' => ['Emergency Contact Number','tel',   'fa-truck-medical',   'Family'],
        'address'           => ['Home Address',            'textarea','fa-location-dot',  'Family'],
    ];
}
function student_extra_collect($src) {
    $out = [];
    foreach (student_extra_fields() as $col => $def) {
        $v = trim((string)($src['std_x_' . $col] ?? ''));
        if ($def[1] === 'date') { $v = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; }
        elseif ($def[1] === 'textarea') { $v = $v === '' ? null : mb_substr($v, 0, 500); }
        else { $v = $v === '' ? null : mb_substr($v, 0, 100); }
        $out[$col] = $v;
    }
    return $out;
}
function student_extra_inputs($row = []) {
    $html = ''; $group = '';
    foreach (student_extra_fields() as $col => $def) {
        if ($def[3] !== $group) { $group = $def[3]; $html .= '<div class="col-12"><div class="fw-bold small text-uppercase text-muted border-bottom pb-1 mt-2">' . htmlspecialchars($group) . ' Information</div></div>'; }
        $val = htmlspecialchars((string)($row[$col] ?? ''));
        $name = 'std_x_' . $col;
        $html .= '<div class="' . ($def[1] === 'textarea' ? 'col-12' : 'col-md-6') . '"><label class="form-label small mb-1">' . htmlspecialchars($def[0]) . '</label>';
        if ($def[1] === 'textarea') { $html .= '<textarea name="' . $name . '" class="form-control" rows="2" maxlength="500">' . $val . '</textarea>'; }
        else { $html .= '<input type="' . $def[1] . '" name="' . $name . '" class="form-control" value="' . $val . '">'; }
        $html .= '</div>';
    }
    return $html;
}

// ===== SITE CONTACT INFO (admin edits, shown on the visitor website) =====
function site_info_defaults() {
    return ['phone' => '+00 000 0000000', 'phone2' => '', 'whatsapp' => '', 'email' => 'info@yourdomain.com', 'address' => 'Your tuition centre address here'];
}
function site_info_get($pdo) {
    $info = site_info_defaults();
    if (!$pdo) return $info;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `site_info` (`k` VARCHAR(40) PRIMARY KEY, `v` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ($pdo->query("SELECT k, v FROM site_info")->fetchAll() as $r) { if (array_key_exists($r['k'], $info)) $info[$r['k']] = (string)$r['v']; }
    } catch (Throwable $e) {}
    return $info;
}

function redirect($url) {
    if (($_GET['action'] ?? '') === 'admin_qbank' && strpos($url, 'index.php?action=admin_mcqs') === 0) {
        $url = 'index.php?action=admin_qbank' . substr($url, strlen('index.php?action=admin_mcqs'));
    }
    header("Location: $url");
    exit();
}


// ---- Smart MCQ text parser: handles "Q1. ... A) ... B) ..." blocks, "Question | A | B | C | D" rows,
// ---- an embedded "ANSWER KEY" section ("1. C) 6,000", "2 B", "3-A") and per-question "Answer: C" lines.
function mcq_parse_key_text($text) {
    $keys = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
        $line = trim($line);
        if ($line === '' || preg_match('/^(answer\s*key|answers?|key)\s*:?$/i', $line)) continue;
        if (preg_match_all('/(?:^|[\s,;])(?:Q\s*)?(\d+)\s*[.:)\-]?\s*\(?([A-D])\)?(?=[\s,;.)]|$)/i', $line, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) { if (!isset($keys[(int)$m[1]])) $keys[(int)$m[1]] = strtoupper($m[2]); }
        }
    }
    return $keys;
}
function mcq_parse_text($text) {
    $text = str_replace(["\xEF\xBB\xBF", "\xC2\xA0"], ['', ' '], (string)$text);
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $qs = []; $cur = null; $keyLines = []; $inKey = false;
    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '') continue;
        if (!$inKey && preg_match('/^(?:[#*_\s]*)(answer\s*key|answers?\s*key|answers|key)\b[^A-Za-z]*$/i', $line)) { $inKey = true; $cur = null; continue; }
        if ($inKey) { $keyLines[] = $line; continue; }
        if (substr_count($line, '|') >= 4) {                       // Question | A | B | C | D
            $parts = array_map('trim', preg_split('/\s*\|\s*/', $line));
            if (count($parts) >= 5) { $qs[] = ['n'=>null,'q'=>$parts[0],'opts'=>['A'=>$parts[1],'B'=>$parts[2],'C'=>$parts[3],'D'=>$parts[4]],'ans'=>(isset($parts[5]) && preg_match('/^[A-D]$/i',$parts[5])) ? strtoupper($parts[5]) : null]; $cur = true; continue; }
        }
        if ($cur !== null && preg_match('/^(?:answer|ans|correct(?:\s*answer)?)\s*[:\-]\s*\(?([A-D])\b/i', $line, $m)) { $qs[count($qs)-1]['ans'] = strtoupper($m[1]); continue; }
        if (preg_match('/^(?:Q(?:uestion)?\s*)?(\d+)\s*[.):\-]\s*(.+)$/i', $line, $m)) {
            // Also accept compact one-line input such as: 1. 2+2=? a.3 b.4 c.5 d.6
            // Split the question from inline A-D options, regardless of uppercase/lowercase labels.
            $body = trim($m[2]);
            $optMatches = [];
            if (preg_match_all('/(?:^|\s)\(?([A-D])\s*[).:\-]\s*/i', $body, $optMatches, PREG_OFFSET_CAPTURE) >= 4) {
                $foundLetters = array_map(static function($x) { return strtoupper($x[0]); }, $optMatches[1]);
                if (in_array('A', $foundLetters, true) && in_array('B', $foundLetters, true) && in_array('C', $foundLetters, true) && in_array('D', $foundLetters, true)) {
                    $positions = [];
                    foreach ($optMatches[1] as $oi => $letterMatch) {
                        $letter = strtoupper($letterMatch[0]);
                        if (!isset($positions[$letter])) $positions[$letter] = ['label_start'=>$optMatches[0][$oi][1], 'label_len'=>strlen($optMatches[0][$oi][0]), 'letter'=>$letter];
                    }
                    $ordered = array_values($positions);
                    usort($ordered, static function($a, $b) { return $a['label_start'] <=> $b['label_start']; });
                    $questionText = trim(substr($body, 0, $ordered[0]['label_start']));
                    $inlineOpts = [];
                    for ($oi = 0; $oi < count($ordered); $oi++) {
                        $start = $ordered[$oi]['label_start'] + $ordered[$oi]['label_len'];
                        $end = ($oi + 1 < count($ordered)) ? $ordered[$oi + 1]['label_start'] : strlen($body);
                        $inlineOpts[$ordered[$oi]['letter']] = trim(substr($body, $start, $end - $start));
                    }
                    if ($questionText !== '' && isset($inlineOpts['A'], $inlineOpts['B'], $inlineOpts['C'], $inlineOpts['D']) && !in_array('', $inlineOpts, true)) {
                        $qs[] = ['n'=>(int)$m[1], 'q'=>$questionText, 'opts'=>$inlineOpts, 'ans'=>null]; $cur = true; continue;
                    }
                }
            }
            $qs[] = ['n'=>(int)$m[1],'q'=>$body,'opts'=>[],'ans'=>null]; $cur = true; continue;
        }
        if ($cur !== null && !empty($qs)) {
            $i = count($qs)-1;
            if (preg_match_all('/(?:^|\s)\(?([A-D])[).]\s+/', $line) >= 3 && preg_match('/^\(?A[).]/i', $line)) {   // options all on one line
                $chunks = preg_split('/(?:^|\s)\(?([A-D])[).]\s+/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
                for ($k=0; $k+1<count($chunks); $k+=2) { $qs[$i]['opts'][strtoupper($chunks[$k])] = trim($chunks[$k+1]); }
                continue;
            }
            if (preg_match('/^\(?([A-D])\s*[).:\-]\s*(.*)$/i', $line, $m)) {
                $L = strtoupper($m[1]); $rest = $m[2];
                if (!isset($qs[$i]['opts'][$L])) { $qs[$i]['opts'][$L] = trim($rest); continue; }
            }
            if (!empty($qs[$i]['opts'])) { $last = array_key_last($qs[$i]['opts']); $qs[$i]['opts'][$last] .= ' ' . $line; }
            else { $qs[$i]['q'] .= ' ' . $line; }
        }
    }
    $keys = mcq_parse_key_text(implode("\n", $keyLines));
    $draft = []; $missing = []; $incomplete = 0;
    foreach ($qs as $idx => $q) {
        $n = $q['n'] ?? ($idx + 1);
        $o = $q['opts'];
        if (!isset($o['A'],$o['B'],$o['C'],$o['D']) || trim($q['q']) === '' || $o['A']==='' || $o['B']==='' || $o['C']==='' || $o['D']==='') { $incomplete++; continue; }
        $correct = $q['ans'] ?: ($keys[$n] ?? null);
        $row = ['question_text'=>trim($q['q']),'option_a'=>trim($o['A']),'option_b'=>trim($o['B']),'option_c'=>trim($o['C']),'option_d'=>trim($o['D']),'correct_option'=>$correct,'_n'=>$n];
        if (!$correct) { $missing[] = $n; }
        $draft[] = $row;
    }
    return ['rows'=>$draft,'missing'=>$missing,'incomplete'=>$incomplete,'keys'=>$keys,'found'=>count($qs)];
}
function mcq_norm_letter($v, $opts = []) {
    if ($v === null || is_bool($v) || is_array($v)) return null;
    $s = trim((string)$v); if ($s === '') return null;
    if (preg_match('/^\(?([A-Da-d])\)?\s*[.):]?\s*$/', $s, $m)) return strtoupper($m[1]);
    if (preg_match('/^option\s*\(?([A-Da-d])\)?\s*$/i', $s, $m)) return strtoupper($m[1]);
    if (preg_match('/^\(?([A-Da-d])\s*[.):]\s+\S/', $s, $m)) return strtoupper($m[1]);
    if (preg_match('/^[0-4]$/', $s)) return $s === '0' ? 'A' : chr(64 + (int)$s);
    foreach ($opts as $L => $t) { if (mb_strtolower(trim((string)$t)) === mb_strtolower($s)) return $L; }
    return null;
}
// Reads a test/MCQ JSON file: the portal's own export (tuition-test-v1), a backup, {"questions":[...]}, or a plain list.
// Each question carries its own key (correct_option / answer / correct ...) or an "answer_key" map is supplied.
function mcq_parse_json($text) {
    $d = json_decode(trim(str_replace("\xEF\xBB\xBF", '', (string)$text)), true);
    if (!is_array($d)) return null;
    $isList = function($a){ return $a === [] || array_keys($a) === range(0, count($a) - 1); };
    if (isset($d['tables']['questions']) && is_array($d['tables']['questions'])) $list = $d['tables']['questions'];
    elseif (isset($d['questions']) && is_array($d['questions'])) $list = $d['questions'];
    elseif ($isList($d)) $list = $d;
    else return null;
    $keyMap = [];
    foreach (['answer_key','answers','keys','key'] as $kn) {
        if (!isset($d[$kn]) || !is_array($d[$kn])) continue;
        $kl = $isList($d[$kn]);
        foreach ($d[$kn] as $i => $v) {
            $n = $kl ? ((int)$i + 1) : (int)preg_replace('/\D/', '', (string)$i);
            $L = mcq_norm_letter($v); if ($n > 0 && $L && !isset($keyMap[$n])) $keyMap[$n] = $L;
        }
    }
    $rows = []; $missing = []; $subject = '';
    foreach ($list as $idx => $q) {
        if (!is_array($q)) continue;
        $n = (int)($q['n'] ?? $q['number'] ?? $q['no'] ?? ($idx + 1)); if ($n < 1) $n = $idx + 1;
        $qt = '';
        foreach (['question_text','question','q','text','title'] as $k) { if (isset($q[$k]) && !is_array($q[$k]) && trim((string)$q[$k]) !== '') { $qt = trim((string)$q[$k]); break; } }
        $o = [];
        foreach (['A','B','C','D'] as $L) {
            foreach (['option_'.strtolower($L), 'option'.$L, 'opt_'.strtolower($L), strtolower($L), $L] as $k) {
                if (isset($q[$k]) && !is_array($q[$k]) && trim((string)$q[$k]) !== '') { $o[$L] = trim((string)$q[$k]); break; }
            }
        }
        if (count($o) < 4) {
            $arr = $q['options'] ?? $q['choices'] ?? null;
            if (is_array($arr)) {
                $o = [];
                if ($isList($arr)) { foreach (array_values($arr) as $i => $t) { if ($i < 4 && !is_array($t)) $o[chr(65 + $i)] = trim((string)$t); } }
                else { foreach ($arr as $k => $t) { $L = strtoupper(trim((string)$k)); if (in_array($L, ['A','B','C','D'], true) && !is_array($t)) $o[$L] = trim((string)$t); } }
                if (count($o) === 4) { $strip = true; foreach (['A','B','C','D'] as $L) { if (!preg_match('/^\(?'.$L.'\s*[.)]\s+/i', $o[$L])) $strip = false; } if ($strip) { foreach ($o as $L => $t) $o[$L] = preg_replace('/^\(?'.$L.'\s*[.)]\s+/i', '', $t); } }
            }
        }
        if ($qt === '' || count($o) < 4 || in_array('', $o, true)) continue;
        $c = null;
        foreach (['correct_option','correct','answer','correct_answer','right_answer','key'] as $k) { if (isset($q[$k])) { $c = mcq_norm_letter($q[$k], $o); if ($c) break; } }
        if (!$c && isset($keyMap[$n])) $c = $keyMap[$n];
        if ($subject === '' && !empty($q['subject']) && is_string($q['subject'])) $subject = mb_substr(trim($q['subject']), 0, 80);
        if (!$c) $missing[] = $n;
        $rows[] = ['question_text'=>$qt,'option_a'=>$o['A'],'option_b'=>$o['B'],'option_c'=>$o['C'],'option_d'=>$o['D'],'correct_option'=>$c,'_n'=>$n];
    }
    return ['rows'=>$rows,'missing'=>$missing,'subject'=>$subject,'found'=>count($list)];
}
function mcq_read_upload_text($file, &$err) {
    $name = strtolower((string)($file['name'] ?? '')); $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return '';
    if (($file['size'] ?? 0) > 5*1024*1024) { $err = 'File is too large (max 5 MB).'; return ''; }
    if (preg_match('/\.(txt|md|csv|tsv|json|rtf|html|htm)$/', $name)) { $t = (string)file_get_contents($tmp); if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252'); return $t; }
    if (preg_match('/\.docx$/', $name)) {
        if (!class_exists('ZipArchive')) { $err = 'Server cannot read .docx (ZipArchive missing). Save the file as .txt or paste the text.'; return ''; }
        $z = new ZipArchive();
        if ($z->open($tmp) !== true) { $err = 'Could not open the .docx file.'; return ''; }
        $xml = (string)$z->getFromName('word/document.xml'); $z->close();
        $xml = preg_replace('/<\/w:p>/', "\n", $xml); $xml = preg_replace('/<w:tab\/>/', ' ', $xml); $xml = preg_replace('/<w:br[^>]*\/>/', "\n", $xml);
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
    if ($name !== '') $err = 'Upload TXT, MD, CSV, TSV, JSON, RTF, HTML, DOCX or PDF (PDF text is extracted in your browser), or paste the text.';
    return '';
}
function mcq_guess_subject($text) {
    $t = strtolower(mb_substr((string)$text, 0, 6000));
    $map = ['Mathematics'=>['mathematics','math','hcf','lcm','algebra','geometry','fraction','perimeter','probability','equation','triangle','percentage'],'Physics'=>['physics','velocity','newton','force','voltage','current','acceleration','wavelength'],'Chemistry'=>['chemistry','atom','molecule','acid','periodic table','compound','electron','reaction'],'Biology'=>['biology','cell','photosynthesis','organism','dna','enzyme','tissue','species'],'English'=>['english','grammar','noun','verb','synonym','antonym','tense','adjective'],'Computer Science'=>['computer','software','hardware','algorithm','database','internet','cpu','programming'],'History'=>['history','empire','war of','independence','dynasty','treaty'],'Geography'=>['geography','continent','climate','river','mountain','latitude','population']];
    $best = 'General'; $bestN = 0;
    foreach ($map as $sub => $words) { $n = 0; foreach ($words as $w) $n += substr_count($t, $w); if ($sub === 'Mathematics' && strpos($t, 'mathematics') !== false) $n += 5; if ($n > $bestN) { $bestN = $n; $best = $sub; } }
    return $bestN >= 2 ? $best : 'General';
}
function ai_default_key() {
    // Put several keys (from different Google projects) in GEMINI_API_KEYS, separated by commas, for more free quota.
    $k = getenv('GEMINI_API_KEYS') ?: getenv('GEMINI_API_KEY');
    return $k ?: 'AQ.Ab8RN6K2IZcvbt1NtZ8eOnp7f1pLk5DdWh-XpTeESB4z-sD0jQ';
}
function ai_fallback_models() { return ['gemini-2.5-flash','gemini-2.0-flash','gemini-2.5-flash-lite','gemini-flash-latest']; }
function ai_pick_models($apiKey, &$keyErr) {
    // Ask Google which text models this key can use now, then ALWAYS append stable fallbacks (a busy or out-of-quota model must never end the attempt).
    $keyErr = ''; $fallback = ai_fallback_models();
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['x-goog-api-key: '.$apiKey]]);
    $resp = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($resp === false || $http === 0) return $fallback;
    $d = json_decode((string)$resp, true);
    if (in_array($http, [400,401,403], true)) { $keyErr = 'Google rejected the API key (HTTP '.$http.'): '.mb_substr((string)($d['error']['message'] ?? 'invalid key'), 0, 200).' Create a new key at aistudio.google.com/apikey and put it in index.php.'; return []; }
    if ($http < 200 || $http >= 300 || !is_array($d['models'] ?? null)) return $fallback;
    $found = [];
    foreach ($d['models'] as $m) {
        $name = preg_replace('#^models/#', '', (string)($m['name'] ?? ''));
        if (!preg_match('/^gemini-\d/', $name) || stripos($name, 'flash') === false) continue;   // numbered Gemini flash models only (skips "omni", aliases, etc.)
        if (preg_match('/image|tts|live|audio|embed|thinking|exp|robotics|computer|native|lite|preview|omni/i', $name)) continue;
        if (!in_array('generateContent', (array)($m['supportedGenerationMethods'] ?? []), true)) continue;
        preg_match('/gemini-(\d+(?:\.\d+)?)/', $name, $vm);
        $found[$name] = (float)($vm[1] ?? 0);
    }
    uksort($found, function($a, $b) use ($found) { return $found[$b] <=> $found[$a] ?: strlen($a) <=> strlen($b); });
    $list = array_slice(array_keys($found), 0, 3);
    foreach ($fallback as $f) if (!in_array($f, $list, true)) $list[] = $f;
    return array_slice($list, 0, 6);
}
function ai_call_json($prompt, $apiKey, &$apiErr) {
    // Tries every model x every key. 503/500 = busy -> retry with a short wait. 429 = quota -> skip to the next model/key (never stop).
    $apiErr = ''; $notes = []; $keyErr = ''; $started = microtime(true); $budget = 105; $netFails = 0; $tries = 0;
    $keys = array_values(array_filter(array_map('trim', explode(',', (string)$apiKey))));
    if (!$keys) { $apiErr = 'No Gemini API key is configured.'; return null; }
    $models = ai_pick_models($keys[0], $keyErr);
    if ($keyErr !== '') { if (count($keys) === 1) { $apiErr = $keyErr; return null; } $models = ai_fallback_models(); }
    foreach ($models as $aiModel) {
        foreach ($keys as $ki => $k) {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                if (microtime(true) - $started > $budget) break 3;
                $tries++;
                $payload = ['model'=>$aiModel,'messages'=>[['role'=>'system','content'=>'You identify the subject of study material and generate or extract accurate educational MCQs. Return valid JSON only, with no markdown.'],['role'=>'user','content'=>$prompt]],'temperature'=>0.2];
                $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/openai/chat/completions');
                curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>70,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$k,'x-goog-api-key: '.$k],CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE)]);
                $resp = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlErr = curl_error($ch); curl_close($ch);
                $tag = $aiModel.(count($keys) > 1 ? ' (key '.($ki+1).')' : '');
                if ($resp === false || $http === 0) { $netFails++; $notes[] = $tag.': no connection'; if ($attempt < 1) { sleep(1); continue; } break; }
                if ($http >= 200 && $http < 300) {
                    $decoded = json_decode((string)$resp, true);
                    $g = ai_extract_json($decoded['choices'][0]['message']['content'] ?? '');
                    if (is_array($g) && isset($g[0]) && is_array($g[0])) $g = ['questions'=>$g];
                    if (is_array($g['questions'] ?? null) && $g['questions']) {
                        foreach ($g['questions'] as $qi => $qrow) { if (!is_array($qrow)) { unset($g['questions'][$qi]); continue; } $co = strtoupper(trim((string)($qrow['correct_option'] ?? ''))); if (preg_match('/[A-D]/', $co, $cm)) $g['questions'][$qi]['correct_option'] = $cm[0]; }
                        $g['questions'] = array_values($g['questions']); return $g;
                    }
                    $notes[] = $tag.': reply was not valid MCQ JSON'; if ($attempt < 1) continue; break;
                }
                if (in_array($http, [500,502,503,504], true)) { $notes[] = $tag.': busy (HTTP '.$http.')'; if ($attempt < 2) { sleep(2 << $attempt); continue; } break; }
                if ($http === 429) { $notes[] = $tag.': quota reached (HTTP 429)'; break; }
                if (in_array($http, [401,403], true)) { $notes[] = $tag.': key rejected (HTTP '.$http.')'; break; }
                $notes[] = $tag.': HTTP '.$http; break;
            }
        }
    }
    $notes = array_values(array_unique($notes));
    if ($tries > 0 && $netFails === $tries) { $apiErr = 'Could not reach Google AI from this server. Free hosts such as InfinityFree block outgoing connections.'; return null; }
    $all = implode(' ', $notes); $hint = '';
    if (strpos($all, 'busy') !== false) $hint .= ' Google AI is overloaded right now: wait a minute and press the button again.';
    if (strpos($all, 'quota') !== false) $hint .= ' The free AI quota is used up for now: try later, add another key in GEMINI_API_KEYS, or use the Type / Upload tabs.';
    $apiErr = 'AI failed. '.($notes ? mb_substr(implode(' | ', $notes), 0, 380) : 'no available model found').'.'.$hint;
    return null;
}
function mcq_draft_add($rows, $subject = '') {
    // One shared draft for ALL three ways of adding MCQs (Upload, AI, ...). Duplicates are skipped.
    $cur = $_SESSION['mcq_draft'] ?? []; if (!is_array($cur)) $cur = [];
    $seen = []; foreach ($cur as $r) $seen[mb_strtolower(trim((string)($r['question_text'] ?? '')))] = 1;
    $added = 0;
    foreach ((array)$rows as $r) {
        if (!is_array($r) || count($cur) >= 300) continue;
        $c = strtoupper(trim((string)($r['correct_option'] ?? ''))); if (preg_match('/[A-D]/', $c, $m)) $c = $m[0];
        $row = ['question_text'=>trim((string)($r['question_text']??'')),'option_a'=>trim((string)($r['option_a']??'')),'option_b'=>trim((string)($r['option_b']??'')),'option_c'=>trim((string)($r['option_c']??'')),'option_d'=>trim((string)($r['option_d']??'')),'correct_option'=>$c];
        if ($row['question_text']==='' || $row['option_a']==='' || $row['option_b']==='' || $row['option_c']==='' || $row['option_d']==='' || !in_array($c, ['A','B','C','D'], true)) continue;
        $h = mb_strtolower($row['question_text']); if (isset($seen[$h])) continue; $seen[$h] = 1;
        $cur[] = $row; $added++;
    }
    $_SESSION['mcq_draft'] = $cur;
    $subject = trim((string)$subject);
    if ($subject !== '' && (empty($_SESSION['mcq_draft_subject']) || $_SESSION['mcq_draft_subject'] === 'General')) $_SESSION['mcq_draft_subject'] = mb_substr($subject, 0, 80);
    return $added;
}
function ai_extract_prompt($text) {
    return "The text below contains existing multiple-choice questions (possibly with messy formatting, numbering like Q1/1./(1), options A-D in any style, and an answer key somewhere). Detect ALL of them and extract each exactly as written; do not invent or drop questions. Use the supplied answer key for correct_option; if a question has no key, solve it and choose the correct option. Also detect the school subject as detected_subject. Return a JSON object with keys detected_subject and questions (array). Each question has question_text, option_a, option_b, option_c, option_d, correct_option (A/B/C/D). Keep math notation as plain text or LaTeX \\( ... \\); do not use HTML.\nTEXT:\n".mb_substr($text, 0, 30000);
}
function ai_extract_json($s) {
    $s = trim((string)$s);
    $s = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $s);
    $d = json_decode($s, true);
    if (is_array($d)) return $d;
    $a = strpos($s, '{'); $b = strrpos($s, '}');
    if ($a !== false && $b !== false && $b > $a) { $d = json_decode(substr($s, $a, $b-$a+1), true); if (is_array($d)) return $d; }
    return null;
}

function get_global_settings($pdo) {
    if (!$pdo) return ['time_limit_minutes' => 90, 'test_expiry_date' => null];
    $stmt = $pdo->query("SELECT time_limit_minutes, test_expiry_date FROM settings WHERE id = 1");
    $row = $stmt->fetch();
    return $row ? $row : ['time_limit_minutes' => 90, 'test_expiry_date' => null];
}

// ===== MODULE ENGINE (generic add / edit / delete / search / export pages) =====
$MODULE_GROUPS = ['Academics' => 'fa-graduation-cap'];
// key => [Title, icon, Group, student visibility (own|all|none), field spec]
$MODULES = [
 // ---- Academics ----
 'attendance' => ['Attendance','fa-calendar-check','Academics','own','student:Student|date:Date|select:Status:Present,Absent,Late,Leave|text:Remarks'],
 'homework' => ['Homework','fa-book','Academics','all','class:Class|text:Subject|text:Title|textarea:Details|date:Due Date|targetclass:Visible To Class|select:Visible To Gender:All,Male,Female'],
 'timetable' => ['Timetable','fa-table','Academics','all','class:Class|select:Day:Mon,Tue,Wed,Thu,Fri,Sat,Sun|text:Subject|text:Time|text:Teacher|targetclass:Visible To Class|select:Visible To Gender:All,Male,Female'],
 'notes' => ['Study Notes','fa-note-sticky','Academics','all','class:Class|text:Subject|text:Title|url:Link|textarea:Description|targetclass:Visible To Class|select:Visible To Gender:All,Male,Female'],
];

function mod_fields($spec) {
    $out = []; $i = 1;
    foreach (explode('|', $spec) as $part) {
        $p = explode(':', $part, 3);
        $out[] = ['col' => 'c' . $i++, 't' => $p[0], 'l' => $p[1] ?? '', 'o' => isset($p[2]) ? explode(',', $p[2]) : []];
    }
    return $out;
}
function mod_table($key) { return 'mod_' . preg_replace('/[^a-z0-9_]/', '', $key); }
function mod_ensure($pdo, $key) {
    global $MODULES;
    static $done = [];
    if (isset($done[$key])) return;
    $done[$key] = true;
    $cols = [];
    foreach (mod_fields($MODULES[$key][4]) as $f) {
        switch ($f['t']) {
            case 'textarea': $type = 'TEXT'; break;
            case 'number':   $type = 'DECIMAL(12,2)'; break;
            case 'date':     $type = 'DATE'; break;
            case 'student':  $type = 'INT'; break;
            case 'targetclass': $type = 'VARCHAR(50)'; break;
            case 'url':      $type = 'VARCHAR(500)'; break;
            default:         $type = 'VARCHAR(255)';
        }
        $cols[] = "`{$f['col']}` $type NULL DEFAULT NULL";
    }
    $table = mod_table($key);
    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $table . "` (`id` INT AUTO_INCREMENT PRIMARY KEY, " . implode(', ', $cols) . ", `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Add new targeting columns to existing installations without deleting records.
    $existing = $pdo->query("SHOW COLUMNS FROM `" . $table . "`")->fetchAll(PDO::FETCH_COLUMN);
    foreach (mod_fields($MODULES[$key][4]) as $f) {
        if (in_array($f['col'], $existing, true)) continue;
        $type = $f['t'] === 'targetclass' ? 'VARCHAR(50)' : ($f['t'] === 'select' ? 'VARCHAR(255)' : ($f['t'] === 'textarea' ? 'TEXT' : ($f['t'] === 'date' ? 'DATE' : ($f['t'] === 'student' ? 'INT' : ($f['t'] === 'number' ? 'DECIMAL(12,2)' : ($f['t'] === 'url' ? 'VARCHAR(500)' : 'VARCHAR(255)'))))));
        $pdo->exec("ALTER TABLE `" . $table . "` ADD COLUMN `" . $f['col'] . "` $type NULL DEFAULT NULL");
    }
}
function mod_public($pdo, $key, $limit = 6) {
    try {
        mod_ensure($pdo, $key);
        return $pdo->query("SELECT * FROM `" . mod_table($key) . "` ORDER BY id DESC LIMIT " . (int)$limit)->fetchAll();
    } catch (Throwable $e) { return []; }
}
function mod_input($f, $val, $smap, $classes) {
    $n = 'f[' . $f['col'] . ']';
    $v = htmlspecialchars((string)$val);
    switch ($f['t']) {
        case 'textarea':
            echo "<textarea name=\"$n\" rows=\"3\" class=\"form-control\">$v</textarea>"; break;
        case 'number':
            echo "<input type=\"number\" step=\"any\" name=\"$n\" value=\"" . ($val === null || $val === '' ? '' : (float)$val) . "\" class=\"form-control\">"; break;
        case 'date':
            echo "<input type=\"date\" name=\"$n\" value=\"" . ($v !== '' ? $v : ($f['l'] === 'Date' ? date('Y-m-d') : '')) . "\" class=\"form-control\">"; break;
        case 'url':
            echo "<input type=\"text\" inputmode=\"url\" maxlength=\"500\" name=\"$n\" value=\"$v\" class=\"form-control\" placeholder=\"https://... (optional, can be left empty)\">"; break;
        case 'email': case 'phone':
            $type = $f['t'] === 'phone' ? 'tel' : $f['t'];
            echo "<input type=\"$type\" name=\"$n\" value=\"$v\" class=\"form-control\">"; break;
        case 'targetclass':
            break; // merged into the single Class dropdown (kept in DB for old records)
        case 'select': case 'class': case 'student':
            $req = ($f['t'] === 'student') ? ' required' : '';
            echo "<select name=\"$n\" class=\"form-select\"$req>" . ($f['t'] === 'class' ? '' : '<option value="">— Select —</option>');
            if ($f['t'] === 'select') { foreach ($f['o'] as $o) { echo '<option' . ((string)$val === $o ? ' selected' : '') . '>' . htmlspecialchars($o) . '</option>'; } }
            elseif ($f['t'] === 'class') { foreach (array_merge(['All Classes'], $classes) as $o) { echo '<option value="' . htmlspecialchars($o) . '"' . ((string)($val ?: 'All Classes') === $o ? ' selected' : '') . '>' . htmlspecialchars($o) . '</option>'; } }
            elseif ($f['t'] === 'targetclass') { foreach (array_merge(['All Classes'], $classes) as $o) { echo '<option value="' . htmlspecialchars($o) . '"' . ((string)($val ?: 'All Classes') === $o ? ' selected' : '') . '>' . htmlspecialchars($o) . '</option>'; } }
            else { foreach ($smap as $id => $nm) { echo '<option value="' . $id . '"' . ((string)$val === (string)$id ? ' selected' : '') . '>' . htmlspecialchars($nm) . '</option>'; } }
            echo '</select>'; break;
        default:
            echo "<input type=\"text\" maxlength=\"255\" name=\"$n\" value=\"$v\" class=\"form-control\">";
    }
}
// Render safe plain-text powers/subscripts while preserving LaTeX for MathJax.
// Supports maths/physics: \(v= u+at\), \[F=ma\], x^{12}, v_{0};
// chemistry: \(\ce{H2O}\), \(\ce{H2SO4}\), ionic equations and states.
function math_text_html($text) {
    $text = (string)$text;
    // Keep explicit MathJax/LaTeX regions untouched; format ordinary typed notation safely.
    $parts = preg_split('/(\\\\\[.*?\\\\\]|\\\\\(.*?\\\\\)|\$\$.*?\$\$|\$[^$\n]+\$)/us', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

    foreach ($parts as $i => $part) {
        $isMath = preg_match('/^(?:\\\\\[|\\\\\(|\$\$|\$)/', $part) === 1;
        if ($isMath) {
            $parts[$i] = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
            continue;
        }

        $safe = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');

        // Common chemistry notation: H2O, CO2, H2SO4, NH3, CaCl2 -> proper subscripts.
        $safe = preg_replace_callback(
            '/(?<![A-Za-z0-9])(?:[A-Z][a-z]?\d*){2,}(?![A-Za-z0-9])|(?<![A-Za-z0-9])[A-Z][a-z]?\d+(?![A-Za-z0-9])/u',
            function ($m) {
                return preg_replace_callback('/([A-Z][a-z]?)(\d+)/u', function ($n) {
                    return $n[1] . '<sub>' . $n[2] . '</sub>';
                }, $m[0]);
            },
            $safe
        );

        // Superscripts and subscripts: x^2, x^{12}, v_0, SO_4^{2-}.
        $safe = preg_replace_callback('/\^\{([^{}]+)\}|\^([A-Za-z0-9+\-]+)/u', function ($m) {
            return '<sup>' . ($m[1] !== '' ? $m[1] : ($m[2] ?? '')) . '</sup>';
        }, $safe);
        $safe = preg_replace_callback('/_\{([^{}]+)\}|_([A-Za-z0-9]+)/u', function ($m) {
            return '<sub>' . ($m[1] !== '' ? $m[1] : ($m[2] ?? '')) . '</sub>';
        }, $safe);

        // Numeric fractions: 2/2, 3/4, (a+b)/(c+d) are written with a stacked bar.
        $safe = preg_replace_callback(
            '/(?<![A-Za-z0-9])(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)(?![A-Za-z0-9])/u',
            function ($m) {
                return '<span class="math-frac" aria-label="' . $m[1] . ' divided by ' . $m[2] . '"><span>' . $m[1] . '</span><span>' . $m[2] . '</span></span>';
            },
            $safe
        );

        // Chemistry reaction arrows: forward, reverse, double and reversible equilibrium.
        $safe = preg_replace_callback('/<=>|<->|⇌|↔|→|←|->|<-|=>|⇄/u', function ($m) {
            $arrows = ['<=>' => '⇌', '<->' => '⇄', '⇌' => '⇌', '↔' => '↔', '→' => '→', '←' => '←', '->' => '→', '<-' => '←', '=>' => '⇒', '⇄' => '⇄'];
            return '<span class="chem-arrow">' . $arrows[$m[0]] . '</span>';
        }, $safe);

        // English emphasis shortcuts: **bold** and *italic*.
        $safe = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $safe);
        $safe = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<em>$1</em>', $safe);

        $parts[$i] = nl2br($safe);
    }
    return implode('', $parts);
}
function save_question_image($file, &$error) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) { $error = 'Question image upload failed.'; return null; }
    if (($file['size'] ?? 0) > 4 * 1024 * 1024) { $error = 'Question image must be 4 MB or smaller.'; return null; }
    $info = @getimagesize($file['tmp_name']);
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$info['mime'] ?? ''] ?? null;
    if (!$ext) { $error = 'Question image must be JPG, PNG, WEBP, or GIF.'; return null; }
    $dir = __DIR__ . '/uploads_questions';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $error = 'Could not create the question image folder.'; return null; }
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) { $error = 'Could not save question image.'; return null; }
    return 'uploads_questions/' . $name;
}

function mod_cell($f, $val, $smap) {
    if ($val === null || $val === '') return '<span class="text-muted">—</span>';
    switch ($f['t']) {
        case 'student': return htmlspecialchars($smap[$val] ?? ('#' . $val));
        case 'number':  return (string)((float)$val);
        case 'date':    return date('d M Y', strtotime($val));
        case 'url':     return preg_match('#^https?://#i', $val) ? '<a href="' . htmlspecialchars($val) . '" target="_blank" rel="noopener">Open <i class="fa-solid fa-up-right-from-square small"></i></a>' : htmlspecialchars($val);
        case 'textarea': return nl2br(htmlspecialchars($val));
        default: return htmlspecialchars($val);
    }
}


// Lenient class/gender matching so "11th", "Class 11th" and " 11TH " all match.
function mod_norm($v) { $v = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$v)); return preg_replace('/^class/', '', $v); }
// Rows of one academic module that a given student is allowed to see.
function mod_student_rows($pdo, $key, $limit = 1000, $ctx = null) {
    global $MODULES;
    mod_ensure($pdo, $key);
    $tbl = mod_table($key);
    $rows = $pdo->query("SELECT * FROM `$tbl` ORDER BY id DESC LIMIT 1000")->fetchAll();
    $out = [];
    $uid = (int)($ctx['id'] ?? ($_SESSION['user_id'] ?? 0));
    $cls = mod_norm($ctx['class'] ?? ($_SESSION['class'] ?? ''));
    $gen = strtolower(trim((string)($ctx['gender'] ?? ($_SESSION['gender'] ?? ''))));
    $ownCol = null;
    foreach (mod_fields($MODULES[$key][4]) as $f) { if ($f['t'] === 'student') { $ownCol = $f['col']; break; } }
    foreach ($rows as $r) {
        if ($ownCol) { if ((int)$r[$ownCol] !== $uid) continue; }
        else {
            $rc = mod_norm($r['c1'] ?? '');
            if ($rc !== '' && $rc !== 'allclasses' && $rc !== $cls) continue;
            $rg = strtolower(trim((string)($r['c7'] ?? '')));
            if ($rg !== '' && $rg !== 'all' && $rg !== $gen) continue;
        }
        $out[] = $r;
        if (count($out) >= $limit) break;
    }
    return $out;
}

$action = $_GET['action'] ?? 'home';
$mod_key = is_string($_GET['m'] ?? null) ? $_GET['m'] : '';
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

if ($action === 'logout') {
    session_destroy();
    redirect("index.php");
}


if ($action === 'login' && isset($_SESSION['role'])) {
    redirect($_SESSION['role'] === 'admin' ? "index.php?action=admin_dashboard" : "index.php?action=student_dashboard");
}

// Passwords are saved as plain text. Old hashed passwords still log in until they are changed.
function verify_login_password($provided, $stored) {
    $info = password_get_info((string)$stored);
    if (($info['algoName'] ?? 'unknown') !== 'unknown') {
        return password_verify($provided, $stored);
    }
    return hash_equals((string)$stored, (string)$provided);
}
// ADMIN: time-based password that changes every minute (Pakistan time, PKT).
// Format: h:mm,AM/PM,d,m,yyyy  e.g. 12:42,PM,2,11,2026  (zero-padded hour/day/month also accepted).
// The current minute and the previous minute are accepted so slow typing doesn't fail.
function admin_time_passwords() {
    $out = [];
    foreach ([0, 60] as $back) {
        $dt = new DateTime('now', new DateTimeZone('Asia/Karachi'));
        if ($back) { $dt->modify('-' . $back . ' seconds'); }
        $tail = $dt->format('i') . ',' . $dt->format('A') . ',';
        $out[] = $dt->format('g') . ':' . $tail . $dt->format('j') . ',' . $dt->format('n') . ',' . $dt->format('Y');
        $out[] = $dt->format('h') . ':' . $tail . $dt->format('d') . ',' . $dt->format('m') . ',' . $dt->format('Y');
    }
    return array_unique($out);
}
function verify_admin_password($provided) {
    foreach (admin_time_passwords() as $ok) {
        if (hash_equals($ok, (string)$provided)) { return true; }
    }
    return false;
}
function upgrade_login_password($pdo, $table, $id, $provided, $stored) {
    // Passwords are stored as plain text now, so nothing is converted to a hash on login.
}

// LOGIN HANDLER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_btn']) && $db_connected) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && verify_admin_password($password)) {
            session_regenerate_id(true);
            upgrade_login_password($pdo, 'admin', $admin['id'], $password, $admin['password']);
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['role'] = 'admin';
            $_SESSION['name'] = $admin['full_name'];
            redirect("index.php?action=admin_dashboard");
        }

        $stmt = $pdo->prepare("SELECT * FROM students WHERE username = ?");
        $stmt->execute([$username]);
        $student = $stmt->fetch();

        if ($student && verify_login_password($password, $student['password'])) {
            session_regenerate_id(true);
            upgrade_login_password($pdo, 'students', $student['id'], $password, $student['password']);
            $_SESSION['user_id'] = $student['id'];
            $_SESSION['role'] = 'student';
            $_SESSION['name'] = $student['full_name'];
            $_SESSION['class'] = $student['class'];
            $_SESSION['stream'] = $student['stream'];
            $_SESSION['gender'] = $student['gender'];
            redirect("index.php?action=student_dashboard");
        }

        $err = "Invalid username or password!";
    } else {
        $err = "Please enter both username and password!";
    }
}

// ADMIN ACTIONS
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && $db_connected) {
    // RESET ONE SPECIFIC TEST ATTEMPT ONLY (never reset all of a student's tests)
    if (isset($_GET['reset_attempt_id'], $_GET['reset_student_id'])) {
        $reset_student_id = (int)$_GET['reset_student_id'];
        $reset_attempt_id = (int)$_GET['reset_attempt_id'];
        if ($reset_student_id > 0 && $reset_attempt_id > 0) {
            $stmtReset = $pdo->prepare("DELETE FROM student_tests WHERE id = ? AND student_id = ?");
            $stmtReset->execute([$reset_attempt_id, $reset_student_id]);
        }
        redirect("index.php?action=admin_dashboard&msg=Selected test attempt has been reset. The student can retake that test.");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student_btn'])) {
        $username = trim($_POST['std_username']);
        $plain_password = trim($_POST['std_password']);
        $full_name = trim($_POST['std_fullname']);
        $class = $_POST['std_class'];
        $stream = $_POST['std_stream'];
        $gender = $_POST['std_gender'];

        try {
            $extra = student_extra_collect($_POST);
            $cols = array_merge(['username', 'password', 'full_name', 'class', 'stream', 'gender'], array_keys($extra));
            $vals = array_merge([$username, $plain_password, $full_name, $class, $stream, $gender], array_values($extra));
            $stmt = $pdo->prepare("INSERT INTO students (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")");
            $stmt->execute($vals);
            redirect("index.php?action=admin_dashboard&msg=Student Registered Successfully!");
        } catch (PDOException $e) {
            $err = "Failed to add student. Username already exists.";
        }
    }

    // Save the contact details shown on the visitor website
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site_info_btn'])) {
        site_info_get($pdo);
        $st = $pdo->prepare("REPLACE INTO site_info (k, v) VALUES (?, ?)");
        foreach (array_keys(site_info_defaults()) as $k) { $st->execute([$k, mb_substr(trim((string)($_POST['si_' . $k] ?? '')), 0, 500)]); }
        redirect("index.php?action=admin_site_info&msg=" . urlencode("Website contact details saved."));
    }

    // Quick-change a student's class (e.g. 12th Pass)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student_class_btn'])) {
        $sid = (int)($_POST['student_id'] ?? 0);
        $nc = trim((string)($_POST['std_class'] ?? ''));
        if ($sid > 0 && $nc !== '') { $pdo->prepare("UPDATE students SET class = ? WHERE id = ?")->execute([mb_substr($nc, 0, 50), $sid]); }
        redirect("index.php?action=admin_students&msg=" . urlencode("Class updated."));
    }

    // Set / clear a single month's fee for a student from the student's own ID page (number or Free)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_month_fee_btn']) && ($_SESSION['role'] ?? '') === 'admin') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $gm = $_POST['fee_month'] ?? '';
        $g = isset($_POST['mark_free']) ? 'Free' : trim((string)($_POST['fee_given'] ?? ''));
        $msg = "Month fee saved.";
        if ($sid > 0 && preg_match('/^\d{4}-\d{2}$/', $gm)) {
            if ($g === '') { $pdo->prepare("DELETE FROM fee_given WHERE student_id = ? AND fee_month = ?")->execute([$sid, $gm]); $msg = "Month fee cleared."; }
            elseif (strcasecmp($g, 'free') === 0) { $pdo->prepare("REPLACE INTO fee_given (student_id, fee_month, given) VALUES (?, ?, 'Free')")->execute([$sid, $gm]); $msg = month_label($gm) . " marked Free."; }
            elseif (is_numeric($g) && (float)$g >= 0) { $pdo->prepare("REPLACE INTO fee_given (student_id, fee_month, given) VALUES (?, ?, ?)")->execute([$sid, $gm, (string)(float)$g]); }
            else { $msg = "Please write a number (e.g. 3000) or the word Free."; }
        }
        redirect("index.php?action=admin_student_view&id=$sid&msg=" . urlencode($msg));
    }

    // Change any student's login username / password
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student_login_btn'])) {
        $sid = (int)($_POST['student_id'] ?? 0);
        $nu = trim((string)($_POST['new_username'] ?? ''));
        $np = trim((string)($_POST['new_password'] ?? ''));
        $back = "index.php?action=admin_student_logins";
        if ($sid <= 0 || $nu === '' || $np === '') { redirect($back . "&err=" . urlencode("Username and password cannot be empty.")); }
        $c1 = $pdo->prepare("SELECT COUNT(*) FROM students WHERE username = ? AND id <> ?"); $c1->execute([$nu, $sid]);
        $c2 = $pdo->prepare("SELECT COUNT(*) FROM admin WHERE username = ?"); $c2->execute([$nu]);
        if ($c1->fetchColumn() > 0 || $c2->fetchColumn() > 0) { redirect($back . "&err=" . urlencode("That username is already used by someone else.")); }
        $pdo->prepare("UPDATE students SET username = ?, password = ? WHERE id = ?")->execute([mb_substr($nu, 0, 50), $np, $sid]);
        redirect($back . "&msg=" . urlencode("Student login updated."));
    }

    // Delete a student account (their tests, answers and fee records are removed by the database)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student_btn'])) {
        $sid = (int)($_POST['student_id'] ?? 0);
        if ($sid > 0) { $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$sid]); }
        redirect("index.php?action=admin_students&msg=" . urlencode("Student ID deleted."));
    }

    // Update a student's full details (name, class, login password and all extra information)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student_details_btn'])) {
        $sid = (int)($_POST['student_id'] ?? 0);
        $extra = student_extra_collect($_POST);
        $sets = ['full_name = ?', 'class = ?', 'stream = ?', 'gender = ?'];
        $vals = [trim($_POST['std_fullname'] ?? ''), $_POST['std_class'] ?? '', $_POST['std_stream'] ?? '', ($_POST['std_gender'] ?? 'Female') === 'Male' ? 'Male' : 'Female'];
        $newpw = trim($_POST['std_password'] ?? '');
        if ($newpw !== '') { $sets[] = 'password = ?'; $vals[] = $newpw; }
        foreach ($extra as $c => $v) { $sets[] = "`$c` = ?"; $vals[] = $v; }
        $vals[] = $sid;
        if ($sid > 0 && $vals[0] !== '') {
            $pdo->prepare("UPDATE students SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
            redirect("index.php?action=admin_dashboard&msg=" . urlencode("Student details updated."));
        }
        $err = "Student name is required.";
    }

    // AI MCQ generation: automatically identify the subject from the supplied material.
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ai_generate_mcqs_btn']) && ($_SESSION['role']??'')==='admin') {
        $_SESSION['mcq_tab']='ai';
        $material=trim((string)($_POST['ai_material']??''));
        $difficulty=(string)($_POST['ai_difficulty']??'medium');
        if (!in_array($difficulty,['easy','medium','hard'],true)) $difficulty='medium';
        // The admin may choose a subject explicitly, or leave it on Auto Detect.
        $selectedSubject=trim((string)($_POST['ai_subject']??'Auto'));
        $allowedSubjects=['Auto','Mathematics','Physics','Chemistry','Biology','Computer Science','English'];
        if (!in_array($selectedSubject,$allowedSubjects,true)) $selectedSubject='Auto';

        // Chat-style subject shortcuts: a command at the start of the pasted prompt
        // selects the matching subject and is removed before material validation.
        if (preg_match('/^\\s*\\/biology\\b\\s*/i', $material)) {
            $selectedSubject='Biology';
            $material=preg_replace('/^\\s*\\/biology\\b\\s*/i', '', $material, 1);
        } elseif (preg_match('/^\\s*\\/computer\\b\\s*/i', $material)) {
            $selectedSubject='Computer Science';
            $material=preg_replace('/^\\s*\\/computer\\b\\s*/i', '', $material, 1);
        }

        $mcqCount=filter_var($_POST['ai_mcq_count']??20,FILTER_VALIDATE_INT);
        if ($mcqCount===false || $mcqCount<1 || $mcqCount>100) redirect('index.php?action=admin_mcqs&err='.urlencode('Choose a number of MCQs between 1 and 100.'));
        if (!empty($_FILES['ai_material_file']['tmp_name'])) { $upErr=''; $material .= "\n\n".mcq_read_upload_text($_FILES['ai_material_file'],$upErr); if ($upErr!=='') redirect('index.php?action=admin_mcqs&err='.urlencode($upErr)); }
        $material=trim($material);
        if (preg_match('/^\s*[\[{]/',$material)) {   // a test/MCQ JSON file with keys: use it directly, no AI call
            $jpA=mcq_parse_json($material);
            if ($jpA && $jpA['rows']) {
                $readyJ=array_values(array_filter($jpA['rows'],function($r){return !empty($r['correct_option']);}));
                if ($readyJ) { $readyJ=array_map(function($r){unset($r['_n']);return $r;},array_slice($readyJ,0,100)); $addedJ=mcq_draft_add($readyJ,$jpA['subject']!==''?$jpA['subject']:'General'); redirect('index.php?action=admin_mcqs&msg='.urlencode('JSON file read: '.$addedJ.' new questions with answers were added to the draft. Review the draft, then save.')); }
            }
        }
        if (mb_strlen($material)<100) redirect('index.php?action=admin_mcqs&err='.urlencode('Upload or paste at least 100 characters of study material first.'));
        // If the pasted material already contains ready-made MCQs (with an answer key), use them directly - no AI call needed.
        $local = mcq_parse_text($material);
        $localReady = array_values(array_filter($local['rows'], function($r){ return !empty($r['correct_option']); }));
        if (count($localReady) >= 3) {
            $localReady = array_slice(array_map(function($r){ unset($r['_n']); return $r; }, $localReady), 0, 100);
            $added=mcq_draft_add($localReady, mcq_guess_subject($material));
            redirect('index.php?action=admin_mcqs&msg='.urlencode('Your material already contained MCQs: '.$added.' new questions with answers were added to the draft (no AI needed). Review the draft, then save.'));
        }
        @set_time_limit(150);
        $extractMode = ($local['found'] >= 3);   // questions are present but some have no key: let AI extract and solve them
        // Prefer a hosting environment variable; use the supplied key as a fallback.
        $apiKey=ai_default_key();
        if ($apiKey==='') redirect('index.php?action=admin_mcqs&err='.urlencode('AI generator needs GEMINI_API_KEY configured in the PHP hosting environment.'));
        if (!function_exists('curl_init')) redirect('index.php?action=admin_mcqs&err='.urlencode('AI generator requires the PHP cURL extension on the server.'));
        $difficultyInstructions = ['easy'=>'Use easy questions testing basic recall and simple understanding.','medium'=>'Use medium questions requiring understanding and a few reasoning steps.','hard'=>'Use hard questions requiring deeper reasoning, application, and challenging but fair distractors.'];
        $subjectChoice = $selectedSubject === 'Auto'
            ? "Detect the subject from the supplied material."
            : "The selected subject is {$selectedSubject}. Treat this selection as authoritative and generate {$selectedSubject} questions only.";
        $formatInstructions = [
            'Mathematics' => 'MATHEMATICS FORMAT: Write calculation, algebra, geometry, trigonometry, statistics and probability MCQs. Include relevant mathematical expressions/equations where appropriate. Use correct symbols, fractions, powers, roots and LaTeX delimiters \\( ... \\) for inline maths and \\[ ... \\] for display equations. Make numerical options mathematically consistent.',
            'Physics' => 'PHYSICS FORMAT: Write concept, law, unit and numerical/application MCQs. Include relevant physical equations and formulas (for example \\(F=ma\\), \\(v=u+at\\), \\(V=IR\\)). Use SI units, symbols and LaTeX delimiters \\( ... \\) or \\[ ... \\]. Numerical options must be calculated correctly.',
            'Chemistry' => 'CHEMISTRY FORMAT: Write conceptual, reaction, mole, bonding and calculation MCQs. Use chemically correct formulas and balanced equations, with subscripts, superscripts, ionic charges and state symbols (for example \\(\\ce{H2SO4}\\), \\(\\ce{2H2 + O2 -> 2H2O}\\)). Use \\ce{...} inside math delimiters for chemical notation.',
            'Biology' => 'BIOLOGY FORMAT: Write biology MCQs based on the supplied material, covering cell structure and function, genetics, DNA/RNA, human and plant systems, ecology, evolution, microorganisms, classification and biological processes as relevant. Use accurate scientific terminology, organism and species names, labelled-structure descriptions, and clear process or cause-and-effect questions. Use subscripts/superscripts where biologically relevant (for example CO2, O2, ATP, DNA), and do not invent facts beyond the material.',
            'Computer Science' => 'COMPUTER SCIENCE FORMAT: Write computer and information technology MCQs based on the supplied material, covering programming, algorithms, pseudocode, flowcharts, data structures, databases, networks, hardware, software, operating systems, cybersecurity and digital concepts as relevant. Use code snippets, syntax, logical expressions, binary/decimal calculations, truth tables or step-by-step algorithm tracing where appropriate. Keep code and technical terminology accurate, and make output-based questions unambiguous.',
            'English' => 'ENGLISH FORMAT: Write language-based MCQs on grammar, vocabulary, comprehension, sentence correction, parts of speech, tenses, synonyms, antonyms and usage as supported by the material. Use natural, grammatically correct English sentences and standard text; do not add equations unless the source requires them.'
        ];
        $formatInstruction = $selectedSubject === 'Auto'
            ? 'Use subject-appropriate formatting: Mathematics uses equations and calculations; Physics uses laws, formulas, SI units and numerical problems; Chemistry uses formulas, balanced reactions, subscripts, superscripts and ionic charges; Biology uses accurate biological terminology, structures and processes; Computer Science uses algorithms, code, logic, data representation and technical concepts; English uses grammar, vocabulary and sentence-based questions.'
            : $formatInstructions[$selectedSubject];
        $prompt="{$subjectChoice} Create exactly {$mcqCount} {$difficulty} MCQs based ONLY on the supplied material. Return the final subject name in detected_subject. {$difficultyInstructions[$difficulty]} {$formatInstruction} Return JSON with detected_subject and questions. Each question must contain question_text, option_a, option_b, option_c, option_d, and correct_option (A/B/C/D). Create exactly {$mcqCount} distinct questions, ensure one correct answer, use plausible distractors, and do not use HTML.\nSTUDY MATERIAL:\n".mb_substr($material,0,30000);
        if (!empty($extractMode)) {
            $prompt = ai_extract_prompt($material);
            if ($selectedSubject !== 'Auto') $prompt .= "\nSelected subject: {$selectedSubject}. Preserve the supplied questions and options, and keep the notation appropriate to this subject.";
        }
        $apiErr=''; $generated=ai_call_json($prompt,$apiKey,$apiErr);
        if (!$generated) redirect('index.php?action=admin_mcqs&err='.urlencode($apiErr!==''?$apiErr:'AI request failed. Check the API key and hosting outbound access, then try again.'));
        if (!empty($extractMode)) $mcqCount = 100;
        $detectedSubject = $selectedSubject !== 'Auto'
            ? $selectedSubject
            : trim((string)($generated['detected_subject']??'Other'));
        if ($detectedSubject==='') $detectedSubject='Other';
        $detectedSubject=mb_substr($detectedSubject,0,80);
        $added=mcq_draft_add(array_slice($generated['questions'],0,$mcqCount), $detectedSubject);
        redirect('index.php?action=admin_mcqs&msg='.urlencode('AI detected the subject as '.$detectedSubject.' and added '.$added.' MCQs to the draft. Review the draft, then save.'));
    }
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['combine_tests_btn']) && ($_SESSION['role']??'')==='admin') {
        $newName=trim((string)($_POST['combine_test_name']??''));
        $sources=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['source_test_ids']??[])),fn($x)=>$x>0)));
        if ($newName==='' || mb_strlen($newName)>150) redirect('index.php?action=combine_tests&err='.urlencode('Write the test name (up to 150 characters).'));
        if (!$sources) redirect('index.php?action=combine_tests&err='.urlencode('Select at least one source test to combine.'));
        $pdo->beginTransaction();
        try {
            // Use the written name: an existing test with that name receives the questions, otherwise a new test is created from the first source test's settings (it starts Restricted).
            $find=$pdo->prepare('SELECT id FROM tests_meta WHERE test_name=?'); $find->execute([$newName]); $target=(int)$find->fetchColumn();
            $sources=array_values(array_filter($sources,fn($x)=>$x!==$target));
            if (!$sources) { $pdo->rollBack(); redirect('index.php?action=combine_tests&err='.urlencode('Select at least one source test other than the test you named.')); }
            if (!$target) {
                $first=$pdo->prepare('SELECT class,stream,gender_target,time_limit_minutes FROM tests_meta WHERE id=?'); $first->execute([$sources[0]]); $fr=$first->fetch();
                if (!$fr) { $pdo->rollBack(); redirect('index.php?action=combine_tests&err='.urlencode('Source test not found.')); }
                $pdo->prepare('INSERT INTO tests_meta (test_name,class,stream,gender_target,time_limit_minutes,is_live) VALUES (?,?,?,?,?,0)')->execute([$newName,$fr['class'],$fr['stream'],$fr['gender_target'],$fr['time_limit_minutes']]);
                $target=(int)$pdo->lastInsertId();
            }
            $up=$pdo->prepare('UPDATE questions SET test_id=?, class=(SELECT class FROM tests_meta WHERE id=?), stream=(SELECT stream FROM tests_meta WHERE id=?), gender_target=(SELECT gender_target FROM tests_meta WHERE id=?) WHERE test_id=?');
            foreach($sources as $src){$up->execute([$target,$target,$target,$target,$src]);}
            $pdo->commit();
        } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); redirect('index.php?action=combine_tests&err='.urlencode('Could not combine the tests. Please try again.')); }
        redirect('index.php?action=combine_tests&msg='.urlencode('Questions moved into test "'.$newName.'". Source test records were kept.'));
    }

    // Bulk MCQ workflow: paste questions + key first, then enter a NEW test name.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_mcq_btn']) && ($_SESSION['role'] ?? '') === 'admin') {
        $_SESSION['mcq_tab']='upload';
        $rawQuestions=trim((string)($_POST['bulk_questions']??'')); $rawKeys='';   // answer keys now travel inside the same file/text as the MCQs
        if (!empty($_FILES['bulk_file']['tmp_name'])) { $upErr=''; $rawQuestions=trim($rawQuestions."\n".mcq_read_upload_text($_FILES['bulk_file'],$upErr)); if ($upErr!=='') redirect('index.php?action=admin_mcqs&err='.urlencode($upErr)); }
        if($rawQuestions==='') redirect('index.php?action=admin_mcqs&err='.urlencode('Upload an MCQ file (TXT, DOCX, PDF, JSON) or paste the MCQs first. The answer key must be included with the questions.'));
        $jp=null;
        if ((!empty($_FILES['bulk_file']['name']) && preg_match('/\.json$/i',(string)$_FILES['bulk_file']['name'])) || preg_match('/^\s*[\[{]/',$rawQuestions)) $jp=mcq_parse_json($rawQuestions);
        $parsed=($jp && $jp['rows']) ? ['rows'=>$jp['rows'],'missing'=>$jp['missing'],'incomplete'=>0,'keys'=>[],'found'=>$jp['found']] : mcq_parse_text($rawQuestions);
        $needAi = (!$parsed['rows'] || count($parsed['missing']) > 0) && mb_strlen($rawQuestions) >= 40;
        if ($needAi && function_exists('curl_init')) {   // AI detects questions / options / answers when the format is not recognised
            @set_time_limit(150);
            $aiKeyB=ai_default_key(); $aiErrB='';
            $gen=ai_call_json(ai_extract_prompt($rawQuestions.($rawKeys!==''?"\nANSWER KEY:\n".$rawKeys:'')),$aiKeyB,$aiErrB);
            if ($gen && count($gen['questions']) >= count($parsed['rows'])) {
                $parsed['rows']=[]; $parsed['missing']=[];
                foreach ($gen['questions'] as $gi=>$gq) { $gr=['question_text'=>trim((string)($gq['question_text']??'')),'option_a'=>trim((string)($gq['option_a']??'')),'option_b'=>trim((string)($gq['option_b']??'')),'option_c'=>trim((string)($gq['option_c']??'')),'option_d'=>trim((string)($gq['option_d']??'')),'correct_option'=>($gq['correct_option']??null),'_n'=>$gi+1]; if (in_array('',[$gr['question_text'],$gr['option_a'],$gr['option_b'],$gr['option_c'],$gr['option_d']],true)) continue; $parsed['rows'][]=$gr; }
                $boxKeysSkip = true;
            }
        }
        if(!$parsed['rows']) redirect('index.php?action=admin_mcqs&err='.urlencode('No valid questions found. Each question needs 4 options A) B) C) D), e.g. "Q1. Question text" then "A) ..." lines, or Question | A | B | C | D.'));
        $boxKeys=$rawKeys!=='' ? mcq_parse_key_text($rawKeys) : [];
        $draft=[];$missing=[];
        foreach($parsed['rows'] as $r){
            $n=$r['_n']; $correct=$boxKeys[$n] ?? $r['correct_option'];
            if(empty($correct)){$missing[]=$n;continue;}
            unset($r['_n']); $r['correct_option']=$correct; $draft[]=$r;
        }
        if(!$draft) redirect('index.php?action=admin_mcqs&err='.urlencode('Questions were read, but no answer key was found. Add an "ANSWER KEY" section like "1. C" / "2 B", or "Answer: C" under each question.'));
        $added=mcq_draft_add($draft,($jp && !empty($jp['subject']))?$jp['subject']:'General');
        $note=$added.' questions added to the draft.'; if($missing) $note.=' Skipped (no answer key found) question numbers: '.implode(', ',$missing).'.';
        redirect('index.php?action=admin_mcqs&msg='.urlencode($note.' Review the draft, then save.'));
    }

    // One shared draft: discard it, or save it (edited) into an existing or a new test.
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['discard_mcq_draft_btn']) && ($_SESSION['role']??'')==='admin') {
        unset($_SESSION['mcq_draft'],$_SESSION['mcq_draft_subject']);
        redirect('index.php?action=admin_mcqs&msg='.urlencode('Draft cleared.'));
    }
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_mcq_draft_btn']) && ($_SESSION['role']??'')==='admin') {
        $posted=json_decode((string)($_POST['draft_json']??''),true); $rows=[];
        foreach ((array)$posted as $r) {
            if (!is_array($r) || empty($r['keep'])) continue;
            $c=strtoupper(trim((string)($r['k']??''))); $vals=[trim((string)($r['q']??'')),trim((string)($r['a']??'')),trim((string)($r['b']??'')),trim((string)($r['c']??'')),trim((string)($r['d']??''))];
            if (in_array('',$vals,true) || !in_array($c,['A','B','C','D'],true)) continue;
            $rows[]=[$vals,$c];
        }
        $subject=mb_substr(trim((string)($_POST['draft_subject']??'')),0,80); if($subject==='') $subject='General';
        if (!$rows) redirect('index.php?action=admin_mcqs&err='.urlencode('Nothing to save: keep at least one complete question (all 4 options and a correct answer).'));
        $mode=(($_POST['dr_mode']??'new')==='existing')?'existing':'new';
        $drGender=(string)($_POST['dr_gender']??'All'); if(!in_array($drGender,['All','Male','Female'],true)) $drGender='All';
        try {
            $pdo->beginTransaction();
            if ($mode==='existing') {
                $testId=(int)($_POST['dr_existing_test_id']??0);
                $t=$pdo->prepare('SELECT class,stream,test_name,gender_target FROM tests_meta WHERE id=?'); $t->execute([$testId]); $tm=$t->fetch();
                if(!$tm) throw new RuntimeException('Select an existing test, or choose "Create a new test".');
                $class=$tm['class']; $stream=$tm['stream']; $testName=$tm['test_name']; $drGender=$tm['gender_target'];
            } else {
                $testName=trim((string)($_POST['dr_new_test_name']??'')); $class=trim((string)($_POST['dr_class']??'')); $stream=trim((string)($_POST['dr_stream']??''));
                if($testName===''||$class===''||$stream==='') throw new RuntimeException('Enter a new test name, class and stream.');
                $mk=$pdo->prepare('INSERT INTO tests_meta (test_name,class,stream,gender_target,is_live) VALUES (?,?,?,?,0)'); $mk->execute([$testName,$class,$stream,$drGender]); $testId=(int)$pdo->lastInsertId();
            }
            $dup=$pdo->prepare('SELECT 1 FROM questions WHERE test_id=? AND question_text=? LIMIT 1');
            $ins=$pdo->prepare('INSERT INTO questions (test_id,class,stream,subject,gender_target,question_text,option_a,option_b,option_c,option_d,correct_option) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $saved=0; $skipped=0;
            foreach($rows as $rw){ [$v,$c]=$rw; if($mode==='existing'){ $dup->execute([$testId,$v[0]]); if($dup->fetchColumn()){ $skipped++; continue; } } $ins->execute([$testId,$class,$stream,$subject,$drGender,$v[0],$v[1],$v[2],$v[3],$v[4],$c]); $saved++; }
            if($saved<1) throw new RuntimeException('All those questions already exist in the selected test.');
            $pdo->commit();
        } catch(Throwable $e) {
            if($pdo->inTransaction()) $pdo->rollBack();
            $m = ($e instanceof PDOException) ? 'Could not save. Test names must be unique; check the class/stream and the questions.' : $e->getMessage();
            redirect('index.php?action=admin_mcqs&err='.urlencode($m));
        }
        unset($_SESSION['mcq_draft'],$_SESSION['mcq_draft_subject']);
        redirect('index.php?action=admin_mcqs&msg='.urlencode('Saved '.$saved.' MCQs into test "'.$testName.'".'.($skipped?' '.$skipped.' duplicates were skipped.':'')));
    }

    // Delete a whole test (its questions, student attempts and answers) after browser confirmation.
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_test_btn']) && ($_SESSION['role']??'')==='admin') {
        $delId=(int)($_POST['test_id']??0);
        if ($delId<1) redirect('index.php?action=admin_mcqs&err='.urlencode('Invalid test selected.'));
        try {
            $pdo->beginTransaction();
            $nm=$pdo->prepare('SELECT test_name FROM tests_meta WHERE id=?'); $nm->execute([$delId]); $delName=$nm->fetchColumn();
            if ($delName===false) throw new RuntimeException('Test not found.');
            $pdo->prepare('DELETE FROM test_answers WHERE test_id IN (SELECT id FROM student_tests WHERE test_id=?)')->execute([$delId]);
            $pdo->prepare('DELETE FROM student_tests WHERE test_id=?')->execute([$delId]);
            $pdo->prepare('DELETE FROM questions WHERE test_id=?')->execute([$delId]);
            $pdo->prepare('DELETE FROM tests_meta WHERE id=?')->execute([$delId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirect('index.php?action=admin_mcqs&err='.urlencode($e instanceof RuntimeException ? $e->getMessage() : 'Could not delete this test. Please try again.'));
        }
        redirect('index.php?action=admin_mcqs&msg='.urlencode('Test "'.$delName.'" and all its questions and results were deleted.'));
    }

    // Edit a question and re-grade every saved attempt that included it.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_question_edit_btn']) && ($_SESSION['role'] ?? '') === 'admin') {
        $qid=(int)($_POST['question_id']??0); $qtext=trim((string)($_POST['q_text']??''));
        $a=trim((string)($_POST['opt_a']??'')); $b=trim((string)($_POST['opt_b']??'')); $c=trim((string)($_POST['opt_c']??'')); $d=trim((string)($_POST['opt_d']??''));
        $correct=strtoupper(trim((string)($_POST['correct_opt']??''))); $subject=trim((string)($_POST['q_subject']??''));
        if ($qid<1 || $qtext==='' || $a==='' || $b==='' || $c==='' || $d==='' || !in_array($correct,['A','B','C','D'],true)) redirect('index.php?action=admin_mcqs&err='.urlencode('Complete all question fields and choose a valid answer.'));
        $imageErr=''; $newImage=save_question_image($_FILES['question_image']??[], $imageErr);
        if ($imageErr!=='') redirect('index.php?action=admin_mcqs&err='.urlencode($imageErr));
        $pdo->beginTransaction();
        try {
            if ($newImage) $pdo->prepare('UPDATE questions SET question_text=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_option=?,subject=?,image_path=? WHERE id=?')->execute([$qtext,$a,$b,$c,$d,$correct,$subject,$newImage,$qid]);
            else $pdo->prepare('UPDATE questions SET question_text=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_option=?,subject=? WHERE id=?')->execute([$qtext,$a,$b,$c,$d,$correct,$subject,$qid]);
            $pdo->prepare('UPDATE test_answers SET correct_option=?, is_correct=CASE WHEN selected_option=? THEN 1 ELSE 0 END WHERE question_id=?')->execute([$correct,$correct,$qid]);
                        $attemptIds=$pdo->prepare('SELECT DISTINCT test_id FROM test_answers WHERE question_id=?'); $attemptIds->execute([$qid]);
            $ids=$attemptIds->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $aid) {
                $tot=$pdo->prepare('SELECT COUNT(*) FROM test_answers WHERE test_id=?'); $tot->execute([$aid]); $total=(int)$tot->fetchColumn();
                $cor=$pdo->prepare('SELECT COUNT(*) FROM test_answers WHERE test_id=? AND is_correct=1'); $cor->execute([$aid]); $right=(int)$cor->fetchColumn();
                $pdo->prepare('UPDATE student_tests SET total_questions=?,correct_answers=?,score_percentage=? WHERE id=?')->execute([$total,$right,$total?round($right*100/$total,2):0,$aid]);
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); redirect('index.php?action=admin_mcqs&err='.urlencode('Question update/recheck failed. Please verify the database schema.')); }
        redirect('index.php?action=admin_mcqs&msg='.urlencode('Question updated and saved attempts rechecked.'));
    }

    // Download a test as portable JSON, including its question bank.
    if ($action === 'export_test_json' && ($_SESSION['role'] ?? '') === 'admin') {
        $tid=(int)($_GET['test_id']??0); $t=$pdo->prepare('SELECT * FROM tests_meta WHERE id=?'); $t->execute([$tid]); $test=$t->fetch(); 
        if (!$test) { http_response_code(404); exit('Test not found'); }
        $q=$pdo->prepare('SELECT class,stream,subject,gender_target,question_text,option_a,option_b,option_c,option_d,correct_option FROM questions WHERE test_id=? ORDER BY id'); $q->execute([$tid]);
        header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="test_'.preg_replace('/[^A-Za-z0-9_-]/','_', $test['test_name']).'.json"');
        echo json_encode(['format'=>'tuition-test-v1','test'=>$test,'questions'=>$q->fetchAll()], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); exit;
    }

    // Export a test as TXT or DOCX. Same layout the Upload MCQs tab reads back: numbered questions, A-D options and an ANSWER KEY section.
    if (($action === 'export_test_txt' || $action === 'export_test_docx') && ($_SESSION['role'] ?? '') === 'admin') {
        $tid=(int)($_GET['test_id']??0); $t=$pdo->prepare('SELECT * FROM tests_meta WHERE id=?'); $t->execute([$tid]); $test=$t->fetch(); 
        if (!$test) { http_response_code(404); exit('Test not found'); }
        $q=$pdo->prepare('SELECT question_text,option_a,option_b,option_c,option_d,correct_option FROM questions WHERE test_id=? ORDER BY id'); $q->execute([$tid]); $exQs=$q->fetchAll();
        $oneLine=function($v){ return trim(preg_replace('/\s+/u',' ',(string)$v)); };
        $exLines=[$oneLine($test['test_name']), 'Class: '.$test['class'].' | Field: '.$test['stream'], ''];
        foreach ($exQs as $i=>$r) {
            $exLines[]='Q'.($i+1).'. '.$oneLine($r['question_text']);
            foreach (['A'=>'option_a','B'=>'option_b','C'=>'option_c','D'=>'option_d'] as $L=>$col) $exLines[]=$L.') '.$oneLine($r[$col]);
            $exLines[]='';
        }
        $exLines[]='ANSWER KEY';
        foreach ($exQs as $i=>$r) $exLines[]=($i+1).'. '.$r['correct_option'];
        $safeName=preg_replace('/[^A-Za-z0-9_-]/','_', $test['test_name']);
        if ($action === 'export_test_txt') {
            header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="test_'.$safeName.'.txt"');
            echo "\xEF\xBB\xBF".implode("\r\n",$exLines); exit;
        }
        if (!class_exists('ZipArchive')) { http_response_code(500); exit('This server cannot create .docx files (ZipArchive missing). Use the TXT export instead.'); }
        $xe=function($v){ return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',(string)$v), ENT_XML1|ENT_QUOTES, 'UTF-8'); };
        $body='';
        foreach ($exLines as $i=>$ln) { $bold=($i===0 || $ln==='ANSWER KEY'); $body.='<w:p><w:r>'.($bold?'<w:rPr><w:b/></w:rPr>':'').'<w:t xml:space="preserve">'.$xe($ln).'</w:t></w:r></w:p>'; }
        $tmpDocx=tempnam(sys_get_temp_dir(),'tst'); $zip=new ZipArchive();
        if ($zip->open($tmpDocx, ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit('Could not create the .docx file.'); }
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document'); header('Content-Disposition: attachment; filename="test_'.$safeName.'.docx"'); header('Content-Length: '.filesize($tmpDocx));
        readfile($tmpDocx); @unlink($tmpDocx); exit;
    }

    // Full database backup as JSON. Restore supports merge or replace; only known app tables are accepted.
    if ($action === 'backup_download' && ($_SESSION['role'] ?? '') === 'admin') {
        $tables=['settings','admin','fields','classes','subjects','field_subjects','tests_meta','students','questions','student_tests','test_answers','fees','fee_given','announcements','site_teachers','site_info','expenses','mod_attendance','mod_homework','mod_timetable','mod_notes'];
        $data=['format'=>'tuition-backup-v1','created_at'=>date(DATE_ATOM),'tables'=>[]];
        foreach ($tables as $table) { try { $data['tables'][$table]=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(); } catch(Throwable $e) { /* optional module table not installed */ } }
        header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="tuition_backup_'.date('Y-m-d_His').'.json"'); echo json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); exit;
    }
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['backup_upload_btn']) && ($_SESSION['role']??'')==='admin') {
        $mode=($_POST['backup_mode']??'merge')==='replace'?'replace':'merge'; $f=$_FILES['backup_file']??null;
        if (!$f || ($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($f['size']??0)>20*1024*1024) redirect('index.php?action=backup&err='.urlencode('Upload a valid JSON backup smaller than 20 MB.'));
        $data=json_decode((string)file_get_contents($f['tmp_name']),true);
        if (!is_array($data) || ($data['format']??'')!=='tuition-backup-v1' || !is_array($data['tables']??null)) redirect('index.php?action=backup&err='.urlencode('Backup file format is not recognized.'));
        $allowed=['settings','admin','fields','classes','subjects','field_subjects','tests_meta','students','questions','student_tests','test_answers','fees','fee_given','announcements','site_teachers','site_info','expenses','mod_attendance','mod_homework','mod_timetable','mod_notes'];
        $pdo->beginTransaction();
        try {
            if ($mode==='replace') { foreach (array_reverse($allowed) as $table) { try { $pdo->exec('DELETE FROM `'.$table.'`'); } catch(Throwable $e) {} } }
            foreach ($data['tables'] as $table=>$rows) {
                if (!in_array($table,$allowed,true) || !is_array($rows)) continue;
                foreach ($rows as $row) { if (!is_array($row) || !$row) continue; $cols=array_keys($row); if (array_diff($cols, array_map(fn($x)=>$x, $cols))) continue;
                    $quoted=implode(',',array_map(fn($c)=>'`'.str_replace('`','',$c).'`',$cols)); $marks=implode(',',array_fill(0,count($cols),'?'));
                    try { $st=$pdo->prepare('INSERT '.($mode==='merge'?'IGNORE ':'').'INTO `'.$table.'` ('.$quoted.') VALUES ('.$marks.')'); $st->execute(array_values($row)); } catch(Throwable $e) { /* keep processing valid rows */ }
                }
            }
            $pdo->commit();
        } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); redirect('index.php?action=backup&err='.urlencode('Backup restore failed; database changes were rolled back.')); }
        redirect('index.php?action=backup&msg='.urlencode('Backup imported using '.strtoupper($mode).' mode. Review records after import.'));
    }

    // Delete an individual question only after the admin confirms in the browser.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question_btn']) && ($_SESSION['role'] ?? '') === 'admin') {
        $questionId = (int)($_POST['question_id'] ?? 0);
        if ($questionId > 0) {
            try {
                $pdo->beginTransaction();
                // Capture attempts, remove the answer rows, and recompute their totals/scores.
                $affectedStmt = $pdo->prepare("SELECT DISTINCT test_id FROM test_answers WHERE question_id = ?");
                $affectedStmt->execute([$questionId]);
                $affectedAttempts = $affectedStmt->fetchAll(PDO::FETCH_COLUMN);
                $answerDelete = $pdo->prepare("DELETE FROM test_answers WHERE question_id = ?");
                $answerDelete->execute([$questionId]);
                $questionDelete = $pdo->prepare("DELETE FROM questions WHERE id = ?");
                $questionDelete->execute([$questionId]);
                foreach ($affectedAttempts as $attemptId) {
                    $tot = $pdo->prepare("SELECT COUNT(*) FROM test_answers WHERE test_id = ?"); $tot->execute([$attemptId]); $total = (int)$tot->fetchColumn();
                    $cor = $pdo->prepare("SELECT COUNT(*) FROM test_answers WHERE test_id = ? AND is_correct = 1"); $cor->execute([$attemptId]); $right = (int)$cor->fetchColumn();
                    $pdo->prepare("UPDATE student_tests SET total_questions = ?, correct_answers = ?, score_percentage = ? WHERE id = ?")
                        ->execute([$total, $right, $total ? round($right * 100 / $total, 2) : 0, $attemptId]);
                }
                $pdo->commit();
                redirect("index.php?action=admin_mcqs&msg=Question%20removed%20successfully.");
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = "Could not remove this question. Please try again.";
            }
        } else {
            $err = "Invalid question selected.";
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_mcq_btn'])) {
        $target_mode = $_POST['target_mode']; // 'existing' or 'new'
        $class = $_POST['q_class'];
        $stream = $_POST['q_stream'];
        $subject = $_POST['q_subject'];
        $gender_target = $_POST['q_gender_target'];
        $question = trim($_POST['q_text']);
        $optA = trim($_POST['opt_a']);
        $optB = trim($_POST['opt_b']);
        $optC = trim($_POST['opt_c']);
        $optD = trim($_POST['opt_d']);
        $correct = $_POST['correct_opt'];
        $questionImage = save_question_image($_FILES['question_image'] ?? [], $imageErr);
        if (!empty($imageErr)) $err = $imageErr;

        $test_id = null;

        if ($target_mode === 'existing') {
            $test_id = !empty($_POST['existing_test_id']) ? (int)$_POST['existing_test_id'] : 0;
            if ($test_id <= 0) {
                $err = "Test name is required. Please select an existing test or choose “Create a New Named Test” and enter a test name before adding questions.";
            } else {
                // Always attach the question to the exact selected test ID.
                $tStmt = $pdo->prepare("SELECT class, stream, gender_target FROM tests_meta WHERE id = ?");
                $tStmt->execute([$test_id]);
                $tData = $tStmt->fetch();
                if ($tData) {
                    $class = $tData['class'];
                    $stream = $tData['stream'];
                    $gender_target = $tData['gender_target'];
                } else {
                    $err = "The selected test no longer exists. Please select a valid test.";
                }
            }
        } else {
            $new_test_name = trim($_POST['new_test_name']);
            if (!empty($new_test_name)) {
                try {
                    $stmtTest = $pdo->prepare("INSERT INTO tests_meta (test_name, class, stream, gender_target, is_live) VALUES (?, ?, ?, ?, 0)");
                    $stmtTest->execute([$new_test_name, $class, $stream, in_array($gender_target, ['All','Male','Female'], true) ? $gender_target : 'All']);
                    $test_id = $pdo->lastInsertId();
                } catch (PDOException $e) {
                    $err = "Test name already exists or invalid details!";
                }
            } else {
                $err = "Please provide a name for the new test.";
            }
        }

        if (empty($err)) {
            $stmt = $pdo->prepare("INSERT INTO questions (test_id, class, stream, subject, gender_target, question_text, option_a, option_b, option_c, option_d, correct_option, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$test_id, $class, $stream, $subject, $gender_target, $question, $optA, $optB, $optC, $optD, $correct, $questionImage]);

            // Keep the reusable question settings after saving so the admin can add
            // another question without selecting the test/class/stream/subject again.
            $_SESSION['mcq_form_draft'] = [
                'target_mode' => 'existing',
                'existing_test_id' => $test_id ?? '',
                'q_class' => $class,
                'q_stream' => $stream,
                'q_subject' => $subject,
                'q_gender_target' => $gender_target,
                'correct_opt' => $correct
            ];
            redirect("index.php?action=admin_mcqs&msg=MCQ Added Successfully! Reusable settings kept—enter the next question.");
        }
    }

    // Question Bank: move a test between the Restricted area (admin only) and Live (students can see it).
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_test_live_btn']) && ($_SESSION['role'] ?? '') === 'admin') {
        $test_id = (int)($_POST['test_id'] ?? 0);
        $makeLive = ((int)($_POST['make_live'] ?? 0) === 1) ? 1 : 0;
        $liveMsg = ''; $liveErr = '';
        try {
            $chk = $pdo->prepare("SELECT id, test_name, test_expiry_date FROM tests_meta WHERE id = ?");
            $chk->execute([$test_id]);
            $trow = $chk->fetch();
            if (!$trow) {
                $liveErr = "Test not found.";
            } else {
                $qcs = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE test_id = ?");
                $qcs->execute([$test_id]);
                $qcount = (int)$qcs->fetchColumn();
                $tname = (string)$trow['test_name'];
                if ($makeLive && $qcount < 1) {
                    $liveErr = "Add at least one question to '" . $tname . "' before making it Live.";
                } elseif ($makeLive && !empty($trow['test_expiry_date']) && time() >= strtotime($trow['test_expiry_date'])) {
                    $liveErr = "'" . $tname . "' has already expired (" . date('d M Y, h:i A', strtotime($trow['test_expiry_date'])) . "). Set a new future expiry date (or clear it) in Test Settings, then make it Live.";
                } else {
                    $pdo->prepare("UPDATE tests_meta SET is_live = ? WHERE id = ?")->execute([$makeLive, $test_id]);
                    $liveMsg = $makeLive
                        ? "'" . $tname . "' is now LIVE. Matching students can see and start it."
                        : "'" . $tname . "' moved to the Restricted area. Only the admin can see it.";
                }
            }
        } catch (Throwable $e) {
            $liveErr = "Could not change Live status: " . mb_substr($e->getMessage(), 0, 200);
        }
        if ($liveErr !== '') redirect("index.php?action=admin_mcqs&err=" . urlencode($liveErr));
        redirect("index.php?action=admin_mcqs&msg=" . urlencode($liveMsg));
    }

    // Question Bank: save all settings of one test (name, class, stream, gender, duration, expiry).
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_test_settings_btn']) && ($_SESSION['role'] ?? '') === 'admin') {
        $test_id = (int)($_POST['test_id'] ?? 0);
        $t_name = trim($_POST['t_name'] ?? '');
        $t_class = trim($_POST['t_class'] ?? '');
        $t_stream = trim($_POST['t_stream'] ?? '');
        $t_gender = in_array($_POST['t_gender'] ?? '', ['All', 'Male', 'Female'], true) ? $_POST['t_gender'] : 'All';
        $minutesInput = trim($_POST['test_time_limit_minutes'] ?? '');
        $minutes = $minutesInput === '' ? null : (int)$minutesInput;
        $passInput = trim($_POST['passing_percentage'] ?? '');
        $passing = $passInput === '' ? 50.0 : (is_numeric($passInput) ? round((float)$passInput, 2) : -1);
        $expiryInput = trim($_POST['test_expiry_date'] ?? '');
        $expiry = null;
        if ($expiryInput !== '') {
            $ts = strtotime(str_replace('T', ' ', $expiryInput));
            $expiry = $ts ? date('Y-m-d H:i:s', $ts) : false;
        }

        if ($test_id < 1 || $t_name === '' || $t_class === '' || $t_stream === '') {
            redirect("index.php?action=admin_mcqs&err=" . urlencode("Test name, class and stream are required."));
        }
        if ($minutes !== null && ($minutes < 1 || $minutes > 600)) {
            redirect("index.php?action=admin_mcqs&err=" . urlencode("Enter a valid test duration (1 to 600 minutes), or leave it empty to use the default 90 minutes."));
        }
        if ($passing < 0 || $passing > 100) {
            redirect("index.php?action=admin_mcqs&err=" . urlencode("Passing marks must be a percentage between 0 and 100 (leave empty for the default 50%)."));
        }
        if ($expiry === false) {
            redirect("index.php?action=admin_mcqs&err=" . urlencode("The expiry date is not valid."));
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE tests_meta SET test_name = ?, class = ?, stream = ?, gender_target = ?, time_limit_minutes = ?, test_expiry_date = ?, passing_percentage = ? WHERE id = ?")
                ->execute([$t_name, $t_class, $t_stream, $t_gender, $minutes, $expiry, $passing, $test_id]);
            // Keep every question of this test in step with the test's class, stream and gender.
            $pdo->prepare("UPDATE questions SET class = ?, stream = ?, gender_target = ? WHERE test_id = ?")
                ->execute([$t_class, $t_stream, $t_gender, $test_id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirect("index.php?action=admin_mcqs&err=" . urlencode("Could not save. Another test may already use that name."));
        }
        restrict_expired_tests($pdo); // an expiry date set in the past moves the test to Restricted right away
        redirect("index.php?action=admin_mcqs&msg=" . urlencode("Test settings saved."));
    }


    if (!isset($currency)) { $currency = 'Rs.'; } // money() needs this before the page is drawn
    // ---- FEES: receive a payment ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_fee_btn']) && ($_SESSION['role'] ?? '') === 'admin') {
        $sid = (int)($_POST['fee_student_id'] ?? 0);
        $fmonth = trim($_POST['fee_month'] ?? '');
        $famount = (float)($_POST['fee_amount'] ?? 0);
        $fpaid = trim($_POST['fee_paid_on'] ?? '');
        if ($fpaid === '') $fpaid = date('Y-m-d');
        $fmethod = substr(trim($_POST['fee_method'] ?? 'Cash'), 0, 30);
        $fnote = substr(trim($_POST['fee_note'] ?? ''), 0, 255);
        if (preg_match('/^\d{4}-\d{2}$/', $fmonth)) { $_GET['fee_month'] = $fmonth; } // stay on the month the admin was working in
        $stuName = '';
        if ($sid > 0) { $q = $pdo->prepare("SELECT full_name FROM students WHERE id = ?"); $q->execute([$sid]); $stuName = (string)$q->fetchColumn(); }
        $dt = DateTime::createFromFormat('Y-m-d', $fpaid);
        if ($sid <= 0 || $stuName === '') {
            $err = "Please choose the student first (use the green Receive Payment button on the student's row).";
        } elseif (!preg_match('/^\d{4}-\d{2}$/', $fmonth)) {
            $err = "The fee month is missing. Pick the month at the top of the page and try again.";
        } elseif ($famount <= 0) {
            $err = "Please type the amount received. It must be more than 0.";
        } elseif ($famount > 9999999) {
            $err = "That amount looks too large. Please check it and try again.";
        } elseif (!$dt || $dt->format('Y-m-d') !== $fpaid) {
            $err = "The payment date is not valid. Please pick it from the calendar.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO fees (student_id, fee_month, amount, paid_on, method, note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$sid, $fmonth, $famount, $fpaid, $fmethod, $fnote]);
            $__okMsg = "Saved: received " . money($famount) . " from " . $stuName . " for " . month_label($fmonth) . ".";
            if ((int)($_POST['return_student'] ?? 0) === $sid) { redirect("index.php?action=admin_student_view&id=" . $sid . "&msg=" . urlencode($__okMsg)); }
            redirect("index.php?action=admin_fees&fee_month=" . urlencode($fmonth) . "&msg=" . urlencode("Saved: received " . money($famount) . " from " . $stuName . " for " . month_label($fmonth) . "."));
        }
    }

    // ---- FEES: set a student's monthly fee ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_monthly_fee_btn']) && ($_SESSION['role'] ?? '') === 'admin') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $mf = max(0, (float)($_POST['monthly_fee'] ?? 0));
        $fm = $_POST['fee_month'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $fm)) $fm = date('Y-m');
        $msg2 = "Student not found. Monthly fee was not changed.";
        if ($sid > 0) {
            $q = $pdo->prepare("SELECT full_name FROM students WHERE id = ?"); $q->execute([$sid]); $nm = (string)$q->fetchColumn();
            if ($nm !== '') {
                $pdo->prepare("UPDATE students SET monthly_fee = ? WHERE id = ?")->execute([$mf, $sid]);
                $msg2 = "Monthly fee of " . $nm . " is now " . money($mf) . ".";
            }
        }
        if ((int)($_POST['return_student'] ?? 0) === $sid && $sid > 0) { redirect("index.php?action=admin_student_view&id=" . $sid . "&msg=" . urlencode($msg2)); }
        if ((int)($_POST['return_student'] ?? 0) === $sid && $sid > 0) { redirect("index.php?action=admin_student_view&id=" . $sid . "&msg=" . urlencode($msg2)); }
        redirect("index.php?action=admin_fees&fee_month=" . urlencode($fm) . "&msg=" . urlencode($msg2));
    }

    // ---- FEES: mark a month Free / clear it / (older) typed-in total ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_fee_given_btn']) && ($_SESSION['role'] ?? '') === 'admin') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $gm = $_POST['fee_month'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $gm)) $gm = date('Y-m');
        $g = trim($_POST['fee_given'] ?? '');
        $nm = '';
        if ($sid > 0) { $q = $pdo->prepare("SELECT full_name FROM students WHERE id = ?"); $q->execute([$sid]); $nm = (string)$q->fetchColumn(); }
        $msg2 = "Student not found. Nothing was changed.";
        if ($nm !== '') {
            if ($g === '') {
                $pdo->prepare("DELETE FROM fee_given WHERE student_id = ? AND fee_month = ?")->execute([$sid, $gm]);
                $msg2 = month_label($gm) . " for " . $nm . " is back to normal. Their real payments are counted again.";
            } elseif (strcasecmp($g, 'free') === 0) {
                $pdo->prepare("REPLACE INTO fee_given (student_id, fee_month, given) VALUES (?, ?, 'Free')")->execute([$sid, $gm]);
                $msg2 = month_label($gm) . " is now marked Free for " . $nm . ".";
            } elseif (is_numeric($g) && (float)$g >= 0) {
                $pdo->prepare("REPLACE INTO fee_given (student_id, fee_month, given) VALUES (?, ?, ?)")->execute([$sid, $gm, (string)(float)$g]);
                $msg2 = "Paid amount for " . $nm . " (" . month_label($gm) . ") set to " . money((float)$g) . ".";
            } else {
                $msg2 = "Please write a number (for example 3000) or the word Free.";
            }
        }
        redirect("index.php?action=admin_fees&fee_month=" . urlencode($gm) . "&msg=" . urlencode($msg2));
    }

    // ---- FEES: delete a payment ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_fee_btn']) && ($_SESSION['role'] ?? '') === 'admin') {
        $fid = (int)($_POST['fee_id'] ?? 0);
        $fm = $_POST['fee_month'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $fm)) $fm = date('Y-m');
        $msg2 = "Payment not found. Nothing was deleted.";
        $retStu = 0;
        if ($fid > 0) {
            $q = $pdo->prepare("SELECT f.amount, f.student_id, s.full_name FROM fees f JOIN students s ON s.id = f.student_id WHERE f.id = ?"); $q->execute([$fid]);
            $row = $q->fetch();
            if ($row) {
                if ((int)($_POST['return_student'] ?? 0) === (int)$row['student_id']) $retStu = (int)$row['student_id'];
                $pdo->prepare("DELETE FROM fees WHERE id = ?")->execute([$fid]);
                $msg2 = "Deleted the payment of " . money($row['amount']) . " from " . $row['full_name'] . ".";
            }
        }
        if ($retStu > 0) { redirect("index.php?action=admin_student_view&id=" . $retStu . "&msg=" . urlencode($msg2)); }
        redirect("index.php?action=admin_fees&fee_month=" . urlencode($fm) . "&msg=" . urlencode($msg2));
    }

    // ---- EXPENSES: add / delete ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense_btn'])) {
        expenses_ensure($pdo);
        $etitle = mb_substr(trim($_POST['exp_title'] ?? ''), 0, 150);
        $eamount = (float)($_POST['exp_amount'] ?? 0);
        $emonth = trim($_POST['exp_month'] ?? '');
        $enote = mb_substr(trim($_POST['exp_note'] ?? ''), 0, 255);
        if ($etitle !== '' && $eamount > 0 && preg_match('/^\d{4}-\d{2}$/', $emonth)) {
            $pdo->prepare("INSERT INTO expenses (title, amount, expense_month, note) VALUES (?, ?, ?, ?)")->execute([$etitle, $eamount, $emonth, $enote]);
            redirect("index.php?action=admin_expenses&exp_month=" . urlencode($emonth) . "&msg=" . urlencode("Expense added.") . "#expList");
        }
        $err = "Enter the expense name, an amount greater than 0 and the month of payment.";
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_expense_btn'])) {
        expenses_ensure($pdo);
        $eid = (int)($_POST['exp_id'] ?? 0);
        if ($eid > 0) { $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$eid]); }
        $dm = preg_match('/^\d{4}-\d{2}$/', $_POST['exp_month'] ?? '') ? $_POST['exp_month'] : '';
        redirect("index.php?action=admin_expenses" . ($dm ? "&exp_month=" . urlencode($dm) : "") . "&msg=" . urlencode("Expense deleted.") . "#expList");
    }

    // ---- NOTICES ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_notice_btn'])) {
        $ntitle = substr(trim($_POST['notice_title'] ?? ''), 0, 150);
        $nbody = trim($_POST['notice_body'] ?? '');
        if ($ntitle !== '' && $nbody !== '') {
            $stmt = $pdo->prepare("INSERT INTO announcements (title, body) VALUES (?, ?)");
            $stmt->execute([$ntitle, $nbody]);
            redirect("index.php?action=admin_notices&msg=" . urlencode("Notice published."));
        }
        $err = "Please enter both a title and a message.";
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_notice_btn'])) {
        $nid = (int)($_POST['notice_id'] ?? 0);
        if ($nid > 0) {
            $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->execute([$nid]);
        }
        redirect("index.php?action=admin_notices&msg=" . urlencode("Notice deleted."));
    }

    // ---- TEACHERS: add / edit / delete (admin only) ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_teacher_btn'])) {
        $tid = (int)($_POST['teacher_id'] ?? 0);
        $tn = mb_substr(trim($_POST['t_name'] ?? ''), 0, 120);
        $tg = in_array($_POST['t_gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $_POST['t_gender'] : 'Male';
        $vals = [$tn, $tg,
            mb_substr(trim($_POST['t_grade'] ?? ''), 0, 80), mb_substr(trim($_POST['t_subject'] ?? ''), 0, 120),
            mb_substr(trim($_POST['t_qual'] ?? ''), 0, 150), mb_substr(trim($_POST['t_exp'] ?? ''), 0, 60),
            mb_substr(trim($_POST['t_phone'] ?? ''), 0, 40), mb_substr(trim($_POST['t_email'] ?? ''), 0, 120),
            mb_substr(trim($_POST['t_bio'] ?? ''), 0, 1500)];
        if ($tn === '') {
            redirect("index.php?action=admin_teachers&err=" . urlencode("Teacher name is required."));
        }
        $perr = '';
        $newPhoto = teacher_save_photo($_FILES['t_photo'] ?? [], $perr);
        if ($perr !== '') { redirect("index.php?action=admin_teachers" . ($tid ? "&edit=$tid" : "") . "&err=" . urlencode($perr)); }
        if ($tid > 0) {
            $old = $pdo->prepare("SELECT photo FROM site_teachers WHERE id = ?"); $old->execute([$tid]); $oldPhoto = $old->fetchColumn();
            $sql = "UPDATE site_teachers SET full_name=?, gender=?, grade=?, subject=?, qualification=?, experience=?, phone=?, email=?, bio=?";
            $params = $vals;
            if ($newPhoto) { $sql .= ", photo=?"; $params[] = $newPhoto; }
            elseif (!empty($_POST['remove_photo'])) { $sql .= ", photo=NULL"; $newPhoto = ''; }
            $sql .= " WHERE id=?"; $params[] = $tid;
            $pdo->prepare($sql)->execute($params);
            if ($newPhoto !== null) { teacher_delete_photo($oldPhoto); }
        } else {
            $pdo->prepare("INSERT INTO site_teachers (full_name, gender, grade, subject, qualification, experience, phone, email, bio, photo) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute(array_merge($vals, [$newPhoto]));
        }
        redirect("index.php?action=admin_teachers&msg=" . urlencode("Teacher saved."));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_teacher_btn'])) {
        $tid = (int)($_POST['teacher_id'] ?? 0);
        $old = $pdo->prepare("SELECT photo FROM site_teachers WHERE id = ?"); $old->execute([$tid]); teacher_delete_photo($old->fetchColumn());
        $pdo->prepare("DELETE FROM site_teachers WHERE id = ?")->execute([$tid]);
        redirect("index.php?action=admin_teachers&msg=" . urlencode("Teacher removed."));
    }

    // ---- MODULE ENGINE: save / delete / export ----
    $post_mod = is_string($_POST['m'] ?? null) ? $_POST['m'] : '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mod_save_btn']) && isset($MODULES[$post_mod])) {
        mod_ensure($pdo, $post_mod);
        $vals = [];
        foreach (mod_fields($MODULES[$post_mod][4]) as $f) {
            $v = $_POST['f'][$f['col']] ?? '';
            $v = is_string($v) ? trim($v) : '';
            if ($f['t'] === 'class') { $cv = $v === '' ? 'All Classes' : $v; $okc = array_merge(['All Classes'], $pdo->query("SELECT class_name FROM classes")->fetchAll(PDO::FETCH_COLUMN)); $vals[$f['col']] = in_array($cv, $okc, true) ? $cv : 'All Classes'; continue; }
            if ($f['t'] === 'targetclass') { $vals[$f['col']] = $vals['c1'] ?? 'All Classes'; continue; }
            if ($v === '') { $vals[$f['col']] = null; continue; }
            switch ($f['t']) {
                case 'number':   $vals[$f['col']] = is_numeric($v) ? (float)$v : null; break;
                case 'date':     $vals[$f['col']] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; break;
                case 'student':  $vals[$f['col']] = (int)$v > 0 ? (int)$v : null; break;
                case 'targetclass': $vals[$f['col']] = in_array($v, array_merge(['All Classes'], $pdo->query("SELECT class_name FROM classes")->fetchAll(PDO::FETCH_COLUMN)), true) ? $v : 'All Classes'; break;
                case 'select':   $vals[$f['col']] = in_array($v, $f['o'], true) ? $v : null; break;
                case 'textarea': $vals[$f['col']] = mb_substr($v, 0, 5000); break;
                case 'url':      $vals[$f['col']] = mb_substr(preg_match('#^[a-z][a-z0-9+.-]*://#i', $v) ? $v : 'https://' . $v, 0, 500); break;
                default:         $vals[$f['col']] = mb_substr($v, 0, 255);
            }
        }
        if ($post_mod === 'attendance') {
            if (empty($vals['c1'])) redirect("index.php?action=mod&m=attendance&msg=" . urlencode("Please choose a student."));
            if (empty($vals['c2'])) $vals['c2'] = date('Y-m-d');
            if (empty($vals['c3'])) $vals['c3'] = 'Present';
        }
        $rid = (int)($_POST['row_id'] ?? 0);
        if ($rid > 0) {
            $set = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($vals)));
            $stmt = $pdo->prepare("UPDATE `" . mod_table($post_mod) . "` SET $set WHERE id = ?");
            $stmt->execute(array_merge(array_values($vals), [$rid]));
        } else {
            $stmt = $pdo->prepare("INSERT INTO `" . mod_table($post_mod) . "` (`" . implode('`, `', array_keys($vals)) . "`) VALUES (" . implode(', ', array_fill(0, count($vals), '?')) . ")");
            $stmt->execute(array_values($vals));
        }
        redirect("index.php?action=mod&m=" . urlencode($post_mod) . "&msg=" . urlencode("Saved successfully."));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mod_delete_btn']) && isset($MODULES[$post_mod])) {
        mod_ensure($pdo, $post_mod);
        $stmt = $pdo->prepare("DELETE FROM `" . mod_table($post_mod) . "` WHERE id = ?");
        $stmt->execute([(int)($_POST['row_id'] ?? 0)]);
        redirect("index.php?action=mod&m=" . urlencode($post_mod) . "&msg=" . urlencode("Record deleted."));
    }
    if ($action === 'mod_export' && isset($MODULES[$mod_key])) {
        mod_ensure($pdo, $mod_key);
        $ef = mod_fields($MODULES[$mod_key][4]);
        $esm = $pdo->query("SELECT id, full_name FROM students")->fetchAll(PDO::FETCH_KEY_PAIR);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $mod_key . '_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(['ID'], array_column($ef, 'l'), ['Created']));
        foreach ($pdo->query("SELECT * FROM `" . mod_table($mod_key) . "` ORDER BY id DESC")->fetchAll() as $r) {
            $line = [$r['id']];
            foreach ($ef as $f) { $line[] = $f['t'] === 'student' ? ($esm[$r[$f['col']]] ?? $r[$f['col']]) : $r[$f['col']]; }
            $line[] = $r['created_at'];
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_field_btn'])) {
        $field_name = trim($_POST['field_name']);
        if (!empty($field_name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO fields (field_name) VALUES (?)");
                $stmt->execute([$field_name]);
                redirect("index.php?action=manage_academic&msg=Field Added Successfully!");
            } catch (PDOException $e) {
                $err = "Field already exists!";
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_class_btn'])) {
        $class_name = trim($_POST['class_name']);
        if (!empty($class_name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO classes (class_name) VALUES (?)");
                $stmt->execute([$class_name]);
                redirect("index.php?action=manage_academic&msg=Class Added Successfully!");
            } catch (PDOException $e) {
                $err = "Class already exists!";
            }
        }
    }

    // NEW SUBJECT ADDITION HANDLER FROM ADMIN PANEL
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject_btn'])) {
        $subject_name = trim($_POST['subject_name']);
        $assigned_fields = $_POST['field_ids'] ?? [];

        if (!empty($subject_name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO subjects (subject_name) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
                $stmt->execute([$subject_name]);
                $subject_id = $pdo->lastInsertId();

                if (!empty($assigned_fields)) {
                    $stmtLink = $pdo->prepare("INSERT IGNORE INTO field_subjects (field_id, subject_id) VALUES (?, ?)");
                    foreach ($assigned_fields as $field_id) {
                        $stmtLink->execute([$field_id, $subject_id]);
                    }
                }
                redirect("index.php?action=manage_academic&msg=Subject Saved and Mapped Successfully!");
            } catch (PDOException $e) {
                $err = "Error saving subject mapping: " . $e->getMessage();
            }
        }
    }
}

// STUDENT TEST SUBMISSION (Ensures one-time restriction per test)
if (isset($_SESSION['role']) && $_SESSION['role'] === 'student' && $db_connected) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_test_btn'])) {
        $student_id = $_SESSION['user_id'];
        $test_id = isset($_POST['test_id']) ? (int)$_POST['test_id'] : 0;

        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM student_tests WHERE student_id = ? AND test_id = ?");
        $stmtCheck->execute([$student_id, $test_id]);
        if ($stmtCheck->fetchColumn() > 0) {
            redirect("index.php?action=student_dashboard&err=You have already submitted this test!");
        }

        $class = $_SESSION['class'];
        $stream = $_SESSION['stream'];
        $gender = $_SESSION['gender'];

        if ($test_id > 0) {
            $validTestStmt = $pdo->prepare("SELECT test_expiry_date FROM tests_meta WHERE id = ? AND class = ? AND stream = ? AND is_live = 1 AND gender_target IN ('All', ?)");
            $validTestStmt->execute([$test_id, $class, $stream, $gender]);
            $validTest = $validTestStmt->fetch();
            if (!$validTest) {
                redirect("index.php?action=student_dashboard&err=Invalid test.");
            }
            if (!empty($validTest['test_expiry_date']) && time() > strtotime($validTest['test_expiry_date'])) {
                redirect("index.php?action=student_dashboard&err=This test has expired.");
            }
        }
        $subject = mb_substr(trim((string)($_POST['test_subject'] ?? '')), 0, 100); 
        $answers = is_array($_POST['answer'] ?? null) ? $_POST['answer'] : [];
        $time_taken = max(0, (int)($_POST['time_taken_seconds'] ?? 0));

        if ($test_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ? AND (gender_target = 'All' OR gender_target = ?)");
            $stmt->execute([$test_id, $gender]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM questions WHERE class = ? AND stream = ? AND (test_id IS NULL) AND (gender_target = 'All' OR gender_target = ?)");
            $stmt->execute([$class, $stream, $gender]);
        }
        $questions = $stmt->fetchAll();

        $total_q = count($questions);
        $correct_count = 0;

        foreach ($questions as $q) {
            $q_id = $q['id'];
            $user_ans = $answers[$q_id] ?? null;
            if (!in_array($user_ans, ['A', 'B', 'C', 'D'], true)) $user_ans = null;
            if ($user_ans === $q['correct_option']) {
                $correct_count++;
            }
        }

        $percentage = $total_q > 0 ? round(($correct_count / $total_q) * 100, 2) : 0;

        if ($total_q === 0) redirect("index.php?action=student_dashboard&err=No questions available for this test.");
        try {
        $pdo->beginTransaction();
        // Serialize submissions by this student, preventing concurrent duplicate attempts.
        $lock = $pdo->prepare("SELECT id FROM students WHERE id = ? FOR UPDATE");
        $lock->execute([$student_id]);
        $recheck = $pdo->prepare("SELECT COUNT(*) FROM student_tests WHERE student_id = ? AND test_id <=> ?");
        $recheck->execute([$student_id, $test_id > 0 ? $test_id : null]);
        if ($recheck->fetchColumn() > 0) {
            $pdo->rollBack();
            redirect("index.php?action=student_dashboard&err=You have already submitted this test!");
        }
        $stmt = $pdo->prepare("INSERT INTO student_tests (student_id, test_id, class, stream, subject, total_questions, correct_answers, score_percentage, time_taken_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$student_id, $test_id > 0 ? $test_id : null, $class, $stream, $subject, $total_q, $correct_count, $percentage, $time_taken]);
        $saved_test_record_id = $pdo->lastInsertId();

        $stmtAns = $pdo->prepare("INSERT INTO test_answers (test_id, question_id, selected_option, correct_option, is_correct) VALUES (?, ?, ?, ?, ?)");
        foreach ($questions as $q) {
            $q_id = $q['id'];
            $selected = $answers[$q_id] ?? null;
            if (!in_array($selected, ['A', 'B', 'C', 'D'], true)) $selected = null;
            $is_corr = ($selected === $q['correct_option']) ? 1 : 0;
            $stmtAns->execute([$saved_test_record_id, $q_id, $selected, $q['correct_option'], $is_corr]);
        }

        $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Test submission failed: ' . $e->getMessage());
            redirect("index.php?action=student_dashboard&err=Could not save your test. Please contact the administrator.");
        }
        redirect("index.php?action=view_result&test_id=" . $saved_test_record_id);
    }
}

$currency = 'Rs.'; // change to $, ₹, £ etc.
$is_landing = !isset($_SESSION['role']) && $action !== 'login' && !isset($_POST['login_btn']);
function month_label($ym) {
    $t = strtotime($ym . '-01');
    return $t ? date('F Y', $t) : $ym;
}
function fee_months_for($start_src, $recorded_months) {
    // Every month from the start date to now PLUS every month that has any fee record (earlier or later),
    // so previous fees never disappear. Newest first.
    $set = [];
    $ts = strtotime((string)$start_src); if (!$ts) $ts = time();
    $cur = new DateTime(date('Y-m-01', $ts)); $end = new DateTime(date('Y-m-01')); $g = 0;
    while ($cur <= $end && $g++ < 360) { $set[$cur->format('Y-m')] = true; $cur->modify('+1 month'); }
    foreach ((array)$recorded_months as $m) { if (preg_match('/^\d{4}-\d{2}$/', (string)$m)) $set[(string)$m] = true; }
    $out = array_keys($set); rsort($out);
    return $out;
}
function fee_given_apply($pdo, $month, &$paid_map, &$free_map) {
    // Admin-typed "Fee Given" overrides payments for that student/month. Text "Free" = free.
    $st = $pdo->prepare("SELECT student_id, given FROM fee_given WHERE fee_month = ?");
    $st->execute([$month]);
    $raw = [];
    foreach ($st->fetchAll() as $r) {
        $raw[$r['student_id']] = $r['given'];
        if (strcasecmp(trim($r['given']), 'free') === 0) { $free_map[$r['student_id']] = true; $paid_map[$r['student_id']] = 0; }
        elseif (is_numeric($r['given'])) { $paid_map[$r['student_id']] = (float)$r['given']; }
    }
    return $raw;
}
function money($n) {
    global $currency;
    return $currency . ' ' . number_format((float)$n, 0);
}
function expenses_ensure($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `expenses` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(150) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        `expense_month` CHAR(7) NOT NULL,
        `note` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S-Orbit Tuition | Smart Tuition, Online Tests &amp; Instant Results</title>
    <meta name="description" content="S-Orbit Tuition: expert teaching, timed MCQ tests, instant results with full answer review and transparent fee records.">
    <meta name="theme-color" content="#0a0f24">
    <link rel="icon" type="image/png" href="img/s-orbit-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
:root{
  --ink:#0a0f24; --ink-2:#121a3a; --indigo:#5b5bf0; --violet:#8b5cf6; --pink:#ec4899; --amber:#fbbf24; --orange:#f97316; --teal:#14b8a6;
  --bg:#f5f6fb; --card:#ffffff; --muted:#64748b; --line:#e6e8f2; --brand-dark:#0a0f24;
  --grad:linear-gradient(135deg,#5b5bf0 0%,#8b5cf6 55%,#ec4899 100%);
  --grad-gold:linear-gradient(135deg,#fbbf24,#f97316);
  --shadow-sm:0 2px 10px rgba(20,24,60,.05); --shadow-md:0 12px 34px rgba(30,32,90,.10); --shadow-lg:0 30px 70px rgba(30,32,90,.18);
  --bs-primary:#5b5bf0; --bs-primary-rgb:91,91,240; --bs-link-color:#5b5bf0; --bs-link-hover-color:#4338ca;
  --font-body:'Plus Jakarta Sans','Segoe UI',system-ui,sans-serif; --font-display:'Sora','Plus Jakarta Sans',system-ui,sans-serif;
}
html{scroll-behavior:smooth;scroll-padding-top:80px}
body{background:var(--bg);font-family:var(--font-body);color:#1e2440;min-height:100vh;display:flex;flex-direction:column;-webkit-font-smoothing:antialiased}
h1,h2,h3,h4,h5,h6,.navbar-brand{font-family:var(--font-display);letter-spacing:-.02em}
.main-wrapper{flex:1}
.text-muted{color:var(--muted)!important}

/* ===== Global components (apply to admin + student pages too) ===== */
.card,.card-custom{border:1px solid var(--line);border-radius:20px!important;box-shadow:var(--shadow-sm);background:var(--card)}
.card-custom:hover{box-shadow:var(--shadow-md)}
.btn{border-radius:12px;font-weight:600;transition:all .2s}
.btn-primary{--bs-btn-bg:#5b5bf0;--bs-btn-border-color:transparent;--bs-btn-hover-bg:#4a4ae0;--bs-btn-hover-border-color:transparent;--bs-btn-active-bg:#4338ca;--bs-btn-active-border-color:transparent;--bs-btn-disabled-bg:#5b5bf0;--bs-btn-disabled-border-color:transparent;background-image:var(--grad);border:0;box-shadow:0 8px 20px rgba(91,91,240,.28)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 12px 26px rgba(91,91,240,.38);filter:brightness(1.05)}
.btn-warning{--bs-btn-color:#1a1200;--bs-btn-hover-color:#1a1200;--bs-btn-active-color:#1a1200;background-image:var(--grad-gold);border:0;box-shadow:0 8px 20px rgba(249,115,22,.28)}
.btn-warning:hover{transform:translateY(-1px);filter:brightness(1.06);box-shadow:0 12px 26px rgba(249,115,22,.38)}
.btn-outline-primary{--bs-btn-color:#5b5bf0;--bs-btn-border-color:#5b5bf0;--bs-btn-hover-bg:#5b5bf0;--bs-btn-hover-border-color:#5b5bf0;--bs-btn-active-bg:#4338ca}
.form-control,.form-select{border-radius:12px;border:1.5px solid var(--line);padding:.62rem .9rem;background-color:#fff}
.form-control:focus,.form-select:focus{border-color:#8b8bf5;box-shadow:0 0 0 4px rgba(91,91,240,.14)}
.form-label{font-weight:600;font-size:.88rem;color:#334155}
.table{--bs-table-bg:transparent;vertical-align:middle}
.table thead th{background:#f1f2fb;color:#475076;font-size:.74rem;text-transform:uppercase;letter-spacing:.06em;border-bottom:0;padding:.85rem .9rem;white-space:nowrap}
.table thead th:first-child{border-radius:12px 0 0 12px}.table thead th:last-child{border-radius:0 12px 12px 0}
.table>:not(caption)>*>*{padding:.8rem .9rem;border-bottom-color:#eef0f7}
.table-hover>tbody>tr:hover>*{--bs-table-bg-state:#f7f7ff}
.table-primary{--bs-table-bg:#eef0ff;--bs-table-border-color:#e0e3ff}
.badge{border-radius:999px;font-weight:600;letter-spacing:.01em}
.alert{border-radius:16px}
.accordion-item{border:1px solid var(--line);border-radius:16px!important;overflow:hidden;margin-bottom:12px;box-shadow:var(--shadow-sm)}
.accordion-button{font-weight:700;font-family:var(--font-display);padding:1.15rem 1.35rem}
.accordion-button:not(.collapsed){background:#f3f3ff;color:#3f3fd1;box-shadow:none}
.accordion-button:focus{box-shadow:0 0 0 4px rgba(91,91,240,.14)}
.timer-badge{font-size:1.2rem;font-weight:800;background:var(--ink);color:var(--amber);padding:8px 18px;border-radius:12px;box-shadow:0 8px 20px rgba(10,15,36,.25)}
.mcq-option-label{display:block;padding:15px 20px;border:2px solid var(--line);border-radius:14px;margin-bottom:12px;cursor:pointer;transition:all .2s;background:#fff}
.mcq-option-label:hover{background:#f6f6ff;border-color:#c9caff;transform:translateX(3px)}
.mcq-option-input:checked + .mcq-option-label{border-color:#5b5bf0;background:#eef0ff;font-weight:700;box-shadow:0 6px 18px rgba(91,91,240,.15)}
.flash-float{position:fixed;top:84px;right:20px;z-index:2000;max-width:420px;box-shadow:var(--shadow-lg)!important}

/* ===== Navbar ===== */
.site-nav{position:sticky;top:0;z-index:1500;background:rgba(10,15,36,.82);backdrop-filter:blur(16px) saturate(160%);-webkit-backdrop-filter:blur(16px) saturate(160%);border-bottom:1px solid rgba(255,255,255,.08);padding:.7rem 0;transition:all .3s}
.site-nav.scrolled{padding:.4rem 0;background:rgba(10,15,36,.94);box-shadow:0 10px 30px rgba(0,0,0,.25)}
.brand-logo{display:inline-flex;align-items:center;gap:10px;color:#fff;text-decoration:none;font-family:var(--font-display);font-weight:800;font-size:1.15rem;letter-spacing:.02em}
.brand-logo:hover{color:#fff}
.brand-logo .mark{width:40px;height:40px;border-radius:12px;background:var(--grad);display:inline-flex;align-items:center;justify-content:center;font-size:1.05rem;box-shadow:0 8px 20px rgba(139,92,246,.45)}
.brand-logo .mark{background:#fff;box-shadow:0 4px 12px rgba(10,15,36,.12);overflow:hidden;flex:0 0 40px}.brand-logo-img{display:block;width:100%;height:100%;object-fit:cover}
.brand-logo span.gold{background:var(--grad-gold);-webkit-background-clip:text;background-clip:text;color:transparent}
.site-nav .nav-link-pub{color:rgba(255,255,255,.72);text-decoration:none;font-weight:600;font-size:.92rem;padding:.45rem .85rem;border-radius:10px;transition:all .2s}
.site-nav .nav-link-pub:hover{color:#fff;background:rgba(255,255,255,.08)}
.site-nav .navbar-toggler{border:1px solid rgba(255,255,255,.25);padding:.4rem .6rem}
.site-nav .navbar-toggler-icon{filter:invert(1)}
.app-links .btn{--bs-btn-padding-y:.38rem}

/* ===== Landing page ===== */
.lp-bar{background:var(--grad);color:#fff;font-size:.85rem;font-weight:600;text-align:center;padding:.55rem 1rem}
.lp-bar a{color:#fff;text-decoration:underline}
.lp-hero{position:relative;overflow:hidden;background:#070b1f;color:#fff;min-height:calc(100vh - 70px);display:flex;align-items:center;padding:90px 0 110px}
.lp-hero::before{content:"";position:absolute;inset:0;background:
  radial-gradient(900px 500px at 85% 10%,rgba(139,92,246,.45),transparent 60%),
  radial-gradient(700px 500px at 5% 90%,rgba(236,72,153,.28),transparent 60%),
  radial-gradient(600px 400px at 50% 50%,rgba(91,91,240,.25),transparent 70%)}
.lp-hero::after{content:"";position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.045) 1px,transparent 1px);background-size:54px 54px;-webkit-mask-image:radial-gradient(ellipse at center,#000 30%,transparent 78%);mask-image:radial-gradient(ellipse at center,#000 30%,transparent 78%)}
.lp-hero .container{position:relative;z-index:2}
.blob{position:absolute;border-radius:50%;filter:blur(70px);opacity:.55;animation:float 12s ease-in-out infinite;z-index:1}
.blob.b1{width:340px;height:340px;background:#6d5cff;top:-80px;left:-80px}
.blob.b2{width:300px;height:300px;background:#f59e0b;bottom:-100px;right:12%;opacity:.28;animation-delay:-4s}
@keyframes float{0%,100%{transform:translateY(0) translateX(0)}50%{transform:translateY(-30px) translateX(20px)}}
.hero-pill{display:inline-flex;align-items:center;gap:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);backdrop-filter:blur(8px);padding:.5rem 1.1rem;border-radius:999px;font-size:.86rem;font-weight:600;color:#e7e8ff}
.hero-pill .dot{width:9px;height:9px;border-radius:50%;background:#34d399;box-shadow:0 0 0 0 rgba(52,211,153,.7);animation:pulse 2s infinite}
@keyframes pulse{70%{box-shadow:0 0 0 12px rgba(52,211,153,0)}100%{box-shadow:0 0 0 0 rgba(52,211,153,0)}}
.lp-hero h1{font-weight:800;font-size:clamp(2.6rem,6.4vw,5.2rem);line-height:1.04;margin:1.4rem 0 1.4rem}
.grad-text{background:linear-gradient(100deg,#fbbf24 0%,#f472b6 50%,#a78bfa 100%);-webkit-background-clip:text;background-clip:text;color:transparent}
.lp-hero .lead{font-size:clamp(1.05rem,1.6vw,1.3rem);color:rgba(226,228,255,.78);max-width:620px;line-height:1.7}
.btn-hero{padding:1rem 2rem;font-size:1.05rem;border-radius:16px}
.btn-glass{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.25);color:#fff;backdrop-filter:blur(8px)}
.btn-glass:hover{background:rgba(255,255,255,.16);color:#fff}
.hero-trust{display:flex;gap:26px;flex-wrap:wrap;margin-top:2.4rem;color:rgba(226,228,255,.7);font-size:.9rem;font-weight:600}
.hero-trust i{color:#34d399;margin-right:7px}
.hero-visual{position:relative;min-height:520px}
.glass-card{position:absolute;background:rgba(255,255,255,.96);color:#1e2440;border-radius:22px;box-shadow:0 40px 80px rgba(0,0,0,.4);padding:22px}
.gc-test{top:0;right:0;width:320px;animation:bob 7s ease-in-out infinite}
.gc-result{bottom:30px;left:0;width:240px;animation:bob 8s ease-in-out -2s infinite}
.gc-fee{bottom:-10px;right:20px;width:230px;animation:bob 9s ease-in-out -4s infinite}
@keyframes bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-14px)}}
.gc-test .timer{background:var(--ink);color:var(--amber);font-weight:800;font-size:.78rem;padding:4px 10px;border-radius:8px}
.opt{display:flex;align-items:center;gap:10px;border:1.5px solid var(--line);border-radius:12px;padding:9px 12px;font-size:.84rem;font-weight:600;margin-top:8px}
.opt b{width:24px;height:24px;border-radius:7px;background:#f1f2fb;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem}
.opt.on{border-color:#5b5bf0;background:#eef0ff}.opt.on b{background:#5b5bf0;color:#fff}
.ring{width:84px;height:84px;border-radius:50%;background:conic-gradient(#5b5bf0 0 92%,#e8e9fb 0);display:flex;align-items:center;justify-content:center;margin:10px auto}
.ring span{width:64px;height:64px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-family:var(--font-display);font-size:1.15rem}
.hero-scroll{position:absolute;bottom:26px;left:50%;transform:translateX(-50%);z-index:3;color:rgba(255,255,255,.5);font-size:.8rem;text-align:center;animation:bob 2.4s infinite}

.lp-ticker{background:var(--ink);overflow:hidden;padding:20px 0;border-top:1px solid rgba(255,255,255,.06)}
.ticker-track{display:flex;gap:56px;width:max-content;animation:scroll-x 38s linear infinite}
.ticker-track span{color:rgba(255,255,255,.75);font-family:var(--font-display);font-weight:700;font-size:1.05rem;white-space:nowrap;display:inline-flex;align-items:center;gap:14px}
.ticker-track span i{color:var(--amber);font-size:.9rem}
@keyframes scroll-x{to{transform:translateX(-50%)}}

.sec{padding:110px 0;position:relative}
.sec-white{background:#fff}
.sec-dark{background:var(--ink);color:#fff}
.eyebrow{display:inline-block;font-weight:800;font-size:.78rem;letter-spacing:.16em;text-transform:uppercase;color:#5b5bf0;background:#eef0ff;padding:.42rem 1rem;border-radius:999px;margin-bottom:1rem}
.sec-dark .eyebrow{background:rgba(255,255,255,.1);color:#c4b5fd}
.sec-h{font-weight:800;font-size:clamp(2rem,4.4vw,3.4rem);line-height:1.1;margin-bottom:1rem}
.sec-p{color:var(--muted);font-size:1.12rem;max-width:660px;line-height:1.75}
.sec-dark .sec-p{color:rgba(226,228,255,.7)}
.center-head{text-align:center;margin-bottom:64px}.center-head .sec-p{margin:0 auto}

.about-visual{position:relative;padding:30px}
.about-main{background:var(--grad);border-radius:36px;aspect-ratio:1/1.05;position:relative;overflow:hidden;box-shadow:var(--shadow-lg);display:flex;align-items:center;justify-content:center}
.about-main::before{content:"";position:absolute;width:420px;height:420px;border-radius:50%;background:rgba(255,255,255,.12);top:-140px;right:-120px}
.about-main::after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;background:rgba(251,191,36,.28);bottom:-80px;left:-60px}
.about-main i{font-size:9rem;color:#fff;position:relative;z-index:1;filter:drop-shadow(0 20px 30px rgba(0,0,0,.3))}
.float-chip{position:absolute;background:#fff;border-radius:18px;padding:14px 18px;box-shadow:var(--shadow-lg);font-weight:700;display:flex;align-items:center;gap:12px;animation:bob 6s ease-in-out infinite}
.float-chip .ic{width:42px;height:42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;color:#fff}
.float-chip small{display:block;color:var(--muted);font-weight:500;font-size:.75rem}
.fc1{top:6px;left:-10px}.fc2{bottom:40px;right:-14px;animation-delay:-3s}
.check-list{list-style:none;padding:0;margin:1.6rem 0 0}
.check-list li{display:flex;gap:14px;margin-bottom:16px;font-weight:600;color:#334155}
.check-list li i{flex:0 0 28px;height:28px;border-radius:50%;background:#e6f9f4;color:#0d9488;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;margin-top:2px}

.feature-card{border:1px solid var(--line);border-radius:24px;padding:34px 28px;height:100%;background:#fff;box-shadow:var(--shadow-sm);transition:all .3s;position:relative;overflow:hidden}
.feature-card::before{content:"";position:absolute;inset:0 0 auto 0;height:4px;background:var(--grad);transform:scaleX(0);transform-origin:left;transition:transform .4s}
.feature-card:hover{transform:translateY(-8px);box-shadow:var(--shadow-lg);border-color:transparent}
.feature-card:hover::before{transform:scaleX(1)}
.feature-icon{width:64px;height:64px;border-radius:18px;display:inline-flex;align-items:center;justify-content:center;font-size:1.6rem;color:#fff;margin-bottom:20px;background:var(--grad);box-shadow:0 12px 24px rgba(91,91,240,.3)}
.fi-1{background:linear-gradient(135deg,#5b5bf0,#8b5cf6)}.fi-2{background:linear-gradient(135deg,#ec4899,#f43f5e);box-shadow:0 12px 24px rgba(236,72,153,.3)}
.fi-3{background:linear-gradient(135deg,#14b8a6,#06b6d4);box-shadow:0 12px 24px rgba(20,184,166,.3)}.fi-4{background:linear-gradient(135deg,#fbbf24,#f97316);box-shadow:0 12px 24px rgba(249,115,22,.3)}
.fi-5{background:linear-gradient(135deg,#3b82f6,#6366f1)}.fi-6{background:linear-gradient(135deg,#10b981,#14b8a6);box-shadow:0 12px 24px rgba(16,185,129,.3)}
.feature-card h5{font-weight:800;margin-bottom:.6rem}

.stream-card{border-radius:28px;padding:36px 30px;color:#fff;height:100%;position:relative;overflow:hidden;transition:all .3s;box-shadow:var(--shadow-md)}
.stream-card:hover{transform:translateY(-8px) scale(1.01);box-shadow:var(--shadow-lg)}
.stream-card::after{content:"";position:absolute;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.14);right:-60px;top:-60px}
.stream-card i{font-size:2.6rem;margin-bottom:22px;display:block;position:relative;z-index:1}
.stream-card h4{font-weight:800;position:relative;z-index:1}
.stream-card p{opacity:.88;margin:0;position:relative;z-index:1}
.sc-0{background:linear-gradient(135deg,#4f46e5,#7c3aed)}.sc-1{background:linear-gradient(135deg,#ec4899,#f43f5e)}.sc-2{background:linear-gradient(135deg,#0ea5e9,#14b8a6)}
.sc-3{background:linear-gradient(135deg,#f59e0b,#ef4444)}.sc-4{background:linear-gradient(135deg,#10b981,#059669)}.sc-5{background:linear-gradient(135deg,#6366f1,#3b82f6)}
.class-tile{background:#fff;border:1px solid var(--line);border-radius:24px;padding:28px 20px;text-align:center;box-shadow:var(--shadow-sm);transition:all .3s;height:100%}
.class-tile:hover{transform:translateY(-6px);box-shadow:var(--shadow-md);border-color:#c9caff}
.class-tile .big{font-family:var(--font-display);font-weight:800;font-size:3rem;background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent;line-height:1}
.chip{display:inline-block;background:#fff;border:1px solid var(--line);color:#334155;font-weight:600;font-size:.9rem;padding:.55rem 1.1rem;border-radius:999px;margin:5px;box-shadow:var(--shadow-sm);transition:all .2s}
.chip:hover{background:var(--grad);color:#fff;border-color:transparent;transform:translateY(-2px)}

.mock-window{background:#fff;border-radius:24px;box-shadow:0 50px 100px rgba(0,0,0,.45);overflow:hidden;color:#1e2440;border:1px solid rgba(255,255,255,.1)}
.mock-top{background:#f1f2fb;padding:14px 18px;display:flex;align-items:center;gap:8px}
.mock-top i{width:12px;height:12px;border-radius:50%;display:inline-block}
.mock-top .url{margin-left:14px;background:#fff;border-radius:8px;padding:4px 14px;font-size:.76rem;color:var(--muted);flex:1;max-width:340px}
.mock-body{display:flex;min-height:380px}
.mock-side{width:170px;background:var(--ink);padding:20px 14px;color:rgba(255,255,255,.7);font-size:.82rem;font-weight:600}
.mock-side div{padding:9px 12px;border-radius:10px;margin-bottom:6px}.mock-side div.on{background:var(--grad);color:#fff}
.mock-main{flex:1;padding:24px;background:#f8f9ff}
.mini-stat{background:#fff;border-radius:16px;padding:14px 16px;border:1px solid var(--line)}
.mini-stat b{font-family:var(--font-display);font-size:1.4rem;display:block}.mini-stat small{color:var(--muted);font-weight:600}
.bars{display:flex;align-items:flex-end;gap:12px;height:120px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px}
.bars i{flex:1;border-radius:8px 8px 3px 3px;background:var(--grad);display:block;animation:grow 1.4s ease both}
@keyframes grow{from{height:0!important}}

.timeline{position:relative}
.step-card{text-align:center;position:relative;padding:0 14px}
.step-num{width:84px;height:84px;border-radius:50%;background:var(--grad);color:#fff;font-weight:800;font-family:var(--font-display);display:inline-flex;align-items:center;justify-content:center;font-size:1.9rem;margin-bottom:22px;box-shadow:0 18px 36px rgba(91,91,240,.4);position:relative;z-index:2;border:6px solid var(--bg)}
.sec-white .step-num{border-color:#fff}
.step-card h5{font-weight:800}
@media(min-width:992px){.timeline::before{content:"";position:absolute;top:42px;left:12%;right:12%;height:3px;background:repeating-linear-gradient(90deg,#c9caff 0 10px,transparent 10px 20px)}}


.compare{border-radius:28px;padding:38px;height:100%}
.compare.old{background:#fff;border:1px solid var(--line)}
.compare.new{background:var(--ink);color:#fff;box-shadow:var(--shadow-lg);position:relative;overflow:hidden}
.compare.new::after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;background:rgba(139,92,246,.35);filter:blur(60px);top:-80px;right:-60px}
.compare ul{list-style:none;padding:0;margin:1.4rem 0 0;position:relative;z-index:1}
.compare li{display:flex;gap:12px;margin-bottom:15px;font-weight:600;align-items:flex-start}
.compare.old li i{color:#f43f5e;margin-top:5px}.compare.new li i{color:#34d399;margin-top:5px}

.person-card{background:#fff;border:1px solid var(--line);border-radius:26px;padding:30px 22px;text-align:center;box-shadow:var(--shadow-sm);transition:all .3s;height:100%}
.person-card:hover{transform:translateY(-8px);box-shadow:var(--shadow-lg)}
.avatar{width:92px;height:92px;border-radius:50%;background:var(--grad);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-family:var(--font-display);font-weight:800;font-size:2rem;margin-bottom:16px;box-shadow:0 14px 30px rgba(91,91,240,.35);border:4px solid #fff;outline:2px solid #e0e1ff}
.rank-badge{display:inline-block;background:var(--grad-gold);color:#1a1200;font-weight:800;border-radius:999px;padding:.3rem .9rem;font-size:.8rem}
.info-card{background:#fff;border:1px solid var(--line);border-radius:22px;padding:26px;height:100%;box-shadow:var(--shadow-sm);transition:all .3s}
.info-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-md)}
.info-card .date{display:inline-flex;align-items:center;gap:8px;font-size:.78rem;font-weight:700;color:#5b5bf0;background:#eef0ff;padding:.3rem .8rem;border-radius:999px;margin-bottom:14px}
.quote-card{background:#fff;border:1px solid var(--line);border-radius:26px;padding:34px;height:100%;box-shadow:var(--shadow-sm);position:relative}
.quote-card::before{content:"\201C";position:absolute;top:6px;right:26px;font-family:Georgia,serif;font-size:7rem;line-height:1;color:#e6e7ff}
.gallery-img{border-radius:20px;overflow:hidden;aspect-ratio:4/3;background:#e8e9fb;position:relative;box-shadow:var(--shadow-sm)}
.gallery-img img{width:100%;height:100%;object-fit:cover;transition:transform .5s}.gallery-img:hover img{transform:scale(1.08)}
.gallery-img span{position:absolute;inset:auto 0 0 0;padding:30px 16px 12px;background:linear-gradient(transparent,rgba(0,0,0,.7));color:#fff;font-weight:700;font-size:.9rem}
.offer-card{background:var(--grad-gold);color:#1a1200;border-radius:24px;padding:28px;height:100%;box-shadow:0 20px 40px rgba(249,115,22,.3)}

.cta-big{background:var(--ink);border-radius:40px;padding:80px 40px;text-align:center;color:#fff;position:relative;overflow:hidden;box-shadow:var(--shadow-lg)}
.cta-big::before{content:"";position:absolute;inset:0;background:radial-gradient(600px 300px at 15% 0,rgba(139,92,246,.55),transparent 70%),radial-gradient(500px 300px at 90% 100%,rgba(236,72,153,.4),transparent 70%)}
.cta-big>*{position:relative}
.cta-big h2{font-weight:800;font-size:clamp(2rem,5vw,3.8rem)}

.contact-card{background:#fff;border:1px solid var(--line);border-radius:22px;padding:24px;display:flex;gap:18px;align-items:center;box-shadow:var(--shadow-sm);margin-bottom:16px;transition:all .3s}
.contact-card:hover{transform:translateX(6px);box-shadow:var(--shadow-md)}
.contact-card .ic{flex:0 0 56px;height:56px;border-radius:16px;background:var(--grad);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:1.25rem}
.contact-card h6{margin:0 0 3px;font-weight:800}.contact-card p{margin:0;color:var(--muted);font-weight:500}
.form-panel{background:#fff;border:1px solid var(--line);border-radius:30px;padding:40px;box-shadow:var(--shadow-lg)}
.hp{position:absolute;left:-9999px;opacity:0}

.lp-footer{background:#070b1f;color:rgba(226,228,255,.65);padding:80px 0 0;position:relative;overflow:hidden}
.lp-footer h6{color:#fff;font-weight:800;margin-bottom:20px}
.lp-footer a{color:rgba(226,228,255,.65);text-decoration:none;display:block;margin-bottom:11px;transition:all .2s;font-weight:500}
.lp-footer a:hover{color:#fff;transform:translateX(4px)}
.f-bottom{border-top:1px solid rgba(255,255,255,.08);margin-top:56px;padding:24px 0;font-size:.88rem}
.footer{background:var(--ink);color:#94a3b8;padding:18px 0;margin-top:auto}

.reveal{opacity:0;transform:translateY(34px);transition:opacity .8s cubic-bezier(.2,.7,.2,1),transform .8s cubic-bezier(.2,.7,.2,1)}
.reveal.in{opacity:1;transform:none}
.d1{transition-delay:.1s}.d2{transition-delay:.2s}.d3{transition-delay:.3s}.d4{transition-delay:.4s}
.scroll-prog{position:fixed;top:0;left:0;height:4px;width:0;background:var(--grad-gold);z-index:3000}
.to-top{position:fixed;right:22px;bottom:22px;width:52px;height:52px;border-radius:16px;border:0;background:var(--grad);color:#fff;font-size:1.1rem;box-shadow:0 14px 30px rgba(91,91,240,.45);opacity:0;pointer-events:none;transition:all .3s;z-index:1800}
.to-top.show{opacity:1;pointer-events:auto}.to-top:hover{transform:translateY(-4px)}

/* ===== Login ===== */
.login-shell{max-width:1000px;margin:30px auto;display:grid;grid-template-columns:1fr 1fr;border-radius:32px;overflow:hidden;box-shadow:var(--shadow-lg);background:#fff;border:1px solid var(--line)}
.login-art{background:linear-gradient(150deg,#0a0f24 0%,#2b2a8f 55%,#8b5cf6 100%);color:#fff;padding:56px 44px;position:relative;overflow:hidden;display:flex;flex-direction:column;justify-content:center}
.login-art::before{content:"";position:absolute;width:320px;height:320px;border-radius:50%;background:rgba(251,191,36,.25);filter:blur(50px);bottom:-100px;right:-60px}
.login-art>*{position:relative}
.login-art .mark{width:64px;height:64px;border-radius:20px;background:var(--grad-gold);color:#1a1200;display:inline-flex;align-items:center;justify-content:center;font-size:1.7rem;margin-bottom:26px;box-shadow:0 14px 30px rgba(249,115,22,.4)}
.login-form{padding:56px 48px}
.pw-wrap{position:relative}.pw-wrap button{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:none;color:var(--muted);padding:8px 12px}
@media(max-width:820px){.login-shell{grid-template-columns:1fr}.login-art{padding:36px 28px}.login-form{padding:36px 26px}}

@media(max-width:991px){
  .hero-visual{display:none}.sec{padding:76px 0}.lp-hero{padding:70px 0 110px;min-height:auto}
  .form-panel{padding:26px}.cta-big{padding:56px 22px;border-radius:30px}.mock-side{display:none}
}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}.reveal{opacity:1;transform:none}}

.t-photo{width:104px;height:104px;border-radius:50%;overflow:hidden;margin:0 auto 16px;border:4px solid #fff;outline:2px solid #e0e1ff;box-shadow:0 14px 30px rgba(91,91,240,.3)}
.t-avatar{width:100%;height:100%;object-fit:cover;display:block}
.t-bob{animation:tbob 3s ease-in-out infinite;transform-origin:50% 100%}
.t-eyes{animation:tblink 4s infinite;transform-origin:50px 50px}
.t-smile{animation:tsmile 4s ease-in-out infinite;transform-origin:50px 62px}
@keyframes tbob{0%,100%{transform:translateY(0)}50%{transform:translateY(-2.5px)}}
@keyframes tblink{0%,92%,100%{transform:scaleY(1)}96%{transform:scaleY(.1)}}
@keyframes tsmile{0%,100%{transform:scaleX(1)}50%{transform:scaleX(1.12)}}
.t-chip{display:inline-block;background:#eef0ff;color:#4a4ad8;border-radius:50px;padding:3px 11px;font-size:.78rem;font-weight:600;margin:2px}

.studio-credit{display:inline-block;font-weight:900;letter-spacing:.035em;background:linear-gradient(100deg,#ff8e75,#ffd76c,#70edb2,#76c9ff,#d7a2ff);background-size:220% auto;-webkit-background-clip:text;background-clip:text;color:transparent;animation:studio-shimmer 7s linear infinite}
@keyframes studio-shimmer{to{background-position:220% center}}

/* ===== Mobile layout repair =====
   Keep desktop styling intact while preventing cramped layouts and horizontal overflow. */
html { width:100%; max-width:100%; overflow-x:clip; }
body { width:100%; max-width:100%; overflow-x:clip; }
img, svg, video, canvas, iframe { max-width:100%; }
.container, .container-fluid { min-width:0; }
.row { --bs-gutter-x: 1.25rem; }
.card, .card-custom, .form-panel, .compare, .cta-big, .contact-card,
.login-shell, .login-art, .login-form { min-width:0; }
p, h1, h2, h3, h4, h5, h6, label, a, button, td, th {
  overflow-wrap:anywhere;
}
.table-responsive { max-width:100%; -webkit-overflow-scrolling:touch; }
.table-responsive > .table { min-width:620px; }
input, select, textarea, button { max-width:100%; }
.form-control, .form-select { min-width:0; }
.btn { white-space:normal; }
.navbar .container { gap:.5rem; }
.navbar-brand, .brand-logo { min-width:0; max-width:calc(100% - 64px); }
.brand-logo { font-size:clamp(.9rem, 4vw, 1.15rem); }
.brand-logo .mark { flex-shrink:0; }
section, header, footer, main { min-width:0; }

/* ===== Mobile alignment fixes ===== */
@media (max-width: 1199.98px) {
  .app-links.pub-links { flex-direction:column !important; align-items:stretch !important; flex-wrap:nowrap !important; gap:.5rem; }
  .app-links.pub-links > div { width:100%; }
  .app-links.pub-links .btn-warning { width:100%; height:auto; align-self:stretch; padding:.75rem 1rem; margin-top:.25rem; }
}
@media (max-width: 767.98px) {
  .st-table { min-width:0 !important; width:100%; }
  .table-responsive > .st-table { min-width:0 !important; }
  .st-table thead { display:none; }
  .st-table, .st-table tbody, .st-table tr, .st-table td { display:block; width:100%; }
  .st-table tr.st-row { border:1px solid #e5e7f5; border-radius:16px; padding:.5rem .85rem; margin-bottom:.85rem; background:#fff; }
  .st-table td { border:0 !important; padding:.4rem 0 !important; text-align:left !important; display:flex; justify-content:space-between; align-items:center; gap:.75rem; }
  .st-table td::before { content:attr(data-label); font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7090; flex:0 0 76px; }
  .st-table td.st-actions { flex-wrap:wrap; justify-content:flex-start; padding-top:.7rem !important; border-top:1px solid #eef0fb !important; margin-top:.3rem; white-space:normal !important; }
  .st-table td.st-actions::before { display:none; }
  .st-table td.st-actions .btn, .st-table td.st-actions form { flex:0 0 auto; }
  .st-table td.st-actions .btn-primary { flex:1 1 100%; }
  .st-table td.st-actions form.d-inline { flex:1 1 auto; }
  .st-table td.st-actions form.d-inline .btn { width:100%; }
  .st-table td form.d-flex { flex:1 1 auto; justify-content:flex-end; }
}
@media (max-width: 767.98px) {
  html { scroll-padding-top:68px; }
  .container, .container-fluid { padding-left:16px; padding-right:16px; }
  .row { --bs-gutter-x: 1rem; }
  .site-nav { padding:.45rem 0; }
  .site-nav .container { flex-wrap:wrap; }
  .site-nav .navbar-collapse { flex-basis:100%; width:100%; padding-top:.6rem; }
  .site-nav .navbar-nav { gap:.15rem; }
  .site-nav .nav-link-pub { display:block; padding:.7rem .8rem; }
  .app-links { display:flex; flex-direction:row !important; flex-wrap:wrap; gap:.45rem; padding:.45rem 0 .25rem; }
  .app-links .btn { flex:1 1 calc(50% - .45rem); min-width:0; text-align:center; }
  .app-links > span { flex:1 1 100%; margin: .25rem 0 !important; }
  .lp-bar { font-size:.76rem; line-height:1.5; padding:.55rem 14px; }
  .lp-hero { min-height:0; padding:48px 0 68px; }
  .lp-hero h1 { font-size:clamp(2rem, 9vw, 3.15rem); line-height:1.08; margin:1rem 0; }
  .lp-hero .lead { font-size:1rem; line-height:1.65; }
  .hero-pill { max-width:100%; font-size:.75rem; padding:.45rem .75rem; }
  .btn-hero { width:100%; padding:.85rem 1rem; font-size:1rem; }
  .lp-hero .d-flex.gap-3, .lp-hero .d-flex.flex-wrap { gap:.65rem!important; }
  .sec { padding:48px 0; }
  .sec h2 { font-size:clamp(1.65rem, 7vw, 2.35rem); line-height:1.15; }
  .sec p { line-height:1.65; }
  .compare { padding:24px 20px; border-radius:22px; }
  .person-card, .info-card, .quote-card, .offer-card { padding:22px 18px; border-radius:20px; }
  .contact-card { align-items:flex-start; gap:12px; padding:18px; }
  .contact-card .ic { flex-basis:44px; height:44px; font-size:1rem; border-radius:13px; }
  .form-panel { padding:20px 16px; border-radius:22px; }
  .cta-big { padding:42px 18px; border-radius:24px; }
  .lp-footer { padding-top:48px; }
  .f-bottom { margin-top:30px; padding:18px 0; }
  .login-shell { margin:16px 0; border-radius:22px; }
  .login-art { padding:28px 22px; }
  .login-form { padding:26px 20px; }
  .card, .card-custom { border-radius:16px!important; }
  .card-body { padding:1rem; }
  .table thead th, .table>:not(caption)>*>* { padding:.65rem .7rem; }
  .flash-float { top:72px; left:12px; right:12px; max-width:none; }
  .timer-badge { font-size:1rem; padding:7px 12px; }
  .mcq-option-label { padding:12px 14px; }
  .to-top { right:14px; bottom:14px; width:44px; height:44px; border-radius:13px; }
  .t-photo { width:88px; height:88px; }
  .modal-dialog { margin:.65rem; }
  .modal-content { border-radius:18px; }
  .modal-body { overflow-wrap:anywhere; }
  .d-flex:not(.flex-nowrap) { min-width:0; }
}
@media (max-width: 420px) {
  .container, .container-fluid { padding-left:12px; padding-right:12px; }
  .row { --bs-gutter-x:.75rem; }
  .brand-logo { gap:7px; font-size:.88rem; }
  .brand-logo .mark { width:34px; height:34px; flex-basis:34px; border-radius:10px; }
  .lp-hero h1 { font-size:2rem; }
  .sec { padding:40px 0; }
  .form-panel, .login-form { padding:18px 14px; }
  .btn { padding-left:.75rem; padding-right:.75rem; }
}
/* Admin/student data tables remain usable on phones instead of forcing the page wider. */
@media (max-width: 767.98px) {
  /* Admin dashboard action buttons wrap into compact rows on phones. */
  .admin-dashboard-actions {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .55rem !important;
    width: 100%;
  }
  .admin-dashboard-actions .btn {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 0;
    width: 100%;
    min-height: 46px;
    padding: .65rem .45rem;
    line-height: 1.25;
    text-align: center;
    white-space: normal;
    overflow-wrap: anywhere;
  }
  table { max-width:100%; }
  .table:not(.table-responsive .table) { display:block; width:100%; overflow-x:auto; }
  .input-group { flex-wrap:wrap; }
  .input-group > .form-control, .input-group > .form-select { flex:1 1 160px; width:1%; }
  .row.g-4 > [class*="col-"], .row.g-3 > [class*="col-"] { min-width:0; }
}

/* ===== 2026 EDITORIAL REDESIGN ===== */
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap');
    
/* MCQ mathematics and science notation */
.math-frac {
  display:inline-flex; flex-direction:column; vertical-align:middle;
  text-align:center; line-height:1.05; margin:0 .12em; font-size:.92em;
}
.math-frac > span:first-child {
  display:block; padding:0 .22em .10em; border-bottom:1.4px solid currentColor;
}
.math-frac > span:last-child { display:block; padding:.10em .22em 0; }
.chem-arrow { display:inline-block; padding:0 .22em; font-size:1.18em; vertical-align:baseline; }
.mcq-option-label sup, .mcq-option-label sub { line-height:0; }
</style>
<script>
window.MathJax = {
  tex: {
    inlineMath: [['\\(', '\\)'], ['$', '$']],
    displayMath: [['\\[', '\\]'], ['$$', '$$']],
    packages: {'[+]': ['mhchem']}
  },
  options: { skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code'] }
};
</script>
<script defer src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
</head>
<body>

<div class="scroll-prog" id="scrollProg"></div>
<?php $in_test = (isset($_SESSION['role']) && $action === 'start_test'); ?>
<nav class="navbar navbar-expand-xl site-nav" id="siteNav">
    <div class="container">
        <a class="brand-logo" href="<?= $in_test ? 'javascript:void(0)' : 'index.php' ?>" <?= $in_test ? 'style="cursor:default;pointer-events:none"' : '' ?>><span class="mark"><img src="img/s-orbit-logo.png" alt="" class="brand-logo-img"></span><div>S-ORBIT <span class="gold">TUITION</span></div></a>
        <?php if (!$in_test): ?>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu" aria-label="Menu"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse justify-content-end" id="navMenu">
            <div class="d-flex align-items-xl-center gap-2 flex-wrap flex-column flex-xl-row mt-3 mt-xl-0 app-links <?= isset($_SESSION['role']) ? '' : 'pub-links' ?>">
            <?php if (isset($_SESSION['role'])): ?>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="index.php?action=admin_dashboard" class="btn btn-outline-light btn-sm">Dashboard</a>
                    <a href="index.php?action=manage_academic" class="btn btn-outline-warning btn-sm">Academic Setup</a>
                    <a href="index.php?action=admin_mcqs" class="btn btn-outline-info btn-sm">Question Bank</a>
                    <a href="index.php?action=modules" class="btn btn-primary btn-sm"><i class="fa-solid fa-grip me-1"></i>All Modules</a>
                <?php else: ?>
                    <a href="index.php?action=student_dashboard" class="btn btn-outline-light btn-sm">Dashboard</a>
                    <a href="index.php?action=student_fees" class="btn btn-outline-success btn-sm">My Fees</a>
                    <a href="index.php?action=modules" class="btn btn-primary btn-sm"><i class="fa-solid fa-grip me-1"></i>More</a>
                <?php endif; ?>
                <span class="text-white ms-xl-2 me-xl-2 small fw-semibold">
                    <i class="fa-solid fa-user-circle me-1"></i> <?= htmlspecialchars($_SESSION['name']) ?> (<?= ucfirst($_SESSION['role']) ?>)
                </span>
                <a href="index.php?action=logout" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-right-from-bracket me-1"></i> Logout</a>
            <?php else: ?>
                <div class="d-flex flex-column flex-xl-row gap-1 me-xl-3">
                    <a href="index.php#about" class="nav-link-pub">About</a>
                    <a href="index.php#features" class="nav-link-pub">Features</a>
                    <a href="index.php#courses" class="nav-link-pub">Courses</a>
                    <a href="index.php#portal" class="nav-link-pub">Portal</a>
                    <a href="index.php#notices" class="nav-link-pub">Notices</a>
                    <a href="index.php#faq" class="nav-link-pub">FAQ</a>
                    <a href="index.php#contact" class="nav-link-pub">Contact</a>
                </div>
                <a href="index.php?action=login" class="btn btn-warning px-4"><i class="fa-solid fa-right-to-bracket me-2"></i>Login</a>
            <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</nav>

<div class="<?= $is_landing ? 'main-wrapper' : 'container my-4 main-wrapper' ?>">

    <?php if (!$db_connected): ?>
        <div class="alert alert-warning card-custom p-4 text-center my-4">
            <h4 class="text-warning"><i class="fa-solid fa-triangle-exclamation me-2"></i> Database Connection Error</h4>
            <p><?= $is_landing ? 'We are having trouble loading live content right now. Please try again shortly.' : htmlspecialchars($db_error) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show card-custom border-start border-4 border-success <?= $is_landing ? 'flash-float' : '' ?>">
            <i class="fa-solid fa-circle-check me-2 text-success"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($err): ?>
        <div class="alert alert-danger alert-dismissible fade show card-custom border-start border-4 border-danger <?= $is_landing ? 'flash-float' : '' ?>">
            <i class="fa-solid fa-triangle-exclamation me-2 text-danger"></i><?= htmlspecialchars($err) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php
    // PUBLIC VISITOR WEBSITE (landing page)
    if ($is_landing):
        $site = site_info_get($db_connected ? $pdo : null);
        $site_phones = array_values(array_filter([trim($site['phone']), trim($site['phone2'])]));
        $lf = []; $lc = []; $ln = []; $ls = [];
        if ($db_connected) {
            $lf = $pdo->query("SELECT field_name FROM fields ORDER BY field_name")->fetchAll(PDO::FETCH_COLUMN);
            $lc = $pdo->query("SELECT class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_COLUMN); natsort($lc); $lc = array_values($lc);
            $ls = $pdo->query("SELECT subject_name FROM subjects ORDER BY subject_name")->fetchAll(PDO::FETCH_COLUMN);
            $ln = $pdo->query("SELECT * FROM announcements ORDER BY id DESC LIMIT 6")->fetchAll();
        }
        $pub_events   = [];
        $pub_tests    = [];
        $pub_faqs     = [];
        $pub_teachers = []; if ($db_connected) { try { $pub_teachers = $pdo->query("SELECT * FROM site_teachers ORDER BY id ASC LIMIT 24")->fetchAll(); } catch (Exception $e) { $pub_teachers = []; } }
        $pub_toppers  = [];
        $pub_offers   = [];
        $pub_batches  = [];
        $pub_gallery  = [];
        $topper_names = [];
        if ($pub_toppers) {
            foreach ($pdo->query("SELECT id, full_name FROM students")->fetchAll() as $s) { $topper_names[$s['id']] = $s['full_name']; }
        }
        if (!$pub_faqs) {
            $pub_faqs = [
                ['c1'=>'How do I get my login?','c2'=>'The admin registers each student and hands over a private username and password. Contact the centre if you have not received yours.'],
                ['c1'=>'How do the online tests work?','c2'=>'Log in, choose a test for your class and stream, and attempt it within the time limit. Your score appears the moment you submit.'],
                ['c1'=>'Can I see which answers were wrong?','c2'=>'Yes. Every result page shows each question, the option you chose and the correct answer so you can learn from every mistake.'],
                ['c1'=>'How can I check my fee record?','c2'=>'Open My Fees in your student account to see every month paid, the amount and the payment date.'],
                ['c1'=>'Can I download the app?','c2'=>'Yes. You can download the app from download-the-apk.netlify.app'],
            ];
        }
        $stream_icons = ['engineering'=>'fa-gears','medical'=>'fa-stethoscope','computer science'=>'fa-laptop-code'];
        $stream_fallback = ['fa-book-open-reader','fa-flask','fa-atom','fa-lightbulb','fa-chart-line','fa-microscope'];
        $marquee = array_merge(['Online MCQ Tests','Instant Results','Full Answer Review','Fee Records','Notices & Updates','Secure Login'], $lf, array_map(function($c){return 'Class '.$c;}, $lc));
    ?>

    <div class="lp-bar"><i class="fa-solid fa-bolt me-1"></i> Admissions open &middot; Smart tuition with online tests and instant results &middot; <a href="#contact">Contact us</a></div>

    <!-- ================= HERO ================= -->
    <section class="lp-hero" id="home">
        <div class="blob b1"></div><div class="blob b2"></div>
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <span class="hero-pill reveal"><span class="dot"></span> Smart Tuition &bull; Online Tests &bull; Instant Results</span>
                    <h1 class="reveal d1">Learn smarter.<br>Score <span class="grad-text">higher.</span><br>Shine at S-Orbit.</h1>
                    <p class="lead reveal d2">Expert teaching, regular timed MCQ tests, instant results with full answer review and a transparent fee record. Everything for your success, in one beautiful place.</p>
                    <div class="d-flex flex-wrap gap-3 mt-4 reveal d3">
                        <a href="index.php?action=login" class="btn btn-warning btn-hero"><i class="fa-solid fa-right-to-bracket me-2"></i>Student / Admin Login</a>
                        <a href="#contact" class="btn btn-glass btn-hero"><i class="fa-solid fa-paper-plane me-2"></i>Contact Us</a>
                    </div>
                    <div class="hero-trust reveal d4">
                        <span><i class="fa-solid fa-circle-check"></i>Timed MCQ tests</span>
                        <span><i class="fa-solid fa-circle-check"></i>Instant scoring</span>
                        <span><i class="fa-solid fa-circle-check"></i>Private accounts</span>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="hero-visual reveal d2">
                        <div class="glass-card gc-test">
                            <div class="d-flex justify-content-between align-items-center mb-2"><strong style="font-family:var(--font-display)">Mock Test &middot; Physics</strong><span class="timer"><i class="fa-regular fa-clock me-1"></i>45:12</span></div>
                            <div class="small text-muted fw-semibold">Q 12 of 40</div>
                            <div class="fw-bold mt-1" style="font-size:.92rem">Which quantity is a vector?</div>
                            <div class="opt"><b>A</b>Speed</div>
                            <div class="opt on"><b>B</b>Velocity</div>
                            <div class="opt"><b>C</b>Distance</div>
                            <div class="opt"><b>D</b>Mass</div>
                        </div>
                        <div class="glass-card gc-result text-center">
                            <div class="small fw-bold text-muted">INSTANT RESULT</div>
                            <div class="ring"><span>92%</span></div>
                            <div class="small fw-semibold">Sample score card</div>
                        </div>
                        <div class="glass-card gc-fee">
                            <div class="d-flex align-items-center gap-3">
                                <div class="feature-icon fi-6 mb-0" style="width:46px;height:46px;font-size:1.1rem;border-radius:14px"><i class="fa-solid fa-check"></i></div>
                                <div><div class="small text-muted fw-semibold">Fee status</div><div class="fw-bold">Paid &#10003;</div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <a href="#about" class="hero-scroll text-decoration-none"><i class="fa-solid fa-chevron-down d-block mb-1"></i>Scroll</a>
    </section>

    <!-- ================= TICKER ================= -->
    <div class="lp-ticker">
        <div class="ticker-track">
            <?php for ($r = 0; $r < 2; $r++): foreach ($marquee as $m): ?>
                <span><i class="fa-solid fa-star"></i><?= htmlspecialchars($m) ?></span>
            <?php endforeach; endfor; ?>
        </div>
    </div>

    <!-- ================= ABOUT ================= -->
    <section class="sec" id="about">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6 reveal">
                    <div class="about-visual">
                        <div class="about-main"><i class="fa-solid fa-book-open-reader"></i></div>
                    </div>
                </div>
                <div class="col-lg-6 reveal d2">
                    <span class="eyebrow">About S-Orbit</span>
                    <h2 class="sec-h">A tuition centre that thinks <span class="grad-text">like a student.</span></h2>
                    <p class="sec-p">We pair focused teaching with a modern testing portal. Students practise with timed MCQ tests, see results instantly and learn from a full answer review, while parents enjoy a clear, honest fee record.</p>
                    <ul class="check-list">
                        <li><i class="fa-solid fa-check"></i>Tests built for your exact class and stream</li>
                        <li><i class="fa-solid fa-check"></i>Every answer explained through side-by-side review</li>
                        <li><i class="fa-solid fa-check"></i>Month-by-month fee history, always available</li>
                        <li><i class="fa-solid fa-check"></i>Notices and updates straight to your dashboard</li>
                    </ul>
                    <a href="index.php?action=login" class="btn btn-primary btn-lg mt-3 px-4">Go to Student Portal <i class="fa-solid fa-arrow-right ms-2"></i></a>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= FEATURES ================= -->
    <section class="sec sec-white" id="features">
        <div class="container">
            <div class="center-head reveal">
                <span class="eyebrow">Why choose us</span>
                <h2 class="sec-h">Everything you need to <span class="grad-text">succeed.</span></h2>
                <p class="sec-p">Six powerful features designed to make learning measurable, transparent and stress-free.</p>
            </div>
            <div class="row g-4">
                <?php
                $features = [
                    ['fa-pen-to-square','Online MCQ Tests','Timed tests for your class and stream, available anytime on any device.'],
                    ['fa-square-poll-vertical','Instant Results','Get your score, percentage and time taken the moment you submit.'],
                    ['fa-magnifying-glass-chart','Full Answer Review','See every question, your answer and the correct answer to learn from mistakes.'],
                    ['fa-wallet','Transparent Fee Record','Check which months you paid and how much, any time from your account.'],
                    ['fa-bullhorn','Notices & Updates','Important announcements from teachers appear right on your dashboard.'],
                    ['fa-shield-halved','Secure Personal Login','Every student has a private account. Only you see your results and fees.'],
                ];
                foreach ($features as $i => $f): ?>
                <div class="col-md-6 col-lg-4 reveal d<?= ($i % 3) + 1 ?>">
                    <div class="feature-card">
                        <div class="feature-icon fi-<?= $i + 1 ?>"><i class="fa-solid <?= $f[0] ?>"></i></div>
                        <h5><?= $f[1] ?></h5>
                        <p class="text-muted mb-0"><?= $f[2] ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ================= COURSES ================= -->
    <section class="sec" id="courses">
        <div class="container">
            <div class="center-head reveal">
                <span class="eyebrow">Classes &amp; Streams</span>
                <h2 class="sec-h">Find your <span class="grad-text">path.</span></h2>
                <p class="sec-p">Choose your class and field. Tests and learning material are tailored to each one.</p>
            </div>
            <div class="row g-4 mb-5">
                <?php if (!$lf): ?><div class="col-12 text-center text-muted">Streams coming soon.</div><?php endif; ?>
                <?php foreach ($lf as $i => $f):
                    $ic = $stream_icons[strtolower($f)] ?? $stream_fallback[$i % count($stream_fallback)]; ?>
                <div class="col-md-6 col-lg-4 reveal d<?= ($i % 3) + 1 ?>">
                    <div class="stream-card sc-<?= $i % 6 ?>">
                        <i class="fa-solid <?= $ic ?>"></i>
                        <h4><?= htmlspecialchars($f) ?></h4>
                        <p>Focused practice tests and expert guidance for this stream.</p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($lc): ?>
            <div class="row g-4 justify-content-center">
                <?php foreach ($lc as $i => $c): ?>
                <div class="col-6 col-md-4 col-lg-3 reveal d<?= ($i % 4) + 1 ?>">
                    <div class="class-tile"><div class="small fw-bold text-muted mb-2">CLASS</div><div class="big"><?= htmlspecialchars($c) ?></div></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($ls): ?>
            <div class="text-center mt-5 reveal">
                <div class="fw-bold mb-3" style="font-family:var(--font-display)">Subjects we cover</div>
                <?php foreach ($ls as $s): ?><span class="chip"><?= htmlspecialchars($s) ?></span><?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ================= PORTAL PREVIEW ================= -->
    <section class="sec sec-dark" id="portal" style="overflow:hidden">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-5 reveal">
                    <span class="eyebrow">Student Portal</span>
                    <h2 class="sec-h">Your whole progress, <span class="grad-text">one dashboard.</span></h2>
                    <p class="sec-p">Log in to attempt tests, revisit results, read notices and check fees. Clean, fast and works on phone, tablet and desktop.</p>
                    <ul class="check-list" style="color:#e2e4ff">
                        <li style="color:#e2e4ff"><i class="fa-solid fa-check"></i>Take timed tests with a live countdown</li>
                        <li style="color:#e2e4ff"><i class="fa-solid fa-check"></i>See scores and answers instantly</li>
                        <li style="color:#e2e4ff"><i class="fa-solid fa-check"></i>Track every fee payment by month</li>
                    </ul>
                    <a href="index.php?action=login" class="btn btn-warning btn-lg mt-3 px-4">Open Portal <i class="fa-solid fa-arrow-right ms-2"></i></a>
                </div>
                <div class="col-lg-7 reveal d2">
                    <div class="mock-window">
                        <div class="mock-top"><i style="background:#ff5f57"></i><i style="background:#febc2e"></i><i style="background:#28c840"></i><span class="url"><i class="fa-solid fa-lock me-1" style="width:auto;height:auto;background:none"></i>portal / dashboard</span></div>
                        <div class="mock-body">
                            <div class="mock-side">
                                <div class="on"><i class="fa-solid fa-house me-2"></i>Dashboard</div>
                                <div><i class="fa-solid fa-pen-to-square me-2"></i>Tests</div>
                                <div><i class="fa-solid fa-chart-simple me-2"></i>Results</div>
                                <div><i class="fa-solid fa-wallet me-2"></i>My Fees</div>
                                <div><i class="fa-solid fa-bullhorn me-2"></i>Notices</div>
                            </div>
                            <div class="mock-main">
                                <div class="row g-3 mb-3">
                                    <div class="col-4"><div class="mini-stat"><b>12</b><small>Tests taken</small></div></div>
                                    <div class="col-4"><div class="mini-stat"><b style="color:#10b981">86%</b><small>Avg score</small></div></div>
                                    <div class="col-4"><div class="mini-stat"><b style="color:#5b5bf0">Paid</b><small>This month</small></div></div>
                                </div>
                                <div class="fw-bold small mb-2">Score trend (sample)</div>
                                <div class="bars"><i style="height:45%"></i><i style="height:60%"></i><i style="height:52%"></i><i style="height:74%"></i><i style="height:68%"></i><i style="height:85%"></i><i style="height:92%"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= HOW IT WORKS ================= -->
    <section class="sec">
        <div class="container">
            <div class="center-head reveal">
                <span class="eyebrow">How it works</span>
                <h2 class="sec-h">Start in <span class="grad-text">four easy steps.</span></h2>
            </div>
            <div class="row g-4 timeline">
                <div class="col-md-6 col-lg-3 step-card reveal"><div class="step-num">1</div><h5>Contact</h5><p class="text-muted">Call us or visit the centre and talk to our team.</p></div>
                <div class="col-md-6 col-lg-3 step-card reveal d1"><div class="step-num">2</div><h5>Get Your Login</h5><p class="text-muted">The admin registers you and gives you a private username and password.</p></div>
                <div class="col-md-6 col-lg-3 step-card reveal d2"><div class="step-num">3</div><h5>Take Tests</h5><p class="text-muted">Pick a test for your class and attempt it within the time limit.</p></div>
                <div class="col-md-6 col-lg-3 step-card reveal d3"><div class="step-num">4</div><h5>Track Progress</h5><p class="text-muted">Review answers, see results and check your monthly fee history.</p></div>
            </div>
        </div>
    </section>

    <!-- ================= COMPARE ================= -->
    <section class="sec sec-white">
        <div class="container">
            <div class="center-head reveal">
                <span class="eyebrow">The difference</span>
                <h2 class="sec-h">Old-school tuition vs <span class="grad-text">S-Orbit.</span></h2>
            </div>
            <div class="row g-4 justify-content-center">
                <div class="col-lg-5 reveal">
                    <div class="compare old">
                        <h4 class="fw-bold"><i class="fa-regular fa-face-frown me-2 text-muted"></i>Traditional</h4>
                        <ul>
                            <li><i class="fa-solid fa-xmark"></i>Paper tests, results after days</li>
                            <li><i class="fa-solid fa-xmark"></i>No review of wrong answers</li>
                            <li><i class="fa-solid fa-xmark"></i>Lost fee slips and confusion</li>
                            <li><i class="fa-solid fa-xmark"></i>Announcements get missed</li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-5 reveal d2">
                    <div class="compare new">
                        <h4 class="fw-bold"><i class="fa-regular fa-face-smile-beam me-2" style="color:#fbbf24"></i>S-Orbit</h4>
                        <ul>
                            <li><i class="fa-solid fa-check"></i>Online timed tests, results instantly</li>
                            <li><i class="fa-solid fa-check"></i>Full review with correct answers</li>
                            <li><i class="fa-solid fa-check"></i>Digital fee record, always available</li>
                            <li><i class="fa-solid fa-check"></i>Notices on your dashboard</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php if ($pub_teachers): ?>
    <!-- ================= TEACHERS ================= -->
    <section class="sec">
        <div class="container">
            <div class="center-head reveal"><span class="eyebrow">Our faculty</span><h2 class="sec-h">Meet your <span class="grad-text">mentors.</span></h2></div>
            <div class="row g-4 justify-content-center">
                <?php foreach ($pub_teachers as $i => $t): ?>
                <div class="col-sm-6 col-lg-3 reveal d<?= ($i % 4) + 1 ?>">
                    <div class="person-card">
                        <h5 class="fw-bold mb-1"><?= htmlspecialchars($t['full_name']) ?></h5>
                        <?php if (!empty($t['subject'])): ?><div class="fw-semibold" style="color:#5b5bf0"><?= htmlspecialchars($t['subject']) ?></div><?php endif; ?>
                        <div class="mt-2">
                            <span class="t-chip"><i class="fa-solid <?= $t['gender'] === 'Female' ? 'fa-venus' : ($t['gender'] === 'Male' ? 'fa-mars' : 'fa-genderless') ?> me-1"></i><?= htmlspecialchars($t['gender']) ?></span>
                            <?php if (!empty($t['grade'])): ?><span class="t-chip"><i class="fa-solid fa-graduation-cap me-1"></i><?= htmlspecialchars($t['grade']) ?></span><?php endif; ?>
                        </div>
                        <?php if (!empty($t['qualification'])): ?><div class="small text-muted mt-2"><i class="fa-solid fa-award me-1"></i><?= htmlspecialchars($t['qualification']) ?></div><?php endif; ?>
                        <?php if (!empty($t['experience'])): ?><div class="small text-muted"><i class="fa-solid fa-briefcase me-1"></i><?= htmlspecialchars($t['experience']) ?></div><?php endif; ?>
                        <?php if (!empty($t['bio'])): ?><p class="small text-muted mt-2 mb-0"><?= nl2br(htmlspecialchars($t['bio'])) ?></p><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($pub_toppers): ?>
    <!-- ================= TOPPERS ================= -->
    <section class="sec sec-white">
        <div class="container">
            <div class="center-head reveal"><span class="eyebrow">Hall of fame</span><h2 class="sec-h">Our <span class="grad-text">toppers.</span></h2></div>
            <div class="row g-4 justify-content-center">
                <?php foreach ($pub_toppers as $i => $t): $nm = $topper_names[$t['c1']] ?? 'Student'; ?>
                <div class="col-sm-6 col-lg-3 reveal d<?= ($i % 4) + 1 ?>">
                    <div class="person-card">
                        <div class="avatar" style="background:var(--grad-gold);color:#1a1200"><i class="fa-solid fa-crown"></i></div>
                        <h5 class="fw-bold mb-1"><?= htmlspecialchars($nm) ?></h5>
                        <div class="text-muted small mb-2"><?= htmlspecialchars((string)$t['c2']) ?></div>
                        <div class="fw-bold" style="font-size:1.6rem;font-family:var(--font-display)"><?= htmlspecialchars((string)(float)$t['c3']) ?>%</div>
                        <?php if (!empty($t['c4'])): ?><span class="rank-badge mt-2">Rank <?= (int)$t['c4'] ?></span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($pub_offers): ?>
    <section class="sec">
        <div class="container">
            <div class="center-head reveal"><span class="eyebrow">Special offers</span><h2 class="sec-h">Save more, <span class="grad-text">learn more.</span></h2></div>
            <div class="row g-4 justify-content-center">
                <?php foreach ($pub_offers as $i => $o): ?>
                <div class="col-md-6 col-lg-4 reveal d<?= $i + 1 ?>">
                    <div class="offer-card">
                        <i class="fa-solid fa-tags fa-2x mb-3"></i>
                        <h4 class="fw-bold"><?= htmlspecialchars((string)$o['c1']) ?></h4>
                        <p><?= nl2br(htmlspecialchars((string)$o['c2'])) ?></p>
                        <?php if (!empty($o['c3'])): ?><div class="small fw-bold">Valid till <?= date('d M Y', strtotime($o['c3'])) ?></div><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($pub_batches): ?>
    <section class="sec sec-white">
        <div class="container">
            <div class="center-head reveal"><span class="eyebrow">Batches</span><h2 class="sec-h">Pick your <span class="grad-text">batch.</span></h2></div>
            <div class="row g-4">
                <?php foreach ($pub_batches as $i => $b): ?>
                <div class="col-md-6 col-lg-4 reveal d<?= ($i % 3) + 1 ?>">
                    <div class="info-card">
                        <span class="date"><i class="fa-solid fa-users"></i>Class <?= htmlspecialchars((string)$b['c2']) ?></span>
                        <h5 class="fw-bold"><?= htmlspecialchars((string)$b['c1']) ?></h5>
                        <?php if (!empty($b['c4'])): ?><div class="text-muted small mt-1"><i class="fa-solid fa-chalkboard-user me-2"></i><?= htmlspecialchars((string)$b['c4']) ?></div><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ================= NOTICES ================= -->
    <section class="sec<?= $pub_batches ? '' : ' sec-white' ?>" id="notices">
        <div class="container">
            <div class="center-head reveal"><span class="eyebrow">Stay updated</span><h2 class="sec-h">Latest <span class="grad-text">notices.</span></h2></div>
            <div class="row g-4">
                <?php if (!$ln): ?><div class="col-12 text-center text-muted">No notices at the moment.</div><?php endif; ?>
                <?php foreach ($ln as $i => $n): ?>
                <div class="col-md-6 col-lg-4 reveal d<?= ($i % 3) + 1 ?>">
                    <div class="info-card">
                        <span class="date"><i class="fa-regular fa-calendar"></i><?= date('d M Y', strtotime($n['created_at'])) ?></span>
                        <h5 class="fw-bold"><?= htmlspecialchars($n['title']) ?></h5>
                        <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($n['body'])) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php if ($pub_events): ?>
    <section class="sec sec-white">
        <div class="container">
            <div class="center-head reveal"><span class="eyebrow">Calendar</span><h2 class="sec-h">Upcoming <span class="grad-text">events.</span></h2></div>
            <div class="row g-4">
                <?php foreach ($pub_events as $i => $e): ?>
                <div class="col-md-6 col-lg-3 reveal d<?= $i + 1 ?>">
                    <div class="info-card">
                        <span class="date"><i class="fa-regular fa-calendar"></i><?= $e['c2'] ? date('d M Y', strtotime($e['c2'])) : 'TBA' ?></span>
                        <h6 class="fw-bold"><?= htmlspecialchars((string)$e['c1']) ?></h6>
                        <div class="small text-muted"><i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars((string)$e['c3']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($pub_tests): ?>
    <section class="sec">
        <div class="container">
            <div class="center-head reveal"><span class="eyebrow">Testimonials</span><h2 class="sec-h">What students <span class="grad-text">say.</span></h2></div>
            <div class="row g-4">
                <?php foreach ($pub_tests as $i => $t): ?>
                <div class="col-md-4 reveal d<?= $i + 1 ?>">
                    <div class="quote-card">
                        <div class="mb-3" style="color:#fbbf24"><?= str_repeat('★', max(0, min(5, (int)$t['c3']))) ?></div>
                        <p class="text-muted" style="line-height:1.8;position:relative"><?= htmlspecialchars((string)$t['c2']) ?></p>
                        <div class="fw-bold">&mdash; <?= htmlspecialchars((string)$t['c1']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php
    $gal = array_filter($pub_gallery, function($g){ return !empty($g['c2']) && preg_match('#^https?://#i', (string)$g['c2']); });
    if ($gal): ?>
    <section class="sec sec-white">
        <div class="container">
            <div class="center-head reveal"><span class="eyebrow">Gallery</span><h2 class="sec-h">Life at <span class="grad-text">S-Orbit.</span></h2></div>
            <div class="row g-3">
                <?php foreach ($gal as $i => $g): ?>
                <div class="col-6 col-lg-3 reveal d<?= ($i % 4) + 1 ?>"><div class="gallery-img"><img src="<?= htmlspecialchars((string)$g['c2']) ?>" alt="<?= htmlspecialchars((string)$g['c1']) ?>" loading="lazy"><span><?= htmlspecialchars((string)$g['c1']) ?></span></div></div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ================= FAQ ================= -->
    <section class="sec<?= $gal ? '' : ' sec-white' ?>" id="faq">
        <div class="container" style="max-width:860px">
            <div class="center-head reveal"><span class="eyebrow">FAQ</span><h2 class="sec-h">Questions? <span class="grad-text">Answered.</span></h2></div>
            <div class="accordion reveal" id="faqAcc">
                <?php foreach ($pub_faqs as $i => $q): ?>
                <div class="accordion-item">
                    <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>"><?= htmlspecialchars((string)$q['c1']) ?></button></h2>
                    <div id="faq<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#faqAcc"><div class="accordion-body text-muted"><?= nl2br(htmlspecialchars((string)$q['c2'])) ?></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ================= CTA ================= -->
    <section class="sec" style="padding-top:40px">
        <div class="container">
            <div class="cta-big reveal">
                <h2 class="mb-3">Ready to <span class="grad-text">level up</span> your results?</h2>
                <p class="mb-4" style="color:rgba(226,228,255,.78);font-size:1.15rem;max-width:560px;margin-inline:auto">Join S-Orbit Tuition today and turn every test into progress.</p>
                <a href="#contact" class="btn btn-warning btn-hero me-2 mb-2"><i class="fa-solid fa-paper-plane me-2"></i>Contact Us</a>
                <a href="index.php?action=login" class="btn btn-glass btn-hero mb-2">Student Login</a>
            </div>
        </div>
    </section>

    <!-- ================= CONTACT ================= -->
    <section class="sec sec-white" id="contact">
        <div class="container">
            <div class="center-head reveal"><span class="eyebrow">Contact</span><h2 class="sec-h">Let&rsquo;s <span class="grad-text">talk.</span></h2><p class="sec-p">Call, message or visit us using the details below.</p></div>
            <div class="row g-5 align-items-start">
                <div class="col-lg-6 mx-auto reveal">
                    <div class="contact-card"><div class="ic"><i class="fa-solid fa-phone"></i></div><div><h6>Phone</h6><p><?php foreach ($site_phones as $i => $ph): ?><?= $i ? '<br>' : '' ?><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $ph)) ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($ph) ?></a><?php endforeach; ?></p></div></div>
                    <?php if (trim($site['whatsapp']) !== ''): ?><div class="contact-card"><div class="ic"><i class="fa-brands fa-whatsapp"></i></div><div><h6>WhatsApp</h6><p><a href="https://wa.me/<?= htmlspecialchars(preg_replace('/[^0-9]/', '', $site['whatsapp'])) ?>" target="_blank" rel="noopener" style="color:inherit;text-decoration:none"><?= htmlspecialchars($site['whatsapp']) ?></a></p></div></div><?php endif; ?>
                    <div class="contact-card"><div class="ic"><i class="fa-solid fa-envelope"></i></div><div><h6>Email</h6><p><a href="mailto:<?= htmlspecialchars($site['email']) ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($site['email']) ?></a></p></div></div>
                    <div class="contact-card"><div class="ic"><i class="fa-solid fa-location-dot"></i></div><div><h6>Address</h6><p><?= nl2br(htmlspecialchars($site['address'])) ?></p></div></div>
                </div>
            </div>
        </div>
    </section>

    <?php
    // LOGIN FORM
    elseif (!isset($_SESSION['role'])):
    ?>
    <div class="login-shell">
        <div class="login-art">
            <div class="mark"><i class="fa-solid fa-graduation-cap"></i></div>
            <h2 class="fw-bold mb-3" style="font-size:2.2rem">Welcome back to <span class="grad-text">S-Orbit</span></h2>
            <p style="color:rgba(226,228,255,.78);line-height:1.7">Sign in to take timed MCQ tests, review answers, track your progress and check your fee record, all in one secure place.</p>
            <div class="mt-3" style="font-weight:600;font-size:.92rem">
                <div class="mb-2"><i class="fa-solid fa-circle-check me-2" style="color:#34d399"></i>Instant results</div>
                <div class="mb-2"><i class="fa-solid fa-circle-check me-2" style="color:#34d399"></i>Full answer review</div>
                <div><i class="fa-solid fa-circle-check me-2" style="color:#34d399"></i>Private personal account</div>
            </div>
        </div>
        <div class="login-form">
            <h3 class="fw-bold mb-1">Sign in</h3>
            <p class="text-muted mb-4">Unified Student &amp; Admin login</p>
            <form action="index.php" method="POST">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control form-control-lg" required autofocus>
                </div>
                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <div class="pw-wrap">
                        <input type="password" name="password" id="pwField" class="form-control form-control-lg" required>
                        <button type="button" onclick="var f=document.getElementById('pwField');f.type=f.type==='password'?'text':'password';this.firstElementChild.className=f.type==='password'?'fa-regular fa-eye':'fa-regular fa-eye-slash'" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
                    </div>
                </div>
                <button type="submit" name="login_btn" class="btn btn-primary btn-lg w-100 py-3 fw-bold" <?= !$db_connected ? 'disabled' : '' ?>>Sign In <i class="fa-solid fa-arrow-right ms-2"></i></button>
            </form>
            <div class="text-center mt-4"><a href="index.php" class="small text-decoration-none fw-semibold"><i class="fa-solid fa-arrow-left me-1"></i>Back to website</a></div>
        </div>
    </div>

    <?php
    // ADMIN ACADEMIC MANAGEMENT (Includes Admin Subject Addition Form)
    elseif ($_SESSION['role'] === 'admin' && $action === 'manage_academic'):
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-0"><i class="fa-solid fa-sitemap text-primary me-2"></i>Academic Setup Management</h2>
            <p class="text-muted mb-0">Configure fields/streams, classes, and add new subjects mapping to specific fields</p>
        </div>
        <a href="index.php?action=admin_dashboard" class="btn btn-outline-secondary fw-semibold">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <div class="card card-custom p-4 h-100">
                <h4 class="fw-bold mb-3"><i class="fa-solid fa-layer-group text-primary me-2"></i>Add Field / Stream</h4>
                <form method="POST" action="index.php?action=manage_academic">
                    <div class="mb-3">
                        <label class="form-label">Field Name</label>
                        <input type="text" name="field_name" class="form-control" placeholder="e.g. Commerce, Arts" required>
                    </div>
                    <button type="submit" name="add_field_btn" class="btn btn-primary w-100 fw-bold">Add Field</button>
                </form>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card card-custom p-4 h-100">
                <h4 class="fw-bold mb-3"><i class="fa-solid fa-school text-success me-2"></i>Add Class</h4>
                <form method="POST" action="index.php?action=manage_academic">
                    <div class="mb-3">
                        <label class="form-label">Class Name</label>
                        <input type="text" name="class_name" class="form-control" placeholder="e.g. 10th, B.Sc" required>
                    </div>
                    <button type="submit" name="add_class_btn" class="btn btn-success w-100 fw-bold">Add Class</button>
                </form>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card card-custom p-4 h-100">
                <h4 class="fw-bold mb-3"><i class="fa-solid fa-book text-warning me-2"></i>Add New Subject & Map</h4>
                <form method="POST" action="index.php?action=manage_academic">
                    <div class="mb-3">
                        <label class="form-label">New Subject Name</label>
                        <input type="text" name="subject_name" class="form-control" placeholder="e.g. Accounting, Physics" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Applicable Fields</label>
                        <?php
                        $db_fields = $pdo->query("SELECT * FROM fields ORDER BY field_name ASC")->fetchAll();
                        if (empty($db_fields)):
                        ?>
                            <p class="text-muted small">No fields added yet.</p>
                        <?php else: ?>
                            <?php foreach ($db_fields as $f): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="field_ids[]" value="<?= $f['id'] ?>" id="field_<?= $f['id'] ?>">
                                    <label class="form-check-label" for="field_<?= $f['id'] ?>">
                                        <?= htmlspecialchars($f['field_name']) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <button type="submit" name="add_subject_btn" class="btn btn-warning w-100 fw-bold">Save Subject & Mapping</button>
                </form>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card card-custom p-4">
                <h4 class="fw-bold mb-3"><i class="fa-solid fa-list text-secondary me-2"></i>Existing Subjects List</h4>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Subject Name</th>
                                <th>Mapped Fields / Streams</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $subjStmt = $pdo->query("
                                SELECT s.subject_name, GROUP_CONCAT(f.field_name SEPARATOR ', ') as mapped_fields 
                                FROM subjects s 
                                LEFT JOIN field_subjects fs ON s.id = fs.subject_id 
                                LEFT JOIN fields f ON fs.field_id = f.id 
                                GROUP BY s.id 
                                ORDER BY s.subject_name ASC
                            ");
                            if ($subjStmt->rowCount() == 0):
                            ?>
                            <tr><td colspan="2" class="text-center text-muted py-3">No subjects added yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($subjStmt->fetchAll() as $subRow): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($subRow['subject_name']) ?></td>
                                    <td>
                                        <?php if (!empty($subRow['mapped_fields'])): ?>
                                            <span class="badge bg-info text-dark"><?= htmlspecialchars($subRow['mapped_fields']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted italic">Not mapped to any field</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php
    // ADMIN DASHBOARD
    elseif ($_SESSION['role'] === 'admin' && ($action === 'admin_dashboard' || $action === 'home')):
        $selected_student_id = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $filter_date = trim((string)($_GET['filter_date'] ?? ''));
        if ($filter_date !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $filter_date)) $filter_date = '';
        $filter_class = trim((string)($_GET['filter_class'] ?? ''));
        $filter_gender = trim((string)($_GET['filter_gender'] ?? ''));
        if (!in_array($filter_gender, ['', 'Male', 'Female'], true)) $filter_gender = '';
        // Only accept a class that actually exists; prevents stale/invalid query values.
        $classCheck = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE class_name = ?");
        if ($filter_class !== '') {
            $classCheck->execute([$filter_class]);
            if ((int)$classCheck->fetchColumn() === 0) $filter_class = '';
        }

        $db_classes = $pdo->query("SELECT * FROM classes ORDER BY id ASC")->fetchAll();
        $db_fields = $pdo->query("SELECT * FROM fields ORDER BY field_name ASC")->fetchAll();
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h2 class="fw-bold text-dark mb-0"><i class="fa-solid fa-user-shield text-primary me-2"></i>Admin Dashboard</h2>
            <p class="text-muted mb-0">Manage students, individual test settings, subjects, and view advanced filtered results</p>
        </div>
        <div class="d-flex gap-2 admin-dashboard-actions">
            <a href="index.php?action=admin_qbank" class="btn btn-primary fw-semibold"><i class="fa-solid fa-database me-2"></i>Existing Question Bank &amp; Tests</a>
            <a href="index.php?action=admin_test_reports" class="btn btn-outline-primary fw-semibold">Test Reports</a>
            <a href="index.php?action=combine_tests" class="btn btn-outline-info fw-semibold">Combine Tests</a>
            <a href="index.php?action=backup" class="btn btn-outline-secondary fw-semibold">Backup</a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- REGISTER STUDENT FORM -->
        <div class="col-lg-5">
            <div class="card card-custom p-4">
                <h4 class="fw-bold mb-3"><i class="fa-solid fa-user-plus text-primary me-2"></i>Register Student</h4>
                <form action="index.php?action=admin_dashboard" method="POST">
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="std_fullname" class="form-control" placeholder="John Doe" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="std_username" class="form-control" placeholder="johndoe12" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="std_password" class="form-control" placeholder="••••••••" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <label class="form-label">Class</label>
                            <select name="std_class" class="form-select" required>
                                <?php foreach ($db_classes as $c): ?>
                                    <option value="<?= htmlspecialchars($c['class_name']) ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-4">
                            <label class="form-label">Field / Stream</label>
                            <select name="std_stream" class="form-select" required>
                                <?php foreach ($db_fields as $f): ?>
                                    <option value="<?= htmlspecialchars($f['field_name']) ?>"><?= htmlspecialchars($f['field_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-4">
                            <label class="form-label">Gender</label>
                            <select name="std_gender" class="form-select" required>
                                <option value="Female">Female</option>
                                <option value="Male">Male</option>
                            </select>
                        </div>
                    </div>
                    <details class="mb-3">
                        <summary class="fw-semibold text-primary" style="cursor:pointer"><i class="fa-solid fa-address-card me-1"></i>More Student Information (phone, father, address...)</summary>
                        <div class="row g-2 mt-1"><?= student_extra_inputs() ?></div>
                    </details>
                    <button type="submit" name="add_student_btn" class="btn btn-primary w-100 fw-bold">Register Student Account</button>
                </form>
            </div>

        </div>

        <!-- STUDENTS LIST & RESET ATTEMPT BUTTON -->
        <div class="col-lg-7">
            <div class="card card-custom p-4">
                <h4 class="fw-bold mb-3"><i class="fa-solid fa-users text-primary me-2"></i>Registered Students & Test Reset</h4>
                <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Class & Gender</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->query("SELECT * FROM students ORDER BY id DESC");
                            if ($stmt->rowCount() == 0):
                            ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">No students registered yet.</td></tr>
                            <?php else: ?>
                                <?php while ($row = $stmt->fetch()): ?>
                                <tr class="<?= ($selected_student_id == $row['id']) ? 'table-primary' : '' ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($row['full_name']) ?></strong><br>
                                        <small class="text-muted">Username: <?= htmlspecialchars($row['username']) ?></small>
                                        <?php if (!empty($row['father_name']) || !empty($row['father_phone'])): ?><br><small class="text-muted"><i class="fa-solid fa-user-tie me-1"></i><?= htmlspecialchars((string)$row['father_name']) ?> <?= htmlspecialchars((string)$row['father_phone']) ?></small><?php endif; ?>
                                        <?php if (!empty($row['student_phone'])): ?><br><small class="text-muted"><i class="fa-solid fa-mobile-screen me-1"></i><?= htmlspecialchars((string)$row['student_phone']) ?></small><?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($row['class']) ?> (<?= htmlspecialchars($row['stream']) ?>)<br>
                                        <span class="badge <?= $row['gender'] === 'Female' ? 'bg-danger' : 'bg-info text-dark' ?>">
                                            <?= htmlspecialchars($row['gender']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-1">
                                            <a href="index.php?action=admin_student_edit&id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary fw-semibold"><i class="fa-solid fa-pen me-1"></i> Edit Details</a>
                                            <a href="index.php?action=admin_student_results&id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-primary fw-semibold">
                                                <i class="fa-solid fa-square-poll-vertical me-1"></i> Results
                                            </a>
                                            <?php
                                            $stmtStudentAttempts = $pdo->prepare("SELECT st.id AS attempt_id, st.test_id, st.subject, st.test_date, tm.test_name FROM student_tests st LEFT JOIN tests_meta tm ON tm.id = st.test_id WHERE st.student_id = ? ORDER BY st.test_date DESC");
                                            $stmtStudentAttempts->execute([(int)$row['id']]);
                                            $studentAttempts = $stmtStudentAttempts->fetchAll(PDO::FETCH_ASSOC);
                                            ?>
                                            <?php if (!empty($studentAttempts)): ?>
                                                <details class="mt-1">
                                                    <summary class="btn btn-sm btn-outline-warning fw-semibold"><i class="fa-solid fa-rotate-right me-1"></i> Allow Retake (Choose Test)</summary>
                                                    <div class="border rounded p-2 mt-2 bg-light" style="min-width:220px;">
                                                        <?php foreach ($studentAttempts as $attempt): ?>
                                                            <div class="d-flex justify-content-between align-items-center gap-2 py-1 border-bottom">
                                                                <div class="small">
                                                                    <strong><?= htmlspecialchars(!empty($attempt['test_name']) ? $attempt['test_name'] : ($attempt['subject'] ?: 'General Test')) ?></strong><br>
                                                                    <span class="text-muted"><?= htmlspecialchars((string)$attempt['test_date']) ?></span>
                                                                </div>
                                                                <a class="btn btn-sm btn-warning" href="index.php?action=admin_dashboard&reset_student_id=<?= (int)$row['id'] ?>&reset_attempt_id=<?= (int)$attempt['attempt_id'] ?>" onclick="return confirm('Reset only this test attempt for this student? Other test results will remain unchanged.')">Retake</a>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </details>
                                            <?php else: ?>
                                                <small class="text-muted">No tests taken yet</small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php
    // QUESTIONS BANK VIEW
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_mcqs'):
        $mcqDraft = $_SESSION['mcq_form_draft'] ?? [];
        unset($_SESSION['mcq_form_draft']);
        $db_classes = $pdo->query("SELECT * FROM classes ORDER BY id ASC")->fetchAll();
        $db_fields = $pdo->query("SELECT * FROM fields ORDER BY field_name ASC")->fetchAll();
        $db_subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_name ASC")->fetchAll();
        $db_tests = $pdo->query("SELECT * FROM tests_meta ORDER BY id DESC")->fetchAll();
        $editingQuestion = null; if (!empty($_GET['edit_q'])) { $eq=$pdo->prepare('SELECT * FROM questions WHERE id=?'); $eq->execute([(int)$_GET['edit_q']]); $editingQuestion=$eq->fetch() ?: null; }
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-0"><i class="fa-solid fa-file-circle-plus text-primary me-2"></i>MCQ Question Bank</h2>
            <p class="text-muted mb-0">Create questions and assign/name them to custom tests</p>
        </div>
        <a href="index.php?action=admin_dashboard" class="btn btn-outline-secondary fw-semibold">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>

    <div class="row g-4">
        <div class="col-lg-8 mx-auto">
            <?php
        $activeTab = $_SESSION['mcq_tab'] ?? 'type'; unset($_SESSION['mcq_tab']);
        if (!in_array($activeTab, ['type','upload','ai'], true)) $activeTab = 'type';
        $draftRows = (isset($_SESSION['mcq_draft']) && is_array($_SESSION['mcq_draft'])) ? $_SESSION['mcq_draft'] : [];
        $draftSubject = (string)($_SESSION['mcq_draft_subject'] ?? 'General');
    ?>
            <?php if ($draftRows): ?>
            <div class="card card-custom p-4 mb-4 border-success" id="mcqDraftCard">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h4 class="fw-bold mb-0"><i class="fa-solid fa-list-check text-success me-2"></i>Draft: <?= count($draftRows) ?> MCQs to review</h4>
                    <form method="POST" action="index.php?action=admin_mcqs" onsubmit="return confirm('Clear the whole draft?');"><button class="btn btn-sm btn-outline-danger" name="discard_mcq_draft_btn">Clear draft</button></form>
                </div>
                <p class="small text-muted">Questions from Upload and AI Generate collect here. Edit anything, untick what you do not want, then save once.</p>
                <form method="POST" action="index.php?action=admin_mcqs" id="mcqDraftForm" onsubmit="return packMcqDraft(this)">
                    <input type="hidden" name="draft_json" value="">
                    <div style="max-height:480px;overflow-y:auto" class="mb-3">
                    <?php foreach ($draftRows as $i => $r): ?>
                        <div class="border rounded p-2 mb-2 draft-row">
                            <div class="form-check mb-1"><input class="form-check-input dk" type="checkbox" id="dk_<?= $i ?>" checked><label class="form-check-label small fw-bold" for="dk_<?= $i ?>">Q<?= $i+1 ?></label></div>
                            <textarea class="form-control form-control-sm mb-1 dq" rows="2"><?= htmlspecialchars($r['question_text']) ?></textarea>
                            <div class="row g-1 mb-1">
                                <div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">A</span><input class="form-control da" value="<?= htmlspecialchars($r['option_a']) ?>"></div></div>
                                <div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">B</span><input class="form-control db" value="<?= htmlspecialchars($r['option_b']) ?>"></div></div>
                                <div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">C</span><input class="form-control dc" value="<?= htmlspecialchars($r['option_c']) ?>"></div></div>
                                <div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">D</span><input class="form-control dd" value="<?= htmlspecialchars($r['option_d']) ?>"></div></div>
                            </div>
                            <select class="form-select form-select-sm dkey" style="max-width:190px"><?php foreach (['A','B','C','D'] as $op): ?><option value="<?= $op ?>" <?= $r['correct_option']===$op?'selected':'' ?>>Correct: <?= $op ?></option><?php endforeach; ?></select>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <div class="mb-2"><label class="form-label">Subject</label><input class="form-control" name="draft_subject" maxlength="80" value="<?= htmlspecialchars($draftSubject) ?>" required></div>
                    <div class="p-3 bg-light rounded border mb-3">
                        <div class="form-check"><input class="form-check-input" type="radio" name="dr_mode" id="dr_mode_new" value="new" checked onchange="drToggle()"><label class="form-check-label fw-semibold" for="dr_mode_new">Create a new test</label></div>
                        <div class="form-check mb-2"><input class="form-check-input" type="radio" name="dr_mode" id="dr_mode_existing" value="existing" onchange="drToggle()"><label class="form-check-label fw-semibold" for="dr_mode_existing">Add to an existing test</label></div>
                        <div id="dr_new_box" class="row g-2">
                            <div class="col-12"><input class="form-control" name="dr_new_test_name" maxlength="150" placeholder="New test name, e.g. Biology Chapter 3 Test"></div>
                            <div class="col-md-6"><select class="form-select" name="dr_class"><option value="">Select class</option><?php foreach ($db_classes as $c): ?><option value="<?= htmlspecialchars($c['class_name']) ?>"><?= htmlspecialchars($c['class_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><select class="form-select" name="dr_stream"><option value="">Select stream</option><?php foreach ($db_fields as $f): ?><option value="<?= htmlspecialchars($f['field_name']) ?>"><?= htmlspecialchars($f['field_name']) ?></option><?php endforeach; ?></select></div>
                        </div>
                        <div id="dr_existing_box" style="display:none"><select class="form-select" name="dr_existing_test_id"><option value="">-- Select a test --</option><?php foreach ($db_tests as $t): ?><option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['test_name']) ?> (<?= htmlspecialchars($t['class']) ?> - <?= htmlspecialchars($t['stream']) ?>)</option><?php endforeach; ?></select><div class="form-text">Questions that already exist in that test are skipped.</div></div>
                    </div>
                    <div class="mb-3"><label class="form-label fw-semibold">Who can take these MCQs? (Gender)</label><select class="form-select" name="dr_gender" required><option value="All" selected>All Students</option><option value="Male">Male Only (Boys)</option><option value="Female">Female Only (Girls)</option></select></div>
                    <button class="btn btn-success w-100 fw-bold" name="save_mcq_draft_btn">Save Draft MCQs</button>
                </form>
            </div>
            <script>
            function drToggle(){var ex=document.getElementById('dr_mode_existing').checked;document.getElementById('dr_new_box').style.display=ex?'none':'';document.getElementById('dr_existing_box').style.display=ex?'':'none';}
            function packMcqDraft(f){
                var ex=document.getElementById('dr_mode_existing').checked;
                if(ex&&!f.dr_existing_test_id.value){alert('Select an existing test first.');return false;}
                if(!ex&&(!f.dr_new_test_name.value.trim()||!f.dr_class.value||!f.dr_stream.value)){alert('Enter the new test name and choose class and stream.');return false;}
                var out=[];f.querySelectorAll('.draft-row').forEach(function(r){out.push({keep:r.querySelector('.dk').checked,q:r.querySelector('.dq').value,a:r.querySelector('.da').value,b:r.querySelector('.db').value,c:r.querySelector('.dc').value,d:r.querySelector('.dd').value,k:r.querySelector('.dkey').value});});
                f.draft_json.value=JSON.stringify(out);return true;
            }
            </script>
            <?php endif; ?>
            <div class="card card-custom p-4">
                <h4 class="fw-bold mb-1">Add MCQs</h4>
                <p class="small text-muted">Three ways in, one place out: write a question yourself, upload an MCQ file, or let AI generate from study material. Upload and AI results land in one shared draft above, where you review and save once.</p>
                <ul class="nav nav-pills nav-fill mb-3" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link <?= $activeTab==='type'?'active':'' ?>" data-bs-toggle="pill" data-bs-target="#mcqTabType"><i class="fa-solid fa-pen me-1"></i>Type / Write</button></li>
                    <li class="nav-item"><button type="button" class="nav-link <?= $activeTab==='upload'?'active':'' ?>" data-bs-toggle="pill" data-bs-target="#mcqTabUpload"><i class="fa-solid fa-file-arrow-up me-1"></i>Upload</button></li>
                    <li class="nav-item"><button type="button" class="nav-link <?= $activeTab==='ai'?'active':'' ?>" data-bs-toggle="pill" data-bs-target="#mcqTabAi"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>AI Generate</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade <?= $activeTab==='type'?'show active':'' ?>" id="mcqTabType">
                        <p class="small text-muted">Write one question at a time and add it straight to a test.</p>
                        <form action="index.php?action=admin_mcqs" method="POST" enctype="multipart/form-data" id="addQuestionForm" onsubmit="return validateQuestionTestName()">
                    
                    <!-- TEST SELECTION (New or Old) -->
                    <div class="mb-3 p-3 bg-light rounded border">
                        <label class="form-label fw-bold text-primary">Where do you want to add this question?</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="target_mode" id="mode_existing" value="existing" <?= (($mcqDraft['target_mode'] ?? 'existing') === 'existing') ? 'checked' : '' ?> onchange="toggleTestMode()">
                            <label class="form-check-label fw-semibold" for="mode_existing">
                                Add to an Existing Named Test
                            </label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="target_mode" id="mode_new" value="new" <?= (($mcqDraft['target_mode'] ?? '') === 'new') ? 'checked' : '' ?> onchange="toggleTestMode()">
                            <label class="form-check-label fw-semibold" for="mode_new">
                                Create a New Named Test
                            </label>
                        </div>

                        <!-- Existing Test Select -->
                        <div id="existing_test_container" class="mb-2">
                            <label class="form-label small">Select Existing Test Name</label>
                            <select name="existing_test_id" id="existing_test_id" class="form-select form-select-sm">
                                <?php if (empty($db_tests)): ?>
                                    <option value="">No custom tests created yet. Choose 'Create New'.</option>
                                <?php else: ?>
                                    <option value="">-- Select a test name (required) --</option>
                                    <?php foreach ($db_tests as $t): ?>
                                        <option value="<?= $t['id'] ?>" <?= (string)($mcqDraft['existing_test_id'] ?? '') === (string)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['test_name']) ?> (<?= htmlspecialchars($t['class']) ?> - <?= htmlspecialchars($t['stream']) ?>)</option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- New Test Input -->
                        <div id="new_test_container" class="mb-2" style="display: none;">
                            <label class="form-label small">New Test Name</label>
                            <input type="text" name="new_test_name" id="new_test_name" class="form-control form-control-sm" placeholder="e.g. Midterm Physics Assessment">
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Class</label>
                            <select name="q_class" class="form-select" required>
                                <?php foreach ($db_classes as $c): ?>
                                    <option value="<?= htmlspecialchars($c['class_name']) ?>" <?= ($mcqDraft['q_class'] ?? '') === $c['class_name'] ? 'selected' : '' ?>><?= htmlspecialchars($c['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Target Field/Stream</label>
                            <select name="q_stream" class="form-select" required>
                                <?php foreach ($db_fields as $f): ?>
                                    <option value="<?= htmlspecialchars($f['field_name']) ?>" <?= ($mcqDraft['q_stream'] ?? '') === $f['field_name'] ? 'selected' : '' ?>><?= htmlspecialchars($f['field_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Subject</label>
                            <select name="q_subject" class="form-select" required>
                                <?php if (empty($db_subjects)): ?>
                                    <option value="" disabled selected>No subjects found. Add them!</option>
                                <?php else: ?>
                                    <option value="" disabled selected>Select Subject</option>
                                    <?php foreach ($db_subjects as $s): ?>
                                        <option value="<?= htmlspecialchars($s['subject_name']) ?>" <?= ($mcqDraft['q_subject'] ?? '') === $s['subject_name'] ? 'selected' : '' ?>><?= htmlspecialchars($s['subject_name']) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Target Audience</label>
                            <select name="q_gender_target" class="form-select" required>
                                <option value="All" <?= ($mcqDraft['q_gender_target'] ?? 'All') === 'All' ? 'selected' : '' ?>>All Students</option>
                                <option value="Female" <?= ($mcqDraft['q_gender_target'] ?? '') === 'Female' ? 'selected' : '' ?>>Girls Only Test</option>
                                <option value="Male" <?= ($mcqDraft['q_gender_target'] ?? '') === 'Male' ? 'selected' : '' ?>>Boys Only Test</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Question Text</label>
                        <textarea name="q_text" class="form-control" rows="3" placeholder="Enter question..." required></textarea>
                    </div>
                    <div class="mb-3"><label class="form-label">Question picture (optional)</label><input type="file" name="question_image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif"><div class="form-text">Add a diagram, graph, or picture (JPG/PNG/WEBP/GIF, max 4 MB).</div></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><input type="text" name="opt_a" class="form-control" placeholder="Option A" required></div>
                        <div class="col-6"><input type="text" name="opt_b" class="form-control" placeholder="Option B" required></div>
                        <div class="col-6"><input type="text" name="opt_c" class="form-control" placeholder="Option C" required></div>
                        <div class="col-6"><input type="text" name="opt_d" class="form-control" placeholder="Option D" required></div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Correct Option</label>
                        <select name="correct_opt" class="form-select" required>
                            <option value="A" <?= ($mcqDraft['correct_opt'] ?? 'A') === 'A' ? 'selected' : '' ?>>Option A</option>
                            <option value="B" <?= ($mcqDraft['correct_opt'] ?? '') === 'B' ? 'selected' : '' ?>>Option B</option>
                            <option value="C" <?= ($mcqDraft['correct_opt'] ?? '') === 'C' ? 'selected' : '' ?>>Option C</option>
                            <option value="D" <?= ($mcqDraft['correct_opt'] ?? '') === 'D' ? 'selected' : '' ?>>Option D</option>
                        </select>
                    </div>
                    <button type="submit" name="add_mcq_btn" class="btn btn-success w-100 fw-bold">Add Question to Bank</button>
                </form>
                    </div>
                    <div class="tab-pane fade <?= $activeTab==='upload'?'show active':'' ?>" id="mcqTabUpload">
                        <p class="small text-muted">Upload or paste ready-made MCQs in common text/document formats. AI attempts to normalize unfamiliar layouts into the portal MCQ format. The answer key must be inside the same file/text as the questions (an ANSWER KEY section, "Answer: C" lines, or a correct answer in the JSON). Any layout works: AI separates the questions, options and answers by itself. Questions go to the draft for review.</p>
                        <form method="POST" enctype="multipart/form-data" action="index.php?action=admin_mcqs">
                    <label class="form-label">Upload MCQ file with keys (TXT, MD, CSV, TSV, DOCX, PDF or JSON)</label><input type="file" name="bulk_file" id="bulk_file" class="form-control mb-2" accept=".txt,.md,.csv,.tsv,.docx,.pdf,.json,application/pdf,application/json,text/plain,text/csv"><div class="form-text mb-2">JSON can be a test exported from this portal or any list of questions with a correct answer. For PDF, text is extracted in your browser (scanned/image PDFs may not work).</div>
                    <label class="form-label">Or paste MCQs together with their answer key</label><textarea class="form-control mb-3" name="bulk_questions" id="bulk_questions" rows="8" placeholder="Q1. What is 2+2?&#10;A) 3&#10;B) 4&#10;C) 5&#10;D) 6&#10;Answer: B"></textarea>
                    <button class="btn btn-primary w-100" name="bulk_mcq_btn">Read Questions into Draft</button>
                </form>
                    </div>
                    <div class="tab-pane fade <?= $activeTab==='ai'?'show active':'' ?>" id="mcqTabAi">
                        <p class="small text-muted">Upload or paste study material. AI detects the subject and writes the number of MCQs you ask for. If AI is busy it retries and switches model automatically.</p>
                        <form method="POST" enctype="multipart/form-data" action="index.php?action=admin_mcqs">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><label class="form-label">Select Subject / MCQ Format</label><select class="form-select" name="ai_subject" required><option value="Auto" selected>Auto Detect from Material</option><option value="Mathematics">Mathematics — equations & calculations</option><option value="Physics">Physics — laws, formulas & numericals</option><option value="Chemistry">Chemistry — formulas & reactions</option><option value="Biology">Biology — cells, systems & processes</option><option value="Computer Science">Computer Science — coding, logic & algorithms</option><option value="English">English — grammar & vocabulary</option></select></div>
                        <div class="col-md-6"><label class="form-label">How many MCQs?</label><input type="number" class="form-control" name="ai_mcq_count" min="1" max="100" value="20" required></div>
                        <div class="col-md-6"><label class="form-label">MCQ difficulty</label><select class="form-select" name="ai_difficulty" required><option value="easy">1. Easy MCQs</option><option value="medium" selected>2. Medium MCQs</option><option value="hard">3. Hard MCQs</option></select></div>
                    </div>
                    <label class="form-label">Upload study material or an MCQ file (.txt/.md/.csv/.tsv/.json/.docx/.pdf)</label><input type="file" id="ai_material_file" name="ai_material_file" class="form-control mb-2" accept=".txt,.md,.csv,.tsv,.json,.docx,.pdf,text/plain,text/markdown,text/csv,application/json,application/pdf">
                    <label class="form-label">Or paste study material</label><textarea class="form-control mb-3" name="ai_material" rows="6" placeholder="Paste textbook/lesson material here (minimum 100 characters)"></textarea>
<small class="text-muted">Shortcuts: start your prompt with <code>/biology</code> or <code>/computer</code> to select the subject automatically.</small>
                    <button class="btn btn-info w-100" name="ai_generate_mcqs_btn">Generate MCQs into Draft</button>
                </form>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <script type="module">
    async function extractPdfText(file) {
        const pdfjs = await import('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.min.mjs');
        pdfjs.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.worker.min.mjs';
        const pdf = await pdfjs.getDocument({data: await file.arrayBuffer()}).promise; let pages=[];
        for(let p=1;p<=pdf.numPages;p++){const page=await pdf.getPage(p);const content=await page.getTextContent();const lines={};for(const item of content.items){if(!item.str||!item.str.trim())continue;const y=Math.round(item.transform[5]/3)*3;(lines[y]??=[]).push(item.str.trim());}pages.push(Object.keys(lines).map(Number).sort((a,b)=>b-a).map(y=>lines[y].join(' ')).join('\n'));}
        return pages.join('\n');
    }
    function parsePdfMcqs(text){const lines=text.split(/\r?\n/).map(x=>x.trim()).filter(Boolean),qs=[],keys={};let current=null,keySection=false;for(const line of lines){if(/^(answer\s*key|answers|key)\b/i.test(line)){keySection=true;current=null;continue;}if(keySection){const m=line.match(/^\s*(\d+)\s*[.)\-:]?\s*([A-D])\s*$/i);if(m)keys[+m[1]]=m[2].toUpperCase();continue;}const qm=line.match(/^\s*(\d+)\s*[.)]\s*(.+)$/);if(qm){current={n:+qm[1],q:qm[2],opts:{}};qs.push(current);continue;}const om=line.match(/^\s*([A-D])\s*[).:\-]\s*(.+)$/i);if(om&&current){current.opts[om[1].toUpperCase()]=om[2];continue;}if(current){const opts=Object.keys(current.opts).sort();if(opts.length)current.opts[opts[opts.length-1]]+=' '+line;else current.q+=' '+line;}}
        const qr=[],kr=[];let invalid=0;for(const q of qs){if(q.opts.A&&q.opts.B&&q.opts.C&&q.opts.D){qr.push([q.q,q.opts.A,q.opts.B,q.opts.C,q.opts.D].join(' | '));if(keys[q.n])kr.push(q.n+' '+keys[q.n]);}else invalid++;}return {qr,kr,found:qs.length,invalid};}
    const bulkPdf=document.getElementById('bulk_file');if(bulkPdf)bulkPdf.addEventListener('change',async function(){if(!this.files[0]||!/\.pdf$/i.test(this.files[0].name))return;try{const txt=await extractPdfText(this.files[0]);document.getElementById('bulk_questions').value=txt;alert('PDF text extracted (questions and keys together). Click "Read Questions into Draft" to continue.');}catch(e){alert('Could not extract PDF. It may be scanned or PDF.js could not load.');}this.value='';});
    const aiFile=document.getElementById('ai_material_file');if(aiFile)aiFile.addEventListener('change',async function(){const f=this.files[0];if(!f)return;try{if(f.name.toLowerCase().endsWith('.pdf')){document.querySelector('textarea[name="ai_material"]').value=await extractPdfText(f);this.value='';alert('PDF text extracted. The AI will detect the subject when you click Detect Subject & Generate MCQs.');}else if(/\.(txt|md|csv)$/i.test(f.name)){document.querySelector('textarea[name="ai_material"]').value=await f.text();this.value='';}}catch(e){alert('Could not extract this file. Paste the text manually.');}});
    </script>
    <script>
    function confirmQuestionRemoval(form) {
    const confirmed = window.confirm('Do you want to remove this question?\n\nClick OK to remove it or Cancel to keep it.');
    return confirmed;
}

    function validateQuestionTestName() {
    const existingMode = document.getElementById('mode_existing').checked;
    const existingSelect = document.getElementById('existing_test_id');
    const newName = document.getElementById('new_test_name');
    if (existingMode && (!existingSelect || !existingSelect.value)) {
        alert('Please select a test name before adding this question.');
        if (existingSelect) existingSelect.focus();
        return false;
    }
    if (!existingMode && (!newName || !newName.value.trim())) {
        alert('Please enter a test name before adding this question.');
        if (newName) newName.focus();
        return false;
    }
    return true;
}

function toggleTestMode() {
        const isNew = document.getElementById('mode_new').checked;
        document.getElementById('existing_test_container').style.display = isNew ? 'none' : 'block';
        document.getElementById('new_test_container').style.display = isNew ? 'block' : 'none';
    }
    document.addEventListener('DOMContentLoaded', toggleTestMode);
    </script>

    <?php
    // EXISTING QUESTION BANK & TESTS (full screen)
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_qbank'):
        $db_classes = $pdo->query("SELECT * FROM classes ORDER BY id ASC")->fetchAll();
        $db_fields = $pdo->query("SELECT * FROM fields ORDER BY field_name ASC")->fetchAll();
        $editingQuestion = null; if (!empty($_GET['edit_q'])) { $eq=$pdo->prepare('SELECT * FROM questions WHERE id=?'); $eq->execute([(int)$_GET['edit_q']]); $editingQuestion=$eq->fetch() ?: null; }
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <a href="index.php?action=admin_dashboard" class="btn btn-outline-secondary fw-semibold"><i class="fa-solid fa-arrow-left me-1"></i> Back</a>
        <h2 class="fw-bold text-dark mb-0 flex-grow-1 text-center"><i class="fa-solid fa-database text-primary me-2"></i>Existing Question Bank &amp; Tests</h2>
        <a href="index.php?action=admin_mcqs" class="btn btn-success fw-semibold"><i class="fa-solid fa-file-circle-plus me-1"></i>Add Questions</a>
    </div>
    <?php if ($editingQuestion): ?>
    <div class="card card-custom p-4 mb-4 border-primary"><h4>Edit MCQ #<?= (int)$editingQuestion['id'] ?> (saved results will be rechecked)</h4>
      <form method="POST" enctype="multipart/form-data" action="index.php?action=admin_qbank" class="row g-2"><input type="hidden" name="question_id" value="<?= (int)$editingQuestion['id'] ?>">
      <div class="col-12"><label class="form-label">Question</label><textarea class="form-control" name="q_text" required><?= htmlspecialchars($editingQuestion['question_text']) ?></textarea></div>
      <?php if (!empty($editingQuestion['image_path']) && is_file(__DIR__ . '/' . $editingQuestion['image_path'])): ?><div class="col-12"><p class="small mb-1">Current picture</p><img src="<?= htmlspecialchars($editingQuestion['image_path']) ?>" alt="Current question picture" style="max-width:180px;max-height:120px" class="rounded border"></div><?php endif; ?>
      <div class="col-12"><label class="form-label">Add/replace question picture (optional)</label><input type="file" name="question_image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif"></div>
      <?php foreach (['a'=>'option_a','b'=>'option_b','c'=>'option_c','d'=>'option_d'] as $letter=>$col): ?><div class="col-md-6"><label class="form-label">Option <?= strtoupper($letter) ?></label><input class="form-control" name="opt_<?= $letter ?>" value="<?= htmlspecialchars($editingQuestion[$col]) ?>" required></div><?php endforeach; ?>
      <div class="col-md-6"><label class="form-label">Subject</label><input class="form-control" name="q_subject" value="<?= htmlspecialchars($editingQuestion['subject']) ?>" required></div>
      <div class="col-md-6"><label class="form-label">Correct option</label><select class="form-select" name="correct_opt"><?php foreach(['A','B','C','D'] as $op): ?><option <?= $editingQuestion['correct_option']===$op?'selected':'' ?>><?= $op ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><button class="btn btn-primary" name="save_question_edit_btn">Save and Recheck Results</button> <a class="btn btn-outline-secondary" href="index.php?action=admin_qbank">Cancel</a></div></form></div>
    <?php endif; ?>
    <div class="row g-4">
        <div class="col-12">
            <div class="card card-custom p-4">
                <h4 class="fw-bold mb-3">Existing Question Bank & Tests</h4>
                <div>
                    <?php
                    $testsStmt = $pdo->query("SELECT tm.*, COUNT(q.id) AS question_count FROM tests_meta tm LEFT JOIN questions q ON q.test_id = tm.id GROUP BY tm.id ORDER BY tm.test_name ASC");
                    $namedTests = $testsStmt->fetchAll();
                    // Live tests first, then the Restricted area (admin only).
                    usort($namedTests, function ($a, $b) { return [empty($a['is_live']) ? 1 : 0, $a['test_name']] <=> [empty($b['is_live']) ? 1 : 0, $b['test_name']]; });
                    $__lastGrp = ''; $__qbModals = '';
                    $__nLive = 0; foreach ($namedTests as $__x) { if (!empty($__x['is_live'])) $__nLive++; }
                    $__nRestricted = count($namedTests) - $__nLive;
                    if (!$namedTests): ?>
                        <p class="text-muted">No named tests found yet.</p>
                    <?php else: foreach ($namedTests as $test):
                        $testId = (int)$test['id'];
                        $isLive = !empty($test['is_live']);
                        $__grp = $isLive ? 'live' : 'restricted';
                        if ($__grp !== $__lastGrp) {
                            $__lastGrp = $__grp;
                            echo $isLive
                                ? '<h5 class="fw-bold text-success mb-2"><i class="fa-solid fa-tower-broadcast me-2"></i>Live Tests <span class="badge bg-success">' . $__nLive . '</span></h5><p class="small text-muted mb-2">Students of the matching class, field and gender can see and take these.</p>'
                                : '<h5 class="fw-bold text-danger mt-4 mb-2"><i class="fa-solid fa-lock me-2"></i>Restricted Area (admin only) <span class="badge bg-danger">' . $__nRestricted . '</span></h5><p class="small text-muted mb-2">Students cannot see these. Use <strong>Make Live</strong> to publish one. Live tests move here automatically when their expiry date passes.</p>';
                        }
                        $tExpTs = !empty($test['test_expiry_date']) ? strtotime($test['test_expiry_date']) : null;
                        $tExpired = $tExpTs && time() > $tExpTs;
                        $tDur = !empty($test['time_limit_minutes']) ? (int)$test['time_limit_minutes'] : 90;
                        $subjectsStmt = $pdo->prepare("SELECT DISTINCT subject FROM questions WHERE test_id = ? ORDER BY subject ASC");
                        $subjectsStmt->execute([$testId]);
                        $testSubjects = $subjectsStmt->fetchAll(PDO::FETCH_COLUMN);
                    ?>
                        <details class="border rounded-3 mb-3 p-3">
                            <summary class="fw-bold fs-5" style="cursor:pointer;">
                                <?= htmlspecialchars($test['test_name']) ?>
                                <span class="badge bg-dark ms-2"><?= (int)$test['question_count'] ?> questions</span>
                                <span class="badge <?= $isLive ? 'bg-success' : 'bg-danger' ?> ms-1"><i class="fa-solid <?= $isLive ? 'fa-tower-broadcast' : 'fa-lock' ?> me-1"></i><?= $isLive ? 'LIVE' : 'RESTRICTED' ?></span>
                                <?php if ($tExpired): ?><span class="badge bg-warning text-dark ms-1">Expired<?= $isLive ? '' : ' – auto-restricted' ?></span><?php endif; ?>
                                <small class="text-muted d-block fw-normal mt-1"><?= htmlspecialchars($test['class']) ?> · <?= htmlspecialchars($test['stream']) ?> · <?= $test['gender_target'] === 'All' ? 'All students' : ($test['gender_target'] === 'Male' ? 'Boys only' : 'Girls only') ?> · <?= $tDur ?> min · Pass <?= rtrim(rtrim(number_format((float)($test['passing_percentage'] ?? 50), 2, '.', ''), '0'), '.') ?>% · <?= $tExpTs ? 'Expires ' . date('d M Y, h:i A', $tExpTs) : 'No expiry' ?></small>
                            </summary>
                            <div class="mt-3 ms-2">
                                <div class="mb-3 d-flex flex-wrap gap-2 align-items-center"><form action="index.php?action=admin_qbank" method="POST" class="d-inline"><input type="hidden" name="test_id" value="<?= $testId ?>"><input type="hidden" name="make_live" value="<?= $isLive ? 0 : 1 ?>"><button type="submit" name="toggle_test_live_btn" class="btn btn-sm <?= $isLive ? 'btn-success' : 'btn-outline-success' ?>"><i class="fa-solid fa-tower-broadcast me-1"></i><?= $isLive ? 'Live – move to Restricted' : 'Make Live' ?></button></form> <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editTest<?= $testId ?>"><i class="fa-solid fa-pen me-1"></i>Edit</button> <a class="btn btn-sm btn-outline-primary" href="index.php?action=export_test_json&test_id=<?= $testId ?>">Export JSON</a> <a class="btn btn-sm btn-outline-primary" href="index.php?action=export_test_txt&test_id=<?= $testId ?>">Export TXT</a> <a class="btn btn-sm btn-outline-primary" href="index.php?action=export_test_docx&test_id=<?= $testId ?>">Export DOCX</a> <a class="btn btn-sm btn-outline-secondary" href="index.php?action=export_test_pdf&test_id=<?= $testId ?>" target="_blank">Export PDF</a> <form action="index.php?action=admin_qbank" method="POST" class="d-inline" onsubmit="return confirm('Delete the test &quot;<?= htmlspecialchars(addslashes($test['test_name']), ENT_QUOTES) ?>&quot; with ALL its questions and student results? This cannot be undone.');"><input type="hidden" name="test_id" value="<?= $testId ?>"><button type="submit" name="delete_test_btn" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash me-1"></i>Delete Test</button></form></div>
                                <?php ob_start(); ?>
<div class="modal fade" id="editTest<?= $testId ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title fw-bold"><i class="fa-solid fa-pen me-2"></i>Edit Test – <?= htmlspecialchars($test['test_name']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body">
<form action="index.php?action=admin_qbank" method="POST">
                                    <input type="hidden" name="test_id" value="<?= $testId ?>">
                                                                        <div class="row g-2">
                                        <div class="col-md-6"><label class="form-label small mb-1">Test name</label><input type="text" name="t_name" class="form-control form-control-sm" value="<?= htmlspecialchars($test['test_name']) ?>" required></div>
                                        <div class="col-md-6"><label class="form-label small mb-1">Class</label>
                                            <select name="t_class" class="form-select form-select-sm" required>
                                                <?php $clsOpts = array_column($db_classes, 'class_name'); if (!in_array($test['class'], $clsOpts, true)) $clsOpts[] = $test['class']; foreach ($clsOpts as $co): ?>
                                                    <option value="<?= htmlspecialchars($co) ?>" <?= $test['class'] === $co ? 'selected' : '' ?>><?= htmlspecialchars($co) ?></option>
                                                <?php endforeach; ?>
                                            </select></div>
                                        <div class="col-md-6"><label class="form-label small mb-1">Stream / Field</label>
                                            <select name="t_stream" class="form-select form-select-sm" required>
                                                <?php $strOpts = array_column($db_fields, 'field_name'); if (!in_array($test['stream'], $strOpts, true)) $strOpts[] = $test['stream']; foreach ($strOpts as $so): ?>
                                                    <option value="<?= htmlspecialchars($so) ?>" <?= $test['stream'] === $so ? 'selected' : '' ?>><?= htmlspecialchars($so) ?></option>
                                                <?php endforeach; ?>
                                            </select></div>
                                        <div class="col-md-6"><label class="form-label small mb-1">Gender</label>
                                            <select name="t_gender" class="form-select form-select-sm">
                                                <option value="All" <?= $test['gender_target'] === 'All' ? 'selected' : '' ?>>All Students</option>
                                                <option value="Male" <?= $test['gender_target'] === 'Male' ? 'selected' : '' ?>>Boys Only</option>
                                                <option value="Female" <?= $test['gender_target'] === 'Female' ? 'selected' : '' ?>>Girls Only</option>
                                            </select></div>
                                        <div class="col-md-6"><label class="form-label small mb-1">Duration (minutes)</label><input type="number" name="test_time_limit_minutes" class="form-control form-control-sm" min="1" max="600" value="<?= $tDur ?>" placeholder="Default 90"></div>
                                        <div class="col-md-6"><label class="form-label small mb-1">Expiry date &amp; time</label><input type="datetime-local" name="test_expiry_date" class="form-control form-control-sm" value="<?= $tExpTs ? date('Y-m-d\TH:i', $tExpTs) : '' ?>"><small class="text-muted">Empty = no expiry. After this time the test moves to the Restricted area automatically.</small></div>
                                        <div class="col-md-6"><label class="form-label small mb-1">Passing marks (%)</label><input type="number" name="passing_percentage" class="form-control form-control-sm" min="0" max="100" step="0.01" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float)($test['passing_percentage'] ?? 50), 2, '.', ''), '0'), '.')) ?>" placeholder="Default 50"><small class="text-muted">Students scoring this percentage or more pass. Default is 50%.</small></div>
                                    </div>
                                    <button type="submit" name="update_test_settings_btn" class="btn btn-primary btn-sm fw-bold mt-3"><i class="fa-solid fa-floppy-disk me-1"></i>Save Test Settings</button>
                                    <small class="text-muted ms-2">Class, stream and gender are applied to all questions of this test.</small>
                                </form>
</div></div></div></div>
<?php $__qbModals .= ob_get_clean(); ?>
                                <?php if (!$testSubjects): ?>
                                    <p class="text-muted small mb-0">No subject questions have been added to this test yet.</p>
                                <?php else: foreach ($testSubjects as $subjectName):
                                    $questionsStmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ? AND subject = ? ORDER BY id ASC");
                                    $questionsStmt->execute([$testId, $subjectName]);
                                    $subjectQuestions = $questionsStmt->fetchAll();
                                ?>
                                    <details class="border rounded-2 mb-2 p-2">
                                        <summary style="cursor:pointer;">
                                            <span class="badge bg-info text-dark"><?= htmlspecialchars($subjectName) ?></span>
                                            <span class="text-muted small ms-1"><?= count($subjectQuestions) ?> questions</span>
                                        </summary>
                                        <div class="table-responsive mt-2">
                                            <table class="table table-sm table-hover align-middle mb-0">
                                                <thead class="table-light"><tr><th>Question</th><th>Target</th><th>Correct</th><th>Action</th></tr></thead>
                                                <tbody>
                                                <?php foreach ($subjectQuestions as $q): ?>
                                                    <tr>
                                                        <td><?= math_text_html($q['question_text']) ?><?php if (!empty($q['image_path']) && is_file(__DIR__ . '/' . $q['image_path'])): ?><br><img src="<?= htmlspecialchars($q['image_path']) ?>" alt="Question picture" style="max-width:110px;max-height:75px" class="mt-1 rounded"><?php endif; ?></td>
                                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($q['gender_target']) ?></span></td>
                                                        <td><span class="badge bg-success"><?= htmlspecialchars($q['correct_option']) ?></span></td>
                                                        <td>
                                                            <a href="index.php?action=admin_qbank&edit_q=<?= (int)$q['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                            <form action="index.php?action=admin_qbank" method="POST" class="d-inline" onsubmit="return confirmQuestionRemoval(this);">
                                                                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                                                                <button type="submit" name="delete_question_btn" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash me-1"></i>Remove</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </details>
                                <?php endforeach; endif; ?>
                            </div>
                        </details>
                    <?php endforeach; endif; ?>
                    <?php if ($namedTests && $__nRestricted === 0): ?>
                        <h5 class="fw-bold text-danger mt-4 mb-2"><i class="fa-solid fa-lock me-2"></i>Restricted Area (admin only) <span class="badge bg-danger">0</span></h5>
                        <p class="small text-muted">No restricted tests. New tests start here until you make them Live.</p>
                    <?php endif; ?>
                    <?= $__qbModals ?>
                    <?php
                    $generalStmt = $pdo->query("SELECT * FROM questions WHERE test_id IS NULL ORDER BY id ASC");
                    $generalQuestions = $generalStmt->fetchAll();
                    if ($generalQuestions): ?>
                        <details class="border rounded-3 mb-3 p-3">
                            <summary class="fw-bold fs-5" style="cursor:pointer;">General Bank <span class="badge bg-dark ms-2"><?= count($generalQuestions) ?> questions</span></summary>
                            <div class="table-responsive mt-3"><table class="table table-sm table-hover align-middle"><thead class="table-light"><tr><th>Class/Stream</th><th>Subject</th><th>Question</th><th>Correct</th><th>Action</th></tr></thead><tbody>
                            <?php foreach ($generalQuestions as $q): ?><tr>
                                <td><?= htmlspecialchars($q['class']) ?> / <?= htmlspecialchars($q['stream']) ?></td><td><?= htmlspecialchars($q['subject']) ?></td><td><?= htmlspecialchars($q['question_text']) ?></td><td><span class="badge bg-success"><?= htmlspecialchars($q['correct_option']) ?></span></td>
                                <td><a href="index.php?action=admin_qbank&edit_q=<?= (int)$q['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a> <form action="index.php?action=admin_qbank" method="POST" class="d-inline" onsubmit="return confirmQuestionRemoval(this);"><input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>"><button type="submit" name="delete_question_btn" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash me-1"></i>Remove</button></form></td>
                            </tr><?php endforeach; ?>
                            </tbody></table></div>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
    function confirmQuestionRemoval(form) {
        return window.confirm('Do you want to remove this question?\n\nClick OK to remove it or Cancel to keep it.');
    }
    </script>

    <?php
    // STUDENT DASHBOARD
    elseif ($_SESSION['role'] === 'student' && ($action === 'student_dashboard' || $action === 'home')):
        $student_id = $_SESSION['user_id'];
        $class = $_SESSION['class'];
        $stream = $_SESSION['stream'];
        $gender = $_SESSION['gender'];

        // Fetch custom named tests available for student's class and stream
        $stmtTests = $pdo->prepare("SELECT * FROM tests_meta WHERE class = ? AND stream = ? AND is_live = 1 AND (test_expiry_date IS NULL OR test_expiry_date > ?) AND gender_target IN ('All', ?) ORDER BY id DESC");
        $stmtTests->execute([$class, $stream, date('Y-m-d H:i:s'), $gender]);
        $available_tests = $stmtTests->fetchAll();
    ?>
    <div class="mb-4">
        <h2 class="fw-bold text-dark">Welcome, <?= htmlspecialchars($_SESSION['name']) ?>!</h2>
        <p class="text-muted">Enrolled in <strong>Class <?= htmlspecialchars($class) ?></strong> (<?= htmlspecialchars($stream) ?>) | Gender: <strong><?= htmlspecialchars($gender) ?></strong></p>
    </div>


    <?php
        $stProf = $pdo->prepare("SELECT * FROM students WHERE id = ?"); $stProf->execute([$student_id]);
        $my_prof = $stProf->fetch() ?: [];
        $prof_has = false;
        foreach (student_extra_fields() as $pc => $pd) { if (!empty($my_prof[$pc])) { $prof_has = true; break; } }
    ?>
    <div class="card card-custom p-4 mb-4">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-id-card text-primary me-2"></i>My Profile</h5>
        <?php if (!$prof_has): ?>
            <div class="text-muted small">Your extra details (phone, father name, address...) have not been added yet. Please ask the admin to add them.</div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach (student_extra_fields() as $pc => $pd): if (empty($my_prof[$pc])) continue; $pv = $my_prof[$pc]; if ($pd[1] === 'date') { $pv = date('d M Y', strtotime($pv)); } ?>
                <div class="<?= $pd[1] === 'textarea' ? 'col-12' : 'col-md-6 col-lg-4' ?>">
                    <div class="small text-muted"><i class="fa-solid <?= $pd[2] ?> me-1"></i><?= htmlspecialchars($pd[0]) ?></div>
                    <div class="fw-semibold"><?= nl2br(htmlspecialchars((string)$pv)) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php
        $dash_notices = $pdo->query("SELECT * FROM announcements ORDER BY id DESC LIMIT 3")->fetchAll();
        $stF = $pdo->prepare("SELECT monthly_fee FROM students WHERE id = ?"); $stF->execute([$student_id]);
        $dash_fee = (float)$stF->fetchColumn();
        $stP = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fees WHERE student_id = ? AND fee_month = ?"); $stP->execute([$student_id, date('Y-m')]);
        $dash_paid = (float)$stP->fetchColumn();
        $dash_free = false; $pm = [$student_id => $dash_paid]; $fm = [];
        $stG = $pdo->prepare("SELECT given FROM fee_given WHERE student_id = ? AND fee_month = ?"); $stG->execute([$student_id, date('Y-m')]);
        $gv = $stG->fetchColumn();
        if ($gv !== false) { if (strcasecmp(trim($gv),'free')===0) { $dash_free = true; $dash_paid = 0; } elseif (is_numeric($gv)) { $dash_paid = (float)$gv; } }
    ?>
    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card card-custom p-3 h-100">
                <div class="text-muted small">Fee — <?= date('F Y') ?></div>
                <div class="fs-4 fw-bold <?= ($dash_free || ($dash_fee > 0 && $dash_paid >= $dash_fee)) ? 'text-success' : 'text-danger' ?>">
                    <?= $dash_free ? 'Free' : (($dash_fee > 0 && $dash_paid >= $dash_fee) ? 'Paid' : ($dash_paid > 0 ? 'Partially Paid' : ($dash_fee > 0 ? 'Unpaid' : 'Not set'))) ?>
                </div>
                <div class="small text-muted mb-2"><?= $dash_free ? 'No fee this month' : 'Paid ' . money($dash_paid) . ($dash_fee > 0 ? ' of ' . money($dash_fee) : '') ?></div>
                <a href="index.php?action=student_fees" class="btn btn-sm btn-outline-success mt-auto">View fee history</a>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card card-custom p-3 h-100">
                <h6 class="fw-bold"><i class="fa-solid fa-bullhorn text-warning me-2"></i>Notices</h6>
                <?php if (!$dash_notices): ?><div class="text-muted small">No notices right now.</div><?php endif; ?>
                <?php foreach ($dash_notices as $n): ?>
                    <div class="border-top pt-2 mt-2"><strong><?= htmlspecialchars($n['title']) ?></strong> <span class="small text-muted">· <?= date('d M', strtotime($n['created_at'])) ?></span><div class="small"><?= nl2br(htmlspecialchars($n['body'])) ?></div></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <h4 class="fw-bold mb-3"><i class="fa-solid fa-pen-to-square text-primary me-2"></i>Available Named Tests</h4>
    <div class="row g-4 mb-5">
        <?php if (empty($available_tests)): ?>
            <div class="col-12">
                <div class="alert alert-info">No specific named tests are currently published for your class and stream. Check back later or contact admin.</div>
            </div>
        <?php else: ?>
            <?php foreach ($available_tests as $t): 
                $t_id = $t['id'];
                $test_time_limit = !empty($t['time_limit_minutes']) ? (int)$t['time_limit_minutes'] : 90;
                $expiry_date = $t['test_expiry_date'] ?: null;
                $is_expired = !empty($expiry_date) && time() > strtotime($expiry_date);
                // Check if student has attempted this specific test
                $stmtAtt = $pdo->prepare("SELECT COUNT(*) FROM student_tests WHERE student_id = ? AND test_id = ?");
                $stmtAtt->execute([$student_id, $t_id]);
                $has_attempted_test = $stmtAtt->fetchColumn() > 0;

                // Count questions for this test targetting student's gender
                $stmtQCount = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE test_id = ? AND (gender_target = 'All' OR gender_target = ?)");
                $stmtQCount->execute([$t_id, $gender]);
                $test_q_count = $stmtQCount->fetchColumn();
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card card-custom p-4 text-center h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="bg-light text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 50px; height: 50px;">
                            <i class="fa-solid fa-file-lines fa-lg"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-1"><?= htmlspecialchars($t['test_name']) ?></h4>
                        <p class="text-muted small mb-2">Class <?= htmlspecialchars($t['class']) ?> - <?= htmlspecialchars($t['stream']) ?></p>
                        <p class="fw-semibold text-primary mb-3"><?= $test_q_count ?> Question(s)</p>
                        
                        <?php if ($expiry_date): ?>
                            <div class="mb-3">
                                <small class="badge <?= $is_expired ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                    <i class="fa-solid fa-clock me-1"></i> 
                                    <?= $is_expired ? 'Expired On:' : 'Expires On:' ?> <?= date('d M Y, h:i A', strtotime($expiry_date)) ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if ($is_expired): ?>
                            <button class="btn btn-danger w-100 fw-bold py-2" disabled>
                                <i class="fa-solid fa-lock me-1"></i> Test Expired
                            </button>
                        <?php elseif ($has_attempted_test): ?>
                            <div class="alert alert-warning py-1 mb-2 small">
                                <i class="fa-solid fa-circle-check me-1"></i> Completed
                            </div>
                            <button class="btn btn-secondary w-100 fw-bold py-2" disabled>
                                <i class="fa-solid fa-ban me-1"></i> Already Submitted
                            </button>
                        <?php elseif ($test_q_count > 0): ?>
                            <a href="index.php?action=start_test&test_id=<?= $t_id ?>" class="btn btn-primary w-100 fw-bold py-2" onclick="return confirm('TEST RULES\n\n1. Stay on this test tab.\n2. Switching tabs/apps or hiding this page may submit the test automatically.\n3. Do not use chat or other apps.\n4. Screenshots cannot be reliably blocked by a website; do not attempt to capture test content.\n5. Your answers are submitted when you press Submit or time expires.\n\nClick OK to start the test.')">
                                <i class="fa-solid fa-play me-1"></i> Start Test
                            </a>
                        <?php else: ?>
                            <button class="btn btn-secondary w-100 py-2" disabled>No Questions Available</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <h4 class="fw-bold mb-3"><i class="fa-solid fa-clock-rotate-left text-success me-2"></i>Your Test History</h4>
    <div class="card card-custom p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Test Title</th>
                        <th>Score</th>
                        <th>Percentage</th>
                        <th>Time Taken</th>
                        <th>Date Taken</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmt = $pdo->prepare("SELECT st.*, tm.test_name, COALESCE(tm.passing_percentage, 50) AS pass_mark FROM student_tests st LEFT JOIN tests_meta tm ON st.test_id = tm.id WHERE st.student_id = ? ORDER BY st.test_date DESC");
                    $stmt->execute([$_SESSION['user_id']]);
                    if ($stmt->rowCount() == 0):
                    ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">You have not attempted any tests yet.</td></tr>
                    <?php else: ?>
                        <?php while ($t = $stmt->fetch()): ?>
                        <tr>
                            <td>
                                <?php if (!empty($t['test_name'])): ?>
                                    <strong class="text-dark"><?= htmlspecialchars($t['test_name']) ?></strong><br>
                                <?php endif; ?>
                                <span class="badge bg-primary"><?= htmlspecialchars($t['subject']) ?></span>
                            </td>
                            <td><?= $t['correct_answers'] ?> / <?= $t['total_questions'] ?></td>
                            <td><span class="fw-bold <?= $t['score_percentage'] >= (float)($t['pass_mark'] ?? 50) ? 'text-success' : 'text-danger' ?>"><?= $t['score_percentage'] ?>%</span><br><span class="badge <?= $t['score_percentage'] >= (float)($t['pass_mark'] ?? 50) ? 'bg-success' : 'bg-danger' ?>"><?= $t['score_percentage'] >= (float)($t['pass_mark'] ?? 50) ? 'PASS' : 'FAIL' ?></span></td>
                            <td><?= floor($t['time_taken_seconds'] / 60) ?>m <?= $t['time_taken_seconds'] % 60 ?>s</td>
                            <td><?= date('d M Y, h:i A', strtotime($t['test_date'])) ?></td>
                            <td>
                                <a href="index.php?action=view_result&test_id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary fw-semibold">
                                    <i class="fa-solid fa-eye me-1"></i> Review Test
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    // START TEST VIEW
    elseif ($_SESSION['role'] === 'student' && $action === 'start_test'):
        $student_id = $_SESSION['user_id'];
        $class = $_SESSION['class'];
        $stream = $_SESSION['stream'];
        $gender = $_SESSION['gender'];
        $test_id = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;

        $stmtAttempt = $pdo->prepare("SELECT COUNT(*) FROM student_tests WHERE student_id = ? AND test_id = ?");
        $stmtAttempt->execute([$student_id, $test_id]);
        if ($stmtAttempt->fetchColumn() > 0) {
            redirect("index.php?action=student_dashboard&err=You have already taken this test!");
        }

        $expiry_date = null;
        $test_time_limit = 90;

        $test_title_name = "Grand Assessment Test";
        if ($test_id > 0) {
            $tQuery = $pdo->prepare("SELECT * FROM tests_meta WHERE id = ? AND class = ? AND stream = ? AND is_live = 1 AND (test_expiry_date IS NULL OR test_expiry_date > ?) AND gender_target IN ('All', ?)");
            $tQuery->execute([$test_id, $class, $stream, date('Y-m-d H:i:s'), $gender]);
            $tRow = $tQuery->fetch();
            if (!$tRow) {
                redirect("index.php?action=student_dashboard&err=This test is not live or is not available for you.");
            }
            $test_title_name = $tRow['test_name'];
            $expiry_date = $tRow['test_expiry_date'] ?: null;
            $test_time_limit = !empty($tRow['time_limit_minutes']) ? (int)$tRow['time_limit_minutes'] : 90;
            if (!empty($expiry_date) && time() > strtotime($expiry_date)) {
                redirect("index.php?action=student_dashboard&err=This test has expired.");
            }
            $stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ? AND (gender_target = 'All' OR gender_target = ?) ORDER BY subject ASC, id ASC");
            $stmt->execute([$test_id, $gender]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM questions WHERE class = ? AND stream = ? AND (test_id IS NULL) AND (gender_target = 'All' OR gender_target = ?) ORDER BY subject ASC, id ASC");
            $stmt->execute([$class, $stream, $gender]);
        }
        $questions = $stmt->fetchAll();

        if (empty($questions)) {
            redirect("index.php?action=student_dashboard&err=No test questions found for this test.");
        }

        $time_limit_minutes = $test_time_limit;
        $time_limit_seconds = $time_limit_minutes * 60;
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card card-custom p-4 mb-4 sticky-top bg-white border-bottom shadow-sm" style="top: 15px; z-index: 100;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-file-pen text-primary me-2"></i><?= htmlspecialchars($test_title_name) ?></h4>
                        <small class="text-muted">Class: <?= htmlspecialchars($class) ?> | Stream: <?= htmlspecialchars($stream) ?></small>
                    </div>
                    <div class="text-end">
                        <div class="timer-badge" id="timerDisplay">--:--</div>
                    </div>
                </div>
            </div>

            <form action="index.php" method="POST" id="testForm">
                <input type="hidden" name="submit_test_btn" value="1">
                <input type="hidden" name="test_id" value="<?= $test_id ?>">
                <input type="hidden" name="test_subject" value="<?= htmlspecialchars($test_title_name) ?>">
                <input type="hidden" name="time_taken_seconds" id="time_taken_seconds" value="0">

                <?php 
                $q_no = 1;
                foreach ($questions as $q): 
                ?>
                <div class="card card-custom p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h5 class="fw-bold text-dark">Question <?= $q_no++ ?> of <?= count($questions) ?></h5>
                        <span class="badge bg-secondary"><?= htmlspecialchars($q['subject']) ?></span>
                    </div>
                    <p class="fs-5 mb-3 text-dark"><?= math_text_html($q['question_text']) ?></p>
                    <?php if (!empty($q['image_path']) && is_file(__DIR__ . '/' . $q['image_path'])): ?><div class="mb-3"><img src="<?= htmlspecialchars($q['image_path']) ?>" alt="Question illustration" class="img-fluid rounded border" style="max-height:420px"></div><?php endif; ?>

                    <div class="options-group">
                        <?php foreach (['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']] as $opt_key => $opt_val): ?>
                            <input type="radio" class="btn-check mcq-option-input" name="answer[<?= $q['id'] ?>]" id="q_<?= $q['id'] ?>_<?= $opt_key ?>" value="<?= $opt_key ?>">
                            <label class="mcq-option-label" for="q_<?= $q['id'] ?>_<?= $opt_key ?>">
                                <strong><?= $opt_key ?>.</strong> <?= math_text_html($opt_val) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="card card-custom p-4 text-center mb-5">
                    <button type="submit" class="btn btn-success btn-lg fw-bold py-3" onclick="return confirm('Are you sure you want to submit your final answers?')">
                        <i class="fa-solid fa-paper-plane me-2"></i> Submit Test Now
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    let timeLeft = <?= $time_limit_seconds ?>;
    let timeTaken = 0;
    const timerDisplay = document.getElementById('timerDisplay');
    const timeTakenInput = document.getElementById('time_taken_seconds');
    let securitySubmitting = false;
    function securityAutoSubmit(reason) {
        if (securitySubmitting) return;
        securitySubmitting = true;
        alert(reason + '\nYour test is being submitted now.');
        const form = document.getElementById('testForm');
        if (form) form.submit();
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') securityAutoSubmit('Tab/app switch detected.');
    });
    window.addEventListener('blur', function () {
        // Best-effort only: browser blur can also occur for benign system dialogs.
        setTimeout(function () { if (document.visibilityState === 'hidden') securityAutoSubmit('Leaving the test tab detected.'); }, 250);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'PrintScreen') {
            e.preventDefault();
            alert('Not Allowed');
            // Websites cannot guarantee prevention of operating-system screenshots.
        }
        if ((e.ctrlKey || e.metaKey) && ['c','x','p','s'].includes((e.key || '').toLowerCase())) {
            e.preventDefault(); alert('Not Allowed');
        }
    });

    const countdown = setInterval(function() {
        if (timeLeft <= 0) {
            clearInterval(countdown);
            alert("Time is up! Your test will now be submitted automatically.");
            timeTakenInput.value = <?= $time_limit_seconds ?>;
            document.getElementById('testForm').submit();
        } else {
            timeLeft--;
            timeTaken++;
            timeTakenInput.value = timeTaken;

            let minutes = Math.floor(timeLeft / 60);
            let seconds = timeLeft % 60;
            timerDisplay.textContent = (minutes < 10 ? "0" : "") + minutes + ":" + (seconds < 10 ? "0" : "") + seconds;
        }
    }, 1000);
    </script>

    <?php
    // MODULES HUB
    elseif ($action === 'modules' && isset($_SESSION['role'])):
        $hub_admin = $_SESSION['role'] === 'admin';
        $hub_total = 0;
        foreach ($MODULES as $mm) { if ($hub_admin || $mm[3] !== 'none') $hub_total++; }
        $ADMIN_PANEL = [
            ['All Students', 'fa-user-graduate', 'admin_students'],
            ['Student Results', 'fa-square-poll-vertical', 'admin_student_results'],
            ['Fees', 'fa-coins', 'admin_fees'],
            ['Expenses', 'fa-receipt', 'admin_expenses'],
            ['Teachers', 'fa-chalkboard-user', 'admin_teachers'],
            ['Notices', 'fa-bullhorn', 'admin_notices'],
            ['Site Contact Info', 'fa-address-book', 'admin_site_info'],
            ['Student Logins', 'fa-key', 'admin_student_logins'],
        ];
        if ($hub_admin) { $hub_total += count($ADMIN_PANEL); }
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h2 class="fw-bold mb-0"><i class="fa-solid fa-grip text-primary me-2"></i><?= $hub_admin ? 'All Modules' : 'More for Students' ?></h2>
            <div class="text-muted"><?= $hub_total ?> modules available</div>
        </div>
        <input type="search" id="hubSearch" class="form-control" style="max-width:320px;" placeholder="Search modules...">
    </div>
    <?php if ($hub_admin): ?>
    <div class="hub-group mb-4">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-user-shield text-warning me-2"></i>Admin Panel <span class="badge bg-light text-dark"><?= count($ADMIN_PANEL) ?></span></h5>
        <div class="row g-3">
            <?php foreach ($ADMIN_PANEL as $ap): ?>
            <div class="col-6 col-md-4 col-lg-3 hub-item" data-name="<?= htmlspecialchars(strtolower($ap[0])) ?>">
                <a href="index.php?action=<?= $ap[2] ?>" class="text-decoration-none">
                    <div class="card card-custom p-3 h-100 text-center feature-card" style="padding:18px 12px;">
                        <div class="feature-icon mx-auto"><i class="fa-solid <?= $ap[1] ?>"></i></div>
                        <div class="fw-semibold text-dark"><?= htmlspecialchars($ap[0]) ?></div>
                        <div class="small text-muted">Admin only</div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php foreach ($MODULE_GROUPS as $gname => $gicon):
        $items = array_filter($MODULES, function ($mm) use ($gname, $hub_admin) { return $mm[2] === $gname && ($hub_admin || $mm[3] !== 'none'); });
        if (!$items) continue; ?>
    <div class="hub-group mb-4">
        <h5 class="fw-bold mb-3"><i class="fa-solid <?= $gicon ?> text-warning me-2"></i><?= htmlspecialchars($gname) ?> <span class="badge bg-light text-dark"><?= count($items) ?></span></h5>
        <div class="row g-3">
            <?php foreach ($items as $k => $mm): ?>
            <div class="col-6 col-md-4 col-lg-3 hub-item" data-name="<?= htmlspecialchars(strtolower($mm[0])) ?>">
                <a href="index.php?action=mod&m=<?= urlencode($k) ?>" class="text-decoration-none">
                    <div class="card card-custom p-3 h-100 text-center feature-card" style="padding:18px 12px;">
                        <div class="feature-icon mx-auto"><i class="fa-solid <?= $mm[1] ?>"></i></div>
                        <div class="fw-semibold text-dark"><?= htmlspecialchars($mm[0]) ?></div>
                        <?php if ($hub_admin): ?><div class="small text-muted"><?= $mm[3] === 'none' ? 'Admin only' : 'Students: ' . ($mm[3] === 'own' ? 'own records' : 'view all') ?></div><?php endif; ?>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <script>
    document.getElementById('hubSearch').addEventListener('input', function () {
        var q = this.value.toLowerCase().trim();
        document.querySelectorAll('.hub-item').forEach(function (el) { el.style.display = el.dataset.name.indexOf(q) > -1 ? '' : 'none'; });
        document.querySelectorAll('.hub-group').forEach(function (g) { g.style.display = g.querySelector('.hub-item:not([style*="none"])') ? '' : 'none'; });
    });
    </script>

    <?php
    elseif ($_SESSION['role'] === 'admin' && $action === 'combine_tests'):
        $combineTests=$pdo->query('SELECT id,test_name,class,stream FROM tests_meta ORDER BY test_name')->fetchAll();
    ?>
    <div class="card card-custom p-4 mb-4"><h2>Combine Tests</h2><p>Write the test name, then select one or more source tests. Their questions will be moved into that test. If no test has that name, a new one is created from the first selected test's class, stream and gender (it starts in the Restricted area). Source test records remain in place.</p><form method="POST"><label class="form-label">Test name</label><input type="text" class="form-control mb-3" name="combine_test_name" maxlength="150" placeholder="Write the test name" required><label class="form-label">Source tests to combine</label><div class="border rounded p-3 mb-3"><?php foreach($combineTests as $t): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="source_test_ids[]" value="<?= (int)$t['id'] ?>" id="src_test_<?= (int)$t['id'] ?>"><label class="form-check-label" for="src_test_<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['test_name']) ?> (<?= htmlspecialchars($t['class'].' / '.$t['stream']) ?>)</label></div><?php endforeach; ?></div><button class="btn btn-primary" name="combine_tests_btn" onclick="return confirm('Move questions from selected source tests to the destination test?');">Combine and Save</button></form></div>
    <?php
    // Test report: class + gender + test roster includes students who never attempted the test.
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_test_reports'):
        $classesReport=$pdo->query('SELECT class_name FROM classes ORDER BY class_name')->fetchAll(PDO::FETCH_COLUMN);
        $testsReport=$pdo->query('SELECT id,test_name,class,gender_target FROM tests_meta ORDER BY test_name')->fetchAll();
        $rmode=(($_GET['mode']??'2')==='1')?'1':'2'; // 2 = class + gender + test, 1 = test only
        $rc=trim((string)($_GET['class']??'')); $rg=trim((string)($_GET['gender']??'')); $rt=(int)($_GET['test_id']??0);
        $reportRows=[]; $reportLabel='';
        if($rmode==='1' && $rt>0){
            // Test only: students of the test's own class, and the test's gender setting (All = boys and girls).
            $tm=$pdo->prepare('SELECT class,gender_target FROM tests_meta WHERE id=?'); $tm->execute([$rt]); $tmr=$tm->fetch();
            if($tmr){ $rc=(string)$tmr['class']; $rg=in_array($tmr['gender_target'],['Male','Female'],true)?$tmr['gender_target']:'Both'; }
        }
        if($rc!=='' && in_array($rg,['Male','Female','Both'],true) && $rt>0){
            $gSql=($rg==='Both')?"s.gender IN ('Male','Female')":"s.gender=?";
            $rq=$pdo->prepare("SELECT s.id,s.full_name,s.class,s.gender,st.id AS attempt_id,st.correct_answers,st.total_questions,st.score_percentage,st.test_date FROM students s LEFT JOIN student_tests st ON st.student_id=s.id AND st.test_id=? WHERE s.class=? AND $gSql ORDER BY s.gender,s.full_name");
            $rq->execute($rg==='Both'?[$rt,$rc]:[$rt,$rc,$rg]); $reportRows=$rq->fetchAll();
            $reportLabel=$rc.' · '.($rg==='Both'?'Male & Female':$rg);
        }
    ?>
    <div class="card card-custom p-4 mb-4"><h2>Test Results by Class and Gender</h2><form class="row g-3" method="GET" id="reportForm"><input type="hidden" name="action" value="admin_test_reports"><div class="col-12"><label class="form-label">Choose how many filters</label><select name="mode" id="rMode" class="form-select" onchange="reportMode()"><option value="2" <?= $rmode==='2'?'selected':'' ?>>2 – Class and Gender (then pick the test)</option><option value="1" <?= $rmode==='1'?'selected':'' ?>>1 – Test only</option></select></div><div class="col-md-4 r-cg"><label class="form-label">Class</label><select name="class" class="form-select" required><option value="">Choose class</option><?php foreach($classesReport as $x): ?><option <?= ($rmode==='2' && $rc===$x)?'selected':'' ?>><?= htmlspecialchars($x) ?></option><?php endforeach; ?></select></div><div class="col-md-4 r-cg"><label class="form-label">Gender</label><select name="gender" class="form-select" required><option value="">Choose gender</option><option <?= ($rmode==='2' && $rg==='Male')?'selected':'' ?>>Male</option><option <?= ($rmode==='2' && $rg==='Female')?'selected':'' ?>>Female</option><option value="Both" <?= ($rmode==='2' && $rg==='Both')?'selected':'' ?>>Both (Male &amp; Female students)</option></select></div><div class="col-md-4" id="rTestCol"><label class="form-label">Test</label><select name="test_id" class="form-select" required><option value="">Choose test</option><?php foreach($testsReport as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $rt===(int)$x['id']?'selected':'' ?>><?= htmlspecialchars($x['test_name']) ?> (<?= htmlspecialchars($x['class']) ?>)</option><?php endforeach; ?></select></div><div class="col-12"><button class="btn btn-primary">Show Results</button> <button class="btn btn-outline-secondary" type="button" onclick="window.print()">Print / Save as PDF</button></div></form><script>function reportMode(){var one=document.getElementById('rMode').value==='1';document.querySelectorAll('#reportForm .r-cg').forEach(function(c){c.style.display=one?'none':'';c.querySelectorAll('select').forEach(function(x){x.disabled=one;x.required=!one;});});document.getElementById('rTestCol').className=one?'col-12':'col-md-4';}reportMode();</script></div>
    <?php if($reportRows): ?><div class="card card-custom p-4"><h4>Report: <?= htmlspecialchars($reportLabel) ?></h4><table class="table table-bordered"><thead><tr><th>Student</th><th>Class</th><th>Gender</th><th>Result</th><th>Score</th><th>Date</th></tr></thead><tbody><?php foreach($reportRows as $r): ?><tr><td><?= htmlspecialchars($r['full_name']) ?></td><td><?= htmlspecialchars($r['class']) ?></td><td><?= htmlspecialchars($r['gender']) ?></td><td><?= $r['attempt_id']?'Completed':'Never Did' ?></td><td><?= $r['attempt_id']?((int)$r['correct_answers'].' / '.(int)$r['total_questions'].' ('.htmlspecialchars((string)$r['score_percentage']).'%)'):'—' ?></td><td><?= $r['test_date']?htmlspecialchars($r['test_date']):'—' ?></td></tr><?php endforeach; ?></tbody></table></div><?php elseif($rc && $rg && $rt): ?><div class="alert alert-info">No students matched these filters.</div><?php endif; ?>
    <?php
    elseif ($_SESSION['role'] === 'admin' && $action === 'backup'):
    ?>
    <div class="card card-custom p-4 mb-4"><h2>Teacher/Admin Backup</h2><p>Download a JSON backup of supported application tables. Keep it private because it contains student and plain-text password data.</p><a class="btn btn-success mb-4" href="index.php?action=backup_download">Download Full Backup (.json)</a><h4>Upload Backup</h4><form method="POST" enctype="multipart/form-data"><input type="file" name="backup_file" class="form-control mb-3" accept=".json,application/json" required><label class="form-label">Import mode</label><select class="form-select mb-3" name="backup_mode"><option value="merge">Merge with existing data (keep existing rows)</option><option value="replace">Replace existing data (DANGER: clears supported tables first)</option></select><button class="btn btn-primary" name="backup_upload_btn" onclick="return confirm('Import this backup? Replace mode clears supported tables first.');">Upload and Import Backup</button></form></div>
    <?php
    elseif ($_SESSION['role'] === 'admin' && $action === 'export_test_pdf'):
        $tid=(int)($_GET['test_id']??0); $t=$pdo->prepare('SELECT * FROM tests_meta WHERE id=?'); $t->execute([$tid]); $test=$t->fetch();  $q=$pdo->prepare('SELECT * FROM questions WHERE test_id=? ORDER BY id'); $q->execute([$tid]); $pdfQs=$q->fetchAll();
    ?>
    <div class="card card-custom p-4"><button class="btn btn-primary mb-3" onclick="window.print()">Print / Save as PDF</button><h1><?= htmlspecialchars($test['test_name']??'Test not found') ?></h1><?php foreach($pdfQs as $i=>$q): ?><div class="mb-3"><strong><?= ($i+1).'. '.htmlspecialchars($q['question_text']) ?></strong><ol type="A"><li><?= htmlspecialchars($q['option_a']) ?></li><li><?= htmlspecialchars($q['option_b']) ?></li><li><?= htmlspecialchars($q['option_c']) ?></li><li><?= htmlspecialchars($q['option_d']) ?></li></ol></div><?php endforeach; ?><hr><h3>Answer Key</h3><p><?php foreach($pdfQs as $i=>$q) echo ($i+1).'. '.htmlspecialchars($q['correct_option']).' &nbsp; '; ?></p></div>
    <?php
    // MODULE PAGE (generic records manager)
    elseif ($action === 'mod' && isset($_SESSION['role']) && isset($MODULES[$mod_key])):
        $M = $MODULES[$mod_key];
        $is_admin = $_SESSION['role'] === 'admin';
        $mod_blocked = !$is_admin && $M[3] === 'none';
        if (!$mod_blocked) {
            mod_ensure($pdo, $mod_key);
            $mf = mod_fields($M[4]);
            $tbl = mod_table($mod_key);
            $smap = $pdo->query("SELECT id, full_name FROM students ORDER BY full_name")->fetchAll(PDO::FETCH_KEY_PAIR);
            $cls_list = $pdo->query("SELECT class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_COLUMN); natsort($cls_list); $cls_list = array_values($cls_list);
            $own_col = null;
            foreach ($mf as $f) { if ($f['t'] === 'student') { $own_col = $f['col']; break; } }
            if ($is_admin) {
                $mod_rows = $pdo->query("SELECT * FROM `$tbl` ORDER BY id DESC LIMIT 1000")->fetchAll();
            } elseif ($own_col || in_array($mod_key, ['homework','timetable','notes'], true)) {
                $mod_rows = mod_student_rows($pdo, $mod_key);
            } else {
                $mod_rows = $pdo->query("SELECT * FROM `$tbl` ORDER BY id DESC LIMIT 1000")->fetchAll();
            }
            $edit_row = null;
            if ($is_admin && isset($_GET['edit'])) {
                $stmt = $pdo->prepare("SELECT * FROM `$tbl` WHERE id = ?");
                $stmt->execute([(int)$_GET['edit']]);
                $edit_row = $stmt->fetch() ?: null;
            }
            // ---- Attendance only: admin picks a student + month and sees that month ----
            $att_on = ($mod_key === 'attendance');
            $att_self = ($att_on && !$is_admin); // a student sees only their own attendance, one month at a time
            $att = null; $att_sid = 0; $att_month = date('Y-m'); $att_students = [];
            if ($att_on) {
                if ($att_self) {
                    $att_sid = (int)($_SESSION['user_id'] ?? 0);
                    $as_q = $pdo->prepare("SELECT id, full_name, class, stream FROM students WHERE id = ?"); $as_q->execute([$att_sid]);
                    $att_students = $as_q->fetchAll();
                } else {
                    $att_students = $pdo->query("SELECT id, full_name, class, stream FROM students ORDER BY full_name")->fetchAll();
                    $att_sid = (int)($_GET['att_student'] ?? 0);
                }
                if (isset($_GET['att_month']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$_GET['att_month'])) { $att_month = $_GET['att_month']; }
                $att_stu = null;
                foreach ($att_students as $__as) { if ((int)$__as['id'] === $att_sid) { $att_stu = $__as; break; } }
                if ($att_stu) {
                    $att_first = $att_month . '-01'; $att_last = date('Y-m-t', strtotime($att_first));
                    $aq = $pdo->prepare("SELECT * FROM `$tbl` WHERE c1 = ? AND c2 BETWEEN ? AND ? ORDER BY c2 ASC, id ASC");
                    $aq->execute([$att_sid, $att_first, $att_last]);
                    $att_days = [];
                    foreach ($aq->fetchAll() as $__ar) { $att_days[(string)$__ar['c2']] = $__ar; } // if a day was saved twice, the latest record counts
                    $att_counts = ['Present' => 0, 'Absent' => 0, 'Late' => 0, 'Leave' => 0];
                    foreach ($att_days as $__ar) { if (isset($att_counts[$__ar['c3']])) $att_counts[$__ar['c3']]++; }
                    $att_marked = array_sum($att_counts);
                    $att_pct = $att_marked > 0 ? (int)round(($att_counts['Present'] + $att_counts['Late']) * 100 / $att_marked) : null;
                    $att = ['stu' => $att_stu, 'first' => $att_first, 'days' => $att_days, 'counts' => $att_counts, 'marked' => $att_marked, 'pct' => $att_pct,
                            'dim' => (int)date('t', strtotime($att_first)), 'dow' => (int)date('N', strtotime($att_first)),
                            'prev' => date('Y-m', strtotime($att_first . ' -1 month')), 'next' => date('Y-m', strtotime($att_first . ' +1 month'))];
                }
            }
        }
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <h2 class="fw-bold mb-0"><i class="fa-solid <?= $M[1] ?> text-primary me-2"></i><?= htmlspecialchars($M[0]) ?></h2>
        <div class="d-flex gap-2">
            <a href="index.php?action=modules" class="btn btn-outline-secondary"><i class="fa-solid fa-grip me-1"></i>All Modules</a>
            <?php if ($is_admin && !$mod_blocked): ?>
                <a href="index.php?action=mod_export&m=<?= urlencode($mod_key) ?>" class="btn btn-outline-success"><i class="fa-solid fa-file-csv me-1"></i>Export CSV</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($mod_blocked): ?>
        <div class="alert alert-warning">This section is not available for students.</div>
    <?php else: ?>

    <?php if (!empty($att_on)):
        $attMax = date('Y-m');
        $attColor = ['Present' => 'success', 'Absent' => 'danger', 'Late' => 'warning', 'Leave' => 'info'];
        $attShort = ['Present' => 'P', 'Absent' => 'A', 'Late' => 'L', 'Leave' => 'Lv'];
        $attBase = 'index.php?action=mod&m=attendance' . ($att_self ? '' : '&att_student=' . (int)$att_sid);
    ?>
    <div class="card card-custom p-4 mb-4">
        <h5 class="fw-bold mb-1"><i class="fa-solid fa-calendar-days text-primary me-2"></i><?= $att_self ? 'My Attendance' : 'Student Monthly Attendance' ?></h5>
        <p class="small text-muted"><?= $att_self ? 'This month is shown first. To see an older month, pick that month below.' : 'Choose a student and a month to see that month\'s attendance day by day.' ?></p>
        <form method="GET" action="index.php" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="mod"><input type="hidden" name="m" value="attendance">
            <?php if (!$att_self): ?>
            <div class="col-md-6"><label class="form-label small mb-1">Student</label>
                <select name="att_student" class="form-select" required>
                    <option value="">-- Select student --</option>
                    <?php foreach ($att_students as $__as): ?><option value="<?= (int)$__as['id'] ?>" <?= (int)$__as['id'] === $att_sid ? 'selected' : '' ?>><?= htmlspecialchars($__as['full_name']) ?> (<?= htmlspecialchars($__as['class']) ?> &ndash; <?= htmlspecialchars($__as['stream']) ?>)</option><?php endforeach; ?>
                </select></div>
            <?php endif; ?>
            <div class="<?= $att_self ? 'col-md-6' : 'col-md-3' ?>"><label class="form-label small mb-1">Month</label><input type="month" name="att_month" class="form-control" value="<?= htmlspecialchars($att_month) ?>" <?= $att_self ? 'max="' . $attMax . '" onchange="this.form.submit()"' : '' ?> required></div>
            <div class="<?= $att_self ? 'col-md-6' : 'col-md-3' ?>"><button class="btn btn-primary w-100 fw-bold"><i class="fa-solid fa-magnifying-glass me-1"></i>Show Attendance</button></div>
        </form>
        <?php if ($att_sid > 0 && !$att): ?>
            <div class="alert alert-warning mt-3 mb-0">Student not found.</div>
        <?php elseif ($att): ?>
            <hr>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div><div class="fw-bold fs-5"><?= htmlspecialchars($att['stu']['full_name']) ?></div><div class="small text-muted"><?= htmlspecialchars($att['stu']['class']) ?> &ndash; <?= htmlspecialchars($att['stu']['stream']) ?></div></div>
                <div class="btn-group">
                    <a class="btn btn-outline-secondary" href="<?= $attBase ?>&att_month=<?= $att['prev'] ?>" title="Previous month"><i class="fa-solid fa-chevron-left"></i></a>
                    <span class="btn btn-light fw-bold disabled" style="opacity:1;min-width:150px"><?= htmlspecialchars(month_label($att_month)) ?></span>
                    <?php if (!$att_self || $att['next'] <= $attMax): ?><a class="btn btn-outline-secondary" href="<?= $attBase ?>&att_month=<?= $att['next'] ?>" title="Next month"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
                </div>
            </div>
            <div class="row g-2 mb-3">
                <?php foreach ($att['counts'] as $__st => $__n): ?>
                <div class="col-6 col-md"><div class="border rounded p-2 text-center"><div class="small text-muted"><?= $__st ?></div><div class="fs-4 fw-bold text-<?= $attColor[$__st] === 'warning' ? 'warning' : $attColor[$__st] ?>"><?= (int)$__n ?></div></div></div>
                <?php endforeach; ?>
                <div class="col-6 col-md"><div class="border rounded p-2 text-center"><div class="small text-muted">Days marked</div><div class="fs-4 fw-bold"><?= (int)$att['marked'] ?></div></div></div>
                <div class="col-12 col-md"><div class="border rounded p-2 text-center bg-light"><div class="small text-muted">Attendance</div><div class="fs-4 fw-bold <?= $att['pct'] === null ? 'text-muted' : ($att['pct'] >= 75 ? 'text-success' : 'text-danger') ?>"><?= $att['pct'] === null ? '—' : $att['pct'] . '%' ?></div></div></div>
            </div>
            <div class="small text-muted mb-2">Attendance % = (Present + Late) &divide; days marked.</div>
            <?php if ($att['marked'] === 0): ?><div class="alert alert-info">No attendance was recorded <?= $att_self ? 'for you' : 'for this student' ?> in <?= htmlspecialchars(month_label($att_month)) ?>.<?= $att_self ? '' : ' You can add it with the form below.' ?></div><?php endif; ?>
            <div class="table-responsive mb-3">
                <table class="table table-bordered text-center align-middle mb-0" style="table-layout:fixed;min-width:560px">
                    <thead class="table-light"><tr><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $__dn): ?><th><?= $__dn ?></th><?php endforeach; ?></tr></thead>
                    <tbody><tr>
                    <?php
                        $__cell = 0;
                        for ($__e = 1; $__e < $att['dow']; $__e++) { echo '<td class="bg-light"></td>'; $__cell++; }
                        for ($__d = 1; $__d <= $att['dim']; $__d++) {
                            $__key = $att_month . '-' . str_pad((string)$__d, 2, '0', STR_PAD_LEFT);
                            $__r = $att['days'][$__key] ?? null; $__isToday = ($__key === date('Y-m-d'));
                            if ($__cell % 7 === 0 && $__cell > 0) echo '</tr><tr>';
                            echo '<td class="p-1' . ($__isToday ? ' border-primary border-2' : '') . '" style="height:62px">';
                            echo '<div class="small text-muted text-start">' . $__d . '</div>';
                            if ($__r) {
                                $__c = $attColor[$__r['c3']] ?? 'secondary';
                                echo '<span class="badge bg-' . $__c . ($__c === 'warning' ? ' text-dark' : '') . '" title="' . htmlspecialchars($__r['c3'] . (!empty($__r['c4']) ? ' - ' . $__r['c4'] : '')) . '">' . htmlspecialchars($attShort[$__r['c3']] ?? '?') . '</span>';
                            } else { echo '<span class="text-muted">&middot;</span>'; }
                            echo '</td>'; $__cell++;
                        }
                        while ($__cell % 7 !== 0) { echo '<td class="bg-light"></td>'; $__cell++; }
                    ?>
                    </tr></tbody>
                </table>
            </div>
            <div class="small mb-3"><span class="badge bg-success">P</span> Present &nbsp; <span class="badge bg-danger">A</span> Absent &nbsp; <span class="badge bg-warning text-dark">L</span> Late &nbsp; <span class="badge bg-info">Lv</span> Leave &nbsp; <span class="text-muted">&middot; not marked</span></div>
            <?php if ($att['days']): ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Date</th><th>Day</th><th>Status</th><th>Remarks</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($att['days'] as $__k => $__r): $__c = $attColor[$__r['c3']] ?? 'secondary'; ?>
                    <tr><td><?= date('d M Y', strtotime($__k)) ?></td><td><?= date('D', strtotime($__k)) ?></td>
                        <td><span class="badge bg-<?= $__c ?> <?= $__c === 'warning' ? 'text-dark' : '' ?>"><?= htmlspecialchars((string)$__r['c3']) ?></span></td>
                        <td class="text-muted small"><?= htmlspecialchars((string)($__r['c4'] ?? '')) ?></td>
                        <td class="text-end"><?php if (!$att_self): ?><a class="btn btn-sm btn-outline-primary" href="index.php?action=mod&m=attendance&edit=<?= (int)$__r['id'] ?>" title="Edit this day"><i class="fa-solid fa-pen"></i></a><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($is_admin): ?>
    <div class="card card-custom p-4 mb-4">
        <h5 class="fw-bold mb-3"><?= $edit_row ? 'Edit Record #' . (int)$edit_row['id'] : 'Add New Record' ?></h5>
        <form method="POST" action="index.php?action=mod&m=<?= urlencode($mod_key) ?>" class="row g-3">
            <input type="hidden" name="m" value="<?= htmlspecialchars($mod_key) ?>">
            <input type="hidden" name="row_id" value="<?= $edit_row ? (int)$edit_row['id'] : 0 ?>">
            <?php foreach ($mf as $f): if ($f['t'] === 'targetclass') continue; ?>
            <div class="<?= $f['t'] === 'textarea' ? 'col-md-12' : 'col-md-4' ?>">
                <label class="form-label"><?= htmlspecialchars($f['l']) ?></label>
                <?php mod_input($f, $edit_row[$f['col']] ?? '', $smap, $cls_list); ?>
            </div>
            <?php endforeach; ?>
            <div class="col-12">
                <button type="submit" name="mod_save_btn" class="btn btn-primary fw-bold"><i class="fa-solid fa-floppy-disk me-1"></i><?= $edit_row ? 'Update' : 'Save' ?></button>
                <?php if ($edit_row): ?><a href="index.php?action=mod&m=<?= urlencode($mod_key) ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if (empty($att_self)): ?>
    <div class="card card-custom p-4">
        <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
            <h5 class="fw-bold mb-0">Records <span class="badge bg-light text-dark"><?= count($mod_rows) ?></span></h5>
            <input type="search" id="rowSearch" class="form-control" style="max-width:280px;" placeholder="Search records...">
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="modTable">
                <thead class="table-light"><tr><th>#</th><?php foreach ($mf as $f): if ($f['t'] === 'targetclass') continue; ?><th><?= htmlspecialchars($f['l']) ?></th><?php endforeach; ?><?php if ($is_admin): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (!$mod_rows): ?><tr><td colspan="<?= count($mf) + 1 ?>" class="text-center text-muted py-4">No records yet.</td></tr><?php endif; ?>
                <?php foreach ($mod_rows as $r): ?>
                <tr>
                    <td class="text-muted small"><?= (int)$r['id'] ?></td>
                    <?php foreach ($mf as $f): if ($f['t'] === 'targetclass') continue; ?><td><?= mod_cell($f, $r[$f['col']], $smap) ?></td><?php endforeach; ?>
                    <?php if ($is_admin): ?>
                    <td class="text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="index.php?action=mod&m=<?= urlencode($mod_key) ?>&edit=<?= (int)$r['id'] ?>"><i class="fa-solid fa-pen"></i></a>
                        <form method="POST" action="index.php?action=mod&m=<?= urlencode($mod_key) ?>" class="d-inline" onsubmit="return confirm('Delete this record?');">
                            <input type="hidden" name="m" value="<?= htmlspecialchars($mod_key) ?>">
                            <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
                            <button name="mod_delete_btn" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
    document.getElementById('rowSearch').addEventListener('input', function () {
        var q = this.value.toLowerCase();
        document.querySelectorAll('#modTable tbody tr').forEach(function (tr) { tr.style.display = tr.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none'; });
    });
    </script>
    <?php endif; ?>
    <?php endif; ?>

    <?php
    // ADMIN: FEES MANAGEMENT
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_fees'):
        $fee_month = (isset($_GET['fee_month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['fee_month'])) ? $_GET['fee_month'] : date('Y-m');
        // months for the month box: last 12 months + the chosen month + any month that already has fee data
        $fee_months = [];
        for ($i = 0; $i < 12; $i++) { $fee_months[date('Y-m', strtotime("first day of -$i month"))] = true; }
        $fee_months[$fee_month] = true;
        foreach ($pdo->query("SELECT DISTINCT fee_month FROM fees UNION SELECT DISTINCT fee_month FROM fee_given")->fetchAll(PDO::FETCH_COLUMN) as $fm) {
            if (preg_match('/^\d{4}-\d{2}$/', (string)$fm)) $fee_months[$fm] = true;
        }
        $fee_months = array_keys($fee_months); rsort($fee_months);
        $prev_month = date('Y-m', strtotime($fee_month . '-01 -1 month'));
        $next_month = date('Y-m', strtotime($fee_month . '-01 +1 month'));

        $all_students = $pdo->query("SELECT id, full_name, username, class, stream, monthly_fee, admission_date, created_at FROM students ORDER BY full_name")->fetchAll();
        // ---- "Student fee history": admin picks one student and sees every month ----
        $fv_sid = (int)($_GET['fee_student'] ?? 0); $fv = null;
        foreach ($all_students as $__s) {
            if ((int)$__s['id'] !== $fv_sid) continue;
            $fv_mf = (float)$__s['monthly_fee'];
            $q = $pdo->prepare("SELECT fee_month, SUM(amount) AS paid FROM fees WHERE student_id = ? GROUP BY fee_month"); $q->execute([$fv_sid]); $fv_paid = $q->fetchAll(PDO::FETCH_KEY_PAIR);
            $q = $pdo->prepare("SELECT fee_month, given FROM fee_given WHERE student_id = ?"); $q->execute([$fv_sid]); $fv_given = $q->fetchAll(PDO::FETCH_KEY_PAIR);
            $q = $pdo->prepare("SELECT * FROM fees WHERE student_id = ? ORDER BY paid_on ASC, id ASC"); $q->execute([$fv_sid]);
            $fv_pay = []; foreach ($q->fetchAll() as $__p) { $fv_pay[$__p['fee_month']][] = $__p; }
            $fv_src = !empty($__s['admission_date']) ? $__s['admission_date'] : $__s['created_at'];
            $fv_rows = []; $fv_tpaid = 0; $fv_tdue = 0; $fv_now = date('Y-m');
            foreach (fee_months_for($fv_src, array_merge(array_keys($fv_paid), array_keys($fv_given))) as $__m) {
                $__g = $fv_given[$__m] ?? null; $__free = $__g !== null && strcasecmp(trim($__g), 'free') === 0; $__man = !$__free && $__g !== null && is_numeric($__g);
                $__pd = $__free ? 0 : ($__man ? (float)$__g : (float)($fv_paid[$__m] ?? 0)); $__fut = $__m > $fv_now;
                $__du = ($__free || $__fut) ? 0 : max(0, $fv_mf - $__pd);
                if (!$__free) { $fv_tpaid += $__pd; $fv_tdue += $__du; }
                if ($__free) { $__bd = ['info', 'Free']; } elseif ($__fut) { $__bd = ['info', 'Advance']; } elseif ($fv_mf > 0 && $__pd >= $fv_mf) { $__bd = ['success', 'Paid']; } elseif ($__pd > 0) { $__bd = ['warning', 'Partial']; } else { $__bd = ['danger', $fv_mf > 0 ? 'Unpaid' : 'Fee not set']; }
                $fv_rows[] = ['m' => $__m, 'free' => $__free, 'man' => $__man, 'paid' => $__pd, 'due' => $__du, 'bd' => $__bd, 'pay' => $fv_pay[$__m] ?? []];
            }
            $fv = ['s' => $__s, 'mf' => $fv_mf, 'rows' => $fv_rows, 'tpaid' => $fv_tpaid, 'tdue' => $fv_tdue];
            break;
        }
        $stmt = $pdo->prepare("SELECT student_id, SUM(amount) AS paid FROM fees WHERE fee_month = ? GROUP BY student_id");
        $stmt->execute([$fee_month]);
        $paid_map = array_column($stmt->fetchAll(), 'paid', 'student_id');
        $free_map = [];
        $given_raw = fee_given_apply($pdo, $fee_month, $paid_map, $free_map);
        $total_month = array_sum($paid_map);
        $total_all = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM fees")->fetchColumn();
        // all-time total: where a manual total / Free was set for a month, it replaces that month's recorded payments
        foreach ($pdo->query("SELECT g.student_id, g.fee_month, g.given, (SELECT COALESCE(SUM(f.amount),0) FROM fees f WHERE f.student_id = g.student_id AND f.fee_month = g.fee_month) AS pay FROM fee_given g")->fetchAll() as $gr) {
            if (strcasecmp(trim($gr['given']), 'free') === 0) $total_all -= (float)$gr['pay'];
            elseif (is_numeric($gr['given'])) $total_all += (float)$gr['given'] - (float)$gr['pay'];
        }

        // Work out each student's row once, so the table and the counters always agree
        $fee_rows = []; $total_due = 0; $pending_count = 0;
        $fee_counts = ['all' => 0, 'unpaid' => 0, 'partial' => 0, 'paid' => 0, 'free' => 0, 'noset' => 0];
        foreach ($all_students as $s) {
            $paid = (float)($paid_map[$s['id']] ?? 0);
            $mf = (float)$s['monthly_fee'];
            $isFree = isset($free_map[$s['id']]);
            $manual = !$isFree && isset($given_raw[$s['id']]) && is_numeric($given_raw[$s['id']]);
            $due = $isFree ? 0 : max(0, $mf - $paid);
            if ($isFree) { $key = 'free'; $badge = ['info', 'Free']; }
            elseif ($mf <= 0 && $paid <= 0) { $key = 'noset'; $badge = ['secondary', 'Fee not set']; }
            elseif ($mf > 0 && $paid >= $mf) { $key = 'paid'; $badge = ['success', 'Paid']; }
            elseif ($paid > 0) { $key = 'partial'; $badge = ['warning', 'Partly paid']; }
            else { $key = 'unpaid'; $badge = ['danger', 'Not paid']; }
            if ($due > 0) { $pending_count++; $total_due += $due; }
            $fee_counts['all']++; $fee_counts[$key]++;
            $fee_rows[] = ['s' => $s, 'paid' => $paid, 'mf' => $mf, 'free' => $isFree, 'manual' => $manual, 'due' => $due, 'key' => $key, 'badge' => $badge];
        }

        $stmt = $pdo->prepare("SELECT f.*, s.full_name, s.username FROM fees f JOIN students s ON s.id = f.student_id WHERE f.fee_month = ? ORDER BY f.id DESC");
        $stmt->execute([$fee_month]);
        $month_payments = $stmt->fetchAll();
        $fee_filters = ['all' => 'Everyone', 'unpaid' => 'Not paid', 'partial' => 'Partly paid', 'paid' => 'Paid', 'free' => 'Free', 'noset' => 'Fee not set'];
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h2 class="fw-bold mb-0"><i class="fa-solid fa-wallet text-success me-2"></i>Fee Management</h2>
        <form method="GET" action="index.php" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="action" value="admin_fees">
            <?php if ($fv_sid > 0): ?><input type="hidden" name="fee_student" value="<?= $fv_sid ?>"><?php endif; ?>
            <a class="btn btn-outline-secondary" href="index.php?action=admin_fees&fee_month=<?= urlencode($prev_month) ?>" title="Previous month"><i class="fa-solid fa-chevron-left"></i></a>
            <select name="fee_month" class="form-select fw-semibold" onchange="this.form.submit()" aria-label="Month">
                <?php foreach ($fee_months as $fm): ?>
                    <option value="<?= htmlspecialchars($fm) ?>" <?= $fm === $fee_month ? 'selected' : '' ?>><?= htmlspecialchars(month_label($fm)) ?></option>
                <?php endforeach; ?>
            </select>
            <a class="btn btn-outline-secondary" href="index.php?action=admin_fees&fee_month=<?= urlencode($next_month) ?>" title="Next month"><i class="fa-solid fa-chevron-right"></i></a>
        </form>
    </div>

    <div class="card card-custom p-4 mb-4">
        <h5 class="fw-bold mb-1"><i class="fa-solid fa-user-clock text-primary me-2"></i>Student Fee History</h5>
        <p class="small text-muted">Select a student to see the fees of all their months.</p>
        <form method="GET" action="index.php" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="admin_fees"><input type="hidden" name="fee_month" value="<?= htmlspecialchars($fee_month) ?>">
            <div class="col-md-8"><select name="fee_student" class="form-select" onchange="this.form.submit()" aria-label="Student">
                <option value="">-- Select student --</option>
                <?php foreach ($all_students as $__s): ?><option value="<?= (int)$__s['id'] ?>" <?= (int)$__s['id'] === $fv_sid ? 'selected' : '' ?>><?= htmlspecialchars($__s['full_name']) ?> (<?= htmlspecialchars($__s['class']) ?> &ndash; <?= htmlspecialchars($__s['stream']) ?>)</option><?php endforeach; ?>
            </select></div>
            <div class="col-md-4"><button class="btn btn-primary w-100 fw-bold"><i class="fa-solid fa-magnifying-glass me-1"></i>Show All Months</button></div>
        </form>
        <?php if ($fv_sid > 0 && !$fv): ?>
            <div class="alert alert-warning mt-3 mb-0">Student not found.</div>
        <?php elseif ($fv): ?>
            <hr>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div><div class="fw-bold fs-5"><?= htmlspecialchars($fv['s']['full_name']) ?></div><div class="small text-muted"><?= htmlspecialchars($fv['s']['class']) ?> &ndash; <?= htmlspecialchars($fv['s']['stream']) ?> &middot; Monthly fee: <b><?= $fv['mf'] > 0 ? money($fv['mf']) : 'Not set' ?></b></div></div>
                <div class="d-flex gap-2">
                    <a class="btn btn-sm btn-outline-secondary" href="index.php?action=admin_fees&fee_month=<?= urlencode($fee_month) ?>">Close</a>
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-4"><div class="border rounded p-2 text-center"><div class="small text-muted">Total paid</div><div class="fs-5 fw-bold text-success"><?= money($fv['tpaid']) ?></div></div></div>
                <div class="col-6 col-md-4"><div class="border rounded p-2 text-center"><div class="small text-muted">Total still due</div><div class="fs-5 fw-bold <?= $fv['tdue'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($fv['tdue']) ?></div></div></div>
                <div class="col-12 col-md-4"><div class="border rounded p-2 text-center"><div class="small text-muted">Months listed</div><div class="fs-5 fw-bold"><?= count($fv['rows']) ?></div></div></div>
            </div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Month</th><th>Fee</th><th>Paid</th><th>Due</th><th>Status</th><th>Payments</th></tr></thead>
                <tbody>
                <?php foreach ($fv['rows'] as $__r): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars(month_label($__r['m'])) ?></td>
                        <td><?= $__r['free'] ? '—' : money($fv['mf']) ?></td>
                        <td class="text-success fw-semibold"><?= $__r['free'] ? 'Free' : money($__r['paid']) ?><?php if ($__r['man']): ?> <span class="badge bg-light text-dark border">Typed by hand</span><?php endif; ?></td>
                        <td class="text-danger fw-semibold"><?= money($__r['due']) ?></td>
                        <td><span class="badge bg-<?= $__r['bd'][0] ?> <?= $__r['bd'][0] === 'warning' ? 'text-dark' : '' ?>"><?= $__r['bd'][1] ?></span></td>
                        <td class="small text-muted"><?php if (!$__r['pay']): ?>—<?php else: foreach ($__r['pay'] as $__p): ?><div><?= !empty($__p['paid_on']) ? date('d M Y', strtotime($__p['paid_on'])) : '' ?> &middot; <b class="text-dark"><?= money($__p['amount']) ?></b> &middot; <?= htmlspecialchars((string)$__p['method']) ?></div><?php endforeach; endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light"><tr><th>Total</th><th></th><th class="text-success"><?= money($fv['tpaid']) ?></th><th class="text-danger"><?= money($fv['tdue']) ?></th><th colspan="2"></th></tr></tfoot>
            </table></div>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="card card-custom p-3"><div class="text-muted small">Received in <?= htmlspecialchars(month_label($fee_month)) ?></div><div class="fs-4 fw-bold text-success"><?= money($total_month) ?></div></div></div>
    </div>

    <div class="card card-custom p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h5 class="fw-bold mb-0">Students &mdash; <?= htmlspecialchars(month_label($fee_month)) ?></h5>
            <input type="search" id="feeSearch" class="form-control" style="max-width:280px" placeholder="Search by name or username" autocomplete="off">
        </div>
        <div class="table-responsive">
            <table class="table align-middle" id="feeTable">
                <thead class="table-light"><tr><th>Student</th><th>Class</th><th>Monthly Fee</th><th>Paid</th><th>Balance</th><th>Status</th><th style="min-width:250px">What do you want to do?</th></tr></thead>
                <tbody>
                <?php if (!$fee_rows): ?><tr><td colspan="7" class="text-center text-muted">No students registered yet.</td></tr><?php endif; ?>
                <?php foreach ($fee_rows as $r): $s = $r['s']; ?>
                <tr data-status="<?= $r['key'] ?>" data-search="<?= htmlspecialchars(strtolower($s['full_name'] . ' ' . $s['username'])) ?>">
                    <td><strong><?= htmlspecialchars($s['full_name']) ?></strong><div class="small text-muted"><?= htmlspecialchars($s['username']) ?></div></td>
                    <td><?= htmlspecialchars($s['class']) ?> &ndash; <?= htmlspecialchars($s['stream']) ?></td>
                    <td><?= $r['mf'] > 0 ? money($r['mf']) : '<span class="text-muted">Not set</span>' ?></td>
                    <td class="text-success fw-semibold">
                        <?= $r['free'] ? 'Free' : money($r['paid']) ?>
                        <?php if ($r['manual']): ?><span class="badge bg-light text-dark border ms-1" title="This month's paid amount was typed in by hand, so it ignores the payments below. Open the settings button to go back to normal.">Typed by hand</span><?php endif; ?>
                    </td>
                    <td class="fw-semibold <?= $r['due'] > 0 ? 'text-danger' : 'text-muted' ?>"><?= money($r['due']) ?></td>
                    <td><span class="badge bg-<?= $r['badge'][0] ?> <?= $r['badge'][0] === 'warning' ? 'text-dark' : '' ?>"><?= $r['badge'][1] ?></span></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-success fw-semibold" data-bs-toggle="modal" data-bs-target="#payModal"
                            data-id="<?= (int)$s['id'] ?>" data-name="<?= htmlspecialchars($s['full_name']) ?>" data-due="<?= (float)$r['due'] ?>">
                            <i class="fa-solid fa-hand-holding-dollar me-1"></i>Receive Payment
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#feeSettingsModal" title="Change monthly fee / mark month Free"
                            data-id="<?= (int)$s['id'] ?>" data-name="<?= htmlspecialchars($s['full_name']) ?>" data-mf="<?= (float)$r['mf'] ?>"
                            data-free="<?= $r['free'] ? 1 : 0 ?>" data-manual="<?= $r['manual'] ? 1 : 0 ?>">
                            <i class="fa-solid fa-gear"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="feeNoMatch" class="d-none"><td colspan="7" class="text-center text-muted py-4">No student matches your search.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card card-custom p-4">
        <h5 class="fw-bold mb-1">Payments received &mdash; <?= htmlspecialchars(month_label($fee_month)) ?></h5>
        <p class="small text-muted mb-3">Every payment saved for this month. You can print a receipt or delete a payment that was entered by mistake.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light"><tr><th>Student</th><th>Amount</th><th>Date</th><th>Method</th><th>Note</th><th></th></tr></thead>
                <tbody>
                <?php if (!$month_payments): ?><tr><td colspan="6" class="text-center text-muted">No payment has been received for this month yet.</td></tr><?php endif; ?>
                <?php foreach ($month_payments as $p):
                    $del_text = 'Delete the payment of ' . money($p['amount']) . ' from ' . $p['full_name'] . '? Their balance will go up by the same amount.';
                ?>
                <tr>
                    <td><?= htmlspecialchars($p['full_name']) ?></td>
                    <td class="fw-semibold"><?= money($p['amount']) ?></td>
                    <td><?= date('d M Y', strtotime($p['paid_on'])) ?></td>
                    <td><?= htmlspecialchars($p['method']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($p['note'] ?? '') ?></td>
                    <td class="text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-primary js-receipt" title="Print receipt"
                            data-no="<?= (int)$p['id'] ?>" data-name="<?= htmlspecialchars($p['full_name']) ?>" data-month="<?= htmlspecialchars(month_label($fee_month)) ?>"
                            data-amount="<?= htmlspecialchars(money($p['amount'])) ?>" data-date="<?= date('d M Y', strtotime($p['paid_on'])) ?>"
                            data-method="<?= htmlspecialchars($p['method']) ?>" data-note="<?= htmlspecialchars($p['note'] ?? '') ?>">
                            <i class="fa-solid fa-print"></i> Receipt
                        </button>
                        <form method="POST" action="index.php?action=admin_fees" class="d-inline" onsubmit="return confirm(<?= htmlspecialchars(json_encode($del_text), ENT_QUOTES) ?>);">
                            <input type="hidden" name="fee_id" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="fee_month" value="<?= htmlspecialchars($fee_month) ?>">
                            <button name="delete_fee_btn" class="btn btn-sm btn-outline-danger" title="Delete this payment"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Receive Payment window -->
    <div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="index.php?action=admin_fees" class="modal-content" id="payForm">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-hand-holding-dollar text-success me-2"></i>Receive Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="fee_student_id" id="payId">
                    <input type="hidden" name="fee_month" value="<?= htmlspecialchars($fee_month) ?>">
                    <div class="p-3 bg-light rounded mb-3">
                        <div class="fw-bold fs-5" id="payName"></div>
                        <div class="small text-muted">Fee month: <b><?= htmlspecialchars(month_label($fee_month)) ?></b> &middot; Balance now: <b id="payBalance"></b></div>
                    </div>
                    <label class="form-label fw-semibold">Amount received (<?= htmlspecialchars($currency) ?>)</label>
                    <div class="input-group mb-1">
                        <input type="number" step="0.01" min="1" name="fee_amount" id="payAmount" class="form-control form-control-lg" required>
                        <button type="button" class="btn btn-outline-success" id="payFull">Full balance</button>
                    </div>
                    <div class="small mb-3" id="payHint">&nbsp;</div>
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">Date received</label><input type="date" name="fee_paid_on" value="<?= date('Y-m-d') ?>" class="form-control"></div>
                        <div class="col-6"><label class="form-label">Paid by</label>
                            <select name="fee_method" class="form-select"><option>Cash</option><option>Bank Transfer</option><option>Online</option><option>Cheque</option></select>
                        </div>
                        <div class="col-12"><label class="form-label">Note (optional)</label><input type="text" name="fee_note" maxlength="255" class="form-control" placeholder="e.g. paid by father"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_fee_btn" class="btn btn-success fw-bold"><i class="fa-solid fa-check me-1"></i>Save Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Fee settings window -->
    <div class="modal fade" id="feeSettingsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-gear me-2"></i>Fee settings: <span id="feeSetName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="index.php?action=admin_fees" class="mb-4">
                        <input type="hidden" name="student_id" class="js-sid">
                        <input type="hidden" name="fee_month" value="<?= htmlspecialchars($fee_month) ?>">
                        <label class="form-label fw-semibold">Monthly fee (<?= htmlspecialchars($currency) ?>)</label>
                        <div class="input-group">
                            <input type="number" step="1" min="0" name="monthly_fee" id="feeSetMonthly" class="form-control" required>
                            <button name="set_monthly_fee_btn" class="btn btn-primary">Save fee</button>
                        </div>
                        <div class="form-text">This is the fee this student pays every month. It applies to all months.</div>
                    </form>
                    <hr>
                    <div class="fw-semibold mb-2">Only for <?= htmlspecialchars(month_label($fee_month)) ?></div>
                    <form method="POST" action="index.php?action=admin_fees" id="feeFreeForm" class="mb-2" onsubmit="return confirm('Make <?= htmlspecialchars(month_label($fee_month), ENT_QUOTES) ?> free for this student? They will owe nothing for this month.');">
                        <input type="hidden" name="student_id" class="js-sid"><input type="hidden" name="fee_month" value="<?= htmlspecialchars($fee_month) ?>"><input type="hidden" name="fee_given" value="Free">
                        <button name="set_fee_given_btn" class="btn btn-outline-info w-100 text-start"><i class="fa-solid fa-gift me-2"></i>Make this month Free (no fee)</button>
                    </form>
                    <form method="POST" action="index.php?action=admin_fees" id="feeUnfreeForm" class="mb-2 d-none">
                        <input type="hidden" name="student_id" class="js-sid"><input type="hidden" name="fee_month" value="<?= htmlspecialchars($fee_month) ?>"><input type="hidden" name="fee_given" value="">
                        <div class="small text-muted mb-1">This month is currently marked <b>Free</b>.</div>
                        <button name="set_fee_given_btn" class="btn btn-outline-secondary w-100 text-start"><i class="fa-solid fa-rotate-left me-2"></i>Remove &ldquo;Free&rdquo; (student pays the normal fee)</button>
                    </form>
                    <form method="POST" action="index.php?action=admin_fees" id="feeManualForm" class="mb-0 d-none">
                        <input type="hidden" name="student_id" class="js-sid"><input type="hidden" name="fee_month" value="<?= htmlspecialchars($fee_month) ?>"><input type="hidden" name="fee_given" value="">
                        <div class="small text-muted mb-1">The paid amount for this month was typed in by hand, so it ignores the payments list.</div>
                        <button name="set_fee_given_btn" class="btn btn-outline-secondary w-100 text-start"><i class="fa-solid fa-rotate-left me-2"></i>Go back to adding up the real payments</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var CUR = <?= json_encode($currency) ?>;
        function fmt(n) { return CUR + ' ' + Math.round(n).toLocaleString('en-US'); }
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

        // ---- Receive Payment window ----
        var payModal = document.getElementById('payModal');
        var payAmount = document.getElementById('payAmount'), payHint = document.getElementById('payHint');
        var payDue = 0;
        function updateHint() {
            var amt = parseFloat(payAmount.value);
            if (!amt || amt <= 0) { payHint.className = 'small text-muted mb-3'; payHint.textContent = 'Type the amount the student gave you.'; return; }
            if (payDue <= 0) { payHint.className = 'small text-warning mb-3'; payHint.textContent = 'Nothing is pending for this month. This will be saved as an extra payment.'; return; }
            if (amt > payDue) { payHint.className = 'small text-danger mb-3'; payHint.textContent = 'This is ' + fmt(amt - payDue) + ' more than the balance of ' + fmt(payDue) + '.'; return; }
            payHint.className = 'small text-success mb-3';
            payHint.textContent = (amt === payDue) ? 'This clears the full balance.' : 'Balance left after this payment: ' + fmt(payDue - amt);
        }
        if (payModal) {
            payModal.addEventListener('show.bs.modal', function (e) {
                var b = e.relatedTarget; if (!b) return;
                payDue = parseFloat(b.getAttribute('data-due')) || 0;
                document.getElementById('payId').value = b.getAttribute('data-id');
                document.getElementById('payName').textContent = b.getAttribute('data-name');
                document.getElementById('payBalance').textContent = fmt(payDue);
                payAmount.value = payDue > 0 ? payDue : '';
                updateHint();
            });
            payModal.addEventListener('shown.bs.modal', function () { payAmount.focus(); payAmount.select(); });
            payAmount.addEventListener('input', updateHint);
            document.getElementById('payFull').addEventListener('click', function () { if (payDue > 0) { payAmount.value = payDue; } updateHint(); });
            document.getElementById('payForm').addEventListener('submit', function (e) {
                var amt = parseFloat(payAmount.value);
                if (!amt || amt <= 0) { e.preventDefault(); alert('Please type the amount received.'); return; }
                if (payDue > 0 && amt > payDue && !confirm('The amount (' + fmt(amt) + ') is more than the balance (' + fmt(payDue) + '). Save it anyway?')) { e.preventDefault(); }
            });
        }

        // ---- Fee settings window ----
        var setModal = document.getElementById('feeSettingsModal');
        if (setModal) {
            setModal.addEventListener('show.bs.modal', function (e) {
                var b = e.relatedTarget; if (!b) return;
                var isFree = b.getAttribute('data-free') === '1', isManual = b.getAttribute('data-manual') === '1';
                document.getElementById('feeSetName').textContent = b.getAttribute('data-name');
                document.getElementById('feeSetMonthly').value = b.getAttribute('data-mf');
                setModal.querySelectorAll('.js-sid').forEach(function (i) { i.value = b.getAttribute('data-id'); });
                document.getElementById('feeFreeForm').classList.toggle('d-none', isFree);
                document.getElementById('feeUnfreeForm').classList.toggle('d-none', !isFree);
                document.getElementById('feeManualForm').classList.toggle('d-none', isFree || !isManual);
            });
        }

        // ---- Search + status buttons ----
        var rows = document.querySelectorAll('#feeTable tbody tr[data-status]');
        var search = document.getElementById('feeSearch'), noMatch = document.getElementById('feeNoMatch');
        var filter = 'all';
        function applyFilter() {
            var q = search.value.trim().toLowerCase(), shown = 0;
            rows.forEach(function (r) {
                var ok = (filter === 'all' || r.getAttribute('data-status') === filter) && (!q || r.getAttribute('data-search').indexOf(q) !== -1);
                r.classList.toggle('d-none', !ok); if (ok) shown++;
            });
            if (noMatch) noMatch.classList.toggle('d-none', shown > 0 || !rows.length);
        }
        if (search) search.addEventListener('input', applyFilter);
        document.querySelectorAll('#feeFilters button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                filter = btn.getAttribute('data-filter');
                document.querySelectorAll('#feeFilters button').forEach(function (o) {
                    var on = (o === btn); o.classList.toggle('btn-dark', on); o.classList.toggle('btn-outline-dark', !on);
                });
                applyFilter();
            });
        });

        // ---- Print receipt ----
        document.querySelectorAll('.js-receipt').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var d = function (k) { return btn.getAttribute('data-' + k); };
                var w = window.open('', '_blank', 'width=460,height=640');
                if (!w) { alert('Please allow pop-ups for this site to print the receipt.'); return; }
                w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Receipt #' + esc(d('no')) + '</title>' +
                    '<style>body{font-family:Arial,sans-serif;max-width:380px;margin:24px auto;color:#111}h2{text-align:center;margin:0}p.s{text-align:center;color:#555;margin:4px 0 18px}' +
                    'table{width:100%;border-collapse:collapse}td{padding:8px 0;border-bottom:1px dashed #bbb}td:first-child{color:#555}td:last-child{text-align:right;font-weight:bold}' +
                    '.amt{font-size:22px;margin-top:14px;text-align:center}.sig{margin-top:50px;text-align:right;border-top:1px solid #333;padding-top:4px;font-size:12px}</style></head><body>' +
                    '<h2>S-Orbit Tuition</h2><p class="s">Fee Receipt #' + esc(d('no')) + '</p><table>' +
                    '<tr><td>Student</td><td>' + esc(d('name')) + '</td></tr><tr><td>Fee month</td><td>' + esc(d('month')) + '</td></tr>' +
                    '<tr><td>Date received</td><td>' + esc(d('date')) + '</td></tr><tr><td>Paid by</td><td>' + esc(d('method')) + '</td></tr>' +
                    (d('note') ? '<tr><td>Note</td><td>' + esc(d('note')) + '</td></tr>' : '') +
                    '</table><div class="amt">Amount received: <b>' + esc(d('amount')) + '</b></div><div class="sig">Received by</div>' +
                    '<script>window.onload=function(){window.print()}<\/script></body></html>');
                w.document.close();
            });
        });
    })();
    </script>

    <?php
    // ADMIN: NOTICES
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_student_results'):
        $rs_students = $pdo->query("SELECT id, full_name, class, username FROM students ORDER BY class, full_name")->fetchAll();
        $rs_id = (int)($_GET['id'] ?? 0);
        $rs_rows = [];
        if ($rs_id > 0) {
            $q = $pdo->prepare("SELECT st.*, tm.test_name FROM student_tests st LEFT JOIN tests_meta tm ON tm.id = st.test_id WHERE st.student_id = ? ORDER BY st.test_date DESC"); $q->execute([$rs_id]);
            $rs_rows = $q->fetchAll();
        }
        $rs_avg = $rs_rows ? array_sum(array_column($rs_rows, 'score_percentage')) / count($rs_rows) : 0;
    ?>
    <h2 class="fw-bold mb-3"><i class="fa-solid fa-square-poll-vertical text-primary me-2"></i>Student Results</h2>
    <div class="card card-custom p-3 mb-4">
        <form method="GET" action="index.php" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="admin_student_results">
            <div class="col-md-8"><label class="form-label small mb-1">Select student</label>
                <select name="id" class="form-select" onchange="this.form.submit()">
                    <option value="">Choose a student...</option>
                    <?php foreach ($rs_students as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $rs_id === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['class']) ?> — <?= htmlspecialchars($r['full_name']) ?> (<?= htmlspecialchars($r['username']) ?>)</option><?php endforeach; ?>
                </select></div>
            <div class="col-md-4"><button class="btn btn-primary w-100 fw-bold">Show Results</button></div>
        </form>
    </div>
    <?php if ($rs_id > 0): ?>
    <div class="card card-custom p-3">
        <div class="d-flex flex-wrap justify-content-between mb-2">
            <h5 class="fw-bold mb-0">All Tests (<?= count($rs_rows) ?>)</h5>
            <?php if ($rs_rows): ?><span class="badge bg-primary fs-6">Average <?= number_format($rs_avg, 1) ?>%</span><?php endif; ?>
        </div>
        <?php if (!$rs_rows): ?><div class="text-muted">This student has not taken any test yet.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Test</th><th>Subject</th><th>Date</th><th>Correct</th><th>Score</th><th>Time</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rs_rows as $t): $sc = (float)$t['score_percentage']; ?>
                <tr>
                    <td><?= htmlspecialchars(!empty($t['test_name']) ? $t['test_name'] : 'General Test') ?></td>
                    <td><?= htmlspecialchars($t['subject']) ?></td>
                    <td class="small"><?= htmlspecialchars((string)$t['test_date']) ?></td>
                    <td><?= (int)$t['correct_answers'] ?> / <?= (int)$t['total_questions'] ?></td>
                    <td><span class="badge bg-<?= $sc >= 70 ? 'success' : ($sc >= 40 ? 'warning text-dark' : 'danger') ?>"><?= number_format($sc, 1) ?>%</span></td>
                    <td class="small"><?= floor((int)$t['time_taken_seconds'] / 60) ?>m <?= (int)$t['time_taken_seconds'] % 60 ?>s</td>
                    <td><a class="btn btn-sm btn-outline-primary" href="index.php?action=view_result&test_id=<?= (int)$t['id'] ?>">Full review</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_student_logins'):
        $sl = $pdo->query("SELECT id, full_name, username, password, class FROM students ORDER BY class, full_name")->fetchAll();
        $sl_by = [];
        foreach ($sl as $r) { $sl_by[$r['class']][] = $r; }
        uksort($sl_by, function ($a, $b) { return strnatcasecmp($a, $b); });
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="fw-bold mb-0"><i class="fa-solid fa-key text-primary me-2"></i>Student Logins</h2>
        <input type="text" id="slSearch" class="form-control" style="max-width:280px" placeholder="Search name / username...">
    </div>
    <?php if (!$sl): ?><div class="alert alert-info">No students registered yet.</div><?php endif; ?>
    <?php foreach ($sl_by as $cname => $rows): ?>
    <div class="card card-custom p-3 mb-4 sl-block">
        <h5 class="fw-bold mb-3">Class <?= htmlspecialchars($cname) ?> <span class="badge bg-secondary"><?= count($rows) ?></span></h5>
        <?php foreach ($rows as $r): ?>
        <form method="POST" action="index.php?action=admin_student_logins" class="row g-2 align-items-center border-top py-2 sl-row" autocomplete="off">
            <input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>">
            <div class="col-md-3 fw-semibold"><?= htmlspecialchars($r['full_name']) ?></div>
            <div class="col-md-3"><input type="text" name="new_username" class="form-control form-control-sm" maxlength="50" value="<?= htmlspecialchars($r['username']) ?>" required></div>
            <div class="col-md-3"><input type="text" name="new_password" class="form-control form-control-sm" value="<?= htmlspecialchars($r['password']) ?>" required></div>
            <div class="col-md-3"><button name="save_student_login_btn" class="btn btn-sm btn-primary fw-semibold"><i class="fa-solid fa-floppy-disk me-1"></i>Save</button></div>
        </form>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <script>
    document.getElementById('slSearch').addEventListener('input', function () {
        var q = this.value.toLowerCase();
        document.querySelectorAll('.sl-row').forEach(function (f) { f.style.display = (f.textContent + ' ' + f.querySelector('[name=new_username]').value).toLowerCase().indexOf(q) > -1 ? '' : 'none'; });
        document.querySelectorAll('.sl-block').forEach(function (b) { b.style.display = b.querySelector('.sl-row:not([style*="none"])') ? '' : 'none'; });
    });
    </script>
    <?php
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_site_info'):
        $si = site_info_get($pdo);
    ?>
    <h2 class="fw-bold mb-3"><i class="fa-solid fa-address-book text-primary me-2"></i>Website Contact Info</h2>
    <div class="card card-custom p-4" style="max-width:640px">
        <p class="text-muted small">These details are shown on the visitor website (contact section and footer). Leave WhatsApp or the second number empty to hide them.</p>
        <form method="POST" action="index.php?action=admin_site_info">
            <div class="mb-3"><label class="form-label">Phone Number 1</label><input type="text" name="si_phone" class="form-control" value="<?= htmlspecialchars($si['phone']) ?>"></div>
            <div class="mb-3"><label class="form-label">Phone Number 2 (optional)</label><input type="text" name="si_phone2" class="form-control" value="<?= htmlspecialchars($si['phone2']) ?>"></div>
            <div class="mb-3"><label class="form-label">WhatsApp Number (optional, with country code)</label><input type="text" name="si_whatsapp" class="form-control" placeholder="923001234567" value="<?= htmlspecialchars($si['whatsapp']) ?>"></div>
            <div class="mb-3"><label class="form-label">Email</label><input type="email" name="si_email" class="form-control" value="<?= htmlspecialchars($si['email']) ?>"></div>
            <div class="mb-3"><label class="form-label">Address</label><textarea name="si_address" rows="3" class="form-control"><?= htmlspecialchars($si['address']) ?></textarea></div>
            <button type="submit" name="save_site_info_btn" class="btn btn-primary fw-bold"><i class="fa-solid fa-floppy-disk me-1"></i>Save Contact Info</button>
        </form>
    </div>
    <?php
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_students'):
        $db_classes = $pdo->query("SELECT * FROM classes ORDER BY id ASC")->fetchAll();
        $class_opts = array_column($db_classes, 'class_name'); if (!in_array('12th Pass', $class_opts, true)) $class_opts[] = '12th Pass';
        $all_st = $pdo->query("SELECT * FROM students ORDER BY class, full_name")->fetchAll();
        $by_class = [];
        foreach ($all_st as $r) { $by_class[$r['class']][] = $r; }
        uksort($by_class, function ($a, $b) { return strnatcasecmp($a, $b); });
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="fw-bold mb-0"><i class="fa-solid fa-user-graduate text-primary me-2"></i>All Students <span class="badge bg-primary fs-6"><?= count($all_st) ?></span></h2>
        <input type="text" id="stSearch" class="form-control" style="max-width:280px" placeholder="Search name / username / phone...">
    </div>
    <?php if (!$all_st): ?><div class="alert alert-info">No students registered yet.</div><?php endif; ?>
    <?php foreach ($by_class as $cname => $rows): ?>
    <div class="card card-custom p-3 mb-4 st-class-block">
        <h5 class="fw-bold mb-3">Class <?= htmlspecialchars($cname) ?> <span class="badge bg-secondary"><?= count($rows) ?> students</span></h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 st-table">
                <thead class="table-light"><tr><th>Name</th><th>Username</th><th>Stream</th><th>Father</th><th>Phone</th><th>Class</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr class="st-row">
                        <td data-label="Name"><span><strong><?= htmlspecialchars($r['full_name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($r['gender']) ?></small></span></td>
                        <td data-label="Username"><?= htmlspecialchars($r['username']) ?></td>
                        <td data-label="Stream"><?= htmlspecialchars($r['stream']) ?></td>
                        <td data-label="Father"><span><?= htmlspecialchars((string)($r['father_name'] ?? '')) ?><br><small class="text-muted"><?= htmlspecialchars((string)($r['father_phone'] ?? '')) ?></small></span></td>
                        <td data-label="Phone"><?= htmlspecialchars((string)($r['student_phone'] ?? '')) ?></td>
                        <td data-label="Class">
                            <form method="POST" action="index.php?action=admin_students" class="d-flex gap-1">
                                <input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>">
                                <select name="std_class" class="form-select form-select-sm" style="min-width:105px">
                                    <?php $opts = $class_opts; if (!in_array($r['class'], $opts, true)) $opts[] = $r['class']; foreach ($opts as $co): ?><option <?= $r['class'] === $co ? 'selected' : '' ?>><?= htmlspecialchars($co) ?></option><?php endforeach; ?>
                                </select>
                                <button name="update_student_class_btn" class="btn btn-sm btn-outline-success" title="Save class"><i class="fa-solid fa-check"></i></button>
                            </form>
                        </td>
                        <td class="text-end text-nowrap st-actions">
                            <a href="index.php?action=admin_student_dashboard&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary"><i class="fa-solid fa-gauge-high me-1"></i>Dashboard</a>
                            <a href="index.php?action=admin_student_edit&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i></a>
                            <form method="POST" action="index.php?action=admin_students" class="d-inline" onsubmit="return confirm('Delete this student ID permanently? All their tests, results and fee records will also be deleted.')">
                                <input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" name="delete_student_btn" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash me-1"></i>Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    <script>
    document.getElementById('stSearch').addEventListener('input', function () {
        var q = this.value.toLowerCase();
        document.querySelectorAll('.st-row').forEach(function (tr) { tr.style.display = tr.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none'; });
        document.querySelectorAll('.st-class-block').forEach(function (b) { b.style.display = b.querySelector('.st-row:not([style*="none"])') ? '' : 'none'; });
    });
    </script>
    <?php
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_student_dashboard'):
        $q = $pdo->prepare("SELECT * FROM students WHERE id = ?"); $q->execute([(int)($_GET['id'] ?? 0)]);
        $ds = $q->fetch();
        if (!$ds) { redirect("index.php?action=admin_students&err=" . urlencode("Student not found.")); }
        $dsid = (int)$ds['id'];
        $dsCtx = ['id' => $dsid, 'class' => $ds['class'], 'gender' => $ds['gender']];
        $q = $pdo->prepare("SELECT st.*, tm.test_name FROM student_tests st LEFT JOIN tests_meta tm ON tm.id = st.test_id WHERE st.student_id = ? ORDER BY st.test_date DESC"); $q->execute([$dsid]);
        $ds_tests = $q->fetchAll();
        $dsFee = (float)$ds['monthly_fee'];
        $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fees WHERE student_id = ? AND fee_month = ?"); $q->execute([$dsid, date('Y-m')]);
        $dsPaid = (float)$q->fetchColumn(); $dsFree = false;
        $q = $pdo->prepare("SELECT given FROM fee_given WHERE student_id = ? AND fee_month = ?"); $q->execute([$dsid, date('Y-m')]);
        $dsGv = $q->fetchColumn();
        if ($dsGv !== false) { if (strcasecmp(trim($dsGv), 'free') === 0) { $dsFree = true; $dsPaid = 0; } elseif (is_numeric($dsGv)) { $dsPaid = (float)$dsGv; } }
        $dsSmap = [$dsid => $ds['full_name']];
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h2 class="fw-bold mb-0"><i class="fa-solid fa-gauge-high text-primary me-2"></i><?= htmlspecialchars($ds['full_name']) ?> &mdash; Dashboard</h2>
            <div class="text-muted">Class <strong><?= htmlspecialchars($ds['class']) ?></strong> (<?= htmlspecialchars($ds['stream']) ?>) | Gender: <strong><?= htmlspecialchars($ds['gender']) ?></strong> | Username: <strong><?= htmlspecialchars($ds['username']) ?></strong></div>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php?action=admin_student_results&id=<?= $dsid ?>" class="btn btn-outline-success btn-sm"><i class="fa-solid fa-square-poll-vertical me-1"></i>All Results</a>
            <a href="index.php?action=admin_student_edit&id=<?= $dsid ?>" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-pen me-1"></i>Edit</a>
            <a href="index.php?action=admin_students" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
        </div>
    </div>

    <div class="card card-custom p-4 mb-4">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-id-card text-primary me-2"></i>Profile</h5>
        <div class="row g-3">
            <div class="col-md-4"><div class="small text-muted">Monthly Fee</div><div class="fw-semibold"><?= money($dsFee) ?></div></div>
            <?php foreach (student_extra_fields() as $pc => $pd): if (empty($ds[$pc])) continue; $pv = $ds[$pc]; if ($pd[1] === 'date') { $pv = date('d M Y', strtotime($pv)); } ?>
                <div class="<?= $pd[1] === 'textarea' ? 'col-12' : 'col-md-4' ?>"><div class="small text-muted"><i class="fa-solid <?= $pd[2] ?> me-1"></i><?= htmlspecialchars($pd[0]) ?></div><div class="fw-semibold"><?= nl2br(htmlspecialchars((string)$pv)) ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card card-custom p-3 h-100">
                <div class="text-muted small">Fee &mdash; <?= date('F Y') ?></div>
                <div class="fs-4 fw-bold <?= ($dsFree || ($dsFee > 0 && $dsPaid >= $dsFee)) ? 'text-success' : 'text-danger' ?>">
                    <?= $dsFree ? 'Free' : (($dsFee > 0 && $dsPaid >= $dsFee) ? 'Paid' : ($dsPaid > 0 ? 'Partially Paid' : ($dsFee > 0 ? 'Unpaid' : 'Not set'))) ?>
                </div>
                <div class="small text-muted"><?= $dsFree ? 'No fee this month' : 'Paid ' . money($dsPaid) . ($dsFee > 0 ? ' of ' . money($dsFee) : '') ?></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card card-custom p-3 h-100">
                <div class="text-muted small">Tests Taken</div>
                <div class="fs-4 fw-bold text-primary"><?= count($ds_tests) ?></div>
            </div>
        </div>
    </div>

    <?php
        try {
            $q = $pdo->prepare("SELECT * FROM tests_meta WHERE class = ? AND stream = ? AND is_live = 1 AND (test_expiry_date IS NULL OR test_expiry_date > ?) AND gender_target IN ('All', ?) ORDER BY id DESC");
            $q->execute([$ds['class'], $ds['stream'], date('Y-m-d H:i:s'), $ds['gender']]);
            $ds_avail = $q->fetchAll();
        } catch (Throwable $e) { $ds_avail = []; }
        $doneIds = array_map('intval', array_column($ds_tests, 'test_id'));
    ?>
    <div class="card card-custom p-4 mb-4">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-tower-broadcast text-danger me-2"></i>Live Tests for this Student <span class="badge bg-light text-dark"><?= count($ds_avail) ?></span></h5>
        <?php if (!$ds_avail): ?><div class="text-muted small">No live test is available for this student's class right now.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Test</th><th>Duration</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($ds_avail as $t): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($t['test_name']) ?></td>
                    <td><?= !empty($t['time_limit_minutes']) ? (int)$t['time_limit_minutes'] : 90 ?> min</td>
                    <td><?= in_array((int)$t['id'], $doneIds, true) ? '<span class="badge bg-success">Taken</span>' : '<span class="badge bg-warning text-dark">Not taken yet</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <?php
        try { $ds_notices = $pdo->query("SELECT * FROM announcements ORDER BY id DESC LIMIT 3")->fetchAll(); } catch (Throwable $e) { $ds_notices = []; }
        if ($ds_notices):
    ?>
    <div class="card card-custom p-4 mb-4">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-bullhorn text-warning me-2"></i>Latest Notices</h5>
        <?php foreach ($ds_notices as $nt): ?>
            <div class="mb-2"><strong><?= htmlspecialchars((string)($nt['title'] ?? '')) ?></strong><div class="small text-muted"><?= nl2br(htmlspecialchars((string)($nt['message'] ?? ($nt['body'] ?? '')))) ?></div></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card card-custom p-4 mb-4">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-square-poll-vertical text-success me-2"></i>Tests Taken</h5>
        <?php if (!$ds_tests): ?><div class="text-muted small">No tests taken yet.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Test</th><th>Score</th><th>Date</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($ds_tests as $t): ?>
                <tr>
                    <td><?= htmlspecialchars(!empty($t['test_name']) ? $t['test_name'] : ($t['subject'] ?: 'General Test')) ?></td>
                    <td><strong><?= (int)$t['correct_answers'] ?> / <?= (int)$t['total_questions'] ?></strong> (<?= htmlspecialchars((string)$t['score_percentage']) ?>%)</td>
                    <td class="small"><?= htmlspecialchars((string)$t['test_date']) ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="index.php?action=view_result&test_id=<?= (int)$t['id'] ?>">Details</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>


    <?php
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_student_view'):
        $q = $pdo->prepare("SELECT * FROM students WHERE id = ?"); $q->execute([(int)($_GET['id'] ?? 0)]);
        $vs = $q->fetch();
        if (!$vs) { redirect("index.php?action=admin_students&err=Student not found."); }
        $vsid = (int)$vs['id'];
        $q = $pdo->prepare("SELECT st.*, tm.test_name FROM student_tests st LEFT JOIN tests_meta tm ON tm.id = st.test_id WHERE st.student_id = ? ORDER BY st.test_date DESC"); $q->execute([$vsid]);
        $vs_tests = $q->fetchAll();
        $q = $pdo->prepare("SELECT fee_month, SUM(amount) AS paid FROM fees WHERE student_id = ? GROUP BY fee_month"); $q->execute([$vsid]);
        $vs_paid = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        $q = $pdo->prepare("SELECT fee_month, given FROM fee_given WHERE student_id = ?"); $q->execute([$vsid]);
        $vs_given = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        $q = $pdo->prepare("SELECT * FROM fees WHERE student_id = ? ORDER BY fee_month DESC, paid_on DESC, id DESC"); $q->execute([$vsid]);
        $vs_payments = $q->fetchAll();
        $start_src = !empty($vs['admission_date']) ? $vs['admission_date'] : $vs['created_at'];
        // "Show earlier months" box: lets the admin look further back than the admission date.
        $vs_from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : '';
        if ($vs_from !== '' && strtotime($vs_from . '-01') && strtotime($vs_from . '-01') < strtotime((string)$start_src)) { $start_src = $vs_from . '-01'; }
        $vs_months = fee_months_for($start_src, array_merge(array_keys($vs_paid), array_keys($vs_given)));
        $vs_mf = (float)$vs['monthly_fee']; $tot_due = 0; $tot_paid = 0; $vs_rows = []; $thisMonth = date('Y-m');
        foreach ($vs_months as $m) {
            $g = $vs_given[$m] ?? null; $isFree = $g !== null && strcasecmp(trim($g), 'free') === 0; $manual = !$isFree && $g !== null && is_numeric($g);
            $paid = $isFree ? 0 : ($manual ? (float)$g : (float)($vs_paid[$m] ?? 0));
            $future = $m > $thisMonth;
            $due = ($isFree || $future) ? 0 : max(0, $vs_mf - $paid);
            if (!$isFree) { $tot_due += $due; $tot_paid += $paid; }
            if ($isFree) { $bd = ['info', 'Free']; } elseif ($future) { $bd = ['info', 'Advance']; } elseif ($vs_mf > 0 && $paid >= $vs_mf) { $bd = ['success', 'Paid']; } elseif ($paid > 0) { $bd = ['warning', 'Partial']; } else { $bd = ['danger', $vs_mf > 0 ? 'Unpaid' : 'Fee not set']; }
            $vs_rows[] = ['m' => $m, 'free' => $isFree, 'manual' => $manual, 'paid' => $paid, 'due' => $due, 'bd' => $bd];
        }
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="fw-bold mb-0"><i class="fa-solid fa-id-card text-primary me-2"></i><?= htmlspecialchars($vs['full_name']) ?></h2>
        <div>
            <a href="index.php?action=admin_student_results&id=<?= $vsid ?>" class="btn btn-outline-success btn-sm"><i class="fa-solid fa-square-poll-vertical me-1"></i>All Results</a>
            <a href="index.php?action=admin_student_edit&id=<?= $vsid ?>" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-pen me-1"></i>Edit</a>
            <a href="index.php?action=admin_students" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
        </div>
    </div>
    <div class="card card-custom p-4 mb-4">
        <div class="row g-3">
            <div class="col-md-4"><div class="small text-muted">Username</div><div class="fw-semibold"><?= htmlspecialchars($vs['username']) ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Class / Stream</div><div class="fw-semibold"><?= htmlspecialchars($vs['class']) ?> (<?= htmlspecialchars($vs['stream']) ?>)</div></div>
            <div class="col-md-4"><div class="small text-muted">Gender</div><div class="fw-semibold"><?= htmlspecialchars($vs['gender']) ?></div></div>
            <div class="col-md-4"><div class="small text-muted">Monthly Fee</div><div class="fw-semibold"><?= money($vs['monthly_fee']) ?></div></div>
            <?php foreach (student_extra_fields() as $pc => $pd): if (empty($vs[$pc])) continue; $pv = $vs[$pc]; if ($pd[1] === 'date') { $pv = date('d M Y', strtotime($pv)); } ?>
                <div class="<?= $pd[1] === 'textarea' ? 'col-12' : 'col-md-4' ?>"><div class="small text-muted"><i class="fa-solid <?= $pd[2] ?> me-1"></i><?= htmlspecialchars($pd[0]) ?></div><div class="fw-semibold"><?= nl2br(htmlspecialchars((string)$pv)) ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="row g-4">
        <div class="col-lg-7"><div class="card card-custom p-3"><h5 class="fw-bold">Tests Taken</h5>
            <?php if (!$vs_tests): ?><div class="text-muted small">No tests taken yet.</div><?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Test</th><th>Date</th><th></th></tr></thead><tbody>
            <?php foreach ($vs_tests as $t): ?><tr><td><?= htmlspecialchars(!empty($t['test_name']) ? $t['test_name'] : ($t['subject'] ?: 'General Test')) ?></td><td class="small"><?= htmlspecialchars((string)$t['test_date']) ?></td><td><a class="btn btn-sm btn-outline-primary" href="index.php?action=view_result&test_id=<?= (int)$t['id'] ?>">Result</a></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?></div></div>
    </div>
    <div class="row g-3 mt-2 mb-1">
        <div class="col-6 col-lg-3"><div class="card card-custom p-3 h-100"><div class="text-muted small">Monthly fee</div><div class="fs-4 fw-bold"><?= $vs_mf > 0 ? money($vs_mf) : 'Not set' ?></div>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-bs-toggle="modal" data-bs-target="#vsFeeModal"><i class="fa-solid fa-pen me-1"></i>Change</button></div></div>
        <div class="col-6 col-lg-3"><div class="card card-custom p-3 h-100"><div class="text-muted small">Total paid (all months)</div><div class="fs-4 fw-bold text-success"><?= money($tot_paid) ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="card card-custom p-3 h-100"><div class="text-muted small">Total still due</div><div class="fs-4 fw-bold <?= $tot_due > 0 ? 'text-danger' : 'text-success' ?>"><?= money($tot_due) ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="card card-custom p-3 h-100"><div class="text-muted small">Months shown</div><div class="fs-4 fw-bold text-primary"><?= count($vs_rows) ?></div></div></div>
    </div>
    <div class="card card-custom p-3 mt-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
            <h5 class="fw-bold mb-0"><i class="fa-solid fa-coins text-warning me-2"></i>Fee History &mdash; every month</h5>
        </div>
        <p class="small text-muted">Every month with a fee record is always listed, even before the admission date. Press <b>Receive</b> when the student pays (any past month works too). <b>Free</b> means no fee for that month.</p>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Month</th><th>Fee</th><th>Paid</th><th>Due</th><th>Status</th><th style="min-width:230px">Action</th></tr></thead>
            <tbody>
            <?php foreach ($vs_rows as $r): $m = $r['m']; ?>
                <tr>
                    <td class="fw-semibold"><?= month_label($m) ?></td>
                    <td><?= $r['free'] ? '—' : money($vs_mf) ?></td>
                    <td class="text-success fw-semibold"><?= $r['free'] ? 'Free' : money($r['paid']) ?><?php if ($r['manual']): ?> <span class="badge bg-light text-dark border" title="This month's paid amount was typed in by hand, so it ignores the payments listed below. Press Reset to count the real payments again.">Typed by hand</span><?php endif; ?></td>
                    <td class="text-danger fw-semibold"><?= money($r['due']) ?></td>
                    <td><span class="badge bg-<?= $r['bd'][0] ?> <?= $r['bd'][0] === 'warning' ? 'text-dark' : '' ?>"><?= $r['bd'][1] ?></span></td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                        <?php if (!$r['free']): ?>
                            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#vsPayModal" data-month="<?= $m ?>" data-label="<?= htmlspecialchars(month_label($m)) ?>" data-due="<?= (float)$r['due'] ?>"><i class="fa-solid fa-hand-holding-dollar me-1"></i>Receive</button>
                        <?php endif; ?>
                        <form method="POST" action="index.php?action=admin_student_view&id=<?= $vsid ?>" class="d-inline" <?php if (!$r['free']): ?>onsubmit="return confirm('Make <?= htmlspecialchars(month_label($m), ENT_QUOTES) ?> free for this student?');"<?php endif; ?>>
                            <input type="hidden" name="student_id" value="<?= $vsid ?>"><input type="hidden" name="fee_month" value="<?= $m ?>"><input type="hidden" name="fee_given" value="">
                            <?php if ($r['free']): ?>
                                <button name="student_month_fee_btn" value="1" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-rotate-left me-1"></i>Remove Free</button>
                            <?php else: ?>
                                <input type="hidden" name="mark_free" value="1"><button name="student_month_fee_btn" value="1" class="btn btn-sm btn-outline-info">Free</button>
                            <?php endif; ?>
                        </form>
                        <?php if ($r['manual']): ?>
                        <form method="POST" action="index.php?action=admin_student_view&id=<?= $vsid ?>" class="d-inline" onsubmit="return confirm('Reset <?= htmlspecialchars(month_label($m), ENT_QUOTES) ?>? The hand-typed amount is removed and the real payments are counted again.');">
                            <input type="hidden" name="student_id" value="<?= $vsid ?>"><input type="hidden" name="fee_month" value="<?= $m ?>"><input type="hidden" name="fee_given" value="">
                            <button name="student_month_fee_btn" value="1" class="btn btn-sm btn-outline-warning">Reset</button>
                        </form>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light"><tr><th>Total</th><th></th><th class="text-success"><?= money($tot_paid) ?></th><th class="text-danger"><?= money($tot_due) ?></th><th colspan="2"></th></tr></tfoot>
        </table></div>
    </div>
    <div class="card card-custom p-3 mt-4">
        <h5 class="fw-bold mb-1"><i class="fa-solid fa-receipt text-success me-2"></i>Payments received</h5>
        <p class="small text-muted">Each payment is listed separately with its date. Paid by mistake? Delete it here.</p>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>For month</th><th>Amount</th><th>Date received</th><th>Paid by</th><th>Note</th><th></th></tr></thead>
            <tbody>
            <?php if (!$vs_payments): ?><tr><td colspan="6" class="text-center text-muted">No payments recorded yet.</td></tr><?php endif; ?>
            <?php foreach ($vs_payments as $p): ?>
                <tr>
                    <td><?= month_label($p['fee_month']) ?></td>
                    <td class="fw-semibold text-success"><?= money($p['amount']) ?></td>
                    <td><?= !empty($p['paid_on']) ? date('d M Y', strtotime($p['paid_on'])) : '—' ?></td>
                    <td><?= htmlspecialchars((string)$p['method']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars((string)($p['note'] ?? '')) ?></td>
                    <td class="text-end">
                        <form method="POST" action="index.php?action=admin_student_view&id=<?= $vsid ?>" class="d-inline" onsubmit="return confirm('Delete this payment of <?= htmlspecialchars(money($p['amount']), ENT_QUOTES) ?>?');">
                            <input type="hidden" name="fee_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="fee_month" value="<?= htmlspecialchars($p['fee_month']) ?>"><input type="hidden" name="return_student" value="<?= $vsid ?>">
                            <button name="delete_fee_btn" class="btn btn-sm btn-outline-danger" title="Delete this payment"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <!-- Receive payment (from the student's ID page) -->
    <div class="modal fade" id="vsPayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="index.php?action=admin_student_view&id=<?= $vsid ?>" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-hand-holding-dollar text-success me-2"></i>Receive Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="fee_student_id" value="<?= $vsid ?>"><input type="hidden" name="return_student" value="<?= $vsid ?>"><input type="hidden" name="fee_month" id="vsPayMonth">
                    <div class="p-3 bg-light rounded mb-3"><div class="fw-bold fs-5"><?= htmlspecialchars($vs['full_name']) ?></div>
                        <div class="small text-muted">Fee month: <b id="vsPayLabel"></b> &middot; Balance now: <b id="vsPayBal"></b></div></div>
                    <label class="form-label fw-semibold">Amount received (<?= htmlspecialchars($currency) ?>)</label>
                    <div class="input-group mb-3">
                        <input type="number" step="0.01" min="1" name="fee_amount" id="vsPayAmount" class="form-control form-control-lg" required>
                        <button type="button" class="btn btn-outline-success" id="vsPayFull">Full balance</button>
                    </div>
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">Date received</label><input type="date" name="fee_paid_on" value="<?= date('Y-m-d') ?>" class="form-control"></div>
                        <div class="col-6"><label class="form-label">Paid by</label><select name="fee_method" class="form-select"><option>Cash</option><option>Bank Transfer</option><option>Online</option><option>Cheque</option></select></div>
                        <div class="col-12"><label class="form-label">Note (optional)</label><input type="text" name="fee_note" maxlength="255" class="form-control" placeholder="e.g. paid by father"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" name="add_fee_btn" class="btn btn-success fw-bold"><i class="fa-solid fa-check me-1"></i>Save Payment</button></div>
            </form>
        </div>
    </div>
    <!-- Change monthly fee -->
    <div class="modal fade" id="vsFeeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="index.php?action=admin_student_view&id=<?= $vsid ?>" class="modal-content">
                <div class="modal-header"><h5 class="modal-title fw-bold">Monthly fee: <?= htmlspecialchars($vs['full_name']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="student_id" value="<?= $vsid ?>"><input type="hidden" name="return_student" value="<?= $vsid ?>">
                    <label class="form-label fw-semibold">Monthly fee (<?= htmlspecialchars($currency) ?>)</label>
                    <input type="number" step="1" min="0" name="monthly_fee" class="form-control form-control-lg" value="<?= (float)$vs_mf ?>" required>
                    <div class="form-text">This one fee applies to <b>all</b> months, including past months. Changing it changes the "Due" shown for previous months.</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button name="set_monthly_fee_btn" class="btn btn-primary fw-bold">Save fee</button></div>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var m = document.getElementById('vsPayModal'); if (!m) return;
        var amt = document.getElementById('vsPayAmount'), due = 0;
        var money = function (n) { return '<?= htmlspecialchars($currency, ENT_QUOTES) ?> ' + Math.round(n).toLocaleString(); };
        m.addEventListener('show.bs.modal', function (e) {
            var b = e.relatedTarget; if (!b) return;
            due = parseFloat(b.getAttribute('data-due')) || 0;
            document.getElementById('vsPayMonth').value = b.getAttribute('data-month');
            document.getElementById('vsPayLabel').textContent = b.getAttribute('data-label');
            document.getElementById('vsPayBal').textContent = money(due);
            amt.value = due > 0 ? due : '';
        });
        m.addEventListener('shown.bs.modal', function () { amt.focus(); amt.select(); });
        document.getElementById('vsPayFull').addEventListener('click', function () { if (due > 0) amt.value = due; });
    })();
    </script>
    <?php
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_student_edit'):
        $db_classes = $pdo->query("SELECT * FROM classes ORDER BY id ASC")->fetchAll();
        $db_fields = $pdo->query("SELECT * FROM fields ORDER BY field_name ASC")->fetchAll();
        $q = $pdo->prepare("SELECT * FROM students WHERE id = ?"); $q->execute([(int)($_GET['id'] ?? 0)]);
        $es = $q->fetch();
        if (!$es) { redirect("index.php?action=admin_dashboard&err=Student not found."); }
        $ev = function ($k) use ($es) { return htmlspecialchars((string)($es[$k] ?? '')); };
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="fw-bold mb-0"><i class="fa-solid fa-user-pen text-primary me-2"></i>Edit Student — <?= $ev('full_name') ?></h2>
        <a href="index.php?action=admin_dashboard" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
    </div>
    <div class="card card-custom p-4" style="max-width:820px">
        <form method="POST" action="index.php?action=admin_student_edit&id=<?= (int)$es['id'] ?>">
            <input type="hidden" name="student_id" value="<?= (int)$es['id'] ?>">
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label small mb-1">Full Name</label><input type="text" name="std_fullname" class="form-control" value="<?= $ev('full_name') ?>" required></div>
                <div class="col-md-6"><label class="form-label small mb-1">Username (cannot be changed)</label><input type="text" class="form-control" value="<?= $ev('username') ?>" disabled></div>
                <div class="col-md-6"><label class="form-label small mb-1">New Password (leave empty to keep current)</label><input type="text" name="std_password" class="form-control" placeholder="unchanged"></div>
                <div class="col-md-3"><label class="form-label small mb-1">Class</label>
                    <select name="std_class" class="form-select"><?php $eopts = array_column($db_classes, 'class_name'); if (!in_array('12th Pass', $eopts, true)) $eopts[] = '12th Pass'; if (!in_array($es['class'], $eopts, true)) $eopts[] = $es['class']; foreach ($eopts as $co): ?><option <?= $es['class'] === $co ? 'selected' : '' ?>><?= htmlspecialchars($co) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label small mb-1">Stream</label>
                    <select name="std_stream" class="form-select"><?php foreach ($db_fields as $f): ?><option value="<?= htmlspecialchars($f['field_name']) ?>" <?= $es['stream'] === $f['field_name'] ? 'selected' : '' ?>><?= htmlspecialchars($f['field_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label small mb-1">Gender</label>
                    <select name="std_gender" class="form-select"><option value="Female" <?= $es['gender'] === 'Female' ? 'selected' : '' ?>>Female</option><option value="Male" <?= $es['gender'] === 'Male' ? 'selected' : '' ?>>Male</option></select></div>
                <?= student_extra_inputs($es) ?>
            </div>
            <button type="submit" name="update_student_details_btn" class="btn btn-primary fw-bold mt-4"><i class="fa-solid fa-floppy-disk me-1"></i>Save Student Details</button>
        </form>
    </div>
    <?php
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_teachers'):
        $all_teachers = $pdo->query("SELECT * FROM site_teachers ORDER BY id DESC")->fetchAll();
        $et = null;
        if (isset($_GET['edit'])) { $q = $pdo->prepare("SELECT * FROM site_teachers WHERE id = ?"); $q->execute([(int)$_GET['edit']]); $et = $q->fetch() ?: null; }
        $v = function ($k) use ($et) { return htmlspecialchars((string)($et[$k] ?? '')); };
    ?>
    <style>.t-avatar{width:100%;height:100%;object-fit:cover;display:block}.t-bob{animation:tbob 3s ease-in-out infinite;transform-origin:50% 100%}.t-eyes{animation:tblink 4s infinite;transform-origin:50px 50px}@keyframes tbob{0%,100%{transform:translateY(0)}50%{transform:translateY(-2.5px)}}@keyframes tblink{0%,92%,100%{transform:scaleY(1)}96%{transform:scaleY(.1)}}.t-thumb{width:64px;height:64px;border-radius:50%;overflow:hidden;flex:none;border:3px solid #e0e1ff}</style>
    <h2 class="fw-bold mb-4"><i class="fa-solid fa-chalkboard-user text-primary me-2"></i>Teachers</h2>
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card card-custom p-4">
                <h5 class="fw-bold mb-3"><?= $et ? 'Edit Teacher' : 'Add Teacher' ?></h5>
                <p class="text-muted small">Teachers added here appear on the public website. If no photo is uploaded, an animated avatar is used based on gender.</p>
                <form method="POST" action="index.php?action=admin_teachers" enctype="multipart/form-data">
                    <input type="hidden" name="teacher_id" value="<?= $et ? (int)$et['id'] : 0 ?>">
                    <div class="mb-2"><label class="form-label">Full name *</label><input name="t_name" class="form-control" maxlength="120" required value="<?= $v('full_name') ?>"></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label">Gender</label>
                            <select name="t_gender" class="form-select"><?php foreach (['Male','Female','Other'] as $g): ?><option <?= ($et['gender'] ?? 'Male') === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?></select></div>
                        <div class="col-6"><label class="form-label">Grade / Class</label><input name="t_grade" class="form-control" placeholder="e.g. 9th - 12th" value="<?= $v('grade') ?>"></div>
                    </div>
                    <div class="mb-2"><label class="form-label">Subject</label><input name="t_subject" class="form-control" value="<?= $v('subject') ?>"></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label">Qualification</label><input name="t_qual" class="form-control" value="<?= $v('qualification') ?>"></div>
                        <div class="col-6"><label class="form-label">Experience</label><input name="t_exp" class="form-control" placeholder="e.g. 5 years" value="<?= $v('experience') ?>"></div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label">Phone</label><input name="t_phone" class="form-control" value="<?= $v('phone') ?>"></div>
                        <div class="col-6"><label class="form-label">Email</label><input type="email" name="t_email" class="form-control" value="<?= $v('email') ?>"></div>
                    </div>
                    <div class="mb-2"><label class="form-label">About</label><textarea name="t_bio" rows="3" class="form-control"><?= $v('bio') ?></textarea></div>
                    
                    <button name="save_teacher_btn" class="btn btn-primary fw-bold w-100"><i class="fa-solid fa-floppy-disk me-1"></i>Save Teacher</button>
                    <?php if ($et): ?><a href="index.php?action=admin_teachers" class="btn btn-link w-100">Cancel edit</a><?php endif; ?>
                </form>
            </div>
        </div>
        <div class="col-lg-7">
            <?php if (!$all_teachers): ?><div class="alert alert-info">No teachers added yet.</div><?php endif; ?>
            <?php foreach ($all_teachers as $t): ?>
            <div class="card card-custom p-3 mb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="flex-grow-1">
                        <h6 class="fw-bold mb-0"><?= htmlspecialchars($t['full_name']) ?> <span class="badge bg-light text-dark border"><?= htmlspecialchars($t['gender']) ?></span></h6>
                        <div class="small text-muted"><?= htmlspecialchars(trim(($t['subject'] ?? '') . ($t['grade'] ? ' • ' . $t['grade'] : ''))) ?></div>
                    </div>
                    <a href="index.php?action=admin_teachers&edit=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i></a>
                    <form method="POST" action="index.php?action=admin_teachers" onsubmit="return confirm('Remove this teacher?');">
                        <input type="hidden" name="teacher_id" value="<?= (int)$t['id'] ?>">
                        <button name="delete_teacher_btn" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_notices'):
        $all_notices = $pdo->query("SELECT * FROM announcements ORDER BY id DESC")->fetchAll();
    ?>
    <h2 class="fw-bold mb-4"><i class="fa-solid fa-bullhorn text-warning me-2"></i>Notices & Announcements</h2>
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card card-custom p-4">
                <h5 class="fw-bold mb-3">Publish a Notice</h5>
                <p class="text-muted small">Notices appear on the public website and on every student's dashboard.</p>
                <form method="POST" action="index.php?action=admin_notices">
                    <div class="mb-3"><label class="form-label">Title</label><input type="text" name="notice_title" maxlength="150" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Message</label><textarea name="notice_body" rows="5" class="form-control" required></textarea></div>
                    <button name="add_notice_btn" class="btn btn-warning fw-bold w-100"><i class="fa-solid fa-paper-plane me-1"></i>Publish</button>
                </form>
            </div>
        </div>
        <div class="col-lg-7">
            <?php if (!$all_notices): ?><div class="alert alert-info">No notices yet.</div><?php endif; ?>
            <?php foreach ($all_notices as $n): ?>
            <div class="card card-custom p-3 mb-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="fw-bold mb-0"><?= htmlspecialchars($n['title']) ?></h6>
                        <div class="small text-muted mb-2"><?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></div>
                        <div><?= nl2br(htmlspecialchars($n['body'])) ?></div>
                    </div>
                    <form method="POST" action="index.php?action=admin_notices" onsubmit="return confirm('Delete this notice?');">
                        <input type="hidden" name="notice_id" value="<?= $n['id'] ?>">
                        <button name="delete_notice_btn" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    // ADMIN: EXPENSES
    elseif ($_SESSION['role'] === 'admin' && $action === 'admin_expenses'):
        expenses_ensure($pdo);
        $exp_selected = isset($_GET['exp_month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['exp_month']);
        $exp_month = $exp_selected ? $_GET['exp_month'] : date('Y-m');
        $exp_months = [date('Y-m') => true];
        foreach ($pdo->query("SELECT DISTINCT expense_month FROM expenses")->fetchAll(PDO::FETCH_COLUMN) as $em) { $exp_months[$em] = true; }
        $exp_months = array_keys($exp_months); rsort($exp_months);
        $exp_rows = []; $exp_total = 0;
        if ($exp_selected) {
            $q = $pdo->prepare("SELECT * FROM expenses WHERE expense_month = ? ORDER BY id DESC");
            $q->execute([$exp_month]);
            $exp_rows = $q->fetchAll();
            $exp_total = array_sum(array_column($exp_rows, 'amount'));
        }
        $exp_all_total = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses")->fetchColumn();
    ?>
    <h2 class="fw-bold mb-4"><i class="fa-solid fa-receipt text-danger me-2"></i>Expenses</h2>

    <div class="card card-custom p-4 mb-4">
        <h5 class="fw-bold mb-3">Add an Expense</h5>
        <form method="POST" action="index.php?action=admin_expenses" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Expense (write anything)</label>
                <input type="text" name="exp_title" list="expSuggest" maxlength="150" class="form-control" placeholder="e.g. Electricity bill, Gas bill" required>
                <datalist id="expSuggest">
                    <option value="Electricity bill"><option value="Gas bill"><option value="Water bill"><option value="Internet bill"><option value="Rent"><option value="Stationery"><option value="Maintenance">
                </datalist>
            </div>
            <div class="col-md-2"><label class="form-label">Amount</label><input type="number" step="0.01" min="0.01" name="exp_amount" class="form-control" required></div>
            <div class="col-md-3"><label class="form-label">Month of payment</label><input type="month" name="exp_month" value="<?= htmlspecialchars($exp_month) ?>" class="form-control" required></div>
            <div class="col-md-3"><label class="form-label">Note (optional)</label><input type="text" name="exp_note" maxlength="255" class="form-control"></div>
            <div class="col-12"><button type="submit" name="add_expense_btn" class="btn btn-danger fw-bold"><i class="fa-solid fa-plus me-1"></i>Save Expense</button></div>
        </form>
    </div>

    <div class="card card-custom p-4" id="expList">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h5 class="fw-bold mb-0">View Expenses by Month</h5>
            <form method="GET" action="index.php" class="d-flex gap-2">
                <input type="hidden" name="action" value="admin_expenses">
                <select name="exp_month" class="form-select" onchange="this.form.submit()">
                    <option value="">— Select month —</option>
                    <?php foreach ($exp_months as $em): ?>
                        <option value="<?= htmlspecialchars($em) ?>" <?= ($exp_selected && $em === $exp_month) ? 'selected' : '' ?>><?= htmlspecialchars(month_label($em)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-dark">View</button>
            </form>
        </div>
        <?php if (!$exp_selected): ?>
            <div class="alert alert-info mb-0"><i class="fa-solid fa-circle-info me-1"></i>Select a month from the list to see that month's expenses. All-time expenses: <strong><?= money($exp_all_total) ?></strong></div>
        <?php else: ?>
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                <div class="fw-semibold"><?= month_label($exp_month) ?> — <?= count($exp_rows) ?> expense(s)</div>
                <span class="badge bg-danger fs-6">Total <?= money($exp_total) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Expense</th><th>Amount</th><th>Note</th><th>Added</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$exp_rows): ?><tr><td colspan="5" class="text-center text-muted">No expenses recorded for this month.</td></tr><?php endif; ?>
                    <?php foreach ($exp_rows as $er): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($er['title']) ?></td>
                        <td class="text-danger fw-semibold"><?= money($er['amount']) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($er['note'] ?? '') ?></td>
                        <td class="small text-muted"><?= date('d M Y', strtotime($er['created_at'])) ?></td>
                        <td>
                            <form method="POST" action="index.php?action=admin_expenses" onsubmit="return confirm('Delete this expense?');">
                                <input type="hidden" name="exp_id" value="<?= (int)$er['id'] ?>">
                                <input type="hidden" name="exp_month" value="<?= htmlspecialchars($exp_month) ?>">
                                <button name="delete_expense_btn" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php
    // STUDENT: MY FEES
    elseif ($_SESSION['role'] === 'student' && $action === 'student_fees'):
        $sid = (int)$_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT monthly_fee FROM students WHERE id = ?");
        $stmt->execute([$sid]);
        $my_fee = (float)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT * FROM fees WHERE student_id = ? ORDER BY fee_month DESC, paid_on DESC, id DESC");
        $stmt->execute([$sid]);
        $my_payments = $stmt->fetchAll();
        $by_month = [];
        foreach ($my_payments as $p) { $by_month[$p['fee_month']] = ($by_month[$p['fee_month']] ?? 0) + (float)$p['amount']; }
        $my_given = $pdo->prepare("SELECT fee_month, given FROM fee_given WHERE student_id = ?");
        $my_given->execute([$sid]);
        $my_free_months = [];
        foreach ($my_given->fetchAll() as $g) {
            if (strcasecmp(trim($g['given']), 'free') === 0) { $my_free_months[$g['fee_month']] = true; unset($by_month[$g['fee_month']]); }
            elseif (is_numeric($g['given'])) { $by_month[$g['fee_month']] = (float)$g['given']; }
        }
        $my_total = array_sum($by_month);
    ?>
    <h2 class="fw-bold mb-4"><i class="fa-solid fa-wallet text-success me-2"></i>My Fee Record</h2>
    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card card-custom p-3"><div class="text-muted small">Monthly Fee</div><div class="fs-4 fw-bold"><?= $my_fee > 0 ? money($my_fee) : 'Not set' ?></div></div></div>
        <div class="col-md-4"><div class="card card-custom p-3"><div class="text-muted small">Total Paid</div><div class="fs-4 fw-bold text-success"><?= money($my_total) ?></div></div></div>
        <div class="col-md-4"><div class="card card-custom p-3"><div class="text-muted small">Months Paid</div><div class="fs-4 fw-bold text-primary"><?= count($by_month) ?></div></div></div>
    </div>

    <?php
        $stS = $pdo->prepare("SELECT admission_date, created_at FROM students WHERE id = ?"); $stS->execute([$sid]);
        $me = $stS->fetch() ?: [];
        $startSrc = !empty($me['admission_date']) ? $me['admission_date'] : ($me['created_at'] ?? date('Y-m-01'));
        $myRawGiven = $pdo->prepare("SELECT fee_month, given FROM fee_given WHERE student_id = ?"); $myRawGiven->execute([$sid]);
        $myGivenMap = $myRawGiven->fetchAll(PDO::FETCH_KEY_PAIR);
        $myRawPaid = $pdo->prepare("SELECT fee_month, SUM(amount) FROM fees WHERE student_id = ? GROUP BY fee_month"); $myRawPaid->execute([$sid]);
        $myPaidMap = $myRawPaid->fetchAll(PDO::FETCH_KEY_PAIR);
        $myMonths = fee_months_for($startSrc, array_merge(array_keys($myPaidMap), array_keys($myGivenMap))); // includes previous months with fee records
        $tPaid = 0; $tDue = 0;
    ?>
    <div class="card card-custom p-4 mb-4">
        <h5 class="fw-bold mb-1"><i class="fa-solid fa-coins text-warning me-2"></i>All Months Fees</h5>
        <p class="small text-muted mb-3">This is a view-only record. Fees are added and changed by the admin only.</p>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Month</th><th>Fee</th><th>Paid</th><th>Due</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($myMonths as $m):
                $g = $myGivenMap[$m] ?? null; $isFree = $g !== null && strcasecmp(trim($g), 'free') === 0;
                $paid = $isFree ? 0 : (($g !== null && is_numeric($g)) ? (float)$g : (float)($myPaidMap[$m] ?? 0));
                $due = ($isFree || $m > date('Y-m')) ? 0 : max(0, $my_fee - $paid);
                if (!$isFree) { $tDue += $due; $tPaid += $paid; }
                if ($isFree) { $bd = ['info', 'Free']; } elseif ($m > date('Y-m')) { $bd = ['info', 'Advance']; } elseif ($my_fee > 0 && $paid >= $my_fee) { $bd = ['success', 'Paid']; } elseif ($paid > 0) { $bd = ['warning', 'Partial']; } else { $bd = ['danger', $my_fee > 0 ? 'Unpaid' : 'Fee not set']; }
            ?>
                <tr>
                    <td class="fw-semibold"><?= month_label($m) ?></td>
                    <td><?= $isFree ? '—' : money($my_fee) ?></td>
                    <td class="text-success fw-semibold"><?= $isFree ? 'Free' : money($paid) ?></td>
                    <td class="text-danger fw-semibold"><?= money($due) ?></td>
                    <td><span class="badge bg-<?= $bd[0] ?> <?= $bd[0] === 'warning' ? 'text-dark' : '' ?>"><?= $bd[1] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light"><tr><th>Total</th><th></th><th class="text-success"><?= money($tPaid) ?></th><th class="text-danger"><?= money($tDue) ?></th><th></th></tr></tfoot>
        </table></div>
    </div>

    <div class="card card-custom p-4">
        <h5 class="fw-bold mb-3">Payment History</h5>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="table-light"><tr><th>For Month</th><th>Amount</th><th>Paid On</th><th>Method</th><th>Note</th></tr></thead>
                <tbody>
                <?php if (!$my_payments): ?><tr><td colspan="5" class="text-center text-muted">No payments recorded yet.</td></tr><?php endif; ?>
                <?php foreach ($my_payments as $p): ?>
                <tr>
                    <td><?= month_label($p['fee_month']) ?></td>
                    <td class="fw-semibold text-success"><?= money($p['amount']) ?></td>
                    <td><?= date('d M Y', strtotime($p['paid_on'])) ?></td>
                    <td><?= htmlspecialchars($p['method']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($p['note'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    // VIEW RESULT & REVIEW
    elseif ($action === 'view_result' && isset($_SESSION['user_id'])):
        $test_record_id = (int)($_GET['test_id'] ?? 0);
        $user_id = $_SESSION['user_id'];
        $role = $_SESSION['role'];

        $stmt = $pdo->prepare("SELECT st.*, s.full_name, s.class, s.stream, s.gender, tm.test_name, COALESCE(tm.passing_percentage, 50) AS pass_mark FROM student_tests st JOIN students s ON st.student_id = s.id LEFT JOIN tests_meta tm ON st.test_id = tm.id WHERE st.id = ?");
        $stmt->execute([$test_record_id]);
        $test_record = $stmt->fetch();

        if (!$test_record || ($role === 'student' && $test_record['student_id'] != $user_id)) {
            redirect("index.php?action=student_dashboard&err=Result not found.");
        }

        $stmtAns = $pdo->prepare("SELECT ta.*, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, q.subject FROM test_answers ta JOIN questions q ON ta.question_id = q.id WHERE ta.test_id = ? ORDER BY CASE LOWER(TRIM(q.subject)) WHEN 'math' THEN 1 WHEN 'mathematics' THEN 1 WHEN 'physics' THEN 2 WHEN 'chemistry' THEN 3 WHEN 'biology' THEN 4 WHEN 'english' THEN 5 ELSE 100 END, LOWER(TRIM(q.subject)), q.id ASC");
        $stmtAns->execute([$test_record_id]);
        $answers_detail = $stmtAns->fetchAll();
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card card-custom p-4 mb-4 text-center">
                <div class="mb-3">
                    <div class="bg-success text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 70px; height: 70px;">
                        <i class="fa-solid fa-award fa-2x"></i>
                    </div>
                </div>
                <h2 class="fw-bold mb-1">Test Result Summary</h2>
                <?php if (!empty($test_record['test_name'])): ?>
                    <h5 class="text-primary fw-bold mb-2"><?= htmlspecialchars($test_record['test_name']) ?></h5>
                <?php endif; ?>
                <p class="text-muted mb-3">Candidate: <strong><?= htmlspecialchars($test_record['full_name']) ?></strong> | Class: <?= htmlspecialchars($test_record['class']) ?> (<?= htmlspecialchars($test_record['stream']) ?>)</p>
                
                <div class="row g-3 justify-content-center my-2">
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded">
                            <span class="text-muted d-block small">Score</span>
                            <h3 class="fw-bold text-dark mb-0"><?= $test_record['correct_answers'] ?> / <?= $test_record['total_questions'] ?></h3>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded">
                            <span class="text-muted d-block small">Percentage</span>
                            <h3 class="fw-bold <?= $test_record['score_percentage'] >= (float)($test_record['pass_mark'] ?? 50) ? 'text-success' : 'text-danger' ?> mb-0"><?= $test_record['score_percentage'] ?>%</h3>
                            <span class="badge <?= $test_record['score_percentage'] >= (float)($test_record['pass_mark'] ?? 50) ? 'bg-success' : 'bg-danger' ?> mt-2"><?= $test_record['score_percentage'] >= (float)($test_record['pass_mark'] ?? 50) ? 'PASS' : 'FAIL' ?></span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded">
                            <span class="text-muted d-block small">Time Taken</span>
                            <h3 class="fw-bold text-dark mb-0"><?= floor($test_record['time_taken_seconds'] / 60) ?>m <?= $test_record['time_taken_seconds'] % 60 ?>s</h3>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <?php if ($role === 'admin'): ?>
                        <a href="index.php?action=admin_dashboard" class="btn btn-primary fw-bold">
                            <i class="fa-solid fa-arrow-left me-1"></i> Back to Admin Dashboard
                        </a>
                    <?php else: ?>
                        <a href="index.php?action=student_dashboard" class="btn btn-primary fw-bold">
                            <i class="fa-solid fa-arrow-left me-1"></i> Back to Student Dashboard
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <h4 class="fw-bold mb-3"><i class="fa-solid fa-clipboard-question me-2"></i>Detailed Question Review</h4>
            <?php 
            $rev_no = 1;
            foreach ($answers_detail as $ans): 
                $is_correct = $ans['is_correct'];
            ?>
            <div class="card card-custom p-4 mb-3 border-start border-4 <?= $is_correct ? 'border-success' : 'border-danger' ?>">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h5 class="fw-bold text-dark">Question <?= $rev_no++ ?></h5>
                    <div>
                        <span class="badge bg-secondary me-1"><?= htmlspecialchars($ans['subject']) ?></span>
                        <span class="badge <?= $is_correct ? 'bg-success' : 'bg-danger' ?>">
                            <?= $is_correct ? 'Correct' : 'Incorrect' ?>
                        </span>
                    </div>
                </div>
                <p class="fs-6 mb-3 text-dark"><?= nl2br(htmlspecialchars($ans['question_text'])) ?></p>

                <div class="row g-2 mb-3">
                    <?php foreach (['A' => $ans['option_a'], 'B' => $ans['option_b'], 'C' => $ans['option_c'], 'D' => $ans['option_d']] as $k => $v): 
                        $badge_class = "";
                        if ($k === $ans['correct_option']) {
                            $badge_class = "bg-success text-white fw-bold";
                        } elseif ($k === $ans['selected_option'] && !$is_correct) {
                            $badge_class = "bg-danger text-white fw-bold";
                        }
                    ?>
                    <div class="col-md-6">
                        <div class="p-2 border rounded <?= $badge_class ?>" style="background-color: <?= empty($badge_class) ? '#f8fafc' : '' ?>">
                            <strong><?= $k ?>.</strong> <?= htmlspecialchars($v) ?>
                            <?php if ($k === $ans['correct_option']): ?>
                                <span class="float-end small text-white"><i class="fa-solid fa-check"></i> Correct Answer</span>
                            <?php elseif ($k === $ans['selected_option'] && !$is_correct): ?>
                                <span class="float-end small text-white"><i class="fa-solid fa-xmark"></i> Your Answer</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="small text-muted">
                    Your Answer: <strong><?= $ans['selected_option'] ? $ans['selected_option'] : 'Not Answered' ?></strong> | 
                    Correct Answer: <strong class="text-success"><?= $ans['correct_option'] ?></strong>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php endif; ?>

</div>

<?php if ($is_landing): ?>
<footer class="lp-footer">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-4">
                <a class="brand-logo mb-3" href="index.php" style="display:inline-flex"><span class="mark"><img src="img/s-orbit-logo.png" alt="" class="brand-logo-img"></span><div>S-ORBIT <span class="gold">TUITION</span></div></a>
                <p style="line-height:1.8;max-width:340px">Expert teaching, timed MCQ tests, instant results and transparent fee records. Everything a student needs to grow, in one place.</p>
            </div>
            <div class="col-6 col-lg-2">
                <h6>Explore</h6>
                <a href="#about">About</a><a href="#features">Features</a><a href="#courses">Courses</a><a href="#portal">Student Portal</a>
            </div>
            <div class="col-6 col-lg-2">
                <h6>Help</h6>
                <a href="#notices">Notices</a><a href="#faq">FAQ</a><a href="#contact">Contact</a><a href="index.php?action=login">Login</a>
            </div>
            <div class="col-lg-4">
                <h6>Get in touch</h6>
                <p class="mb-2"><i class="fa-solid fa-phone me-2" style="color:#fbbf24"></i><?= htmlspecialchars(implode(' / ', $site_phones)) ?></p>
                <p class="mb-2"><i class="fa-solid fa-envelope me-2" style="color:#fbbf24"></i><?= htmlspecialchars($site['email']) ?></p>
                <p class="mb-0"><i class="fa-solid fa-location-dot me-2" style="color:#fbbf24"></i><?= htmlspecialchars($site['address']) ?></p>
            </div>
        </div>
        <div class="f-bottom d-flex flex-wrap justify-content-between gap-2">
            <span>&copy; <?= date('Y') ?> S-Orbit Tuition. All rights reserved.</span>
            <span class="studio-credit">Created By WebCraft Studios </span>
        </div>
    </div>
</footer>
<button class="to-top" id="toTop" aria-label="Back to top"><i class="fa-solid fa-arrow-up"></i></button>
<?php else: ?>
<footer class="footer text-center">
    <div class="container">
        <p class="mb-0 small">&copy; <?= date('Y') ?> S-Orbit Tuition. All rights reserved.</p>
    </div>
</footer>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  var nav=document.getElementById('siteNav'),prog=document.getElementById('scrollProg'),top=document.getElementById('toTop');
  function onScroll(){
    var y=window.scrollY||document.documentElement.scrollTop,h=document.documentElement.scrollHeight-window.innerHeight;
    if(nav)nav.classList.toggle('scrolled',y>30);
    if(prog)prog.style.width=(h>0?y/h*100:0)+'%';
    if(top)top.classList.toggle('show',y>600);
  }
  window.addEventListener('scroll',onScroll,{passive:true});onScroll();
  if(top)top.addEventListener('click',function(){window.scrollTo({top:0,behavior:'smooth'})});
  var els=document.querySelectorAll('.reveal');
  if('IntersectionObserver' in window){
    var io=new IntersectionObserver(function(en){en.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target)}})},{threshold:.12});
    els.forEach(function(el){io.observe(el)});
  }else{els.forEach(function(el){el.classList.add('in')})}
  var counters=document.querySelectorAll('[data-count]');
  if(counters.length&&'IntersectionObserver' in window){
    var co=new IntersectionObserver(function(en){en.forEach(function(e){
      if(!e.isIntersecting)return;var el=e.target,end=parseInt(el.getAttribute('data-count'),10)||0,t0=null,dur=1600;
      function step(t){if(!t0)t0=t;var p=Math.min((t-t0)/dur,1),v=Math.floor(end*(1-Math.pow(1-p,3)));el.textContent=v.toLocaleString();if(p<1)requestAnimationFrame(step);else el.textContent=end.toLocaleString()+(el.getAttribute('data-plus')?'+':'')}
      requestAnimationFrame(step);co.unobserve(el)})},{threshold:.4});
    counters.forEach(function(c){co.observe(c)});
  }
  var fl=document.querySelector('.flash-float');if(fl)setTimeout(function(){fl.style.display='none'},8000);
})();
</script>
</body>
</html>