<?php
require_once __DIR__ . '/../config.php';

if (!empty($_SESSION['admin'])) {
    header('Location: dashboard.php');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $stmt = db()->prepare('SELECT * FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['username'] = $admin['username'];
            header('Location: dashboard.php');
            exit;
        } else {
            $err = 'Invalid username or password.';
        }
    } catch (Throwable $e) {
        $err = 'Database connection error: ' . htmlspecialchars($e->getMessage());
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Sign In | School of Management Sciences (SMS)</title>
  <link rel="stylesheet" href="../assets/style.css">
  <style>
    .admin-login-card {
      width: min(440px, 100%);
      background: rgba(255, 255, 255, 0.96);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.2);
      border-radius: 24px;
      padding: 38px 32px;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4);
    }
    @media (max-width: 480px) {
      body {
        padding: 14px !important;
      }
      .admin-login-card {
        padding: 26px 18px;
        border-radius: 18px;
      }
      .admin-login-card h1 {
        font-size: 22px !important;
      }
    }
  </style>
</head>
<body style="background: linear-gradient(135deg, #090e17 0%, #131d2e 100%); min-height: 100vh; display: grid; place-items: center; padding: 20px;">

  <div class="admin-login-card">
    
    <div style="margin-bottom: 24px;">
      <a class="brand" href="../index.php" style="justify-content: flex-start; margin-bottom: 20px;">
        <b>SMS</b>
        <span>School of <span>Management Sciences</span>
          <small>Executive Control Panel</small>
        </span>
      </a>
      <h1 style="font-size: 26px; margin-bottom: 6px; color: #0f172a;">Welcome back</h1>
      <p style="color: #64748b; font-size: 14px;">Sign in with your administrative credentials to manage campus records.</p>
    </div>

    <?php if ($err): ?>
      <div class="alert alert-danger" style="margin-bottom: 20px; padding: 12px 16px;">
        <span>⚠️</span>
        <div><?= htmlspecialchars($err) ?></div>
      </div>
    <?php endif; ?>

    <form method="post" action="login.php">
      <div class="form-group">
        <label>Username</label>
        <input class="form-control" name="username" required placeholder="Enter username" autocomplete="username">
      </div>

      <div class="form-group" style="margin-bottom: 24px;">
        <label>Password</label>
        <input class="form-control" type="password" name="password" required placeholder="••••••••">
      </div>

      <button class="btn" style="width: 100%; padding: 14px; font-size: 15px;" type="submit">
        Secure Sign In →
      </button>
    </form>
    
    <div style="text-align: center; margin-top: 22px;">
      <a href="../index.php" style="color: #64748b; font-size: 13px; text-decoration: none; font-weight: 600;">
        ← Return to Main Website
      </a>
    </div>

  </div>

</body>
</html>