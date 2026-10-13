<?php
require_once __DIR__ . '/../config.php';

if (empty($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$msg = '';
$err = '';
$section = $_GET['s'] ?? 'overview';
$valid_sections = ['overview', 'branches', 'students', 'fees', 'admissions', 'inquiries'];
if (!in_array($section, $valid_sections)) {
    $section = 'overview';
}

// Handle Admin POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Add Branch
    if (isset($_POST['add_branch'])) {
        $branch = trim($_POST['branch_name'] ?? '');
        if ($branch) {
            $q = $pdo->prepare('INSERT INTO branches(branch_name) VALUES(?)');
            $q->execute([$branch]);
            $msg = 'New academic branch added successfully.';
        }
    }
    // 2. Add Student
    elseif (isset($_POST['add_student'])) {
        $sName = trim($_POST['student_name'] ?? '');
        $roll = trim($_POST['roll_no'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $branchId = (int)($_POST['branch_id'] ?? 0) ?: null;

        if ($sName && $roll) {
            try {
                $q = $pdo->prepare('INSERT INTO students(student_name, roll_no, email, phone, branch_id) VALUES(?, ?, ?, ?, ?)');
                $q->execute([$sName, $roll, $email, $phone, $branchId]);
                $msg = "Student $sName (Roll: $roll) added successfully.";
            } catch (Throwable $e) {
                $err = 'Error adding student (check if Roll Number already exists): ' . $e->getMessage();
            }
        }
    }
    // 3. Add Fee Invoice
    elseif (isset($_POST['add_fee'])) {
        $stId = (int)($_POST['student_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $status = $_POST['status'] === 'Paid' ? 'Paid' : 'Pending';
        $remarks = trim($_POST['remarks'] ?? 'Semester Tuition Fee');
        $txnId = $status === 'Paid' ? ('TXN-ADM-' . date('Ymd') . '-' . rand(1000, 9999)) : null;

        if ($stId && $amount > 0) {
            $q = $pdo->prepare('INSERT INTO fees(student_id, amount, status, remarks, transaction_id, payment_mode, payment_date) VALUES(?, ?, ?, ?, ?, ?, ?)');
            $q->execute([$stId, $amount, $status, $remarks, $txnId, $status === 'Paid' ? 'Admin Cash Desk' : 'Pending Payment', $status === 'Paid' ? date('Y-m-d H:i:s') : null]);
            $msg = 'Fee record created successfully.';
        }
    }
    // 4. Mark Fee as Paid
    elseif (isset($_POST['mark_fee_paid'])) {
        $fId = (int)($_POST['fee_id'] ?? 0);
        $txn = 'TXN-DESK-' . date('Ymd') . '-' . rand(1000, 9999);
        $q = $pdo->prepare("UPDATE fees SET status = 'Paid', transaction_id = ?, payment_mode = 'Admin Counter', payment_date = NOW() WHERE id = ?");
        $q->execute([$txn, $fId]);
        $msg = "Fee record #$fId marked as PAID. Generated Ref: $txn";
    }
    // 5. Update Admission Status
    elseif (isset($_POST['update_admission'])) {
        $appId = (int)($_POST['admission_id'] ?? 0);
        $newStatus = in_array($_POST['status'], ['Approved', 'Rejected', 'Under Review']) ? $_POST['status'] : 'Under Review';
        $q = $pdo->prepare("UPDATE admissions SET status = ? WHERE id = ?");
        $q->execute([$newStatus, $appId]);
        $msg = "Admission application #$appId status updated to $newStatus.";
    }
    // 6. Delete Handler
    elseif (isset($_POST['deltype'], $_POST['delid'])) {
        $delType = $_POST['deltype'];
        $delId = (int)$_POST['delid'];
        $tableMap = [
            'branch' => 'branches',
            'student' => 'students',
            'fee' => 'fees',
            'admission' => 'admissions',
            'inquiry' => 'inquiries'
        ];
        if (isset($tableMap[$delType]) && $delId > 0) {
            $table = $tableMap[$delType];
            $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$delId]);
            $msg = ucfirst($delType) . " record deleted successfully.";
        }
    }
}

// Fetch KPI Metrics for Dashboard
$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$totalBranches = (int)$pdo->query("SELECT COUNT(*) FROM branches")->fetchColumn();
$totalApplications = (int)$pdo->query("SELECT COUNT(*) FROM admissions")->fetchColumn();
$totalInquiries = (int)$pdo->query("SELECT COUNT(*) FROM inquiries")->fetchColumn();

$totalPaidFees = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM fees WHERE status = 'Paid'")->fetchColumn();
$totalPendingFees = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM fees WHERE status = 'Pending'")->fetchColumn();

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Dashboard | School of Management Sciences (SMS)</title>
  <link rel="stylesheet" href="../assets/style.css">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📊</text></svg>">
</head>
<body class="admin-body">

  <!-- ADMIN TOPBAR -->
  <header class="admin-header">
    <a class="brand" href="dashboard.php">
      <b>SMS</b>
      <span>School of <span>Management Sciences</span>
        <small>Executive Control Dashboard</small>
      </span>
    </a>

    <div class="admin-user">
      <span>Signed in as: <strong><?= h($_SESSION['username'] ?? 'admin') ?></strong></span>
      <a href="../payment.php" target="_blank" style="color: #2563eb; font-weight: 700;">💳 Payment Gateway</a>
      <a href="../index.php" target="_blank">🌐 View Website</a>
      <a href="logout.php" style="color: #ef4444; font-weight: 700;">🚪 Logout</a>
    </div>
  </header>

  <!-- ADMIN NAVIGATION TABS -->
  <nav class="admin-nav">
    <a class="<?= $section === 'overview' ? 'active' : '' ?>" href="dashboard.php?s=overview">📊 Overview & KPI</a>
    <a class="<?= $section === 'branches' ? 'active' : '' ?>" href="dashboard.php?s=branches">🏛️ Branches (<?= $totalBranches ?>)</a>
    <a class="<?= $section === 'students' ? 'active' : '' ?>" href="dashboard.php?s=students">👨‍🎓 Students (<?= $totalStudents ?>)</a>
    <a class="<?= $section === 'fees' ? 'active' : '' ?>" href="dashboard.php?s=fees">💳 Fee Transactions</a>
    <a class="<?= $section === 'admissions' ? 'active' : '' ?>" href="dashboard.php?s=admissions">📝 Applications (<?= $totalApplications ?>)</a>
    <a class="<?= $section === 'inquiries' ? 'active' : '' ?>" href="dashboard.php?s=inquiries">💬 Inquiries (<?= $totalInquiries ?>)</a>
  </nav>

  <!-- MAIN CONTAINER -->
  <main class="admin-container">

    <!-- Flash Notifications -->
    <?php if ($msg): ?>
      <div class="alert alert-success">
        <span>✅</span>
        <div><?= h($msg) ?></div>
      </div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div class="alert alert-danger">
        <span>⚠️</span>
        <div><?= h($err) ?></div>
      </div>
    <?php endif; ?>

    <!-- 1. OVERVIEW SECTION -->
    <?php if ($section === 'overview'): ?>
      <div class="admin-stat-cards">
        <div class="admin-stat-card">
          <small>Total Enrolled Students</small>
          <h2><?= $totalStudents ?></h2>
          <span style="color: #10b981; font-size: 12px; font-weight: 700;">Active in Academic Database</span>
        </div>

        <div class="admin-stat-card">
          <small>Total Fee Revenue Collected</small>
          <h2 style="color: #10b981;">₹<?= number_format($totalPaidFees, 2) ?></h2>
          <span style="color: #64748b; font-size: 12px;">Verified Online & Desk Payments</span>
        </div>

        <div class="admin-stat-card">
          <small>Pending Fee Invoices</small>
          <h2 style="color: #ef4444;">₹<?= number_format($totalPendingFees, 2) ?></h2>
          <span style="color: #ef4444; font-size: 12px; font-weight: 700;">Awaiting Student Clearance</span>
        </div>

        <div class="admin-stat-card">
          <small>Online Applications</small>
          <h2 style="color: #2563eb;"><?= $totalApplications ?></h2>
          <span style="color: #64748b; font-size: 12px;">Submitted via Public Portal</span>
        </div>
      </div>

      <!-- Recent Transactions Overview -->
      <div class="admin-panel">
        <div class="admin-panel-title">
          <h3>Recent Fee Transactions</h3>
          <a class="btn btn-secondary" href="dashboard.php?s=fees" style="padding: 6px 14px; font-size: 12px;">Manage All Fees →</a>
        </div>

        <table class="admin-table">
          <thead>
            <tr>
              <th>Txn Ref</th>
              <th>Student</th>
              <th>Roll Number</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Payment Mode</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $recTxns = $pdo->query("
              SELECT f.*, s.student_name, s.roll_no 
              FROM fees f 
              JOIN students s ON s.id = f.student_id 
              ORDER BY f.id DESC LIMIT 5
            ")->fetchAll();
            ?>
            <?php if (empty($recTxns)): ?>
              <tr><td colspan="7" style="text-align: center; color: #94a3b8;">No fee records recorded yet.</td></tr>
            <?php else: ?>
              <?php foreach ($recTxns as $t): ?>
                <tr>
                  <td><strong><?= h($t['transaction_id'] ?: 'PENDING') ?></strong></td>
                  <td><?= h($t['student_name']) ?></td>
                  <td><code><?= h($t['roll_no']) ?></code></td>
                  <td><strong>₹<?= number_format((float)$t['amount'], 2) ?></strong></td>
                  <td>
                    <span class="badge <?= $t['status'] === 'Paid' ? 'badge-success' : 'badge-danger' ?>">
                      <?= h($t['status']) ?>
                    </span>
                  </td>
                  <td><?= h($t['payment_mode'] ?: 'N/A') ?></td>
                  <td><?= h($t['payment_date'] ? date('d M Y, h:i A', strtotime($t['payment_date'])) : '—') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    <!-- 2. BRANCHES SECTION -->
    <?php elseif ($section === 'branches'): ?>
      <div class="admin-panel">
        <div class="admin-panel-title">
          <h3>Add Academic Branch / Department</h3>
        </div>
        <form method="post" action="dashboard.php?s=branches" style="display: flex; gap: 12px; max-width: 600px;">
          <input type="hidden" name="add_branch" value="1">
          <input class="form-control" name="branch_name" required placeholder="e.g. Artificial Intelligence & Robotics">
          <button class="btn" type="submit">Add Branch</button>
        </form>
      </div>

      <div class="admin-panel">
        <div class="admin-panel-title">
          <h3>All Departments & Branches</h3>
        </div>
        <table class="admin-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Branch Name</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pdo->query("SELECT * FROM branches ORDER BY id ASC") as $b): ?>
              <tr>
                <td>#<?= $b['id'] ?></td>
                <td><strong><?= h($b['branch_name']) ?></strong></td>
                <td>
                  <form method="post" action="dashboard.php?s=branches" onsubmit="return confirm('Delete this branch?');" style="display:inline;">
                    <input type="hidden" name="deltype" value="branch">
                    <input type="hidden" name="delid" value="<?= $b['id'] ?>">
                    <button class="btn btn-secondary" style="color: #dc2626; padding: 6px 12px; font-size: 11px;">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <!-- 3. STUDENTS SECTION -->
    <?php elseif ($section === 'students'): ?>
      <div class="admin-panel">
        <div class="admin-panel-title">
          <h3>Register New Student</h3>
        </div>
        <form method="post" action="dashboard.php?s=students">
          <input type="hidden" name="add_student" value="1">
          <div class="form-row">
            <div class="form-group">
              <label>Student Full Name *</label>
              <input class="form-control" name="student_name" required placeholder="e.g. Vikrant Saxena">
            </div>
            <div class="form-group">
              <label>Roll Number * (Unique)</label>
              <input class="form-control" name="roll_no" required placeholder="e.g. BCA202605">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Email Address</label>
              <input class="form-control" type="email" name="email" placeholder="e.g. vikrant@example.com">
            </div>
            <div class="form-group">
              <label>Phone Number</label>
              <input class="form-control" name="phone" placeholder="e.g. 9811223344">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Department / Branch</label>
              <select class="form-control" name="branch_id">
                <option value="">Select Branch</option>
                <?php foreach ($pdo->query("SELECT * FROM branches") as $br): ?>
                  <option value="<?= $br['id'] ?>"><?= h($br['branch_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="display: flex; align-items: flex-end;">
              <button class="btn" style="width: 100%;" type="submit">Save Student Record</button>
            </div>
          </div>
        </form>
      </div>

      <div class="admin-panel">
        <div class="admin-panel-title">
          <h3>Enrolled Student Directory</h3>
        </div>
        <table class="admin-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Roll Number</th>
              <th>Student Name</th>
              <th>Branch</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $students = $pdo->query("
              SELECT s.*, b.branch_name 
              FROM students s 
              LEFT JOIN branches b ON b.id = s.branch_id 
              ORDER BY s.id DESC
            ")->fetchAll();
            ?>
            <?php foreach ($students as $st): ?>
              <tr>
                <td>#<?= $st['id'] ?></td>
                <td><strong style="color: var(--primary);"><?= h($st['roll_no']) ?></strong></td>
                <td><strong><?= h($st['student_name']) ?></strong></td>
                <td><?= h($st['branch_name'] ?? 'General') ?></td>
                <td><?= h($st['email'] ?: '—') ?></td>
                <td><?= h($st['phone'] ?: '—') ?></td>
                <td>
                  <form method="post" action="dashboard.php?s=students" onsubmit="return confirm('Delete this student?');" style="display:inline;">
                    <input type="hidden" name="deltype" value="student">
                    <input type="hidden" name="delid" value="<?= $st['id'] ?>">
                    <button class="btn btn-secondary" style="color: #dc2626; padding: 6px 12px; font-size: 11px;">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <!-- 4. FEES & TRANSACTIONS SECTION -->
    <?php elseif ($section === 'fees'): ?>
      <div class="admin-panel">
        <div class="admin-panel-title">
          <h3>Issue New Fee Voucher / Invoice</h3>
        </div>
        <form method="post" action="dashboard.php?s=fees">
          <input type="hidden" name="add_fee" value="1">
          <div class="form-row">
            <div class="form-group">
              <label>Select Student *</label>
              <select class="form-control" name="student_id" required>
                <option value="">Choose Student</option>
                <?php foreach ($pdo->query("SELECT id, student_name, roll_no FROM students ORDER BY student_name ASC") as $sRow): ?>
                  <option value="<?= $sRow['id'] ?>"><?= h($sRow['student_name'] . ' (' . $sRow['roll_no'] . ')') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Fee Amount (₹ INR) *</label>
              <input class="form-control" type="number" step="0.01" min="1" name="amount" required placeholder="e.g. 24500.00">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Initial Status</label>
              <select class="form-control" name="status">
                <option value="Pending">Pending (Student can pay online)</option>
                <option value="Paid">Paid (Cash Desk Collection)</option>
              </select>
            </div>
            <div class="form-group">
              <label>Remarks / Invoice Description</label>
              <input class="form-control" name="remarks" placeholder="e.g. Semester 5 Tuition Fee">
            </div>
          </div>
          <button class="btn" type="submit">Issue Fee Voucher</button>
        </form>
      </div>

      <div class="admin-panel">
        <div class="admin-panel-title">
          <h3>All Fee Records & Transaction Ledgers</h3>
        </div>
        <table class="admin-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Roll No</th>
              <th>Student</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Txn ID</th>
              <th>Mode</th>
              <th>Date</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $allFees = $pdo->query("
              SELECT f.*, s.student_name, s.roll_no 
              FROM fees f 
              JOIN students s ON s.id = f.student_id 
              ORDER BY f.id DESC
            ")->fetchAll();
            ?>
            <?php foreach ($allFees as $f): ?>
              <tr>
                <td>#<?= $f['id'] ?></td>
                <td><code><?= h($f['roll_no']) ?></code></td>
                <td><strong><?= h($f['student_name']) ?></strong></td>
                <td><strong>₹<?= number_format((float)$f['amount'], 2) ?></strong></td>
                <td>
                  <span class="badge <?= $f['status'] === 'Paid' ? 'badge-success' : 'badge-danger' ?>">
                    <?= h($f['status']) ?>
                  </span>
                </td>
                <td><small><?= h($f['transaction_id'] ?: 'Pending') ?></small></td>
                <td><small><?= h($f['payment_mode'] ?: 'N/A') ?></small></td>
                <td><small><?= h($f['payment_date'] ? date('d M Y', strtotime($f['payment_date'])) : '—') ?></small></td>
                <td>
                  <div style="display: flex; gap: 6px;">
                    <?php if ($f['status'] !== 'Paid'): ?>
                      <form method="post" action="dashboard.php?s=fees" style="display:inline;">
                        <input type="hidden" name="mark_fee_paid" value="1">
                        <input type="hidden" name="fee_id" value="<?= $f['id'] ?>">
                        <button class="btn btn-success" style="padding: 6px 10px; font-size: 11px;">Mark Paid</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" action="dashboard.php?s=fees" onsubmit="return confirm('Delete this fee record?');" style="display:inline;">
                      <input type="hidden" name="deltype" value="fee">
                      <input type="hidden" name="delid" value="<?= $f['id'] ?>">
                      <button class="btn btn-secondary" style="color: #dc2626; padding: 6px 10px; font-size: 11px;">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <!-- 5. ADMISSION APPLICATIONS SECTION -->
    <?php elseif ($section === 'admissions'): ?>
      <div class="admin-panel">
        <div class="admin-panel-title">
          <h3>Online Admission Applications</h3>
        </div>
        <table class="admin-table">
          <thead>
            <tr>
              <th>App No</th>
              <th>Applicant Name</th>
              <th>Course Applied</th>
              <th>Score %</th>
              <th>Phone & Email</th>
              <th>Status</th>
              <th>Update Status</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $admissions = $pdo->query("SELECT * FROM admissions ORDER BY id DESC")->fetchAll();
            ?>
            <?php if (empty($admissions)): ?>
              <tr><td colspan="7" style="text-align: center; color: #94a3b8;">No admission applications submitted yet.</td></tr>
            <?php else: ?>
              <?php foreach ($admissions as $adm): ?>
                <tr>
                  <td><strong style="color: var(--primary);"><?= h($adm['application_no']) ?></strong></td>
                  <td><strong><?= h($adm['full_name']) ?></strong></td>
                  <td><?= h($adm['course_name']) ?></td>
                  <td><span class="badge badge-primary"><?= number_format((float)$adm['score_percent'], 1) ?>%</span></td>
                  <td>
                    <div><?= h($adm['phone']) ?></div>
                    <small style="color: #64748b;"><?= h($adm['email']) ?></small>
                  </td>
                  <td>
                    <?php
                    $badgeClass = 'badge-warning';
                    if ($adm['status'] === 'Approved') $badgeClass = 'badge-success';
                    if ($adm['status'] === 'Rejected') $badgeClass = 'badge-danger';
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= h($adm['status']) ?></span>
                  </td>
                  <td>
                    <form method="post" action="dashboard.php?s=admissions" style="display: flex; gap: 6px;">
                      <input type="hidden" name="update_admission" value="1">
                      <input type="hidden" name="admission_id" value="<?= $adm['id'] ?>">
                      <select name="status" class="form-control" style="padding: 4px 8px; font-size: 12px; width: auto;">
                        <option value="Under Review" <?= $adm['status'] === 'Under Review' ? 'selected' : '' ?>>Under Review</option>
                        <option value="Approved" <?= $adm['status'] === 'Approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="Rejected" <?= $adm['status'] === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                      </select>
                      <button class="btn btn-secondary" style="padding: 4px 10px; font-size: 11px;">Update</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    <!-- 6. INQUIRIES SECTION -->
    <?php elseif ($section === 'inquiries'): ?>
      <div class="admin-panel">
        <div class="admin-panel-title">
          <h3>Public Contact Inquiries</h3>
        </div>
        <table class="admin-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Contact Info</th>
              <th>Subject</th>
              <th>Message</th>
              <th>Date</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $inquiries = $pdo->query("SELECT * FROM inquiries ORDER BY id DESC")->fetchAll();
            ?>
            <?php if (empty($inquiries)): ?>
              <tr><td colspan="7" style="text-align: center; color: #94a3b8;">No inquiries recorded yet.</td></tr>
            <?php else: ?>
              <?php foreach ($inquiries as $inq): ?>
                <tr>
                  <td>#<?= $inq['id'] ?></td>
                  <td><strong><?= h($inq['name']) ?></strong></td>
                  <td>
                    <div><?= h($inq['email']) ?></div>
                    <small style="color: #64748b;"><?= h($inq['phone'] ?: 'No phone') ?></small>
                  </td>
                  <td><strong><?= h($inq['subject']) ?></strong></td>
                  <td><p style="max-width: 320px; font-size: 12.5px;"><?= h($inq['message']) ?></p></td>
                  <td><small><?= h(date('d M Y, h:i A', strtotime($inq['created_at']))) ?></small></td>
                  <td>
                    <form method="post" action="dashboard.php?s=inquiries" onsubmit="return confirm('Delete this inquiry?');">
                      <input type="hidden" name="deltype" value="inquiry">
                      <input type="hidden" name="delid" value="<?= $inq['id'] ?>">
                      <button class="btn btn-secondary" style="color: #dc2626; padding: 6px 12px; font-size: 11px;">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>

  </main>

</body>
</html>