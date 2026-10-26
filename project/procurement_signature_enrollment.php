<?php
require_once __DIR__ . '/includes/config.php';
requireAdmin();

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $type = ($_POST['party_type'] ?? '') === 'supplier' ? 'supplier' : 'user';
    $id = (int) ($_POST['party_id'] ?? 0);
    $signature = trim((string) ($_POST['signature'] ?? ''));
    $table = $type === 'supplier' ? 'suppliers' : 'users';
    if ($id <= 0 || !hasDrawnPngSignature($signature)) {
        $error = 'Select a signer and draw a valid reference signature.';
    } else {
        $check = $pdo->prepare("SELECT id FROM {$table} WHERE id = ? LIMIT 1");
        $check->execute([$id]);
        if (!$check->fetchColumn()) {
            $error = 'The selected signer was not found.';
        } else {
            $stmt = $pdo->prepare("UPDATE {$table} SET procurement_signature_reference = ?, procurement_signature_enrolled_at = NOW() WHERE id = ?");
            $stmt->execute([$signature, $id]);
            $message = 'Reference signature saved. Reviewers can now compare future signatures against it.';
        }
    }
}
$users = $pdo->query("SELECT id, full_name, username, role, procurement_signature_reference, procurement_signature_enrolled_at FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();
$suppliers = $pdo->query("SELECT id, company_name, contact_person, procurement_signature_reference, procurement_signature_enrolled_at FROM suppliers WHERE status = 'active' ORDER BY company_name")->fetchAll();
$fullName = trim((string) ($_SESSION['full_name'] ?? 'Administrator'));
$initial = strtoupper(substr($fullName !== '' ? $fullName : 'A', 0, 1));
$avatar = $_SESSION['profile_picture'] ?? '';
$iconSettings = '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="2.6" stroke="currentColor" stroke-width="1.5"/><path d="M10 3v1.6M10 15.4V17M17 10h-1.6M4.6 10H3M14.9 5.1l-1.1 1.1M6.2 13.7l-1.1 1.1M14.9 14.9l-1.1-1.1M6.2 6.3L5.1 5.1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Procurement Signatures</title>
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<style>
body{overflow-x:hidden}.signature-layout{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(300px,.9fr);gap:20px;align-items:start}
.signature-panel{overflow:hidden}.signature-panel .panel-body{padding:24px}.signature-panel h2{margin:0;color:var(--ink);font-size:18px}.help-copy{margin:7px 0 22px;color:var(--ink-soft);font-size:13px;line-height:1.55}.signature-layout>.signature-panel:first-child{order:2}.signature-layout>.signature-panel:last-child{order:1}
.field-row{display:grid;grid-template-columns:1fr 1.5fr;gap:14px}.field-label{display:block;margin-bottom:7px}.field-input{width:100%;box-sizing:border-box}
.signature-preview{min-height:170px;border:1px dashed var(--line);border-radius:12px;background:#f8fafc;padding:14px}.signature-preview strong{display:block;color:var(--ink);font-size:13px}.signature-preview img{display:block;width:100%;height:112px;object-fit:contain;background:#fff;border:1px solid var(--line);border-radius:8px;margin-top:10px}.signature-status{color:var(--ink-soft);font-size:13px;margin-top:10px}
.signature-canvas{display:block;width:100%;height:210px;border:1px dashed #93c5fd;border-radius:12px;background:#fff;touch-action:none}.canvas-label{display:flex;justify-content:space-between;align-items:center;margin:20px 0 8px;color:var(--ink);font-weight:700;font-size:13px}.canvas-label span{font-weight:400;color:var(--ink-soft)}
.form-actions{display:flex;gap:9px;justify-content:flex-end;margin-top:18px}.action-btn{cursor:pointer}.action-btn--secondary{background:var(--white);color:var(--text-accent);border:1px solid var(--line)}
.notice{display:flex;gap:10px;padding:13px 15px;border-radius:10px;margin-bottom:18px;font-size:13px}.notice-ok{background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}.notice-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.steps{display:grid;gap:13px}.step{display:flex;gap:12px;align-items:flex-start}.step-number{display:grid;place-items:center;flex:0 0 26px;height:26px;border-radius:50%;background:#e0f2fe;color:#075985;font-weight:800;font-size:12px}.step strong{display:block;color:var(--ink);font-size:13px}.step span{display:block;color:var(--ink-soft);font-size:12px;line-height:1.5;margin-top:3px}
@media(max-width:900px){.signature-layout{grid-template-columns:1fr}}@media(max-width:560px){.field-row{grid-template-columns:1fr}.form-actions{flex-direction:column}.form-actions button{width:100%}}
</style>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="app-shell">
<?php $active = 'procurement_signature_enrollment'; include __DIR__ . '/includes/sidebar.php'; ?>
<div class="main-area">
<header class="topbar"><div class="topbar-icon"><?= $iconSettings ?></div><h1>Procurement Signatures</h1><div class="topbar-user"><span class="user-avatar"><?php if ($avatar): ?><img src="uploads/avatars/<?= clean($avatar) ?>" alt=""><?php else: ?><?= clean($initial) ?><?php endif; ?></span><span class="user-name"><?= clean($fullName) ?></span></div></header>
<main class="content">
<div class="page-header"><div><h1>Procurement Signature Enrollment</h1><p class="page-subtitle">Register the genuine reference signature that reviewers will use for manual Before and After checking.</p></div></div>
<?php if ($message): ?><div class="notice notice-ok"><?= clean($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= clean($error) ?></div><?php endif; ?>
<div class="signature-layout">
<section class="panel signature-panel"><div class="panel-body">
<h2>After: New signature</h2><p class="help-copy">Draw the signer’s current signature here. It will be saved as the reference used for manual procurement verification.</p>
<form method="post" id="enrollForm"><?php csrfField(); ?>
<div class="field-row"><div><label class="field-label" for="party_type">Signer type</label><select class="field-input" name="party_type" id="party_type"><option value="user">Employee / Finance / Requester</option><option value="supplier">Supplier</option></select></div><div><label class="field-label" for="party_id">Signer</label><select class="field-input" name="party_id" id="party_id"></select></div></div>
<div class="canvas-label"><label for="pad">After signature drawing</label><span>Use mouse, touch, or trackpad</span></div><canvas class="signature-canvas" id="pad" width="900" height="260" aria-label="Draw genuine reference signature"></canvas><input type="hidden" name="signature" id="signature">
<div class="form-actions"><button type="button" class="action-btn action-btn--secondary" id="clear">Clear drawing</button><button type="submit" class="action-btn">Save reference signature</button></div>
</form></div></section>
<aside class="panel signature-panel"><div class="panel-body"><h2>Before: Saved reference</h2><p class="help-copy">This is the previously enrolled signature. Compare it with the new signature before saving or approving.</p><div class="signature-preview"><strong id="referenceTitle">Reference signature</strong><div id="referenceStatus" class="signature-status">Select a signer to view the saved reference.</div><img id="referencePreview" alt="Enrolled reference signature" hidden></div><hr style="border:0;border-top:1px solid var(--line);margin:24px 0"><h2>How it works</h2><div class="steps" style="margin-top:16px"><div class="step"><b class="step-number">1</b><div><strong>Enroll once</strong><span>The real signer draws their reference signature.</span></div></div><div class="step"><b class="step-number">2</b><div><strong>Compare visually</strong><span>Finance sees Before and After signatures side by side.</span></div></div><div class="step"><b class="step-number">3</b><div><strong>Approve securely</strong><span>The reviewer confirms the match before continuing.</span></div></div></div></div></aside>
</div>
</main></div></div>
<script>
const users=<?= json_encode($users,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,suppliers=<?= json_encode($suppliers,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const type=document.getElementById('party_type'),select=document.getElementById('party_id'),canvas=document.getElementById('pad'),ctx=canvas.getContext('2d'),preview=document.getElementById('referencePreview'),status=document.getElementById('referenceStatus');let drawn=false,active=false;
function showReference(){const rows=type.value==='supplier'?suppliers:users,selected=rows.find(r=>String(r.id)===String(select.value)),reference=selected&&selected.procurement_signature_reference;if(reference){preview.src=reference;preview.hidden=false;status.textContent='Saved '+(selected.procurement_signature_enrolled_at?'on '+selected.procurement_signature_enrolled_at+'.':'as the current reference.')}else{preview.hidden=true;preview.removeAttribute('src');status.textContent='No reference signature enrolled yet.'}}
function fill(){const rows=type.value==='supplier'?suppliers:users;select.innerHTML=rows.map(r=>'<option value="'+r.id+'">'+(r.company_name||r.full_name)+'</option>').join('');showReference()}type.onchange=fill;select.onchange=showReference;fill();
function point(e){const r=canvas.getBoundingClientRect();return{x:(e.clientX-r.left)*canvas.width/r.width,y:(e.clientY-r.top)*canvas.height/r.height}}canvas.onpointerdown=e=>{e.preventDefault();active=drawn=true;canvas.setPointerCapture(e.pointerId);const p=point(e);ctx.beginPath();ctx.moveTo(p.x,p.y)};canvas.onpointermove=e=>{if(!active)return;const p=point(e);ctx.lineTo(p.x,p.y);ctx.strokeStyle='#123c5c';ctx.lineWidth=4;ctx.lineCap='round';ctx.stroke()};canvas.onpointerup=canvas.onpointercancel=()=>{active=false};document.getElementById('clear').onclick=()=>{ctx.clearRect(0,0,canvas.width,canvas.height);drawn=false};document.getElementById('enrollForm').onsubmit=e=>{if(!drawn){e.preventDefault();Swal.fire({icon:'warning',title:'Signature required',text:'Draw the genuine reference signature first',confirmButtonText:'Okay'});return}document.getElementById('signature').value=canvas.toDataURL('image/png')};
</script>
</body></html>
