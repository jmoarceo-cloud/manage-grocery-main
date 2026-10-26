<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --green-deep:    #1D4ED8;
    --text-accent:   var(--green-deep);
    --green-mid:     #2563EB;
    --green-leaf:    #4FC3D1;
    --green-pale:    #E3F2F6;
    --mustard:       #E0A23B;
    --mustard-pale:  #FBEFD8;
    --red-alert:     #DC2626;
    --red-pale:      #FEE2E2;
    --cream:         #F3F6F9;
    --white:         #FFFFFF;
    --ink:           #0F172A;
    --ink-soft:      #475569;
    --line:          #DCE5EC;
    --font-display: 'Sora', sans-serif;
    --font-body: 'Plus Jakarta Sans', 'Trebuchet MS', Verdana, sans-serif;
}

html, body { min-height: 100vh; font-family: var(--font-body); color: var(--ink); background: var(--cream); }
a { text-decoration: none; color: inherit; }

/* ── Sidebar ── */
.app-shell { display: flex; min-height: 100vh; }
.sidebar {
    width: 288px; flex-shrink: 0;
    background: radial-gradient(420px 260px at 100% 0%, rgba(79,195,209,.16), transparent 60%), var(--green-deep);
    color: #FFFFFF;
    display: flex; flex-direction: column; padding: 30px 24px;
    position: fixed; top: 0; left: 0; height: 100vh; overflow-y: auto;
    z-index: 100;
    box-shadow: 2px 0 12px rgba(0,0,0,0.18);
}
.sidebar-brand { display: flex; align-items: center; gap: 14px; padding: 0 4px 26px; margin-bottom: 22px; border-bottom: 1px solid rgba(255,255,255,0.14); }
.brand-mark { width: 46px; height: 46px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #1D4ED8, #0284C7); border-radius: 13px; box-shadow: 0 4px 10px rgba(0,0,0,.14); }
.brand-mark .brand-icon, .brand-icon { width: 28px; height: 28px; }
.brand-text { display: flex; flex-direction: column; line-height: 1.35; }
.brand-name { font-family: var(--font-display); font-weight: 700; font-size: 17px; color: #FFFFFF; }
.brand-name span { color: #BFDBFE; }
.brand-tagline { font-size: 11px; color: var(--green-leaf); font-weight: 600; letter-spacing: .02em; }
.nav-section-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.42); padding: 0 14px; margin: 4px 0 10px; }
.sidebar-nav { display: flex; flex-direction: column; gap: 5px; }
.nav-link { display: flex; align-items: center; gap: 13px; padding: 13px 15px; border-radius: 12px; color: rgba(255,255,255,0.82); font-size: 14.5px; font-weight: 600; transition: background 0.15s ease, color 0.15s ease; }
.nav-icon-box { width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,.08); }
.nav-link.is-active .nav-icon-box { background: rgba(255,255,255,.35); }
.nav-icon { width: 17px; height: 17px; flex-shrink: 0; }
.nav-link:hover { background: rgba(255,255,255,0.1); color: #FFFFFF; }
.nav-link.is-active { background: var(--green-leaf); color: var(--green-deep); box-shadow: 0 4px 12px rgba(0,0,0,.16); }
.nav-link--logout { margin-top: 16px; border-top: 1px solid rgba(255,255,255,0.14); padding-top: 18px; color: rgba(255,255,255,0.65); }
.nav-link--logout:hover { background: rgba(200,71,45,0.18); color: #ffb3a3; }
.sidebar-foot { margin-top: auto; padding: 14px; border-radius: 14px; background: rgba(255,255,255,.06); display: flex; align-items: center; gap: 12px; }
.sidebar-foot .foot-avatar { width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0; background: linear-gradient(150deg, var(--green-leaf), #2E9BAE); color: var(--green-deep); display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 15px; overflow: hidden; }
.sidebar-foot .foot-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.sidebar-foot .foot-text { display: flex; flex-direction: column; line-height: 1.3; min-width: 0; }
.sidebar-foot .foot-name { font-size: 13.5px; font-weight: 700; color: #FFFFFF; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sidebar-foot .foot-role { font-size: 11.5px; color: rgba(255,255,255,0.6); font-weight: 600; }
.main-area { margin-left: 288px; flex: 1; min-width: 0; display: flex; flex-direction: column; position: relative; z-index: 1; }
@media (max-width: 900px) {
    .sidebar { width: 76px; padding: 24px 12px; }
    .brand-text, .nav-link span, .sidebar-foot .foot-text { display: none; }
    .sidebar-brand { justify-content: center; padding-bottom: 18px; }
    .nav-link { justify-content: center; padding: 12px; gap: 0; }
    .sidebar-foot { justify-content: center; padding: 10px; }
    .main-area { margin-left: 76px; }
}

.topbar { background: var(--white); border-bottom: 1px solid var(--line); padding: 18px 32px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
.topbar h1 { font-family: var(--font-display); font-size: 20px; color: var(--text-accent); }
.topbar .user-info { font-size: 13.5px; color: var(--ink-soft); display: flex; align-items: center; gap: 16px; }
.user-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--mustard); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 13px; flex-shrink: 0; overflow: hidden; }
.user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

.container { padding: 30px 32px 64px; }

.alert { padding: 12px 16px; border-radius: 12px; font-size: 14px; font-weight: 500; margin-bottom: 20px; }
.alert--success { background: #e6f4ea; color: #1a7a3b; }
.alert--error   { background: var(--red-pale); color: var(--red-alert); }

.btn { padding: 10px 18px; border: none; border-radius: 10px; font-family: var(--font-body); font-size: 14px; font-weight: 600; cursor: pointer; transition: background .15s ease; }
.btn-primary { background: linear-gradient(135deg, var(--green-mid), var(--green-deep)); color: #FFFFFF; box-shadow: 0 2px 8px rgba(18,60,92,0.22); }
.btn-primary:hover { filter: brightness(1.08); box-shadow: 0 5px 16px rgba(18,60,92,0.32); transform: translateY(-2px); }
.btn-primary:active { transform: translateY(0); filter: brightness(0.97); }
.btn-success { background: var(--green-pale); color: var(--text-accent); }
.btn-success:hover { background: #d5ebda; transform: translateY(-2px); box-shadow: 0 3px 10px rgba(18,60,92,0.1); }
.btn-success:active { transform: translateY(0); box-shadow: none; }
.btn-danger { background: #DC2626; color: #FFFFFF; border: 1px solid #B91C1C; box-shadow: 0 2px 8px rgba(220,38,38,.2); }
.btn-danger:hover { background: #B91C1C; color: #FFFFFF; transform: translateY(-2px); box-shadow: 0 3px 10px rgba(220,38,38,.22); }
.btn-danger:active { transform: translateY(0); box-shadow: none; }
.btn-sm { padding: 6px 12px; font-size: 12.5px; }

/* KPI cards */
.kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; margin-bottom: 28px; }
.kpi-card { background: var(--white); border: 1px solid var(--line); border-radius: 16px; padding: 20px; border-top: 3px solid var(--green-mid); }
.kpi-card.accent-mustard { border-top-color: var(--mustard); }
.kpi-card.accent-red { border-top-color: var(--red-alert); }
.kpi-label { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); font-weight: 600; margin-bottom: 10px; }
.kpi-value { font-family: var(--font-display); font-size: 30px; color: var(--text-accent); line-height: 1; }
.kpi-sub { font-size: 12.5px; color: var(--ink-soft); margin-top: 6px; }

/* Sections & panels */
.section { margin-bottom: 28px; }
.section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.section-header h2 { font-family: var(--font-display); font-size: 18px; color: var(--text-accent); }

.panel-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.panel { background: var(--white); border: 1px solid var(--line); border-radius: 16px; padding: 24px; margin-bottom: 20px; }
.panel h3 { font-family: var(--font-display); font-size: 16px; color: var(--text-accent); margin-bottom: 14px; }
.stat-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 14px; }
.stat-row:last-child { border-bottom: none; }
.stat-row .stat-label { color: var(--ink-soft); }
.stat-row .stat-value { font-weight: 700; }

.table-card { background: var(--white); border: 1px solid var(--line); border-radius: 16px; overflow: hidden; }
.table-scroll { max-height: 420px; overflow-y: auto; }
.table-scroll thead th { position: sticky; top: 0; z-index: 1; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 12.5px; text-transform: uppercase; letter-spacing: .03em; color: var(--ink-soft); background: var(--cream); padding: 12px 16px; border-bottom: 1px solid var(--line); }
td { padding: 13px 16px; font-size: 14px; border-bottom: 1px solid var(--line); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--gray-bg, #FAFAF7); }

.badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.actions-cell { display: flex; gap: 6px; flex-wrap: wrap; }
.empty-state { text-align: center; padding: 40px 24px; color: var(--ink-soft); font-size: 14px; }

.note-banner { background: var(--mustard-pale); color: #7A5A1E; border-radius: 12px; padding: 12px 16px; font-size: 13px; margin-bottom: 24px; }

.tip-card {
    display: flex; gap: 16px; align-items: flex-start;
    background: linear-gradient(135deg, var(--mustard-pale) 0%, #FDF6E9 100%);
    border: 1px solid rgba(224,162,59,.4);
    border-radius: 16px; padding: 18px 20px;
    box-shadow: 0 1px 2px rgba(122,90,30,.05), 0 4px 14px rgba(122,90,30,.06);
}
.tip-card__icon {
    flex-shrink: 0; width: 38px; height: 38px; border-radius: 11px;
    background: linear-gradient(150deg, var(--mustard) 0%, #C98A2A 100%);
    color: #FFFFFF; box-shadow: 0 3px 8px rgba(201,138,42,.35);
    display: flex; align-items: center; justify-content: center;
}
.tip-card__body { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
.tip-card__eyebrow { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #A87A24; }
.tip-card__lead { font-size: 14px; color: #6B4F1C; line-height: 1.55; }
.tip-card__divider { height: 1px; background: rgba(224,162,59,.35); margin: 2px 0; }
.tip-card__note { display: flex; gap: 9px; align-items: flex-start; font-size: 12.5px; color: #8a6b32; line-height: 1.55; }
.tip-card__note svg { flex-shrink: 0; margin-top: 2px; color: #C98A2A; }
.tip-card__note strong { color: #5c4416; font-weight: 700; }

.form-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
.field-group { display: flex; flex-direction: column; gap: 6px; min-width: 200px; }
.field-group label { font-size: 12.5px; font-weight: 600; color: var(--ink-soft); }
select, input, textarea { padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; font-family: var(--font-body); font-size: 14px; width: 100%; }
textarea { resize: vertical; min-height: 60px; }

.tabs { display: flex; gap: 8px; padding: 4px; margin-bottom: 20px; flex-wrap: wrap; }
.tab-btn { padding: 9px 16px; border-radius: 10px; font-size: 13.5px; font-weight: 600; color: var(--ink-soft); background: var(--white); border: 1px solid var(--line); }
.tab-btn.active { background: var(--green-mid); color: #FFFFFF; border-color: var(--green-mid); }

.star-rate { color: var(--mustard); font-size: 15px; letter-spacing: 1px; }

@media (max-width: 900px) { .panel-grid { grid-template-columns: 1fr; } }
@media (max-width: 640px) { .container { padding: 20px 16px; } .topbar { padding: 14px 16px; } }
</style>
<link rel="stylesheet" href="assets/hrms-theme.css">