<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
chdir(dirname(__DIR__));
$pipes = [];
$process = proc_open(['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMRD'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0) {
    fwrite(STDERR, "Documentation gate requires a Git index.\n");
    exit(1);
}
$paths = array_values(array_filter(explode("\n", str_replace("\r", '', trim($output)))));
$code = array_filter($paths, static fn (string $path): bool => (bool) preg_match('~^(?:app|routes|resources|config|database/migrations|public|tests|bin|project)/.*\.(?:php|blade\.php|js|css|sql)$|^(?:composer\.(?:json|lock)|package(?:-lock)?\.json|\.htaccess)$~', $path));
if ($code === []) {
    echo "Documentation gate: no implementation paths staged.\n";
    exit;
}
$required = ['CHANGELOG.md', 'docs/PROGRESS.md'];
foreach ($code as $path) {
    if (str_starts_with($path, 'routes/')) {
        $required[] = 'docs/API.md';
    }
    if (str_starts_with($path, 'database/migrations/')) {
        $required[] = 'docs/DATABASE.md';
    }
    if (preg_match('~app/(?:Http/Middleware|Http/Controllers/Auth|Services/Auth)/|app/(?:Services/StaffAuthentication|Models/(?:StaffUser|PosUser|HrUser))\.php|^config/auth\.php$~', $path)) {
        $required[] = 'docs/SECURITY.md';
        $required[] = 'docs/PRIVACY.md';
    }
    if (str_starts_with($path, 'resources/')) {
        $required[] = 'docs/UI.md';
    }
    if (str_starts_with($path, 'tests/')) {
        $required[] = 'docs/TESTING.md';
    }
    if (in_array($path, ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', '.htaccess'], true)) {
        $required[] = 'docs/INSTALL.md';
    }
}
$missing = array_values(array_diff(array_unique($required), $paths));
if ($missing !== []) {
    fwrite(STDERR, 'Documentation gate rejected this staged change. Update and stage: '.implode(', ', $missing)."\n");
    exit(1);
}
echo "Documentation gate passed. Review still must establish accuracy.\n";
