<?php
require_once 'includes/hr_letters.php';
requireHR();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    try {
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['letter_type'] ?? 'official');
        $subject = trim($_POST['subject'] ?? '');
        $body = hrLetterBody($_POST['body_html'] ?? '');
        if ($name === '' || $subject === '' || $body === '') {
            throw new RuntimeException('Template name, subject, and content are required.');
        }
        $pdo->prepare('INSERT INTO hr_letter_templates (name,letter_type,subject,body_html,created_by) VALUES (?,?,?,?,?)')
            ->execute([$name, $type, $subject, $body, $_SESSION['user_id']]);
        header('Location: hr_letter_templates.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$templates = $pdo->query('SELECT * FROM hr_letter_templates ORDER BY created_at DESC')->fetchAll();
hrLetterHeader('Letter Templates');
?>
<style>
.template-page{max-width:960px;margin:0 auto}
.template-page .letter-card{max-width:none}
.template-page .template-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
.template-page .template-toolbar p{margin:0;color:var(--ink-soft);font-size:13px}
.template-page .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:0 15px;border:0;border-radius:10px;background:var(--green-deep);color:#fff;font-weight:700;cursor:pointer;text-decoration:none}
.template-page .btn:hover{background:var(--green-mid);color:#fff}
.template-page .btn--light{background:var(--white);color:var(--text-accent);border:1px solid var(--line)}
.template-page .btn--light:hover{background:var(--green-pale);color:var(--text-accent)}
@media (max-width:640px){.template-page .template-toolbar{align-items:stretch;flex-direction:column}.template-page .template-toolbar .btn{width:100%}}
</style>
<?php if ($error): ?><div class="letter-card" style="color:#b42318"><?= hrLetterEsc($error) ?></div><?php endif; ?>
<div class="template-page"><div class="template-toolbar"><p>Create reusable letter content for contracts, offers, notices, and official HR documents.</p><a class="btn btn--light" href="hr_letters.php"><i class="fa-solid fa-arrow-left"></i> Back to letters</a></div><div class="letter-card"><h2>Create template</h2><form method="post"><?php csrfField(); ?><div class="letter-form-grid"><div><label>Name</label><input name="name" required></div><div><label>Type</label><select name="letter_type"><?php foreach (['contract','appointment','certificate','notice','official'] as $type): ?><option value="<?= $type ?>"><?= ucfirst($type) ?></option><?php endforeach; ?></select></div><div class="full"><label>Subject</label><input name="subject" required></div><div class="full"><label>Content</label><textarea name="body_html" required><p>Date: <?= date('F j, Y') ?></p><p>Dear [Recipient],</p><p></p><p>Sincerely,<br>Human Resources</p></textarea></div></div><button class="btn">Save template</button></form></div>
<div class="letter-card"><h2>Saved templates</h2><?php if (!$templates): ?><p class="letter-muted">No templates yet.</p><?php else: ?><div style="overflow-x:auto"><table class="letter-table"><tr><th>Name</th><th>Type</th><th>Subject</th></tr><?php foreach ($templates as $template): ?><tr><td><?= hrLetterEsc($template['name']) ?></td><td><?= hrLetterEsc($template['letter_type']) ?></td><td><?= hrLetterEsc($template['subject']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?></div></div>
<?php hrLetterFooter(); ?>
