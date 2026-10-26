<?php
require_once 'includes/hr_letters.php';

$token = trim((string) ($_GET['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
    http_response_code(404);
    exit('This letter link is invalid or expired.');
}

$stmt = $pdo->prepare("SELECT * FROM hr_letters WHERE access_token = ? AND status IN ('sent', 'approved', 'accepted', 'declined')");
$stmt->execute([$token]);
$letter = $stmt->fetch();
if (!$letter) {
    http_response_code(404);
    exit('This letter link is invalid or expired.');
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['accept', 'decline'], true) && in_array($letter['status'], ['sent', 'approved'], true)) {
        $note = trim((string) ($_POST['note'] ?? ''));
        $signature = trim((string) ($_POST['recipient_signature'] ?? ''));

        if (strlen($note) > 500) {
            $error = 'Your note cannot exceed 500 characters.';
        } elseif ($action === 'accept') {
            if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $signature)) {
                $error = 'Please draw your electronic signature before accepting the contract.';
            } else {
                $rawSignature = base64_decode(substr($signature, 22), true);
                if ($rawSignature === false || strlen($rawSignature) > 500000 || substr($rawSignature, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                    $error = 'The signature image is invalid or too large. Please clear it and sign again.';
                }
            }
        }

        if ($error === '') {
            $nextStatus = $action === 'accept' ? 'accepted' : 'declined';
            $signatureValue = $action === 'accept' ? $signature : null;
            $signatureTimestamp = $action === 'accept' ? $signature : '';
            $update = $pdo->prepare("
                UPDATE hr_letters
                SET status = ?, responded_at = NOW(), response_note = ?, recipient_signature = ?,
                    recipient_signature_at = CASE WHEN ? <> '' THEN NOW() ELSE NULL END
                WHERE id = ? AND status IN ('sent', 'approved')
            ");
            $update->execute([$nextStatus, $note ?: null, $signatureValue, $signatureTimestamp, $letter['id']]);
            if ($update->rowCount() === 1) {
                hrLetterLog($pdo, $letter['id'], $action, $note);
                $message = $action === 'accept'
                    ? 'Thank you. Your contract acceptance and electronic signature have been submitted to Human Resources.'
                    : 'Your response has been recorded. Human Resources will follow up if needed.';
                $letter['status'] = $nextStatus;
            } else {
                $error = 'This letter has already received a response.';
            }
        }
    }
}

$contractDetails = hrLetterContractDetails($letter);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= hrLetterEsc($letter['subject']) ?></title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;padding:30px;background:#f3f6f9;color:#17222e;font:15px/1.65 Arial,sans-serif}
        .card{max-width:800px;margin:auto;padding:clamp(22px,5vw,42px);background:#fff;border:1px solid #dce5ec;border-radius:16px;box-shadow:0 12px 36px rgba(15,23,42,.08)}
        h1{margin:0 0 18px;color:#123c5c;font-size:clamp(23px,4vw,30px);line-height:1.25}
        .letter-body{margin-top:22px}
        .contract-details{border-collapse:collapse;width:100%;margin:18px 0;background:#f3f6f9;border:1px solid #dce5ec}
        .contract-details td{padding:10px 12px;border-bottom:1px solid #dce5ec}
        .contract-details tr:last-child td{border-bottom:0}
        .contract-details td:first-child{width:35%;color:#526273}
        .reference{margin:20px 0 0;color:#64748b;font-size:12px}
        .signature-label{display:block;margin:18px 0 8px;font-weight:700}
        canvas{display:block;width:100%;max-width:600px;height:170px;border:1px dashed #94a3b8;border-radius:9px;background:#fff;touch-action:none}
        textarea{display:block;width:100%;min-height:76px;margin:16px 0;padding:10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}
        button{min-height:42px;padding:10px 16px;border:0;border-radius:8px;background:#123c5c;color:#fff;font-weight:700;cursor:pointer}
        button.secondary{background:#e2e8f0;color:#17222e}
        .actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}
        .message{padding:13px 15px;border-radius:9px;margin:0 0 18px;background:#dcfce7;color:#166534}
        .error{padding:13px 15px;border-radius:9px;margin:0 0 18px;background:#fee2e2;color:#991b1b}
        .help{color:#64748b;font-size:13px}
        @media(max-width:600px){body{padding:14px}.card{padding:20px}.actions button{flex:1}}
    </style>
</head>
<body>
<article class="card">
    <h1><?= hrLetterEsc($letter['subject']) ?></h1>
    <?php if ($message): ?><p class="message" role="status"><?= hrLetterEsc($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error" role="alert"><?= hrLetterEsc($error) ?></p><?php endif; ?>
    <?php if ($contractDetails !== ''): ?>
        <?= $contractDetails ?>
    <?php endif; ?>
    <div class="letter-body"><?= hrLetterBody($letter['body_html']) ?></div>
    <p class="reference"><strong>Reference:</strong> <?= hrLetterEsc($letter['reference_no']) ?></p>

    <?php if (in_array($letter['status'], ['sent', 'approved'], true)): ?>
        <form method="post" id="recipientResponseForm">
            <?php csrfField(); ?>
            <label class="signature-label" for="signature-pad">
                <?= $letter['letter_type'] === 'contract' ? 'Draw your electronic signature to accept this contract' : 'Draw your signature to accept this letter' ?>
            </label>
            <p class="help">Review the complete letter and contract dates before signing. Your signature will be submitted securely to Human Resources.</p>
            <canvas id="signature-pad" width="1200" height="340" aria-label="Signature drawing area"></canvas>
            <input type="hidden" name="recipient_signature" id="recipient-signature">
            <p class="error" id="signature-error" role="alert" hidden>Please draw your electronic signature before accepting.</p>
            <textarea name="note" maxlength="500" placeholder="Optional note to Human Resources"></textarea>
            <div class="actions">
                <button type="button" class="secondary" id="clear-signature">Clear signature</button>
                <button type="submit" name="action" value="accept">Accept and sign</button>
                <button type="submit" name="action" value="decline" class="secondary" formnovalidate>Decline</button>
            </div>
        </form>
    <?php elseif ($letter['status'] === 'accepted'): ?>
        <p class="message" role="status">This letter has been accepted and signed. Thank you.</p>
    <?php else: ?>
        <p class="message" role="status">This letter has already received a response. Human Resources will follow up if needed.</p>
    <?php endif; ?>
</article>
<script>
(function () {
    const canvas = document.getElementById('signature-pad');
    const form = document.getElementById('recipientResponseForm');
    if (!canvas || !form) return;
    const context = canvas.getContext('2d');
    const signatureError = document.getElementById('signature-error');
    context.strokeStyle = '#123c5c';
    context.lineWidth = 4;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    let drawing = false;
    let hasInk = false;

    function point(event) {
        const rect = canvas.getBoundingClientRect();
        const source = event.touches ? event.touches[0] : event;
        return {
            x: (source.clientX - rect.left) * canvas.width / rect.width,
            y: (source.clientY - rect.top) * canvas.height / rect.height
        };
    }
    function start(event) {
        drawing = true;
        hasInk = true;
        signatureError.hidden = true;
        const position = point(event);
        context.beginPath();
        context.moveTo(position.x, position.y);
        event.preventDefault();
    }
    function move(event) {
        if (!drawing) return;
        const position = point(event);
        context.lineTo(position.x, position.y);
        context.stroke();
        event.preventDefault();
    }
    function stop() { drawing = false; }

    canvas.addEventListener('pointerdown', start);
    canvas.addEventListener('pointermove', move);
    window.addEventListener('pointerup', stop);
    document.getElementById('clear-signature').addEventListener('click', function () {
        context.clearRect(0, 0, canvas.width, canvas.height);
        hasInk = false;
    });
    form.addEventListener('submit', function (event) {
        if (event.submitter && event.submitter.value === 'accept') {
            if (!hasInk) {
                event.preventDefault();
                signatureError.hidden = false;
                canvas.focus();
                return;
            }
            document.getElementById('recipient-signature').value = canvas.toDataURL('image/png');
        }
    });
})();
</script>
</body>
</html>
