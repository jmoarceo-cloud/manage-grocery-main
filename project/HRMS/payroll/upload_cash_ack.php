<?php
// ============================================================
// payroll/upload_cash_ack.php
// Handles the "Upload signed receipt" form on payslip.php, shown
// only for employees on the Cash (Walk-in) payout method. Since
// there's no bank transfer to point to as proof the salary was
// actually handed over, HR/Admin instead upload a scanned signed
// receipt/acknowledgment here, tied to that specific payroll_id
// (one pay period's release, not the employee in general).
// Regular form POST (not AJAX) — redirects back to payslip.php with
// a flash message, same pattern as employee_documents.php.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
requireHR(); // Admin, HR Staff, HR Manager can all upload proof of a cash release

$return_to = $_POST['return_to'] ?? '../payslip.php';
// Only allow redirecting back within this app (defense-in-depth —
// avoid this being used as an open redirect).
if (!preg_match('#^[A-Za-z0-9_./?=&-]+$#', $return_to)) {
    $return_to = '../payslip.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($return_to);
}

requireCSRF();

$payroll_id = (int) ($_POST['payroll_id'] ?? 0);
$note       = trim($_POST['note'] ?? '');

$stmt = $pdo->prepare("
    SELECT pr.payroll_id, e.payment_method
    FROM payroll_records pr
    JOIN employees e ON e.employee_id = pr.employee_id
    WHERE pr.payroll_id = ?
");
$stmt->execute([$payroll_id]);
$record = $stmt->fetch();

define('MAX_CASH_ACK_SIZE', 5 * 1024 * 1024); // 5MB
$ALLOWED_CASH_ACK_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

if (!$record) {
    $_SESSION['flash_error'] = "Payroll record not found.";
} elseif ($record['payment_method'] !== 'cash') {
    $_SESSION['flash_error'] = "This employee is not on the Cash (Walk-in) payout method.";
} elseif (empty($_FILES['ack_file']['name'])) {
    $_SESSION['flash_error'] = "Please select a file to upload first.";
} elseif ($_FILES['ack_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['flash_error'] = "There was a problem with the upload. Please try again.";
} elseif ($_FILES['ack_file']['size'] > MAX_CASH_ACK_SIZE) {
    $_SESSION['flash_error'] = "The file is too large (max 5MB).";
} else {
    $ext = strtolower(pathinfo($_FILES['ack_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_CASH_ACK_EXT, true)) {
        $_SESSION['flash_error'] = "This file type is not supported. Only accepted: " . implode(', ', $ALLOWED_CASH_ACK_EXT) . ".";
    } elseif ($ext === 'pdf' && !isValidPdfSignature($_FILES['ack_file']['tmp_name'])) {
        // Item 5 (file upload hardening) — this only checked the extension
        // before, so a renamed non-PDF file would sail through. Same
        // magic-byte check already used for resumes/employee documents.
        $_SESSION['flash_error'] = "That file doesn't look like a valid PDF.";
    } elseif ($ext !== 'pdf' && @getimagesize($_FILES['ack_file']['tmp_name']) === false) {
        // Same idea for the jpg/jpeg/png branch — confirm it actually
        // decodes as an image, not just that the name ends in .jpg.
        $_SESSION['flash_error'] = "That file doesn't look like a valid image.";
    } else {
        try {
            $upload_dir  = __DIR__ . '/../uploads/cash_ack/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }

            $stored_filename = 'cashack_' . bin2hex(random_bytes(12)) . '.' . $ext;
            $destination = $upload_dir . $stored_filename;

            if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                $_SESSION['flash_error'] = "The server's receipt upload folder is missing or not writable. Please contact the administrator.";
            } elseif (!move_uploaded_file($_FILES['ack_file']['tmp_name'], $destination)) {
                $_SESSION['flash_error'] = "The file could not be saved on the server.";
            } else {
                $pdo->prepare("
                    UPDATE payroll_records SET
                        cash_ack_file = ?, cash_ack_original_name = ?, cash_ack_note = ?,
                        cash_ack_uploaded_by = ?, cash_ack_uploaded_at = NOW()
                    WHERE payroll_id = ?
                ")->execute([
                    $stored_filename,
                    $_FILES['ack_file']['name'],
                    $note ?: null,
                    $_SESSION['user_id'],
                    $payroll_id,
                ]);
                $pdo->prepare("
                    INSERT INTO payroll_logs (payroll_id, action, performed_by, notes)
                    VALUES (?, 'Cash release acknowledgment uploaded', ?, ?)
                ")->execute([
                    $payroll_id,
                    $_SESSION['user_id'],
                    'Signed receipt uploaded as proof the cash salary was released.' . ($note !== '' ? ' Note: ' . $note : ''),
                ]);
                $_SESSION['flash_success'] = "Signed receipt uploaded.";
            }
        } catch (PDOException $e) {
            error_log("Cash ack upload error: " . $e->getMessage());
            $_SESSION['flash_error'] = "The receipt could not be saved. Please try again.";
        }
    }
}

redirect($return_to);
