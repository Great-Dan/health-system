<?php
/**
 * verify_email_otp.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Second factor: the user enters the 6-digit OTP that was emailed to them.
 * This page sits between security_questions_verify.php and dashboard.php.
 *
 * Session vars consumed here:
 *   otp_pending_user_id  – int  user ID waiting for OTP confirmation
 *   otp_pending_email    – str  email address the code was sent to
 *   otp_pending_name     – str  user's full name (for greeting)
 *   otp_user_data        – arr  full user record to pass to finalize_login()
 *   otp_attempts         – int  failed attempts counter (max OTP_MAX_ATTEMPTS)
 *   _dev_otp             – str  DEV MODE only: plaintext OTP for local testing
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/email_otp.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ── Guard: must arrive here from the login flow ────────────────────────────
if (empty($_SESSION['otp_pending_user_id'])) {
    header('Location: login.php');
    exit;
}

$pendingUserId = (int)$_SESSION['otp_pending_user_id'];
$pendingEmail  = $_SESSION['otp_pending_email']  ?? '(unknown)';
$pendingName   = $_SESSION['otp_pending_name']   ?? 'User';
$userData      = $_SESSION['otp_user_data']      ?? [];
$attempts      = (int)($_SESSION['otp_attempts'] ?? 0);
$emailSent     = (bool)($_SESSION['otp_email_sent'] ?? false);
$error         = '';
$success       = '';
$instantOtp    = $_SESSION['_dev_otp'] ?? null;

// ── POST: verify submitted OTP ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Resend OTP ──────────────────────────────────────────────────────────
    if (isset($_POST['resend'])) {
        $otp    = generate_email_otp($pendingUserId, db());
        $mailed = send_otp_email($pendingEmail, $pendingName, $otp);
        $_SESSION['otp_attempts'] = 0;   // reset attempt counter on resend
        $_SESSION['otp_email_sent'] = $mailed;
        $emailSent = $mailed;
        $instantOtp = $_SESSION['_dev_otp'] ?? null;
        $error = '';
        $success = $mailed ? 'A new verification code has been dispatched to your email.' : 'A new verification code has been generated.';

    // ── Verify OTP ──────────────────────────────────────────────────────────
    } elseif (isset($_POST['otp'])) {
        $entered = trim($_POST['otp'] ?? '');

        // Rate-limiting: lock after OTP_MAX_ATTEMPTS failures.
        if ($attempts >= OTP_MAX_ATTEMPTS) {
            session_unset();
            header('Location: login.php?error=too_many_otp_attempts');
            exit;
        }

        if (!preg_match('/^\d{6}$/', $entered)) {
            $error = 'Please enter a valid 6-digit code.';
        } elseif (verify_email_otp($pendingUserId, $entered, db())) {
            // ── SUCCESS ──────────────────────────────────────────────────────
            // Clear OTP session vars.
            unset(
                $_SESSION['otp_pending_user_id'],
                $_SESSION['otp_pending_email'],
                $_SESSION['otp_pending_name'],
                $_SESSION['otp_user_data'],
                $_SESSION['otp_attempts'],
                $_SESSION['otp_email_sent']
            );

            // Complete the login using the stored user data.
            complete_pending_login($userData, 'email_otp');

        } else {
            // ── FAILURE ──────────────────────────────────────────────────────
            $_SESSION['otp_attempts'] = $attempts + 1;
            $remaining = OTP_MAX_ATTEMPTS - ($attempts + 1);
            $error = $remaining > 0
                ? "Incorrect or expired code. {$remaining} attempt(s) remaining."
                : 'Too many incorrect attempts. Please log in again.';

            if ($remaining <= 0) {
                session_unset();
                header('Location: login.php?error=too_many_otp_attempts');
                exit;
            }
        }
    }
}

// ── Mask the email for display  (e.g.  d****@gmail.com) ───────────────────
function mask_email(string $email): string
{
    [$local, $domain] = explode('@', $email, 2) + ['', ''];
    $visible = function_exists('mb_substr')
        ? mb_substr($local, 0, 1)
        : substr($local, 0, 1);

    return $visible . '****@' . $domain;
}

$maskedEmail = mask_email($pendingEmail);

// ── DEV MODE indicator ─────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email Verification – Secure Health System</title>
  <link rel="stylesheet" href="style.css">
  <style>
    /* ── OTP-specific styles ──────────────────────────────────────────────── */
    .otp-card {
      max-width: 420px;
      margin: 80px auto 40px;
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 4px 24px rgba(0,0,0,.12);
      padding: 40px 36px;
      text-align: center;
    }
    .otp-icon {
      font-size: 48px;
      margin-bottom: 12px;
    }
    .otp-card h2 {
      color: #1565c0;
      margin: 0 0 8px;
      font-size: 1.5rem;
    }
    .otp-card p.subtitle {
      color: #555;
      margin: 0 0 28px;
      font-size: 0.95rem;
      line-height: 1.5;
    }
    .otp-input-group {
      display: flex;
      gap: 8px;
      justify-content: center;
      margin-bottom: 20px;
    }
    .otp-input {
      width: 46px;
      height: 56px;
      font-size: 1.6rem;
      text-align: center;
      border: 2px solid #90caf9;
      border-radius: 8px;
      outline: none;
      color: #0d47a1;
      font-weight: bold;
      transition: border-color .2s;
    }
    .otp-input:focus {
      border-color: #1565c0;
      box-shadow: 0 0 0 3px rgba(21,101,192,.15);
    }
    .otp-hidden-input {
      /* hidden full input for non-JS fallback */
      display: none;
    }
    .btn-verify {
      width: 100%;
      padding: 13px;
      background: #1565c0;
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background .2s;
      margin-bottom: 12px;
    }
    .btn-verify:hover { background: #0d47a1; }
    .btn-resend {
      background: none;
      border: 1px solid #90caf9;
      color: #1565c0;
      border-radius: 8px;
      padding: 10px 20px;
      font-size: 0.9rem;
      cursor: pointer;
      transition: background .2s;
    }
    .btn-resend:hover { background: #e3f2fd; }
    .alert { border-radius: 8px; padding: 12px 16px; margin-bottom: 18px; font-size: 0.92rem; text-align: left; }
    .alert-error   { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }
    .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
    .alert-dev     { background: #fff8e1; color: #f57f17; border: 1px solid #ffe082; font-family: monospace; }
    .back-link { display: block; margin-top: 20px; color: #888; font-size: 0.85rem; text-decoration: none; }
    .back-link:hover { color: #1565c0; }
    .attempt-bar {
      height: 4px;
      background: #e0e0e0;
      border-radius: 4px;
      margin: 12px 0 20px;
      overflow: hidden;
    }
    .attempt-bar-fill {
      height: 100%;
      background: #ef5350;
      border-radius: 4px;
      transition: width .4s;
    }
  </style>
</head>
<body>

<div class="otp-card">
  <div class="otp-icon">📧</div>
  <h2>Email Verification</h2>
  <p class="subtitle">
    We sent a 6-digit code to<br>
    <strong><?= htmlspecialchars($maskedEmail, ENT_QUOTES, 'UTF-8') ?></strong>.<br>
    Enter it below to continue.
  </p>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <!-- Attempt progress bar -->
  <?php if ($attempts > 0): ?>
    <?php $pct = ($attempts / OTP_MAX_ATTEMPTS) * 100; ?>
    <div class="attempt-bar">
      <div class="attempt-bar-fill" style="width:<?= $pct ?>%"></div>
    </div>
  <?php endif; ?>

  <?php if (!empty($instantOtp)): ?>
    <div style="background: #e8f4fd; border: 1.5px solid #2196f3; border-radius: 10px; padding: 14px 16px; margin: 0 0 20px; text-align: center;">
      <div style="font-size: 0.8rem; font-weight: 700; color: #0d47a1; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 4px;">🔐 Instant Access Code</div>
      <div style="font-size: 2rem; font-weight: 800; letter-spacing: 8px; color: #1565c0; margin: 6px 0;" id="instantCodeDisplay"><?= htmlspecialchars((string)$instantOtp, ENT_QUOTES, 'UTF-8') ?></div>
      <button type="button" id="btnAutoFill" style="background: #1976d2; color: #fff; border: none; border-radius: 6px; padding: 7px 16px; font-size: 0.88rem; font-weight: 600; cursor: pointer; margin-top: 4px;">
        ⚡ Click to Auto-Fill &amp; Enter
      </button>
    </div>
  <?php endif; ?>

  <!-- OTP Entry Form -->
  <form method="POST" id="otpForm" autocomplete="off">
    <!-- Visual digit boxes (JS-powered) -->
    <div class="otp-input-group" id="digitBoxes">
      <?php for ($i = 0; $i < 6; $i++): ?>
        <input type="text"
               class="otp-input"
               maxlength="1"
               inputmode="numeric"
               pattern="[0-9]"
               data-idx="<?= $i ?>"
               aria-label="Digit <?= $i + 1 ?>">
      <?php endfor; ?>
    </div>

    <!-- Hidden field that carries the assembled code -->
    <input type="hidden" name="otp" id="otpValue">

    <button type="submit" name="submit_otp" class="btn-verify">✓ Verify Code</button>
  </form>

  <!-- Resend form -->
  <form method="POST" style="margin-top:0;">
    <button type="submit" name="resend" class="btn-resend">↺ Resend Code</button>
  </form>

  <a href="login.php" class="back-link">← Back to Login</a>
</div>

<script>
// ── OTP digit-box keyboard UX ──────────────────────────────────────────────
(function () {
  const boxes  = Array.from(document.querySelectorAll('.otp-input'));
  const hidden = document.getElementById('otpValue');
  const form   = document.getElementById('otpForm');

  function syncHidden() {
    hidden.value = boxes.map(b => b.value).join('');
  }

  boxes.forEach((box, idx) => {
    box.addEventListener('input', e => {
      // Allow only digits
      box.value = box.value.replace(/\D/g, '').slice(-1);
      syncHidden();
      if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
    });

    box.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !box.value && idx > 0) {
        boxes[idx - 1].value = '';
        boxes[idx - 1].focus();
        syncHidden();
      }
      // Auto-submit when last digit entered
      if (e.key === 'Enter') form.submit();
    });

    box.addEventListener('paste', e => {
      e.preventDefault();
      const pasted = (e.clipboardData || window.clipboardData)
        .getData('text').replace(/\D/g, '').slice(0, 6);
      pasted.split('').forEach((ch, i) => {
        if (boxes[i]) boxes[i].value = ch;
      });
      syncHidden();
      const next = boxes[Math.min(pasted.length, boxes.length - 1)];
      if (next) next.focus();
    });
  });

  // Auto-submit when all 6 digits filled
  boxes.forEach(box => {
    box.addEventListener('input', () => {
      if (hidden.value.length === 6) {
        setTimeout(() => form.submit(), 200);
      }
    });
  });

  const autoBtn = document.getElementById('btnAutoFill');
  const codeDisp = document.getElementById('instantCodeDisplay');
  if (autoBtn && codeDisp) {
    autoBtn.addEventListener('click', function () {
      const code = codeDisp.innerText.trim();
      code.split('').forEach((ch, i) => {
        if (boxes[i]) boxes[i].value = ch;
      });
      syncHidden();
      form.submit();
    });
  }

  boxes[0].focus();
})();
</script>

</body>
</html>
