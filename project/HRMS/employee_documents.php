<?php
require_once 'includes/config.php';
requireHR(); // HR Staff + HR Manager + Admin — HR Manager gets view-only access (see $is_view_only below)

// HR Manager can view/download employee documents pero hindi sila
// makaka-upload/delete — oversight/view-only lang ang role nila dito,
// kaparehas ng ibang parte ng system (see requireHRManagerRole()).
$is_view_only = isHRManagerRole();

$success_message = $_SESSION['flash_success'] ?? '';
$error_message   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$employee_id = dec_id($_GET['employee_id'] ?? '');

// When opened inside the Documents modal on employees.php (an iframe),
// this hides the page's own topbar and "Back to Employees" link, since
// those don't make sense nested inside a popup — the modal already has
// its own header/close button.
$embed = isset($_GET['embed']) && $_GET['embed'] == '1';

$stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();

if (!$employee) {
    http_response_code(404);
    die("
        <h3 style='color:red; font-family:sans-serif;'>Employee not found.</h3>
        <p style='font-family:sans-serif;'><a href='employees.php'>Back to Employees</a></p>
    ");
}

define('MAX_DOCUMENT_SIZE', 5 * 1024 * 1024); // 5MB
$ALLOWED_DOCUMENT_EXT = ['pdf'];

$DOCUMENT_TYPES = [
    'Valid ID',
    'NBI Clearance',
    'Barangay Clearance',
    'Medical Certificate',
    'Employment Contract',
    'SSS Registration',
    'PhilHealth Registration',
    'Pag-IBIG Registration',
    'TIN / BIR Form',
    'Diploma / TOR',
    'Resume',
    'Other',
];

// Document types that only make sense to have one active copy of per
// employee. "Other" is excluded since staff may legitimately need to
// upload several different "Other" documents for the same 201 file.
$SINGLE_INSTANCE_TYPES = array_values(array_diff($DOCUMENT_TYPES, ['Other']));

// =====================================================
// HANDLE UPLOAD (multiple documents in one submit)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'upload_document' && !$is_view_only) {
    requireCSRF();

    // Existing types already on file for this employee — checked against
    // as we go, and appended to as each row in the batch succeeds, so two
    // rows in the same batch can't both claim the same single-instance type.
    $existingTypesStmt = $pdo->prepare("SELECT document_type FROM employee_documents WHERE employee_id = ?");
    $existingTypesStmt->execute([$employee_id]);
    $typesOnFile = $existingTypesStmt->fetchAll(PDO::FETCH_COLUMN);

    $document_types = $_POST['document_type'] ?? [];
    $notes_list      = $_POST['notes'] ?? [];
    $files            = $_FILES['document'] ?? null;

    $rowCount = is_array($document_types) ? count($document_types) : 0;

    $uploaded_count = 0;
    $row_errors     = [];

    if ($rowCount === 0 || !$files || !is_array($files['name'] ?? null)) {
        $error_message = "Please add at least one document before submitting.";
    } else {
        for ($i = 0; $i < $rowCount; $i++) {
            $document_type = trim($document_types[$i] ?? '');
            $notes         = trim($notes_list[$i] ?? '');

            $file_name  = $files['name'][$i]     ?? '';
            $file_tmp   = $files['tmp_name'][$i]  ?? '';
            $file_error = $files['error'][$i]     ?? UPLOAD_ERR_NO_FILE;
            $file_size  = $files['size'][$i]      ?? 0;

            // Ignore completely blank rows (e.g. an "Add Another" row the
            // user added then decided not to fill in).
            if ($document_type === '' && $file_error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $rowLabel = 'Row ' . ($i + 1) . ($document_type !== '' ? " ($document_type)" : '');

            $already_uploaded = $document_type !== '' && in_array($document_type, $SINGLE_INSTANCE_TYPES, true)
                && in_array($document_type, $typesOnFile, true);

            if ($document_type === '' || !in_array($document_type, $DOCUMENT_TYPES, true)) {
                $row_errors[] = "$rowLabel: please select a valid document type.";
                continue;
            }
            if ($already_uploaded) {
                $row_errors[] = "$rowLabel: already uploaded for this employee. Delete the existing one first.";
                continue;
            }
            if ($file_error === UPLOAD_ERR_NO_FILE || $file_name === '') {
                $row_errors[] = "$rowLabel: no file was selected.";
                continue;
            }
            if ($file_error !== UPLOAD_ERR_OK) {
                $row_errors[] = "$rowLabel: there was a problem with the upload.";
                continue;
            }
            if ($file_size > MAX_DOCUMENT_SIZE) {
                $row_errors[] = "$rowLabel: file is too large (max 5MB).";
                continue;
            }

            $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $ALLOWED_DOCUMENT_EXT, true)) {
                $row_errors[] = "$rowLabel: file type not supported (only " . implode(', ', $ALLOWED_DOCUMENT_EXT) . ").";
                continue;
            }

            // Item 13 — extension says .pdf but content isn't really a
            // PDF (magic-byte check, same pattern as apply.php resume
            // upload). Reject before it touches the filesystem.
            if (!isValidPdfSignature($file_tmp)) {
                $row_errors[] = "$rowLabel: that file doesn't look like a valid PDF.";
                continue;
            }
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            if ($fileInfo->file($file_tmp) !== 'application/pdf') {
                $row_errors[] = "$rowLabel: the uploaded content is not a PDF.";
                continue;
            }

            try {
                $stored_filename = 'doc_' . bin2hex(random_bytes(12)) . '.' . $ext;
                $upload_dir  = __DIR__ . '/uploads/employee_documents/';
                $destination = $upload_dir . $stored_filename;

                // Same class of bug as uploads/resumes/ in apply.php — this
                // folder isn't tracked by git and won't exist on a fresh
                // clone/deploy, which made move_uploaded_file() fail silently
                // (no such file or directory). Create it on the fly if missing.
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0755, true);
                }

                if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                    $row_errors[] = "$rowLabel: the server's document upload folder is missing or not writable. Please contact the administrator.";
                    continue;
                }

                if (!move_uploaded_file($file_tmp, $destination)) {
                    $row_errors[] = "$rowLabel: the file could not be saved on the server.";
                    continue;
                }

                $pdo->prepare("
                    INSERT INTO employee_documents (employee_id, document_type, file_name, original_name, uploaded_by, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([
                    $employee_id,
                    $document_type,
                    $stored_filename,
                    $file_name,
                    $_SESSION['user_id'],
                    $notes ?: null,
                ]);

                $uploaded_count++;
                // Lock this type for the rest of the batch too.
                if (in_array($document_type, $SINGLE_INSTANCE_TYPES, true)) {
                    $typesOnFile[] = $document_type;
                }
            } catch (PDOException $e) {
                error_log("Employee document upload error: " . $e->getMessage());
                $row_errors[] = "$rowLabel: the document could not be saved. Please try again.";
            }
        }

        if ($uploaded_count > 0) {
            $success_message = $uploaded_count === 1
                ? "1 document uploaded."
                : "$uploaded_count documents uploaded.";
        }
        if (!empty($row_errors)) {
            $error_message = implode(' ', $row_errors);
        }
        if ($uploaded_count === 0 && empty($row_errors)) {
            $error_message = "Please add at least one document before submitting.";
        }
    }
}

// =====================================================
// HANDLE DELETE
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'delete_document' && !$is_view_only) {
    requireCSRF();
    $document_id = (int) ($_POST['document_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM employee_documents WHERE document_id = ? AND employee_id = ?");
    $stmt->execute([$document_id, $employee_id]);
    $doc = $stmt->fetch();

    if (!$doc) {
        $error_message = "Document not found.";
    } else {
        $filepath = __DIR__ . '/uploads/employee_documents/' . $doc['file_name'];
        if (file_exists($filepath)) {
            @unlink($filepath);
        }
        $pdo->prepare("DELETE FROM employee_documents WHERE document_id = ?")->execute([$document_id]);
        $success_message = "Document removed.";
    }
}

// Post/Redirect/Get — without this, hitting reload/F5 after uploading
// or deleting a document re-sends the same POST and repeats it (e.g.
// re-uploading an "Other" document as a duplicate).
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $_SESSION['flash_success'] = $success_message;
    $_SESSION['flash_error']   = $error_message;
    header('Location: employee_documents.php?employee_id=' . enc_id($employee_id) . ($embed ? '&embed=1' : ''));
    exit;
}

$stmt = $pdo->prepare("
    SELECT ed.*, u.full_name AS uploader_name
    FROM employee_documents ed
    LEFT JOIN users u ON ed.uploaded_by = u.id
    WHERE ed.employee_id = ?
    ORDER BY ed.uploaded_at DESC
");
$stmt->execute([$employee_id]);
$documents = $stmt->fetchAll();

// Mga document type na na-upload na (para sa dropdown: i-disable/markahan
// yung mga type na hindi na dapat piliin ulit).
$uploaded_types = array_unique(array_column($documents, 'document_type'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Documents · <?= clean($employee['first_name'] . ' ' . $employee['last_name']) ?> — HRMS &amp; Payroll</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --green-deep:    #123C5C;
    --text-accent:   var(--green-deep);
    --green-mid:     #1978A8;
    --green-leaf:    #4FC3D1;
    --green-pale:    #E3F2F6;
    --mustard:       #E0A23B;
    --mustard-pale:  #FBEFD8;
    --red-alert:     #C8472D;
    --red-pale:      #FBE7E2;
    --cream:         #F3F6F9;
    --white:         #FFFFFF;
    --ink:           #17222E;
    --ink-soft:      #3D4C5C;
    --line:          #DCE5EC;
    --font-display: 'Sora', sans-serif;
    --font-body: 'Inter', sans-serif;
}

html, body {
    min-height: 100vh;
    font-family: var(--font-body);
    color: var(--ink);
    background: var(--cream);
}

a { text-decoration: none; color: inherit; }

.topbar {
    background: var(--white);
    border-bottom: 1px solid var(--line);
    padding: 16px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.topbar h1 { font-family: var(--font-display); font-size: 20px; color: var(--text-accent); }
.topbar .user-info { font-size: 13.5px; color: var(--ink-soft); display: flex; align-items: center; gap: 16px; }
.user-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--mustard); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 13px; flex-shrink: 0; overflow: hidden; }
.user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.topbar a.logout { color: var(--red-alert); font-weight: 600; font-size: 13.5px; }

.container { max-width: 1000px; margin: 0 auto; padding: 28px 32px; }

.back-link { display:inline-block; margin-bottom:16px; font-size:13.5px; color:var(--green-mid); font-weight:600; }

.profile-strip {
    background: var(--white);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 18px 22px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
.profile-strip h2 { font-family: var(--font-display); color: var(--text-accent); font-size: 19px; }
.profile-strip .meta { font-size: 13px; color: var(--ink-soft); }

.alert { padding: 12px 16px; border-radius: 12px; font-size: 14px; font-weight: 500; margin-bottom: 20px; }
.alert--success { background: #e6f4ea; color: #1a7a3b; }
.alert--error   { background: var(--red-pale); color: var(--red-alert); }

.table-card { background: var(--white); border: 1px solid var(--line); border-radius: 16px; overflow: hidden; margin-bottom: 24px; }
table { width: 100%; border-collapse: collapse; }
th {
    text-align: left; font-size: 12.5px; text-transform: uppercase; letter-spacing: .03em;
    color: var(--ink-soft); background: var(--cream); padding: 12px 16px; border-bottom: 1px solid var(--line);
}
td { padding: 14px 16px; font-size: 14px; border-bottom: 1px solid var(--line); }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #FAFAF7; }

.badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: var(--mustard-pale); color: #8a6414; }
.document-file-name {
    display: block;
    width: fit-content;
    max-width: 100%;
    margin-top: 5px;
    color: var(--ink-soft);
    font-size: 12px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}
.document-file-name:hover { color: var(--green-mid); text-decoration: underline; }
.document-file-type { display: block; width: fit-content; max-width: 100%; }

.empty-state { text-align: center; padding: 40px 24px; color: var(--ink-soft); }

.btn {
    padding: 9px 16px; border: none; border-radius: 10px; font-family: var(--font-body);
    font-size: 13.5px; font-weight: 600; cursor: pointer; transition: background .15s ease;
}
.btn-primary { background: linear-gradient(135deg, var(--green-mid), var(--green-deep)); color: #FFFFFF; box-shadow: 0 2px 8px rgba(18,60,92,0.22); }
.btn-primary:hover { filter: brightness(1.08); box-shadow: 0 5px 16px rgba(18,60,92,0.32); transform: translateY(-2px); }
.btn-primary:active { transform: translateY(0); filter: brightness(0.97); }
.btn-primary:disabled { background: var(--line); color: var(--ink-soft); cursor: not-allowed; }
.btn-secondary { background: var(--white); color: var(--ink); border: 1.5px solid var(--line); }
.btn-secondary:hover { background: var(--cream); border-color: var(--green-leaf); transform: translateY(-2px); box-shadow: 0 3px 10px rgba(18,60,92,0.08); }
.btn-secondary:active { transform: translateY(0); box-shadow: none; }
.btn-danger { background: var(--red-pale); color: var(--red-alert); }
.btn-danger:hover { background: #f5d3ca; transform: translateY(-2px); box-shadow: 0 3px 10px rgba(200,71,45,0.16); }
.btn-danger:active { transform: translateY(0); box-shadow: none; }
.btn-sm { padding: 6px 12px; font-size: 12.5px; }

.upload-card { background: var(--white); border: 1px solid var(--line); border-radius: 16px; padding: 22px; margin-bottom: 24px; }
.upload-card h3 { font-family: var(--font-display); color: var(--text-accent); font-size: 17px; margin-bottom: 14px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-grid .full { grid-column: 1 / -1; }
.field-group label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 6px; color: var(--ink); }
.field-group input, .field-group select, .field-group textarea {
    width: 100%; padding: 10px 12px; border: 1.5px solid var(--line); border-radius: 10px;
    font-family: var(--font-body); font-size: 13.5px;
}
.field-group select option:disabled { color: var(--ink-soft); }
.field-group textarea { resize: vertical; min-height: 60px; }
.field-group .hint { font-size: 11.5px; color: var(--ink-soft); margin-top: 4px; }
.upload-actions { margin-top: 16px; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }

.document-row {
    border: 1.5px dashed var(--line);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 14px;
    position: relative;
}
.document-row .row-head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 10px;
}
.document-row .row-head span { font-size: 12.5px; font-weight: 700; color: var(--text-accent); text-transform: uppercase; letter-spacing: .03em; }
.remove-row-btn {
    background: none; border: none; color: var(--red-alert); font-size: 12.5px; font-weight: 600;
    cursor: pointer; padding: 2px 6px;
}
.remove-row-btn:hover { text-decoration: underline; }
.add-row-btn { background: var(--green-pale); color: var(--text-accent); }
.add-row-btn:hover { background: #d7ecdd; }

@media (max-width: 640px) {
    .form-grid { grid-template-columns: 1fr; }
    .container { padding: 20px 16px; }
    .topbar { padding: 14px 16px; flex-wrap: wrap; gap: 8px; }
}
</style>
</head>
<body>

<?php if (!$embed): ?>
<div class="topbar">
    <h1>HRMS &amp; Payroll — Employee Documents</h1>
    <div class="user-info">
        <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span>
        <span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span>
    </div>
</div>
<?php endif; ?>

<div class="container">

    <?php if (!$embed): ?>
    <a href="employees.php" class="back-link">← Back to Employees</a>
    <?php endif; ?>

    <div class="profile-strip">
        <div>
            <h2><?= clean($employee['last_name'] . ', ' . $employee['first_name']) ?></h2>
            <div class="meta"><?= clean($employee['employee_code']) ?> · <?= clean($employee['position'] ?: '—') ?> · <?= clean($employee['department'] ?: '—') ?></div>
        </div>
    </div>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert--success"><?= clean($success_message) ?></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="alert alert--error"><?= clean($error_message) ?></div>
    <?php endif; ?>

    <?php if ($is_view_only): ?>
    <div class="alert" style="background: var(--green-pale); color: var(--text-accent);">
        View-only access — you can view and download employee documents, but only HR Staff/Admin can upload or delete them.
    </div>
    <?php endif; ?>

    <?php if (!$is_view_only): ?>
    <div class="upload-card">
        <h3>Upload Document(s) (201 File)</h3>
        <form method="POST" enctype="multipart/form-data" id="upload-form">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="upload_document">

            <div id="document-rows">
                <!-- Document rows are injected here; template below -->
            </div>

            <div class="upload-actions">
                <button type="button" id="add-row-btn" class="btn add-row-btn">+ Add Another Document</button>
                <button type="submit" class="btn btn-primary">Submit All</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Uploaded By</th>
                    <th>Uploaded On</th>
                    <?php if (!$is_view_only): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($documents)): ?>
                <tr><td colspan="<?= $is_view_only ? 3 : 4 ?>"><div class="empty-state">No documents have been uploaded for this employee yet.</div></td></tr>
                <?php else: ?>
                <?php foreach ($documents as $doc): ?>
                <tr>
                    <td>
                        <span class="badge document-file-type"><?= clean($doc['document_type']) ?></span>
                        <a class="document-file-name" href="employee_document_download.php?id=<?= enc_id($doc['document_id']) ?>" title="<?= clean($doc['original_name']) ?>"><?= clean($doc['original_name']) ?></a>
                    </td>
                    <td><?= clean($doc['uploader_name'] ?: '—') ?></td>
                    <td><?= clean(date('M d, Y g:i A', strtotime($doc['uploaded_at']))) ?></td>
                    <?php if (!$is_view_only): ?>
                    <td>
                        <form method="POST" data-confirm="Remove this document? This cannot be undone." data-confirm-title="Remove Document" data-confirm-danger="1" data-confirm-ok="Yes, remove" style="display:inline;">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="delete_document">
                            <input type="hidden" name="document_id" value="<?= $doc['document_id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<?php include 'includes/sweetalert.php'; ?>

<?php if (!$is_view_only): ?>
<script>
// Document types the dropdown should mark as "already uploaded" —
// mirrors $SINGLE_INSTANCE_TYPES / $uploaded_types from the PHP side above.
const DOCUMENT_TYPES = <?= json_encode($DOCUMENT_TYPES) ?>;
const ALREADY_TAKEN  = <?= json_encode(array_values(array_intersect($SINGLE_INSTANCE_TYPES, $uploaded_types))) ?>;

let rowCounter = 0;

function buildTypeOptions(selectedValue) {
    let html = '<option value="">-- Select --</option>';
    DOCUMENT_TYPES.forEach(function (type) {
        const isTaken = ALREADY_TAKEN.includes(type);
        const selected = type === selectedValue ? 'selected' : '';
        const disabled = isTaken ? 'disabled' : '';
        const label = isTaken ? type + ' (already uploaded)' : type;
        html += `<option value="${type}" ${selected} ${disabled}>${label}</option>`;
    });
    return html;
}

function addDocumentRow() {
    rowCounter++;
    const container = document.getElementById('document-rows');

    const row = document.createElement('div');
    row.className = 'document-row';
    row.innerHTML = `
        <div class="row-head">
            <span>Document ${rowCounter}</span>
            <button type="button" class="remove-row-btn" title="Remove this document">✕ Remove</button>
        </div>
        <div class="form-grid">
            <div class="field-group">
                <label>Document Type *</label>
                <select name="document_type[]" required>${buildTypeOptions('')}</select>
            </div>
            <div class="field-group">
                <label>File *</label>
                <input type="file" name="document[]" accept=".pdf,application/pdf" required>
            </div>
            <div class="field-group full">
                <label>Notes (optional)</label>
                <textarea name="notes[]" placeholder="Additional details about the document..."></textarea>
            </div>
        </div>
    `;

    row.querySelector('.remove-row-btn').addEventListener('click', function () {
        // Always keep at least one row on the form.
        if (container.querySelectorAll('.document-row').length > 1) {
            row.remove();
        }
    });

    container.appendChild(row);
}

document.getElementById('add-row-btn').addEventListener('click', addDocumentRow);

// Start with one row already visible so the form isn't empty on load.
addDocumentRow();
</script>
<?php endif; ?>

</body>
</html>