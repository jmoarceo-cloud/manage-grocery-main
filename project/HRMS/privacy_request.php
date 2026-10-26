<?php
require_once __DIR__ . '/includes/config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$errorMessage = '';
$requestTypes = [
    'access' => 'Access my personal information',
    'correction' => 'Correct my personal information',
    'deletion' => 'Request deletion',
    'portability' => 'Request a copy in a portable format',
    'restriction' => 'Restrict processing',
    'objection' => 'Object to processing',
    'other' => 'Other privacy request',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $nameInput = $_POST['requester_name'] ?? null;
    $emailInput = $_POST['requester_email'] ?? null;
    $typeInput = $_POST['request_type'] ?? null;
    $detailsInput = $_POST['request_details'] ?? null;

    if (!is_string($nameInput)) {
        $errorMessage = 'Enter your name.';
    } elseif (!is_string($emailInput)) {
        $errorMessage = 'Enter a valid email address.';
    } elseif (!is_string($typeInput)) {
        $errorMessage = 'Select a valid request type.';
    } elseif (!is_string($detailsInput)) {
        $errorMessage = 'Describe your request.';
    } else {
        $name = trim($nameInput);
        $email = trim($emailInput);
        $type = trim($typeInput);
        $details = trim($detailsInput);

        if ($name === '' || mb_strlen($name) > 150) {
            $errorMessage = 'Enter your name (up to 150 characters).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $errorMessage = 'Enter a valid email address.';
        } elseif (!isset($requestTypes[$type])) {
            $errorMessage = 'Select a valid request type.';
        } elseif ($details === '' || mb_strlen($details) > 4000) {
            $errorMessage = 'Describe your request (up to 4,000 characters).';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO data_subject_requests
                        (requester_name, requester_email, request_type, request_details)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$name, $email, $type, $details]);
                $_SESSION['privacy_request_submitted'] = true;
                header('Location: privacy_request.php?submitted=1');
                exit;
            } catch (PDOException $e) {
                error_log('Privacy request submission failed: ' . $e->getMessage());
                $errorMessage = 'Your request could not be recorded. Please try again later.';
            }
        }
    }
}

$submitted = isset($_GET['submitted']) && ($_SESSION['privacy_request_submitted'] ?? false) === true;
unset($_SESSION['privacy_request_submitted']);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Request | HCI GroceryOne</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/theme.css') ?>">
    <link rel="stylesheet" href="../theme-toggle.css">
    <script>
        (function () {
            try {
                var savedTheme = localStorage.getItem('manage-grocery-theme');
                document.documentElement.dataset.theme = savedTheme ||
                    (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            } catch (error) {
                document.documentElement.dataset.theme = 'light';
            }
        }());
    </script>
</head>
<body class="privacy-intake-page">
<main class="privacy-intake-card">
    <div class="privacy-intake-actions">
        <a href="../index.php">HCI GroceryOne</a>
        <button class="theme-toggle" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
            <svg class="moon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M20.4 15.4A8.5 8.5 0 0 1 8.6 3.6 9 9 0 1 0 20.4 15.4Z" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <svg class="sun-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4" stroke-linecap="round"/>
            </svg>
            <span>Dark</span>
        </button>
    </div>
    <h1>Privacy request</h1>
    <p class="muted">Use this form to ask about personal information held in connection with recruitment or employment.</p>
    <p class="privacy-note">We may contact you at the address provided to verify your identity before acting on a request or disclosing information. Do not include passwords, government ID numbers, or other unnecessary sensitive details here. Submitting a request does not guarantee that every requested action can be completed; applicable obligations and retention requirements will be reviewed.</p>
    <?php if ($submitted): ?>
        <p class="notice" role="status">Your request has been received for review. Keep an eye on the email address you provided in case we need to verify your identity.</p>
    <?php else: ?>
        <?php if ($errorMessage !== ''): ?><p class="notice notice--error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" action="privacy_request.php">
            <?php csrfField(); ?>
            <label for="requester_name">Full name</label>
            <input id="requester_name" name="requester_name" maxlength="150" autocomplete="name" required value="<?= htmlspecialchars(is_string($_POST['requester_name'] ?? null) ? $_POST['requester_name'] : '', ENT_QUOTES, 'UTF-8') ?>">
            <label for="requester_email">Email address</label>
            <input id="requester_email" type="email" name="requester_email" maxlength="254" autocomplete="email" required value="<?= htmlspecialchars(is_string($_POST['requester_email'] ?? null) ? $_POST['requester_email'] : '', ENT_QUOTES, 'UTF-8') ?>">
            <label for="request_type">Request type</label>
            <select id="request_type" name="request_type" required>
                <option value="">Select a request</option>
                <?php foreach ($requestTypes as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= (($_POST['request_type'] ?? null) === $value) ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <label for="request_details">Request details</label>
            <textarea id="request_details" name="request_details" maxlength="4000" required><?= htmlspecialchars(is_string($_POST['request_details'] ?? null) ? $_POST['request_details'] : '', ENT_QUOTES, 'UTF-8') ?></textarea>
            <button type="submit">Submit privacy request</button>
        </form>
    <?php endif; ?>
</main>
<script>
    (function () {
        var toggle = document.getElementById('themeToggle');
        var label = toggle ? toggle.querySelector('span') : null;
        if (!toggle) return;

        function updateControl() {
            var isDark = document.documentElement.dataset.theme === 'dark';
            if (label) label.textContent = isDark ? 'Light' : 'Dark';
            toggle.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
            toggle.setAttribute('aria-pressed', String(isDark));
        }

        updateControl();
        toggle.addEventListener('click', function () {
            var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = next;
            try {
                localStorage.setItem('manage-grocery-theme', next);
            } catch (error) {
                console.error('Could not save the selected theme.', error);
            }
            updateControl();
        });
    }());
</script>
</body>
</html>
