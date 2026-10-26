<?php
require_once 'includes/config.php';
requireSupervisorRole();

$supervisorEmployeeId = isSupervisorRole() ? (int) ($_SESSION['employee_id'] ?? 0) : dec_id($_GET['supervisor_id'] ?? '');
// Admin browsing without picking a specific Supervisor sees every
// Supervisor's team combined, instead of an empty page.
$viewingAllTeams = isAdminRole() && $supervisorEmployeeId === 0;
$teamIds = $viewingAllTeams ? getAllSupervisedEmployeeIds($pdo) : getSupervisorTeamEmployeeIds($pdo, $supervisorEmployeeId);

$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) { $selectedDate = date('Y-m-d'); }

$correct_message = '';
$correct_error   = '';

// =====================================================
// CORRECT ATTENDANCE — lets a Supervisor manually fix a team
// member's record: set/adjust Time In, set/adjust Time Out (most
// commonly used to close an "unfinished" record where the employee
// forgot to Time Out), and change status. A reason is REQUIRED and
// kept as an audit trail (corrected_by / correction_reason /
// corrected_at — see ensureAttendanceCorrectionColumns() in
// includes/config.php) so HR can always see who touched a record,
// when, and why (e.g. "employee forgot to time out").
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'correct_attendance') {
    requireCSRF();

    $targetEmployeeId = (int) ($_POST['employee_id'] ?? 0);
    $targetDate       = trim($_POST['record_date'] ?? '');
    $newTimeIn        = trim($_POST['time_in'] ?? '');
    $newTimeOut       = trim($_POST['time_out'] ?? '');
    $newStatus        = trim($_POST['status'] ?? 'present');
    $reason           = trim($_POST['correction_reason'] ?? '');

    $canManage = $viewingAllTeams
        ? in_array($targetEmployeeId, $teamIds, true)
        : supervisorManagesEmployee($pdo, $supervisorEmployeeId, $targetEmployeeId);

    if (!$canManage) {
        $correct_error = "You can only correct attendance for your own team members.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        $correct_error = "Invalid record date.";
    } elseif (mb_strlen($reason) < 3) {
        $correct_error = "Please enter a reason for this correction (e.g. \"employee forgot to time out\").";
    } elseif ($newTimeIn !== '' && !isValidTime($newTimeIn)) {
        $correct_error = "Please enter a valid Time In.";
    } elseif ($newTimeOut !== '' && !isValidTime($newTimeOut)) {
        $correct_error = "Please enter a valid Time Out.";
    } elseif (!in_array($newStatus, ['present', 'absent', 'leave', 'holiday', 'day_off'], true)) {
        $correct_error = "Invalid status.";
    }
    // NOTE: no "Time Out must be later than Time In" check here on
    // purpose. Time In/Time Out are plain TIME columns (no date), so
    // for an overnight/graveyard shift (e.g. Time In 10:00 PM, Time
    // Out 6:00 AM the next day) the Time Out is numerically EARLIER
    // than the Time In — that's correct, not an error. Blocking it
    // made it impossible for a Supervisor to save a Time Out for any
    // shift crossing midnight. Same crosses-midnight handling as
    // shiftHasEnded() / computeShiftDurationHours() in config.php.
    else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO attendance (employee_id, date, time_in, time_out, status, corrected_by, correction_reason, corrected_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    time_in = VALUES(time_in),
                    time_out = VALUES(time_out),
                    status = VALUES(status),
                    corrected_by = VALUES(corrected_by),
                    correction_reason = VALUES(correction_reason),
                    corrected_at = VALUES(corrected_at)
            ");
            $stmt->execute([
                $targetEmployeeId,
                $targetDate,
                $newTimeIn !== '' ? $newTimeIn : null,
                $newTimeOut !== '' ? $newTimeOut : null,
                $newStatus,
                $_SESSION['user_id'],
                mb_substr($reason, 0, 255),
            ]);
            $correct_message = "Attendance record for " . date('M d, Y', strtotime($targetDate)) . " has been corrected.";
        } catch (PDOException $e) {
            error_log("correct_attendance error: " . $e->getMessage());
            $correct_error = "Could not save the correction. Please try again.";
        }
    }
}

// Every OPEN record (Timed In, never Timed Out) across the whole
// team, regardless of the date filter above — an employee who forgot
// to Time Out yesterday shouldn't require the Supervisor to guess
// which date to filter to; it's surfaced here immediately.
$unfinishedRecords = [];
if (!empty($teamIds)) {
    $ph2 = implode(',', array_fill(0, count($teamIds), '?'));
    $stmt = $pdo->prepare("
        SELECT a.*, e.employee_code, e.first_name, e.last_name
        FROM attendance a JOIN employees e ON e.employee_id = a.employee_id
        WHERE a.time_in IS NOT NULL AND a.time_out IS NULL AND a.date < CURDATE() AND a.employee_id IN ($ph2)
        ORDER BY a.date DESC
    ");
    $stmt->execute($teamIds);
    $unfinishedRecords = $stmt->fetchAll();
}

$records = [];
$team = [];
if (!empty($teamIds)) {
    $ph = implode(',', array_fill(0, count($teamIds), '?'));
    $stmt = $pdo->prepare("SELECT employee_id, employee_code, first_name, last_name, department, position FROM employees WHERE employee_id IN ($ph) ORDER BY last_name");
    $stmt->execute($teamIds);
    $team = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT a.*, e.employee_code, e.first_name, e.last_name, e.department, e.position
        FROM attendance a JOIN employees e ON e.employee_id = a.employee_id
        WHERE a.date = ? AND a.employee_id IN ($ph)
        ORDER BY e.last_name
    ");
    $stmt->execute(array_merge([$selectedDate], $teamIds));
    $records = $stmt->fetchAll();
}
$recordedIds = array_column($records, 'employee_id');
$noRecordEmployees = array_filter($team, fn($t) => !in_array($t['employee_id'], $recordedIds, true));

function fmtTime($t) { return $t ? date('g:i A', strtotime($t)) : '—'; }

$activeNav = 'attendance';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Team Attendance — Supervisor</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php include 'includes/supervisor_style.php'; ?>
<style>
.alert { padding: 12px 16px; border-radius: 12px; font-size: 14px; font-weight: 500; margin-bottom: 20px; }
.alert--success { background: var(--green-succ-bg, #e6f4ea); color: var(--green-succ-text, #1a7a3b); }
.alert--error   { background: var(--red-pale); color: var(--red-alert); }
.unfinished-panel { background: var(--red-pale); border: 1.5px solid var(--salmon-bg, #F3C6BA); border-radius: 16px; padding: 18px 22px; margin-bottom: 22px; }
.unfinished-panel h2 { font-family: var(--font-display); color: var(--red-alert); font-size: 16px; margin-bottom: 4px; }
.unfinished-panel .sub { font-size: 13px; color: var(--ink-soft); margin-bottom: 14px; }
.unfinished-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; background: var(--white); border-radius: 12px; padding: 12px 16px; margin-bottom: 8px; flex-wrap: wrap; }
.unfinished-row:last-child { margin-bottom: 0; }
.unfinished-row .who { font-weight: 600; color: var(--ink); font-size: 13.5px; }
.unfinished-row .meta { font-size: 12.5px; color: var(--ink-soft); }
.btn-fix { background: var(--red-alert); color: #FFFFFF; border: none; padding: 9px 16px; border-radius: 9px; font-weight: 600; font-size: 13px; cursor: pointer; }
.btn-edit { background: var(--cream); color: var(--text-accent); border: 1.5px solid var(--line); padding: 6px 12px; border-radius: 8px; font-weight: 600; font-size: 12.5px; cursor: pointer; }
.modal-overlay { position: fixed; inset: 0; background: rgba(18,60,92,.25); display: flex; align-items: center; justify-content: center; padding: 24px; z-index: 100; }
.modal-card { background: var(--white); border-radius: 20px; padding: 28px; max-width: 440px; width: 100%; max-height: 88vh; overflow-y: auto; }
.modal-card h2 { font-family: var(--font-display); color: var(--text-accent); font-size: 18px; margin-bottom: 4px; }
.modal-card .modal-sub { font-size: 13px; color: var(--ink-soft); margin-bottom: 18px; }
.modal-card .field-group { margin-bottom: 14px; }
.modal-card .field-group label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 6px; color: var(--ink); }
.modal-card .field-group input, .modal-card .field-group select, .modal-card .field-group textarea {
    width: 100%; padding: 10px 12px; border: 1.5px solid var(--line); border-radius: 10px;
    font-family: var(--font-body); font-size: 13.5px;
}
.modal-card .field-row { display: flex; gap: 12px; }
.modal-card .field-row .field-group { flex: 1; }
.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }
.btn-secondary { background: var(--cream); color: var(--ink); border: 1.5px solid var(--line); padding: 10px 18px; border-radius: 10px; font-weight: 600; font-size: 13.5px; cursor: pointer; }
</style>
</head>
<body>
<div class="app-shell">
    <?php include 'includes/supervisor_sidebar.php'; ?>
    <div class="main-area">
        <div class="topbar">
            <h1>Team Attendance<?= $viewingAllTeams ? ' — All Teams' : '' ?></h1>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span></div>
        </div>
        <div class="container">

            <?php if ($correct_message): ?><div class="alert alert--success"><?= clean($correct_message) ?></div><?php endif; ?>
            <?php if ($correct_error): ?><div class="alert alert--error"><?= clean($correct_error) ?></div><?php endif; ?>

            <?php if (!empty($unfinishedRecords)): ?>
            <div class="unfinished-panel">
                <h2>⚠️ Unfinished Attendance Records</h2>
                <div class="sub">These team members Timed In but never Timed Out. They cannot Time In again until you correct the record below.</div>
                <?php foreach ($unfinishedRecords as $u): ?>
                    <div class="unfinished-row">
                        <div>
                            <div class="who"><?= clean($u['last_name'] . ', ' . $u['first_name']) ?> (<?= clean($u['employee_code']) ?>)</div>
                            <div class="meta"><?= clean(date('M d, Y (D)', strtotime($u['date']))) ?> &middot; Timed In: <?= fmtTime($u['time_in']) ?> &middot; Time Out: — never recorded</div>
                        </div>
                        <button type="button" class="btn-fix" onclick='openCorrectModal(<?= json_encode([
                            "employee_id" => (int) $u["employee_id"],
                            "name"        => $u["last_name"] . ", " . $u["first_name"],
                            "date"        => $u["date"],
                            "time_in"     => $u["time_in"] ? substr($u["time_in"], 0, 5) : "",
                            "time_out"    => $u["time_out"] ? substr($u["time_out"], 0, 5) : "",
                            "status"      => $u["status"],
                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Fix Time Out</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="panel">
                <form method="GET" class="form-row">
                    <div class="field-group">
                        <label>Date</label>
                        <input type="date" name="date" value="<?= clean($selectedDate) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">View</button>
                </form>
            </div>

            <div class="section">
                <div class="section-header"><h2>Attendance — <?= clean(date('F d, Y', strtotime($selectedDate))) ?></h2></div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Code</th><th>Name</th><th>Position</th><th>Time In</th><th>Time Out</th><th>Late (min)</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php if (empty($teamIds)): ?>
                            <tr><td colspan="8"><div class="empty-state">No team members assigned yet.</div></td></tr>
                        <?php elseif (empty($records) && empty($noRecordEmployees)): ?>
                            <tr><td colspan="8"><div class="empty-state">No attendance data for this date.</div></td></tr>
                        <?php else:
                            foreach ($records as $r):
                                $statusColors = [
                                    'present' => ['var(--green-pale)', 'var(--text-accent)'],
                                    'absent'  => ['var(--red-pale)', 'var(--red-alert)'],
                                    'leave'   => ['var(--mustard-pale)', 'var(--mustard-text2, #7A5A1E)'],
                                    'holiday' => ['var(--blue-bg, #E6F0FB)', 'var(--blue-text, #1F4E8C)'],
                                    'day_off' => ['var(--gray-bg, #EFEDE6)', 'var(--ink-soft)'],
                                ];
                                [$bg, $fg] = $statusColors[$r['status']] ?? ['var(--gray-bg, #eee)', 'var(--gray-text, #333)'];
                        ?>
                            <tr>
                                <td><?= clean($r['employee_code']) ?></td>
                                <td><?= clean($r['last_name'] . ', ' . $r['first_name']) ?></td>
                                <td><?= clean($r['position'] ?: '—') ?></td>
                                <td><?= fmtTime($r['time_in']) ?></td>
                                <td><?= fmtTime($r['time_out']) ?><?php if (!empty($r['time_in']) && empty($r['time_out'])): ?> <span class="badge" style="background:var(--red-pale); color:var(--red-alert); font-size:10.5px;">Unfinished</span><?php endif; ?></td>
                                <td><?= (int) $r['late_minutes'] ?></td>
                                <td><span class="badge" style="background:<?= $bg ?>; color:<?= $fg ?>;"><?= clean(ucwords(str_replace('_',' ', $r['status']))) ?></span></td>
                                <td><button type="button" class="btn-edit" onclick='openCorrectModal(<?= json_encode([
                                    "employee_id" => (int) $r["employee_id"],
                                    "name"        => $r["last_name"] . ", " . $r["first_name"],
                                    "date"        => $r["date"],
                                    "time_in"     => $r["time_in"] ? substr($r["time_in"], 0, 5) : "",
                                    "time_out"    => $r["time_out"] ? substr($r["time_out"], 0, 5) : "",
                                    "status"      => $r["status"],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button></td>
                            </tr>
                        <?php endforeach;
                            foreach ($noRecordEmployees as $t): ?>
                            <tr>
                                <td><?= clean($t['employee_code']) ?></td>
                                <td><?= clean($t['last_name'] . ', ' . $t['first_name']) ?></td>
                                <td><?= clean($t['position'] ?: '—') ?></td>
                                <td colspan="3" style="color:var(--ink-soft);">No record yet</td>
                                <td><span class="badge" style="background:var(--gray-bg, #EFEDE6); color:var(--ink-soft);">No record</span></td>
                                <td><button type="button" class="btn-edit" onclick='openCorrectModal(<?= json_encode([
                                    "employee_id" => (int) $t["employee_id"],
                                    "name"        => $t["last_name"] . ", " . $t["first_name"],
                                    "date"        => $selectedDate,
                                    "time_in"     => "",
                                    "time_out"    => "",
                                    "status"      => "present",
                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Add / Edit</button></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div></div>
            </div>

        </div>
    </div>
</div>

<!-- CORRECT ATTENDANCE modal — manual Time In/Time Out edit with a
     required reason, kept as an audit trail (corrected_by /
     correction_reason / corrected_at). Used both for closing an
     "Unfinished" record (employee forgot to Time Out) and for
     general edits to a team member's attendance record. -->
<div class="modal-overlay" id="correctModal" style="display:none;">
    <div class="modal-card">
        <h2>Correct Attendance</h2>
        <div class="modal-sub" id="correctModalWho"></div>
        <form method="POST">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="correct_attendance">
            <input type="hidden" name="employee_id" id="cmEmployeeId">
            <input type="hidden" name="record_date" id="cmDate">
            <div class="field-row">
                <div class="field-group">
                    <label>Time In</label>
                    <input type="time" name="time_in" id="cmTimeIn">
                </div>
                <div class="field-group">
                    <label>Time Out</label>
                    <input type="time" name="time_out" id="cmTimeOut">
                </div>
            </div>
            <div class="field-group">
                <label>Status</label>
                <select name="status" id="cmStatus">
                    <option value="present">Present</option>
                    <option value="absent">Absent</option>
                    <option value="leave">Leave</option>
                    <option value="holiday">Holiday</option>
                    <option value="day_off">Day Off</option>
                </select>
            </div>
            <div class="field-group">
                <label>Reason for correction (required)</label>
                <textarea name="correction_reason" rows="2" required minlength="3" placeholder="e.g. Employee forgot to time out"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeCorrectModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Correction</button>
            </div>
        </form>
    </div>
</div>
<script>
function openCorrectModal(data) {
    document.getElementById('cmEmployeeId').value = data.employee_id;
    document.getElementById('cmDate').value        = data.date;
    document.getElementById('cmTimeIn').value       = data.time_in || '';
    document.getElementById('cmTimeOut').value      = data.time_out || '';
    document.getElementById('cmStatus').value       = data.status || 'present';
    document.getElementById('correctModalWho').textContent =
        data.name + ' — ' + data.date;
    document.getElementById('correctModal').style.display = 'flex';
}
function closeCorrectModal() {
    document.getElementById('correctModal').style.display = 'none';
}
</script>

<?php include 'includes/sweetalert.php'; ?>
</body>
</html>