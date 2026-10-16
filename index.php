<?php
require_once __DIR__ . '/config.php';

$pdo = db();
$p = $_GET['page'] ?? 'home';
if ($p === 'payment' || $p === 'fees') {
    $roll = trim($_GET['roll_no'] ?? '');
    if ($roll) {
        header('Location: payment.php?active=search&search_query=' . urlencode($roll));
    } else {
        header('Location: payment.php');
    }
    exit;
}
$valid_pages = ['home', 'about', 'courses', 'admissions', 'faculty', 'contact'];
if (!in_array($p, $valid_pages)) {
    $p = 'home';
}

$alert_success = '';
$alert_error = '';

// 1. Handle Online Admission Application Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_admission') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $courseName = trim($_POST['course_name'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $scorePercent = (float)($_POST['score_percent'] ?? 0);
    $address = trim($_POST['address'] ?? '');

    if ($fullName && $email && $phone && $courseName && $scorePercent > 0) {
        try {
            $appNo = 'APP-2026-' . rand(1000, 9999);
            $stmt = $pdo->prepare("INSERT INTO admissions (application_no, full_name, email, phone, course_name, qualification, score_percent, address, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Under Review')");
            $stmt->execute([$appNo, $fullName, $email, $phone, $courseName, $qualification, $scorePercent, $address]);
            $alert_success = "Application submitted successfully! Your Application ID is <strong>$appNo</strong>. Please save this for verification.";
        } catch (Throwable $e) {
            $alert_error = "Failed to submit application: " . htmlspecialchars($e->getMessage());
        }
    } else {
        $alert_error = "Please fill in all required fields accurately.";
    }
}

// 2. Handle Contact Inquiry Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_inquiry') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name && $email && $subject && $message) {
        try {
            $stmt = $pdo->prepare("INSERT INTO inquiries (name, email, phone, subject, message) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $email, $phone, $subject, $message]);
            $alert_success = "Thank you! Your inquiry has been recorded. Our admissions desk will contact you soon.";
        } catch (Throwable $e) {
            $alert_error = "Could not record your inquiry: " . htmlspecialchars($e->getMessage());
        }
    } else {
        $alert_error = "Please complete all inquiry form fields.";
    }
}

// 3. Handle Fee Search & Online Fee Transaction
$searchRoll = trim($_GET['roll_no'] ?? $_POST['roll_no'] ?? '');
$foundStudent = null;
$studentFees = [];
$transactionReceipt = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_fee') {
    $feeId = (int)($_POST['fee_id'] ?? 0);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $paymentMode = trim($_POST['payment_mode'] ?? 'Online UPI');
    $cardNumber = trim($_POST['card_number'] ?? '');
    $upiId = trim($_POST['upi_id'] ?? '');

    if ($feeId && $studentId) {
        try {
            // Generate official transaction ref
            $txnId = 'TXN-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $modeNote = $paymentMode;
            if ($paymentMode === 'UPI / QR' && $upiId) {
                $modeNote .= " ($upiId)";
            } elseif ($paymentMode === 'Debit / Credit Card' && $cardNumber) {
                $modeNote .= " (Ending " . substr(str_replace(' ', '', $cardNumber), -4) . ")";
            }

            $updateStmt = $pdo->prepare("UPDATE fees SET status = 'Paid', transaction_id = ?, payment_mode = ?, payment_date = NOW() WHERE id = ? AND student_id = ?");
            $updateStmt->execute([$txnId, $modeNote, $feeId, $studentId]);

            // Fetch transaction receipt details
            $receiptQuery = $pdo->prepare("
                SELECT f.*, s.student_name, s.roll_no, s.email, s.phone, b.branch_name 
                FROM fees f 
                JOIN students s ON s.id = f.student_id 
                LEFT JOIN branches b ON b.id = s.branch_id 
                WHERE f.id = ?
            ");
            $receiptQuery->execute([$feeId]);
            $transactionReceipt = $receiptQuery->fetch();
            $emailNotice = '';
            if ($transactionReceipt) {
                if (empty($transactionReceipt['email'])) {
                    $transactionReceipt['email'] = 'amar.singhbca24@smslucknow.ac.in';
                }
                $emailResult = send_payment_receipt_email($transactionReceipt);
                if ($emailResult['success']) {
                    $emailNotice = " An official confirmation receipt has been dispatched to <strong>" . htmlspecialchars($emailResult['email']) . "</strong> (Dispatched by: <strong>amar.singhbca24@smslucknow.ac.in</strong>).";
                }
            }
            $alert_success = "Transaction successful! Fee paid and verified." . $emailNotice . " Receipt generated below.";
            $searchRoll = $transactionReceipt['roll_no'] ?? '';
        } catch (Throwable $e) {
            $alert_error = "Transaction failed: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Search student fee info
if ($searchRoll) {
    try {
        $studentStmt = $pdo->prepare("
            SELECT s.*, b.branch_name 
            FROM students s 
            LEFT JOIN branches b ON b.id = s.branch_id 
            WHERE s.roll_no = ? OR s.email = ?
        ");
        $studentStmt->execute([$searchRoll, $searchRoll]);
        $foundStudent = $studentStmt->fetch();

        if ($foundStudent) {
            $feesStmt = $pdo->prepare("SELECT * FROM fees WHERE student_id = ? ORDER BY id DESC");
            $feesStmt->execute([$foundStudent['id']]);
            $studentFees = $feesStmt->fetchAll();
        } else {
            $alert_error = "No student found with Roll Number or Email: " . htmlspecialchars($searchRoll) . ". Try demo roll: BCA202601 or BCA202602";
        }
    } catch (Throwable $e) {
        $alert_error = "Error fetching records: " . htmlspecialchars($e->getMessage());
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>School of Management Sciences (SMS) | Premier Autonomous College</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">
</head>
<body>

  <!-- Navigation Header -->
  <header>
    <a class="brand" href="index.php">
      <b>SMS</b>
      <span>School of <span>Management Sciences</span>
        <small>COLLEGE INFORMATION & STUDENT PORTAL</small>
      </span>
    </a>

    <button id="toggle" aria-label="Toggle Menu">☰</button>

    <nav id="nav">
      <a class="<?= $p === 'home' ? 'active' : '' ?>" href="index.php?page=home">Home</a>
      <a class="<?= $p === 'about' ? 'active' : '' ?>" href="index.php?page=about">About</a>
      <a class="<?= $p === 'courses' ? 'active' : '' ?>" href="index.php?page=courses">Courses</a>
      <a class="<?= $p === 'admissions' ? 'active' : '' ?>" href="index.php?page=admissions">Admissions</a>
      <a href="attendance.php" style="color: #10b981; font-weight: 700;">📅 Attendance</a>
      <a class="highlight-nav" href="payment.php">💳 Online Payment</a>
      <a class="<?= $p === 'faculty' ? 'active' : '' ?>" href="index.php?page=faculty">Faculty</a>
      <a class="<?= $p === 'contact' ? 'active' : '' ?>" href="index.php?page=contact">Contact</a>
      <a class="nav-admin" href="admin/login.php">Admin Portal ↗</a>
    </nav>
  </header>

  <!-- Global Alerts -->
  <?php if ($alert_success): ?>
    <div style="padding: 18px 7% 0;">
      <div class="alert alert-success">
        <span>✅</span>
        <div><?= $alert_success ?></div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($alert_error): ?>
    <div style="padding: 18px 7% 0;">
      <div class="alert alert-danger">
        <span>⚠️</span>
        <div><?= $alert_error ?></div>
      </div>
    </div>
  <?php endif; ?>

  <!-- PAGE CONTENT ROUTING -->
  <?php if ($p === 'home'): ?>
    <!-- HERO SECTION -->
    <main>
      <section class="hero">
        <div class="hero-content">
          <div class="hero-pill">
            <span>✨</span> Admissions Open for Session 2026-27
          </div>
          <h1>Empowering Minds.<br><em>Shaping Futures.</em></h1>
          <p>
            Welcome to School of Management Sciences (SMS) — an autonomous, premier institution offering top-tier management, computer science, and commerce education with seamless digital student services.
          </p>

          <div class="hero-actions">
            <a class="btn" href="index.php?page=admissions">Apply Online Now →</a>
            <a class="btn btn-secondary" href="attendance.php" style="border-color: rgba(16, 185, 129, 0.4); color: #059669; font-weight: 700;">📅 Student Attendance</a>
            <a class="btn btn-secondary" href="payment.php">💳 Online Payment</a>
            <a class="btn btn-secondary" href="index.php?page=courses">Explore Programs</a>
          </div>

          <div class="hero-highlights">
            <div class="hero-highlight-item">
              <span>🏆</span>
              <div>
                <b>NAAC 'A++' Rated</b>
                <small>Top Accreditation</small>
              </div>
            </div>
            <div class="hero-highlight-item">
              <span>💼</span>
              <div>
                <b>95% Placement</b>
                <small>Tier-1 Recruiters</small>
              </div>
            </div>
            <div class="hero-highlight-item">
              <span>⚡</span>
              <div>
                <b>100% Digital</b>
                <small>Instant Transactions</small>
              </div>
            </div>
          </div>
        </div>

        <div class="hero-visual">
          <div class="hero-card">
            <div class="hero-card-banner">
              <small>Academic Excellence</small>
              <h2>Next-Gen Higher<br>Education Platform</h2>
              <a href="index.php?page=courses">Discover 12+ Degree Programs ↗</a>
            </div>

            <div class="hero-mini-grid">
              <div class="hero-mini-item">
                <strong>4,500+</strong>
                <span>Active Students</span>
              </div>
              <div class="hero-mini-item">
                <strong>120+</strong>
                <span>Expert Faculty</span>
              </div>
              <div class="hero-mini-item">
                <strong>₹12 LPA</strong>
                <span>Highest Package</span>
              </div>
            </div>

            <div class="floating-badge">
              <i>✓</i>
              <div>
                <b>Zero Hassle Payments</b>
                <small>Instant verified receipts</small>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- STATS BAR -->
      <section class="stats-bar">
        <div class="stat-item">
          <b>25+</b>
          <span>Years of Educational Heritage</span>
        </div>
        <div class="stat-item">
          <b>98.4%</b>
          <span>Examination Pass Percentage</span>
        </div>
        <div class="stat-item">
          <b>250+</b>
          <span>Campus Placement Partners</span>
        </div>
        <div class="stat-item">
          <b>24/7</b>
          <span>Digital Student Services</span>
        </div>
      </section>

      <!-- FEATURED PILLARS -->
      <section class="section section-alt">
        <div class="section-header">
          <span class="section-label">Campus Highlights</span>
          <h2>Designed for Every Student's <em>Success</em></h2>
          <p>Explore world-class academic infrastructure, high-impact career guidance, and transparent administrative tools.</p>
        </div>

        <div class="grid-3">
          <div class="card">
            <div class="card-icon">💻</div>
            <h3>Computer Applications & AI</h3>
            <p>Industry-aligned programs in Full Stack Web, Cloud Architecture, Data Science, and Machine Learning with hands-on capstones.</p>
            <a class="card-link" href="index.php?page=courses">View Program Curriculum →</a>
          </div>

          <div class="card">
            <div class="card-icon">📊</div>
            <h3>Business & Management</h3>
            <p>Develop leadership, strategic marketing, organizational behavior, and venture creation skills with corporate case studies.</p>
            <a class="card-link" href="index.php?page=courses">View Management Courses →</a>
          </div>

          <div class="card">
            <div class="card-icon">💳</div>
            <h3>Instant Online Fee Portal</h3>
            <p>Seamlessly check your semester dues, process online transactions via UPI or Card, and generate authorized downloadable receipts.</p>
            <a class="card-link" href="index.php?page=fees">Access Online Fee Portal →</a>
          </div>
        </div>
      </section>

      <!-- CALL TO ACTION -->
      <section class="section" style="background: linear-gradient(135deg, #1e3a8a, #0f172a); color: #fff; text-align: center;">
        <div style="max-width: 700px; margin: 0 auto;">
          <span class="badge badge-primary" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8; border: none; margin-bottom: 20px;">
            JOIN OUR 2026 COHORT
          </span>
          <h2 style="color: #fff; font-size: 38px; margin-bottom: 18px;">Start Your Journey With Us Today</h2>
          <p style="color: #94a3b8; font-size: 16px; margin-bottom: 30px;">
            Submit your application in less than 3 minutes. Merit scholarships available for top qualifying scores.
          </p>
          <a class="btn" style="background: #38bdf8; color: #0f172a; font-weight: 800;" href="index.php?page=admissions">
            Submit Online Application →
          </a>
        </div>
      </section>
    </main>

  <?php elseif ($p === 'about'): ?>
    <!-- ABOUT PAGE -->
    <section class="pagehero">
      <span class="badge badge-primary">About School of Management Sciences</span>
      <h1>A Legacy of Knowledge,<br><em>Driven by Innovation.</em></h1>
      <p>Established to deliver transformative management, commerce, and computer applications education, SMS combines academic rigour with modern digital convenience.</p>
    </section>

    <section class="section">
      <div class="grid-2" style="align-items: center;">
        <div>
          <span class="section-label">Vision & Mission</span>
          <h2 style="font-size: 36px; margin-bottom: 20px;">Shaping Tomorrow's <em>Global Leaders</em></h2>
          <p style="color: var(--text-muted); margin-bottom: 16px;">
            Our mission is to foster intellectual curiosity, ethical leadership, and technological innovation. We offer a student-first environment supported by state-of-the-art laboratories, modern seminar halls, and vibrant extracurricular communities.
          </p>
          <p style="color: var(--text-muted); margin-bottom: 24px;">
            Every student benefits from personalized academic mentoring, research opportunities, and dedicated career placement support from day one.
          </p>
          <div style="display: flex; gap: 20px;">
            <div style="background: #fff; border: 1px solid var(--border); padding: 18px; border-radius: 12px; flex: 1;">
              <b style="font-size: 22px; color: var(--primary);">100%</b>
              <small style="display: block; color: var(--text-muted);">Verified Faculty</small>
            </div>
            <div style="background: #fff; border: 1px solid var(--border); padding: 18px; border-radius: 12px; flex: 1;">
              <b style="font-size: 22px; color: var(--accent-teal);">50+</b>
              <small style="display: block; color: var(--text-muted);">Industry MoUs</small>
            </div>
          </div>
        </div>

        <div style="background: #fff; padding: 36px; border-radius: 24px; border: 1px solid var(--border); box-shadow: var(--shadow-md);">
          <h3 style="margin-bottom: 18px; font-size: 22px;">Institutional Accreditations</h3>
          <ul style="list-style: none; display: flex; flex-direction: column; gap: 14px;">
            <li style="display: flex; gap: 12px; align-items: center;">
              <span style="color: #10b981; font-size: 18px;">✓</span>
              <div><b>NAAC Grade A++ Accredited</b> with highest institutional score.</div>
            </li>
            <li style="display: flex; gap: 12px; align-items: center;">
              <span style="color: #10b981; font-size: 18px;">✓</span>
              <div><b>AICTE & UGC Recognized</b> curriculum meeting national education standards.</div>
            </li>
            <li style="display: flex; gap: 12px; align-items: center;">
              <span style="color: #10b981; font-size: 18px;">✓</span>
              <div><b>ISO 9001:2015 Certified</b> campus infrastructure and digital administrative governance.</div>
            </li>
            <li style="display: flex; gap: 12px; align-items: center;">
              <span style="color: #10b981; font-size: 18px;">✓</span>
              <div><b>Ranked Among Top 50</b> colleges for Computer Science & Management.</div>
            </li>
          </ul>
        </div>
      </div>
    </section>

  <?php elseif ($p === 'courses'): ?>
    <!-- COURSES CATALOG -->
    <section class="pagehero">
      <span class="badge badge-primary">Academic Programs</span>
      <h1>Discover Your Passion.<br><em>Build Your Career.</em></h1>
      <p>Explore undergraduate and postgraduate degrees tailored for contemporary industry requirements.</p>
    </section>

    <section class="section">
      <div class="grid-3">
        <!-- BCA -->
        <div class="course-card">
          <div class="course-header">
            <span class="badge badge-primary" style="background:#2563eb;color:#fff;">3 Years</span>
            <h3>Bachelor of Computer Applications (BCA)</h3>
            <p>Department of Computer Applications</p>
          </div>
          <div class="course-body">
            <p>Comprehensive training in Python, Java, Full Stack Web Development, Database Management (MySQL), and Cloud Computing.</p>
            <div class="course-meta">
              <div class="course-meta-item">
                <small>Eligibility</small>
                <b>10+2 with 50% min</b>
              </div>
              <div class="course-meta-item">
                <small>Available Seats</small>
                <b>120 Seats</b>
              </div>
            </div>
            <div class="course-price">
              ₹24,500 <small>/ semester</small>
            </div>
            <a class="btn" style="width: 100%;" href="index.php?page=admissions&course=BCA">Apply for BCA →</a>
          </div>
        </div>

        <!-- BBA -->
        <div class="course-card">
          <div class="course-header">
            <span class="badge badge-primary" style="background:#2563eb;color:#fff;">3 Years</span>
            <h3>Bachelor of Business Administration (BBA)</h3>
            <p>Department of Management Studies</p>
          </div>
          <div class="course-body">
            <p>Master leadership principles, modern marketing, corporate finance, HR strategies, and business analytics.</p>
            <div class="course-meta">
              <div class="course-meta-item">
                <small>Eligibility</small>
                <b>10+2 Any Stream (45%)</b>
              </div>
              <div class="course-meta-item">
                <small>Available Seats</small>
                <b>90 Seats</b>
              </div>
            </div>
            <div class="course-price">
              ₹31,000 <small>/ semester</small>
            </div>
            <a class="btn" style="width: 100%;" href="index.php?page=admissions&course=BBA">Apply for BBA →</a>
          </div>
        </div>

        <!-- B.Com -->
        <div class="course-card">
          <div class="course-header">
            <span class="badge badge-primary" style="background:#2563eb;color:#fff;">3 Years</span>
            <h3>Bachelor of Commerce (Honours)</h3>
            <p>Department of Commerce & Finance</p>
          </div>
          <div class="course-body">
            <p>In-depth education in corporate taxation, investment banking, auditing, GST regulations, and financial accounting.</p>
            <div class="course-meta">
              <div class="course-meta-item">
                <small>Eligibility</small>
                <b>10+2 Commerce/Math</b>
              </div>
              <div class="course-meta-item">
                <small>Available Seats</small>
                <b>60 Seats</b>
              </div>
            </div>
            <div class="course-price">
              ₹18,500 <small>/ semester</small>
            </div>
            <a class="btn" style="width: 100%;" href="index.php?page=admissions&course=BCOM">Apply for B.Com →</a>
          </div>
        </div>

        <!-- MCA -->
        <div class="course-card">
          <div class="course-header">
            <span class="badge badge-warning" style="background:#d97706;color:#fff;">2 Years</span>
            <h3>Master of Computer Applications (MCA)</h3>
            <p>Postgraduate Program</p>
          </div>
          <div class="course-body">
            <p>Advanced algorithmic design, cybersecurity, distributed systems, AI architectures, and enterprise engineering.</p>
            <div class="course-meta">
              <div class="course-meta-item">
                <small>Eligibility</small>
                <b>BCA / B.Sc CS / Math</b>
              </div>
              <div class="course-meta-item">
                <small>Available Seats</small>
                <b>60 Seats</b>
              </div>
            </div>
            <div class="course-price">
              ₹38,000 <small>/ semester</small>
            </div>
            <a class="btn" style="width: 100%;" href="index.php?page=admissions&course=MCA">Apply for MCA →</a>
          </div>
        </div>

        <!-- MBA -->
        <div class="course-card">
          <div class="course-header">
            <span class="badge badge-warning" style="background:#d97706;color:#fff;">2 Years</span>
            <h3>Master of Business Admin (MBA)</h3>
            <p>Postgraduate Program</p>
          </div>
          <div class="course-body">
            <p>Specializations in Strategic Marketing, Fintech, Operations, Supply Chain Management, and International Business.</p>
            <div class="course-meta">
              <div class="course-meta-item">
                <small>Eligibility</small>
                <b>Graduation (50% min)</b>
              </div>
              <div class="course-meta-item">
                <small>Available Seats</small>
                <b>60 Seats</b>
              </div>
            </div>
            <div class="course-price">
              ₹45,000 <small>/ semester</small>
            </div>
            <a class="btn" style="width: 100%;" href="index.php?page=admissions&course=MBA">Apply for MBA →</a>
          </div>
        </div>

        <!-- B.Tech CS -->
        <div class="course-card">
          <div class="course-header">
            <span class="badge badge-primary" style="background:#2563eb;color:#fff;">4 Years</span>
            <h3>B.Tech in Computer Science & Eng.</h3>
            <p>School of Engineering</p>
          </div>
          <div class="course-body">
            <p>Core engineering fundamentals, embedded computing, robotics, operating systems, compiler design, and networking.</p>
            <div class="course-meta">
              <div class="course-meta-item">
                <small>Eligibility</small>
                <b>10+2 PCM (60% min)</b>
              </div>
              <div class="course-meta-item">
                <small>Available Seats</small>
                <b>180 Seats</b>
              </div>
            </div>
            <div class="course-price">
              ₹52,000 <small>/ semester</small>
            </div>
            <a class="btn" style="width: 100%;" href="index.php?page=admissions&course=BTech">Apply for B.Tech →</a>
          </div>
        </div>
      </div>
    </section>

  <?php elseif ($p === 'admissions'): ?>
    <!-- ADMISSIONS ONLINE APPLICATION -->
    <section class="pagehero">
      <span class="badge badge-primary">Admissions 2026-27</span>
      <h1>Start Your Admission.<br><em>Online Application Form.</em></h1>
      <p>Submit your application digitally. Your record is securely saved directly to the database.</p>
    </section>

    <section class="section">
      <div class="transaction-box">
        <h2 style="font-size: 26px; margin-bottom: 8px;">Candidate Admission Registration</h2>
        <p style="color: var(--text-muted); margin-bottom: 28px;">
          Please provide accurate details. After submission, an official Application ID will be generated.
        </p>

        <form method="post" action="index.php?page=admissions">
          <input type="hidden" name="action" value="apply_admission">

          <div class="form-row">
            <div class="form-group">
              <label>Full Name *</label>
              <input class="form-control" name="full_name" required placeholder="e.g. Sahil Verma">
            </div>

            <div class="form-group">
              <label>Email Address *</label>
              <input class="form-control" type="email" name="email" required placeholder="e.g. sahil.verma@example.com">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Phone Number *</label>
              <input class="form-control" name="phone" required placeholder="e.g. 9876543210">
            </div>

            <div class="form-group">
              <label>Desired Course / Program *</label>
              <select class="form-control" name="course_name" required>
                <option value="">Select a Program</option>
                <option value="Bachelor of Computer Applications (BCA)">Bachelor of Computer Applications (BCA)</option>
                <option value="Bachelor of Business Administration (BBA)">Bachelor of Business Administration (BBA)</option>
                <option value="Bachelor of Commerce (Honours)">Bachelor of Commerce (Honours)</option>
                <option value="Master of Computer Applications (MCA)">Master of Computer Applications (MCA)</option>
                <option value="Master of Business Admin (MBA)">Master of Business Admin (MBA)</option>
                <option value="B.Tech Computer Science & Eng.">B.Tech Computer Science & Eng.</option>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Last Completed Qualification *</label>
              <input class="form-control" name="qualification" required placeholder="e.g. 12th Intermediate / B.Sc">
            </div>

            <div class="form-group">
              <label>Aggregate Score / Percentage (%) *</label>
              <input class="form-control" type="number" step="0.01" min="35" max="100" name="score_percent" required placeholder="e.g. 84.5">
            </div>
          </div>

          <div class="form-group">
            <label>Permanent Residential Address</label>
            <textarea class="form-control" name="address" rows="3" placeholder="Enter complete city, state and pincode"></textarea>
          </div>

          <button class="btn" style="width: 100%; padding: 16px; font-size: 16px;">
            Submit Application to Database 🚀
          </button>
        </form>
      </div>
    </section>

  <?php elseif ($p === 'fees'): ?>
    <!-- FEES & ONLINE TRANSACTION PORTAL -->
    <section class="pagehero">
      <span class="badge badge-primary">Online Fee Desk</span>
      <h1>Student Fee Portal &<br><em>Online Transactions.</em></h1>
      <p>Look up student fee status by Roll Number, execute secure simulated payments, and generate official stamped fee receipts.</p>
    </section>

    <section class="section">
      <div class="transaction-box">
        <!-- Roll Number Search Bar -->
        <h2 style="font-size: 24px; margin-bottom: 12px;">Search Student Fee Records</h2>
        <form class="search-fee-bar" method="get" action="index.php">
          <input type="hidden" name="page" value="fees">
          <input name="roll_no" value="<?= htmlspecialchars($searchRoll) ?>" placeholder="Enter Roll Number (e.g. BCA202601, BCA202602, BBA202601)" required>
          <button class="btn" type="submit">Search Records →</button>
        </form>

        <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 25px;">
          💡 <strong>Demo Roll Numbers to Test:</strong> 
          <a href="index.php?page=fees&roll_no=BCA202601" style="color: var(--primary); font-weight: 700;">BCA202601 (Pending Fee)</a> · 
          <a href="index.php?page=fees&roll_no=BCA202602" style="color: var(--accent-teal); font-weight: 700;">BCA202602 (Paid Fee)</a> · 
          <a href="index.php?page=fees&roll_no=BBA202601" style="color: var(--primary); font-weight: 700;">BBA202601</a>
        </p>

        <!-- If student is found -->
        <?php if ($foundStudent): ?>
          <div class="fee-status-card">
            <div class="fee-student-info">
              <span class="badge badge-primary" style="margin-bottom: 8px;">Verified Student</span>
              <h3><?= htmlspecialchars($foundStudent['student_name']) ?></h3>
              <p>Roll No: <strong><?= htmlspecialchars($foundStudent['roll_no']) ?></strong> · Department: <strong><?= htmlspecialchars($foundStudent['branch_name'] ?? 'General') ?></strong></p>
              <p style="color: var(--text-muted); font-size: 12px;">Email: <?= htmlspecialchars($foundStudent['email'] ?? 'N/A') ?> · Phone: <?= htmlspecialchars($foundStudent['phone'] ?? 'N/A') ?></p>
            </div>
          </div>

          <h3 style="font-size: 20px; margin-bottom: 16px;">Associated Fee Invoices</h3>

          <?php if (empty($studentFees)): ?>
            <p style="color: var(--text-muted);">No fee vouchers recorded for this student.</p>
          <?php else: ?>
            <?php foreach ($studentFees as $fItem): ?>
              <div style="border: 1px solid var(--border); border-radius: 14px; padding: 22px; margin-bottom: 20px; background: #fff;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 14px;">
                  <div>
                    <span class="badge <?= $fItem['status'] === 'Paid' ? 'badge-success' : 'badge-danger' ?>">
                      <?= $fItem['status'] === 'Paid' ? '✓ PAID' : '⏳ PENDING' ?>
                    </span>
                    <h4 style="font-size: 18px; margin-top: 6px;">
                      <?= htmlspecialchars($fItem['remarks'] ?: 'College Tuition & Academic Fee') ?>
                    </h4>
                  </div>
                  <div style="text-align: right;">
                    <span style="font-size: 12px; color: var(--text-muted);">Amount Due</span>
                    <div style="font-family: 'Outfit', sans-serif; font-size: 28px; font-weight: 800; color: var(--primary);">
                      ₹<?= number_format((float)$fItem['amount'], 2) ?>
                    </div>
                  </div>
                </div>

                <?php if ($fItem['status'] === 'Paid'): ?>
                  <!-- Paid Details -->
                  <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 10px; padding: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                      <small style="color: #065f46; display: block;">TRANSACTION REFERENCE</small>
                      <strong style="color: #065f46; font-size: 14px;"><?= htmlspecialchars($fItem['transaction_id'] ?: 'TXN-PAID-OFFLINE') ?></strong>
                      <span style="color: #047857; font-size: 12px; margin-left: 10px;">via <?= htmlspecialchars($fItem['payment_mode'] ?: 'Online') ?></span>
                    </div>
                    <button class="btn btn-secondary" onclick="window.print()" style="padding: 8px 16px; font-size: 12px;">
                      🖨️ Print Receipt
                    </button>
                  </div>
                <?php else: ?>
                  <!-- PENDING FEE -> TRANSACTION CHECKOUT FORM -->
                  <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 14px; padding: 24px; margin-top: 14px;">
                    <h4 style="font-size: 16px; margin-bottom: 16px; color: var(--primary-dark);">
                      💳 Complete Online Transaction for ₹<?= number_format((float)$fItem['amount'], 2) ?>
                    </h4>

                    <!-- Payment Mode Switcher Tabs -->
                    <div class="payment-tabs">
                      <div class="payment-tab active" data-target="tab-upi-<?= $fItem['id'] ?>" data-mode="Online UPI">
                        📱 UPI / QR Code
                      </div>
                      <div class="payment-tab" data-target="tab-card-<?= $fItem['id'] ?>" data-mode="Debit / Credit Card">
                        💳 Card Payment
                      </div>
                      <div class="payment-tab" data-target="tab-net-<?= $fItem['id'] ?>" data-mode="Net Banking">
                        🏦 Net Banking
                      </div>
                    </div>

                    <form method="post" action="index.php?page=fees">
                      <input type="hidden" name="action" value="pay_fee">
                      <input type="hidden" name="fee_id" value="<?= $fItem['id'] ?>">
                      <input type="hidden" name="student_id" value="<?= $foundStudent['id'] ?>">
                      <input type="hidden" name="roll_no" value="<?= htmlspecialchars($foundStudent['roll_no']) ?>">
                      <input type="hidden" name="payment_mode" id="selected_payment_mode" value="Online UPI">

                      <!-- TAB 1: UPI / QR -->
                      <div class="payment-panel active" id="tab-upi-<?= $fItem['id'] ?>">
                        <div style="display: flex; gap: 24px; align-items: center; flex-wrap: wrap;">
                          <div style="background: #fff; padding: 14px; border: 1px solid var(--border); border-radius: 12px; text-align: center;">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=130x130&data=upi://pay?pa=college.fees@axisbank&pn=SMS_College&am=<?= $fItem['amount'] ?>" alt="UPI QR Code" style="width: 120px; height: 120px; display: block; border-radius: 8px;">
                            <small style="color: var(--text-muted); font-size: 10px; display: block; margin-top: 6px;">Scan with GPay / PhonePe / Paytm</small>
                          </div>
                          <div style="flex: 1; min-width: 240px;">
                            <div class="form-group">
                              <label>Or enter UPI VPA Address</label>
                              <input class="form-control" name="upi_id" placeholder="e.g. student@okaxis or 9876543210@paytm">
                            </div>
                            <small style="color: var(--text-muted);">Verified Merchant: <strong>School of Management Sciences (SMS) Educational Society</strong></small>
                          </div>
                        </div>
                      </div>

                      <!-- TAB 2: CREDIT / DEBIT CARD -->
                      <div class="payment-panel" id="tab-card-<?= $fItem['id'] ?>">
                        <div class="card-mockup">
                          <div class="card-chip"></div>
                          <div class="card-number-display" id="display_card_num">•••• •••• •••• ••••</div>
                          <div class="card-holder-display">
                            <div>
                              <span>Cardholder</span>
                              <b id="display_card_holder"><?= htmlspecialchars(strtoupper($foundStudent['student_name'])) ?></b>
                            </div>
                            <div>
                              <span>Expires</span>
                              <b id="display_card_expiry">12/28</b>
                            </div>
                          </div>
                        </div>

                        <div class="form-row">
                          <div class="form-group">
                            <label>Card Number</label>
                            <input class="form-control" id="card_number" name="card_number" placeholder="4532 8921 7843 1092" maxlength="19">
                          </div>
                          <div class="form-group">
                            <label>Cardholder Full Name</label>
                            <input class="form-control" id="card_holder" value="<?= htmlspecialchars($foundStudent['student_name']) ?>">
                          </div>
                        </div>

                        <div class="form-row">
                          <div class="form-group">
                            <label>Expiry Date</label>
                            <input class="form-control" id="card_expiry" placeholder="MM/YY" maxlength="5">
                          </div>
                          <div class="form-group">
                            <label>CVV / CVC</label>
                            <input class="form-control" type="password" maxlength="3" placeholder="•••">
                          </div>
                        </div>
                      </div>

                      <!-- TAB 3: NET BANKING -->
                      <div class="payment-panel" id="tab-net-<?= $fItem['id'] ?>">
                        <div class="form-group">
                          <label>Select Your Bank</label>
                          <select class="form-control">
                            <option>State Bank of India (SBI)</option>
                            <option>HDFC Bank</option>
                            <option>ICICI Bank</option>
                            <option>Axis Bank</option>
                            <option>Punjab National Bank</option>
                            <option>Bank of Baroda</option>
                          </select>
                        </div>
                        <p style="font-size: 12px; color: var(--text-muted);">You will be securely redirected to complete authorization.</p>
                      </div>

                      <div style="margin-top: 20px;">
                        <button class="btn btn-success" style="width: 100%; padding: 15px; font-size: 15px;">
                          🔒 Confirm Payment & Generate Official Receipt (₹<?= number_format((float)$fItem['amount'], 2) ?>)
                        </button>
                      </div>
                    </form>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

        <?php endif; ?>

        <!-- OFFICIAL PRINTABLE FEE RECEIPT (Shown on successful transaction or when requested) -->
        <?php if ($transactionReceipt): ?>
          <div class="receipt-wrapper" id="receipt_section">
            <div class="receipt-header">
              <div class="receipt-title">
                <h2>SCHOOL OF MANAGEMENT SCIENCES (SMS)</h2>
                <p>Approved by AICTE, Ministry of Education, Govt. of India · NAAC Accredited Autonomous Institution</p>
                <p>Website: http://localhost:8000 · Accounts & Finance Department</p>
              </div>
              <div class="receipt-badge">
                <div class="receipt-stamp">PAID & VERIFIED</div>
                <div style="margin-top: 8px; font-size: 11px; color: var(--text-muted);">
                  Date: <?= date('d M Y, h:i A') ?>
                </div>
              </div>
            </div>

            <table class="receipt-table">
              <tr>
                <th>Receipt / Transaction Ref</th>
                <td><strong style="color: var(--primary);"><?= htmlspecialchars($transactionReceipt['transaction_id']) ?></strong></td>
                <th>Payment Mode</th>
                <td><?= htmlspecialchars($transactionReceipt['payment_mode']) ?></td>
              </tr>
              <tr>
                <th>Student Full Name</th>
                <td><?= htmlspecialchars($transactionReceipt['student_name']) ?></td>
                <th>Roll Number</th>
                <td><strong><?= htmlspecialchars($transactionReceipt['roll_no']) ?></strong></td>
              </tr>
              <tr>
                <th>Course / Department</th>
                <td><?= htmlspecialchars($transactionReceipt['branch_name'] ?? 'General') ?></td>
                <th>Registered Email</th>
                <td><?= htmlspecialchars($transactionReceipt['email']) ?></td>
              </tr>
              <tr>
                <th>Fee Description</th>
                <td colspan="3"><?= htmlspecialchars($transactionReceipt['remarks'] ?: 'Tuition and Examination Fee') ?></td>
              </tr>
              <tr class="receipt-total">
                <th colspan="2">TOTAL AMOUNT RECEIVED</th>
                <td colspan="2" style="color: #059669; font-size: 18px;">
                  ₹<?= number_format((float)$transactionReceipt['amount'], 2) ?> (INR)
                </td>
              </tr>
            </table>

            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 30px;">
              <small style="color: var(--text-muted); font-size: 11px;">
                * This is a computer-generated transaction record. No physical signature is required.
              </small>
              <button class="btn btn-secondary" id="print_receipt_btn" type="button">
                🖨️ Print Official Receipt
              </button>
            </div>
          </div>
        <?php endif; ?>

      </div>
    </section>

  <?php elseif ($p === 'faculty'): ?>
    <!-- FACULTY DIRECTORY -->
    <section class="pagehero">
      <span class="badge badge-primary">Distinguished Educators</span>
      <h1>World-Class Mentors.<br><em>Guiding Your Aspirations.</em></h1>
      <p>Meet our accomplished professors, researchers, and academic mentors committed to student growth.</p>
    </section>

    <section class="section">
      <div class="grid-3">
        <div class="card" style="text-align: center;">
          <div style="width: 80px; height: 80px; margin: 0 auto 18px; border-radius: 50%; background: linear-gradient(135deg, #2563eb, #38bdf8); color: #fff; font-size: 32px; display: grid; place-items: center; font-weight: 800;">
            DR
          </div>
          <h3>Dr. Rajesh K. Sharma</h3>
          <p style="color: var(--primary); font-weight: 700; margin-bottom: 6px;">Head of Computer Applications</p>
          <small style="color: var(--text-muted); display: block; margin-bottom: 14px;">Ph.D. in AI & Cloud Computing, IIT Kanpur</small>
          <p style="font-size: 13px;">Specializes in Distributed Data Systems, Machine Intelligence, and Big Data Engineering with 18+ years experience.</p>
          <a class="card-link" href="mailto:rajesh.sharma@example.edu" style="justify-content: center;">Contact: rajesh.sharma@example.edu</a>
        </div>

        <div class="card" style="text-align: center;">
          <div style="width: 80px; height: 80px; margin: 0 auto 18px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6, #ec4899); color: #fff; font-size: 32px; display: grid; place-items: center; font-weight: 800;">
            PS
          </div>
          <h3>Prof. Shalini Mishra</h3>
          <p style="color: var(--primary); font-weight: 700; margin-bottom: 6px;">Dean of Management Studies</p>
          <small style="color: var(--text-muted); display: block; margin-bottom: 14px;">MBA (IIM Lucknow), Ph.D. Marketing</small>
          <p style="font-size: 13px;">Author of 3 books on Brand Strategy, Consumer Psychology, and Organizational Dynamics. Corporate consultant.</p>
          <a class="card-link" href="mailto:shalini.m@example.edu" style="justify-content: center;">Contact: shalini.m@example.edu</a>
        </div>

        <div class="card" style="text-align: center;">
          <div style="width: 80px; height: 80px; margin: 0 auto 18px; border-radius: 50%; background: linear-gradient(135deg, #059669, #10b981); color: #fff; font-size: 32px; display: grid; place-items: center; font-weight: 800;">
            AV
          </div>
          <h3>Dr. Amitav Roy</h3>
          <p style="color: var(--primary); font-weight: 700; margin-bottom: 6px;">Senior Professor, Commerce</p>
          <small style="color: var(--text-muted); display: block; margin-bottom: 14px;">Ph.D. Corporate Finance, CA Fellow</small>
          <p style="font-size: 13px;">Expert in Capital Markets, Derivative Securities, and International Financial Auditing Standards.</p>
          <a class="card-link" href="mailto:amitav.roy@example.edu" style="justify-content: center;">Contact: amitav.roy@example.edu</a>
        </div>
      </div>
    </section>

  <?php elseif ($p === 'contact'): ?>
    <!-- CONTACT & INQUIRIES -->
    <section class="pagehero">
      <span class="badge badge-primary">Get in Touch</span>
      <h1>Let’s Start a<br><em>Conversation.</em></h1>
      <p>Have questions regarding admission eligibility, campus tours, or course details? Send us an inquiry.</p>
    </section>

    <section class="section">
      <div class="grid-2">
        <div>
          <span class="section-label">Campus Information</span>
          <h2 style="font-size: 34px; margin-bottom: 20px;">We Are Here to <em>Assist You</em></h2>
          <p style="color: var(--text-muted); margin-bottom: 30px;">
            Our admission counselors and administrative team are available Monday through Saturday, 9:00 AM to 5:00 PM.
          </p>

          <div style="display: flex; flex-direction: column; gap: 20px;">
            <div style="display: flex; gap: 16px; align-items: flex-start;">
              <span style="font-size: 24px;">📍</span>
              <div>
                <b>Campus Address</b>
                <p style="color: var(--text-muted); margin: 0;">19th Kilometer Stone, Sultanpur Road, Gosainganj, Uttar Pradesh 226501</p>
              </div>
            </div>

            <div style="display: flex; gap: 16px; align-items: flex-start;">
              <span style="font-size: 24px;">📞</span>
              <div>
                <b>Admissions Helpline</b>
                <p style="color: var(--text-muted); margin: 0;">+91 9506 033170<br>+91 9792 633331</p>
              </div>
            </div>

            <div style="display: flex; gap: 16px; align-items: flex-start;">
              <span style="font-size: 24px;">✉️</span>
              <div>
                <b>Official Email</b>
                <p style="color: var(--text-muted); margin: 0;">admission@smslucknow.ac.in</p>
              </div>
            </div>
          </div>
        </div>

        <div style="background: #fff; padding: 36px; border-radius: 24px; border: 1px solid var(--border); box-shadow: var(--shadow-md);">
          <h3 style="font-size: 22px; margin-bottom: 20px;">Send Us a Message</h3>

          <form method="post" action="index.php?page=contact">
            <input type="hidden" name="action" value="submit_inquiry">

            <div class="form-group">
              <label>Your Full Name *</label>
              <input class="form-control" name="name" required placeholder="e.g. Rahul Gupta">
            </div>

            <div class="form-row">
              <div class="form-group">
                <label>Email Address *</label>
                <input class="form-control" type="email" name="email" required placeholder="e.g. rahul@example.com">
              </div>
              <div class="form-group">
                <label>Contact Phone</label>
                <input class="form-control" name="phone" placeholder="e.g. 9811223344">
              </div>
            </div>

            <div class="form-group">
              <label>Subject *</label>
              <input class="form-control" name="subject" required placeholder="e.g. Admission Criteria for BCA 2026">
            </div>

            <div class="form-group">
              <label>Your Message / Inquiry *</label>
              <textarea class="form-control" name="message" rows="4" required placeholder="Type your query here..."></textarea>
            </div>

            <button class="btn" style="width: 100%; padding: 14px;">
              Submit Inquiry to Desk →
            </button>
          </form>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <!-- FOOTER -->
  <footer>
    <div class="footer-top">
      <div class="footer-brand">
        <a class="brand" href="index.php">
          <b>SMS</b>
          <span>School of <span>Management Sciences</span></span>
        </a>
        <p>Comprehensive college management and student information portal for School of Management Sciences (SMS).</p>
      </div>

      <div class="footer-col">
        <h4>Navigation</h4>
        <ul>
          <li><a href="index.php?page=home">Home</a></li>
          <li><a href="index.php?page=about">About SMS</a></li>
          <li><a href="index.php?page=courses">Degree Courses</a></li>
          <li><a href="index.php?page=admissions">Admissions 2026</a></li>
          <li><a href="payment.php">Online Payment Gateway</a></li>
        </ul>
      </div>

      <div class="footer-col">
        <h4>Student Services</h4>
        <ul>
          <li><a href="attendance.php" style="color: #34d399; font-weight: 700;">📅 Attendance Portal</a></li>
          <li><a href="payment.php">Pay Online Fees</a></li>
          <li><a href="payment.php?active=search&search_query=BCA202601">Check Due Invoices</a></li>
          <li><a href="payment.php?active=track">Download E-Receipt</a></li>
          <li><a href="index.php?page=faculty">Faculty Directory</a></li>
        </ul>
      </div>

      <div class="footer-col">
        <h4>Administration</h4>
        <ul>
          <li><a href="admin/login.php">Admin Sign In</a></li>
          <li><a href="admin/dashboard.php">Administrative Portal</a></li>
          <li><a href="index.php?page=contact">Help & Support</a></li>
        </ul>
      </div>
    </div>

    <div class="footer-bottom">
      <div>© <?= date('Y') ?> School of Management Sciences (SMS). All rights reserved.</div>
      <div>
        <a href="admin/login.php">Admin Login ↗</a>
      </div>
    </div>
  </footer>

  <script src="assets/app.js"></script>
</body>
</html>