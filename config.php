<?php
// Smart Database Configuration: Supports MySQL (Localhost/Cloud) & Auto SQLite Fallback (Vercel)

// Suppress deprecation notices on modern PHP runtimes (PHP 8.5+)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
@ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'college_website');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

function db() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    // Attempt 1: Try MySQL (Localhost XAMPP or Remote Cloud MySQL)
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 2
        ]);
        return $pdo;
    } catch (Throwable $e) {
        // If MySQL fails (e.g. deployed to Vercel without remote MySQL configured), fallback to SQLite
    }

    // Attempt 2: Fallback to SQLite (Guaranteed to work on Vercel & serverless environments)
    try {
        $tmpDir = sys_get_temp_dir();
        $dbPath = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'college_website.db';
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        // Register MySQL NOW() compatibility function in SQLite (PHP 7.4 - 8.5+ compatible)
        if (method_exists($pdo, 'createFunction')) {
            $pdo->createFunction('NOW', function() {
                return date('Y-m-d H:i:s');
            });
        } elseif (method_exists($pdo, 'sqliteCreateFunction')) {
            @$pdo->sqliteCreateFunction('NOW', function() {
                return date('Y-m-d H:i:s');
            });
        }

        // Initialize schema and seed data if needed
        init_sqlite_tables($pdo);
        return $pdo;
    } catch (Throwable $ex) {
        throw new Exception("Database connection failed: " . $ex->getMessage());
    }
}

function init_sqlite_tables(PDO $pdo) {
    $hasTables = $pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='students'")->fetchColumn();
    if ($hasTables > 0) {
        return;
    }

    $schema = "
    CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username VARCHAR(60) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL
    );

    CREATE TABLE IF NOT EXISTS branches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_name VARCHAR(120) NOT NULL
    );

    CREATE TABLE IF NOT EXISTS students (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_name VARCHAR(120) NOT NULL,
        roll_no VARCHAR(60) UNIQUE NOT NULL,
        email VARCHAR(150),
        phone VARCHAR(30),
        branch_id INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS fees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        transaction_id VARCHAR(100),
        payment_mode VARCHAR(50) DEFAULT 'Online UPI',
        payment_date DATETIME,
        remarks VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS admissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        application_no VARCHAR(60) UNIQUE NOT NULL,
        full_name VARCHAR(120) NOT NULL,
        email VARCHAR(150) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        course_name VARCHAR(120) NOT NULL,
        qualification VARCHAR(100) NOT NULL,
        score_percent DECIMAL(5,2) NOT NULL,
        address TEXT,
        status VARCHAR(30) NOT NULL DEFAULT 'Under Review',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS inquiries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(150) NOT NULL,
        phone VARCHAR(30),
        subject VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'New',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS attendance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        attendance_date DATE NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Present',
        remarks VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(student_id, attendance_date)
    );
    ";

    $pdo->exec($schema);

    // Seed Initial Data
    $pdo->exec("INSERT OR IGNORE INTO branches(id, branch_name) VALUES
        (1, 'Computer Applications & IT'),
        (2, 'Management Studies (BBA/MBA)'),
        (3, 'Commerce & Finance'),
        (4, 'Applied Sciences & Biotech')");

    $pdo->exec("INSERT OR IGNORE INTO admins(username, password_hash) VALUES
        ('Amar', '$2y$10\$PhGFbBcQ4aiyyoxlrDIPguh8Ur/89EDthPGQ9lal6FQQlhbJ.65o2')");

    $pdo->exec("INSERT OR IGNORE INTO students (id, student_name, roll_no, email, phone, branch_id) VALUES
        (1, 'Aarav Sharma', 'BCA202601', 'aarav.sharma@example.com', '9876543210', 1),
        (2, 'Priya Patel', 'BCA202602', 'priya.patel@example.com', '9812345678', 1),
        (3, 'Rohan Verma', 'BBA202601', 'rohan.verma@example.com', '9898989898', 2),
        (4, 'Ananya Singh', 'BCOM202601', 'ananya.singh@example.com', '9765432109', 3)");

    $pdo->exec("INSERT OR IGNORE INTO fees (id, student_id, amount, status, transaction_id, payment_mode, payment_date, remarks) VALUES
        (1, 1, 24500.00, 'Pending', NULL, 'Pending Payment', NULL, 'Semester 5 Tuition Fee'),
        (2, 2, 24500.00, 'Paid', 'TXN-2026-984321', 'UPI (Google Pay)', datetime('now'), 'Semester 5 Tuition Fee'),
        (3, 3, 31000.00, 'Pending', NULL, 'Pending Payment', NULL, 'Semester 3 Management Fee'),
        (4, 4, 18500.00, 'Paid', 'TXN-2026-773412', 'Net Banking HDFC', datetime('now'), 'Semester 1 Admission Fee'),
        (5, 2, 2500.00, 'Pending', NULL, 'Pending Payment', NULL, 'Semester Examination Fee')");

    $pdo->exec("INSERT OR IGNORE INTO admissions (id, application_no, full_name, email, phone, course_name, qualification, score_percent, address, status) VALUES
        (1, 'APP-2026-1042', 'Neha Gupta', 'neha.gupta@example.com', '9822334455', 'Bachelor of Computer Applications (BCA)', '12th Science / Math', 88.50, 'Lucknow, Uttar Pradesh', 'Approved'),
        (2, 'APP-2026-1043', 'Vikram Malhotra', 'vikram.m@example.com', '9833445566', 'Master of Business Admin (MBA)', 'Graduation (B.Com)', 79.20, 'Kanpur, Uttar Pradesh', 'Under Review')");

    $pdo->exec("INSERT OR IGNORE INTO inquiries (id, name, email, phone, subject, message, status) VALUES
        (1, 'Rajesh Khanna', 'rajesh.k@example.com', '9844556677', 'Hostel Facility Query', 'Does the college provide campus hostel facilities for first-year outstation students?', 'New')");

    $pdo->exec("INSERT OR IGNORE INTO attendance (student_id, attendance_date, status, remarks) VALUES
        (1, date('now'), 'Present', 'Regular Class'),
        (1, date('now', '-1 day'), 'Present', 'Regular Class'),
        (1, date('now', '-2 day'), 'Present', 'Lab Practical'),
        (1, date('now', '-3 day'), 'Absent', 'Uninformed'),
        (1, date('now', '-4 day'), 'Present', 'Regular Class'),
        (2, date('now'), 'Present', 'Regular Class'),
        (2, date('now', '-1 day'), 'Present', 'Regular Class'),
        (2, date('now', '-2 day'), 'Present', 'Lab Practical'),
        (3, date('now'), 'Absent', 'Absent'),
        (3, date('now', '-1 day'), 'Absent', 'Absent'),
        (4, date('now'), 'Present', 'Regular Class')");
}

