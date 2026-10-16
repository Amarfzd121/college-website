<?php
require_once __DIR__ . '/config.php';

$pdo = db();

// AJAX API for instant lookup
if (isset($_GET['api']) && $_GET['api'] === 'lookup_attendance') {
    header('Content-Type: application/json; charset=utf-8');
    $roll = strtoupper(trim($_GET['roll'] ?? ''));
    if (!$roll) {
        echo json_encode(['found' => false]);
        exit;
    }
    try {
        $st = $pdo->prepare("
            SELECT s.*, b.branch_name 
            FROM students s 
            LEFT JOIN branches b ON b.id = s.branch_id 
            WHERE s.roll_no = ?
        ");
        $st->execute([$roll]);
        $student = $st->fetch();

        if ($student) {
            $att = $pdo->prepare("
                SELECT * FROM attendance 
                WHERE student_id = ? 
                ORDER BY attendance_date DESC
            ");
            $att->execute([$student['id']]);
            $records = $att->fetchAll();

            $total = count($records);
            $present = 0;
            $absent = 0;
            $leave = 0;
            $late = 0;

            foreach ($records as $r) {
                if ($r['status'] === 'Present') $present++;
                elseif ($r['status'] === 'Absent') $absent++;
                elseif ($r['status'] === 'Leave') $leave++;
                elseif ($r['status'] === 'Late') $late++;
            }

            $pct = $total > 0 ? round(($present / $total) * 100, 1) : 0;

            echo json_encode([
                'found' => true,
                'student' => $student,
                'stats' => [
                    'total' => $total,
                    'present' => $present,
                    'absent' => $absent,
                    'leave' => $leave,
                    'late' => $late,
                    'pct' => $pct
                ],
                'records' => $records
            ]);
        } else {
            echo json_encode(['found' => false]);
        }
    } catch (Throwable $e) {
        echo json_encode(['found' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$searchRoll = strtoupper(trim($_GET['roll_no'] ?? ''));
$student = null;
$records = [];
$totalDays = 0;
$presentDays = 0;
$absentDays = 0;
$leaveDays = 0;
$lateDays = 0;
$percentage = 0.0;
$alertError = '';

if ($searchRoll) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, b.branch_name 
            FROM students s 
            LEFT JOIN branches b ON b.id = s.branch_id 
            WHERE s.roll_no = ? OR s.email = ?
        ");
        $stmt->execute([$searchRoll, $searchRoll]);
        $student = $stmt->fetch();

        if ($student) {
            $attStmt = $pdo->prepare("
                SELECT * FROM attendance 
                WHERE student_id = ? 
                ORDER BY attendance_date DESC
            ");
            $attStmt->execute([$student['id']]);
            $records = $attStmt->fetchAll();

            $totalDays = count($records);
            foreach ($records as $r) {
                if ($r['status'] === 'Present') $presentDays++;
                elseif ($r['status'] === 'Absent') $absentDays++;
                elseif ($r['status'] === 'Leave') $leaveDays++;
                elseif ($r['status'] === 'Late') $lateDays++;
            }
            $percentage = $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 1) : 0;
        } else {
            $alertError = "No student found with Roll Number: " . htmlspecialchars($searchRoll) . ". Please verify and try again.";
        }
    } catch (Throwable $e) {
        $alertError = "Error loading attendance records: " . htmlspecialchars($e->getMessage());
    }
}

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Attendance Portal | School of Management Sciences (SMS)</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📅</text></svg>">
  <style>
    .att-lookup-box {
      max-width: 680px;
      margin: -25px auto 35px;
      position: relative;
      z-index: 10;
    }
    .att-stat-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 16px;
      margin: 20px 0;
    }
    .att-stat-box {
      background: #f8fafc;
      border: 1px solid var(--border);
      border-radius: var(--radius-md);
      padding: 16px;
      text-align: center;
    }
    .att-stat-box h3 {
      font-size: 26px;
      margin-bottom: 2px;
      line-height: 1;
    }
    .att-stat-box small {
      font-size: 11.5px;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .demo-pill-btn {
      background: rgba(37, 99, 235, 0.08);
      color: var(--primary);
      border: 1px solid rgba(37, 99, 235, 0.2);
      border-radius: 9999px;
      padding: 4px 12px;
      font-size: 11.5px;
      font-weight: 700;
      text-decoration: none;
      display: inline-block;
      margin: 3px;
      transition: all 0.2s;
    }
    .demo-pill-btn:hover {
      background: var(--primary);
      color: #ffffff;
    }
  </style>
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
      <a href="index.php?page=home">Home</a>
      <a href="index.php?page=about">About</a>
      <a href="index.php?page=courses">Courses</a>
      <a href="index.php?page=admissions">Admissions</a>
      <a class="active" href="attendance.php" style="color: #10b981; font-weight: 700;">📅 Attendance</a>
      <a class="highlight-nav" href="payment.php">💳 Online Payment</a>
      <a href="index.php?page=faculty">Faculty</a>
      <a href="index.php?page=contact">Contact</a>
      <a class="nav-admin" href="admin/login.php">Admin Portal ↗</a>
    </nav>
  </header>

  <!-- PORTAL HERO -->
  <section class="att-portal-header">
    <div style="max-width: 900px; margin: 0 auto; text-align: center;">
      <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(16, 185, 129, 0.18); border: 1px solid rgba(16, 185, 129, 0.4); padding: 5px 14px; border-radius: 9999px; font-size: 12px; font-weight: 700; color: #34d399; margin-bottom: 14px;">
        <span>📊</span> Real-Time Academic Attendance Radar
      </div>
      <h1 style="color: #ffffff; font-size: clamp(26px, 4vw, 42px); margin-bottom: 10px;">
        Student Attendance Portal
      </h1>
      <p style="color: #cbd5e1; font-size: 15px; max-width: 640px; margin: 0 auto;">
        Lookup verified lecture attendance records, track daily classroom presence, verify semester examination eligibility (Min. 75%), and generate official slips.
      </p>
    </div>
  </section>

  <!-- SEARCH BAR CONTAINER -->
  <main class="payment-wrapper" style="padding-top: 0;">
    
    <div class="att-lookup-box">
      <div class="att-card" style="padding: 22px 26px;">
        <form method="get" action="attendance.php">
          <label style="font-weight: 700; font-size: 14px; display: block; margin-bottom: 8px;">
            🔍 Enter Student University Roll Number:
          </label>
          <div style="display: flex; gap: 10px;">
            <input 
              class="form-control" 
              type="text" 
              name="roll_no" 
              required 
              placeholder="e.g. BCA202601, BCA202602, BBA202601" 
              value="<?= h($searchRoll) ?>"
              style="text-transform: uppercase; font-weight: 700; font-size: 15px; letter-spacing: 0.5px;"
            >
            <button class="btn" type="submit" style="padding: 0 24px; white-space: nowrap;">
              Check Attendance →
            </button>
          </div>
        </form>

        <!-- Quick Demo Roll Pills -->
        <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--border); font-size: 12px; color: var(--text-muted);">
          <span>Quick Demo Rolls:</span>
          <a class="demo-pill-btn" href="attendance.php?roll_no=BCA202601">BCA202601 (Aarav - 80%)</a>
          <a class="demo-pill-btn" href="attendance.php?roll_no=BCA202602">BCA202602 (Priya - 90%)</a>
          <a class="demo-pill-btn" href="attendance.php?roll_no=BBA202601" style="color: #dc2626; border-color: rgba(220, 38, 38, 0.3);">BBA202601 (Rohan - 50% Shortage)</a>
          <a class="demo-pill-btn" href="attendance.php?roll_no=BCOM202601" style="color: #059669; border-color: rgba(5, 150, 105, 0.3);">BCOM202601 (Ananya - 100%)</a>
        </div>
      </div>
    </div>

    <!-- Error Alert -->
    <?php if ($alertError): ?>
      <div class="alert alert-danger" style="margin-bottom: 24px;">
        <span>⚠️</span>
        <div><?= $alertError ?></div>
      </div>
    <?php endif; ?>

    <!-- STUDENT ATTENDANCE REPORT VIEW -->
    <?php if ($student): ?>
      
      <!-- 1. STUDENT IDENTITY BANNER -->
      <div class="att-card">
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 18px;">
          <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 58px; height: 58px; border-radius: 50%; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 800; font-family: 'Outfit', sans-serif; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);">
              <?= strtoupper(substr($student['student_name'], 0, 1)) ?>
            </div>
            <div>
              <h2 style="font-size: 22px; margin-bottom: 3px;"><?= h($student['student_name']) ?></h2>
              <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 13px; color: var(--text-muted);">
                <span>Roll No: <code style="font-size: 13px; color: var(--primary); font-weight: 700;"><?= h($student['roll_no']) ?></code></span>
                <span>•</span>
                <span>🏛️ <?= h($student['branch_name'] ?? 'General Studies') ?></span>
                <span>•</span>
                <span>Session: 2026-2027</span>
              </div>
            </div>
          </div>

          <!-- Print / Action Toolbar -->
          <div class="no-print" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button class="btn btn-secondary" onclick="window.print()" style="padding: 8px 16px; font-size: 13px;">
              🖨️ Print Attendance Slip
            </button>
            <a class="btn" href="payment.php?roll_no=<?= urlencode($student['roll_no']) ?>" style="padding: 8px 16px; font-size: 13px;">
              💳 Fee Portal →
            </a>
          </div>
        </div>

        <!-- 2. EXAM ELIGIBILITY BANNER -->
        <div style="margin-top: 22px;">
          <?php if ($percentage >= 75): ?>
            <div class="alert alert-success" style="margin-bottom: 0;">
              <span style="font-size: 20px;">✅</span>
              <div>
                <strong>Eligible for University Examinations:</strong> Current attendance is <strong><?= $percentage ?>%</strong>, which comfortably satisfies the university's mandatory <strong>75% attendance threshold</strong>.
              </div>
            </div>
          <?php elseif ($percentage >= 65): ?>
            <div class="alert alert-warning" style="margin-bottom: 0;">
              <span style="font-size: 20px;">⚠️</span>
              <div>
                <strong>Borderline Attendance Warning:</strong> Current attendance is <strong><?= $percentage ?>%</strong>. Regular class attendance is urgently advised in the remaining lectures to avoid exam debarment.
              </div>
            </div>
          <?php else: ?>
            <div class="alert alert-danger" style="margin-bottom: 0;">
              <span style="font-size: 20px;">🚨</span>
              <div>
                <strong>Attendance Shortage Alert:</strong> Current attendance is <strong><?= $percentage ?>%</strong>, falling below the mandatory 75% requirement. Please submit medical/leave justification to the Academic Dean's office immediately.
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- 3. CIRCULAR GAUGE & STATISTIC CARDS -->
        <div style="margin-top: 26px; display: grid; grid-template-columns: auto 1fr; gap: 24px; align-items: center;" class="att-gauge-wrap">
          
          <!-- Circular Meter -->
          <?php
          $meterColor = '#10b981';
          if ($percentage < 65) $meterColor = '#ef4444';
          elseif ($percentage < 75) $meterColor = '#f59e0b';
          ?>
          <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px;">
            <div class="att-circle-meter" style="--meter-color: <?= $meterColor ?>; --meter-val: <?= min(100, $percentage) ?>;">
              <div class="att-circle-inner">
                <b style="color: <?= $meterColor ?>;"><?= $percentage ?>%</b>
                <small>Attendance</small>
              </div>
            </div>
          </div>

          <!-- Metric Cards Grid -->
          <div class="att-stat-grid">
            <div class="att-stat-box">
              <h3><?= $totalDays ?></h3>
              <small>Lectures Held</small>
            </div>
            <div class="att-stat-box">
              <h3 style="color: #10b981;"><?= $presentDays ?></h3>
              <small>Attended</small>
            </div>
            <div class="att-stat-box">
              <h3 style="color: #ef4444;"><?= $absentDays ?></h3>
              <small>Absent</small>
            </div>
            <div class="att-stat-box">
              <h3 style="color: #f59e0b;"><?= $leaveDays ?></h3>
              <small>Approved Leave</small>
            </div>
            <div class="att-stat-box">
              <h3 style="color: #3b82f6;"><?= $lateDays ?></h3>
              <small>Late Entries</small>
            </div>
          </div>
        </div>

        <!-- Progress Bar Bar Indicator -->
        <div style="margin-top: 14px;">
          <div class="att-progress-track" style="height: 10px;">
            <div class="att-progress-fill <?= $percentage >= 75 ? 'green' : ($percentage >= 65 ? 'orange' : 'red') ?>" style="width: <?= min(100, $percentage) ?>%;"></div>
          </div>
          <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--text-muted); margin-top: 4px;">
            <span>0% (Minimum)</span>
            <span style="color: #ef4444; font-weight: 700;">65% Warning Cutoff</span>
            <span style="color: #10b981; font-weight: 700;">75% University Mandatory Eligibility</span>
            <span>100% (Perfect)</span>
          </div>
        </div>
      </div>

      <!-- 4. DETAILED CLASSROOM ATTENDANCE REGISTER -->
      <div class="att-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
          <div>
            <h3 style="font-size: 18px; margin-bottom: 2px;">Day-by-Day Attendance Log</h3>
            <small style="color: var(--text-muted);">Official synchronized records from department academic ledger</small>
          </div>
          <span class="badge badge-primary">Total Verified Records: <?= count($records) ?></span>
        </div>

        <?php if (empty($records)): ?>
          <p style="text-align: center; color: #94a3b8; padding: 30px;">No attendance logged for this student yet.</p>
        <?php else: ?>
          <table class="admin-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Day</th>
                <th>Lecture / Session</th>
                <th>Status</th>
                <th>Faculty Remarks</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($records as $rec): ?>
                <?php
                $statusPill = 'badge-success';
                $icon = '✔';
                if ($rec['status'] === 'Absent') {
                    $statusPill = 'badge-danger';
                    $icon = '✖';
                } elseif ($rec['status'] === 'Leave') {
                    $statusPill = 'badge-warning';
                    $icon = '🏖';
                } elseif ($rec['status'] === 'Late') {
                    $statusPill = 'badge-primary';
                    $icon = '⏰';
                }
                $dTime = strtotime($rec['attendance_date']);
                ?>
                <tr>
                  <td><strong><?= date('d M Y', $dTime) ?></strong></td>
                  <td><span style="color: var(--text-muted);"><?= date('l', $dTime) ?></span></td>
                  <td>Classroom Lecture / Practical</td>
                  <td>
                    <span class="badge <?= $statusPill ?>" style="display: inline-flex; align-items: center; gap: 4px;">
                      <?= $icon ?> <?= h($rec['status']) ?>
                    </span>
                  </td>
                  <td><?= h($rec['remarks'] ?: 'Regular Session Attendance') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <!-- Institutional Signature Area for Print -->
        <div style="margin-top: 50px; display: none;" class="print-only">
          <div style="display: flex; justify-content: space-between; margin-top: 40px; padding: 0 20px;">
            <div style="text-align: center;">
              <div style="border-top: 1px solid #000; width: 180px; padding-top: 6px;">
                <strong>Student Signature</strong>
              </div>
            </div>
            <div style="text-align: center;">
              <div style="border-top: 1px solid #000; width: 180px; padding-top: 6px;">
                <strong>Head of Department</strong>
              </div>
            </div>
            <div style="text-align: center;">
              <div style="border-top: 1px solid #000; width: 180px; padding-top: 6px;">
                <strong>Registrar / Seal</strong>
              </div>
            </div>
          </div>
        </div>
      </div>

    <?php elseif (!$alertError): ?>
      
      <!-- DEFAULT STATE: How It Works & Information -->
      <div class="att-card" style="text-align: center; padding: 40px 24px;">
        <div style="font-size: 48px; margin-bottom: 12px;">📊</div>
        <h3 style="font-size: 22px; margin-bottom: 8px;">Check Your College Attendance</h3>
        <p style="color: var(--text-muted); max-width: 520px; margin: 0 auto 20px;">
          Enter your institutional Roll Number above to inspect your attendance percentage, subject sessions, and examination eligibility.
        </p>

        <div style="display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">
          <a class="btn" href="attendance.php?roll_no=BCA202601">Try Demo: Aarav (BCA) →</a>
          <a class="btn btn-secondary" href="attendance.php?roll_no=BBA202601">Try Demo: Rohan (BBA) →</a>
        </div>
      </div>

    <?php endif; ?>

  </main>

  <!-- Global Footer -->
  <footer>
    <div class="footer-top">
      <div>
        <h3 style="color:#fff; margin-bottom:12px;">School of Management Sciences</h3>
        <p style="color:var(--text-light); font-size:14px; line-height:1.7;">
          Autonomous Institution approved by AICTE & UGC. Committed to academic distinction, values, and holistic student development.
        </p>
      </div>

      <div>
        <h4 style="color:#fff; margin-bottom:12px;">Quick Links</h4>
        <ul style="list-style:none; padding:0; line-height:2.2; font-size:14px;">
          <li><a href="index.php?page=home" style="color:var(--text-light); text-decoration:none;">Home</a></li>
          <li><a href="index.php?page=about" style="color:var(--text-light); text-decoration:none;">About College</a></li>
          <li><a href="index.php?page=courses" style="color:var(--text-light); text-decoration:none;">Degree Programs</a></li>
          <li><a href="attendance.php" style="color:#34d399; text-decoration:none; font-weight:700;">📅 Attendance Portal</a></li>
          <li><a href="payment.php" style="color:var(--text-light); text-decoration:none;">💳 Fee Payment Gateway</a></li>
          <li><a href="admin/login.php" style="color:var(--text-light); text-decoration:none;">Faculty / Admin Login</a></li>
        </ul>
      </div>

      <div>
        <h4 style="color:#fff; margin-bottom:12px;">Attendance Regulations</h4>
        <p style="color:var(--text-light); font-size:13.5px; line-height:1.7;">
          As per university statutory guidelines, a minimum of <strong>75% classroom attendance</strong> in all enrolled theoretical and practical subjects is required to appear in Semester End Examinations.
        </p>
      </div>
    </div>

    <div class="footer-bottom">
      <div>&copy; 2026 School of Management Sciences (SMS). All Rights Reserved.</div>
      <div>Autonomous Academic Portal • Powered by PHP & MySQL</div>
    </div>
  </footer>

  <script>
    // Mobile navigation drawer toggle
    const toggleBtn = document.getElementById('toggle');
    const navMenu = document.getElementById('nav');
    if (toggleBtn && navMenu) {
      toggleBtn.addEventListener('click', () => {
        navMenu.classList.toggle('open');
      });
    }
  </script>

</body>
</html>
