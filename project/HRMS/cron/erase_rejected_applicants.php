<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

// Run daily from Windows Task Scheduler or cron with --execute after
// validating the dry-run count. The default invocation never deletes data.
require_once __DIR__ . '/../includes/config.php';

$retentionDays = filter_var(
    env('APPLICANT_REJECTED_RETENTION_DAYS', '365'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 3650]]
);
if ($retentionDays === false) {
    fwrite(STDERR, "APPLICANT_REJECTED_RETENTION_DAYS must be an integer from 1 to 3650.\n");
    exit(2);
}

$limit = 100;
foreach ($argv as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $matches)) {
        $limit = (int) $matches[1];
    }
}
if ($limit < 1 || $limit > 1000) {
    fwrite(STDERR, "Batch limit must be from 1 to 1000.\n");
    exit(2);
}
$execute = in_array('--execute', $argv, true);

function privateApplicantFilePath(string $directory, string $filename): ?string
{
    if ($filename === '' || basename($filename) !== $filename || strpos($filename, "\0") !== false) {
        throw new RuntimeException('Unexpected stored applicant filename; refusing file deletion.');
    }

    $root = realpath($directory);
    if ($root === false) {
        return null;
    }

    $candidate = $root . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($candidate) && !is_link($candidate)) {
        return null;
    }
    if (is_link($candidate)) {
        throw new RuntimeException('Applicant file is a symbolic link; refusing file deletion.');
    }
    $resolved = realpath($candidate);
    if ($resolved === false || strpos($resolved, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($resolved)) {
        throw new RuntimeException('Applicant file resolved outside its managed directory; refusing deletion.');
    }

    return $resolved;
}

$resumeDir = __DIR__ . '/../uploads/resumes';
$applicantDocumentDir = __DIR__ . '/../uploads/applicant_documents';
$failed = 0;
if ($execute) {
    foreach ([$resumeDir, $applicantDocumentDir] as $directory) {
        if (!is_dir($directory)) {
            continue;
        }
        $pendingFiles = glob($directory . DIRECTORY_SEPARATOR . 'retention_pending_*', GLOB_NOSORT);
        if ($pendingFiles === false) {
            error_log("Rejected applicant retention could not scan staged files in {$directory}.");
            $failed++;
            continue;
        }
        foreach ($pendingFiles as $pendingFile) {
            if (is_link($pendingFile) || !is_file($pendingFile) || !unlink($pendingFile)) {
                error_log("Rejected applicant retention could not remove a staged file in {$directory}.");
                $failed++;
            }
        }
    }
}

try {
    $sql = "
        SELECT ja.applicant_id, ja.email, ja.resume_filename
        FROM job_applicants ja
        WHERE ja.status = 'rejected'
          AND ja.updated_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)
          AND NOT EXISTS (
              SELECT 1
              FROM data_subject_requests dsr
              WHERE LOWER(dsr.requester_email) COLLATE utf8mb4_general_ci
                    = LOWER(ja.email) COLLATE utf8mb4_general_ci
                AND dsr.status IN ('pending','identity_verification','identity_verified','in_review','on_hold')
          )
          AND NOT EXISTS (
              SELECT 1 FROM hr_letters hl WHERE hl.applicant_id = ja.applicant_id
          )
        ORDER BY ja.updated_at ASC
        LIMIT {$limit}
    ";
    $applicants = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Rejected applicant retention query failed: ' . $e->getMessage());
    fwrite(STDERR, "Could not query eligible rejected applicants; no records were deleted.\n");
    exit(1);
}

if (!$execute) {
    printf(
        "DRY RUN: %d rejected applicant record(s) are eligible (retention: %d days, batch limit: %d). Use --execute to erase records and uploaded files.\n",
        count($applicants),
        $retentionDays,
        $limit
    );
    exit(0);
}

$deleted = 0;

foreach ($applicants as $candidate) {
    $applicantId = (int) $candidate['applicant_id'];
    $stagedFiles = [];
    $committed = false;
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare("
            SELECT applicant_id, email, resume_filename
            FROM job_applicants
            WHERE applicant_id = ?
              AND status = 'rejected'
              AND updated_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)
            FOR UPDATE
        ");
        $lock->execute([$applicantId]);
        $applicant = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$applicant) {
            $pdo->rollBack();
            continue;
        }

        $hold = $pdo->prepare("
            SELECT request_id
            FROM data_subject_requests
            WHERE LOWER(requester_email) COLLATE utf8mb4_general_ci
                  = LOWER(?) COLLATE utf8mb4_general_ci
              AND status IN ('pending','identity_verification','identity_verified','in_review','on_hold')
            LIMIT 1
            FOR UPDATE
        ");
        $hold->execute([$applicant['email']]);
        if ($hold->fetchColumn()) {
            $pdo->rollBack();
            continue;
        }

        $letterCheck = $pdo->prepare('SELECT id FROM hr_letters WHERE applicant_id = ? LIMIT 1');
        $letterCheck->execute([$applicantId]);
        if ($letterCheck->fetchColumn()) {
            $pdo->rollBack();
            error_log("Rejected applicant retention skipped applicant_id={$applicantId}: linked HR letter requires review.");
            continue;
        }

        $documents = $pdo->prepare('SELECT file_name FROM applicant_documents WHERE applicant_id = ?');
        $documents->execute([$applicantId]);
        $filePaths = [];
        $resumePath = privateApplicantFilePath($resumeDir, (string) $applicant['resume_filename']);
        if ($resumePath !== null) {
            $filePaths[] = $resumePath;
        }
        foreach ($documents->fetchAll(PDO::FETCH_COLUMN) as $filename) {
            $documentPath = privateApplicantFilePath($applicantDocumentDir, (string) $filename);
            if ($documentPath !== null) {
                $filePaths[] = $documentPath;
            }
        }

        foreach ($filePaths as $filePath) {
            $stagedPath = dirname($filePath) . DIRECTORY_SEPARATOR . 'retention_pending_' . bin2hex(random_bytes(12));
            if (!rename($filePath, $stagedPath)) {
                throw new RuntimeException('Could not stage a managed applicant file for erasure.');
            }
            $stagedFiles[$stagedPath] = $filePath;
        }

        $pdo->prepare('DELETE FROM applicant_documents WHERE applicant_id = ?')->execute([$applicantId]);
        $pdo->prepare('DELETE FROM job_applicant_logs WHERE applicant_id = ?')->execute([$applicantId]);
        $delete = $pdo->prepare("
            DELETE FROM job_applicants
            WHERE applicant_id = ?
              AND status = 'rejected'
              AND updated_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)
        ");
        $delete->execute([$applicantId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('Applicant record changed during retention processing.');
        }
        $pdo->commit();
        $committed = true;
        $deleted++;
        $fileCleanupFailed = false;
        foreach (array_keys($stagedFiles) as $stagedPath) {
            if (!unlink($stagedPath)) {
                error_log("Rejected applicant record id={$applicantId} was erased, but a staged private file could not be removed.");
                $fileCleanupFailed = true;
            }
        }
        if ($fileCleanupFailed) {
            $failed++;
            fwrite(STDERR, "Applicant record id={$applicantId} was erased; staged file cleanup will be retried on the next execute run.\n");
            continue;
        }
        printf("Erased rejected applicant record id=%d.\n", $applicantId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!$committed) {
            foreach ($stagedFiles as $stagedPath => $originalPath) {
                if (is_file($stagedPath) && !file_exists($originalPath) && !@rename($stagedPath, $originalPath)) {
                    error_log("Rejected applicant retention could not restore a staged file for applicant_id={$applicantId}.");
                }
            }
        }
        $failed++;
        error_log("Rejected applicant retention failed applicant_id={$applicantId}: " . $e->getMessage());
        fwrite(STDERR, "Could not fully process applicant record id={$applicantId}; see protected server log.\n");
    }
}

printf("Retention run complete: %d erased, %d failed, %d initially eligible.\n", $deleted, $failed, count($applicants));
exit($failed === 0 ? 0 : 1);
