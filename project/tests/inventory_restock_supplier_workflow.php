<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This contract test must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$sources = [];
foreach (['inventory_requests.php', 'finance.php', 'supplier_inventory_approval.php', 'procurement_letter.php', 'signature_image.php', 'HRMS/includes/mailer.php', 'includes/sidebar.php', 'HRMS/includes/hr_sidebar.php', 'HRMS/includes/supervisor_sidebar.php', 'assets/js/scroll-preservation.js'] as $file) {
    $contents = file_get_contents($root . DIRECTORY_SEPARATOR . $file);
    if ($contents === false) {
        throw new RuntimeException("Could not read {$file}.");
    }
    $sources[$file] = $contents;
}

$inventory = $sources['inventory_requests.php'];
$finance = $sources['finance.php'];
$supplierApproval = $sources['supplier_inventory_approval.php'];
$procurementLetter = $sources['procurement_letter.php'];
$signatureImage = $sources['signature_image.php'];
$hrmsMailer = $sources['HRMS/includes/mailer.php'];
$posSidebar = $sources['includes/sidebar.php'];
$hrmsSidebar = $sources['HRMS/includes/hr_sidebar.php'];
$supervisorSidebar = $sources['HRMS/includes/supervisor_sidebar.php'];
$scrollPreservation = $sources['assets/js/scroll-preservation.js'];

$contracts = [
    'Inventory request access and CSRF protection' =>
        strpos($inventory, 'requireInventoryStaff();') !== false
        && strpos($inventory, 'requireCSRF();') !== false,
    'New and validated restock requests require a supplier with an email' =>
        strpos($inventory, '$supplier_id === null') !== false
        && strpos($inventory, 'FILTER_VALIDATE_EMAIL') !== false
        && strpos($inventory, "WHERE id = ? AND status = 'active' LIMIT 1") !== false,
    'Inventory-created single and grouped restock requests go directly to Finance' =>
        strpos($inventory, "VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, 'pending_finance', ?, ?, NOW(), ?)") !== false
        && strpos($inventory, "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_finance', ?, ?, NOW(), ?)") !== false
        && strpos($inventory, "submitted for Finance approval.") !== false,
    'Finance is notified when a restock request reaches Finance approval' =>
        strpos($inventory, "['finance', 'admin']") !== false
        && strpos($inventory, 'finance.php#restock-requests') !== false,
    'Finance is notified when a departmental PO is submitted for approval' =>
        strpos($finance, 'createFinanceApprovedRestockPurchaseOrder(') !== false
        && strpos($finance, '$requestApprovalLink = \'purchase_orders.php?po=\'') !== false,
    'Finance email links to one review page for the letter and supplier e-signature' =>
        strpos($finance, "SET status = 'pending_supplier'") !== false
        && strpos($finance, 'if ($isGroupedProcurement)') !== false
        && strpos($finance, '$sendGroupedRestockApprovalNotice($approvalRequests, $supplier, $requestDepartment, $token)') !== false
        && strpos($finance, 'Review Letter and E-sign Approval') !== false
        && strpos($finance, '$signatureBlock') === false
        && strpos($finance, '$request[\'procurement_letter\']') === false
        && strpos($finance, '$sendRestockSupplierInvite($req, $supplier, $token)') !== false
        && strpos($finance, "'/supplier_inventory_approval.php?request_id='") !== false,
    'Grouped lines for the same supplier appear and are decided as one Finance request' =>
        strpos($finance, '$pending_finance_groups[$groupKey][\'items\'][] = $request;') !== false
        && strpos($finance, 'WHERE request_group_id = ? AND supplier_id = ? AND status = \'pending_finance\'') !== false
        && strpos($finance, 'WHERE request_group_id = ? AND status = \'pending_finance\'') !== false
        && strpos($finance, 'approvedLineCount . " restock item(s) approved by Finance, linked to "') !== false,
    'Finance shows one prominent combined formality letter per request group' =>
        strpos($finance, 'class="finance-formality-letter"') !== false
        && strpos($finance, 'One combined letter for all') !== false
        && strpos($finance, 'View Formality Letter</a>') !== false
        && strpos($finance, 'procurement_letter.php?request_id=<?= enc_id($item[\'request_id\']) ?>') === false,
    'Grouped procurement letter includes every request line and combined estimated total' =>
        strpos($procurementLetter, 'WHERE ir.request_group_id = ? ORDER BY ir.request_id ASC') !== false
        && strpos($procurementLetter, '$requestItems = $groupStmt->fetchAll();') !== false
        && strpos($procurementLetter, '$totalAmount += (float) $item[\'estimated_cost\'];') !== false
        && strpos($procurementLetter, 'foreach ($requestItems as $item)') !== false,
    'Supplier approval emails use Finance branding and clear purchase-order status wording' =>
        strpos($finance, '<div style="font-size:20px;font-weight:700;">Finance Department</div>') !== false
        && strpos($finance, 'contact Finance for questions about this approval') !== false
        && strpos($finance, "'Finance Department'") !== false
        && strpos($supplierApproval, 'Finance-approved Purchase Order') !== false
        && strpos($hrmsMailer, '$mail->CharSet   = PHPMailer::CHARSET_UTF8;') !== false,
    'Finance can resend failed or expired supplier invitations' =>
        strpos($finance, "finance_resend_supplier_invite") !== false
        && strpos($finance, 'Resend Invitation') !== false,
    'Supplier approval validates token, expiry, signature and status' =>
        strpos($supplierApproval, 'dec_id($requestIdToken)') !== false
        && strpos($supplierApproval, 'hash(\'sha256\', $token)') !== false
        && strpos($supplierApproval, "supplier_approval_expires_at") !== false
        && strpos($supplierApproval, 'hasDrawnPngSignature($signature)') !== false
        && strpos($supplierApproval, "status = 'approved', supplier_signature = ?") !== false
        && strpos($supplierApproval, "SET status = 'ordered', ordered_at = NOW()") !== false
        && strpos($supplierApproval, 'supplier_approval_confirmed') !== false
        && strpos($supplierApproval, 'FOR UPDATE') !== false
        && strpos($supplierApproval, '<strong>Inventory Staff signature</strong>') !== false
        && strpos($supplierApproval, '<strong>Finance approval signature</strong>') !== false
        && strpos($supplierApproval, '<strong>Supplier e-signature — draw to confirm approval</strong>') !== false,
    'Receiving is limited to a signed ready-to-receive request' =>
        strpos($inventory, '$req[\'status\'] !== \'ready_to_receive\'') !== false
        && strpos($inventory, "AND status = 'ready_to_receive' AND supplier_signature IS NOT NULL") !== false
        && strpos($inventory, "'approved'], true") === false,
    'Inventory staff and admin are notified after supplier signature' =>
        strpos($supplierApproval, "role IN ('inventory_staff', 'admin')") !== false
        && strpos($supplierApproval, "purchase_orders.php?status=ordered") !== false
        && strpos($inventory, 'Supplier Approved — Purchase Orders') !== false
        && strpos($inventory, 'View Full Formality Letter') !== false
        && strpos($supplierApproval, 'signature_image.php?request_id=') !== false
        && strpos($procurementLetter, 'signature_image.php?request_id=') !== false
        && strpos($finance, "'/signature_image.php?request_id='") === false
        && strpos($procurementLetter, "'finance_signature_at'") !== false
        && strpos($procurementLetter, "'supplier_signature_at'") !== false,
    'Supplier-approved restock lines track the automatically created Purchase Order' =>
        strpos($inventory, '$status_filter === \'approved\'') !== false
        && strpos($inventory, "'items' => []") !== false
        && strpos($inventory, 'Track Purchase Order') !== false
        && strpos($inventory, 'Restock Purchase Orders are now created automatically') !== false
        && strpos($inventory, 'Create Departmental POs for Selected Supplier-Approved Requests') === false
        && strpos($inventory, 'count($requestDisplayRows)') !== false,
    'Shared scroll preservation covers POS, HRMS, supervisor, navigation, form actions and redirects' =>
        strpos($posSidebar, '/assets/js/scroll-preservation.js') !== false
        && strpos($hrmsSidebar, '/assets/js/scroll-preservation.js') !== false
        && strpos($supervisorSidebar, '/assets/js/scroll-preservation.js') !== false
        && strpos($scrollPreservation, 'history.scrollRestoration = \'manual\'') !== false
        && strpos($scrollPreservation, 'if (!event.isTrusted) return;') !== false
        && strpos($scrollPreservation, 'document.addEventListener(\'click\'') !== false
        && strpos($scrollPreservation, 'document.addEventListener(\'submit\'') !== false
        && strpos($scrollPreservation, 'window.addEventListener(\'pagehide\'') !== false
        && strpos($scrollPreservation, 'window.addEventListener(\'pageshow\'') !== false
        && strpos($scrollPreservation, 'sessionStorage.setItem(storageKey') !== false
        && strpos($scrollPreservation, 'sessionStorage.removeItem(storageKey)') !== false
        && strpos($scrollPreservation, 'window.scrollTo(Number(position.x), Number(position.y))') !== false
        && strpos($inventory, 'inventory-requests-scroll:') === false,
    'Signature images are served as validated PNGs to authorized staff or the signed supplier link' =>
        strpos($signatureImage, "Content-Type: image/png") !== false
        && strpos($signatureImage, 'hasDrawnPngSignature($dataUrl)') !== false
        && strpos($signatureImage, 'hash_equals') !== false
        && strpos($signatureImage, "['admin', 'inventory_staff', 'finance']") !== false
        && strpos($signatureImage, 'u.procurement_signature_reference AS requester_signature_reference') !== false
        && strpos($signatureImage, "Cache-Control: private, no-store") !== false,
];

$failures = [];
foreach ($contracts as $label => $passed) {
    if (!$passed) {
        $failures[] = $label;
    }
}

if ($failures) {
    fwrite(STDERR, "Restock supplier workflow contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Restock supplier workflow contract passed: finance approval, supplier signing, resend, notifications and signed-only receipt are present.\n";
