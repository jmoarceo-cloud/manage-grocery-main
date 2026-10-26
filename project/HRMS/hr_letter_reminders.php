<?php
require_once 'includes/hr_letters.php';
requireHR();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $count = 0;
    $stmt = $pdo->query("SELECT * FROM hr_letters WHERE status='sent' AND recipient_email IS NOT NULL");
    foreach ($stmt->fetchAll() as $letter) {
        $last = $pdo->prepare("SELECT sent_at FROM hr_letter_email_log WHERE letter_id=? ORDER BY id DESC LIMIT 1");
        $last->execute([$letter['id']]);
        $lastSent = $last->fetchColumn();
        if ($lastSent && strtotime($lastSent) > time() - 604800) continue;
        [$ok] = hrLetterEmail($pdo, $letter);
        if ($ok) { hrLetterLog($pdo, $letter['id'], 'reminder_sent'); $count++; }
    }
    $message = $count . ' reminder email(s) sent.';
}
hrLetterHeader('Letter Reminders');
?>
<style>
.reminder-page{width:100%;max-width:1440px;margin:0 auto}
.reminder-page .reminder-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
.reminder-page .reminder-toolbar p{margin:0;color:var(--ink-soft);font-size:13px}
.reminder-page .letter-card{max-width:none;display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:18px 32px;padding:24px 28px}
.reminder-copy{min-width:0}
.reminder-page .letter-card h2{margin:0 0 8px}
.reminder-page .reminder-note{max-width:850px;margin:0}
.reminder-note{color:var(--ink-soft);font-size:13px;line-height:1.6}
.reminder-success{grid-column:1/-1;color:#166534;background:#dcfce7;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;font-size:13px;font-weight:600}
.reminder-page .letter-card form{margin:0}
.reminder-page .letter-card form .btn{white-space:nowrap}
.reminder-page .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:0 15px;border-radius:10px;background:var(--green-deep);color:#fff;font-weight:700;cursor:pointer;text-decoration:none;border:0}
.reminder-page .btn:hover{background:var(--green-mid);color:#fff}
.reminder-page .btn--light{background:var(--white);color:var(--text-accent);border:1px solid var(--line)}
.reminder-page .btn--light:hover{background:var(--green-pale);color:var(--text-accent)}
@media (max-width:640px){.reminder-page .reminder-toolbar{align-items:stretch;flex-direction:column}.reminder-page .reminder-toolbar .btn{width:100%}.reminder-page .letter-card{grid-template-columns:1fr;padding:20px}.reminder-page .letter-card form .btn{width:100%}}
</style>
<div class="reminder-page">
    <div class="reminder-toolbar">
        <p>Keep recipients updated without sending duplicate reminders.</p>
        <a class="btn btn--light" href="hr_letters.php"><i class="fa-solid fa-arrow-left"></i> Back to letters</a>
    </div>
    <section class="letter-card" aria-labelledby="reminderTitle">
        <?php if ($message): ?><p class="reminder-success"><?= hrLetterEsc($message) ?></p><?php endif; ?>
        <div class="reminder-copy">
            <h2 id="reminderTitle">Send outstanding reminders</h2>
            <p class="reminder-note">Letters already sent and not emailed again within seven days are eligible for a reminder. Processing is safe to repeat; recent reminders are skipped automatically.</p>
        </div>
        <form method="post">
            <?php csrfField(); ?>
            <button class="btn"><i class="fa-regular fa-paper-plane"></i> Process reminders now</button>
        </form>
    </section>
</div>
<?php hrLetterFooter(); ?>
