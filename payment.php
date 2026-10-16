<?php
require_once __DIR__ . '/config.php';

$pdo = db();

// --- AJAX API: Instant Student & Due Invoice Lookup ---
if (isset($_GET['api']) && $_GET['api'] === 'lookup_student') {
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
            $pf = $pdo->prepare("SELECT * FROM fees WHERE student_id = ? AND status = 'Pending' ORDER BY id ASC LIMIT 1");
            $pf->execute([$student['id']]);
            $pendingFee = $pf->fetch();

            echo json_encode([
                'found' => true,
                'student' => $student,
                'pending_fee' => $pendingFee ?: null
            ]);
        } else {
            echo json_encode(['found' => false]);
        }
    } catch (Throwable $e) {
        echo json_encode(['found' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$alert_success = '';
$alert_error = '';
$transactionReceipt = null;
$searchedStudent = null;
$pendingFees = [];
$paidFees = [];

// Determine default or requested tab
$activeTab = $_GET['active'] ?? 'quick_pay';
if (!in_array($activeTab, ['quick_pay', 'search', 'track', 'receipt'])) {
    $activeTab = 'quick_pay';
}

// Prefill values
$prefillRoll = strtoupper(trim($_GET['roll_no'] ?? ''));
$prefillStudentName = '';
$prefillEmail = '';
$prefillPhone = '';
$prefillBranchId = 0;
$prefillAmount = trim($_GET['amount'] ?? '');
$prefillCategory = trim($_GET['category'] ?? 'Semester Tuition Fee');
$prefillFeeId = (int)($_GET['fee_id'] ?? 0);

// Load Branches
$branches = [];
try {
    $branches = $pdo->query("SELECT id, branch_name FROM branches ORDER BY id ASC")->fetchAll();
} catch (Throwable $e) {
    $branches = [];
}

// If specific pending fee ID was selected to pay
if ($prefillFeeId > 0) {
    try {
        $pFee = $pdo->prepare("
            SELECT f.*, s.student_name, s.roll_no, s.email, s.phone, s.branch_id, b.branch_name 
            FROM fees f
            JOIN students s ON s.id = f.student_id
            LEFT JOIN branches b ON b.id = s.branch_id
            WHERE f.id = ?
        ");
        $pFee->execute([$prefillFeeId]);
        $tFee = $pFee->fetch();
        if ($tFee) {
            $prefillRoll = $tFee['roll_no'];
            $prefillStudentName = $tFee['student_name'];
            $prefillEmail = $tFee['email'] ?? '';
            $prefillPhone = $tFee['phone'] ?? '';
            $prefillBranchId = (int)$tFee['branch_id'];
            $prefillAmount = (float)$tFee['amount'];
            $prefillCategory = $tFee['remarks'] ?: 'Semester Tuition Fee';
            $activeTab = 'quick_pay';
        }
    } catch (Throwable $e) {}
} elseif ($prefillRoll) {
    try {
        $sLookup = $pdo->prepare("SELECT s.*, b.branch_name FROM students s LEFT JOIN branches b ON b.id = s.branch_id WHERE s.roll_no = ?");
        $sLookup->execute([$prefillRoll]);
        $sRow = $sLookup->fetch();
        if ($sRow) {
            $prefillStudentName = $sRow['student_name'];
            $prefillEmail = $sRow['email'] ?? '';
            $prefillPhone = $sRow['phone'] ?? '';
            $prefillBranchId = (int)$sRow['branch_id'];
        }
    } catch (Throwable $e) {}
}

// 1. HANDLE PAYMENT SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_payment') {
    $studentName = trim($_POST['student_name'] ?? '');
    $rollNo = strtoupper(trim($_POST['roll_no'] ?? ''));
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $branchId = (int)($_POST['branch_id'] ?? 0) ?: null;
    $feeCategory = trim($_POST['fee_category'] ?? 'Semester Tuition Fee');
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMode = trim($_POST['payment_mode'] ?? 'UPI / QR Code');
    $upiId = trim($_POST['upi_id'] ?? '');
    $cardNumber = trim($_POST['card_number'] ?? '');
    $bankName = trim($_POST['bank_name'] ?? '');
    $existingFeeId = (int)($_POST['existing_fee_id'] ?? 0);

    if (empty($studentName) || empty($rollNo) || $amount <= 0) {
        $alert_error = 'Please fill in Student Name, Roll Number, and a valid amount.';
    } else {
        try {
            $pdo->beginTransaction();

            $modeDetails = $paymentMode;
            if (strpos($paymentMode, 'UPI') !== false && $upiId) {
                $modeDetails .= " ($upiId)";
            } elseif (strpos($paymentMode, 'Card') !== false && $cardNumber) {
                $cleanCard = str_replace(' ', '', $cardNumber);
                $modeDetails .= " (Ending " . substr($cleanCard, -4) . ")";
            } elseif (strpos($paymentMode, 'Net Banking') !== false && $bankName) {
                $modeDetails .= " ($bankName)";
            }

            // Generate official Transaction Reference
            $txnId = 'TXN-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));

            // Case A: Paying an existing pending fee voucher
            if ($existingFeeId > 0) {
                $chkFee = $pdo->prepare("SELECT * FROM fees WHERE id = ?");
                $chkFee->execute([$existingFeeId]);
                $feeRecord = $chkFee->fetch();

                if ($feeRecord) {
                    $remarks = $feeRecord['remarks'] ?: $feeCategory;
                    $updateStmt = $pdo->prepare("
                        UPDATE fees 
                        SET status = 'Paid', transaction_id = ?, payment_mode = ?, payment_date = NOW(), remarks = ? 
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$txnId, $modeDetails, $remarks, $existingFeeId]);
                    $targetFeeId = $existingFeeId;

                    // Also sync student details if provided
                    $syncSt = $pdo->prepare("
                        UPDATE students 
                        SET student_name = COALESCE(NULLIF(?, ''), student_name),
                            email = COALESCE(NULLIF(?, ''), email),
                            phone = COALESCE(NULLIF(?, ''), phone),
                            branch_id = COALESCE(NULLIF(?, 0), branch_id)
                        WHERE id = ?
                    ");
                    $syncSt->execute([$studentName, $email, $phone, $branchId, $feeRecord['student_id']]);
                } else {
                    throw new Exception("Referenced fee record not found.");
                }
            } else {
                // Case B: Direct Quick Pay - Check or Create Student
                $chkStudent = $pdo->prepare("SELECT id FROM students WHERE roll_no = ?");
                $chkStudent->execute([$rollNo]);
                $existingStudent = $chkStudent->fetch();

                if ($existingStudent) {
                    $studentId = $existingStudent['id'];
                    $updStudent = $pdo->prepare("
                        UPDATE students 
                        SET student_name = COALESCE(NULLIF(?, ''), student_name),
                            email = COALESCE(NULLIF(?, ''), email),
                            phone = COALESCE(NULLIF(?, ''), phone),
                            branch_id = COALESCE(NULLIF(?, 0), branch_id)
                        WHERE id = ?
                    ");
                    $updStudent->execute([$studentName, $email, $phone, $branchId, $studentId]);
                } else {
                    $insStudent = $pdo->prepare("
                        INSERT INTO students (student_name, roll_no, email, phone, branch_id) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $insStudent->execute([$studentName, $rollNo, $email, $phone, $branchId]);
                    $studentId = $pdo->lastInsertId();
                }

                // Create paid fee entry
                $insFee = $pdo->prepare("
                    INSERT INTO fees (student_id, amount, status, transaction_id, payment_mode, payment_date, remarks) 
                    VALUES (?, ?, 'Paid', ?, ?, NOW(), ?)
                ");
                $insFee->execute([$studentId, $amount, $txnId, $modeDetails, $feeCategory]);
                $targetFeeId = $pdo->lastInsertId();
            }

            $pdo->commit();

            // Fetch complete receipt
            $receiptStmt = $pdo->prepare("
                SELECT f.*, s.student_name, s.roll_no, s.email, s.phone, b.branch_name 
                FROM fees f
                JOIN students s ON s.id = f.student_id
                LEFT JOIN branches b ON b.id = s.branch_id
                WHERE f.id = ?
            ");
            $receiptStmt->execute([$targetFeeId]);
            $transactionReceipt = $receiptStmt->fetch();

            // Dispatch Official E-Receipt message to given email
            $emailNotice = '';
            if ($transactionReceipt && !empty($transactionReceipt['email'])) {
                $emailResult = send_payment_receipt_email($transactionReceipt);
                if ($emailResult['success']) {
                    $emailNotice = " A confirmation receipt message has been dispatched to <strong>" . htmlspecialchars($emailResult['email']) . "</strong>.";
                }
            }

            $alert_success = "Payment verified and recorded! Transaction Reference: <strong>$txnId</strong>." . $emailNotice . " Official E-Receipt is ready below.";
            $activeTab = 'receipt';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $alert_error = 'Transaction could not be completed: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// 2. HANDLE SEARCH (By Roll No or TXN ID)
if (isset($_GET['search_query']) && trim($_GET['search_query']) !== '') {
    $query = trim($_GET['search_query']);

    if (strpos(strtoupper($query), 'TXN-') === 0) {
        // Direct receipt lookup
        try {
            $txnSearch = $pdo->prepare("
                SELECT f.*, s.student_name, s.roll_no, s.email, s.phone, b.branch_name 
                FROM fees f
                JOIN students s ON s.id = f.student_id
                LEFT JOIN branches b ON b.id = s.branch_id
                WHERE f.transaction_id = ?
            ");
            $txnSearch->execute([$query]);
            $transactionReceipt = $txnSearch->fetch();

            if ($transactionReceipt) {
                $activeTab = 'receipt';
            } else {
                $alert_error = "No transaction record found with Reference: " . htmlspecialchars($query);
                $activeTab = 'track';
            }
        } catch (Throwable $e) {
            $alert_error = "Error locating transaction: " . htmlspecialchars($e->getMessage());
            $activeTab = 'track';
        }
    } else {
        // Student invoice lookup
        $activeTab = 'search';
        try {
            $stQuery = $pdo->prepare("
                SELECT s.*, b.branch_name 
                FROM students s
                LEFT JOIN branches b ON b.id = s.branch_id
                WHERE s.roll_no = ? OR s.email = ?
            ");
            $stQuery->execute([$query, $query]);
            $searchedStudent = $stQuery->fetch();

            if ($searchedStudent) {
                $fQuery = $pdo->prepare("SELECT * FROM fees WHERE student_id = ? ORDER BY id DESC");
                $fQuery->execute([$searchedStudent['id']]);
                $allFees = $fQuery->fetchAll();

                foreach ($allFees as $fee) {
                    if ($fee['status'] === 'Paid') {
                        $paidFees[] = $fee;
                    } else {
                        $pendingFees[] = $fee;
                    }
                }
            } else {
                $alert_error = "No student record found with Roll Number or Email: " . htmlspecialchars($query);
            }
        } catch (Throwable $e) {
            $alert_error = "Error searching student records: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Online Fee Payment Gateway | School of Management Sciences (SMS)</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💳</text></svg>">
  <style>
    /* Global Viewport Overflow Fix */
    html, body {
      width: 100%;
      max-width: 100%;
      overflow-x: hidden;
      margin: 0;
      padding: 0;
    }

    *, *::before, *::after {
      box-sizing: border-box;
    }

    /* Payment Container */
    .pay-container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 30px 20px 80px;
      width: 100%;
      box-sizing: border-box;
    }

    .pay-nav-tabs {
      display: flex;
      gap: 12px;
      margin-bottom: 30px;
      border-bottom: 2px solid var(--border);
      padding-bottom: 12px;
      flex-wrap: wrap;
    }

    .pay-tab-link {
      padding: 12px 22px;
      border-radius: var(--radius-sm);
      font-weight: 700;
      font-size: 14px;
      background: #f1f5f9;
      color: var(--text-muted);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: var(--transition);
      text-decoration: none;
      border: 1px solid var(--border);
    }

    .pay-tab-link:hover {
      background: var(--primary-light);
      color: var(--primary);
    }

    .pay-tab-link.active {
      color: #ffffff;
      background: var(--primary);
      border-color: var(--primary);
      box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
    }

    .pay-card {
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 34px;
      box-shadow: var(--shadow-md);
      position: relative;
      box-sizing: border-box;
      max-width: 100%;
    }

    .pay-grid {
      display: grid;
      grid-template-columns: 1fr 380px;
      gap: 32px;
      align-items: start;
    }

    .amount-presets {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(95px, 1fr));
      gap: 10px;
      margin-top: 8px;
      margin-bottom: 16px;
    }

    .amount-chip {
      background: var(--bg-main);
      border: 1px solid var(--border);
      padding: 8px 10px;
      border-radius: var(--radius-sm);
      text-align: center;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: var(--transition);
      user-select: none;
    }

    .amount-chip:hover, .amount-chip.selected {
      background: var(--primary-light);
      border-color: var(--primary);
      color: var(--primary);
    }

    .payment-tabs {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 10px;
      margin-bottom: 24px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--border);
    }

    .payment-tab {
      padding: 12px 10px;
      border-radius: var(--radius-md);
      border: 1px solid var(--border);
      background: #fff;
      cursor: pointer;
      font-weight: 700;
      font-size: 13px;
      color: var(--text-muted);
      transition: var(--transition);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      gap: 6px;
      min-height: 70px;
      user-select: none;
    }

    .payment-tab.active {
      background: var(--primary);
      color: #fff;
      border-color: var(--primary);
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    }

    .upi-qr-row {
      display: flex;
      gap: 24px;
      align-items: center;
      flex-wrap: wrap;
    }

    .upi-qr-box {
      background: #ffffff;
      padding: 14px;
      border: 1px solid var(--border);
      border-radius: 12px;
      text-align: center;
      box-shadow: var(--shadow-sm);
      flex-shrink: 0;
    }

    .upi-qr-form {
      flex: 1;
      min-width: 200px;
    }

    .pay-summary-box {
      background: linear-gradient(145deg, #0f172a, #1e293b);
      border-radius: var(--radius-lg);
      color: #ffffff;
      padding: 30px;
      box-shadow: var(--shadow-lg);
      position: sticky;
      top: 100px;
    }

    .pay-summary-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px 0;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      font-size: 13.5px;
    }

    .pay-summary-row:last-child {
      border-bottom: none;
      padding-top: 18px;
      margin-top: 6px;
    }

    .pay-summary-total {
      font-family: 'Outfit', sans-serif;
      font-size: 32px;
      font-weight: 800;
      color: #38bdf8;
    }

    .trust-badges {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
      margin-top: 24px;
      padding-top: 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.12);
    }

    .trust-badge-item {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 11px;
      color: #94a3b8;
    }

    .trust-badge-item span {
      font-size: 18px;
    }

    .bank-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
      gap: 10px;
      margin-bottom: 16px;
    }

    .bank-item {
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 10px 8px;
      text-align: center;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      background: #ffffff;
      transition: var(--transition);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
    }

    .bank-item:hover, .bank-item.active {
      border-color: var(--primary);
      background: var(--primary-light);
      color: var(--primary);
    }

    .autofill-indicator {
      display: none;
      margin-top: 6px;
      font-size: 12px;
      padding: 6px 12px;
      border-radius: 6px;
    }

    .autofill-indicator.found {
      display: inline-block;
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid #a7f3d0;
    }

    .pending-fee-banner {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 10px;
      padding: 14px 18px;
      margin-bottom: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
    }

    /* TABLET RESPONSIVE (max-width: 992px) */
    @media (max-width: 992px) {
      .pay-grid {
        grid-template-columns: 1fr;
      }
      .pay-summary-box {
        position: static;
        margin-top: 24px;
        padding: 24px;
      }
    }

    /* MOBILE RESPONSIVE (max-width: 768px) */
    @media (max-width: 768px) {
      /* Pure Solid Header with No Overflow & No Overlap */
      header {
        height: auto !important;
        min-height: 58px !important;
        padding: 8px 14px !important;
        background: #ffffff !important;
        position: sticky !important;
        top: 0 !important;
        z-index: 9999 !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08) !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        width: 100% !important;
        max-width: 100% !important;
      }

      .brand {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        text-decoration: none !important;
        flex: 1 !important;
        min-width: 0 !important;
      }

      .brand b {
        width: 36px !important;
        height: 36px !important;
        min-width: 36px !important;
        max-width: 36px !important;
        font-size: 14px !important;
        border-radius: 8px !important;
        display: grid !important;
        place-items: center !important;
        padding: 0 !important;
        flex-shrink: 0 !important;
      }

      .brand span {
        font-size: 15px !important;
        line-height: 1.15 !important;
        font-weight: 800 !important;
        white-space: normal !important;
        word-break: break-word !important;
        display: block !important;
        overflow: hidden !important;
      }

      .brand small {
        display: none !important;
      }

      #toggle {
        flex-shrink: 0 !important;
        width: 38px !important;
        height: 38px !important;
        font-size: 20px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        background: #f8fafc !important;
        border: 1px solid var(--border) !important;
        border-radius: 8px !important;
        padding: 0 !important;
        margin-left: 8px !important;
      }

      /* Container & Card Padding on Mobile */
      .pay-container {
        padding: 16px 12px 60px !important;
        width: 100% !important;
        max-width: 100% !important;
      }

      .pay-card {
        padding: 18px 14px !important;
        border-radius: 16px !important;
        width: 100% !important;
      }

      /* Stack Navigation Tabs on Mobile */
      .pay-nav-tabs {
        display: flex !important;
        flex-direction: column !important;
        gap: 8px !important;
        margin-bottom: 18px !important;
        border-bottom: none !important;
        padding-bottom: 0 !important;
      }

      .pay-tab-link {
        width: 100% !important;
        justify-content: center !important;
        padding: 12px 14px !important;
        font-size: 13.5px !important;
        box-sizing: border-box !important;
      }

      /* Quick Amount Presets: 3 equal columns */
      .amount-presets {
        display: grid !important;
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 6px !important;
        width: 100% !important;
      }

      .amount-chip {
        padding: 8px 4px !important;
        font-size: 12.5px !important;
        text-align: center !important;
        white-space: nowrap !important;
      }

      /* Payment Channels: 2x2 Grid instead of stretched row */
      .payment-tabs {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 8px !important;
        width: 100% !important;
        margin-bottom: 18px !important;
        border-bottom: none !important;
        padding-bottom: 0 !important;
      }

      .payment-tab {
        width: 100% !important;
        padding: 12px 6px !important;
        font-size: 12px !important;
        min-height: 64px !important;
        border-radius: 12px !important;
        white-space: normal !important;
        box-sizing: border-box !important;
      }

      /* UPI QR Code: Center and Stack on Mobile */
      .upi-qr-row {
        flex-direction: column !important;
        align-items: center !important;
        text-align: center !important;
        gap: 16px !important;
      }

      .upi-qr-box {
        margin: 0 auto !important;
        width: 160px !important;
        padding: 12px !important;
      }

      .upi-qr-box img {
        width: 130px !important;
        height: 130px !important;
        margin: 0 auto !important;
      }

      .upi-qr-form {
        width: 100% !important;
        min-width: 0 !important;
      }

      /* Net Banking Grid */
      .bank-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 8px !important;
      }

      /* Card Simulator on Mobile */
      .card-mockup {
        padding: 16px 14px !important;
        margin-bottom: 18px !important;
      }

      .card-number-display {
        font-size: 15px !important;
        letter-spacing: 1px !important;
        margin-bottom: 12px !important;
      }

      /* Form inputs on Mobile: 16px to stop iOS auto-zoom */
      .form-row {
        grid-template-columns: 1fr !important;
        gap: 0 !important;
      }

      .form-control, input, select, textarea {
        font-size: 16px !important;
        width: 100% !important;
        max-width: 100% !important;
      }

      .btn {
        width: 100% !important;
        justify-content: center !important;
      }

      .pay-summary-total {
        font-size: 26px !important;
      }

      .pending-fee-banner {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 10px !important;
      }

      .pending-fee-banner .btn {
        width: 100% !important;
        text-align: center !important;
      }
    }

    @media print {
      header, footer, .pay-nav-tabs, .hero-actions, .no-print, button, .alert {
        display: none !important;
      }
      body {
        background: #fff !important;
        color: #000 !important;
      }
      .pay-container {
        padding: 0 !important;
        max-width: 100% !important;
      }
      .pay-card {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
      }
      .receipt-wrapper {
        border: 2px solid #000 !important;
        box-shadow: none !important;
      }
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
      <a href="attendance.php" style="color: #10b981; font-weight: 700;">📅 Attendance</a>
      <a class="active highlight-nav" href="payment.php">💳 Online Payment</a>
      <a href="index.php?page=faculty">Faculty</a>
      <a href="index.php?page=contact">Contact</a>
      <a class="nav-admin" href="admin/login.php">Admin Portal ↗</a>
    </nav>
  </header>

  <!-- Global Notification Alerts -->
  <?php if ($alert_success): ?>
    <div style="padding: 20px 6% 0;">
      <div class="alert alert-success">
        <span>✅</span>
        <div><?= $alert_success ?></div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($alert_error): ?>
    <div style="padding: 20px 6% 0;">
      <div class="alert alert-danger">
        <span>⚠️</span>
        <div><?= $alert_error ?></div>
      </div>
    </div>
  <?php endif; ?>

  <!-- PAGE HERO -->
  <section class="pagehero">
    <span class="badge badge-primary">256-BIT SSL ENCRYPTED GATEWAY</span>
    <h1>Online Student Fee Portal<br><em>& Institutional E-Receipt Desk</em></h1>
    <p>Directly authorize semester tuition fees, admission deposits, or examination charges with real-time database recording and verified digital receipts.</p>
  </section>

  <!-- MAIN PAYMENT WORKSPACE -->
  <main class="pay-container">

    <!-- TAB SELECTOR (Hidden in print mode or when displaying full receipt) -->
    <?php if ($activeTab !== 'receipt'): ?>
      <div class="pay-nav-tabs no-print">
        <a href="payment.php?active=quick_pay" class="pay-tab-link <?= $activeTab === 'quick_pay' ? 'active' : '' ?>">
          ⚡ Quick Fee Payment
        </a>
        <a href="payment.php?active=search" class="pay-tab-link <?= $activeTab === 'search' ? 'active' : '' ?>">
          🔍 Search Student Due Invoices
        </a>
        <a href="payment.php?active=track" class="pay-tab-link <?= $activeTab === 'track' ? 'active' : '' ?>">
          🧾 Track Receipt by TXN ID
        </a>
      </div>
    <?php endif; ?>

    <!-- VIEW 1: OFFICIAL PRINTABLE RECEIPT -->
    <?php if ($activeTab === 'receipt' && $transactionReceipt): ?>
      <div class="pay-card" style="border: 2px solid #10b981;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 22px; border-bottom: 1px solid var(--border); padding-bottom: 16px;">
          <div>
            <span class="badge badge-success" style="font-size: 13px;">✓ PAYMENT CONFIRMED & VERIFIED</span>
            <h2 style="font-size: 24px; margin-top: 6px;">Institutional Digital Fee Receipt</h2>
          </div>
          <div class="no-print" style="display: flex; gap: 10px;">
            <button class="btn btn-secondary" onclick="window.print()" style="padding: 10px 18px;">
              🖨️ Print / Save as PDF
            </button>
            <a href="payment.php?active=quick_pay" class="btn" style="padding: 10px 18px;">
              + Make Another Payment
            </a>
            <a href="payment.php?active=search&search_query=<?= urlencode($transactionReceipt['roll_no']) ?>" class="btn btn-secondary" style="padding: 10px 18px;">
              View Student History
            </a>
          </div>
        </div>

        <div class="receipt-wrapper" style="margin: 0; box-shadow: none; border: 1px solid var(--border);">
          <div class="receipt-header">
            <div class="receipt-title">
              <h2 style="font-size: 22px; color: var(--primary);">SCHOOL OF MANAGEMENT SCIENCES (SMS)</h2>
              <p>Autonomous Institution Accredited 'A++' by NAAC · Approved by AICTE, New Delhi</p>
              <p>Campus Accounts & Treasury Bureau · Official Digital Transaction Counter</p>
            </div>
            <div class="receipt-badge">
              <div class="receipt-stamp">PAID & VERIFIED</div>
              <div style="margin-top: 6px; font-size: 11px; color: var(--text-muted);">
                Issued: <?= date('d M Y, h:i A', strtotime($transactionReceipt['payment_date'] ?? 'now')) ?>
              </div>
            </div>
          </div>

          <table class="receipt-table">
            <tr>
              <td>Receipt / Voucher No:</td>
              <td><strong>#REC-<?= str_pad((string)$transactionReceipt['id'], 6, '0', STR_PAD_LEFT) ?></strong></td>
              <td>Transaction Reference:</td>
              <td><strong style="color: var(--primary);"><?= htmlspecialchars($transactionReceipt['transaction_id']) ?></strong></td>
            </tr>
            <tr>
              <td>Student Name:</td>
              <td><strong><?= htmlspecialchars($transactionReceipt['student_name']) ?></strong></td>
              <td>Roll / Enrollment No:</td>
              <td><strong><?= htmlspecialchars($transactionReceipt['roll_no']) ?></strong></td>
            </tr>
            <tr>
              <td>Course / Academic Branch:</td>
              <td><?= htmlspecialchars($transactionReceipt['branch_name'] ?? 'General Studies') ?></td>
              <td>Payment Channel:</td>
              <td><strong><?= htmlspecialchars($transactionReceipt['payment_mode']) ?></strong></td>
            </tr>
            <tr>
              <td>Student Email:</td>
              <td><?= htmlspecialchars($transactionReceipt['email'] ?: 'N/A') ?></td>
              <td>Student Contact Phone:</td>
              <td><?= htmlspecialchars($transactionReceipt['phone'] ?: 'N/A') ?></td>
            </tr>
            <tr>
              <td>Fee Category / Purpose:</td>
              <td colspan="3"><strong style="color: var(--text-main);"><?= htmlspecialchars($transactionReceipt['remarks'] ?: 'Tuition Fee') ?></strong></td>
            </tr>
          </table>

          <table class="receipt-table" style="margin-top: 14px;">
            <thead>
              <tr style="background: #f1f5f9;">
                <th style="padding: 10px; text-align: left; font-size: 12px;">Fee Component Description</th>
                <th style="padding: 10px; text-align: right; font-size: 12px; width: 150px;">Amount (INR)</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><?= htmlspecialchars($transactionReceipt['remarks'] ?: 'Semester Tuition Fee') ?></td>
                <td style="text-align: right; font-weight: 600;">₹<?= number_format((float)$transactionReceipt['amount'], 2) ?></td>
              </tr>
              <tr>
                <td>Digital Gateway Convenience Surcharge</td>
                <td style="text-align: right; color: var(--accent-teal); font-weight: 600;">₹0.00 (Waived)</td>
              </tr>
              <tr>
                <td>GST / Central Education Cess</td>
                <td style="text-align: right; color: var(--text-muted);">₹0.00 (Exempted)</td>
              </tr>
              <tr style="background: #f8fafc; font-size: 15px;">
                <td><strong>Total Paid Amount:</strong></td>
                <td style="text-align: right;"><strong style="color: var(--primary); font-size: 19px;">₹<?= number_format((float)$transactionReceipt['amount'], 2) ?></strong></td>
              </tr>
            </tbody>
          </table>

          <div class="receipt-footer">
            <div class="receipt-qr">
              <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode('SMS-COLLEGE-VERIFIED-TXN:' . $transactionReceipt['transaction_id'] . '|ROLL:' . $transactionReceipt['roll_no'] . '|INR:' . $transactionReceipt['amount']) ?>" alt="Authentication QR">
              <small>Scan to verify authenticity on college server</small>
            </div>
            <div class="receipt-sign">
              <div class="receipt-seal">SMS ACCOUNTS SEAL</div>
              <div class="receipt-line"></div>
              <p>Authorized Finance Officer<br><small>School of Management Sciences</small></p>
            </div>
          </div>
        </div>

        <!-- EMAIL NOTIFICATION BANNER & PREVIEW (SCREEN ONLY) -->
        <?php if (!empty($transactionReceipt['email'])): ?>
          <div class="no-print" style="margin-top: 20px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 18px 22px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
              <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 26px;">📧</span>
                <div>
                  <h4 style="margin: 0; color: #166534; font-size: 15px;">Official E-Receipt Email Dispatched</h4>
                  <p style="margin: 3px 0 0; font-size: 13px; color: #15803d;">
                    A formatted payment confirmation message has been sent to: <strong><?= htmlspecialchars($transactionReceipt['email']) ?></strong>
                  </p>
                </div>
              </div>
              <button type="button" class="btn btn-secondary" onclick="toggleEmailPreview()" style="padding: 7px 14px; font-size: 12.5px; border-color: #86efac; color: #166534; background: #ffffff;">
                📩 Preview Dispatched Email
              </button>
            </div>

            <!-- Email Message Preview Drawer -->
            <div id="email_preview_container" style="display: none; margin-top: 16px; border: 1px solid #cbd5e1; border-radius: 10px; overflow: hidden; background: #ffffff; box-shadow: var(--shadow-sm);">
              <div style="background: #f8fafc; padding: 10px 16px; border-bottom: 1px solid #e2e8f0; font-size: 12.5px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                <span><strong>To:</strong> <?= htmlspecialchars($transactionReceipt['email']) ?></span>
                <span><strong>Subject:</strong> Official Fee Receipt [<?= htmlspecialchars($transactionReceipt['transaction_id']) ?>] - SMS</span>
                <span style="color: #10b981; font-weight: 700;">✓ Sent</span>
              </div>
              <div style="padding: 20px; max-height: 480px; overflow-y: auto;">
                <?php 
                if (!empty($_SESSION['last_sent_receipt_email']['body'])) {
                    echo $_SESSION['last_sent_receipt_email']['body'];
                } else {
                    echo "<p style='color: #64748b;'>An official e-receipt has been sent to " . htmlspecialchars($transactionReceipt['email']) . ".</p>";
                }
                ?>
              </div>
            </div>
          </div>

          <script>
          function toggleEmailPreview() {
            var box = document.getElementById('email_preview_container');
            if (box) {
              box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
            }
          }
          </script>
        <?php endif; ?>
      </div>

    <!-- VIEW 2: SEARCH STUDENT INVOICES -->
    <?php elseif ($activeTab === 'search'): ?>
      <div class="pay-card">
        <h2 style="font-size: 24px; margin-bottom: 8px;">Look Up Student Fee Invoices & Records</h2>
        <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 22px;">
          Enter Roll Number or Email to fetch pending dues or inspect past stamped vouchers.
        </p>

        <form method="get" action="payment.php" style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;">
          <input type="hidden" name="active" value="search">
          <input class="form-control" name="search_query" value="<?= htmlspecialchars($_GET['search_query'] ?? '') ?>" placeholder="Enter Roll No (e.g. BBA202601, BCA202601, BCA202602)" style="flex: 1; min-width: 250px;" required>
          <button class="btn" type="submit">Search Records →</button>
        </form>

        <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 24px;">
          💡 <strong>Demo Roll Numbers to Test:</strong> 
          <a href="payment.php?active=search&search_query=BBA202601" style="color: var(--primary); font-weight: 700; margin-right: 12px;">BBA202601 (Rohan Verma - Due ₹31,000)</a>
          <a href="payment.php?active=search&search_query=BCA202602" style="color: var(--accent-teal); font-weight: 700; margin-right: 12px;">BCA202602 (Priya Patel - Paid)</a>
          <a href="payment.php?active=search&search_query=BCA202601" style="color: var(--primary); font-weight: 700;">BCA202601 (Aarav Sharma)</a>
        </div>

        <?php if ($searchedStudent): ?>
          <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 22px; margin-bottom: 26px;">
            <span class="badge badge-primary">STUDENT PROFILE</span>
            <h3 style="font-size: 22px; margin-top: 6px;"><?= htmlspecialchars($searchedStudent['student_name']) ?></h3>
            <p style="font-size: 14px; color: var(--text-muted); margin-top: 4px;">
              Roll No: <strong><?= htmlspecialchars($searchedStudent['roll_no']) ?></strong> · 
              Department: <strong><?= htmlspecialchars($searchedStudent['branch_name'] ?? 'General') ?></strong> · 
              Email: <strong><?= htmlspecialchars($searchedStudent['email'] ?? 'N/A') ?></strong> · 
              Phone: <strong><?= htmlspecialchars($searchedStudent['phone'] ?? 'N/A') ?></strong>
            </p>
          </div>

          <!-- PENDING INVOICES -->
          <h3 style="font-size: 19px; margin-bottom: 14px;">Pending Fee Invoices</h3>
          <?php if (empty($pendingFees)): ?>
            <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 12px; padding: 18px; margin-bottom: 26px; color: #065f46;">
              🎉 <strong>All dues are cleared!</strong> No pending invoices found for this student.
            </div>
          <?php else: ?>
            <div style="display: grid; gap: 14px; margin-bottom: 30px;">
              <?php foreach ($pendingFees as $pItem): ?>
                <div style="border: 1px solid #fecaca; background: #fff5f5; border-radius: 12px; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                  <div>
                    <span class="badge badge-danger">⏳ PAYMENT DUE</span>
                    <h4 style="font-size: 18px; margin-top: 6px;"><?= htmlspecialchars($pItem['remarks'] ?: 'Semester Tuition Fee') ?></h4>
                    <small style="color: var(--text-muted);">Invoice Date: <?= date('d M Y', strtotime($pItem['created_at'])) ?></small>
                  </div>
                  <div style="text-align: right; display: flex; align-items: center; gap: 18px;">
                    <div>
                      <span style="font-size: 12px; color: var(--text-muted); display: block;">Amount Due</span>
                      <strong style="font-size: 24px; color: #dc2626;">₹<?= number_format((float)$pItem['amount'], 2) ?></strong>
                    </div>
                    <a href="payment.php?active=quick_pay&fee_id=<?= $pItem['id'] ?>" class="btn" style="padding: 12px 22px; font-weight: 700;">
                      Pay Now 💳
                    </a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <!-- PAID FEES -->
          <h3 style="font-size: 19px; margin-bottom: 14px;">Completed Payment Receipts</h3>
          <?php if (empty($paidFees)): ?>
            <p style="color: var(--text-muted); font-size: 13.5px;">No completed payments recorded yet.</p>
          <?php else: ?>
            <div style="display: grid; gap: 12px;">
              <?php foreach ($paidFees as $pdItem): ?>
                <div style="border: 1px solid #a7f3d0; background: #ecfdf5; border-radius: 12px; padding: 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                  <div>
                    <span class="badge badge-success">✓ PAID</span>
                    <h4 style="font-size: 17px; margin-top: 4px;"><?= htmlspecialchars($pdItem['remarks'] ?: 'Fee Payment') ?></h4>
                    <small style="color: #065f46;">Ref: <strong><?= htmlspecialchars($pdItem['transaction_id'] ?: 'N/A') ?></strong> · <?= date('d M Y, h:i A', strtotime($pdItem['payment_date'] ?? $pdItem['created_at'])) ?></small>
                  </div>
                  <div style="text-align: right; display: flex; align-items: center; gap: 16px;">
                    <strong style="font-size: 20px; color: #047857;">₹<?= number_format((float)$pdItem['amount'], 2) ?></strong>
                    <a href="payment.php?search_query=<?= urlencode($pdItem['transaction_id']) ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 13px;">
                      View Receipt 🖨️
                    </a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

    <!-- VIEW 3: TRACK RECEIPT BY TRANSACTION ID -->
    <?php elseif ($activeTab === 'track'): ?>
      <div class="pay-card">
        <h2 style="font-size: 24px; margin-bottom: 8px;">Track / Download Receipt by Transaction ID</h2>
        <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 22px;">
          Have a transaction reference like <code>TXN-2026-984321</code> or an official bank receipt? Enter it below to pull the stamped institutional voucher.
        </p>

        <form method="get" action="payment.php" style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;">
          <input class="form-control" name="search_query" placeholder="Enter Transaction ID (e.g. TXN-2026-984321, TXN-2026-773412)" style="flex: 1; min-width: 280px;" required>
          <button class="btn" type="submit">Track Receipt →</button>
        </form>

        <div style="font-size: 13px; color: var(--text-muted);">
          💡 <strong>Demo Transaction References:</strong> 
          <a href="payment.php?search_query=TXN-2026-984321" style="color: var(--primary); font-weight: 700; margin-right: 12px;">TXN-2026-984321 (Priya Patel)</a>
          <a href="payment.php?search_query=TXN-2026-773412" style="color: var(--primary); font-weight: 700;">TXN-2026-773412 (Ananya Singh)</a>
        </div>
      </div>

    <!-- VIEW 4: QUICK FEE PAYMENT WORKSPACE (Default) -->
    <?php else: ?>
      <div class="pay-grid">
        <!-- LEFT: PAYMENT FORM -->
        <div class="pay-card">
          <?php if ($prefillFeeId > 0): ?>
            <div class="pending-fee-banner">
              <div>
                <span class="badge badge-warning" style="margin-bottom: 4px;">PAYING PENDING INVOICE #<?= $prefillFeeId ?></span>
                <div style="font-weight: 700; font-size: 15px; color: #1e3a8a;">
                  <?= htmlspecialchars($prefillStudentName) ?> (<?= htmlspecialchars($prefillRoll) ?>) — <?= htmlspecialchars($prefillCategory) ?>
                </div>
              </div>
              <a href="payment.php?active=quick_pay" style="font-size: 12px; color: #dc2626; font-weight: 700; text-decoration: underline;">
                ✕ Clear & Pay Custom
              </a>
            </div>
          <?php endif; ?>

          <h2 style="font-size: 24px; margin-bottom: 6px;">Direct Fee Payment & Checkout</h2>
          <p style="color: var(--text-muted); font-size: 13.5px; margin-bottom: 24px;">
            Enter student roll number to auto-fill details, or type your information directly. Instant authorization and digital receipt generated upon confirmation.
          </p>

          <form method="post" action="payment.php" id="payment_form">
            <input type="hidden" name="action" value="process_payment">
            <input type="hidden" name="existing_fee_id" id="inp_existing_fee_id" value="<?= htmlspecialchars((string)$prefillFeeId) ?>">
            <input type="hidden" name="payment_mode" id="selected_payment_mode" value="UPI / QR Code">

            <!-- Academic Info -->
            <h3 style="font-size: 16px; margin-bottom: 14px; color: var(--primary-dark); display: flex; align-items: center; gap: 8px;">
              <span>👤</span> Student Academic Details
            </h3>

            <div class="form-row">
              <div class="form-group">
                <label>Roll Number / Enrollment No *</label>
                <input class="form-control" name="roll_no" id="inp_roll_no" required placeholder="e.g. BBA202601 or BCA202601" value="<?= htmlspecialchars($prefillRoll) ?>" autocomplete="off">
                <span id="roll_indicator" class="autofill-indicator"></span>
              </div>

              <div class="form-group">
                <label>Student Full Name *</label>
                <input class="form-control" name="student_name" id="inp_student_name" required placeholder="e.g. Rohan Verma" value="<?= htmlspecialchars($prefillStudentName) ?>">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label>Academic Department / Branch</label>
                <select class="form-control" name="branch_id" id="inp_branch">
                  <option value="">Select Branch</option>
                  <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= (int)$b['id'] === $prefillBranchId ? 'selected' : '' ?>>
                      <?= htmlspecialchars($b['branch_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group">
                <label>Fee Category / Purpose *</label>
                <select class="form-control" name="fee_category" id="inp_category" required style="font-weight: 600; cursor: pointer;">
                  <option value="Semester Examination Fee" <?= (strpos($prefillCategory, 'Exam') !== false || $prefillCategory === 'Semester Examination Fee') ? 'selected' : '' ?>>
                    📝 Semester Examination Fee
                  </option>
                  <option value="Semester Tuition Fee" <?= ($prefillCategory === 'Semester Tuition Fee' || empty($prefillCategory)) ? 'selected' : '' ?>>
                    🎓 Semester Tuition Fee
                  </option>
                  <option value="Backlog / Re-evaluation Examination Fee" <?= $prefillCategory === 'Backlog / Re-evaluation Examination Fee' ? 'selected' : '' ?>>
                    📑 Backlog / Re-evaluation Examination Fee
                  </option>
                  <option value="Admission & Registration Fee" <?= $prefillCategory === 'Admission & Registration Fee' ? 'selected' : '' ?>>
                    📋 Admission & Registration Fee
                  </option>
                  <option value="Semester 5 Tuition Fee" <?= $prefillCategory === 'Semester 5 Tuition Fee' ? 'selected' : '' ?>>
                    🎓 Semester 5 Tuition Fee
                  </option>
                  <option value="Semester 3 Management Fee" <?= $prefillCategory === 'Semester 3 Management Fee' ? 'selected' : '' ?>>
                    🎓 Semester 3 Management Fee
                  </option>
                  <option value="Hostel & Mess Charges" <?= $prefillCategory === 'Hostel & Mess Charges' ? 'selected' : '' ?>>
                    🏢 Hostel & Mess Charges
                  </option>
                  <option value="Library & Lab Caution Deposit" <?= $prefillCategory === 'Library & Lab Caution Deposit' ? 'selected' : '' ?>>
                    📚 Library & Lab Caution Deposit
                  </option>
                  <option value="Annual Sports & Cultural Fee" <?= $prefillCategory === 'Annual Sports & Cultural Fee' ? 'selected' : '' ?>>
                    🏆 Annual Sports & Cultural Fee
                  </option>
                  <?php if (!empty($prefillCategory) && !in_array($prefillCategory, ['Semester Examination Fee', 'Semester Tuition Fee', 'Backlog / Re-evaluation Examination Fee', 'Admission & Registration Fee', 'Semester 5 Tuition Fee', 'Semester 3 Management Fee', 'Hostel & Mess Charges', 'Library & Lab Caution Deposit', 'Annual Sports & Cultural Fee'])): ?>
                    <option value="<?= htmlspecialchars($prefillCategory) ?>" selected><?= htmlspecialchars($prefillCategory) ?></option>
                  <?php endif; ?>
                </select>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label>Student Email (For Instant E-Receipt Message) *</label>
                <input class="form-control" type="email" name="email" id="inp_email" required placeholder="student@example.com" value="<?= htmlspecialchars($prefillEmail) ?>">
              </div>

              <div class="form-group">
                <label>Contact Phone</label>
                <input class="form-control" name="phone" id="inp_phone" placeholder="9876543210" value="<?= htmlspecialchars($prefillPhone) ?>">
              </div>
            </div>

            <!-- Amount Specification -->
            <div style="margin: 10px 0 24px; padding-top: 14px; border-top: 1px dashed var(--border);">
              <div class="form-group" style="margin-bottom: 8px;">
                <label style="display: flex; justify-content: space-between;">
                  <span>Payable Amount (INR ₹) *</span>
                  <span style="font-weight: 400; color: var(--accent-teal); font-size: 12px;">✓ 0% Gateway Convenience Fee</span>
                </label>
                <input class="form-control" type="number" step="1" min="10" name="amount" id="inp_amount" required placeholder="Enter fee amount" value="<?= htmlspecialchars($prefillAmount ?: (($prefillCategory === 'Semester Examination Fee' || strpos($prefillCategory, 'Exam') !== false) ? '2500' : '24500')) ?>" style="font-size: 22px; font-weight: 700; color: var(--primary);">
              </div>

              <!-- Presets -->
              <label style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted);">Quick Presets:</label>
              <div class="amount-presets">
                <div class="amount-chip <?= ($prefillAmount == '2500' || strpos($prefillCategory, 'Exam') !== false) ? 'selected' : '' ?>" data-amt="2500" data-category="Semester Examination Fee">₹2,500 (Exam Fee)</div>
                <div class="amount-chip" data-amt="5000">₹5,000</div>
                <div class="amount-chip" data-amt="15000">₹15,000</div>
                <div class="amount-chip <?= ((!$prefillAmount && strpos($prefillCategory, 'Exam') === false) || $prefillAmount == '24500') ? 'selected' : '' ?>" data-amt="24500" data-category="Semester Tuition Fee">₹24,500 (Tuition)</div>
                <div class="amount-chip <?= ($prefillAmount == '31000') ? 'selected' : '' ?>" data-amt="31000" data-category="Semester 3 Management Fee">₹31,000</div>
                <div class="amount-chip" data-amt="45000">₹45,000</div>
              </div>
            </div>

            <!-- Payment Methods -->
            <h3 style="font-size: 16px; margin-bottom: 14px; color: var(--primary-dark); display: flex; align-items: center; gap: 8px;">
              <span>💳</span> Select Payment Channel
            </h3>

            <div class="payment-tabs" style="margin-bottom: 20px;">
              <div class="payment-tab active" data-target="tab_upi" data-mode="UPI / QR Code">
                📱 UPI / QR Code
              </div>
              <div class="payment-tab" data-target="tab_card" data-mode="Debit / Credit Card">
                💳 Card Payment
              </div>
              <div class="payment-tab" data-target="tab_net" data-mode="Net Banking">
                🏦 Net Banking
              </div>
              <div class="payment-tab" data-target="tab_emi" data-mode="Edu EMI / Pay Later">
                📅 0% Edu EMI
              </div>
            </div>

            <!-- TAB 1: UPI & DYNAMIC QR -->
                <div class="upi-qr-row">
                  <div class="upi-qr-box">
                    <img id="dynamic_qr_img" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=upi://pay?pa=sms.fees@icici&pn=SMS_Autonomous_College&am=<?= htmlspecialchars($prefillAmount ?: '24500') ?>" alt="Instant UPI QR" style="width: 140px; height: 140px; display: block; border-radius: 8px; margin: 0 auto;">
                    <small style="color: var(--text-muted); font-size: 10px; display: block; margin-top: 8px; font-weight: 600;">Auto-refreshes with Amount</small>
                  </div>
                  <div class="upi-qr-form">
                    <h4 style="font-size: 16px; margin-bottom: 6px;">Scan & Pay via any UPI App</h4>
                    <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 14px;">
                      Scan using Google Pay, PhonePe, Paytm, BHIM, or any banking app to pay directly into verified college treasury.
                    </p>

                    <div class="form-group" style="margin-bottom: 10px;">
                      <label>Or enter UPI VPA Virtual ID</label>
                      <input class="form-control" name="upi_id" placeholder="e.g. mobile@upi or student@okhdfcbank">
                    </div>

                    <div style="font-size: 11.5px; color: #047857;">
                      Verified Merchant: <strong>School of Management Sciences (sms.fees@icici)</strong>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- TAB 2: DEBIT / CREDIT CARD -->
            <div class="payment-panel" id="tab_card">
              <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 22px;">
                <div class="card-mockup">
                  <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div class="card-chip"></div>
                    <span id="display_card_type" style="font-weight: 800; font-size: 15px; letter-spacing: 1px; color: #38bdf8;">VISA / RUPAY</span>
                  </div>
                  <div class="card-number-display" id="display_card_num">•••• •••• •••• ••••</div>
                  <div class="card-holder-display">
                    <div>
                      <span>Cardholder</span>
                      <b id="display_card_holder"><?= htmlspecialchars($prefillStudentName ?: 'STUDENT NAME') ?></b>
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
                    <label>Name on Card</label>
                    <input class="form-control" id="card_holder" name="card_holder" placeholder="e.g. Rajesh Sharma" value="<?= htmlspecialchars($prefillStudentName) ?>">
                  </div>
                </div>

                <div class="form-row">
                  <div class="form-group">
                    <label>Expiry Month / Year</label>
                    <input class="form-control" id="card_expiry" name="card_expiry" placeholder="MM/YY" maxlength="5">
                  </div>
                  <div class="form-group">
                    <label>CVV Security Code</label>
                    <input class="form-control" type="password" name="card_cvv" maxlength="4" placeholder="•••">
                  </div>
                </div>
              </div>
            </div>

            <!-- TAB 3: NET BANKING -->
            <div class="payment-panel" id="tab_net">
              <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 22px;">
                <h4 style="font-size: 15px; margin-bottom: 12px;">Select Popular Bank:</h4>
                <div class="bank-grid">
                  <div class="bank-item active" data-bank="State Bank of India (SBI)">
                    <span>🏛️</span> SBI
                  </div>
                  <div class="bank-item" data-bank="HDFC Bank">
                    <span>🏦</span> HDFC
                  </div>
                  <div class="bank-item" data-bank="ICICI Bank">
                    <span>🏧</span> ICICI
                  </div>
                  <div class="bank-item" data-bank="Axis Bank">
                    <span>🏢</span> Axis
                  </div>
                  <div class="bank-item" data-bank="Punjab National Bank">
                    <span>🏛️</span> PNB
                  </div>
                  <div class="bank-item" data-bank="Bank of Baroda">
                    <span>🏦</span> BOB
                  </div>
                </div>

                <div class="form-group" style="margin-top: 14px;">
                  <label>Or Select From All Indian Banks</label>
                  <select class="form-control" name="bank_name" id="bank_select">
                    <option value="State Bank of India (SBI)">State Bank of India (SBI)</option>
                    <option value="HDFC Bank">HDFC Bank</option>
                    <option value="ICICI Bank">ICICI Bank</option>
                    <option value="Axis Bank">Axis Bank</option>
                    <option value="Punjab National Bank">Punjab National Bank</option>
                    <option value="Bank of Baroda">Bank of Baroda</option>
                    <option value="Kotak Mahindra Bank">Kotak Mahindra Bank</option>
                    <option value="Canara Bank">Canara Bank</option>
                    <option value="Union Bank of India">Union Bank of India</option>
                  </select>
                </div>
              </div>
            </div>

            <!-- TAB 4: 0% EDU EMI -->
            <div class="payment-panel" id="tab_emi">
              <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 22px;">
                <span class="badge badge-warning" style="margin-bottom: 8px;">COLLEGE EDUCATION LOAN & EMI PARTNERSHIP</span>
                <h4 style="font-size: 16px; margin-bottom: 8px;">Zero Interest Monthly Installments</h4>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
                  Pay tuition fees over 3 to 6 easy installments with verified banking partners.
                </p>
                <div style="display: grid; gap: 10px;">
                  <label style="display: flex; align-items: center; gap: 10px; padding: 12px; background: #fff; border: 1px solid var(--border); border-radius: 8px; cursor: pointer;">
                    <input type="radio" name="emi_plan" value="3_months" checked>
                    <div>
                      <strong>3 Months No-Cost EMI</strong> · <span id="display_emi_3">₹8,166 / month</span>
                    </div>
                  </label>
                  <label style="display: flex; align-items: center; gap: 10px; padding: 12px; background: #fff; border: 1px solid var(--border); border-radius: 8px; cursor: pointer;">
                    <input type="radio" name="emi_plan" value="6_months">
                    <div>
                      <strong>6 Months Education Plan</strong> · <span id="display_emi_6">₹4,083 / month</span>
                    </div>
                  </label>
                </div>
              </div>
            </div>

            <!-- Submit Button -->
            <div style="margin-top: 28px;">
              <button class="btn btn-success" type="submit" style="width: 100%; padding: 16px; font-size: 16px; font-weight: 800; border-radius: var(--radius-md); display: flex; justify-content: center; align-items: center; gap: 10px; box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);">
                <span>🔒</span>
                <span id="btn_submit_text">Authorize & Pay Now</span>
              </button>
              <p style="text-align: center; font-size: 12px; color: var(--text-muted); margin-top: 10px;">
                Instant transaction authorization & downloadable institutional receipt generated upon submission.
              </p>
            </div>
          </form>
        </div>

        <!-- RIGHT: LIVE SUMMARY & SECURITY BADGES -->
        <aside>
          <div class="pay-summary-box">
            <span class="badge" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8; margin-bottom: 12px;">ORDER BREAKDOWN</span>
            <h3 style="font-size: 20px; color: #ffffff; margin-bottom: 6px;">Payment Summary</h3>
            <p style="font-size: 12px; color: #94a3b8; margin-bottom: 20px;">Real-time invoice calculation</p>

            <div class="pay-summary-row">
              <span style="color: #cbd5e1;">Selected Purpose</span>
              <strong id="sum_purpose" style="color: #ffffff; font-size: 13px;"><?= htmlspecialchars($prefillCategory) ?></strong>
            </div>

            <div class="pay-summary-row">
              <span style="color: #cbd5e1;">Base Fee Amount</span>
              <span id="sum_base" style="font-weight: 700; color: #ffffff;">₹<?= number_format((float)($prefillAmount ?: 24500), 2) ?></span>
            </div>

            <div class="pay-summary-row">
              <span style="color: #cbd5e1;">Gateway Convenience Fee</span>
              <span style="color: #10b981; font-weight: 700;">FREE (₹0.00)</span>
            </div>

            <div class="pay-summary-row">
              <span style="color: #cbd5e1;">Institutional Stamp & Tax</span>
              <span style="color: #10b981; font-weight: 700;">₹0.00</span>
            </div>

            <div class="pay-summary-row">
              <span style="color: #ffffff; font-weight: 700; font-size: 15px;">Total Payable</span>
              <div class="pay-summary-total" id="sum_total">₹<?= number_format((float)($prefillAmount ?: 24500), 2) ?></div>
            </div>

            <div class="trust-badges">
              <div class="trust-badge-item">
                <span>🔒</span>
                <div>
                  <strong>256-Bit SSL</strong><br>Bank-Grade Security
                </div>
              </div>
              <div class="trust-badge-item">
                <span>🛡️</span>
                <div>
                  <strong>PCI-DSS Level 1</strong><br>Encrypted Vault
                </div>
              </div>
              <div class="trust-badge-item">
                <span>🏛️</span>
                <div>
                  <strong>RBI Guidelines</strong><br>Compliant Gateway
                </div>
              </div>
              <div class="trust-badge-item">
                <span>⚡</span>
                <div>
                  <strong>Instant Stamped</strong><br>E-Receipt Download
                </div>
              </div>
            </div>
          </div>

          <div class="pay-card" style="margin-top: 24px; padding: 22px;">
            <h4 style="font-size: 16px; margin-bottom: 8px;">Accounts Helpdesk</h4>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 12px;">
              For invoice adjustments or payment assistance:
            </p>
            <div style="font-size: 12.5px; line-height: 1.7;">
              📞 <strong>Phone:</strong> +91 542 2271771<br>
              ✉️ <strong>Email:</strong> accounts@smsvaranasi.com<br>
              ⏰ <strong>Hours:</strong> Mon - Sat, 9:00 AM - 5:00 PM
            </div>
          </div>
        </aside>
      </div>
    <?php endif; ?>

  </main>

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
          <li><a href="payment.php?active=quick_pay">Pay Tuition Fee</a></li>
          <li><a href="payment.php?active=search&search_query=BBA202601">Check Due Invoices</a></li>
          <li><a href="payment.php?active=track">Download Receipt</a></li>
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
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const amtInput = document.getElementById('inp_amount');
      const sumBase = document.getElementById('sum_base');
      const sumTotal = document.getElementById('sum_total');
      const btnText = document.getElementById('btn_submit_text');
      const qrImg = document.getElementById('dynamic_qr_img');
      const emi3 = document.getElementById('display_emi_3');
      const emi6 = document.getElementById('display_emi_6');
      const categoryInput = document.getElementById('inp_category');
      const sumPurpose = document.getElementById('sum_purpose');
      const chips = document.querySelectorAll('.amount-chip');

      // Autofill fields
      const rollInput = document.getElementById('inp_roll_no');
      const nameInput = document.getElementById('inp_student_name');
      const emailInput = document.getElementById('inp_email');
      const phoneInput = document.getElementById('inp_phone');
      const branchSelect = document.getElementById('inp_branch');
      const existingFeeInput = document.getElementById('inp_existing_fee_id');
      const rollIndicator = document.getElementById('roll_indicator');

      function updateAmounts(val) {
        const num = parseFloat(val) || 0;
        const formatted = '₹' + num.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (sumBase) sumBase.textContent = formatted;
        if (sumTotal) sumTotal.textContent = formatted;
        if (btnText) btnText.textContent = 'Authorize & Pay ' + formatted + ' Now';

        if (qrImg) {
          qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=' + encodeURIComponent('upi://pay?pa=sms.fees@icici&pn=SMS_Autonomous_College&am=' + num);
        }

        if (emi3) {
          const m3 = Math.round(num / 3);
          emi3.textContent = '₹' + m3.toLocaleString('en-IN') + ' / month';
        }
        if (emi6) {
          const m6 = Math.round(num / 6);
          emi6.textContent = '₹' + m6.toLocaleString('en-IN') + ' / month';
        }
      }

      if (amtInput) {
        amtInput.addEventListener('input', (e) => {
          updateAmounts(e.target.value);
          chips.forEach(c => c.classList.remove('selected'));
        });
      }

      chips.forEach(chip => {
        chip.addEventListener('click', () => {
          chips.forEach(c => c.classList.remove('selected'));
          chip.classList.add('selected');
          const amt = chip.getAttribute('data-amt');
          const cat = chip.getAttribute('data-category');
          if (amtInput) {
            amtInput.value = amt;
            updateAmounts(amt);
          }
          if (cat && categoryInput) {
            categoryInput.value = cat;
            if (sumPurpose) sumPurpose.textContent = cat;
          }
        });
      });

      if (categoryInput && sumPurpose) {
        const syncCat = () => {
          const val = categoryInput.value || 'Semester Tuition Fee';
          sumPurpose.textContent = val;
          // Auto-adjust suggested amount if user selects Examination Fee
          if (val === 'Semester Examination Fee') {
            if (amtInput && (!amtInput.value || amtInput.value === '24500')) {
              amtInput.value = '2500';
              updateAmounts('2500');
              chips.forEach(c => {
                c.classList.toggle('selected', c.getAttribute('data-amt') === '2500');
              });
            }
          } else if (val === 'Semester Tuition Fee') {
            if (amtInput && amtInput.value === '2500') {
              amtInput.value = '24500';
              updateAmounts('24500');
              chips.forEach(c => {
                c.classList.toggle('selected', c.getAttribute('data-amt') === '24500');
              });
            }
          }
        };
        categoryInput.addEventListener('change', syncCat);
        categoryInput.addEventListener('input', syncCat);
      }

      // Dynamic Student Lookup when typing Roll No
      let lookupTimeout = null;
      if (rollInput) {
        rollInput.addEventListener('input', (e) => {
          clearTimeout(lookupTimeout);
          const rollVal = e.target.value.trim().toUpperCase();
          if (rollVal.length < 4) {
            if (rollIndicator) {
              rollIndicator.className = 'autofill-indicator';
              rollIndicator.style.display = 'none';
            }
            return;
          }

          lookupTimeout = setTimeout(() => {
            fetch('payment.php?api=lookup_student&roll=' + encodeURIComponent(rollVal))
              .then(res => res.json())
              .then(data => {
                if (data.found && data.student) {
                  const s = data.student;
                  if (nameInput && !nameInput.value) nameInput.value = s.student_name;
                  if (emailInput && !emailInput.value) emailInput.value = s.email || '';
                  if (phoneInput && !phoneInput.value) phoneInput.value = s.phone || '';
                  if (branchSelect && s.branch_id) branchSelect.value = s.branch_id;

                  // Check if pending fee is found
                  if (data.pending_fee) {
                    const pf = data.pending_fee;
                    if (existingFeeInput) existingFeeInput.value = pf.id;
                    if (amtInput) {
                      amtInput.value = pf.amount;
                      updateAmounts(pf.amount);
                    }
                    if (categoryInput) {
                      let foundOpt = false;
                      for (let i = 0; i < categoryInput.options.length; i++) {
                        if (categoryInput.options[i].value === pf.remarks) {
                          categoryInput.selectedIndex = i;
                          foundOpt = true;
                          break;
                        }
                      }
                      if (!foundOpt && pf.remarks) {
                        const opt = new Option(pf.remarks, pf.remarks, true, true);
                        categoryInput.add(opt);
                      }
                      if (sumPurpose) sumPurpose.textContent = pf.remarks;
                    }
                    if (rollIndicator) {
                      rollIndicator.className = 'autofill-indicator found';
                      rollIndicator.innerHTML = `✓ Student: <strong>${s.student_name}</strong> · Pending due: <strong>₹${parseFloat(pf.amount).toLocaleString('en-IN')}</strong> (${pf.remarks})`;
                    }
                  } else {
                    if (rollIndicator) {
                      rollIndicator.className = 'autofill-indicator found';
                      rollIndicator.innerHTML = `✓ Student Record Found: <strong>${s.student_name}</strong> (${s.branch_name || 'General'})`;
                    }
                  }
                } else {
                  if (rollIndicator) {
                    rollIndicator.className = 'autofill-indicator';
                    rollIndicator.style.display = 'none';
                  }
                }
              })
              .catch(() => {});
          }, 350);
        });
      }

      // Bank Selector Buttons
      const bankItems = document.querySelectorAll('.bank-item');
      const bankSelect = document.getElementById('bank_select');
      bankItems.forEach(item => {
        item.addEventListener('click', () => {
          bankItems.forEach(b => b.classList.remove('active'));
          item.classList.add('active');
          const bName = item.getAttribute('data-bank');
          if (bankSelect) {
            bankSelect.value = bName;
          }
        });
      });

      // Name on card sync
      const cardHolderInput = document.getElementById('card_holder');
      const displayCardHolder = document.getElementById('display_card_holder');
      if (cardHolderInput && displayCardHolder) {
        cardHolderInput.addEventListener('input', (e) => {
          displayCardHolder.textContent = e.target.value.toUpperCase() || 'STUDENT NAME';
        });
      }

      // Initial calculation
      if (amtInput) {
        updateAmounts(amtInput.value);
      }
    });
  </script>
</body>
</html>
