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
        (4, 'Ananya Singh', 'BCOM202601', 'ananya.singh@example.com', '9765432109', 3),
        (5, 'Amar Singh', 'BCA202401', 'amar.singhbca24@smslucknow.ac.in', '9876543210', 1)");

    $pdo->exec("INSERT OR IGNORE INTO fees (id, student_id, amount, status, transaction_id, payment_mode, payment_date, remarks) VALUES
        (1, 1, 24500.00, 'Pending', NULL, 'Pending Payment', NULL, 'Semester 5 Tuition Fee'),
        (2, 2, 24500.00, 'Paid', 'TXN-2026-984321', 'UPI (Google Pay)', datetime('now'), 'Semester 5 Tuition Fee'),
        (3, 3, 31000.00, 'Pending', NULL, 'Pending Payment', NULL, 'Semester 3 Management Fee'),
        (4, 4, 18500.00, 'Paid', 'TXN-2026-773412', 'Net Banking HDFC', datetime('now'), 'Semester 1 Admission Fee'),
        (5, 2, 2500.00, 'Pending', NULL, 'Pending Payment', NULL, 'Semester Examination Fee'),
        (6, 5, 2500.00, 'Pending', NULL, 'Pending Payment', NULL, 'Semester Examination Fee')");

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
        (4, date('now'), 'Present', 'Regular Class'),
        (5, date('now'), 'Present', 'Regular Class'),
        (5, date('now', '-1 day'), 'Present', 'Regular Class'),
        (5, date('now', '-2 day'), 'Present', 'Lab Practical')");
}

/**
 * Send Payment Receipt Email Notification
 * Dispatches an official HTML receipt to the student's email,
 * and maintains amar.singhbca24@smslucknow.ac.in as official dispatcher and audit copy.
 * 
 * @param array $receipt Array containing student and transaction details
 * @return array Status of email dispatch ['success' => bool, 'message' => string, 'email' => string, 'dispatcher' => string]
 */
function send_payment_receipt_email(array $receipt) {
    $dispatcherEmail = 'amar.singhbca24@smslucknow.ac.in';
    $to = trim($receipt['email'] ?? '');
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $to = $dispatcherEmail;
    }

    $studentName = $receipt['student_name'] ?? 'Student';
    $rollNo = $receipt['roll_no'] ?? 'N/A';
    $branchName = $receipt['branch_name'] ?? 'Computer Applications & IT';
    $amount = number_format((float)($receipt['amount'] ?? 0), 2);
    $txnId = $receipt['transaction_id'] ?? ('TXN-' . date('Ymd'));
    $purpose = $receipt['remarks'] ?: 'College Fee Payment';
    $paymentMode = $receipt['payment_mode'] ?: 'Online Gateway';
    $dateStr = !empty($receipt['payment_date']) ? date('d M Y, h:i A', strtotime($receipt['payment_date'])) : date('d M Y, h:i A');

    $subject = "Official Fee Receipt [{$txnId}] - School of Management Sciences Lucknow (SMS)";

    // Responsive HTML Email Template
    $body = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment Receipt</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 20px; color: #0f172a; }
    .email-container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
    .email-header { background: linear-gradient(135deg, #090e17 0%, #1e3a8a 100%); color: #ffffff; padding: 32px 24px; text-align: center; }
    .email-header h1 { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: 0.5px; }
    .email-header p { margin: 6px 0 0; font-size: 13px; color: #93c5fd; }
    .email-body { padding: 30px 24px; }
    .badge-success { display: inline-block; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 6px 14px; border-radius: 9999px; font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 16px; }
    .amount-banner { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 20px; text-align: center; margin: 20px 0; }
    .amount-banner small { font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 700; }
    .amount-banner h2 { font-size: 32px; color: #10b981; margin: 4px 0 0; }
    .details-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    .details-table td { padding: 10px 8px; border-bottom: 1px solid #f1f5f9; font-size: 13.5px; }
    .details-table td.label { color: #64748b; font-weight: 600; width: 40%; }
    .details-table td.val { color: #0f172a; font-weight: 700; text-align: right; }
    .email-footer { background: #f8fafc; padding: 20px 24px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; line-height: 1.6; }
  </style>
</head>
<body>
  <div class="email-container">
    <div class="email-header">
      <h1>School of Management Sciences (SMS Lucknow)</h1>
      <p>Institutional Digital Fee Payment Gateway</p>
    </div>
    <div class="email-body">
      <div style="text-align: center;">
        <span class="badge-success">✓ Payment Verified & Received</span>
        <h2 style="font-size: 20px; margin: 0 0 6px;">Dear ' . htmlspecialchars($studentName) . ',</h2>
        <p style="color: #64748b; font-size: 14px; margin: 0;">We have received your payment. Here is your official e-receipt confirmation:</p>
      </div>

      <div class="amount-banner">
        <small>Total Amount Received</small>
        <h2>₹' . $amount . '</h2>
        <span style="font-size: 13px; color: #2563eb; font-weight: 600;">' . htmlspecialchars($purpose) . '</span>
      </div>

      <table class="details-table">
        <tr>
          <td class="label">Transaction Ref:</td>
          <td class="val"><code style="background: #eff6ff; color: #2563eb; padding: 2px 6px; border-radius: 4px;">' . htmlspecialchars($txnId) . '</code></td>
        </tr>
        <tr>
          <td class="label">Student Name:</td>
          <td class="val">' . htmlspecialchars($studentName) . '</td>
        </tr>
        <tr>
          <td class="label">Roll Number:</td>
          <td class="val">' . htmlspecialchars($rollNo) . '</td>
        </tr>
        <tr>
          <td class="label">Department / Branch:</td>
          <td class="val">' . htmlspecialchars($branchName) . '</td>
        </tr>
        <tr>
          <td class="label">Payment Purpose:</td>
          <td class="val">' . htmlspecialchars($purpose) . '</td>
        </tr>
        <tr>
          <td class="label">Payment Mode:</td>
          <td class="val">' . htmlspecialchars($paymentMode) . '</td>
        </tr>
        <tr>
          <td class="label">Date & Time:</td>
          <td class="val">' . htmlspecialchars($dateStr) . '</td>
        </tr>
        <tr>
          <td class="label">Payment Status:</td>
          <td class="val" style="color: #10b981;">PAID & VERIFIED</td>
        </tr>
      </table>

      <div style="margin-top: 25px; padding: 14px; background: #eff6ff; border-radius: 8px; font-size: 12.5px; color: #1e40af; text-align: center;">
        📌 Please retain this email and transaction ID for academic records and examination admit card verification.
      </div>
    </div>
    <div class="email-footer">
      <strong>School of Management Sciences (SMS Lucknow)</strong><br>
      Campus Accounts & Finance Division • Dispatched via: ' . htmlspecialchars($dispatcherEmail) . '<br>
      This is an automated institutional transaction notification.
    </div>
  </div>
</body>
</html>';

    // MIME Headers
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $headers[] = 'From: SMS Lucknow Accounts Portal <' . $dispatcherEmail . '>';
    $headers[] = 'Reply-To: ' . $dispatcherEmail;
    if (strtolower($to) !== strtolower($dispatcherEmail)) {
        $headers[] = 'Bcc: ' . $dispatcherEmail;
    }
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    $headerStr = implode("\r\n", $headers);
    $sent = @mail($to, $subject, $body, $headerStr);

    // If student email is distinct, also deliver directly to dispatcher copy
    if (strtolower($to) !== strtolower($dispatcherEmail)) {
        @mail($dispatcherEmail, "[Audit Copy] " . $subject, $body, $headerStr);
    }

    // Save dispatch record in session for instant UI notification and preview
    $_SESSION['last_sent_receipt_email'] = [
        'recipient' => $to,
        'dispatcher' => $dispatcherEmail,
        'subject' => $subject,
        'txn_id' => $txnId,
        'amount' => $amount,
        'purpose' => $purpose,
        'body' => $body,
        'dispatched_at' => date('Y-m-d H:i:s'),
        'mail_sent' => (bool)$sent
    ];

    return [
        'success' => true,
        'sent' => (bool)$sent,
        'email' => $to,
        'dispatcher' => $dispatcherEmail,
        'subject' => $subject
    ];
}


