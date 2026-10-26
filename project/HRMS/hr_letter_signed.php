<?php
require_once 'includes/hr_letters.php'; requireHR();$id=(int)($_GET['id']??0);$s=$pdo->prepare("SELECT * FROM hr_letters WHERE id=?");$s->execute([$id]);$l=$s->fetch();if(!$l)die('Not found');$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){requireCSRF();$signature=trim($_POST['recipient_signature']??'');$hasFile=isset($_FILES['signed_file'])&&$_FILES['signed_file']['error']!==UPLOAD_ERR_NO_FILE;if($hasFile){if($_FILES['signed_file']['error']!==UPLOAD_ERR_OK)$error='The PDF upload could not be completed.';elseif(strtolower(pathinfo($_FILES['signed_file']['name'],PATHINFO_EXTENSION))!=='pdf'||$_FILES['signed_file']['size']>10*1024*1024)$error='Only PDF files up to 10 MB are allowed.';elseif(!isValidPdfSignature($_FILES['signed_file']['tmp_name']))$error='The uploaded file is not a valid PDF.';}if(!$error&&$signature!==''){if(!preg_match('#^data:image/png;base64,#',$signature))$error='The drawn signature format is invalid.';else{$raw=base64_decode(substr($signature,22),true);if($raw===false||strlen($raw)>500000||substr($raw,0,8)!="\x89PNG\r\n\x1a\n")$error='The drawn signature is invalid or too large.';}}if(!$error&&!$hasFile&&$signature==='')$error='Draw a signature or choose a signed PDF before saving.';if(!$error&&$hasFile){$dir=__DIR__.'/uploads/hr_letters';if(!is_dir($dir))mkdir($dir,0750,true);$name='signed_'.$id.'_'.bin2hex(random_bytes(8)).'.pdf';if(move_uploaded_file($_FILES['signed_file']['tmp_name'],$dir.'/'.$name)){$pdo->prepare("UPDATE hr_letters SET signed_file=?,signed_original_name=?,recipient_signature=CASE WHEN ?<>'' THEN ? ELSE recipient_signature END,recipient_signature_at=CASE WHEN ?<>'' THEN NOW() ELSE recipient_signature_at END WHERE id=?")->execute([$name,$_FILES['signed_file']['name'],$signature,$signature,$signature,$id]);hrLetterLog($pdo,$id,'signed_uploaded');header('Location: hr_letters.php');exit;}$error='Upload failed.';}elseif(!$error){$pdo->prepare("UPDATE hr_letters SET recipient_signature=?,recipient_signature_at=NOW() WHERE id=?")->execute([$signature,$id]);hrLetterLog($pdo,$id,'signature_drawn');header('Location: hr_letters.php');exit;}}
hrLetterHeader('Upload signed document');?>
<style>
.signed-upload{max-width:760px;margin:0 auto}
.signed-upload__toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:18px}
.signed-upload__toolbar p{margin:0;color:var(--ink-soft);font-size:13px}
.signed-upload__card{background:var(--white);border:1px solid var(--line);border-radius:18px;padding:28px;box-shadow:0 10px 28px rgba(15,23,42,.055)}
.signed-upload__eyebrow{margin:0 0 6px;color:var(--text-accent);font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.signed-upload h2{margin:0;color:var(--ink);font-family:var(--font-display);font-size:20px}
.signed-upload__meta{margin:8px 0 22px;color:var(--ink-soft);font-size:13px}
.signed-upload__drop{display:block;padding:24px;border:1px dashed #93c5fd;border-radius:14px;background:var(--green-pale);cursor:pointer}
.signed-upload__drop strong{display:block;color:var(--ink);font-size:14px;margin-bottom:5px}
.signed-upload__drop span{display:block;color:var(--ink-soft);font-size:12px}
.signed-upload input[type=file]{display:block;width:100%;margin-top:14px;padding:12px;border:1px solid var(--line);border-radius:10px;background:var(--white);color:var(--ink);font:inherit;font-size:13px}
.signed-upload__hint{margin:10px 0 20px;color:var(--ink-soft);font-size:12px}
.signed-upload__divider{display:flex;align-items:center;gap:12px;margin:24px 0;color:var(--ink-soft);font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.signed-upload__divider:before,.signed-upload__divider:after{content:"";height:1px;flex:1;background:var(--line)}
.signed-upload__signature{padding:18px;border:1px solid var(--line);border-radius:14px;background:var(--cream)}.signed-upload__signature p{margin:0 0 10px;color:var(--ink-soft);font-size:12px}.signature-pad{display:block;width:100%;height:170px;border:1px dashed #93c5fd;border-radius:10px;background:#fff;touch-action:none}.signature-tools{display:flex;justify-content:flex-end;margin-top:8px}.signature-tools button{border:0;background:transparent;color:var(--text-accent);font:700 12px inherit;cursor:pointer}
.signed-upload__actions{display:flex;justify-content:flex-end;gap:10px}
.signed-upload .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:0 16px;border:0;border-radius:10px;background:var(--green-deep);color:#fff;font:700 13px inherit;cursor:pointer}
.signed-upload .btn:hover{background:var(--green-mid)}
.signed-upload .btn--light{background:var(--white);border:1px solid var(--line);color:var(--text-accent);text-decoration:none}
.signed-upload .btn--light:hover{background:var(--green-pale)}
.signed-upload__error{margin-bottom:18px;padding:12px 14px;border:1px solid #fecaca;border-radius:10px;background:#fef2f2;color:#b91c1c;font-size:13px;font-weight:600}
@media(max-width:640px){.signed-upload__toolbar{align-items:stretch;flex-direction:column}.signed-upload__toolbar .btn{width:100%}.signed-upload__card{padding:20px}.signed-upload__actions{flex-direction:column}.signed-upload__actions .btn{width:100%}}
</style>
<div class="signed-upload">
    <div class="signed-upload__toolbar">
        <p>Attach a signed PDF copy to this HR letter for recordkeeping.</p>
        <a class="btn btn--light" href="hr_letters.php"><i class="fa-solid fa-arrow-left"></i> Back to letters</a>
    </div>
    <?php if($error): ?><div class="signed-upload__error"><?=hrLetterEsc($error)?></div><?php endif; ?>
    <section class="signed-upload__card">
        <p class="signed-upload__eyebrow">Signed document</p>
        <h2><?=hrLetterEsc($l['subject'])?></h2>
        <p class="signed-upload__meta">Reference <?=hrLetterEsc($l['reference_no'])?> · Recipient <?=hrLetterEsc($l['recipient_name'])?></p>
        <form method="post" enctype="multipart/form-data">
            <?php csrfField(); ?>
            <label class="signed-upload__drop">
                <strong>Choose the signed contract PDF</strong>
                <span>Use a clear, final copy signed by the recipient.</span>
                <input type="file" name="signed_file" accept="application/pdf">
            </label>
            <p class="signed-upload__hint">Accepted format: PDF · Maximum size: 10 MB</p>
            <div class="signed-upload__divider">or draw signature</div>
            <div class="signed-upload__signature">
                <p>Draw the signature below using your mouse, trackpad, or touchscreen.</p>
                <canvas class="signature-pad" id="signature-pad" width="1040" height="340" aria-label="Draw signature"></canvas>
                <input type="hidden" name="recipient_signature" id="recipient-signature">
                <div class="signature-tools"><button type="button" id="clear-signature">Clear signature</button></div>
            </div>
            <div class="signed-upload__actions">
                <a class="btn btn--light" href="hr_letters.php">Cancel</a>
                <button class="btn" type="submit"><i class="fa-solid fa-check"></i> Save signature / PDF</button>
            </div>
        </form>
    </section>
</div>
<script>
const signatureCanvas = document.getElementById('signature-pad');
if (signatureCanvas) {
    const context = signatureCanvas.getContext('2d');
    context.strokeStyle = '#123c5c';
    context.lineWidth = 4;
    context.lineCap = 'round';
    let drawing = false;
    const point = event => {
        const rect = signatureCanvas.getBoundingClientRect();
        const source = event.touches ? event.touches[0] : event;
        return {x: (source.clientX - rect.left) * signatureCanvas.width / rect.width, y: (source.clientY - rect.top) * signatureCanvas.height / rect.height};
    };
    const start = event => { drawing = true; context.beginPath(); const p = point(event); context.moveTo(p.x, p.y); event.preventDefault(); };
    const move = event => { if (!drawing) return; const p = point(event); context.lineTo(p.x, p.y); context.stroke(); event.preventDefault(); };
    const stop = () => { drawing = false; };
    ['mousedown', 'touchstart'].forEach(name => signatureCanvas.addEventListener(name, start, {passive: false}));
    ['mousemove', 'touchmove'].forEach(name => signatureCanvas.addEventListener(name, move, {passive: false}));
    ['mouseup', 'mouseleave', 'touchend'].forEach(name => signatureCanvas.addEventListener(name, stop));
    document.getElementById('clear-signature').addEventListener('click', () => context.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height));
    signatureCanvas.closest('form').addEventListener('submit', () => {
        document.getElementById('recipient-signature').value = signatureCanvas.toDataURL('image/png');
    });
}
</script>
<?php hrLetterFooter();?>
