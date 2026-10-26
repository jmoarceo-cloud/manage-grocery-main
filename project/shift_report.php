<?php
require_once 'includes/config.php';
requireShiftReportAccess();

$store = [];
try {
    $rows  = $pdo->query("SELECT setting_key, setting_value FROM store_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $store = $rows;
} catch (Exception $e) {}
$store_name    = $store['store_name']    ?? 'HCI GroceryOne';
$store_address = $store['store_address'] ?? '';
$store_phone   = $store['store_phone']   ?? '';
$store_logo    = $store['store_logo']    ?? '';

$is_admin   = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$current_id = (int) $_SESSION['user_id'];

$selected_date    = $_GET['date']       ?? date('Y-m-d');
$selected_cashier = $is_admin ? dec_id($_GET['cashier_id'] ?? '') : $current_id;

$cashiers = [];
if ($is_admin) {
    $cashiers = $pdo->query("SELECT id, full_name FROM users WHERE role = 'cashier' AND is_active = 1 ORDER BY full_name ASC")->fetchAll();
}

$where  = ["DATE(s.transaction_date) = :d"];
$params = [':d' => $selected_date];
if ($selected_cashier > 0) {
    $where[]        = "s.cashier_id = :cid";
    $params[':cid'] = $selected_cashier;
} elseif (!$is_admin) {
    $where[]        = "s.cashier_id = :cid";
    $params[':cid'] = $current_id;
}
$where_sql = implode(' AND ', $where);

// Kasama na ang 'partial_refunded' — pero ang gross_sales ay net na
// (binawasan ng na-refund na parte lang), hindi ang buong transaction
// ang na-exclude (see reports.php note para sa parehong logic).
$summary = $pdo->prepare("
    SELECT
        COUNT(*)                                            AS total_transactions,
        COALESCE(SUM(s.total_amount - COALESCE(sr.refunded_amount,0)),0) AS gross_sales,
        COALESCE(SUM(s.discount_amount),0)                  AS total_discounts,
        COALESCE(SUM(s.payment),0)                           AS total_cash_received,
        COALESCE(SUM(s.change_amount),0)                     AS total_change_given
    FROM sales s
    LEFT JOIN (SELECT sale_id, SUM(amount) AS refunded_amount FROM sale_refunds GROUP BY sale_id) sr ON sr.sale_id = s.id
    WHERE $where_sql AND s.status IN ('completed', 'partial_refunded')
");
$summary->execute($params);
$summary = $summary->fetch();

$txns = $pdo->prepare("
    SELECT s.*, u.full_name AS cashier_name
    FROM sales s JOIN users u ON u.id = s.cashier_id
    WHERE $where_sql AND s.status IN ('completed', 'partial_refunded') ORDER BY s.transaction_date DESC
");
$txns->execute($params);
$transactions = $txns->fetchAll();

$items_sold = $pdo->prepare("
    SELECT p.product_name, p.category,
           SUM(sd.quantity - COALESCE(lr.refunded_qty,0)) AS total_qty,
           SUM(sd.subtotal - COALESCE(lr.refunded_amount,0)) AS total_revenue
    FROM sales_details sd
    JOIN sales s    ON s.id  = sd.sale_id
    JOIN products p ON p.id  = sd.product_id
    LEFT JOIN (SELECT sales_detail_id, SUM(quantity) AS refunded_qty, SUM(amount) AS refunded_amount FROM sale_refunds GROUP BY sales_detail_id) lr ON lr.sales_detail_id = sd.id
    WHERE $where_sql AND s.status IN ('completed', 'partial_refunded')
    GROUP BY sd.product_id, p.product_name, p.category
    ORDER BY total_qty DESC
");
$items_sold->execute($params);
$items_sold = $items_sold->fetchAll();

$cashier_label = 'All Cashiers';
if ($selected_cashier > 0) {
    $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([$selected_cashier]);
    $row = $stmt->fetch();
    $cashier_label = $row['full_name'] ?? 'Unknown';
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="shift_report_' . $selected_date . '_' . preg_replace('/\s+/', '_', $cashier_label) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [$store_name . ' — Shift Report']);
    fputcsv($out, ['Date: ' . date('F d, Y', strtotime($selected_date))]);
    fputcsv($out, ['Cashier: ' . $cashier_label]);
    fputcsv($out, ['Generated: ' . date('F d, Y h:i A')]);
    fputcsv($out, []);
    fputcsv($out, ['SUMMARY']);
    fputcsv($out, ['Total Transactions', $summary['total_transactions']]);
    fputcsv($out, ['Gross Sales',        number_format($summary['gross_sales'],         2)]);
    fputcsv($out, ['Total Discounts',    number_format($summary['total_discounts'],     2)]);
    fputcsv($out, ['Net Sales',          number_format($summary['gross_sales'] - $summary['total_discounts'], 2)]);
    fputcsv($out, ['Cash Received',      number_format($summary['total_cash_received'], 2)]);
    fputcsv($out, []);
    fputcsv($out, ['TRANSACTION LOG']);
    fputcsv($out, ['Txn #', 'Time', 'Cashier', 'Total', 'Discount', 'Cash', 'Change']);
    foreach ($transactions as $t) {
        fputcsv($out, [
            $t['id'],
            date('h:i A', strtotime($t['transaction_date'])),
            $t['cashier_name'],
            number_format($t['total_amount'],    2),
            number_format($t['discount_amount'], 2),
            number_format($t['payment'],         2),
            number_format($t['change_amount'],   2),
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['ITEMS SOLD']);
    fputcsv($out, ['Product', 'Category', 'Qty Sold', 'Revenue']);
    foreach ($items_sold as $i) {
        fputcsv($out, [$i['product_name'], $i['category'], $i['total_qty'], number_format($i['total_revenue'], 2)]);
    }
    fclose($out);
    exit;
}

$export_params = http_build_query([
    'date'       => $selected_date,
    'cashier_id' => $selected_cashier > 0 ? enc_id($selected_cashier) : '0',
    'export'     => 'csv',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shift Report — <?= clean($store_name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --green-deep:   #123C5C;
    --green-mid:    #1978A8;
    --green-leaf:   #4FC3D1;
    --green-pale:   #E3F2F6;
    --mustard:      #E0A23B;
    --mustard-pale: #FBEFD8;
    --red-alert:    #C8472D;
    --red-pale:     #FBE7E2;
    --cream:        #F3F6F9;
    --white:        #FFFFFF;
    --ink:          #17222E;
    --ink-soft:     #3D4C5C;
    --line:         #DCE5EC;
    --font-display: 'Sora', sans-serif;
    --font-body:    'Inter', sans-serif;
    --sidebar-w:    288px;
    --sidebar-w-collapsed: 84px;
    --shadow-card:  0 1px 2px rgba(18,60,92,0.06), 0 6px 16px rgba(18,60,92,0.06);
    /* Used for headings/values/icons that need to read as "brand deep"
       on light backgrounds, but must switch to a bright color in dark
       mode instead of staying dark (or the text disappears against
       the dark card backgrounds). Light mode: same as --green-deep. */
    --text-accent:  var(--green-deep);
}

/* ── DARK MODE — this is what was missing before. The toggle button
   worked (icon/label swapped), but nothing on the page actually
   recolored because these variable overrides didn't exist yet. Since
   the whole page already uses var(--...) everywhere, redefining the
   variables here is enough to recolor sidebar, cards, tables, filters,
   everything in one shot. ── */
html[data-theme="dark"] {
    --green-deep:   #0B2131;
    --green-mid:    #1E8FC4;
    --green-leaf:   #4FC3D1;
    --green-pale:   #12303F;
    --mustard:      #E0A23B;
    --mustard-pale: #3A2E14;
    --red-alert:    #E5644A;
    --red-pale:     #3A1912;
    --cream:        #0F1720;
    --white:        #1A2530;
    --ink:          #E7EEF4;
    --ink-soft:     #94A7B8;
    --line:         #2A3846;
    --shadow-card:  0 1px 2px rgba(0,0,0,0.25), 0 6px 16px rgba(0,0,0,0.25);
    --text-accent:  var(--green-leaf);
}
html[data-theme="dark"] .sidebar {
    /* Matched to HRMS's dark-mode sidebar (hr_sidebar.php) and the
       shared style.css — same two-layer cyan + violet glow on the
       same near-black base, so the sidebar reads identically across
       every module (Cashier, Shift Report, HRMS, etc.) in dark mode. */
    background:
        radial-gradient(420px 260px at 100% 0%, rgba(85,214,255,.16), transparent 60%),
        radial-gradient(360px 320px at 0% 100%, rgba(176,108,255,.14), transparent 60%),
        #05070f;
}
/* A few spots use hardcoded hex instead of the theme variables above,
   so they need explicit dark-mode values or they'd stay light-colored
   and look broken against the new dark background. */
html[data-theme="dark"] .summary-card.txns .s-icon { background: #202B36; }
html[data-theme="dark"] .summary-card.net  .s-icon { background: #123030; }
html[data-theme="dark"] .data-table tbody tr:hover { background: #1E2A34; }
html[data-theme="dark"] .data-table tbody tr:nth-child(even) { background: #182129; }
html[data-theme="dark"] .data-table tbody tr:nth-child(even):hover { background: #1E2A34; }
/* Safety net: table values (dates, amounts, plain cell text) must
   always read against the dark card background — force it here in
   case any page's own table/td rule doesn't correctly inherit --ink. */
html[data-theme="dark"] table td,
html[data-theme="dark"] .card td { color: var(--ink); }

html, body { height: 100%; font-family: var(--font-body); color: var(--ink); background: var(--cream); transition: background-color .18s ease, color .18s ease; }
.panel, .summary-card, .filter-input, .filter-select, .data-table, .pos-topbar, .report-filters {
    transition: background-color .18s ease, color .18s ease, border-color .18s ease;
}
a { text-decoration: none; color: inherit; }

.pos-shell {
    display: grid;
    grid-template-columns: var(--sidebar-w) 1fr;
    grid-template-rows: 100vh;
    height: 100vh;
    overflow: hidden;
}

/* ── SIDEBAR — matches the shared sidebar look used everywhere else
   (dashboard, inventory, POS, etc.): navy base with a cyan + violet
   radial-gradient accent, not the plain teal --green-deep used by the
   rest of this page's cards/buttons. Scoped to .sidebar only. ── */
.sidebar {
    --green-deep: #0a1024;
    --green-mid:  #6d7cff;
    --green-leaf: #55d6ff;
    --green-pale: rgba(85,214,255,.16);

    position: relative;
    background:
        radial-gradient(420px 260px at 100% 0%, rgba(85,214,255,.18), transparent 60%),
        radial-gradient(360px 320px at 0% 100%, rgba(176,108,255,.16), transparent 60%),
        var(--green-deep);
    color: #FFFFFF;
    display: flex;
    flex-direction: column;
    padding: 30px 24px;
    overflow-y: auto;
    overflow-x: hidden;
    z-index: 2;
    box-shadow: 2px 0 12px rgba(0,0,0,0.18);
    transition: width 0.22s ease, padding 0.22s ease, box-shadow 0.22s ease;
}
.sidebar {
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.sidebar::-webkit-scrollbar { display: none; }

/* Collapse toggle — little pill button docked on the sidebar edge.
   Missing here before, so it rendered unstyled and overlapped the
   brand logo at the top of the sidebar. */
.sidebar-toggle {
    /* Fixed (not absolute) so it's always anchored to the sidebar's
       actual edge — see pos.php for the full explanation of why
       "right: -13px" here was resolving against the whole page. */
    position: fixed;
    top: 34px;
    left: calc(var(--sidebar-w) - 13px);
    width: 26px;
    height: 26px;
    border-radius: 50%;
    border: 1px solid var(--line);
    background: var(--white);
    color: var(--text-accent);
    box-shadow: var(--shadow-card);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 20;
    padding: 0;
    transition: left 0.22s ease, background 0.15s ease;
}
.pos-shell.sidebar-collapsed .sidebar-toggle { left: calc(var(--sidebar-w-collapsed) - 13px); }
.sidebar-toggle:hover { background: var(--green-pale); }
.sidebar-toggle svg { width: 13px; height: 13px; transition: transform 0.22s ease; }
.pos-shell.sidebar-collapsed .sidebar-toggle svg { transform: rotate(180deg); }

/* Collapsed state — icon-only rail */
.pos-shell.sidebar-collapsed { grid-template-columns: var(--sidebar-w-collapsed) 1fr; }
.pos-shell.sidebar-collapsed .sidebar { padding-left: 14px; padding-right: 14px; }
.pos-shell.sidebar-collapsed .brand-text,
.pos-shell.sidebar-collapsed .nav-label,
.pos-shell.sidebar-collapsed .nav-section-label,
.pos-shell.sidebar-collapsed .sidebar-foot .foot-text,
.pos-shell.sidebar-collapsed .theme-toggle-btn .tt-label {
    display: none;
}
.pos-shell.sidebar-collapsed .sidebar-brand { justify-content: center; padding-left: 0; padding-right: 0; }
.pos-shell.sidebar-collapsed .nav-link { justify-content: center; gap: 0; }
.pos-shell.sidebar-collapsed .sidebar-foot { justify-content: center; padding: 10px; }
.pos-shell.sidebar-collapsed .theme-toggle-btn { justify-content: center; }

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 0 4px 26px;
    margin-bottom: 22px;
    border-bottom: 1px solid rgba(255,255,255,0.14);
}
.brand-mark {
    width: 46px; height: 46px;
    background: var(--white);
    border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 10px rgba(0,0,0,.14);
}
.brand-icon { width: 28px; height: 28px; }
.brand-text { display: flex; flex-direction: column; line-height: 1.35; }
.brand-name    { font-family: var(--font-display); font-weight: 700; font-size: 17px; }
.brand-tagline { font-size: 11px; color: var(--green-leaf); font-weight: 600; letter-spacing: .02em; }

.sidebar-nav { display: flex; flex-direction: column; gap: 5px; }

.nav-section-label {
    font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
    color: rgba(255,255,255,.42); padding: 0 14px; margin: 4px 0 10px;
}
.nav-link {
    display: flex; align-items: center; gap: 13px;
    padding: 13px 15px;
    border-radius: 12px;
    font-size: 14.5px; font-weight: 600;
    color: rgba(255,255,255,0.82);
    transition: background .15s, color .15s;
    position: relative;
}
.nav-link:hover     { background: rgba(255,255,255,0.1); color: #FFFFFF; }
.nav-link.is-active { background: var(--green-leaf); color: var(--green-deep); box-shadow: 0 4px 12px rgba(0,0,0,.16); }
.nav-icon-box {
    width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,.08);
}
.nav-link.is-active .nav-icon-box { background: rgba(255,255,255,.35); }
.nav-icon { width: 17px; height: 17px; flex-shrink: 0; }
.nav-label { white-space: nowrap; }
.nav-link--logout {
    margin-top: 16px;
    padding-top: 18px;
    border-top: 1px solid rgba(255,255,255,0.14);
    color: rgba(255,255,255,0.65);
}
.nav-link--logout:hover { background: rgba(200,71,45,0.18); color: #ffb3a3; }
.nav-link--back {
    margin-top: 16px;
    padding-top: 18px;
    border-top: 1px solid rgba(255,255,255,0.14);
    color: rgba(255,255,255,0.65);
}
.nav-link--back:hover { background: rgba(255,255,255,0.1); color: #FFFFFF; }

.sidebar-foot {
    margin-top: auto;
    padding: 14px;
    border-radius: 14px;
    background: rgba(255,255,255,.06);
    display: flex; align-items: center; gap: 12px;
}

.sidebar-account-group {
    margin-top: auto;
    display: flex; flex-direction: column; gap: 8px;
}
.sidebar-account-group .sidebar-foot { margin-top: 0; }
.nav-link--logout-big {
    display: flex; align-items: center; gap: 13px;
    padding: 13px 15px; border-radius: 12px;
    font-size: 14.5px; font-weight: 700;
    color: rgba(255,255,255,0.82);
    background: rgba(255,255,255,.06);
    transition: background .15s ease, color .15s ease;
}
.nav-link--logout-big:hover { background: rgba(200,71,45,0.18); color: #ffb3a3; }
.nav-link--logout-big:hover .nav-icon-box { background: rgba(200,71,45,0.28); }
.nav-link--back-big {
    display: flex; align-items: center; gap: 13px;
    padding: 13px 15px; border-radius: 12px;
    font-size: 14.5px; font-weight: 700;
    color: rgba(255,255,255,0.82);
    background: rgba(255,255,255,.06);
    transition: background .15s ease, color .15s ease;
}
.nav-link--back-big:hover { background: rgba(255,255,255,0.14); color: #FFFFFF; }
.sidebar-foot .foot-avatar {
    width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(150deg, var(--green-leaf), #2E9BAE);
    color: var(--green-deep);
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-display); font-weight: 700; font-size: 15px;
}
.sidebar-foot .foot-text { display: flex; flex-direction: column; line-height: 1.3; min-width: 0; }
.sidebar-foot .foot-name { font-size: 13.5px; font-weight: 700; color: #FFFFFF; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sidebar-foot .foot-role { font-size: 11.5px; color: rgba(255,255,255,0.6); font-weight: 600; }

/* Dark mode toggle — was missing here, so both sun/moon icons showed
   at once with default browser button styling and overlapped the text. */
.theme-toggle-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 9px 12px;
    margin-top: 8px;
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 10px;
    background: rgba(255,255,255,.05);
    color: #FFFFFF;
    font-family: var(--font-body);
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
}
.theme-toggle-btn:hover { background: rgba(255,255,255,.1); }
.theme-toggle-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
.theme-toggle-btn .tt-icon-dark { display: none; }
html[data-theme="dark"] .theme-toggle-btn .tt-icon-light { display: none; }
html[data-theme="dark"] .theme-toggle-btn .tt-icon-dark { display: block; }

/* ── MAIN AREA ── */
.report-main {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--cream);
}
.pos-topbar {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 32px;
    background: var(--white);
    border-bottom: 1px solid var(--line);
    box-shadow: 0 2px 8px rgba(18,60,92,0.04);
    flex-shrink: 0;
    position: relative;
    z-index: 3;
}
.topbar-icon {
    width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
    background: var(--green-pale); color: var(--text-accent);
    display: flex; align-items: center; justify-content: center;
}
.topbar-icon svg { width: 20px; height: 20px; }
.pos-topbar h1 {
    font-family: var(--font-display);
    font-size: 20px; font-weight: 700;
    color: var(--text-accent);
    margin-right: auto;
}
.topbar-right { display: flex; align-items: center; gap: 10px; }
.cashier-name { font-size: 13px; color: var(--ink-soft); font-weight: 600; }

/* ── FILTER BAR ── */
.report-filters {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
    padding: 16px 32px;
    background: var(--white);
    border-bottom: 1px solid var(--line);
    flex-shrink: 0;
}
.filter-field { display: flex; flex-direction: column; gap: 5px; }
.filter-label { font-size: 11px; font-weight: 700; color: var(--ink-soft); text-transform: uppercase; letter-spacing: .04em; }
.filter-input, .filter-select {
    padding: 8px 12px;
    border: 1.5px solid var(--line);
    border-radius: 9px;
    font-family: var(--font-body);
    font-size: 13.5px;
    background: var(--cream);
    color: var(--ink);
    transition: border-color .15s;
}
.filter-input:focus, .filter-select:focus { outline: none; border-color: var(--green-mid); background: var(--white); }
.filter-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border: none;
    border-radius: 9px;
    background: var(--green-mid);
    color: #FFFFFF;
    font-family: var(--font-body);
    font-size: 13.5px; font-weight: 700;
    cursor: pointer;
    transition: background .15s, transform .1s;
    align-self: flex-end;
}
.filter-btn:active { transform: translateY(1px); }
.filter-btn:hover { background: var(--green-deep); }

/* ── SCROLLABLE CONTENT ── */
.report-body {
    flex: 1;
    overflow-y: auto;
    padding: 26px 32px 32px;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

/* ── HEADER ROW ── */
.report-header-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.report-title-block h2 {
    font-family: var(--font-display);
    font-size: 20px; font-weight: 800;
    color: var(--text-accent);
}
.report-title-block p { font-size: 13px; color: var(--ink-soft); margin-top: 2px; }
.report-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.btn-action {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px;
    border-radius: 9px;
    font-family: var(--font-body);
    font-size: 13px; font-weight: 700;
    cursor: pointer; border: none;
    text-decoration: none;
    transition: background .15s;
}
.btn-action.primary { background: var(--green-mid); color: #FFFFFF; }
.btn-action.primary:hover { background: var(--green-deep); }
.btn-action.outline {
    background: var(--white); color: var(--text-accent);
    border: 1.5px solid var(--text-accent);
}
.btn-action.outline:hover { background: var(--green-pale); }
.btn-action svg { width: 15px; height: 15px; }

/* ── SUMMARY CARDS ── */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(140px, 1fr));
    gap: 12px;
}
@media (max-width: 1100px) { .summary-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px)  { .summary-grid { grid-template-columns: repeat(2, 1fr); } }

@media (max-width: 900px) {
    /* Mirrors style.css's auto-shrink at this breakpoint — was missing
       here, so this page never narrowed to the icon-only rail like
       every other page, making the sidebar feel inconsistent across pages. */
    .pos-shell { grid-template-columns: var(--sidebar-w-collapsed) 1fr; }
    .pos-shell .sidebar { padding: 24px 12px; }
    .pos-shell .brand-text, .pos-shell .nav-label, .pos-shell .nav-section-label,
    .pos-shell .sidebar-foot .foot-text, .pos-shell .theme-toggle-btn .tt-label { display: none; }
    .pos-shell .sidebar-brand { justify-content: center; padding-left: 0; padding-right: 0; }
    .pos-shell .nav-link { justify-content: center; gap: 0; }
    .pos-shell .sidebar-foot { justify-content: center; padding: 10px; }
    .pos-shell .theme-toggle-btn { justify-content: center; }
}

.summary-card {
    background: var(--white);
    border: 1.5px solid var(--line);
    border-radius: 14px;
    padding: 16px 18px;
    min-width: 0;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    transition: transform .12s ease, box-shadow .15s ease, border-color .15s ease;
}
.summary-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
    background: var(--line);
}
.summary-card:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(18,60,92,0.12); border-color: transparent; }
.summary-card.txns::before  { background: var(--ink-soft); }
.summary-card.sales::before { background: var(--green-mid); }
.summary-card.disc::before  { background: var(--mustard); }
.summary-card.cash::before  { background: var(--green-deep); }
.summary-card.net::before   { background: #2C8C8C; }

.s-icon {
    width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    margin-top: 1px;
}
.s-icon svg { width: 18px; height: 18px; }
.summary-card.txns  .s-icon { background: #EFF1EE; color: var(--ink-soft); }
.summary-card.sales .s-icon { background: var(--green-pale); color: var(--green-mid); }
.summary-card.disc  .s-icon { background: var(--mustard-pale); color: #a06b10; }
.summary-card.cash  .s-icon { background: var(--green-pale); color: var(--text-accent); }
.summary-card.net   .s-icon { background: #E4F2F2; color: #2C8C8C; }

.s-text { min-width: 0; }
.s-label {
    font-size: 11px; font-weight: 700;
    color: var(--ink-soft);
    text-transform: uppercase; letter-spacing: .04em;
    white-space: nowrap;
}
.s-value {
    font-family: var(--font-display);
    font-size: 19px; font-weight: 800;
    margin-top: 5px;
    color: var(--text-accent);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.summary-card.txns  .s-value { color: var(--ink); }
.summary-card.sales .s-value { color: var(--green-mid); }
.summary-card.disc  .s-value { color: #a06b10; }
.summary-card.cash  .s-value { color: var(--text-accent); }
.summary-card.net   .s-value { color: #2C8C8C; }

/* ── PANELS ── */
.panel {
    background: var(--white);
    border: 1.5px solid var(--line);
    border-radius: 14px;
    overflow: hidden;
}
.panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid var(--line);
    background: var(--white);
}
.panel-header-title { display: flex; align-items: center; gap: 10px; }
.panel-icon {
    width: 28px; height: 28px; border-radius: 8px; flex-shrink: 0;
    background: var(--green-pale); color: var(--text-accent);
    display: flex; align-items: center; justify-content: center;
}
.panel-icon svg { width: 15px; height: 15px; }
.panel-header h3 {
    font-family: var(--font-display);
    font-size: 15px; font-weight: 700;
    color: var(--text-accent);
}
.panel-header .row-count {
    font-size: 12px;
    font-weight: 700;
    color: var(--green-mid);
    background: var(--green-pale);
    padding: 4px 10px;
    border-radius: 20px;
}

/* ── TABLE ── */
.data-table {
    width: 100%;
    min-width: 640px;
    border-collapse: collapse;
    font-size: 13.5px;
}
.data-table th {
    padding: 12px 16px;
    text-align: left;
    font-size: 11.5px; font-weight: 700;
    color: var(--ink-soft);
    text-transform: uppercase; letter-spacing: .04em;
    border-bottom: 1.5px solid var(--line);
    background: var(--cream);
    white-space: nowrap;
}
.data-table td {
    padding: 13px 16px;
    border-bottom: 1px solid var(--line);
    color: var(--ink);
    white-space: nowrap;
}
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table tbody tr:hover { background: #f1f6f2; }
.no-data {
    display: flex; flex-direction: column; align-items: center; gap: 10px;
    text-align: center; padding: 44px 20px;
    color: var(--ink-soft); font-size: 13.5px;
}
.no-data svg { width: 34px; height: 34px; color: var(--line); }

.panel-body--scrollable {
    display: block;
    height: 340px;
    max-height: 340px;
    min-height: 340px;
    overflow-y: scroll;
    overflow-x: auto;
    padding-bottom: 4px;
    scrollbar-width: thin;
    scrollbar-color: var(--green-leaf) transparent;
}
.panel-body--scrollable table { width: 100%; }
.panel-body--scrollable thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--cream);
    box-shadow: 0 1px 0 var(--line);
}
.panel-body--scrollable::-webkit-scrollbar { width: 9px; height: 9px; }
.panel-body--scrollable::-webkit-scrollbar-track { background: transparent; }
.panel-body--scrollable::-webkit-scrollbar-thumb {
    background: var(--green-leaf);
    border-radius: 20px;
    border: 2px solid var(--white);
    background-clip: padding-box;
}
.panel-body--scrollable::-webkit-scrollbar-thumb:hover { background: var(--green-mid); }

.data-table tbody tr:nth-child(even) { background: #fafaf8; }
.data-table tbody tr:nth-child(even):hover { background: #f1f6f2; }

/* ── STATUS BADGES ── */
.status-badge {
    display: inline-flex;
    align-items: center;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .03em;
    padding: 3px 8px;
    border-radius: 20px;
    margin-left: 6px;
    text-transform: uppercase;
    vertical-align: middle;
}
.status-badge.voided   { color: var(--red-alert); background: var(--red-pale); }
.status-badge.refunded { color: #8a5800; background: var(--mustard-pale); }
.status-badge.partial_refunded { color: #8a5800; background: var(--mustard-pale); }

/* ── PRINT ── */
@media print {
    .sidebar, .report-filters, .report-actions, .btn-action { display: none !important; }
    .pos-shell  { display: block !important; height: auto !important; }
    .report-main { overflow: visible !important; }
    .report-body { overflow: visible !important; padding: 0 !important; gap: 16px !important; }
    .pos-topbar { border: none; padding: 0 0 12px 0; }
    body { background: white; }
    .panel { border: 1px solid #ccc; break-inside: avoid; }
    .print-meta { display: block !important; }

    /* Show every row instead of the fixed-height scroll window */
    .panel-body--scrollable {
        height: auto !important;
        max-height: none !important;
        min-height: 0 !important;
        overflow: visible !important;
    }
    .panel-body--scrollable thead th { position: static !important; box-shadow: none !important; }
    .data-table { break-inside: auto; }
    .data-table tr { break-inside: avoid; }
}
.print-meta {
    display: none;
    font-size: 12px; color: #666;
    margin-bottom: 8px;
}

/* ============================================================
   Restyled SweetAlert2 popup so confirms on this page look like
   part of the app instead of the large, generic CDN default —
   same restyle already applied via style.css / includes/sweetalert.php
   on every other page. !important since SweetAlert injects its own
   <style> tag, which can otherwise load after this one.
   ============================================================ */
.swal2-popup:not(.swal2-toast) {
    font-family: var(--font-body) !important;
    border-radius: var(--radius-lg, 16px) !important;
    width: auto !important;
    max-width: 360px !important;
    padding: 26px 22px 22px !important;
    background: var(--white) !important;
    box-shadow: var(--shadow-card) !important;
}
.swal2-popup:not(.swal2-toast) .swal2-icon { width: 50px !important; height: 50px !important; margin: 4px auto 10px !important; }
.swal2-popup:not(.swal2-toast) .swal2-title {
    font-family: var(--font-display) !important;
    font-size: 18px !important;
    font-weight: 700 !important;
    color: var(--ink) !important;
    padding: 0 !important;
    margin-bottom: 4px !important;
}
.swal2-popup:not(.swal2-toast) .swal2-html-container {
    font-size: 13.5px !important;
    line-height: 1.5 !important;
    color: var(--ink-soft) !important;
    margin: 6px 2px 0 !important;
}
.swal2-popup:not(.swal2-toast) .swal2-actions { margin-top: 20px !important; gap: 8px !important; }
.swal2-styled.swal2-confirm,
.swal2-styled.swal2-cancel {
    border-radius: 10px !important;
    font-family: var(--font-body) !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    padding: 9px 16px !important;
    box-shadow: none !important;
}

/* Keep Shift Report identical to the shared Dashboard role rail. */
.pos-shell {
    --sidebar-w: 248px;
    grid-template-columns: var(--sidebar-w) 1fr;
}
.pos-shell .sidebar {
    width: var(--sidebar-w);
    padding: 24px 16px 18px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    color: #1e293b;
    border-right: 1px solid #e2e8f0;
    box-shadow: 8px 0 26px rgba(15,23,42,.06);
}
.pos-shell .sidebar-brand {
    gap: 12px;
    padding: 0 8px 22px;
    margin-bottom: 20px;
    border-bottom-color: #e2e8f0;
}
.pos-shell .brand-mark {
    width: 44px;
    height: 44px;
    border-radius: 14px;
    background: linear-gradient(135deg, #1d4ed8, #0284c7);
    border: 1px solid rgba(96,165,250,.3);
    box-shadow: 0 10px 24px rgba(37,99,235,.18);
    transition: transform .24s cubic-bezier(.2,.8,.2,1), box-shadow .24s ease, filter .24s ease;
}
.pos-shell .sidebar-brand:hover .brand-mark {
    transform: translateY(-3px) rotate(-3deg) scale(1.05);
    filter: saturate(1.08);
    box-shadow: 0 14px 28px rgba(37,99,235,.22);
}
.pos-shell .brand-name { color: #0f172a; font-size: 16px; }
.pos-shell .brand-name span { color: #2563eb; }
.pos-shell .brand-tagline,
.pos-shell .nav-section-label { color: #64748b; }
.pos-shell .nav-link {
    min-height: 44px;
    padding: 8px 11px;
    border: 1px solid transparent;
    border-radius: 13px;
    color: #334155;
    transition: transform .2s ease, background .2s ease, color .2s ease, border-color .2s ease, box-shadow .2s ease;
}
.pos-shell .nav-icon-box {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    color: #2563eb;
    background: #eff6ff;
    border: 1px solid #dbeafe;
    transition: background .2s ease, color .2s ease, transform .2s ease;
}
.pos-shell .nav-link:hover {
    color: #1d4ed8;
    background: #f8fbff;
    border-color: #dbeafe;
    transform: translateX(3px);
}
.pos-shell .nav-link:hover .nav-icon-box { color: #1d4ed8; background: #dbeafe; transform: scale(1.05); }
.pos-shell .nav-link.is-active {
    color: #fff;
    background: linear-gradient(100deg, #2563eb, #38bdf8);
    border-color: rgba(96,165,250,.7);
    box-shadow: 0 9px 22px rgba(37,99,235,.2);
    transform: translateX(2px);
}
.pos-shell .nav-link.is-active .nav-icon-box { color: #1d4ed8; background: rgba(255,255,255,.82); border-color: rgba(255,255,255,.55); }
.pos-shell .sidebar-foot { padding: 10px; border: 1px solid #e2e8f0; border-radius: 14px; background: #f8fafc; }
.pos-shell .sidebar-foot .foot-name { color: #0f172a; font-size: 12.5px; }
.pos-shell .sidebar-foot .foot-role { color: #64748b; font-size: 10.5px; }
.pos-shell .theme-toggle-btn { border-color: #dbe3ef; border-radius: 11px; background: #fff; color: #334155; }
.pos-shell.sidebar-collapsed { grid-template-columns: var(--sidebar-w-collapsed) 1fr; }
.pos-shell.sidebar-collapsed .sidebar { width: var(--sidebar-w-collapsed); }
html[data-theme="dark"] .pos-shell .sidebar {
    background: linear-gradient(180deg,#0b1530 0%,#080f24 58%,#070c1b 100%);
    color: #e8efff;
    border-right-color: rgba(126,155,255,.16);
}
html[data-theme="dark"] .pos-shell .brand-mark {
    background: linear-gradient(135deg, #2563eb, #0284c7);
}
html[data-theme="dark"] .pos-shell .brand-name { color: #e8efff; }
html[data-theme="dark"] .pos-shell .brand-name span { color: #55d6ff; }
html[data-theme="dark"] .pos-shell .nav-link { color: #91a0bd; }
html[data-theme="dark"] .pos-shell .nav-icon-box { color: #91a9e7; background: rgba(125,151,220,.1); border-color: rgba(151,174,242,.1); }
.swal2-styled.swal2-cancel {
    background: var(--cream) !important;
    color: var(--ink-soft) !important;
}
.swal2-styled:focus { outline: none !important; box-shadow: 0 0 0 3px rgba(0,0,0,.08) !important; }
.swal2-textarea {
    font-family: var(--font-body) !important;
    border-radius: 10px !important;
    border: 1.5px solid var(--line) !important;
}

/* Toasts (e.g. flash success/error banners) use SweetAlert2's own compact
   row layout, which the confirm-dialog rules above were clobbering
   (centered 50px icon, 26px padding, 360px width) since both share the
   .swal2-popup class. Scoped out above with :not(.swal2-toast); here we
   just brand the toast's font/corners/shadow, keeping its layout. */
.swal2-popup.swal2-toast {
    font-family: var(--font-body) !important;
    border-radius: 10px !important;
    box-shadow: var(--shadow-card) !important;
}
.swal2-popup.swal2-toast .swal2-title {
    font-family: var(--font-body) !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    color: var(--ink) !important;
}
.swal2-popup.swal2-toast .swal2-html-container {
    color: var(--ink-soft) !important;
}
/* Keep Shift Report aligned with the shared catalog-inspired sidebar. */
.sidebar { background:#fff; color:#1e293b; border-right:1px solid #e2e8f0; box-shadow:8px 0 26px rgba(15,23,42,.06); }
.sidebar-brand { border-bottom-color:#e2e8f0; }
.brand-name { color:#0f172a; }
.brand-tagline, .nav-section-label { color:#64748b; }
.nav-link { color:#334155; }
.nav-icon-box { color:#2563eb; background:#eff6ff; }
.nav-link:hover { color:#1d4ed8; background:#f8fbff; }
.nav-link.is-active { color:#fff !important; background:linear-gradient(100deg,#2563eb,#38bdf8) !important; box-shadow:0 10px 22px rgba(37,99,235,.22); }
.nav-link.is-active .nav-icon-box { color:#1d4ed8; background:rgba(255,255,255,.82); }
.sidebar-foot { background:#f8fafc; border-color:#e2e8f0; }
html[data-theme="dark"] .sidebar { background:linear-gradient(180deg,#0b1530 0%,#080f24 58%,#070c1b 100%); color:#e8efff; border-right-color:rgba(126,155,255,.16); }
html[data-theme="dark"] .nav-link { color:#91a0bd; }
html[data-theme="dark"] .sidebar-foot { background:rgba(85,111,184,.11); border-color:rgba(142,164,228,.14); }
</style>
<style>
/* Shared POS dashboard skin: keep report markup/data, remove its legacy
   teal treatment so it reads like Dashboard, Sales Report, and Inventory. */
:root {
    --green-deep: #0f172a;
    --green-mid: #2563eb;
    --green-leaf: #38bdf8;
    --green-pale: #eff6ff;
    --mustard: #f59e0b;
    --mustard-pale: #fffbeb;
    --cream: #f1f5f9;
    --white: #ffffff;
    --ink: #0f172a;
    --ink-soft: #64748b;
    --line: #dbe4ef;
    --text-accent: #1e3a8a;
    --dashboard-grid-line: rgba(148,163,184,.13);
}
html[data-theme="dark"] {
    --green-deep: #f1f5f9;
    --green-mid: #2563eb;
    --green-leaf: #60a5fa;
    --green-pale: #172554;
    --cream: #090b0d;
    --white: #0d1013;
    --ink: #f1f5f9;
    --ink-soft: #94a3b8;
    --line: #26313a;
    --text-accent: #93c5fd;
    --dashboard-grid-line: rgba(147,197,253,.09);
}
html, body { background: var(--cream); }
.pos-shell { grid-template-columns: 248px 1fr; }
.pos-shell.sidebar-collapsed { grid-template-columns: 84px 1fr; }
.sidebar {
    --green-deep: #0f172a;
    --green-mid: #2563eb;
    --green-leaf: #60a5fa;
    background: #fff;
    color: #1e293b;
    padding: 24px 18px;
    border-right: 1px solid #e2e8f0;
    box-shadow: 8px 0 26px rgba(15,23,42,.06);
}
.sidebar-brand { border-bottom-color: #e2e8f0; }
.brand-name { color: #0f172a; }
.brand-tagline, .nav-section-label { color: #64748b; }
.nav-link { color: #334155; }
.nav-icon-box { color: #2563eb; background: #eff6ff; }
.nav-link:hover { color: #1d4ed8; background: #f8fbff; }
.nav-link.is-active {
    color: #fff !important;
    background: linear-gradient(100deg,#2563eb,#38bdf8) !important;
    box-shadow: 0 10px 22px rgba(37,99,235,.22);
}
.nav-link.is-active .nav-icon-box { color: #1d4ed8; background: rgba(255,255,255,.82); }
.sidebar-foot { background: #f8fafc; border-color: #e2e8f0; }
.sidebar-foot .foot-name { color: #0f172a; }
.sidebar-foot .foot-role { color: #64748b; }
.sidebar-foot .foot-avatar {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    overflow: hidden;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.sidebar-foot .foot-avatar img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}
.topbar-user-avatar {
    width: 32px;
    height: 32px;
    overflow: hidden;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #2563eb, #38bdf8);
    color: #fff;
    font-size: 12px;
    font-weight: 800;
}
.topbar-user-avatar img { width: 100%; height: 100%; object-fit: cover; }
.report-main {
    background:
        radial-gradient(700px 300px at 92% 0%, rgba(96,165,250,.08), transparent 70%),
        linear-gradient(var(--dashboard-grid-line) 1px, transparent 1px),
        linear-gradient(90deg, var(--dashboard-grid-line) 1px, transparent 1px),
        var(--cream);
    background-size: auto, 58px 58px, 58px 58px, auto;
    background-attachment: fixed;
}
.pos-topbar { padding: 16px 28px; background: var(--white); border-bottom-color: var(--line); box-shadow: 0 2px 8px rgba(15,23,42,.04); }
.topbar-icon { background: var(--green-pale); color: var(--green-mid); }
.pos-topbar h1 { color: var(--text-accent); font-size: 19px; }
.report-filters { padding: 14px 28px; background: var(--white); border-bottom-color: var(--line); }
.filter-input, .filter-select { background: var(--cream); border-color: var(--line); }
.filter-btn, .btn-action.primary { background: #2563eb; }
.filter-btn:hover, .btn-action.primary:hover { background: #1d4ed8; }
.report-body { padding: 24px 28px 30px; gap: 18px; }
.report-title-block h2 { color: var(--text-accent); font-size: 21px; }
.summary-grid { gap: 14px; }
.summary-card, .panel { border-color: var(--line); border-radius: 14px; box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 8px 20px rgba(15,23,42,.04); }
.summary-card:hover { box-shadow: 0 10px 24px rgba(37,99,235,.12); }
.summary-card.sales::before { background: #2563eb; }
.summary-card.net::before { background: #0d9488; }
.summary-card.sales .s-value { color: #2563eb; }
.panel-header { background: var(--white); }
.panel-header h3 { color: var(--text-accent); }
.data-table th { background: var(--cream); }
.data-table tbody tr:nth-child(even) { background: color-mix(in srgb, var(--cream) 55%, var(--white)); }
.data-table tbody tr:hover, .data-table tbody tr:nth-child(even):hover { background: var(--green-pale); }
html[data-theme="dark"] .sidebar {
    background: linear-gradient(180deg,#0b1530 0%,#080f24 58%,#070c1b 100%);
    color: #e8efff;
    border-right-color: rgba(126,155,255,.16);
}
html[data-theme="dark"] .nav-link { color: #91a0bd; }
html[data-theme="dark"] .sidebar-foot { background: rgba(85,111,184,.11); border-color: rgba(142,164,228,.14); }
html[data-theme="dark"] .sidebar-foot .foot-name { color: #edf2ff; }
html[data-theme="dark"] .sidebar-foot .foot-role { color: #8fa6d4; }
html[data-theme="dark"] .topbar-user-avatar { background: linear-gradient(135deg, #1d4ed8, #2563eb); }
.shift-report-shell .report-mobile-backdrop,.shift-report-shell .report-mobile-menu { display:none; }
@media (max-width: 900px) {
    .pos-shell { grid-template-columns: 84px 1fr; }
    .sidebar { padding: 24px 12px; }
    .sidebar-brand { justify-content: center; padding-left: 0; padding-right: 0; }
    .brand-text, .nav-label, .nav-section-label, .sidebar-foot .foot-text, .theme-toggle-btn .tt-label { display: none; }
    .nav-link { justify-content: center; gap: 0; }
    .sidebar-foot, .theme-toggle-btn { justify-content: center; }
    .report-body, .report-filters, .pos-topbar { padding-left: 18px; padding-right: 18px; }
}
@media (max-width:1023px) {
    .shift-report-shell,.shift-report-shell.sidebar-collapsed {
        grid-template-columns:minmax(0,1fr) !important;
        grid-template-rows:auto; height:auto; min-height:100vh; overflow:visible;
    }
    .shift-report-shell .sidebar {
        position:fixed !important; inset:0 auto 0 0; width:min(300px,86vw) !important; min-width:0 !important;
        height:100dvh; padding:22px 16px 18px !important; z-index:9988 !important;
        transform:translateX(-105%) !important; transition:transform .28s cubic-bezier(.2,.8,.2,1),box-shadow .28s ease !important;
        box-shadow:16px 0 40px rgba(15,23,42,.18) !important;
    }
    .shift-report-shell.mobile-nav-open .sidebar { transform:translateX(0) !important; }
    .shift-report-shell .report-mobile-backdrop {
        position:fixed; inset:0; z-index:9987; border:0; padding:0; background:rgba(15,23,42,.42); backdrop-filter:blur(2px);
    }
    .shift-report-shell.mobile-nav-open .report-mobile-backdrop { display:block; }
    .shift-report-shell .report-mobile-menu {
        display:inline-flex; flex:0 0 40px; width:40px; height:40px; align-items:center; justify-content:center;
        border:1px solid var(--line); border-radius:12px; color:var(--ink); background:var(--white); cursor:pointer;
    }
    .shift-report-shell .report-mobile-menu svg { width:20px; height:20px; }
    .shift-report-shell .sidebar-toggle { display:none !important; }
    .shift-report-shell .brand-text,.shift-report-shell .nav-label,.shift-report-shell .nav-section-label,
    .shift-report-shell .theme-toggle-btn .tt-label { display:initial !important; }
    .shift-report-shell .sidebar-foot .foot-text { display:flex !important; }
    .shift-report-shell .sidebar-brand { justify-content:flex-start !important; }
    .shift-report-shell .nav-link,.shift-report-shell .foot-logout-btn { justify-content:flex-start !important; gap:10px !important; }
    .shift-report-shell .sidebar-foot,.shift-report-shell .theme-toggle-btn { justify-content:flex-start !important; }
    .shift-report-shell.sidebar-collapsed .sidebar-brand {
        width:auto !important; height:auto !important; min-height:0 !important; margin:0 0 20px !important;
        padding:0 8px 22px !important; justify-content:flex-start !important; gap:12px !important;
        border-bottom:1px solid #e2e8f0 !important;
    }
    .shift-report-shell.sidebar-collapsed .sidebar-brand .brand-mark { width:44px !important; height:44px !important; margin:0 !important; }
    .shift-report-shell.sidebar-collapsed .brand-text,.shift-report-shell.sidebar-collapsed .nav-label,
    .shift-report-shell.sidebar-collapsed .nav-section-label,.shift-report-shell.sidebar-collapsed .theme-toggle-btn .tt-label { display:initial !important; }
    .shift-report-shell.sidebar-collapsed .sidebar-foot .foot-text { display:flex !important; }
    .shift-report-shell.sidebar-collapsed .nav-link,.shift-report-shell.sidebar-collapsed .foot-logout-btn { justify-content:flex-start !important; gap:10px !important; }
    .shift-report-shell.sidebar-collapsed .sidebar-foot,.shift-report-shell.sidebar-collapsed .theme-toggle-btn { justify-content:flex-start !important; }
    .shift-report-shell .report-main { min-width:0; width:100%; margin:0; }
    .shift-report-shell .report-mobile-menu { margin-right:2px; }
    .shift-report-shell .pos-topbar { gap:10px; }
}
@media (max-width: 640px) {
    .shift-report-shell, .shift-report-shell.sidebar-collapsed { display:block; height:auto; min-height:100vh; overflow:visible; }
    .shift-report-shell .report-main { margin:0; min-height:100vh; overflow:visible; }
    .shift-report-shell .report-body { flex:none; overflow:visible; }
    .shift-report-shell .pos-topbar { padding:12px 14px; }
    .shift-report-shell .pos-topbar h1 { font-size:17px; }
    .shift-report-shell .topbar-user-avatar,.shift-report-shell .topbar-right .cashier-name { display:none; }
    .shift-report-shell .report-body,.shift-report-shell .report-filters { padding-left:12px; padding-right:12px; }
    .shift-report-shell .summary-card,.shift-report-shell .panel { min-width:0; }
    .summary-grid { grid-template-columns: 1fr; }
    .report-header-row { align-items: flex-start; }
    .report-actions, .btn-action { width: 100%; }
    .btn-action { justify-content: center; }
    .report-filters { align-items: stretch; }
    .filter-field, .filter-input, .filter-select, .filter-btn { width: 100%; }
}
</style>
</head>
<body>
<div class="pos-shell app-shell shift-report-shell">

    <!-- ── SIDEBAR — copied from dashboard.php ── -->
    <?php $active = 'shift_report'; include 'includes/sidebar.php'; ?>
    <button type="button" class="report-mobile-backdrop" id="reportNavBackdrop" aria-label="Close navigation"></button>

    <!-- ── MAIN ── -->
    <div class="report-main">

        <!-- TOPBAR -->
        <div class="pos-topbar">
            <button type="button" class="report-mobile-menu" id="reportMenuToggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
            <div class="topbar-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M4 4h12v12H4z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M7 8h6M7 11h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></div>
            <h1>Shift Report</h1>
            <div class="topbar-right">
                <span class="topbar-user-avatar">
                    <?php if (!empty($__avatarFile)): ?>
                        <img src="uploads/avatars/<?= clean($__avatarFile) ?>" alt="">
                    <?php else: ?>
                        <?= clean($__initial) ?>
                    <?php endif; ?>
                </span>
                <span class="cashier-name"><?= $is_admin ? 'Administrator' : 'Cashier: ' . clean($_SESSION['full_name']) ?></span>
            </div>
        </div>

        <!-- FILTER BAR -->
        <form method="GET" action="shift_report.php">
            <div class="report-filters">
                <div class="filter-field">
                    <label class="filter-label">Date</label>
                    <input type="date" name="date" value="<?= clean($selected_date) ?>" class="filter-input" max="<?= date('Y-m-d') ?>">
                </div>
                <?php if ($is_admin): ?>
                <div class="filter-field">
                    <label class="filter-label">Cashier</label>
                    <select name="cashier_id" class="filter-select">
                        <option value="0">All Cashiers</option>
                        <?php foreach ($cashiers as $c): ?>
                            <option value="<?= enc_id($c['id']) ?>" <?= $selected_cashier == $c['id'] ? 'selected' : '' ?>>
                                <?= clean($c['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit" class="filter-btn">
                    <svg viewBox="0 0 20 20" fill="none" style="width:14px;height:14px;flex-shrink:0;"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.7"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                    View Report
                </button>
            </div>
        </form>

        <!-- SCROLLABLE BODY -->
        <div class="report-body">

            <!-- Print-only meta -->
            <div class="print-meta">
                <?= clean($store_name) ?> — Shift Report &nbsp;|&nbsp;
                <?= date('F d, Y', strtotime($selected_date)) ?> &nbsp;|&nbsp;
                Cashier: <?= clean($cashier_label) ?> &nbsp;|&nbsp;
                Generated: <?= date('F d, Y h:i A') ?>
            </div>

            <!-- HEADER ROW -->
            <div class="report-header-row">
                <div class="report-title-block">
                    <h2><?= date('F d, Y', strtotime($selected_date)) ?></h2>
                    <p>Cashier: <strong><?= clean($cashier_label) ?></strong></p>
                </div>
                <div class="report-actions">
                    <button onclick="window.print()" class="btn-action outline">
                        <svg viewBox="0 0 20 20" fill="none"><path d="M5 7V3h10v4M5 17H3a1 1 0 01-1-1V9a1 1 0 011-1h14a1 1 0 011 1v7a1 1 0 01-1 1h-2M5 13h10v4H5v-4z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Print
                    </button>
                    <a href="shift_report.php?<?= $export_params ?>" class="btn-action primary">
                        <svg viewBox="0 0 20 20" fill="none"><path d="M10 3v10M6 9l4 4 4-4M3 14v1a2 2 0 002 2h10a2 2 0 002-2v-1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Export CSV
                    </a>
                </div>
            </div>

            <!-- SUMMARY CARDS -->
            <div class="summary-grid">
                <div class="summary-card txns">
                    <div class="s-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M4 4h12v12H4z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M7 8h6M7 11h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></div>
                    <div class="s-text">
                        <div class="s-label">Transactions</div>
                        <div class="s-value"><?= number_format($summary['total_transactions']) ?></div>
                    </div>
                </div>
                <div class="summary-card sales">
                    <div class="s-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M10 2v16M14 5.5c0-1.5-1.8-2.5-4-2.5s-4 1-4 2.6c0 3.4 8 1.6 8 5.1 0 1.7-1.9 2.8-4 2.8s-4-1-4-2.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></div>
                    <div class="s-text">
                        <div class="s-label">Gross Sales</div>
                        <div class="s-value"><?= peso($summary['gross_sales']) ?></div>
                    </div>
                </div>
                <div class="summary-card disc">
                    <div class="s-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M3 10.5l7-7 7 3-3 7-7 7-4-4z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="12" cy="8" r="1.3" fill="currentColor"/></svg></div>
                    <div class="s-text">
                        <div class="s-label">Discounts Given</div>
                        <div class="s-value"><?= peso($summary['total_discounts']) ?></div>
                    </div>
                </div>
                <div class="summary-card cash">
                    <div class="s-icon"><svg viewBox="0 0 20 20" fill="none"><rect x="2" y="5" width="16" height="10" rx="1.6" stroke="currentColor" stroke-width="1.6"/><circle cx="10" cy="10" r="2.4" stroke="currentColor" stroke-width="1.5"/></svg></div>
                    <div class="s-text">
                        <div class="s-label">Cash Received</div>
                        <div class="s-value"><?= peso($summary['total_cash_received']) ?></div>
                    </div>
                </div>
                <div class="summary-card net">
                    <div class="s-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M3 14l4.5-5 3.5 3 6-7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M13 5h4v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                    <div class="s-text">
                        <div class="s-label">Net Sales</div>
                        <div class="s-value"><?= peso($summary['gross_sales'] - $summary['total_discounts']) ?></div>
                    </div>
                </div>
            </div>

            <!-- TRANSACTION LOG -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-header-title">
                        <span class="panel-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M4 3h9l3 3v11a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7 9h6M7 12h6M7 15h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
                        <h3>Transaction Log</h3>
                    </div>
                    <span class="row-count"><?= count($transactions) ?> transaction<?= count($transactions) == 1 ? '' : 's' ?></span>
                </div>
                <?php if (empty($transactions)): ?>
                    <div class="no-data">
                        <svg viewBox="0 0 20 20" fill="none"><path d="M4 3h9l3 3v11a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7 9h6M7 12h6M7 15h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                        No transactions found for this date / cashier.
                    </div>
                <?php else: ?>
                <div class="panel-body--scrollable">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Txn #</th>
                            <th>Time</th>
                            <?php if ($is_admin && $selected_cashier == 0): ?><th>Cashier</th><?php endif; ?>
                            <th>Gross</th>
                            <th>Discount</th>
                            <th>Total Paid</th>
                            <th>Cash</th>
                            <th>Change</th>
                        </tr>
                    </thead>
                    <tbody>
    <?php foreach ($transactions as $t): ?>
    <tr style="<?= in_array($t['status'], ['voided', 'refunded'], true) ? 'opacity:0.55;' : '' ?>">
        <td style="font-weight:700;">
            #<?= $t['id'] ?>
            <?php if ($t['status'] === 'voided'): ?>
                <span class="status-badge voided">Voided</span>
            <?php elseif ($t['status'] === 'refunded'): ?>
                <span class="status-badge refunded">Refunded</span>
            <?php elseif ($t['status'] === 'partial_refunded'): ?>
                <span class="status-badge partial_refunded">Partially Refunded</span>
            <?php endif; ?>
        </td>
        <td style="color:var(--ink-soft); font-size:13px;"><?= date('h:i A', strtotime($t['transaction_date'])) ?></td>
        <?php if ($is_admin && $selected_cashier == 0): ?>
            <td style="font-weight:600;"><?= clean($t['cashier_name']) ?></td>
        <?php endif; ?>
        <td><?= peso($t['total_amount'] + $t['discount_amount']) ?></td>
        <td style="color:#a06b10; font-weight:600;">
            <?= $t['discount_amount'] > 0 ? '−' . peso($t['discount_amount']) : '—' ?>
        </td>
        <td style="font-weight:700; color:var(--green-mid);"><?= peso($t['total_amount']) ?></td>
        <td><?= peso($t['payment']) ?></td>
        <td style="color:var(--ink-soft);"><?= peso($t['change_amount']) ?></td>
    </tr>
    <?php endforeach; ?>
</tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ITEMS SOLD -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-header-title">
                        <span class="panel-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M3 6.5l7-3.5 7 3.5v7L10 17l-7-3.5v-7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M3 6.5L10 10l7-3.5M10 10v7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                        <h3>Items Sold</h3>
                    </div>
                    <span class="row-count"><?= count($items_sold) ?> product<?= count($items_sold) == 1 ? '' : 's' ?></span>
                </div>
                <?php if (empty($items_sold)): ?>
                    <div class="no-data">
                        <svg viewBox="0 0 20 20" fill="none"><path d="M3 6.5l7-3.5 7 3.5v7L10 17l-7-3.5v-7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M3 6.5L10 10l7-3.5M10 10v7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        No items sold for this date / cashier.
                    </div>
                <?php else: ?>
                <div class="panel-body--scrollable">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Qty Sold</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items_sold as $i): ?>
                        <tr>
                            <td style="font-weight:600;"><?= clean($i['product_name']) ?></td>
                            <td style="color:var(--ink-soft); font-size:13px;"><?= clean($i['category']) ?></td>
                            <td style="font-weight:700;"><?= number_format($i['total_qty']) ?> pcs</td>
                            <td style="font-weight:700; color:var(--green-mid);"><?= peso($i['total_revenue']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /report-body -->
    </div><!-- /report-main -->
</div><!-- /pos-shell -->
<script>
function confirmNav(e, url, opts) {
    e.preventDefault();
    Swal.fire(Object.assign({
        showCancelButton: true,
        cancelButtonText: 'Cancel'
    }, opts)).then(function (result) {
        if (result.isConfirmed) window.location.href = url;
    });
    return false;
}
(function () {
    const shell = document.querySelector('.shift-report-shell');
    const toggle = document.getElementById('reportMenuToggle');
    const backdrop = document.getElementById('reportNavBackdrop');
    if (!shell || !toggle || !backdrop) return;
    function setOpen(open) {
        shell.classList.toggle('mobile-nav-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    }
    toggle.addEventListener('click', function () { setOpen(!shell.classList.contains('mobile-nav-open')); });
    backdrop.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') setOpen(false);
    });
    window.addEventListener('resize', function () {
        if (window.matchMedia('(min-width: 1024px)').matches) setOpen(false);
    });
})();
</script>
</body>
</html>