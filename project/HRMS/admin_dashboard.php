<?php
require_once 'includes/config.php';
require_once 'includes/mailer.php';
require_once 'includes/application_form.php';
requireAdmin();

$field_label_map = getFieldLabelMap($pdo);

$success_message = "";
$error_message = "";

// From the successful "Add User Account" redirect (PRG pattern above).
if (isset($_GET['created']) && $_GET['created'] == '1') {
    $success_message = "User account created successfully.";
}

// =====================================================
// HANDLE FORM SUBMISSIONS
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    requireCSRF();
    $action = $_POST['action'] ?? '';

    // -------------------------------------------------
    // LEAVE REQUESTS: approve / reject
    // -------------------------------------------------
    if ($action == 'approve_leave' || $action == 'reject_leave') {

        $leave_id = $_POST['leave_id'] ?? 0;
        $new_status = $action == 'approve_leave' ? 'approved' : 'rejected';

        $result = processLeaveDecision($pdo, $leave_id, $new_status, $_SESSION['user_id']);
        if ($result['ok']) {
            $success_message = $result['message'];
        } else {
            $error_message = $result['message'];
        }
    }

    // -------------------------------------------------
    // USER ACCOUNTS: add
    // -------------------------------------------------
    elseif ($action == 'add_user') {

        $employee_id = trim($_POST['employee_id'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = trim($_POST['role'] ?? '');

        if (empty($employee_id)) {
            $error_message = "You must select an employee to link to the account. Employees not on the list cannot be given an account.";
        } elseif (empty($username) || empty($password)) {
            $error_message = "Username and password are required.";
        } elseif (strlen($password) < 6) {
            $error_message = "Password must be at least 6 characters.";
        } elseif (!in_array($role, ['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee'])) {
            $error_message = "Please select a valid role.";
        } else {
            try {
                // Verify that the selected employee is valid AND doesn't
                // already have another linked account (defense in-depth —
                // don't just rely on the <select> shown in the form).
                $stmt = $pdo->prepare("
                    SELECT employee_id, first_name, last_name FROM employees
                    WHERE employee_id = ?
                    AND employee_id NOT IN (SELECT employee_id FROM users WHERE employee_id IS NOT NULL)
                    AND status NOT IN ('resigned', 'terminated')
                ");
                $stmt->execute([$employee_id]);
                $emp = $stmt->fetch();

                if (!$emp) {
                    $error_message = "Invalid or unavailable employee selected.";
                } else {
                    // The full name is taken directly from the employee record
                    // (not from the form's text field content), to make sure
                    // the name matches between the account and the Employees list.
                    $full_name = $emp['first_name'] . ' ' . $emp['last_name'];

                    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$username]);

                    if ($stmt->fetch()) {
                        $error_message = "This username is already taken. Please choose a different username.";
                    } else {
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO users (employee_id, username, password, full_name, role) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$employee_id, $username, $hashed, $full_name, $role]);

                        // PRG: go straight back to the list (no ?action=add_user)
                        // after creating, so the form automatically closes and
                        // the new account is immediately visible in User Accounts.
                        redirect('admin_dashboard.php?created=1#user-accounts');
                    }
                }
            } catch (PDOException $e) {
                error_log("Add user error: " . $e->getMessage());
                $error_message = "Could not create the user account. Please try again.";
            }
        }
    }

    // -------------------------------------------------
    // USER ACCOUNTS: edit (full name, username, role, linked employee)
    // -------------------------------------------------
    elseif ($action == 'edit_user') {

        $user_id = $_POST['user_id'] ?? 0;
        $employee_id = trim($_POST['employee_id'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $role = trim($_POST['role'] ?? '');

        if (empty($username) || empty($full_name)) {
            $error_message = "Username and full name are required.";
        } elseif (!in_array($role, ['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee'])) {
            $error_message = "Please select a valid role.";
        } else {
            try {
                // check username not taken by *another* user
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$username, $user_id]);

                if ($stmt->fetch()) {
                    $error_message = "This username is already taken. Please choose a different username.";
                } else {
                    // guard: don't demote the last active admin
                    $stmt = $pdo->prepare("SELECT role, is_active FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $target = $stmt->fetch();

                    if (!$target) {
                        $error_message = "User not found.";
                    } elseif ($target['role'] == 'admin' && $role != 'admin' && $target['is_active'] == 1) {
                        $count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                        if ($count <= 1) {
                            $error_message = "The role of the last active admin account cannot be changed.";
                        }
                    }

                    if (empty($error_message)) {
                        $stmt = $pdo->prepare("UPDATE users SET employee_id = ?, username = ?, full_name = ?, role = ? WHERE id = ?");
                        $stmt->execute([$employee_id ?: null, $username, $full_name, $role, $user_id]);
                        $success_message = "User account updated.";
                    }
                }
            } catch (PDOException $e) {
                error_log("Edit user error: " . $e->getMessage());
                $error_message = "Could not update the user account. Please try again.";
            }
        }
    }

    // -------------------------------------------------
    // USER ACCOUNTS: reset password
    // -------------------------------------------------
    elseif ($action == 'reset_user_password') {

        $user_id = $_POST['user_id'] ?? 0;
        $new_password = trim($_POST['new_password'] ?? '');

        if (strlen($new_password) < 8 || !preg_match('/[A-Za-z]/', $new_password) || !preg_match('/\d/', $new_password)) {
            $error_message = "Use at least 8 characters, including a letter and a number.";
        } else {
            try {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ?, force_logout = 1 WHERE id = ?")
                    ->execute([$hashed, $user_id]);
                $success_message = "Password reset successfully. This user will be logged out on their next request.";
            } catch (PDOException $e) {
                error_log("Reset password error: " . $e->getMessage());
                $error_message = "Could not reset the password. Please try again.";
            }
        }
    }

    // -------------------------------------------------
    // USER ACCOUNTS: toggle active
    // -------------------------------------------------
    elseif ($action == 'toggle_active') {

        $user_id = $_POST['user_id'] ?? 0;

        if ($user_id == $_SESSION['user_id']) {
            $error_message = "You cannot deactivate your own account.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT is_active, role FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $target = $stmt->fetch();

                if (!$target) {
                    $error_message = "User not found.";
                } elseif ($target['is_active'] == 1 && $target['role'] == 'admin') {
                    $count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                    if ($count <= 1) {
                        $error_message = "The last active admin account cannot be deactivated.";
                    }
                }

                if (empty($error_message)) {
                    $new_state = $target['is_active'] ? 0 : 1;
                    $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$new_state, $user_id]);
                    $success_message = $new_state ? "Account activated." : "Account deactivated.";
                }
            } catch (PDOException $e) {
                error_log("Toggle active error: " . $e->getMessage());
                $error_message = "Could not update the account. Please try again.";
            }
        }
    }

    // -------------------------------------------------
    // USER ACCOUNTS: force logout
    // -------------------------------------------------
    elseif ($action == 'force_logout') {

        $user_id = $_POST['user_id'] ?? 0;

        if ($user_id == $_SESSION['user_id']) {
            $error_message = "You cannot force logout your own account.";
        } else {
            try {
                $pdo->prepare("UPDATE users SET force_logout = 1 WHERE id = ?")->execute([$user_id]);
                $success_message = "This user will be logged out on their next request.";
            } catch (PDOException $e) {
                error_log("Force logout error: " . $e->getMessage());
                $error_message = "Could not force logout. Please try again.";
            }
        }
    }

    // -------------------------------------------------
    // USER ACCOUNTS: delete
    // -------------------------------------------------
    elseif ($action == 'delete_user') {

        $user_id = $_POST['user_id'] ?? 0;

        if ($user_id == $_SESSION['user_id']) {
            $error_message = "You cannot delete your own account.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $target = $stmt->fetch();

                if (!$target) {
                    $error_message = "User not found.";
                } elseif ($target['role'] == 'admin') {
                    $count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                    if ($count <= 1) {
                        $error_message = "The last admin account cannot be deleted.";
                    }
                }

                if (empty($error_message)) {
                    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
                    $success_message = "User account deleted.";
                }
            } catch (PDOException $e) {
                error_log("Delete user error: " . $e->getMessage());
                $error_message = "Could not delete this account. This account has related records (such as payroll logs) that must be deleted first.";
            }
        }
    }

    // Note: the "change_own_password" action for Admin's own profile now
    // lives in admin_profile.php (a separate page — see "My Profile"
    // above), along with the "My Admin Profile" section itself. What's
    // left here are only the actions that genuinely belong to the Dashboard.

}

// =====================================================
// EMPLOYEE STATS
// =====================================================
$employee_stats = ['active' => 0, 'on-leave' => 0, 'resigned' => 0, 'terminated' => 0];
$stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM employees GROUP BY status");
foreach ($stmt->fetchAll() as $row) {
    $employee_stats[$row['status']] = (int) $row['cnt'];
}
$total_employees = array_sum($employee_stats);

$open_dsar_count = (int) $pdo->query("
    SELECT COUNT(*) FROM data_subject_requests
    WHERE status IN ('pending','identity_verification','identity_verified','in_review','on_hold')
")->fetchColumn();
$pending_applicant_reviews = (int) $pdo->query("
    SELECT COUNT(*) FROM job_applicants
    WHERE status IN ('for_resume','for_initial','for_final','for_requirements','for_admin_approval')
")->fetchColumn();
$upcoming_contracts = (int) $pdo->query("
    SELECT COUNT(*) FROM hr_letters
    WHERE letter_type = 'contract'
      AND status IN ('sent','accepted')
      AND contract_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
")->fetchColumn();
$recent_hr_audit = $pdo->query("
    SELECT id, module, record_id, action, details, user_name, created_at
    FROM hr_audit_log
    ORDER BY created_at DESC, id DESC
    LIMIT 10
")->fetchAll();

// =====================================================
// ATTENDANCE TODAY
// =====================================================
$attendance_stats = ['present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0, 'day_off' => 0];
$stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM attendance WHERE date = CURDATE() GROUP BY status");
$stmt->execute();
foreach ($stmt->fetchAll() as $row) {
    $attendance_stats[$row['status']] = (int) $row['cnt'];
}
$late_today = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND late_minutes > 0")->fetchColumn();
$no_record_today = max(0, $employee_stats['active'] - array_sum($attendance_stats));

// =====================================================
// PAYROLL OVERVIEW
// =====================================================
// NOTE: 'pending' (still awaiting Admin's approval — for ANY payroll
// batch not processed by Admin themself, whether Employee or HR
// Staff/HR Manager) and 'rejected' are excluded from the totals
// below — that money hasn't actually been released yet.
// See the "Pending Payroll Approvals" panel above on this Dashboard
// for batches still awaiting a decision.
$latest_period = $pdo->query("
    SELECT pay_period_start, pay_period_end, payout_date
    FROM payroll_records
    WHERE approval_status IN ('n/a', 'approved')
    ORDER BY pay_period_end DESC, generated_at DESC
    LIMIT 1
")->fetch();

$payroll_summary = null;
$employees_pending_payroll = 0;

if ($latest_period) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT employee_id) as paid_count, SUM(gross_pay) as total_gross, SUM(net_pay) as total_net
        FROM payroll_records
        WHERE pay_period_end = ? AND approval_status IN ('n/a', 'approved')
    ");
    $stmt->execute([$latest_period['pay_period_end']]);
    $payroll_summary = $stmt->fetch();
    $employees_pending_payroll = max(0, $employee_stats['active'] - (int) $payroll_summary['paid_count']);
}

$ytd_net_pay = (float) $pdo->query("
    SELECT COALESCE(SUM(net_pay), 0) FROM payroll_records WHERE YEAR(pay_period_end) = YEAR(CURDATE()) AND approval_status IN ('n/a', 'approved')
")->fetchColumn();

// Count of payroll batches still awaiting a decision from Admin — for
// a small reminder/link to Pending Payroll Approvals.
$pending_payroll_approvals_count = (int) $pdo->query("
    SELECT COUNT(DISTINCT CONCAT(pay_period_start,'_',pay_period_end,'_',generated_by))
    FROM payroll_records WHERE approval_status = 'pending'
")->fetchColumn();

// =====================================================
// PENDING LEAVE REQUESTS
// =====================================================
// Default view is per-day (based on the request date), but you can
// still go back to previous days using the date navigation.
// A separate COUNT (unfiltered) is used for the KPI card above so
// the true count of all pending requests isn't lost from view.
$leave_pending_date = isset($_GET['leave_pending_date']) ? trim($_GET['leave_pending_date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $leave_pending_date)) {
    $leave_pending_date = date('Y-m-d');
}
if ($leave_pending_date > date('Y-m-d')) {
    $leave_pending_date = date('Y-m-d');
}
$prev_leave_pending_date = date('Y-m-d', strtotime($leave_pending_date . ' -1 day'));
$next_leave_pending_date = date('Y-m-d', strtotime($leave_pending_date . ' +1 day'));
$is_today_leave_pending = ($leave_pending_date === date('Y-m-d'));

$total_pending_leaves_count = (int) $pdo->query("
    SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'
")->fetchColumn();

$stmt = $pdo->prepare("
    SELECT lr.*, e.first_name, e.last_name, e.employee_code
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    WHERE lr.status = 'pending'
      AND DATE(lr.requested_at) = ?
    ORDER BY lr.requested_at ASC
");
$stmt->execute([$leave_pending_date]);
$pending_leaves = $stmt->fetchAll();

$leave_decisions_date = isset($_GET['leave_decisions_date']) ? trim($_GET['leave_decisions_date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $leave_decisions_date)) {
    $leave_decisions_date = date('Y-m-d');
}
if ($leave_decisions_date > date('Y-m-d')) {
    $leave_decisions_date = date('Y-m-d');
}
$prev_leave_decisions_date = date('Y-m-d', strtotime($leave_decisions_date . ' -1 day'));
$next_leave_decisions_date = date('Y-m-d', strtotime($leave_decisions_date . ' +1 day'));
$is_today_leave_decisions = ($leave_decisions_date === date('Y-m-d'));

$stmt = $pdo->prepare("
    SELECT lr.*, e.first_name, e.last_name, e.employee_code, u.full_name as approver_name, u.role as approver_role
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    LEFT JOIN users u ON lr.approved_by = u.id
    WHERE lr.status IN ('approved', 'rejected')
      AND DATE(COALESCE(lr.decided_at, lr.requested_at)) = ?
    ORDER BY COALESCE(lr.decided_at, lr.requested_at) DESC
");
$stmt->execute([$leave_decisions_date]);
$recent_leave_decisions = $stmt->fetchAll();

// =====================================================
// USER ACCOUNTS
// =====================================================
// The "User Accounts" table here is only for the accounts of Employees
// and HR (hr_staff, hr_manager, employee) — Admin is SEPARATE, since
// they're not "included" among the employees they oversee. Admin's own
// account can instead be seen in the "My Admin Profile" section.
$users = $pdo->query("
    SELECT u.*, e.first_name, e.last_name, e.employee_code
    FROM users u
    LEFT JOIN employees e ON u.employee_id = e.employee_id
    WHERE u.role != 'admin'
      AND (e.status IS NULL OR e.status NOT IN ('resigned', 'terminated'))
    ORDER BY u.id DESC
")->fetchAll();

// Note: the logged-in Admin's own account (username, full
// name, status, change-password) can instead be seen on admin_profile.php
// — a separate page, no longer here on the Dashboard.

$available_employees = $pdo->query("
    SELECT employee_id, employee_code, first_name, last_name
    FROM employees
    WHERE employee_id NOT IN (SELECT employee_id FROM users WHERE employee_id IS NOT NULL)
      AND status NOT IN ('resigned', 'terminated')
    ORDER BY first_name
")->fetchAll();

// Cashier accounts also log into the POS terminal (separate database),
// where they can get locked out after too many failed attempts. Pull
// that lock status here (by username) so HR/Admin can see, right in
// this table, which accounts need a password reset to unlock — no need
// to check the POS side separately.
$posLockedUsernames = [];
if ($posPdo) {
    try {
        $stmt = $posPdo->query("SELECT username FROM users WHERE is_locked = 1");
        $posLockedUsernames = array_flip(array_column($stmt->fetchAll(), 'username'));
    } catch (PDOException $e) {
        error_log("POS lock status lookup skipped: " . $e->getMessage());
    }
}

// =====================================================
// RECENT ACTIVITY LOG
// =====================================================
// Also defaults to per-day (based on logged_at), with prev/next day
// navigation, and the table scrolls if the content is long.
$activity_date = isset($_GET['activity_date']) ? trim($_GET['activity_date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $activity_date)) {
    $activity_date = date('Y-m-d');
}
if ($activity_date > date('Y-m-d')) {
    $activity_date = date('Y-m-d');
}
$prev_activity_date = date('Y-m-d', strtotime($activity_date . ' -1 day'));
$next_activity_date = date('Y-m-d', strtotime($activity_date . ' +1 day'));
$is_today_activity = ($activity_date === date('Y-m-d'));

$stmt = $pdo->prepare("
    SELECT pl.*, u.full_name as performed_by_name
    FROM payroll_logs pl
    LEFT JOIN users u ON pl.performed_by = u.id
    WHERE DATE(pl.logged_at) = ?
    ORDER BY pl.logged_at DESC
");
$stmt->execute([$activity_date]);
$activity_log = $stmt->fetchAll();

// =====================================================
// HELPERS
// =====================================================
function getRoleBadge($role)
{
    $map = [
        'admin' => ['label' => 'Admin', 'bg' => 'var(--green-pale)', 'color' => 'var(--text-accent)'],
        'hr' => ['label' => 'HR Staff', 'bg' => 'var(--mustard-pale)', 'color' => 'var(--mustard-text2, #97661F)'],
        'hr_manager' => ['label' => 'HR Manager', 'bg' => 'var(--blue-bg, #E8EEFB)', 'color' => '#2C4A8F'],
        'employee' => ['label' => 'Employee', 'bg' => 'var(--gray-bg, #EFEFEF)', 'color' => 'var(--ink-soft)'],
    ];
    return $map[$role] ?? ['label' => ucfirst($role), 'bg' => 'var(--gray-bg, #EFEFEF)', 'color' => 'var(--ink-soft)'];
}

function getLeaveTypeLabel($type)
{
    $map = ['sick' => 'Sick Leave', 'vacation' => 'Vacation Leave', 'emergency' => 'Emergency Leave', 'others' => 'Others'];
    return $map[$type] ?? ucfirst($type);
}

function daysBetween($from, $to)
{
    $d1 = new DateTime($from);
    $d2 = new DateTime($to);
    return $d1->diff($d2)->days + 1;
}

$show_add_user_form = isset($_GET['action']) && $_GET['action'] == 'add_user';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — HRMS &amp; Payroll</title>
    <link rel="stylesheet"
        href="../assets/css/theme.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/theme.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --background: #f8fafc;
            --surface: #ffffff;
            --surface-raised: #ffffff;
            --surface-muted: #f1f5f9;
            --border: #e2e8f0;
            --text: #0f172a;
            --text-muted: #475569;
            --accent: #2563eb;
            --accent-hover: #1d4ed8;
            --accent-foreground: #ffffff;
            --focus-ring: rgb(37 99 235 / 45%);
            --shadow-surface: 0 12px 32px rgb(15 23 42 / 8%);
            --green-deep: #1d4ed8;
            --text-accent: #2563eb;
            --green-mid: #2563eb;
            --green-leaf: #0ea5e9;
            --green-pale: #eff6ff;
            --mustard: #f59e0b;
            --mustard-pale: #fffbeb;
            --red-alert: #dc2626;
            --red-pale: #fef2f2;
            --cream: var(--background);
            --white: var(--surface);
            --ink: var(--text);
            --ink-soft: var(--text-muted);
            --line: var(--border);
            --font-display: Georgia, "Times New Roman", serif;
            --font-body: "Plus Jakarta Sans", "Segoe UI", Arial, sans-serif;
        }

        html,
        body {
            min-height: 100vh;
            font-family: var(--font-body);
            color: var(--ink);
            background: var(--cream);
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* ── Sidebar (same look/size as the POS "My Profile" sidebar) ── */
        .app-shell {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 288px;
            flex-shrink: 0;
            background: radial-gradient(420px 260px at 100% 0%, rgba(79, 195, 209, .16), transparent 60%), var(--green-deep);
            color: #FFFFFF;
            display: flex;
            flex-direction: column;
            padding: 30px 24px;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
            box-shadow: 2px 0 12px rgba(0, 0, 0, 0.18);
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 0 4px 26px;
            margin-bottom: 22px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.14);
        }

        .brand-mark {
            width: 46px;
            height: 46px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--white);
            border-radius: 13px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, .14);
        }

        .brand-mark .brand-icon,
        .brand-icon {
            width: 28px;
            height: 28px;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.35;
        }

        .brand-name {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 17px;
        }

        .brand-tagline {
            font-size: 11px;
            color: var(--green-leaf);
            font-weight: 600;
            letter-spacing: .02em;
        }

        .nav-section-label {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: rgba(255, 255, 255, .42);
            padding: 0 14px;
            margin: 4px 0 10px;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 13px 15px;
            border-radius: 12px;
            color: rgba(255, 255, 255, 0.82);
            font-size: 14.5px;
            font-weight: 600;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .nav-icon-box {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, .08);
        }

        .nav-link.is-active .nav-icon-box {
            background: rgba(255, 255, 255, .35);
        }

        .nav-icon {
            width: 17px;
            height: 17px;
            flex-shrink: 0;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #FFFFFF;
        }

        .nav-link.is-active {
            background: var(--green-leaf);
            color: var(--green-deep);
            box-shadow: 0 4px 12px rgba(0, 0, 0, .16);
        }

        .nav-link--logout {
            margin-top: 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.14);
            padding-top: 18px;
            color: rgba(255, 255, 255, 0.65);
        }

        .nav-link--logout:hover {
            background: rgba(200, 71, 45, 0.18);
            color: var(--salmon-bg, #ffb3a3);
        }

        .sidebar-foot {
            margin-top: auto;
            padding: 14px;
            border-radius: 14px;
            background: rgba(255, 255, 255, .06);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-foot .foot-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            flex-shrink: 0;
            background: linear-gradient(150deg, var(--green-leaf), #2E9BAE);
            color: var(--green-deep);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 15px;
            overflow: hidden;
        }

        .sidebar-foot .foot-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .sidebar-foot .foot-text {
            display: flex;
            flex-direction: column;
            line-height: 1.3;
            min-width: 0;
        }

        .sidebar-foot .foot-name {
            font-size: 13.5px;
            font-weight: 700;
            color: #FFFFFF;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-foot .foot-role {
            font-size: 11.5px;
            color: rgba(255, 255, 255, 0.6);
            font-weight: 600;
        }

        .main-area {
            margin-left: 288px;
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 1;
        }

        @media (max-width: 900px) {
            .sidebar {
                width: 76px;
                padding: 24px 12px;
            }

            .brand-text,
            .nav-link span,
            .sidebar-foot .foot-text {
                display: none;
            }

            .sidebar-brand {
                justify-content: center;
                padding-bottom: 18px;
            }

            .nav-link {
                justify-content: center;
                padding: 12px;
                gap: 0;
            }

            .sidebar-foot {
                justify-content: center;
                padding: 10px;
            }

            .main-area {
                margin-left: 76px;
            }
        }

        .topbar {
            background: var(--white);
            border-bottom: 1px solid var(--line);
            padding: 16px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .topbar h1 {
            font-family: var(--font-display);
            font-size: 20px;
            color: var(--text-accent);
        }

        .topbar .user-info {
            font-size: 13.5px;
            color: var(--ink-soft);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--mustard);
            color: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .topbar a.logout {
            color: var(--red-alert);
            font-weight: 600;
            font-size: 13.5px;
        }

        .container {
            padding: 28px 32px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
        }

        .alert--success {
            background: var(--green-succ-bg, #e6f4ea);
            color: var(--green-succ-text, #1a7a3b);
        }

        .alert--error {
            background: var(--red-pale);
            color: var(--red-alert);
        }

        /* Used by the shared includes/pending_payroll_approvals.php panel */
        .card-box {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 20px;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .add-btn {
            font-family: var(--font-body);
            font-size: 14px;
            font-weight: 600;
            background: var(--green-mid);
            color: #FFFFFF;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            cursor: pointer;
            transition: background .15s ease;
        }

        .add-btn:hover {
            filter: brightness(1.08);
            box-shadow: 0 5px 16px rgba(18, 60, 92, 0.3);
            transform: translateY(-2px);
        }

        .add-btn:active {
            transform: translateY(0) scale(.98);
        }

        .add-btn.danger {
            background: #dc2626;
            color: #fff;
            border-color: #b91c1c;
            box-shadow: 0 2px 8px rgba(220, 38, 38, .2);
        }

        .add-btn.danger:hover {
            background: #b91c1c;
            color: #fff;
        }

        .add-btn:disabled {
            background: var(--gray-bg, #D8D3C4) !important;
            color: #FFFFFF;
            cursor: not-allowed;
        }

        .empty-note {
            text-align: center;
            padding: 24px;
            color: var(--ink-soft);
            font-size: 13.5px;
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            font-family: var(--font-body);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: filter .15s ease, box-shadow .15s ease, transform .15s ease, background .15s ease, border-color .15s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--green-mid), var(--green-deep));
            color: #FFFFFF;
            box-shadow: 0 2px 8px rgba(18, 60, 92, 0.22);
        }

        .btn-primary:hover {
            filter: brightness(1.08);
            box-shadow: 0 5px 16px rgba(18, 60, 92, 0.32);
            transform: translateY(-2px);
        }

        .btn-primary:active {
            transform: translateY(0);
            filter: brightness(0.97);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--ink);
            border: 1.5px solid var(--line);
        }

        .btn-secondary:hover {
            background: var(--cream);
            border-color: var(--green-leaf);
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(18, 60, 92, 0.08);
        }

        .btn-secondary:active {
            transform: translateY(0);
            box-shadow: none;
        }

        .btn-danger {
            background: var(--red-pale);
            color: var(--red-alert);
        }

        .btn-danger:hover {
            background: var(--salmon-bg, #f5d3ca);
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(200, 71, 45, 0.16);
        }

        .btn-danger:active {
            transform: translateY(0);
            box-shadow: none;
        }

        .btn-success {
            background: var(--green-pale);
            color: var(--text-accent);
        }

        .btn-success:hover {
            background: var(--green-succ-bg, #d5ebda);
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(18, 60, 92, 0.1);
        }

        .btn-success:active {
            transform: translateY(0);
            box-shadow: none;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12.5px;
        }

        /* KPI cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .kpi-card {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 20px;
            border-top: 3px solid var(--green-mid);
        }

        .kpi-card.accent-mustard {
            border-top-color: var(--mustard);
        }

        .kpi-card.accent-red {
            border-top-color: var(--red-alert);
        }

        .kpi-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--ink-soft);
            font-weight: 600;
            margin-bottom: 10px;
        }

        .kpi-value {
            font-family: var(--font-display);
            font-size: 30px;
            color: var(--text-accent);
            line-height: 1;
        }

        .kpi-sub {
            font-size: 12.5px;
            color: var(--ink-soft);
            margin-top: 6px;
        }

        /* Sections & panels */
        .section {
            margin-bottom: 28px;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-header h2 {
            font-family: var(--font-display);
            font-size: 18px;
            color: var(--text-accent);
        }

        .section-header .link-out {
            font-size: 13px;
            font-weight: 600;
            color: var(--green-mid);
        }

        .panel-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .panel {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 24px;
        }

        .panel h3 {
            font-family: var(--font-display);
            font-size: 16px;
            color: var(--text-accent);
            margin-bottom: 14px;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--line);
            font-size: 14px;
        }

        .stat-row:last-child {
            border-bottom: none;
        }

        .stat-row .stat-label {
            color: var(--ink-soft);
        }

        .stat-row .stat-value {
            font-weight: 700;
        }

        .table-card {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 16px;
            overflow: hidden;
        }

        .table-scroll {
            max-height: 420px;
            overflow-y: auto;
        }

        .table-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .table-scroll-compact {
            max-height: 260px;
            overflow-y: auto;
        }

        .table-scroll-compact thead th {
            position: sticky;
            top: 0;
            z-index: 1;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            font-size: 12.5px;
            text-transform: uppercase;
            letter-spacing: .03em;
            color: var(--ink-soft);
            background: var(--cream);
            padding: 12px 16px;
            border-bottom: 1px solid var(--line);
        }

        td {
            padding: 14px 16px;
            font-size: 14px;
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: var(--gray-bg, #FAFAF7);
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .actions-cell {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .actions-cell form {
            display: inline-flex;
            align-items: center;
            margin: 0;
        }

        .actions-group {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }

        .actions-group--danger {
            padding-left: 10px;
            border-left: 1.5px solid var(--line);
        }

        #user-accounts .actions-cell .btn {
            white-space: nowrap;
            -webkit-text-fill-color: #fff !important;
        }

        #user-accounts .actions-cell .btn-secondary:hover,
        #user-accounts .actions-cell .btn-secondary:focus,
        #user-accounts .actions-cell .btn-secondary:active {
            background: var(--accent-hover, #1d4ed8) !important;
            border-color: var(--accent-hover, #1d4ed8) !important;
            color: #fff !important;
            -webkit-text-fill-color: #fff !important;
        }

        .badge-locked {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: var(--red-pale, var(--red-pale));
            color: var(--red-alert, var(--red-alert));
            margin-left: 6px;
            font-size: 11px;
            padding: 3px 9px;
            vertical-align: middle;
        }

        .empty-state {
            text-align: center;
            padding: 40px 24px;
            color: var(--ink-soft);
            font-size: 14px;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(18, 60, 92, .25);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            z-index: 100;
        }

        .modal-card {
            background: var(--white);
            border-radius: 20px;
            padding: 32px;
            max-width: 480px;
            width: 100%;
            max-height: 88vh;
            overflow-y: auto;
        }

        .modal-card h2 {
            font-family: var(--font-display);
            color: var(--text-accent);
            font-size: 20px;
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .form-grid .full {
            grid-column: 1 / -1;
        }

        .field-group label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--ink);
        }

        .field-group input,
        .field-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid var(--line);
            border-radius: 10px;
            font-family: var(--font-body);
            font-size: 13.5px;
        }

        .field-group .hint {
            font-size: 11.5px;
            color: var(--ink-soft);
            margin-top: 4px;
        }

        .password-wrap {
            position: relative;
        }

        .password-wrap input {
            width: 100%;
            padding-right: 40px !important;
        }

        .password-toggle-btn {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ink-soft);
            border-radius: 8px;
        }

        .password-toggle-btn:hover {
            color: var(--ink);
            background: var(--cream);
        }

        .password-toggle-btn svg {
            width: 18px;
            height: 18px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 22px;
        }

        #resetModal .modal-footer button.btn-secondary {
            background: var(--surface, #fff) !important;
            color: var(--ink, #0f172a) !important;
            -webkit-text-fill-color: var(--ink, #0f172a) !important;
            border: 1px solid var(--line, #dce5ec) !important;
            box-shadow: none !important;
        }

        #resetModal .modal-footer button.btn-secondary:hover,
        #resetModal .modal-footer button.btn-secondary:focus-visible,
        #resetModal .modal-footer button.btn-secondary:active {
            background: var(--green-pale, #e3f2f6) !important;
            color: var(--text-accent, #1d4ed8) !important;
            -webkit-text-fill-color: var(--text-accent, #1d4ed8) !important;
        }

        @media (max-width: 900px) {
            .panel-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .container {
                padding: 20px 16px;
            }

            .topbar {
                padding: 14px 16px;
                flex-wrap: wrap;
                gap: 8px;
            }
        }

        .admin-home-overview {
            display: grid;
            gap: 18px;
            margin-bottom: 24px;
            color: var(--text);
        }

        body.dashboard-page--administrator .main-area > .container {
            width: 100%;
            max-width: none;
            margin-inline: 0;
            padding-inline: clamp(20px, 2.4vw, 44px);
        }

        .admin-home-overview h2,
        .admin-home-overview h3 {
            color: var(--text);
        }

        .admin-home-kpis {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .admin-home-kpi,
        .admin-home-panel {
            min-width: 0;
            padding: 18px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface);
            box-shadow: var(--shadow-surface);
        }

        .admin-home-kpi {
            display: grid;
            gap: 8px;
            text-decoration: none;
        }

        .admin-home-kpi span:first-child {
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .admin-home-kpi strong {
            color: var(--accent);
            font-size: 30px;
            line-height: 1;
        }

        .admin-home-body {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(250px, .8fr);
            gap: 18px;
            align-items: start;
        }

        .admin-home-panel h2 {
            margin: 0 0 16px;
            font-size: 18px;
        }

        .admin-audit-scroll {
            overflow-x: auto;
        }

        .admin-audit-table {
            width: 100%;
            border-collapse: collapse;
            color: var(--text);
            font-size: 13px;
        }

        .admin-audit-table th,
        .admin-audit-table td {
            padding: 10px 9px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
        }

        .admin-audit-table th {
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
        }

        .admin-audit-table td {
            overflow-wrap: anywhere;
        }

        .admin-quick-actions {
            display: grid;
            align-content: start;
            gap: 10px;
        }

        .admin-quick-actions a {
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface-muted);
            color: var(--text);
            font-weight: 650;
            text-decoration: none;
        }

        .admin-quick-actions a:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .admin-home-overview .empty-note {
            color: var(--text-muted);
        }

        @media (max-width: 900px) {
            body.dashboard-page--administrator .main-area > .container {
                padding-inline: 20px;
            }

            .admin-home-kpis {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .admin-home-body {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            body.dashboard-page--administrator .main-area > .container {
                padding-inline: 14px;
            }

            .admin-home-kpis {
                grid-template-columns: 1fr 1fr;
                gap: 9px;
            }

            .admin-home-kpi {
                padding: 14px;
            }
        }
    </style>
    <link rel="stylesheet"
        href="../assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>">
</head>

<body class="dashboard-page dashboard-page--administrator">

    <div class="app-shell">
        <?php $activeNav = 'dashboard';
        include 'includes/hr_sidebar.php'; ?>
        <div class="main-area">

            <div class="topbar">
                <nav class="topbar-breadcrumbs" aria-label="Breadcrumb">
                    <span>HRMS &amp; Payroll</span>
                    <span aria-hidden="true">/</span>
                    <span>Admin</span>
                    <span aria-hidden="true">/</span>
                    <h1 aria-current="page">Dashboard</h1>
                </nav>
                <div class="topbar-actions">
                    <a class="topbar-icon-action"
                        href="<?= htmlspecialchars(rtrim(appBaseUrl(), '/') . '/hr_notifications.php', ENT_QUOTES, 'UTF-8') ?>"
                        aria-label="Open HRMS notifications" title="HRMS notifications">
                        <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M6 8a4 4 0 0 1 8 0c0 3.2 1 4.5 1.5 5H4.5C5 12.5 6 11.2 6 8Z" stroke="currentColor"
                                stroke-width="1.5" stroke-linejoin="round" />
                            <path d="M8.2 15.5a1.8 1.8 0 0 0 3.6 0" stroke="currentColor" stroke-width="1.5"
                                stroke-linecap="round" />
                        </svg>
                    </a>
                    <details class="topbar-profile">
                        <summary aria-label="Open account menu">
                            <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img
                                        src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>"
                                        alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span>
                            <span class="topbar-profile-copy">
                                <strong><?= clean($_SESSION['full_name'] ?? 'Administrator') ?></strong>
                                <span><?= clean(roleLabel($_SESSION['role'])) ?></span>
                            </span>
                            <svg class="topbar-profile-chevron" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                        </summary>
                        <div class="topbar-profile-menu">
                            <a href="admin_profile.php">Account profile</a>
                            <a href="<?= htmlspecialchars(logoutUrl(), ENT_QUOTES, 'UTF-8') ?>" data-confirm="Are you sure you want to log out?" data-confirm-title="Log out?" data-confirm-ok="Log Out" data-confirm-color="#123C5C">Log out</a>
                        </div>
                    </details>
                </div>
            </div>

            <div class="container">

                <section class="dashboard-hero" aria-labelledby="dashboard-greeting">
                    <div class="dashboard-hero__copy">
                        <span class="dashboard-hero__label">Administrator</span>
                        <h2 id="dashboard-greeting">
                            <?= date('G') < 12 ? 'Good morning' : (date('G') < 18 ? 'Good afternoon' : 'Good evening') ?>,
                            <?= clean($_SESSION['full_name'] ?? 'Administrator') ?>.
                        </h2>
                        <p>Full oversight of the system, users, and audit trail.</p>
                    </div>
                    <a class="dashboard-primary-action" href="privacy_requests.php">Review requests <span
                            aria-hidden="true">→</span></a>
                </section>

                <section class="admin-home-overview" aria-label="Admin overview">
                    <div class="admin-home-kpis">
                        <a class="admin-home-kpi" href="privacy_requests.php">
                            <span>Open DSARs</span><strong><?= $open_dsar_count ?></strong>
                        </a>
                        <a class="admin-home-kpi" href="applicant_review_queue.php">
                            <span>Pending Applicant Reviews</span><strong><?= $pending_applicant_reviews ?></strong>
                        </a>
                        <a class="admin-home-kpi" href="employees.php">
                            <span>Employees</span><strong><?= $total_employees ?></strong>
                        </a>
                        <a class="admin-home-kpi" href="hr_letters.php">
                            <span>Upcoming Contracts · 30 days</span><strong><?= $upcoming_contracts ?></strong>
                        </a>
                    </div>
                    <div class="admin-home-body">
                        <section class="admin-home-panel" aria-labelledby="recent-audit-heading">
                            <h2 id="recent-audit-heading">Recent audit log</h2>
                            <div class="admin-audit-scroll">
                                <table class="admin-audit-table">
                                    <thead>
                                        <tr>
                                            <th>When</th>
                                            <th>Actor</th>
                                            <th>Module / target</th>
                                            <th>Action</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_hr_audit as $audit): ?>
                                            <tr>
                                                <td><?= clean(date('M j, H:i', strtotime($audit['created_at']))) ?></td>
                                                <td><?= clean($audit['user_name'] ?: 'System') ?></td>
                                                <td><?= clean($audit['module']) ?> #<?= (int) $audit['record_id'] ?></td>
                                                <td><?= clean($audit['action']) ?></td>
                                                <td><?= clean($audit['details'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (!$recent_hr_audit): ?>
                                            <tr>
                                                <td colspan="5" class="empty-note">No HR audit entries have been recorded
                                                    yet.</td>
                                            </tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <nav class="admin-home-panel admin-quick-actions" aria-label="Quick actions">
                            <h2>Quick actions</h2>
                            <a href="privacy_requests.php">Review privacy requests</a>
                            <a href="applicant_review_queue.php">Review applicants</a>
                            <a href="employees.php">Manage employees</a>
                            <a href="hr_letters.php">Manage contracts and letters</a>
                            <a href="attendance.php">Open attendance</a>
                        </nav>
                    </div>
                </section>

                <?php // Success/error ngayon ay pinapakita bilang SweetAlert toast
                // (auto-disappear) sa dulo ng page — tingnan ang script bago
                // mag-</body>, hindi na ito namamalagi na static banner dito. ?>

                <!-- ============================================= -->
                <!-- KPI OVERVIEW                                   -->
                <!-- ============================================= -->
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-label">Total Employees</div>
                        <div class="kpi-value"><?= $total_employees ?></div>
                        <div class="kpi-sub"><?= $employee_stats['active'] ?> active</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">On Leave</div>
                        <div class="kpi-value"><?= $employee_stats['on-leave'] ?></div>
                        <div class="kpi-sub">right now</div>
                    </div>
                    <div class="kpi-card <?= $total_pending_leaves_count > 0 ? 'accent-mustard' : '' ?>">
                        <div class="kpi-label">Pending Leave Requests</div>
                        <div class="kpi-value"><?= $total_pending_leaves_count ?></div>
                        <div class="kpi-sub">need approval</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">Present Today</div>
                        <div class="kpi-value"><?= $attendance_stats['present'] ?></div>
                        <div class="kpi-sub"><?= $late_today ?> late · <?= $attendance_stats['absent'] ?> absent</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">YTD Net Payroll</div>
                        <div class="kpi-value" style="font-size:22px;"><?= peso($ytd_net_pay) ?></div>
                        <div class="kpi-sub">this year</div>
                    </div>
                    <div class="kpi-card <?= $employees_pending_payroll > 0 ? 'accent-red' : '' ?>">
                        <div class="kpi-label">User Accounts</div>
                        <div class="kpi-value"><?= count($users) ?></div>
                        <div class="kpi-sub"><?= $employees_pending_payroll ?> employees w/o payroll this period</div>
                    </div>
                </div>

                <!-- ============================================= -->
                <!-- ATTENDANCE + PAYROLL PANELS                    -->
                <!-- ============================================= -->
                <div class="section">
                    <div class="panel-grid">
                        <div class="panel">
                            <h3><a href="attendance.php" style="color:inherit;">Attendance Today (<?= date('M d, Y') ?>)
                                    &rarr;</a></h3>
                            <div class="stat-row"><span class="stat-label">Present</span><span
                                    class="stat-value"><?= $attendance_stats['present'] ?></span></div>
                            <div class="stat-row"><span class="stat-label">Late</span><span
                                    class="stat-value"><?= $late_today ?></span></div>
                            <div class="stat-row"><span class="stat-label">Absent</span><span
                                    class="stat-value"><?= $attendance_stats['absent'] ?></span></div>
                            <div class="stat-row"><span class="stat-label">On Leave</span><span
                                    class="stat-value"><?= $attendance_stats['leave'] ?></span></div>
                            <div class="stat-row"><span class="stat-label">Holiday</span><span
                                    class="stat-value"><?= $attendance_stats['holiday'] ?></span></div>
                            <div class="stat-row"><span class="stat-label">Day Off (Rest Day)</span><span
                                    class="stat-value"><?= $attendance_stats['day_off'] ?></span></div>
                            <div class="stat-row"><span class="stat-label">No record yet</span><span
                                    class="stat-value"><?= $no_record_today ?></span></div>
                        </div>
                        <div class="panel">
                            <h3>Payroll Overview</h3>
                            <?php if ($pending_payroll_approvals_count > 0): ?>
                                <div
                                    style="background:var(--mustard-pale,var(--mustard-pale)); color:var(--mustard-text2, #7A5A1E); border:1px solid var(--mustard,var(--mustard)); border-radius:10px; padding:10px 14px; margin-bottom:12px; font-size:13px; font-weight:600;">
                                    ⏳ <?= $pending_payroll_approvals_count ?> payroll
                                    batch<?= $pending_payroll_approvals_count > 1 ? 'es' : '' ?> awaiting Finance/Admin
                                    approval.
                                </div>
                            <?php endif; ?>
                            <?php if ($latest_period): ?>
                                <div class="stat-row">
                                    <span class="stat-label">Latest Period</span>
                                    <span class="stat-value" style="font-weight:600;">
                                        <?= clean(date('M d', strtotime($latest_period['pay_period_start']))) ?>–<?= clean(date('M d, Y', strtotime($latest_period['pay_period_end']))) ?>
                                    </span>
                                </div>
                                <?php if (!empty($latest_period['payout_date'])): ?>
                                    <div class="stat-row"><span class="stat-label">Payout Date</span><span
                                            class="stat-value"><?= clean(date('M d, Y', strtotime($latest_period['payout_date']))) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="stat-row"><span class="stat-label">Employees Paid</span><span
                                        class="stat-value"><?= (int) $payroll_summary['paid_count'] ?></span></div>
                                <div class="stat-row"><span class="stat-label">Total Gross Pay</span><span
                                        class="stat-value"><?= peso($payroll_summary['total_gross'] ?? 0) ?></span></div>
                                <div class="stat-row"><span class="stat-label">Total Net Pay</span><span
                                        class="stat-value"><?= peso($payroll_summary['total_net'] ?? 0) ?></span></div>
                                <div class="stat-row"><span class="stat-label">Not Yet Paid This Period</span><span
                                        class="stat-value"><?= $employees_pending_payroll ?></span></div>
                            <?php else: ?>
                                <div class="empty-state">No payroll records have been generated yet.</div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>

                <!-- ============================================= -->
                <!-- PENDING LEAVE REQUESTS                         -->
                <!-- ============================================= -->
                <div class="section" id="leave-pending">
                    <div class="section-header">
                        <h2>Pending Leave Requests <span
                                style="color:var(--ink-soft); font-weight:500; font-size:14px;">—
                                <?= clean(date('M d, Y', strtotime($leave_pending_date))) ?><?= $is_today_leave_pending ? ' (Today)' : '' ?></span>
                        </h2>
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <a href="?leave_pending_date=<?= $prev_leave_pending_date ?>#leave-pending"
                                class="btn btn-secondary btn-sm">◀ Prev Day</a>
                            <form method="GET" style="display:inline-flex;">
                                <input type="date" name="leave_pending_date" value="<?= clean($leave_pending_date) ?>"
                                    max="<?= date('Y-m-d') ?>" onchange="this.form.submit()"
                                    style="padding:8px 10px; border:1.5px solid var(--line); border-radius:10px; font-size:13px; font-family:var(--font-body);">
                            </form>
                            <?php if (!$is_today_leave_pending): ?>
                                <a href="?leave_pending_date=<?= $next_leave_pending_date ?>#leave-pending"
                                    class="btn btn-secondary btn-sm">Next Day ▶</a>
                                <a href="?leave_pending_date=<?= date('Y-m-d') ?>#leave-pending"
                                    class="btn btn-primary btn-sm">Today</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="table-card">
                        <div class="table-scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Type</th>
                                        <th>Dates</th>
                                        <th>Days</th>
                                        <th>Reason</th>
                                        <th>Requested</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($pending_leaves)): ?>
                                        <tr>
                                            <td colspan="7">
                                                <div class="empty-state">There are no pending leave requests for this date.
                                                    (<?= clean(date('M d, Y', strtotime($leave_pending_date))) ?>).</div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($pending_leaves as $lv): ?>
                                            <tr>
                                                <td><?= clean($lv['last_name'] . ', ' . $lv['first_name']) ?><br><span
                                                        style="color:var(--ink-soft); font-size:12px;"><?= clean($lv['employee_code']) ?></span>
                                                </td>
                                                <td><?= clean(getLeaveTypeLabel($lv['leave_type'])) ?></td>
                                                <td><?= clean(date('M d', strtotime($lv['date_from']))) ?>–<?= clean(date('M d, Y', strtotime($lv['date_to']))) ?>
                                                </td>
                                                <td><?= daysBetween($lv['date_from'], $lv['date_to']) ?></td>
                                                <td style="max-width:220px;"><?= clean($lv['reason'] ?: '—') ?></td>
                                                <td><?= clean(date('M d, Y', strtotime($lv['requested_at']))) ?></td>
                                                <td class="actions-cell">
                                                    <form method="POST" style="display:inline;">
                                                        <?php csrfField(); ?>
                                                        <input type="hidden" name="action" value="approve_leave">
                                                        <input type="hidden" name="leave_id" value="<?= $lv['leave_id'] ?>">
                                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                                    </form>
                                                    <form method="POST" style="display:inline;"
                                                        data-confirm="Reject this leave request?"
                                                        data-confirm-title="Reject Leave" data-confirm-danger="1"
                                                        data-confirm-ok="Yes, reject">
                                                        <?php csrfField(); ?>
                                                        <input type="hidden" name="action" value="reject_leave">
                                                        <input type="hidden" name="leave_id" value="<?= $lv['leave_id'] ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ============================================= -->
                <!-- RECENT LEAVE DECISIONS (audit — who approved what) -->
                <!-- ============================================= -->
                <div class="section" id="leave-decisions">
                    <div class="section-header">
                        <h2>Recent Leave Decisions <span
                                style="color:var(--ink-soft); font-weight:500; font-size:14px;">—
                                <?= clean(date('M d, Y', strtotime($leave_decisions_date))) ?><?= $is_today_leave_decisions ? ' (Today)' : '' ?></span>
                        </h2>
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <a href="?leave_decisions_date=<?= $prev_leave_decisions_date ?>#leave-decisions"
                                class="btn btn-secondary btn-sm">◀ Prev Day</a>
                            <form method="GET" style="display:inline-flex;">
                                <input type="date" name="leave_decisions_date"
                                    value="<?= clean($leave_decisions_date) ?>" max="<?= date('Y-m-d') ?>"
                                    onchange="this.form.submit()"
                                    style="padding:8px 10px; border:1.5px solid var(--line); border-radius:10px; font-size:13px; font-family:var(--font-body);">
                            </form>
                            <?php if (!$is_today_leave_decisions): ?>
                                <a href="?leave_decisions_date=<?= $next_leave_decisions_date ?>#leave-decisions"
                                    class="btn btn-secondary btn-sm">Next Day ▶</a>
                                <a href="?leave_decisions_date=<?= date('Y-m-d') ?>#leave-decisions"
                                    class="btn btn-primary btn-sm">Today</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="table-card">
                        <div class="table-scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Type</th>
                                        <th>Dates</th>
                                        <th>Status</th>
                                        <th>Decided On</th>
                                        <th>Decided By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_leave_decisions)): ?>
                                        <tr>
                                            <td colspan="6">
                                                <div class="empty-state">There are no processed leave requests for this
                                                    date. (<?= clean(date('M d, Y', strtotime($leave_decisions_date))) ?>).
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_leave_decisions as $lv): ?>
                                            <tr>
                                                <td><?= clean($lv['last_name'] . ', ' . $lv['first_name']) ?></td>
                                                <td><?= clean(getLeaveTypeLabel($lv['leave_type'])) ?></td>
                                                <td><?= clean(date('M d', strtotime($lv['date_from']))) ?>–<?= clean(date('M d, Y', strtotime($lv['date_to']))) ?>
                                                </td>
                                                <td>
                                                    <span class="badge"
                                                        style="background:<?= $lv['status'] == 'approved' ? 'var(--green-pale)' : 'var(--red-pale)' ?>; color:<?= $lv['status'] == 'approved' ? 'var(--text-accent)' : 'var(--red-alert)' ?>;">
                                                        <?= ucfirst($lv['status']) ?>
                                                    </span>
                                                </td>
                                                <td><?= !empty($lv['decided_at']) ? clean(date('M d, Y g:i A', strtotime($lv['decided_at']))) : '—' ?>
                                                </td>
                                                <td><?= clean($lv['approver_name'] ?: '—') ?><?= $lv['approver_role'] ? ' (' . clean(roleLabel($lv['approver_role'])) . ')' : '' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ============================================= -->
                <!-- USER ACCOUNTS                                  -->
                <!-- ============================================= -->
                <div class="section" id="user-accounts">
                    <div class="section-header">
                        <h2>User Accounts</h2>
                        <a href="?action=add_user" class="btn btn-primary">+ Add User Account</a>
                    </div>
                    <div class="table-card">
                        <table>
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Linked Employee</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">No user accounts.</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $u):
                                        $badge = getRoleBadge($u['role']);
                                        $isLocked = isset($posLockedUsernames[$u['username']]); ?>
                                        <tr>
                                            <td>
                                                <?= clean($u['username']) ?>
                                                <?php if ($isLocked): ?>
                                                    <span class="badge badge-locked"
                                                        title="Locked out on the POS terminal after too many failed login attempts. Resetting the password below will unlock it.">🔒
                                                        Locked</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= clean($u['full_name']) ?></td>
                                            <td><?= $u['employee_id'] ? clean($u['last_name'] . ', ' . $u['first_name'] . ' (' . $u['employee_code'] . ')') : '—' ?>
                                            </td>
                                            <td><span class="badge"
                                                    style="background:<?= $badge['bg'] ?>; color:<?= $badge['color'] ?>;"><?= $badge['label'] ?></span>
                                            </td>
                                            <td>
                                                <span class="badge"
                                                    style="background:<?= $u['is_active'] ? 'var(--green-pale)' : 'var(--red-pale)' ?>; color:<?= $u['is_active'] ? 'var(--text-accent)' : 'var(--red-alert)' ?>;">
                                                    <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </td>
                                            <td class="actions-cell">
                                                <div class="actions-group">
                                                    <button type="button" class="btn btn-secondary btn-sm"
                                                        onclick="openEditModal(<?= $u['id'] ?>, '<?= $u['employee_id'] ?: '' ?>', '<?= $u['employee_id'] ? clean(addslashes($u['last_name'] . ', ' . $u['first_name'] . ' (' . $u['employee_code'] . ')')) : '' ?>', '<?= clean(addslashes($u['username'])) ?>', '<?= clean(addslashes($u['full_name'])) ?>', '<?= $u['role'] ?>')">Edit</button>
                                                    <button type="button"
                                                        class="btn btn-sm <?= $isLocked ? 'btn-primary' : 'btn-secondary' ?>"
                                                        title="<?= $isLocked ? 'Reset password and unlock account' : 'Reset password' ?>"
                                                        onclick="openResetModal(<?= $u['id'] ?>, '<?= clean(addslashes($u['username'])) ?>')">Reset</button>

                                                    <form method="POST">
                                                        <?php csrfField(); ?>
                                                        <input type="hidden" name="action" value="force_logout">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <button type="submit" class="btn btn-secondary btn-sm">Force
                                                            Logout</button>
                                                    </form>
                                                </div>

                                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                                    <div class="actions-group actions-group--danger">
                                                        <form method="POST"
                                                            data-confirm="<?= $u['is_active'] ? 'deactivate' : 'activate' ?> this Account?"
                                                            data-confirm-title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> Account"
                                                            data-confirm-ok="Yes, proceed">
                                                            <?php csrfField(); ?>
                                                            <input type="hidden" name="action" value="toggle_active">
                                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                            <button type="submit"
                                                                class="btn <?= $u['is_active'] ? 'btn-danger' : 'btn-success' ?> btn-sm"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                                                        </form>
                                                        <form method="POST"
                                                            data-confirm="This account will be permanently deleted. This action cannot be undone."
                                                            data-confirm-title="Delete Account" data-confirm-danger="1"
                                                            data-confirm-ok="Yes, delete">
                                                            <?php csrfField(); ?>
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ============================================= -->
                <!-- RECENT ACTIVITY LOG                            -->
                <!-- ============================================= -->
                <div class="section" id="payroll-activity">
                    <div class="section-header">
                        <h2>Recent Payroll Activity <span
                                style="color:var(--ink-soft); font-weight:500; font-size:14px;">—
                                <?= clean(date('M d, Y', strtotime($activity_date))) ?><?= $is_today_activity ? ' (Today)' : '' ?></span>
                        </h2>
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <a href="?activity_date=<?= $prev_activity_date ?>#payroll-activity"
                                class="btn btn-secondary btn-sm">◀ Prev Day</a>
                            <form method="GET" style="display:inline-flex;">
                                <input type="date" name="activity_date" value="<?= clean($activity_date) ?>"
                                    max="<?= date('Y-m-d') ?>" onchange="this.form.submit()"
                                    style="padding:8px 10px; border:1.5px solid var(--line); border-radius:10px; font-size:13px; font-family:var(--font-body);">
                            </form>
                            <?php if (!$is_today_activity): ?>
                                <a href="?activity_date=<?= $next_activity_date ?>#payroll-activity"
                                    class="btn btn-secondary btn-sm">Next Day ▶</a>
                                <a href="?activity_date=<?= date('Y-m-d') ?>#payroll-activity"
                                    class="btn btn-primary btn-sm">Today</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="table-card">
                        <div class="table-scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Action</th>
                                        <th>Performed By</th>
                                        <th>Notes</th>
                                        <th>Date/Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($activity_log)): ?>
                                        <tr>
                                            <td colspan="4">
                                                <div class="empty-state">No activity was recorded on this date.
                                                    (<?= clean(date('M d, Y', strtotime($activity_date))) ?>).</div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($activity_log as $log): ?>
                                            <tr>
                                                <td><?= clean(ucfirst($log['action'])) ?></td>
                                                <td><?= clean($log['performed_by_name'] ?: '—') ?></td>
                                                <td><?= clean($log['notes'] ?: '—') ?></td>
                                                <td><?= clean(date('M d, Y g:i A', strtotime($log['logged_at']))) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
            <footer class="dashboard-footer">
                <span>Administration workspace · <?= clean(date('F j, Y')) ?></span>
                <a href="privacy_requests.php">Review privacy requests</a>
            </footer>

        </div>
    </div>

    <?php if ($show_add_user_form): ?>
        <div class="modal-overlay">
            <div class="modal-card">
                <h2>Add User Account</h2>
                <form method="POST">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="add_user">
                    <div class="form-grid">
                        <div class="field-group full">
                            <label>Link to Employee *</label>
                            <select name="employee_id" id="addEmployeeSelect" required
                                onchange="fillFullNameFromEmployee(this, 'addFullName')">
                                <option value="">— Choose Employee —</option>
                                <?php foreach ($available_employees as $emp): ?>
                                    <option value="<?= $emp['employee_id'] ?>"
                                        data-fullname="<?= clean($emp['first_name'] . ' ' . $emp['last_name']) ?>">
                                        <?= clean($emp['last_name'] . ', ' . $emp['first_name'] . ' (' . $emp['employee_code'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint">Applicants who are not listed here are either not yet hired/confirmed, already
                                have an account, or have resigned/been terminated. Therefore, they cannot be assigned an
                                account.</div>
                        </div>
                        <div class="field-group full">
                            <label>Full Name</label>
                            <input type="text" id="addFullName" readonly
                                placeholder="Automatically displayed based on the selected employee">
                        </div>
                        <div class="field-group"><label>Username *</label><input type="text" name="username" required></div>
                        <div class="field-group">
                            <label>Role *</label>
                            <select name="role" required>
                                <option value="employee">Employee</option>
                                <option value="supervisor">Supervisor</option>
                                <option value="accountant">Accountant</option>
                                <option value="hr">HR Staff</option>
                                <option value="hr_manager">HR Manager</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="field-group full">
                            <label>Password *</label>
                            <div class="password-wrap">
                                <input type="password" name="password" id="addPasswordInput" minlength="6" required>
                                <button type="button" class="password-toggle-btn"
                                    onclick="togglePasswordVisibility('addPasswordInput', this)"
                                    aria-label="Show/Hide password">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                            <div class="hint">At least 6 characters. Notify the user.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="admin_dashboard.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Create Account</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Edit User Modal (hidden by default, opened via JS) -->
    <div class="modal-overlay" id="editModal" style="display:none;">
        <div class="modal-card">
            <h2>Edit User Account</h2>
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="form-grid">
                    <div class="field-group full">
                        <label>Link to Employee (optional)</label>
                        <select name="employee_id" id="editEmployeeSelect"></select>
                    </div>
                    <div class="field-group full"><label>Full Name *</label><input type="text" name="full_name"
                            id="editFullName" required></div>
                    <div class="field-group"><label>Username *</label><input type="text" name="username"
                            id="editUsername" required></div>
                    <div class="field-group">
                        <label>Role *</label>
                        <select name="role" id="editRole" required>
                            <option value="employee">Employee</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="accountant">Accountant</option>
                            <option value="hr">HR Staff</option>
                            <option value="hr_manager">HR Manager</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="field-group" style="margin-top:14px;">
                    <div class="hint">Use the "Reset" button if you want to change the password. Only the name,
                        username, role, and linked employee can be modified here.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal (hidden by default, opened via JS) -->
    <div class="modal-overlay" id="resetModal" style="display:none;">
        <div class="modal-card">
            <h2>Reset Password — <span id="resetUsername"></span></h2>
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="reset_user_password">
                <input type="hidden" name="user_id" id="resetUserId">
                <div class="field-group">
                    <label>New Password *</label>
                    <div class="password-wrap">
                        <input type="password" name="new_password" id="resetPasswordInput" minlength="8"
                            pattern="(?=.*[A-Za-z])(?=.*\d).{8,}"
                            title="Use at least 8 characters, including a letter and a number."
                            autocomplete="new-password" required>
                        <button type="button" class="password-toggle-btn"
                            onclick="togglePasswordVisibility('resetPasswordInput', this)"
                            aria-label="Show/Hide password">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                    <div class="hint">Use at least 8 characters, including a letter and a number. The user will be
                        forcefully logged out after the password
                        reset. If this account is locked out on the POS terminal after too many failed attempts, this
                        also unlocks it.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Reset Password</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Show/Hide p        assword toggle (Add User + Reset Password modals)
        const EYE_OPEN_ICON = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
        const EYE_CLOSED_ICON = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.4 10.4 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';

        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.querySelector('svg').innerHTML = isHidden ? EYE_CLOSED_ICON : EYE_OPEN_ICON;
        }

        // Employees who don't have a linked user account yet (used in the Add + Edit modals)
        const unlinkedEmployees = <?= json_encode($available_employees) ?>;

        function fillFullNameFromEmployee(selectEl, targetInputId) {
            const opt = selectEl.options[selectEl.selectedIndex];
            document.getElementById(targetInputId).value = opt && opt.dataset.fullname ? opt.dataset.fullname : '';
        }

        function buildEmployeeOptions(selectedId, selectedLabel) {
            let list = unlinkedEmployees.map(e => ({
                employee_id: e.employee_id,
                label: e.last_name + ', ' + e.first_name + ' (' + e.employee_code + ')'
            }));

            // include the currently linked employee of the user being edited (even if already "taken")
            if (selectedId && !list.find(e => String(e.employee_id) === String(selectedId))) {
                list.push({ employee_id: selectedId, label: selectedLabel });
            }

            let html = '<option value="">— No linked employee (e.g. admin account) —</option>';
            list.forEach(e => {
                const isSelected = String(e.employee_id) === String(selectedId) ? 'selected' : '';
                html += `<option value="${e.employee_id}" ${isSelected}>${e.label}</option>`;
            });

            document.getElementById('editEmployeeSelect').innerHTML = html;
        }

        function openEditModal(userId, employeeId, employeeLabel, username, fullName, role) {
            document.getElementById('editUserId').value = userId;
            document.getElementById('editUsername').value = username;
            document.getElementById('editFullName').value = fullName;
            document.getElementById('editRole').value = role;
            buildEmployeeOptions(employeeId, employeeLabel);
            document.getElementById('editModal').style.display = 'flex';
        }
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function openResetModal(userId, username) {
            document.getElementById('resetUserId').value = userId;
            document.getElementById('resetUsername').textContent = username;
            document.getElementById('resetModal').style.display = 'flex';
        }
        function closeResetModal() {
            document.getElementById('resetModal').style.display = 'none';
        }
    </script>

    <?php include 'includes/sweetalert.php'; ?>

    <?php if (!empty($success_message)): ?>
            <script>
                Swal.fire({ icon: 'success', title: <?= json_encode(strip_tags($success_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 2200, timerProgressBar: true });
            </script>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
            <script>
                Swal.fire({ icon: 'error', title: <?= json_encode(strip_tags($error_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
            </script>
    <?php endif; ?>

</body>

</html>