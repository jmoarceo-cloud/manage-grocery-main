<?php
require_once 'includes/config.php';
requireSupervisorRole(); // Admin + Supervisor only

// A Supervisor sees only their own team. Admin visiting this page can
// preview a specific Supervisor's team via ?supervisor_id=.
$supervisorEmployeeId = isSupervisorRole()
    ? (int) ($_SESSION['employee_id'] ?? 0)
    : dec_id($_GET['supervisor_id'] ?? '');

// Admin browsing without picking a specific Supervisor sees every
// Supervisor's team combined, instead of an empty dashboard.
$viewingAllTeams = isAdminRole() && $supervisorEmployeeId === 0;
$teamIds = $viewingAllTeams ? getAllSupervisedEmployeeIds($pdo) : getSupervisorTeamEmployeeIds($pdo, $supervisorEmployeeId);
$today   = date('Y-m-d');

// ---- Team roster ----
$team = [];
if (!empty($teamIds)) {
    $ph = implode(',', array_fill(0, count($teamIds), '?'));
    $stmt = $pdo->prepare("
        SELECT employee_id, employee_code, first_name, last_name, department, position, schedule_type, status
        FROM employees WHERE employee_id IN ($ph) ORDER BY last_name
    ");
    $stmt->execute($teamIds);
    $team = $stmt->fetchAll();
}
$activeTeamCount = count(array_filter($team, fn($e) => $e['status'] === 'active'));

// ---- Today's attendance among the team ----
$attToday = ['present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0, 'day_off' => 0];
if (!empty($teamIds)) {
    $ph = implode(',', array_fill(0, count($teamIds), '?'));
    $stmt = $pdo->prepare("SELECT status, COUNT(*) cnt FROM attendance WHERE date = ? AND employee_id IN ($ph) GROUP BY status");
    $stmt->execute(array_merge([$today], $teamIds));
    foreach ($stmt->fetchAll() as $row) {
        $attToday[$row['status']] = (int) $row['cnt'];
    }
}
$noRecordToday = $activeTeamCount - array_sum($attToday);
if ($noRecordToday < 0) $noRecordToday = 0;

// ---- Pending leave / OT requests for the team ----
$pendingLeaves = [];
$pendingOT = [];
if (!empty($teamIds)) {
    $ph = implode(',', array_fill(0, count($teamIds), '?'));
    $stmt = $pdo->prepare("
        SELECT lr.*, e.first_name, e.last_name, e.employee_code
        FROM leave_requests lr JOIN employees e ON e.employee_id = lr.employee_id
        WHERE lr.status = 'pending' AND lr.employee_id IN ($ph)
        ORDER BY lr.requested_at DESC
    ");
    $stmt->execute($teamIds);
    $pendingLeaves = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT ot.*, e.first_name, e.last_name, e.employee_code
        FROM overtime_requests ot JOIN employees e ON e.employee_id = ot.employee_id
        WHERE ot.status = 'pending' AND ot.employee_id IN ($ph)
        ORDER BY ot.requested_at DESC
    ");
    $stmt->execute($teamIds);
    $pendingOT = $stmt->fetchAll();
}

// ---- Pending Time In requests for the team ----
$pendingLateTimeIn = [];
if (!empty($teamIds)) {
    $ph = implode(',', array_fill(0, count($teamIds), '?'));
    $stmt = $pdo->prepare("
        SELECT r.*, e.first_name, e.last_name, e.employee_code
        FROM late_timein_requests r JOIN employees e ON e.employee_id = r.employee_id
        WHERE r.status = 'pending' AND r.employee_id IN ($ph)
        ORDER BY r.requested_at DESC
    ");
    $stmt->execute($teamIds);
    $pendingLateTimeIn = $stmt->fetchAll();
}

// ---- POS snapshot for the team (today's sales by team cashiers) ----
$posUserMap   = getPosUserIdsForEmployees($posPdo, $teamIds);
$todaySales   = 0.00;
$todayTxCount = 0;
$pendingInventoryCount = 0;
if ($posPdo && !empty($posUserMap)) {
    try {
        $ph = implode(',', array_fill(0, count($posUserMap), '?'));
        $stmt = $posPdo->prepare("
            SELECT COALESCE(SUM(total_amount),0) total, COUNT(*) cnt
            FROM sales WHERE status = 'completed' AND DATE(transaction_date) = ? AND cashier_id IN ($ph)
        ");
        $stmt->execute(array_merge([$today], array_values($posUserMap)));
        $row = $stmt->fetch();
        $todaySales   = (float) $row['total'];
        $todayTxCount = (int) $row['cnt'];
    } catch (PDOException $e) {
        error_log("Supervisor dashboard POS snapshot skipped: " . $e->getMessage());
    }
}
if ($posPdo && !empty($teamIds)) {
    try {
        $ph = implode(',', array_fill(0, count($teamIds), '?'));
        $stmt = $posPdo->prepare("SELECT COUNT(*) FROM inventory_requests WHERE status IN ('pending_validation', 'pending_finance') AND requested_by_employee_id IN ($ph)");
        $stmt->execute($teamIds);
        $pendingInventoryCount = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Supervisor dashboard inventory snapshot skipped: " . $e->getMessage());
    }
}

// ---- Live POS Registers & Lanes (per-cashier snapshot for today) ----
// "Active" is inferred from a sale rung up within the last 20 minutes —
// there's no real-time terminal/session table in the POS system, so this
// is the closest honest signal we have from the sales log itself.
$liveRegisters = [];
$activeRegisterCount = 0;
if ($posPdo && !empty($posUserMap)) {
    try {
        $ph = implode(',', array_fill(0, count($posUserMap), '?'));
        $stmt = $posPdo->prepare("
            SELECT cashier_id,
                   COUNT(*) cnt,
                   COALESCE(SUM(total_amount),0) total,
                   MIN(transaction_date) first_tx,
                   MAX(transaction_date) last_tx
            FROM sales
            WHERE DATE(transaction_date) = ? AND cashier_id IN ($ph) AND status IN ('completed','partial_refunded')
            GROUP BY cashier_id
        ");
        $stmt->execute(array_merge([$today], array_values($posUserMap)));
        $byPosUserId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byPosUserId[$row['cashier_id']] = $row;
        }

        $nowTs = time();
        foreach ($posUserMap as $employeeId => $posUserId) {
            $emp = null;
            foreach ($team as $t) { if ((int) $t['employee_id'] === (int) $employeeId) { $emp = $t; break; } }
            if (!$emp) continue;

            $row = $byPosUserId[$posUserId] ?? null;
            $cnt   = $row ? (int) $row['cnt'] : 0;
            $total = $row ? (float) $row['total'] : 0.0;
            $lastTx = $row ? strtotime($row['last_tx']) : null;
            $avgMinutes = ($row && $cnt > 1)
                ? round((strtotime($row['last_tx']) - strtotime($row['first_tx'])) / 60 / ($cnt - 1), 1)
                : null;
            $isActive = $lastTx !== null && ($nowTs - $lastTx) <= 1200; // 20 min
            if ($isActive) $activeRegisterCount++;

            $liveRegisters[] = [
                'name'        => $emp['last_name'] . ', ' . $emp['first_name'],
                'position'    => $emp['position'] ?: 'Cashier',
                'cnt'         => $cnt,
                'total'       => $total,
                'avg_minutes' => $avgMinutes,
                'is_active'   => $isActive,
            ];
        }
        usort($liveRegisters, function ($a, $b) { return $b['total'] <=> $a['total']; });
    } catch (PDOException $e) {
        error_log("Supervisor dashboard live registers snapshot skipped: " . $e->getMessage());
    }
}

// ---- Presentation-only derived values (design change; no new data) ----
// Groups the 3 existing pending-request counts into one "Approvals"
// figure for the KPI row — the individual breakdown is still shown
// in full further down in the Operational Inbox tabs.
$totalApprovals = count($pendingLeaves) + count($pendingOT) + count($pendingLateTimeIn);
$__firstName = trim(strtok(trim($_SESSION['full_name'] ?? ''), ' '));
function initialsOf($first, $last) {
    $a = mb_substr(trim((string) $first), 0, 1);
    $b = mb_substr(trim((string) $last), 0, 1);
    $i = mb_strtoupper($a . $b);
    return $i !== '' ? $i : '?';
}

$activeNav = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Supervisor Dashboard — HRMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php include 'includes/supervisor_style.php'; ?>
<style>
/* ── Dashboard redesign (visual only — same data as before) ── */
.dashboard-page.dashboard-page--supervisor .main-area > .container {
    width: 100%;
    max-width: none;
    padding: clamp(20px, 2.4vw, 38px) clamp(18px, 2.4vw, 40px) 48px;
}
.dash-hero {
    display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
}
.dash-hero__greeting h1 { font-family: var(--font-display); font-size: 21px; color: var(--text-accent); margin-bottom: 4px; }
.dash-hero__greeting p { font-size: 13px; color: var(--ink-soft); }
.dash-hero__greeting .pill-tag {
    display: inline-flex; align-items: center; gap: 6px; background: var(--green-pale); color: var(--text-accent);
    font-size: 11.5px; font-weight: 700; padding: 3px 10px; border-radius: 999px; margin-left: 8px; vertical-align: middle;
}

/* KPI cards v2 — icon chip + colored accent per category, same markup
   shape as the original .kpi-card so nothing else needs to change. */
.kpi-grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px; }
.kpi-card2 {
    background: var(--white); border: 1px solid var(--line); border-radius: 18px; padding: 20px;
    display: flex; flex-direction: column; gap: 14px;
    box-shadow: 0 1px 2px rgba(18,60,92,.04);
    transition: box-shadow .15s ease, transform .15s ease;
}
.kpi-card2:hover { box-shadow: 0 10px 24px -10px rgba(18,60,92,.22); transform: translateY(-2px); }
.kpi-card2__top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.kpi-card2__label { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); font-weight: 700; }
.kpi-icon {
    width: 38px; height: 38px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.kpi-icon svg { width: 19px; height: 19px; }
.kpi-icon--blue    { background: var(--blue-bg, #E7EEFB);    color: var(--blue-text, #2D5FC8); }
.kpi-icon--green   { background: var(--green-succ-bg, #E6F4EA); color: var(--green-succ-text, #1A7A3B); }
.kpi-icon--mustard { background: var(--mustard-pale);         color: var(--mustard-text2, #97661F); }
.kpi-icon--teal    { background: var(--green-pale);           color: var(--text-accent); }
.kpi-icon--red     { background: var(--red-pale);             color: var(--red-alert); }
.kpi-card2__value { font-family: var(--font-display); font-size: 26px; color: var(--text-accent); line-height: 1; }
.kpi-card2__sub { font-size: 12.5px; color: var(--ink-soft); }
.kpi-card2__sub a { color: var(--green-mid); font-weight: 700; }

/* Operational Inbox & Approvals — tabbed card replacing the 3-panel grid */
.inbox-card { background: var(--white); border: 1px solid var(--line); border-radius: 18px; padding: 22px 24px; margin-bottom: 28px; }
.inbox-card__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.inbox-card__head h2 { font-family: var(--font-display); font-size: 17px; color: var(--text-accent); }
.inbox-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
.inbox-tab {
    padding: 8px 14px; border-radius: 999px; font-size: 13px; font-weight: 600;
    color: var(--ink-soft); background: var(--cream); border: 1px solid var(--line); cursor: pointer;
}
.inbox-tab.is-active { background: var(--green-mid); color: #FFFFFF; border-color: var(--green-mid); }
.inbox-tab .count-dot {
    display: inline-flex; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px;
    background: rgba(255,255,255,.35); color: inherit; font-size: 11px; font-weight: 700;
    align-items: center; justify-content: center; margin-left: 6px;
}
.inbox-tab:not(.is-active) .count-dot { background: var(--line); color: var(--ink-soft); }
.inbox-panel { display: none; }
.inbox-panel.is-active { display: block; }
.inbox-empty-all {
    display: flex; flex-direction: column; align-items: center; text-align: center; gap: 10px;
    padding: 34px 20px;
}
.inbox-empty-all__icon {
    width: 54px; height: 54px; border-radius: 50%; background: var(--green-succ-bg, #E6F4EA); color: var(--green-succ-text, #1A7A3B);
    display: flex; align-items: center; justify-content: center;
}
.inbox-empty-all__icon svg { width: 26px; height: 26px; }
.inbox-empty-all h3 { font-family: var(--font-display); font-size: 15.5px; color: var(--text-accent); }
.inbox-empty-all p { font-size: 13px; color: var(--ink-soft); max-width: 360px; }

/* Operational Inbox + Live POS Registers side-by-side */
.dash-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; align-items: stretch; }
.dash-two-col .inbox-card { margin-bottom: 0; display: flex; flex-direction: column; align-self: start; }
.dash-two-col .inbox-card .inbox-panel.is-active,
.dash-two-col .inbox-card .inbox-empty-all { flex: 1; display: flex; flex-direction: column; }
.dash-two-col .inbox-card .inbox-empty-all { align-items: center; justify-content: center; }
@media (max-width: 980px) { .dash-two-col { grid-template-columns: 1fr; } }

/* Live POS Registers & Lanes */
.live-card { background: var(--white); border: 1px solid var(--line); border-radius: 18px; padding: 22px 24px; display: flex; flex-direction: column; }
.live-card__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 4px; }
.live-card__head h2 { font-family: var(--font-display); font-size: 17px; color: var(--text-accent); display: flex; align-items: center; gap: 8px; }
.live-card__head h2 svg { width: 18px; height: 18px; color: var(--green-mid); }
.live-card__sub { font-size: 12.5px; color: var(--ink-soft); margin-bottom: 16px; }
.live-badge {
    display: inline-flex; align-items: center; font-size: 12.5px; font-weight: 700; color: var(--text-accent);
    background: var(--green-pale); padding: 5px 12px; border-radius: 999px; white-space: nowrap;
}
.live-list { display: flex; flex-direction: column; gap: 10px; flex: 1; max-height: 460px; overflow-y: auto; }
.live-row { display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid var(--line); border-radius: 14px; }
.live-row__num {
    width: 32px; height: 32px; border-radius: 10px; background: var(--cream); color: var(--text-accent);
    display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 12.5px; flex-shrink: 0;
}
.live-row__mid { flex: 1; min-width: 0; }
.live-row__name-line { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.live-row__name-line strong { font-size: 13.5px; color: var(--ink); }
.live-status { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
.live-status--active { background: var(--green-succ-bg, #E6F4EA); color: var(--green-succ-text, #1A7A3B); }
.live-status--idle   { background: var(--cream); color: var(--ink-soft); }
.live-row__sub { font-size: 12px; color: var(--ink-soft); margin-top: 2px; }
.live-row__end { text-align: right; flex-shrink: 0; }
.live-row__amount { font-family: var(--font-display); font-size: 15px; color: var(--text-accent); }
.live-row__tickets { font-size: 11.5px; color: var(--ink-soft); margin-top: 2px; }
.live-empty { padding: 28px 16px; text-align: center; color: var(--ink-soft); font-size: 13px; flex: 1; display: flex; align-items: center; justify-content: center; }
.live-card__footer { margin-top: auto; padding-top: 14px; border-top: 1px solid var(--line); text-align: right; }
.live-card__footer a { color: var(--green-mid); font-weight: 700; font-size: 13px; }

/* Team Roster v2 */
.roster-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.roster-search { position: relative; min-width: 220px; flex: 1 1 240px; }
.roster-search input {
    width: 100%; padding: 9px 12px 9px 34px; border: 1px solid var(--line); border-radius: 10px;
    font-size: 13.5px; background: var(--white); color: var(--ink);
}
.roster-search svg {
    position: absolute; left: 11px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--ink-soft);
}
.roster-count { font-size: 12.5px; color: var(--ink-soft); white-space: nowrap; }
.roster-name-cell { display: flex; align-items: center; gap: 10px; }
.roster-avatar {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(150deg, var(--green-leaf), var(--green-mid)); color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-display); font-weight: 700; font-size: 12px;
}
.status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px; font-size: 12px; font-weight: 700; }
.status-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.status-pill--active   { background: var(--green-succ-bg, #E6F4EA); color: var(--green-succ-text, #1A7A3B); }
.status-pill--inactive { background: var(--red-pale); color: var(--red-alert); }
.no-match-row td { text-align: center; padding: 32px; color: var(--ink-soft); font-size: 13.5px; }

@media (max-width: 700px) { .dash-hero { flex-direction: column; align-items: flex-start; } }
</style>
<link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>">
</head>
<body class="dashboard-page dashboard-page--supervisor">
<div class="app-shell">
    <?php include 'includes/supervisor_sidebar.php'; ?>
    <div class="main-area">
        <div class="topbar">
            <div class="dash-hero">
                <div class="dash-hero__greeting">
                    <h1>Welcome back, <?= clean($__firstName ?: 'Supervisor') ?><?php if ($viewingAllTeams): ?><span class="pill-tag">All Teams</span><?php endif; ?></h1>
                    <p><?= clean(roleLabel($_SESSION['role'])) ?> · <?= clean(date('l, F j, Y')) ?></p>
                </div>
            </div>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span></div>
        </div>
        <div class="container">
            <section class="dashboard-hero" aria-labelledby="dashboard-greeting">
                <div class="dashboard-hero__copy">
                    <span class="dashboard-hero__label">Supervisor</span>
                    <h2 id="dashboard-greeting"><?= date('G') < 12 ? 'Good morning' : (date('G') < 18 ? 'Good afternoon' : 'Good evening') ?>, <?= clean($__firstName ?: 'Supervisor') ?>.</h2>
                    <p>Support your team with attendance, approvals, and store activity in one view.</p>
                </div>
                <a class="dashboard-primary-action" href="#inboxCard">Review approvals <span aria-hidden="true">→</span></a>
            </section>

            <?php if (empty($teamIds)): ?>

            <div class="note-banner">
                <?= $viewingAllTeams
                    ? 'No employees are assigned to any Supervisor yet. Ask an Admin to assign employees from <strong>Supervisor Teams</strong>.'
                    : "You don't have any team members assigned yet. Ask an Admin to assign employees to you from <strong>Supervisor Teams</strong>. Once assigned, their attendance, leave, overtime, performance, and POS activity will show up here." ?>
            </div>
            <?php endif; ?>

            <div class="kpi-grid2">
                <div class="kpi-card2">
                    <div class="kpi-card2__top">
                        <div class="kpi-card2__label">My Team</div>
                        <div class="kpi-icon kpi-icon--blue"><svg viewBox="0 0 20 20" fill="none"><circle cx="7" cy="6.5" r="2.6" stroke="currentColor" stroke-width="1.6"/><path d="M2.5 16c.7-3 2.6-4.5 4.5-4.5s3.8 1.5 4.5 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="14" cy="7" r="2.1" stroke="currentColor" stroke-width="1.5"/><path d="M12.8 11.7c1.6-.3 3 .8 3.7 3.3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></div>
                    </div>
                    <div class="kpi-card2__value"><?= $activeTeamCount ?></div>
                    <div class="kpi-card2__sub">active employees</div>
                </div>
                <div class="kpi-card2">
                    <div class="kpi-card2__top">
                        <div class="kpi-card2__label">Present Today</div>
                        <div class="kpi-icon kpi-icon--green"><svg viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="12" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 8h14" stroke="currentColor" stroke-width="1.6"/><path d="M7.3 11.3l1.4 1.4 3-3.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                    </div>
                    <div class="kpi-card2__value"><?= $attToday['present'] ?></div>
                    <div class="kpi-card2__sub"><?= $attToday['absent'] ?> absent · <?= $attToday['leave'] ?> on leave</div>
                </div>
                <div class="kpi-card2">
                    <div class="kpi-card2__top">
                        <div class="kpi-card2__label">Approvals</div>
                        <div class="kpi-icon kpi-icon--mustard"><svg viewBox="0 0 20 20" fill="none"><rect x="4" y="3" width="12" height="14" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M7.5 3V2.3a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1V3" stroke="currentColor" stroke-width="1.5"/><path d="M7 10l2 2 4-4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                    </div>
                    <div class="kpi-card2__value"><?= $totalApprovals ?></div>
                    <div class="kpi-card2__sub">leave · overtime · time in — <a href="#inboxCard">review &rarr;</a></div>
                </div>
                <div class="kpi-card2">
                    <div class="kpi-card2__top">
                        <div class="kpi-card2__label">Team Sales Today</div>
                        <div class="kpi-icon kpi-icon--teal"><svg viewBox="0 0 20 20" fill="none"><path d="M3 14l4-4 3 3 6-7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 6h4v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                    </div>
                    <div class="kpi-card2__value" style="font-size:22px;"><?= peso($todaySales) ?></div>
                    <div class="kpi-card2__sub"><?= $todayTxCount ?> transaction<?= $todayTxCount === 1 ? '' : 's' ?></div>
                </div>
                <div class="kpi-card2">
                    <div class="kpi-card2__top">
                        <div class="kpi-card2__label">Pending Inventory</div>
                        <div class="kpi-icon kpi-icon--red"><svg viewBox="0 0 20 20" fill="none"><path d="M3 6l7-3.5L17 6v8l-7 3.5L3 14V6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M3 6l7 3.5L17 6M10 9.5V17" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></div>
                    </div>
                    <div class="kpi-card2__value"><?= $pendingInventoryCount ?></div>
                    <div class="kpi-card2__sub"><a href="supervisor_inventory.php">view requests &rarr;</a></div>
                </div>
            </div>

            <div class="dash-two-col">
            <div class="inbox-card" id="inboxCard">
                <div class="inbox-card__head">
                    <h2>Operational Inbox &amp; Approvals</h2>
                </div>
                <?php if ($totalApprovals === 0): ?>
                    <div class="inbox-empty-all">
                        <div class="inbox-empty-all__icon"><svg viewBox="0 0 24 24" fill="none"><path d="M5 13l4.5 4.5L19 8" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                        <h3>All clear — nothing pending</h3>
                        <p>No leave, overtime, or time-in requests need your review right now.</p>
                    </div>
                <?php else: ?>
                    <div class="inbox-tabs" role="tablist">
                        <button type="button" class="inbox-tab is-active" data-tab="leave">Leave Requests <span class="count-dot"><?= count($pendingLeaves) ?></span></button>
                        <button type="button" class="inbox-tab" data-tab="overtime">Overtime <span class="count-dot"><?= count($pendingOT) ?></span></button>
                        <button type="button" class="inbox-tab" data-tab="timein">Time In Requests <span class="count-dot"><?= count($pendingLateTimeIn) ?></span></button>
                    </div>

                    <div class="inbox-panel is-active" data-panel="leave">
                        <?php if (empty($pendingLeaves)): ?>
                            <div class="empty-state">No pending leave requests.</div>
                        <?php else: foreach (array_slice($pendingLeaves, 0, 5) as $lv): ?>
                            <div class="stat-row">
                                <span class="stat-label"><?= clean($lv['last_name'] . ', ' . $lv['first_name']) ?> — <?= clean(ucfirst($lv['leave_type'])) ?></span>
                                <span class="stat-value"><?= clean(date('M d', strtotime($lv['date_from']))) ?>–<?= clean(date('M d', strtotime($lv['date_to']))) ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                        <div style="margin-top:14px;"><a href="supervisor_leave.php" style="color:var(--green-mid); font-weight:700; font-size:13px;">Review all leave requests &rarr;</a></div>
                    </div>

                    <div class="inbox-panel" data-panel="overtime">
                        <?php if (empty($pendingOT)): ?>
                            <div class="empty-state">No pending overtime requests.</div>
                        <?php else: foreach (array_slice($pendingOT, 0, 5) as $ot): ?>
                            <div class="stat-row">
                                <span class="stat-label"><?= clean($ot['last_name'] . ', ' . $ot['first_name']) ?> — <?= clean(date('M d', strtotime($ot['ot_date']))) ?></span>
                                <span class="stat-value"><?= clean($ot['hours_requested']) ?> hrs</span>
                            </div>
                        <?php endforeach; endif; ?>
                        <div style="margin-top:14px;"><a href="supervisor_overtime.php" style="color:var(--green-mid); font-weight:700; font-size:13px;">Review all overtime requests &rarr;</a></div>
                    </div>

                    <div class="inbox-panel" data-panel="timein">
                        <?php if (empty($pendingLateTimeIn)): ?>
                            <div class="empty-state">No pending Time In requests.</div>
                        <?php else: foreach (array_slice($pendingLateTimeIn, 0, 5) as $r): ?>
                            <div class="stat-row">
                                <span class="stat-label"><?= clean($r['last_name'] . ', ' . $r['first_name']) ?> — <?= clean(date('M d', strtotime($r['request_date']))) ?></span>
                                <span class="stat-value"><?= clean(date('h:i A', strtotime($r['requested_time']))) ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                        <div style="margin-top:14px;"><a href="supervisor_late_timein.php" style="color:var(--green-mid); font-weight:700; font-size:13px;">Review all Time In requests &rarr;</a></div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="live-card">
                <div class="live-card__head">
                    <h2><svg viewBox="0 0 20 20" fill="none"><rect x="2.5" y="4" width="15" height="10" rx="1.8" stroke="currentColor" stroke-width="1.5"/><path d="M7 17h6M10 14v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>Live POS Registers &amp; Lanes</h2>
                    <span class="live-badge"><?= $activeRegisterCount ?> / <?= count($liveRegisters) ?> Active</span>
                </div>
                <div class="live-card__sub">Today's terminal throughput for your team's cashiers</div>
                <?php if (empty($liveRegisters)): ?>
                    <div class="live-empty">No cashier accounts linked to your team yet.</div>
                <?php else: ?>
                    <div class="live-list">
                        <?php foreach ($liveRegisters as $i => $r): ?>
                        <div class="live-row">
                            <div class="live-row__num"><?= str_pad($i + 1, 2, '0', STR_PAD_LEFT) ?></div>
                            <div class="live-row__mid">
                                <div class="live-row__name-line">
                                    <strong><?= clean($r['name']) ?></strong>
                                    <span class="live-status live-status--<?= $r['is_active'] ? 'active' : 'idle' ?>"><?= $r['is_active'] ? 'Active' : 'Idle' ?></span>
                                </div>
                                <div class="live-row__sub"><?= clean($r['position']) ?> · <?= $r['avg_minutes'] !== null ? $r['avg_minutes'] . ' min avg' : ($r['cnt'] > 0 ? '—' : 'No sales yet') ?></div>
                            </div>
                            <div class="live-row__end">
                                <div class="live-row__amount"><?= peso($r['total']) ?></div>
                                <div class="live-row__tickets"><?= $r['cnt'] ?> ticket<?= $r['cnt'] === 1 ? '' : 's' ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="live-card__footer"><a href="supervisor_pos_monitor.php">Launch POS Monitor Grid &rarr;</a></div>
            </div>
            </div>

            <div class="section">
                <div class="section-header">
                    <h2>My Team Roster</h2>
                    <div class="roster-toolbar">
                        <div class="roster-search">
                            <svg viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.6"/><path d="M16 16l-3.2-3.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                            <input type="text" id="rosterSearch" placeholder="Filter by code or name...">
                        </div>
                        <span class="roster-count" id="rosterCount"><?= count($team) ?> staff member<?= count($team) === 1 ? '' : 's' ?></span>
                    </div>
                </div>
                <div class="table-card"><div class="table-scroll">
                    <table id="rosterTable">
                        <thead><tr><th>Code</th><th>Name</th><th>Department</th><th>Position</th><th>Schedule</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if (empty($team)): ?>
                            <tr><td colspan="6"><div class="empty-state">No team members assigned yet.</div></td></tr>
                        <?php else: foreach ($team as $t): ?>
                            <tr data-search="<?= clean(mb_strtolower($t['employee_code'] . ' ' . $t['first_name'] . ' ' . $t['last_name'])) ?>">
                                <td><?= clean($t['employee_code']) ?></td>
                                <td>
                                    <div class="roster-name-cell">
                                        <span class="roster-avatar"><?= clean(initialsOf($t['first_name'], $t['last_name'])) ?></span>
                                        <span><?= clean($t['last_name'] . ', ' . $t['first_name']) ?></span>
                                    </div>
                                </td>
                                <td><?= clean($t['department'] ?: '—') ?></td>
                                <td><?= clean($t['position'] ?: '—') ?></td>
                                <td><?= clean(ucwords(str_replace('_',' ', $t['schedule_type']))) ?></td>
                                <td><span class="status-pill status-pill--<?= $t['status']==='active' ? 'active' : 'inactive' ?>"><span class="dot"></span><?= clean(ucfirst($t['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div></div>
            </div>

        </div>
                <footer class="dashboard-footer">
                    <span>Team workspace · <?= clean(date('F j, Y')) ?></span>
                    <a href="#inboxCard">Review approvals</a>
                </footer>
            </div>
        </div>
<script>
// Operational Inbox tabs (pure UI — same server-rendered data underneath)
(function () {
    var tabs = document.querySelectorAll('.inbox-tab');
    var panels = document.querySelectorAll('.inbox-panel');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('is-active'); });
            panels.forEach(function (p) { p.classList.remove('is-active'); });
            tab.classList.add('is-active');
            var panel = document.querySelector('.inbox-panel[data-panel="' + tab.getAttribute('data-tab') + '"]');
            if (panel) panel.classList.add('is-active');
        });
    });
})();

// Team Roster client-side filter (filters the rows already rendered by
// PHP above — no extra request, just show/hide by code or name match)
(function () {
    var input = document.getElementById('rosterSearch');
    var rows = document.querySelectorAll('#rosterTable tbody tr[data-search]');
    var countLabel = document.getElementById('rosterCount');
    var totalCount = rows.length;
    if (!input || !rows.length) return;

    var noMatchRow = document.createElement('tr');
    noMatchRow.className = 'no-match-row';
    noMatchRow.innerHTML = '<td colspan="6">No team members match your search.</td>';
    noMatchRow.hidden = true;
    document.querySelector('#rosterTable tbody').appendChild(noMatchRow);

    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var visible = 0;
        rows.forEach(function (row) {
            var match = row.getAttribute('data-search').indexOf(q) > -1;
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        noMatchRow.hidden = visible !== 0;
        countLabel.textContent = q
            ? visible + ' of ' + totalCount + ' staff member' + (totalCount === 1 ? '' : 's')
            : totalCount + ' staff member' + (totalCount === 1 ? '' : 's');
    });
})();
</script>
<?php include 'includes/sweetalert.php'; ?>
</body>
</html>