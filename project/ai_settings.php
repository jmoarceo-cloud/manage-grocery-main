<?php
require_once __DIR__ . '/includes/config.php';
requireAiAdmin();
require_once __DIR__ . '/includes/ai.php';
ai_pdo($pdo);
$__fromHrms = false;
$__navBase = '';
$activeNav = '';
$__full_name = trim((string) ($_SESSION['full_name'] ?? ''));
$__initial = $__full_name !== '' ? strtoupper(substr($__full_name, 0, 1)) : '?';
$__avatarFile = $_SESSION['profile_picture'] ?? null;
$__icon_settings = '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="2.6" stroke="currentColor" stroke-width="1.5"/><path d="M10 3v1.6M10 15.4V17M17 10h-1.6M4.6 10H3M14.9 5.1l-1.1 1.1M6.2 13.7l-1.1 1.1M14.9 14.9l-1.1-1.1M6.2 6.3L5.1 5.1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'save') {
        $model = trim((string) ($_POST['model'] ?? ''));
        $cap = max(1, min(10000, (int) ($_POST['daily_call_cap'] ?? 100)));
        $searchCap = max(0, min(1000, (int) ($_POST['daily_search_cap'] ?? 20)));
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        if ($model !== '' && ($_SESSION['ai_tested_model'] ?? '') !== $model) {
            $error = 'Test the selected AI model successfully before saving it.';
        } else {
        $stmt = $pdo->prepare('UPDATE ai_settings SET model = ?, daily_call_cap = ?, daily_search_cap = ?, enabled = ? WHERE id = 1');
        $stmt->execute([$model !== '' ? $model : null, $cap, $searchCap, $enabled]);
        $message = 'AI settings saved.';
        }
    } elseif ($action === 'test') {
        $testModel = trim((string) ($_POST['model'] ?? ''));
        if ($testModel !== '') {
            $pdo->prepare('UPDATE ai_settings SET model = ? WHERE id = 1')->execute([$testModel]);
        }
        $result = ai_gemini_text('Reply with the single word: OK', ['task' => 'connection_test']);
        $message = $result['ok'] ? 'AI provider replied: ' . ($result['raw'] ?? 'OK') : '';
        $error = $result['error'] ?? '';
        if ($result['ok'] && $testModel !== '') {
            $_SESSION['ai_tested_model'] = $testModel;
        }
    }
}
$settings = ai_settings();
$modelsResult = ai_list_models();
$models = $modelsResult['ok'] ? ($modelsResult['data'] ?? []) : [];
$modelNotice = $modelsResult['error'] ?? '';
$logs = $pdo->query('SELECT created_at, task, model, status, input_tokens, output_tokens FROM ai_usage_log ORDER BY id DESC LIMIT 20')->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AI Settings</title><link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>"><style>body{overflow-x:hidden}.ai-settings-actions{align-items:center;flex-wrap:wrap}.ai-settings-actions .ai-save-action{order:2}.ai-settings-actions .ai-test-action{order:1}.panel-body--table{overflow-x:auto}.ai-notice{margin:10px 0;padding:10px 12px;border-radius:10px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;font-size:13px}@media (max-width:560px){.ai-settings-actions .action-btn{width:100%}}</style><!-- admin shell --></head><body><div class="app-shell"><?php $active = "ai_settings"; include 'includes/sidebar.php'; ?><div class="main-area"><header class="topbar"><div class="topbar-icon"><?= $__icon_settings ?></div><h1>AI Settings</h1><div class="topbar-user"><span class="user-avatar"><?php if (!empty($__avatarFile)): ?><img src="uploads/avatars/<?= clean($__avatarFile) ?>" alt=""><?php else: ?><?= clean($__initial) ?><?php endif; ?></span><span class="user-name"><?= clean($__full_name !== '' ? $__full_name : 'Administrator') ?></span></div></header><main class="content"><div class="page-header"><h1>AI Provider Settings</h1><p class="page-subtitle">Configure the label-reading service and review recent usage.</p></div><section class="panel"><div class="panel-body"><p>Provider: <strong><?= clean((string) env('AI_PROVIDER', 'gemini')) ?></strong></p><p>API key: <strong><?= ai_api_key() !== '' ? 'configured' : 'not set' ?></strong></p><?php if ($modelNotice): ?><p class="ai-notice"><?= clean($modelNotice) ?></p><?php endif; ?><?php if ($message): ?><p class="ok"><?= clean($message) ?></p><?php endif; ?><?php if ($error): ?><p class="error"><?= clean($error) ?></p><?php endif; ?><form id="aiSettingsForm" method="post" action="<?= $__fromHrms ? 'ai_settings.php?shell=hrms' : 'ai_settings.php' ?>"><?php csrfField(); ?><input type="hidden" name="action" value="save"><div class="field-group"><label class="field-label">Model</label><select class="field-input" name="model"><option value="">Select a model</option><?php foreach ($models as $model): ?><option value="<?= clean($model['name']) ?>" <?= $settings['model'] === $model['name'] ? 'selected' : '' ?>><?= clean($model['displayName'] ?: $model['name']) ?></option><?php endforeach; ?></select></div><div class="field-group"><label class="field-label">Daily call cap</label><input class="field-input" type="number" name="daily_call_cap" min="1" max="10000" value="<?= (int) $settings['daily_call_cap'] ?>"></div><div class="field-group"><label class="field-label">Daily web-search cap</label><input class="field-input" type="number" name="daily_search_cap" min="0" max="1000" value="<?= (int) ($settings['daily_search_cap'] ?? 20) ?>"></div><label class="field-label"><input type="checkbox" name="enabled" <?= (int) $settings['enabled'] === 1 ? 'checked' : '' ?>> Enable AI</label></form><div class="form-actions ai-settings-actions"><button class="action-btn ai-save-action" type="submit" form="aiSettingsForm">Save settings</button><span class="ai-test-action"><button class="action-btn action-btn--secondary" type="submit" form="aiSettingsForm" name="action" value="test">Test connection</button></span></div></div></section><section class="panel"><div class="panel-header"><h2>Recent calls</h2></div><div class="panel-body panel-body--table ai-settings-table-panel"><table class="data-table"><tr><th>Time</th><th>Task</th><th>Model</th><th>Status</th><th>Tokens</th></tr><?php foreach ($logs as $log): ?><tr><td><?= clean($log['created_at']) ?></td><td><?= clean($log['task']) ?></td><td><?= clean($log['model'] ?? '') ?></td><td><span class="badge <?= $log['status'] === 'ok' ? 'badge--success' : ($log['status'] === 'cached' ? 'badge--neutral' : 'badge--danger') ?>"><?= clean($log['status']) ?></span></td><td><?= (int) $log['input_tokens'] ?> / <?= (int) $log['output_tokens'] ?></td></tr><?php endforeach; ?></table></div></section></main></div></div></body></html>
