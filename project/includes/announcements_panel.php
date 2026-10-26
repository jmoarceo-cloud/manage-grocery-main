<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// ============================================================
// includes/announcements_panel.php
// ------------------------------------------------------------
// Read-only "Announcements" panel for the POS side (Admin, Cashier,
// Finance, Inventory Staff dashboards). Pulls COMPANY-WIDE, PUBLISHED,
// not-yet-expired announcements from the `hrms` database via the
// existing $hrmsPdo bridge (includes/config.php) — same fail-open
// pattern as getPosSnapshotForAdmin() on the HRMS side, just in the
// reverse direction.
//
// Department-scoped HR announcements NEVER reach this panel — the
// filter happens in the SQL itself (getCompanyWideAnnouncementsForPos()
// in HRMS/includes/config.php), not just by omission here.
//
// Fail-open: if $hrmsPdo is null (HRMS database unreachable) or the
// query fails, this panel simply renders nothing — it never errors
// out or breaks the rest of the dashboard.
//
// Usage: `include 'includes/announcements_panel.php';` from any page
// that has already required includes/config.php (so $hrmsPdo exists).
// ============================================================

$__announcements = function_exists('getCompanyWideAnnouncementsForPos')
    ? getCompanyWideAnnouncementsForPos($hrmsPdo, 5)
    : [];

if (!empty($__announcements)):
?>
<div class="panel">
    <div class="panel-header">
        <h2>Announcements</h2>
    </div>
    <div class="panel-body" style="display:flex; flex-direction:column; gap:10px; padding:4px 22px 20px;">
        <?php foreach ($__announcements as $__ann): ?>
            <div style="padding:12px 14px; border:1.5px solid var(--line); border-radius:12px; background:var(--cream);">
                <div style="font-size:14px; font-weight:700; color:var(--ink); font-family:var(--font-display);"><?= clean($__ann['title']) ?></div>
                <div style="font-size:13px; color:var(--ink); margin-top:6px; white-space:pre-wrap;"><?= clean(mb_strimwidth($__ann['content'], 0, 220, '...')) ?></div>
                <div style="font-size:11.5px; color:var(--ink-soft); margin-top:8px;">
                    <?= clean(date('F d, Y', strtotime($__ann['published_at']))) ?>
                    <?php if (!empty($__ann['expires_at'])): ?>
                        &middot; Expires <?= clean(date('F d, Y', strtotime($__ann['expires_at']))) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
