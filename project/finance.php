<?php
// ============================================================
// finance.php — Dedicated Finance module (Admin + Finance role).
//
// Home base for:
//   1. Profit & Business Cash Position — moved here from reports.php
//      (see getProfitForPeriod() / getBusinessCashPosition() /
//      recordCapitalTransaction() in includes/config.php).
//   2. Restock Requests Awaiting Finance Approval — the third stage
//      of the restock workflow (v2, confirmed Aug 2026 — see the
//      flow diagram): Supervisor submits -> Inventory Staff
//      validates (checks actual stock vs. the requested quantity,
//      sets the estimated cost) -> lands here as 'pending_finance'
//      -> Finance approves and signs (creating the linked, Finance-approved
//      Purchase Order) -> 'pending_supplier' -> supplier e-signs -> the PO
//      becomes 'ordered' and appears in Orders for Admin receiving.
// ============================================================

require_once 'includes/config.php';
require_once __DIR__ . '/includes/payroll_release.php';
requireFinance();

$financeEmailTemplate = static function (string $title, string $bodyHtml): string {
    return '<!doctype html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:24px;background:#F3F6F9;font-family:Arial,sans-serif;color:#17222E;">'
        . '<div style="max-width:650px;margin:0 auto;background:#fff;border:1px solid #DCE5EC;border-radius:14px;overflow:hidden;">'
        . '<div style="padding:22px 28px;background:#123C5C;color:#fff;"><div style="font-size:20px;font-weight:700;">Finance Department</div>'
        . '<div style="margin-top:4px;font-size:13px;color:#DCEAF4;">HCI GroceryOne</div></div>'
        . '<div style="padding:28px;font-size:15px;line-height:1.65;"><h1 style="margin:0 0 18px;color:#123C5C;font-size:22px;">'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' . $bodyHtml . '</div>'
        . '<div style="padding:16px 28px;background:#F3F6F9;color:#526273;font-size:12px;line-height:1.5;">'
        . 'This message was sent by the Finance Department of HCI GroceryOne. Please contact Finance for questions about this approval.'
        . '</div></div></body></html>';
};

$sendRestockSupplierInvite = static function (array $request, array $supplier, string $token) use ($financeEmailTemplate): bool {
    $baseUrl = rtrim(posApplicationBaseUrl(), '/');
    $requestToken = rawurlencode(enc_id($request['request_id']));
    $encodedToken = rawurlencode($token);
    $approvalUrl = $baseUrl . '/supplier_inventory_approval.php?request_id=' . $requestToken . '&token=' . $encodedToken;
    $escape = static function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };
    $body = '<p>Dear ' . $escape($supplier['company_name']) . ',</p>'
        . '<p>Finance has approved restock request #' . (int) $request['request_id']
        . ' for <strong>' . $escape($request['item_name']) . '</strong>. Review the complete Formality Letter and provide your supplier e-signature on the secure review page.</p>'
        . '<p style="margin:24px 0;"><a href="' . $escape($approvalUrl)
        . '" style="display:inline-block;padding:12px 18px;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Review Letter and E-sign Approval</a></p>'
        . '<p>The letter and supplier e-signature form are available together on the review page. The order will proceed after approval is signed.</p>';
    return sendSystemEmail(
        (string) $supplier['email'],
        (string) $supplier['company_name'],
        'Formality Letter — Restock Request #' . (int) $request['request_id'],
        $financeEmailTemplate('Formality Letter', $body),
        null,
        'Finance Department'
    );
};

$sendGroupedRestockApprovalNotice = static function (array $requests, array $supplier, string $department, string $token) use ($financeEmailTemplate): bool {
    $escape = static function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };
    $request = $requests[0];
    $baseUrl = rtrim(posApplicationBaseUrl(), '/');
    $requestToken = rawurlencode(enc_id($request['request_id']));
    $encodedToken = rawurlencode($token);
    $approvalUrl = $baseUrl . '/supplier_inventory_approval.php?request_id=' . $requestToken . '&token=' . $encodedToken;
    $rows = '';
    $total = 0.0;
    foreach ($requests as $request) {
        $lineTotal = (float) ($request['estimated_cost'] ?? 0);
        $total += $lineTotal;
        $rows .= '<tr>'
            . '<td style="padding:8px;border-bottom:1px solid #DCE5EC;">' . $escape($request['item_name']) . '</td>'
            . '<td style="padding:8px;border-bottom:1px solid #DCE5EC;text-align:center;">' . (int) $request['quantity'] . '</td>'
            . '<td style="padding:8px;border-bottom:1px solid #DCE5EC;text-align:right;">' . $escape(peso($lineTotal)) . '</td>'
            . '</tr>';
    }
    $requestIds = implode(', ', array_map(static function ($request): string {
        return '#' . (int) $request['request_id'];
    }, $requests));
    $requestNotes = array_values(array_unique(array_filter(array_map(static function ($request): string {
        return trim((string) ($request['notes'] ?? ''));
    }, $requests))));
    $body = '<p>Dear ' . $escape($supplier['company_name']) . ',</p>'
        . '<p>Finance has reviewed and approved the restock request below for the <strong>'
        . $escape($department) . ' Department</strong>. Review the complete Formality Letter and provide your supplier e-signature together on the secure review page.</p>'
        . '<p><strong>Request:</strong> ' . $escape($requestIds) . '<br><strong>Supplier:</strong> '
        . $escape($supplier['company_name']) . '<br><strong>Requested by:</strong> '
        . $escape($request['requester_full_name'] ?? 'Inventory Staff')
        . '<br><strong>Request date:</strong> ' . $escape(date('F j, Y', strtotime($request['requested_at'])))
        . '<br><strong>Finance approval date:</strong> ' . $escape(date('F j, Y h:i A')) . '</p>'
        . '<h2 style="margin:22px 0 10px;color:#123C5C;font-size:18px;">Formality Letter details</h2>'
        . '<table style="width:100%;border-collapse:collapse;"><thead><tr>'
        . '<th style="padding:8px;text-align:left;background:#F3F6F9;">Item</th>'
        . '<th style="padding:8px;text-align:center;background:#F3F6F9;">Quantity</th>'
        . '<th style="padding:8px;text-align:right;background:#F3F6F9;">Estimated cost</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<p style="text-align:right;font-size:16px;"><strong>Total estimated cost: ' . $escape(peso($total)) . '</strong></p>'
        . ($requestNotes ? '<p><strong>Request notes:</strong> ' . $escape(implode(' · ', $requestNotes)) . '</p>' : '')
        . '<p style="margin:24px 0;"><a href="' . $escape($approvalUrl) . '" style="display:inline-block;padding:12px 18px;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Review Letter and E-sign Approval</a></p>'
        . '<p>The letter, Inventory Staff and Finance signatures, and supplier e-signature form are together on the review page. The order will move to the Orders queue after you sign. Please do not deliver until the store contacts you or confirms the delivery schedule.</p>'
        . '<p>Regards,<br><strong>Finance Department</strong><br>HCI GroceryOne</p>';

    return sendSystemEmail(
        (string) $supplier['email'],
        (string) $supplier['company_name'],
        'Formality Letter — Supplier approval required for ' . $requestIds,
        $financeEmailTemplate('Formality Letter', $body),
        null,
        'Finance Department'
    );
};

$sendHrPurchaseOrderSupplierInvite = static function (array $po, array $supplier, array $items, string $token) use ($financeEmailTemplate): bool {
    $escape = static function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };
    $baseUrl = rtrim(posApplicationBaseUrl(), '/');
    $approvalUrl = $baseUrl . '/supplier_purchase_order_approval.php?po_id='
        . rawurlencode(enc_id((int) $po['id'])) . '&token=' . rawurlencode($token);
    $rows = '';
    $total = 0.0;
    foreach ($items as $item) {
        $subtotal = (float) $item['subtotal'];
        $total += $subtotal;
        $rows .= '<tr><td style="padding:8px;border-bottom:1px solid #DCE5EC;">' . $escape($item['item_name']) . '</td>'
            . '<td style="padding:8px;border-bottom:1px solid #DCE5EC;text-align:center;">' . (int) $item['quantity_ordered'] . '</td>'
            . '<td style="padding:8px;border-bottom:1px solid #DCE5EC;text-align:right;">' . $escape(peso($subtotal)) . '</td></tr>';
    }
    $body = '<p>Dear ' . $escape($supplier['company_name']) . ',</p>'
        . '<p>Finance has reviewed and signed the following purchase request. Please review the Formality Letter, the requester and Finance signatures, and the order details on the secure page. Sign electronically to confirm acceptance. Do not deliver until your approval has been recorded and Admin coordinates delivery.</p>'
        . '<p><strong>Purchase Order:</strong> ' . $escape($po['po_number']) . '<br><strong>Department:</strong> '
        . $escape($po['department']) . '<br><strong>Requested by:</strong> ' . $escape($po['created_by_name'] ?? 'HR / Supervisor')
        . '<br><strong>Finance approval:</strong> ' . $escape(date('F j, Y g:i A')) . '</p>'
        . '<h2 style="margin:22px 0 10px;color:#123C5C;font-size:18px;">Formality Letter — Items and Estimates</h2>'
        . '<table style="width:100%;border-collapse:collapse;"><thead><tr>'
        . '<th style="padding:8px;text-align:left;background:#F3F6F9;">Item</th>'
        . '<th style="padding:8px;text-align:center;background:#F3F6F9;">Quantity</th>'
        . '<th style="padding:8px;text-align:right;background:#F3F6F9;">Estimated cost</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<p style="text-align:right;font-size:16px;"><strong>Total: ' . $escape(peso($total)) . '</strong></p>'
        . (!empty($po['notes']) ? '<p><strong>Request notes:</strong> ' . $escape($po['notes']) . '</p>' : '')
        . '<p style="margin:24px 0;"><a href="' . $escape($approvalUrl) . '" style="display:inline-block;padding:12px 18px;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Review Formality Letter and E-sign</a></p>'
        . '<p>The secure review page includes the Formality Letter and requester/Finance e-signatures. After your e-signature, the PO will move to Admin for receiving.</p>'
        . '<p>Regards,<br><strong>Finance Department</strong><br>HCI GroceryOne</p>';
    return sendSystemEmail(
        (string) $supplier['email'],
        (string) $supplier['company_name'],
        'Formality Letter — Supplier approval required for ' . (string) $po['po_number'],
        $financeEmailTemplate('Formality Letter — Supplier Approval Required', $body),
        null,
        'Finance Department'
    );
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_capital_transaction') {
        $type = ($_POST['capital_type'] ?? 'capital_in') === 'capital_out' ? 'capital_out' : 'capital_in';
        $amount = (float) ($_POST['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $back = 'finance.php' . (!empty($_POST['back_qs']) ? '?' . $_POST['back_qs'] : '');

        if ($amount <= 0) {
            $_SESSION['flash_error'] = "Please enter a valid amount.";
        } elseif (mb_strlen($reason) < 3) {
            $_SESSION['flash_error'] = "Please enter a reason (e.g. \"Payroll shortfall — Jul 1-15\").";
        } else {
            $ok = recordCapitalTransaction($pdo, $type, $amount, $reason, currentPosUserId(), $_SESSION['full_name'] ?? null);
            $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $ok
                ? ($type === 'capital_in'
                    ? "Recorded " . peso($amount) . " added as capital."
                    : "Recorded " . peso($amount) . " owner withdrawal.")
                : "The transaction could not be saved. Please try again.";
        }
        redirect($back);

    } elseif (in_array($action, ['approve_payroll', 'reject_payroll'], true)) {
        $result = decidePayrollBatchByFinance(
            $hrmsPdo,
            $_POST['period_start'] ?? '',
            $_POST['period_end'] ?? '',
            (int) ($_POST['generated_by'] ?? 0),
            $action === 'approve_payroll' ? 'approve' : 'reject',
            (int) ($_SESSION['employee_id'] ?? 0)
        );
        $_SESSION[$result['ok'] ? 'flash_success' : 'flash_error'] = $result['message'];
        redirect('finance.php#payroll-approval');

    } elseif ($action === 'release_payroll') {
        if (!in_array($_SESSION['role'] ?? '', ['finance', 'admin'], true)) {
            $_SESSION['flash_error'] = "Only Finance or Admin can release payroll.";
        } else {
            $result = releasePayrollBatch(
                $hrmsPdo,
                $pdo,
                $_POST['period_start'] ?? '',
                $_POST['period_end'] ?? '',
                (int) ($_POST['generated_by'] ?? 0),
                (int) ($_SESSION['employee_id'] ?? 0),
                $_POST['reference'] ?? ''
            );
            $_SESSION[$result['ok'] ? 'flash_success' : 'flash_error'] = $result['message'];
        }
        redirect('finance.php#payroll-release');

    } elseif ($action === 'finance_approve') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $signature = trim($_POST['finance_signature'] ?? '');
        $requester_signature_confirmed = ($_POST['requester_signature_confirmed'] ?? '') === '1';
        $stmt = $pdo->prepare("SELECT * FROM inventory_requests WHERE request_id = ?");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();
        $approvalRequests = $req ? [$req] : [];
        if ($req && !empty($req['request_group_id'])) {
            $groupStmt = $pdo->prepare("SELECT * FROM inventory_requests WHERE request_group_id = ? AND status = 'pending_finance' ORDER BY request_id ASC");
            $groupStmt->execute([$req['request_group_id']]);
            $approvalRequests = $groupStmt->fetchAll();
        }

        if (!$req) {
            $_SESSION['flash_error'] = "Request not found.";
        } elseif ($req['status'] !== 'pending_finance') {
            $_SESSION['flash_error'] = "Only requests awaiting Finance approval can be approved here.";
        } elseif (!hasDrawnPngSignature($signature)) {
            $_SESSION['flash_error'] = "Draw your Finance approval signature before approving this restock request.";
        } elseif (!$requester_signature_confirmed) {
            $_SESSION['flash_error'] = "Review the procurement letter and confirm that the Inventory Staff signature is correct before approving.";

        } else {
            $groupSupplierId = (int) ($req['supplier_id'] ?? 0);
            foreach ($approvalRequests as $approvalRequest) {
                if ((int) ($approvalRequest['supplier_id'] ?? 0) !== $groupSupplierId) {
                    $_SESSION['flash_error'] = "Items in one restock request must use the same supplier before they can be approved together.";
                    redirect('finance.php');
                }
                if (!empty($approvalRequest['product_id'])) {
                    $productCheck = $pdo->prepare("SELECT supplier_id FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
                    $productCheck->execute([(int) $approvalRequest['product_id']]);
                    $registeredSupplierId = $productCheck->fetchColumn();
                    if (!$registeredSupplierId || empty($approvalRequest['supplier_id']) || (int) $registeredSupplierId !== (int) $approvalRequest['supplier_id']) {
                        $_SESSION['flash_error'] = "This request has an invalid product and supplier pairing. Return it for correction before approval.";
                        redirect('finance.php');
                    }
                }
            }
            $supplierStmt = $pdo->prepare("SELECT id, company_name, email FROM suppliers WHERE id = ? AND status = 'active' LIMIT 1");
            $supplierStmt->execute([(int) ($req['supplier_id'] ?? 0)]);
            $supplier = $supplierStmt->fetch();
            if (!$supplier || !filter_var(trim((string) $supplier['email']), FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash_error'] = "A valid email for an active supplier is required. Return this request to Inventory Staff for correction.";
                redirect('finance.php');
            }

            $isGroupedProcurement = !empty($req['request_group_id']);
            $requestDepartment = trim((string) ($req['request_department'] ?? ''));
            if ($isGroupedProcurement && $requestDepartment === '' && $hrmsPdo) {
                try {
                    $departmentStmt = $hrmsPdo->prepare("SELECT department FROM employees WHERE employee_id = ? LIMIT 1");
                    $departmentStmt->execute([(int) $req['requested_by_employee_id']]);
                    $requestDepartment = trim((string) $departmentStmt->fetchColumn());
                } catch (PDOException $e) {
                    error_log("Finance grouped request department lookup skipped: " . $e->getMessage());
                }
            }
            if ($isGroupedProcurement && $requestDepartment === '') {
                $_SESSION['flash_error'] = 'The requesting employee has no HRMS department. Assign it before approving this Procurement request.';
                redirect('finance.php');
            }
            if (!$isGroupedProcurement && ($requestDepartment === '' || $requestDepartment === 'Unassigned') && $hrmsPdo) {
                try {
                    $departmentStmt = $hrmsPdo->prepare("SELECT department FROM employees WHERE employee_id = ? LIMIT 1");
                    $departmentStmt->execute([(int) $req['requested_by_employee_id']]);
                    $requestDepartment = trim((string) $departmentStmt->fetchColumn());
                } catch (PDOException $e) {
                    error_log("Finance restock department lookup skipped: " . $e->getMessage());
                }
            }
            if ($requestDepartment === '' || $requestDepartment === 'Unassigned') {
                $_SESSION['flash_error'] = 'The requesting employee has no HRMS department. Assign it before approving this restock request.';
                redirect('finance.php');
            }
            if ($isGroupedProcurement) {
                foreach ($approvalRequests as &$approvalRequest) {
                    $approvalRequest['request_department'] = $requestDepartment;
                }
                unset($approvalRequest);
            } else {
                $approvalRequests[0]['request_department'] = $requestDepartment;
            }
            $token = bin2hex(random_bytes(32));
            try {
                $pdo->beginTransaction();
                if ($isGroupedProcurement) {
                    $update = $pdo->prepare("UPDATE inventory_requests SET status = 'pending_supplier', request_department = ?, decided_by = ?, decided_at = NOW(), finance_signature = ?, finance_signature_at = NOW(), requester_signature_checked_by = ?, requester_signature_checked_at = NOW(), supplier_approval_token_hash = ?, supplier_approval_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE request_group_id = ? AND supplier_id = ? AND status = 'pending_finance'");
                    $update->execute([$requestDepartment, currentPosUserId(), $signature, currentPosUserId(), hash('sha256', $token), $req['request_group_id'], $groupSupplierId]);
                } else {
                    $update = $pdo->prepare("UPDATE inventory_requests SET status = 'pending_supplier', request_department = ?, decided_by = ?, decided_at = NOW(), finance_signature = ?, finance_signature_at = NOW(), requester_signature_checked_by = ?, requester_signature_checked_at = NOW(), supplier_approval_token_hash = ?, supplier_approval_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE request_id = ? AND status = 'pending_finance'");
                    $update->execute([$requestDepartment, currentPosUserId(), $signature, currentPosUserId(), hash('sha256', $token), $request_id]);
                }
                if ($update->rowCount() !== count($approvalRequests)) {
                    throw new RuntimeException('The request changed before approval. Refresh and review its current status.');
                }
                $restockPo = createFinanceApprovedRestockPurchaseOrder(
                    $pdo,
                    $approvalRequests,
                    currentPosUserId(),
                    $_SESSION['full_name'] ?? null
                );
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Finance restock PO creation failed for request #' . $request_id . ': ' . $e->getMessage());
                $_SESSION['flash_error'] = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Finance approval could not be saved as a Purchase Order. Please retry.';
                redirect('finance.php');
            }
            foreach ($approvalRequests as &$approvedRequest) {
                $approvedRequest['finance_signature'] = $signature;
                $approvedRequest['finance_signature_at'] = date('Y-m-d H:i:s');
            }
            unset($approvedRequest);
            $req['finance_signature'] = $signature;
            $req['finance_signature_at'] = date('Y-m-d H:i:s');

            $requesterUserId = $pdo->prepare(
                $isGroupedProcurement
                    ? "SELECT id FROM users WHERE is_active = 1 AND (employee_id = ? OR role = 'inventory_staff')"
                    : "SELECT id FROM users WHERE is_active = 1 AND (employee_id = ? OR role IN ('inventory_staff', 'admin'))"
            );
            $requesterUserId->execute([(int) $req['requested_by_employee_id']]);
            $approvedLineCount = count($approvalRequests);
            $requestApprovalMessage = $isGroupedProcurement
                ? $approvedLineCount . ' item(s) in restock request #' . $request_id . ' were approved by Finance and sent to the supplier for e-signature.'
                : 'Inventory request #' . $request_id . ' was approved by Finance and is awaiting supplier signature.';
            $requestApprovalLink = 'purchase_orders.php?po=' . enc_id($restockPo['id']);
            $notify = $pdo->prepare("INSERT INTO notifications (user_id, category, type, message, link_url, source_type, source_id) VALUES (?, 'workflow', 'inventory_request', ?, ?, 'inventory_request', ?)");
            foreach ($requesterUserId->fetchAll(PDO::FETCH_COLUMN) as $notifyUserId) {
                $notify->execute([(int) $notifyUserId, $requestApprovalMessage, $requestApprovalLink, $request_id]);
            }
            if ($isGroupedProcurement) {
                if ($sendGroupedRestockApprovalNotice($approvalRequests, $supplier, $requestDepartment, $token)) {
                    $_SESSION['flash_success'] = $approvedLineCount . " restock item(s) approved by Finance, linked to " . $restockPo['number'] . ", and emailed to the supplier for e-signature.";
                } else {
                    $_SESSION['flash_error'] = "Finance approval was recorded, but the supplier email could not be sent. The request is still awaiting supplier signature; resend the invitation after checking mail configuration.";
                }
            } elseif ($sendRestockSupplierInvite($req, $supplier, $token)) {
                $_SESSION['flash_success'] = "Request approved and linked to " . $restockPo['number'] . ". The supplier approval email was sent; ordering and receiving remain blocked until the supplier signs.";
            } else {
                $_SESSION['flash_error'] = "Finance approval was recorded, but supplier email delivery failed. The request remains awaiting supplier signature; use Resend Invitation after checking mail configuration.";
            }
        }
        redirect('finance.php');

    } elseif ($action === 'finance_resend_supplier_invite') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM inventory_requests WHERE request_id = ? AND status = 'pending_supplier' LIMIT 1");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();
        if (!$req) {
            $_SESSION['flash_error'] = "Only requests awaiting supplier signature can be emailed again.";
        } else {
            $supplierStmt = $pdo->prepare("SELECT id, company_name, email FROM suppliers WHERE id = ? AND status = 'active' LIMIT 1");
            $supplierStmt->execute([(int) ($req['supplier_id'] ?? 0)]);
            $supplier = $supplierStmt->fetch();
            if (!$supplier || !filter_var(trim((string) $supplier['email']), FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash_error'] = "The request has no active supplier with a valid email address.";
            } elseif (!empty($req['request_group_id'])) {
                $groupStmt = $pdo->prepare("SELECT * FROM inventory_requests WHERE request_group_id = ? AND supplier_id = ? AND status = 'pending_supplier' ORDER BY request_id ASC");
                $groupStmt->execute([$req['request_group_id'], $req['supplier_id']]);
                $groupRequests = $groupStmt->fetchAll();
                $previousTokens = [];
                foreach ($groupRequests as $groupRequest) {
                    $previousTokens[(int) $groupRequest['request_id']] = [
                        'hash' => $groupRequest['supplier_approval_token_hash'] ?? null,
                        'expires' => $groupRequest['supplier_approval_expires_at'] ?? null,
                    ];
                }
                $token = bin2hex(random_bytes(32));
                $update = $pdo->prepare("UPDATE inventory_requests SET supplier_approval_token_hash = ?, supplier_approval_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE request_group_id = ? AND supplier_id = ? AND status = 'pending_supplier'");
                $update->execute([hash('sha256', $token), $req['request_group_id'], $req['supplier_id']]);
                if ($update->rowCount() !== count($groupRequests)) {
                    $_SESSION['flash_error'] = "The request changed before its invitation could be resent.";
                } elseif ($sendGroupedRestockApprovalNotice($groupRequests, $supplier, (string) $req['request_department'], $token)) {
                    $_SESSION['flash_success'] = "Combined Formality Letter and supplier e-signature invitation resent. The link expires in 7 days.";
                } else {
                    $restore = $pdo->prepare("UPDATE inventory_requests SET supplier_approval_token_hash = ?, supplier_approval_expires_at = ? WHERE request_id = ? AND status = 'pending_supplier' AND supplier_approval_token_hash = ?");
                    foreach ($previousTokens as $previousRequestId => $previousToken) {
                        $restore->execute([
                            $previousToken['hash'],
                            $previousToken['expires'],
                            $previousRequestId,
                            hash('sha256', $token),
                        ]);
                    }
                    $_SESSION['flash_error'] = "The supplier email could not be sent. The prior signing link remains valid; check SMTP configuration and retry.";
                }
            } else {
                $previousTokenHash = $req['supplier_approval_token_hash'] ?? null;
                $previousTokenExpiry = $req['supplier_approval_expires_at'] ?? null;
                $token = bin2hex(random_bytes(32));
                $update = $pdo->prepare("UPDATE inventory_requests SET supplier_approval_token_hash = ?, supplier_approval_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE request_id = ? AND status = 'pending_supplier'");
                $update->execute([hash('sha256', $token), $request_id]);
                if ($update->rowCount() !== 1) {
                    $_SESSION['flash_error'] = "The request changed before its invitation could be resent.";
                } elseif ($sendRestockSupplierInvite($req, $supplier, $token)) {
                    $_SESSION['flash_success'] = "Supplier approval email sent. The new signing link expires in 7 days.";
                } else {
                    $restore = $pdo->prepare("UPDATE inventory_requests SET supplier_approval_token_hash = ?, supplier_approval_expires_at = ? WHERE request_id = ? AND status = 'pending_supplier' AND supplier_approval_token_hash = ?");
                    $restore->execute([$previousTokenHash, $previousTokenExpiry, $request_id, hash('sha256', $token)]);
                    $_SESSION['flash_error'] = "The supplier email could not be sent. The request remains blocked from receiving; check SMTP configuration and resend.";
                }
            }
        }
        redirect('finance.php');

    } elseif ($action === 'finance_return_restock') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT request_id, request_group_id, notes, requested_by_employee_id FROM inventory_requests WHERE request_id = ? AND status = 'pending_finance' LIMIT 1");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();
        if (!$req) {
            $_SESSION['flash_error'] = "Only requests awaiting Finance approval can be returned for correction.";
        } else {
            $reason = trim((string) ($_POST['return_reason'] ?? ''));
            $returnNote = ' | Returned by Finance for correction' . ($reason !== '' ? ': ' . $reason : '.');
            if (!empty($req['request_group_id'])) {
                $update = $pdo->prepare("UPDATE inventory_requests SET status = 'pending_validation', validated_by = NULL, validated_at = NULL, notes = CONCAT(COALESCE(notes, ''), ?) WHERE request_group_id = ? AND status = 'pending_finance'");
                $update->execute([$returnNote, $req['request_group_id']]);
            } else {
                $update = $pdo->prepare("UPDATE inventory_requests SET status = 'pending_validation', validated_by = NULL, validated_at = NULL, notes = CONCAT(COALESCE(notes, ''), ?) WHERE request_id = ? AND status = 'pending_finance'");
                $update->execute([$returnNote, $request_id]);
            }
            $returned = $update->rowCount() > 0;
            if ($returned) {
                $users = $pdo->prepare("SELECT id FROM users WHERE is_active = 1 AND (employee_id = ? OR role IN ('inventory_staff', 'admin'))");
                $users->execute([(int) $req['requested_by_employee_id']]);
                $notify = $pdo->prepare("INSERT INTO notifications (user_id, category, type, message, link_url, source_type, source_id) VALUES (?, 'workflow', 'inventory_request', ?, 'inventory_requests.php?status=pending_validation', 'inventory_request', ?)");
                foreach ($users->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                    $notify->execute([(int) $userId, 'Finance returned the restock request containing ' . $update->rowCount() . ' item(s) for correction.', $request_id]);
                }
            }
            $_SESSION[$returned ? 'flash_success' : 'flash_error'] = $returned
                ? "The restock request was returned to Inventory Staff for correction."
                : "The request changed before it could be returned.";
        }
        redirect('finance.php');

    } elseif ($action === 'finance_reject') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM inventory_requests WHERE request_id = ?");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();

        if (!$req) {
            $_SESSION['flash_error'] = "Request not found.";
        } elseif ($req['status'] !== 'pending_finance') {
            $_SESSION['flash_error'] = "Only requests awaiting Finance approval can be rejected here.";
        } else {
            $reject_reason = trim($_POST['reject_reason'] ?? '');
            $rejectNote = !empty($reject_reason) ? ' | Rejected by Finance: ' . $reject_reason : ' | Rejected by Finance';
            if (!empty($req['request_group_id'])) {
                $reject = $pdo->prepare("UPDATE inventory_requests SET status = 'rejected', decided_by = ?, decided_at = NOW(), notes = CONCAT(COALESCE(notes, ''), ?) WHERE request_group_id = ? AND status = 'pending_finance'");
                $reject->execute([currentPosUserId(), $rejectNote, $req['request_group_id']]);
            } else {
                $reject = $pdo->prepare("UPDATE inventory_requests SET status = 'rejected', decided_by = ?, decided_at = NOW(), notes = CONCAT(COALESCE(notes, ''), ?) WHERE request_id = ? AND status = 'pending_finance'");
                $reject->execute([currentPosUserId(), $rejectNote, $request_id]);
            }
            $_SESSION['flash_success'] = $reject->rowCount() > 1
                ? $reject->rowCount() . " restock items rejected together."
                : "Request rejected.";
        }
        redirect('finance.php');

    } elseif ($action === 'finance_approve_po') {
        // Financial approval ONLY — this never touches products.stock
        // or product_batches. Stock only moves once Inventory Staff
        // actually receives the delivery (see purchase_orders.php /
        // recordPurchaseOrderReceipt()).
        if (($_SESSION['role'] ?? '') !== 'finance') {
            $_SESSION['flash_error'] = 'Only a Finance-role user can approve Purchase Orders; Admin Procurement cannot approve its own order.';
            redirect('finance.php');
        }
        $po_id = (int) ($_POST['po_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT po.*, s.company_name AS supplier_name, s.email AS supplier_email
            FROM purchase_orders po
            JOIN suppliers s ON s.id = po.supplier_id
            WHERE po.id = ?
        ");
        $stmt->execute([$po_id]);
        $po = $stmt->fetch();

        if (!$po) {
            $_SESSION['flash_error'] = "Purchase Order not found.";
        } elseif ($po['status'] !== 'pending_approval') {
            $_SESSION['flash_error'] = "Only Purchase Orders awaiting approval can be approved here.";
        } elseif (!empty($po['requested_by_hrms_user_id'])) {
            $signature = trim((string) ($_POST['finance_signature'] ?? ''));
            if (!hasDrawnPngSignature($signature)) {
                $_SESSION['flash_error'] = 'Draw your Finance e-signature before approving this HR/Supervisor purchase request.';
            } elseif (($_POST['requester_signature_confirmed'] ?? '') !== '1' || !hasDrawnPngSignature($po['requester_signature'] ?? '')) {
                $_SESSION['flash_error'] = 'Review and confirm the requester e-signature before approving this purchase request.';
            } elseif (!filter_var(trim((string) ($po['supplier_email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash_error'] = 'An active supplier email is required before Finance can approve and send the Formality Letter.';
            } else {
                $itemsStmt = $pdo->prepare("SELECT item_name, quantity_ordered, subtotal FROM purchase_order_items WHERE po_id = ? ORDER BY id");
                $itemsStmt->execute([$po_id]);
                $items = $itemsStmt->fetchAll();
                if (!$items) {
                    $_SESSION['flash_error'] = 'This purchase request has no items and cannot be approved.';
                } else {
                    try {
                        $token = bin2hex(random_bytes(32));
                        $pdo->beginTransaction();
                        $approve = $pdo->prepare("
                            UPDATE purchase_orders
                            SET status = 'pending_supplier', approved_by = ?, approved_at = NOW(),
                                finance_signature = ?, finance_signature_at = NOW(),
                                supplier_approval_token_hash = ?, supplier_approval_expires_at = DATE_ADD(NOW(), INTERVAL 14 DAY)
                            WHERE id = ? AND status = 'pending_approval' AND requested_by_hrms_user_id IS NOT NULL
                        ");
                        $approve->execute([currentPosUserId(), $signature, hash('sha256', $token), $po_id]);
                        if ($approve->rowCount() !== 1) {
                            throw new RuntimeException('The purchase order changed before Finance approval.');
                        }
                        $pdo->commit();
                        $sent = $sendHrPurchaseOrderSupplierInvite($po, [
                            'company_name' => $po['supplier_name'],
                            'email' => $po['supplier_email'],
                        ], $items, $token);
                        $_SESSION[$sent ? 'flash_success' : 'flash_error'] = $sent
                            ? 'Purchase Order ' . $po['po_number'] . ' was approved with Finance e-signature. The Formality Letter was emailed to the supplier for e-signature.'
                            : 'Purchase Order ' . $po['po_number'] . ' was approved and is awaiting supplier e-signature, but the Formality Letter email failed. Use Resend Supplier Invitation in the awaiting supplier section.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Finance HR purchase order approval failed for PO #' . $po_id . ': ' . $e->getMessage());
                        $_SESSION['flash_error'] = 'The Finance approval or supplier Formality Letter could not be completed. Refresh and retry, or contact Admin.';
                    }
                }
            }
        } else {
            $pdo->prepare("UPDATE purchase_orders SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?")
                ->execute([currentPosUserId(), $po_id]);
            $_SESSION['flash_success'] = "Purchase Order " . clean($po['po_number']) . " approved — Inventory Staff can now mark it Ordered and receive the delivery.";
        }
        redirect('finance.php');

    } elseif ($action === 'finance_resend_po_supplier_invite') {
        if (($_SESSION['role'] ?? '') !== 'finance') {
            $_SESSION['flash_error'] = 'Only Finance can resend a supplier Formality Letter.';
            redirect('finance.php#purchase-orders');
        }
        $po_id = (int) ($_POST['po_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT po.*, s.company_name AS supplier_name, s.email AS supplier_email
            FROM purchase_orders po
            JOIN suppliers s ON s.id = po.supplier_id
            WHERE po.id = ? AND po.status = 'pending_supplier' AND po.requested_by_hrms_user_id IS NOT NULL
        ");
        $stmt->execute([$po_id]);
        $po = $stmt->fetch();
        if (!$po || !filter_var(trim((string) ($po['supplier_email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'The supplier invitation could not be resent because the purchase order or active supplier email is unavailable.';
        } else {
            try {
                $itemsStmt = $pdo->prepare("SELECT item_name, quantity_ordered, subtotal FROM purchase_order_items WHERE po_id = ? ORDER BY id");
                $itemsStmt->execute([$po_id]);
                $items = $itemsStmt->fetchAll();
                if (!$items) {
                    throw new RuntimeException('Purchase order items are missing.');
                }
                $token = bin2hex(random_bytes(32));
                $update = $pdo->prepare("
                    UPDATE purchase_orders
                    SET supplier_approval_token_hash = ?, supplier_approval_expires_at = DATE_ADD(NOW(), INTERVAL 14 DAY)
                    WHERE id = ? AND status = 'pending_supplier'
                ");
                $update->execute([hash('sha256', $token), $po_id]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('The purchase order is no longer awaiting supplier approval.');
                }
                $sent = $sendHrPurchaseOrderSupplierInvite($po, [
                    'company_name' => $po['supplier_name'],
                    'email' => $po['supplier_email'],
                ], $items, $token);
                $_SESSION[$sent ? 'flash_success' : 'flash_error'] = $sent
                    ? 'The Formality Letter was resent to the supplier. The e-signature link is valid for 14 days.'
                    : 'The invitation link was refreshed, but the email could not be sent. Check the supplier address and mail configuration, then resend.';
            } catch (Throwable $e) {
                error_log('Finance HR purchase order supplier invite resend failed for PO #' . $po_id . ': ' . $e->getMessage());
                $_SESSION['flash_error'] = 'The supplier Formality Letter could not be resent. Please try again.';
            }
        }
        redirect('finance.php#purchase-orders');

    } elseif ($action === 'finance_reject_po') {
        if (($_SESSION['role'] ?? '') !== 'finance') {
            $_SESSION['flash_error'] = 'Only a Finance-role user can reject Purchase Orders.';
            redirect('finance.php');
        }
        $po_id = (int) ($_POST['po_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ?");
        $stmt->execute([$po_id]);
        $po = $stmt->fetch();

        if (!$po) {
            $_SESSION['flash_error'] = "Purchase Order not found.";
        } elseif ($po['status'] !== 'pending_approval') {
            $_SESSION['flash_error'] = "Only Purchase Orders awaiting approval can be rejected here.";
        } else {
            $reject_reason = trim($_POST['reject_reason'] ?? '');
            $pdo->prepare("UPDATE purchase_orders SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? WHERE id = ?")
                ->execute([currentPosUserId(), $reject_reason ?: null, $po_id]);
            $_SESSION['flash_success'] = "Purchase Order " . clean($po['po_number']) . " rejected.";
        }
        redirect('finance.php');

    } elseif ($action === 'finance_approve_discount') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM discount_requests WHERE request_id = ?");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();

        if (!$req) {
            $_SESSION['flash_error'] = "Discount request not found.";
        } elseif ($req['status'] !== 'pending') {
            $_SESSION['flash_error'] = "Only discount requests still awaiting approval can be approved here.";
        } else {
            $pdo->prepare("UPDATE discount_requests SET status = 'approved', decided_by = ?, decided_at = NOW() WHERE request_id = ?")
                ->execute([currentPosUserId(), $request_id]);
            $_SESSION['flash_success'] = "Discount approved — the cashier's register will apply it automatically.";
        }
        redirect('finance.php');

    } elseif ($action === 'finance_reject_discount') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM discount_requests WHERE request_id = ?");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();

        if (!$req) {
            $_SESSION['flash_error'] = "Discount request not found.";
        } elseif ($req['status'] !== 'pending') {
            $_SESSION['flash_error'] = "Only discount requests still awaiting approval can be rejected here.";
        } else {
            $reject_reason = trim($_POST['reject_reason'] ?? '');
            $pdo->prepare("UPDATE discount_requests SET status = 'rejected', reject_reason = ?, decided_by = ?, decided_at = NOW() WHERE request_id = ?")
                ->execute([$reject_reason !== '' ? $reject_reason : null, currentPosUserId(), $request_id]);
            $_SESSION['flash_success'] = "Discount request rejected.";
        }
        redirect('finance.php');

    } elseif ($action === 'finance_approve_product_discount') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM product_discount_requests WHERE request_id = ?");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();

        if (!$req) {
            $_SESSION['flash_error'] = "Discount request not found.";
        } elseif ($req['status'] !== 'pending') {
            $_SESSION['flash_error'] = "Only requests still awaiting approval can be approved here.";
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE products SET discount_type = ?, discount_value = ?, discount_reason = ? WHERE id = ?")
                    ->execute([$req['discount_type'], $req['discount_value'], $req['discount_reason'], $req['product_id']]);
                $pdo->prepare("UPDATE product_discount_requests SET status = 'approved', decided_by = ?, decided_at = NOW() WHERE request_id = ?")
                    ->execute([currentPosUserId(), $request_id]);
                $pdo->commit();
                $_SESSION['flash_success'] = "Product discount approved and applied.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("finance_approve_product_discount error: " . $e->getMessage());
                $_SESSION['flash_error'] = "Could not approve this discount. Please try again.";
            }
        }
        redirect('finance.php');

    } elseif ($action === 'finance_reject_product_discount') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM product_discount_requests WHERE request_id = ?");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();

        if (!$req) {
            $_SESSION['flash_error'] = "Discount request not found.";
        } elseif ($req['status'] !== 'pending') {
            $_SESSION['flash_error'] = "Only requests still awaiting approval can be rejected here.";
        } else {
            $reject_reason = trim($_POST['reject_reason'] ?? '');
            $pdo->prepare("UPDATE product_discount_requests SET status = 'rejected', reject_reason = ?, decided_by = ?, decided_at = NOW() WHERE request_id = ?")
                ->execute([$reject_reason !== '' ? $reject_reason : null, currentPosUserId(), $request_id]);
            $_SESSION['flash_success'] = "Product discount request rejected. The product keeps its current discount.";
        }
        redirect('finance.php');
    }
}

$success_message = $_SESSION['flash_success'] ?? '';
$error_message = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Profit ng napiling date range (Revenue - COGS).
$profit_period = getProfitForPeriod($pdo, $date_from, $date_to);

// All-time Business Cash Position.
$cash_position = getBusinessCashPosition($pdo, $hrmsPdo);

// Payroll that HR already approved and is waiting for Finance to release.
$is_finance_role = in_array(($_SESSION['role'] ?? ''), ['finance', 'admin'], true);
$payroll_awaiting_finance_approval = getPayrollAwaitingFinanceApproval($hrmsPdo);
$payroll_awaiting_release = getPayrollAwaitingRelease($hrmsPdo, (int) ($_SESSION['employee_id'] ?? 0));

// Capital / Owner Transaction ledger (pinaka-bago muna sa taas).
$capital_ledger = [];
try {
    $capital_ledger = $pdo->query("SELECT * FROM capital_transactions ORDER BY created_at DESC LIMIT 25")->fetchAll();
} catch (Exception $e) {
}

// Restock requests waiting on Finance — the third stage of the
// approval chain, reached only after Inventory Staff has validated
// the request and set its estimated cost (see inventory_requests.php's
// 'validate' action + the 'pending_finance' status).
$pending_finance_requests = [];
try {
    $pending_finance_requests = $pdo->query("
        SELECT ir.*, p.product_name AS linked_product_name,
               s.company_name AS supplier_name, s.email AS supplier_email,
               u.procurement_signature_reference AS requester_signature_reference
        FROM inventory_requests ir
        LEFT JOIN products p ON p.id = ir.product_id
        LEFT JOIN suppliers s ON s.id = ir.supplier_id
        LEFT JOIN users u ON u.employee_id = ir.requested_by_employee_id
        WHERE ir.status = 'pending_finance'
        ORDER BY ir.requested_at ASC
    ")->fetchAll();
} catch (Exception $e) {
}

$pending_supplier_requests = [];
try {
    $pending_supplier_requests = $pdo->query("
        SELECT ir.request_id, ir.request_group_id, ir.item_name, ir.quantity, ir.estimated_cost,
               ir.request_department, ir.supplier_approval_expires_at,
               s.company_name AS supplier_name, s.email AS supplier_email
        FROM inventory_requests ir
        LEFT JOIN suppliers s ON s.id = ir.supplier_id
        WHERE ir.status = 'pending_supplier'
        ORDER BY ir.requested_at ASC
    ")->fetchAll();
} catch (Exception $e) {
}
$pending_supplier_groups = [];
foreach ($pending_supplier_requests as $request) {
    $groupKey = !empty($request['request_group_id'])
        ? 'group:' . $request['request_group_id']
        : 'request:' . (int) $request['request_id'];
    if (!isset($pending_supplier_groups[$groupKey])) {
        $pending_supplier_groups[$groupKey] = [
            'request' => $request,
            'items' => [],
            'total' => 0.0,
        ];
    }
    $pending_supplier_groups[$groupKey]['items'][] = $request;
    $pending_supplier_groups[$groupKey]['total'] += (float) ($request['estimated_cost'] ?? 0);
}

// Whoever on Inventory Staff validated each request — same
// cross-database employee-name lookup pattern as $requester_names
// below (validated_by stores a POS users.id, not an HRMS employee_id,
// so this one is a POS-side lookup instead).
$validator_names = [];
if (!empty($pending_finance_requests)) {
    $vids = array_unique(array_filter(array_column($pending_finance_requests, 'validated_by')));
    if (!empty($vids)) {
        $ph = implode(',', array_fill(0, count($vids), '?'));
        try {
            $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id IN ($ph)");
            $stmt->execute(array_values($vids));
            foreach ($stmt->fetchAll() as $row) {
                $validator_names[$row['id']] = $row['full_name'];
            }
        } catch (PDOException $e) {
            error_log("finance.php validator lookup skipped: " . $e->getMessage());
        }
    }
}

// Purchase Orders waiting on Finance's approval — see
// purchase_orders.php ('submit_for_approval' action / 'pending_approval'
// status). Finance only ever sees/decides this one step of the PO
// flow; creation, ordering, and receiving all live in
// purchase_orders.php (Admin / Inventory Staff only).
$pending_po_requests = [];
$pending_hr_po_supplier_signatures = [];
try {
    $pending_po_requests = $pdo->query("
        SELECT po.*, s.company_name AS supplier_name,
            (SELECT COALESCE(SUM(subtotal), 0) FROM purchase_order_items WHERE po_id = po.id) AS total_amount,
            (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.id) AS item_count
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        WHERE po.status = 'pending_approval'
        ORDER BY po.submitted_at ASC
    ")->fetchAll();
    $pending_hr_po_supplier_signatures = $pdo->query("
        SELECT po.*, s.company_name AS supplier_name, s.email AS supplier_email,
            (SELECT COALESCE(SUM(subtotal), 0) FROM purchase_order_items WHERE po_id = po.id) AS total_amount,
            (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.id) AS item_count
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        WHERE po.status = 'pending_supplier' AND po.requested_by_hrms_user_id IS NOT NULL
        ORDER BY po.approved_at ASC, po.id ASC
    ")->fetchAll();
} catch (PDOException $e) {
    error_log('Finance purchase order approval queue could not be loaded: ' . $e->getMessage());
}

// Manual (percent/fixed) discount requests waiting on Finance — see
// discount_requests table + the apply_discount action in pos.php.
$pending_discount_requests = [];
try {
    $pending_discount_requests = $pdo->query("
        SELECT dr.*, u.full_name AS cashier_name
        FROM discount_requests dr
        LEFT JOIN users u ON u.id = dr.cashier_id
        WHERE dr.status = 'pending'
        ORDER BY dr.requested_at ASC
    ")->fetchAll();
} catch (Exception $e) {
}

// Product discount changes (from products.php's Edit form) waiting on
// Finance — see product_discount_requests table.
$pending_product_discount_requests = [];
try {
    $pending_product_discount_requests = $pdo->query("
        SELECT pdr.*, p.product_name, u.full_name AS requester_name
        FROM product_discount_requests pdr
        LEFT JOIN products p ON p.id = pdr.product_id
        LEFT JOIN users u ON u.id = pdr.requested_by
        WHERE pdr.status = 'pending'
        ORDER BY pdr.requested_at ASC
    ")->fetchAll();
} catch (Exception $e) {
}

// Requesting Supervisors' names live in HRMS, not this database.
$requester_names = [];
if ($hrmsPdo && !empty($pending_finance_requests)) {
    $ids = array_unique(array_column($pending_finance_requests, 'requested_by_employee_id'));
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $hrmsPdo->prepare("SELECT employee_id, first_name, last_name FROM employees WHERE employee_id IN ($ph)");
            $stmt->execute(array_values($ids));
            foreach ($stmt->fetchAll() as $row) {
                $requester_names[$row['employee_id']] = $row['first_name'] . ' ' . $row['last_name'];
            }
        } catch (PDOException $e) {
            error_log("finance.php requester lookup skipped: " . $e->getMessage());
        }
    }
}

$pending_finance_groups = [];
foreach ($pending_finance_requests as $request) {
    $groupKey = !empty($request['request_group_id'])
        ? 'group:' . $request['request_group_id']
        : 'request:' . (int) $request['request_id'];
    if (!isset($pending_finance_groups[$groupKey])) {
        $pending_finance_groups[$groupKey] = [
            'request' => $request,
            'items' => [],
            'total' => 0.0,
        ];
    }
    $pending_finance_groups[$groupKey]['items'][] = $request;
    $pending_finance_groups[$groupKey]['total'] += (float) ($request['estimated_cost'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance — HCI GroceryOne</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .date-filter-form {
            display: flex;
            align-items: flex-end;
            gap: 14px;
            flex-wrap: wrap;
            background: var(--white);
            border-radius: 14px;
            padding: 18px 22px;
            box-shadow: var(--shadow-card);
        }

        .date-filter-form .field-group {
            margin: 0;
        }

        .date-filter-form label {
            font-size: 12px;
            font-weight: 700;
            color: var(--ink-soft);
            display: block;
            margin-bottom: 6px;
        }

        .date-filter-form input[type=date] {
            padding: 10px 14px;
            border: 1.5px solid var(--line);
            border-radius: 10px;
            font-family: var(--font-body);
            font-size: 14px;
            color: var(--ink);
            background: var(--cream);
        }

        .date-filter-form input[type=date]:focus {
            outline: none;
            border-color: var(--green-mid);
        }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        @media (max-width: 900px) {
            .two-col {
                grid-template-columns: 1fr;
            }
        }

        .summary-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }

        @media (max-width: 700px) {
            .summary-row {
                grid-template-columns: 1fr;
            }
        }

        .stat-card.accent-green {
            border-left: 4px solid var(--green-mid);
        }

        .stat-card.accent-mustard {
            border-left: 4px solid var(--mustard);
        }

        .stat-card.accent-leaf {
            border-left: 4px solid var(--green-leaf);
        }

        .finance-req-card {
            background: var(--white);
            border: 1.5px solid var(--line);
            border-radius: 14px;
            padding: 18px;
            display: grid;
            gap: 14px;
            box-shadow: 0 5px 16px rgba(18, 60, 92, .06);
        }

        .finance-req-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .finance-req-name {
            font-size: 16px;
            font-weight: 800;
            color: var(--ink);
            letter-spacing: -.01em;
        }

        .finance-req-meta {
            font-size: 12.5px;
            color: var(--ink-soft);
            line-height: 1.55;
            margin-top: 3px;
        }

        .finance-req-notes {
            font-size: 13px;
            color: var(--ink-soft);
            background: var(--cream);
            border-radius: 10px;
            padding: 8px 12px;
        }

        .signature-comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 0;
        }

        .signature-comparison>div {
            min-height: 116px;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #f8fafc;
            font-size: 12px;
            color: var(--ink-soft);
        }

        .signature-comparison strong {
            display: block;
            color: var(--ink);
            margin-bottom: 8px;
            font-size: 12px;
        }

        .signature-comparison img {
            display: block;
            width: 100%;
            height: 74px;
            object-fit: contain;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 8px;
        }

        .signature-missing {
            display: block;
            padding-top: 24px;
            color: #b91c1c;
        }

        @media (max-width: 650px) {
            .signature-comparison {
                grid-template-columns: 1fr;
            }

            .finance-formality-letter {
                align-items: stretch;
                flex-direction: column;
            }

            .finance-formality-letter a {
                text-align: center;
            }
        }

        .finance-req-actions {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 14px;
            align-items: center;
            padding-top: 4px;
        }

        .finance-restock-actions {
            grid-template-columns: minmax(0, 1fr);
            width: 100%;
            max-width: 100%;
            align-items: center;
            gap: 12px;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #f8fafc;
        }

        .finance-formality-letter {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin: 14px 0;
            padding: 16px 18px;
            border: 2px solid #f2c14e;
            border-left-width: 7px;
            border-radius: 12px;
            background: #fffbeb;
            color: #713f12;
        }

        .finance-formality-letter strong {
            display: block;
            margin-bottom: 4px;
            color: #713f12;
            font-size: 14px;
        }

        .finance-formality-letter span {
            font-size: 12px;
        }

        .finance-formality-letter a {
            flex: 0 0 auto;
            padding: 10px 15px;
            border-radius: 9px;
            background: #b45309;
            color: #fff;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
        }

        .finance-formality-letter a:hover {
            background: #92400e;
        }

        .finance-reject-btn {
            background: #dc2626 !important;
            color: #fff !important;
            border: 1px solid #b91c1c !important;
            box-shadow: 0 2px 8px rgba(220, 38, 38, .2) !important;
        }

        .finance-req-actions>a {
            align-self: stretch;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 178px;
        }

        .finance-po-approval-card {
            grid-template-columns: minmax(0, 1fr);
            align-items: start;
            gap: 18px 22px;
            padding: 22px;
            border-left: 4px solid #2563eb;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(18, 60, 92, .08);
        }

        .finance-po-approval-card.finance-po-has-evidence {
            grid-template-columns: minmax(280px, .8fr) minmax(0, 1.6fr);
        }

        .finance-po-approval-card .finance-req-top {
            grid-column: 1 / -1;
            align-items: center;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--line);
        }

        .finance-po-approval-card .finance-req-name {
            font-size: 19px;
            line-height: 1.35;
        }

        .finance-po-approval-card .finance-req-meta {
            margin-top: 8px;
            font-size: 13.5px;
            line-height: 1.6;
        }

        .finance-po-approval-card .finance-badge {
            flex: 0 0 auto;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 11px;
            letter-spacing: .04em;
        }

        .finance-po-evidence {
            display: grid;
            align-content: start;
            gap: 10px;
            min-width: 0;
        }

        .finance-po-panel>.panel-header {
            display: block;
        }

        .finance-po-panel>.panel-header h2 {
            margin-bottom: 7px;
            color: var(--ink);
            font-size: 21px;
            line-height: 1.3;
        }

        .finance-po-panel>.panel-header>span {
            display: block;
            max-width: 980px;
            color: var(--ink-soft);
            font-size: 14px !important;
            line-height: 1.6;
        }

        .finance-po-signature {
            width: 100%;
            box-sizing: border-box;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #f8fafc;
        }

        .finance-po-signature img {
            display: block;
            width: 100%;
            height: 86px;
            margin-top: 8px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            object-fit: contain;
        }

        .finance-po-signature small {
            display: block;
            margin-top: 6px;
            color: var(--ink-soft);
            font-size: 12px;
        }

        .finance-po-signature strong {
            font-size: 14px !important;
        }

        .finance-po-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: stretch;
            gap: 10px;
            min-width: 0;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #f8fafc;
        }

        .finance-po-actions>a,
        .finance-po-actions>form {
            min-width: 0;
        }

        .finance-po-actions>a {
            display: inline-flex;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 10px 16px !important;
            border-radius: 10px;
            font-size: 13px !important;
        }

        .finance-po-approve-form {
            display: flex;
            flex: 1 1 420px;
            flex-direction: row;
            align-items: center;
            gap: 14px;
            min-width: 280px;
            padding: 0;
            border: 0;
            background: transparent;
        }

        .finance-po-approve-form label {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            max-width: none !important;
            color: var(--ink-soft);
            flex: 1 1 auto;
            font-size: 13px !important;
            line-height: 1.45 !important;
        }

        .finance-po-approve-form input[type="checkbox"] {
            flex: 0 0 auto;
            margin-top: 2px;
        }

        .finance-po-approve-form button,
        .finance-po-actions>form:not(.finance-po-approve-form) button {
            min-height: 46px;
            width: auto;
            white-space: nowrap;
            border-radius: 10px;
            padding: 10px 18px !important;
            font-size: 13px !important;
        }

        .finance-po-actions>form:not(.finance-po-approve-form) {
            display: flex;
            flex: 0 0 auto;
            align-items: center;
        }

        @media (max-width: 900px) {
            .finance-po-approval-card.finance-po-has-evidence {
                grid-template-columns: minmax(0, 1fr);
            }

            .finance-po-actions {
                grid-column: 1;
            }
        }

        @media (max-width: 560px) {
            .finance-po-approval-card {
                padding: 16px;
            }

            .finance-po-approval-card .finance-req-top {
                flex-direction: column;
                align-items: flex-start;
            }

            .finance-po-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
                padding: 12px;
            }

            .finance-po-actions>a,
            .finance-po-actions>form,
            .finance-po-approve-form {
                min-width: 0;
                width: 100%;
            }

            .finance-po-approve-form {
                grid-column: 1 / -1;
                flex-direction: column;
                align-items: stretch;
            }

            .finance-po-approve-form button {
                width: 100%;
            }

            .finance-po-actions>form:not(.finance-po-approve-form) {
                grid-column: 1 / -1;
            }

            .finance-po-actions>form:not(.finance-po-approve-form) button {
                width: 100%;
            }
        }

        @media (max-width: 560px) {
            .finance-po-signature {
                width: 100%;
                box-sizing: border-box;
            }

            .finance-po-actions {
                grid-template-columns: 1fr;
            }

            .finance-po-approve-form {
                grid-column: auto;
            }
        }

        .finance-restock-actions>a {
            align-self: center;
            min-height: 38px;
            min-width: 0 !important;
            width: 150px;
            border-radius: 9px;
            font-weight: 700;
            padding: 7px 8px !important;
            font-size: 12px !important;
        }

        .finance-decision-actions {
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: flex-start;
            gap: 16px;
            min-width: 0;
            width: 100%;
        }

        .finance-decision-buttons {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex: 0 0 auto;
            margin-left: auto;
        }

        .finance-approval-form {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .finance-approval-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 !important;
            color: var(--ink-soft);
            line-height: 1.35;
            max-width: none;
            flex: 1 1 auto;
            min-width: 0;
            font-size: 12px;
        }

        .finance-approval-check input {
            flex: 0 0 auto;
            margin-top: 2px;
        }

        .finance-reject-form .action-btn {
            background: #dc2626 !important;
            color: #fff !important;
            border: 1px solid #b91c1c !important;
            box-shadow: 0 2px 8px rgba(220, 38, 38, .2) !important;
        }

        @media (max-width: 900px) {

            .finance-req-actions,
            .finance-restock-actions {
                grid-template-columns: 1fr;
                width: 100%;
            }

            .finance-req-actions>a {
                min-height: 42px;
            }

            .finance-decision-actions {
                width: 100%;
            }

            .finance-restock-actions {
                padding: 10px;
            }

            .finance-restock-actions .finance-decision-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .finance-restock-actions .finance-approval-check {
                width: 100%;
            }

            .finance-restock-actions .finance-decision-buttons {
                width: 100%;
                flex-wrap: wrap;
                justify-content: flex-start;
                margin-left: 0;
            }
        }

        @media (max-width: 650px) {

            .finance-decision-actions,
            .finance-decision-buttons,
            .finance-approval-form,
            .finance-reject-form {
                width: 100%;
            }

            .finance-decision-buttons .action-btn {
                flex: 1;
            }
        }

        .finance-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 700;
            background: var(--mustard-pale);
            color: #7A5A1E;
            white-space: nowrap;
        }

        html[data-theme="dark"] .finance-badge {
            color: var(--mustard);
        }
    </style>
</head>

<body>
    <div class="app-shell">

        <!-- SIDEBAR -->
        <?php $active = 'finance';
        include 'includes/sidebar.php'; ?>

        <!-- MAIN -->
        <div class="main-area">
            <header class="topbar">
                <div class="topbar-icon"><?= $__icon_finance ?></div>
                <h1>Finance</h1>
                <div class="topbar-user"><span
                        class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img
                                src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>"
                                alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'F') ?><?php endif; ?></span>
                    <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Finance') ?></span>
                </div>
            </header>

            <main class="content">

                <?php if ($success_message): ?>
                    <div class="alert alert--success"><?= clean($success_message) ?></div>
                <?php endif; ?>
                <?php if ($error_message): ?>
                    <div class="alert alert--error"><?= clean($error_message) ?></div>
                <?php endif; ?>

                <!-- PAYROLL AWAITING FINANCE APPROVAL -->
                <div class="panel" id="payroll-approval">
                    <div class="panel-header">
                        <h2>Payroll Awaiting Finance Approval
                            <?php if (!empty($payroll_awaiting_finance_approval)): ?>
                                <span class="finance-badge"
                                    style="margin-left:6px;"><?= count($payroll_awaiting_finance_approval) ?></span>
                            <?php endif; ?>
                        </h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">HR submissions and legacy unreleased
                            payroll remain hidden from employees until this Finance/Admin decision and the separate
                            release step.</span>
                    </div>
                    <div style="padding:0 22px 22px; display:grid; gap:14px;">
                        <?php if (empty($payroll_awaiting_finance_approval)): ?>
                            <p style="color:var(--ink-soft); font-size:14px; padding:8px 0;">No payroll requests are
                                awaiting Finance approval.</p>
                        <?php else:
                            foreach ($payroll_awaiting_finance_approval as $pa): ?>
                                <div class="finance-req-card">
                                    <div class="finance-req-top">
                                        <div>
                                            <div class="finance-req-name"><?= clean($pa['periodLabel']) ?></div>
                                            <div class="finance-req-meta">
                                                Submitted by <?= clean($pa['submittedBy'] ?: '—') ?>
                                                &middot; <?= count($pa['rows']) ?> employee(s)
                                                &middot; Payout date
                                                <?= !empty($pa['payoutDate']) ? clean(date('M d, Y', strtotime($pa['payoutDate']))) : '—' ?>
                                                &middot; Total net pay <strong><?= peso($pa['total']) ?></strong>
                                            </div>
                                        </div>
                                        <span class="finance-badge">PENDING FINANCE APPROVAL</span>
                                    </div>
                                    <div style="overflow-x:auto; margin:10px 0;">
                                        <table class="data-table data-table--simple">
                                            <thead>
                                                <tr>
                                                    <th>Employee</th>
                                                    <th>Department</th>
                                                    <th>Status</th>
                                                    <th style="text-align:right;">Net Pay</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pa['rows'] as $pr): ?>
                                                    <tr>
                                                        <td><?= clean($pr['name']) ?></td>
                                                        <td><?= clean($pr['department'] ?: '—') ?></td>
                                                        <td><?= clean($pr['status']) ?></td>
                                                        <td style="text-align:right;"><?= peso($pr['net']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if ($is_finance_role): ?>
                                        <?php foreach (['approve_payroll' => 'Approve for Release', 'reject_payroll' => 'Reject Payroll'] as $payrollAction => $label): ?>
                                            <form method="POST" action="finance.php" style="display:inline-block; margin:4px;"
                                                data-confirm="<?= clean($payrollAction === 'approve_payroll' ? 'Approve this payroll for Finance release?' : 'Reject this payroll request?') ?>"
                                                data-confirm-title="<?= clean($payrollAction === 'approve_payroll' ? 'Approve Payroll' : 'Reject Payroll') ?>"
                                                data-confirm-danger="<?= $payrollAction === 'reject_payroll' ? '1' : '0' ?>"
                                                data-confirm-ok="<?= clean($payrollAction === 'approve_payroll' ? 'Yes, approve' : 'Yes, reject') ?>">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="<?= clean($payrollAction) ?>">
                                                <input type="hidden" name="period_start" value="<?= clean($pa['periodStart']) ?>">
                                                <input type="hidden" name="period_end" value="<?= clean($pa['periodEnd']) ?>">
                                                <input type="hidden" name="generated_by" value="<?= (int) $pa['generatedBy'] ?>">
                                                <button type="submit"
                                                    class="action-btn<?= $payrollAction === 'reject_payroll' ? ' finance-reject-btn' : '' ?>"
                                                    style="padding:8px 18px; font-size:13.5px;"><?= clean($label) ?></button>
                                            </form>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- FINANCE-APPROVED PAYROLL AWAITING RELEASE -->
                <div class="panel" id="payroll-release">
                    <div class="panel-header">
                        <h2>Payroll Awaiting Release
                            <?php if (!empty($payroll_awaiting_release)): ?>
                                <span class="finance-badge"
                                    style="margin-left:6px;"><?= count($payroll_awaiting_release) ?></span>
                            <?php endif; ?>
                        </h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">Already approved by Finance/Admin.
                            Releasing marks the salary as paid, deducts it from Available Cash, and emails each employee
                            their
                            payslip.<?= $is_finance_role ? '' : ' (View only — only Finance/Admin can release.)' ?></span>
                    </div>
                    <div style="padding:0 22px 22px; display:grid; gap:14px;">
                        <?php if (empty($payroll_awaiting_release)): ?>
                            <p style="color:var(--ink-soft); font-size:14px; padding:8px 0;">No approved payroll is waiting
                                for release right now.</p>
                        <?php else: ?>
                            <?php foreach ($payroll_awaiting_release as $pb): ?>
                                <?php
                                $pb_short = !empty($cash_position['payroll_data_available'])
                                    ? max(0, $pb['releasable'] - (float) $cash_position['available_cash'])
                                    : 0;
                                $pb_confirm = 'Release ' . peso($pb['releasable']) . ' for ' . $pb['periodLabel'] . '? This marks the salary as paid and emails each employee.';
                                ?>
                                <div class="finance-req-card">
                                    <div class="finance-req-top">
                                        <div>
                                            <div class="finance-req-name"><?= clean($pb['periodLabel']) ?></div>
                                            <div class="finance-req-meta">
                                                Prepared by <?= clean($pb['preparedBy'] ?: '—') ?>
                                                &middot; <?= count($pb['rows']) ?> employee(s)
                                                &middot; Payout date
                                                <?= !empty($pb['payoutDate']) ? clean(date('M d, Y', strtotime($pb['payoutDate']))) : '—' ?>
                                                &middot; Total net pay <strong><?= peso($pb['total']) ?></strong>
                                            </div>
                                        </div>
                                        <span class="finance-badge">AWAITING RELEASE</span>
                                    </div>

                                    <div style="overflow-x:auto; margin:10px 0;">
                                        <table class="data-table data-table--simple">
                                            <thead>
                                                <tr>
                                                    <th>Employee</th>
                                                    <th>Department</th>
                                                    <th>Payout</th>
                                                    <th style="text-align:right;">Net Pay</th>
                                                    <th>Approved by</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pb['rows'] as $pr): ?>
                                                    <tr<?= $pr['blocked'] !== '' ? ' style="background:#FFF7F6;"' : '' ?>>
                                                        <td>
                                                            <?= clean($pr['name']) ?>
                                                            <?php if ($pr['blocked'] !== ''): ?>
                                                                <div style="font-size:11.5px; color:var(--red-alert); font-weight:700;">
                                                                    ⚠ <?= clean($pr['blocked']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= clean($pr['dept'] ?: '—') ?></td>
                                                        <td><?= clean($pr['method']) ?></td>
                                                        <td style="text-align:right;"><?= peso($pr['net']) ?></td>
                                                        <td>
                                                            <?= clean($pr['approvedBy'] ?: '—') ?>
                                                            <?php if (!empty($pr['approvedAt'])): ?>
                                                                <div style="font-size:11.5px; color:var(--ink-soft);">
                                                                    <?= clean(date('M d, Y g:i A', strtotime($pr['approvedAt']))) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if (empty($cash_position['payroll_data_available'])): ?>
                                        <div style="background:var(--red-pale); color:var(--red-alert); border-radius:10px; padding:10px 14px; font-size:13px; margin-bottom:10px;">
                                            ⚠ Cannot verify available cash against payroll records. No salary can be released until the HRMS connection is restored.
                                        </div>
                                    <?php elseif ($pb_short > 0): ?>
                                        <div
                                            style="background:var(--red-pale); color:var(--red-alert); border-radius:10px; padding:10px 14px; font-size:13px; margin-bottom:10px;">
                                            ⚠ Not enough Available Cash: this release needs <?= peso($pb['releasable']) ?> but only
                                            <?= peso($cash_position['available_cash']) ?> is available
                                            (short by <strong><?= peso($pb_short) ?></strong>). Add capital under <em>Profit &amp;
                                                Business Capital</em> below first.
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($is_finance_role): ?>
                                        <form method="POST" action="finance.php" class="finance-req-actions"
                                            style="align-items:center;" data-confirm="<?= clean($pb_confirm) ?>"
                                            data-confirm-title="Release Payroll" data-confirm-ok="Yes, release">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="release_payroll">
                                            <input type="hidden" name="period_start" value="<?= clean($pb['periodStart']) ?>">
                                            <input type="hidden" name="period_end" value="<?= clean($pb['periodEnd']) ?>">
                                            <input type="hidden" name="generated_by" value="<?= (int) $pb['generatedBy'] ?>">
                                            <input type="text" name="reference" maxlength="100"
                                                placeholder="Payment reference no. (optional)"
                                                style="padding:9px 12px; border:1.5px solid var(--line); border-radius:10px; background:var(--white); min-width:240px;">
                                            <button type="submit" class="action-btn" style="padding:8px 18px; font-size:13.5px;"
                                                <?= $pb['releasable'] <= 0 || empty($cash_position['payroll_data_available']) || $pb_short > 0 ? 'disabled' : '' ?>>Release Salary
                                                (<?= peso($pb['releasable']) ?>)</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- RESTOCK REQUESTS AWAITING FINANCE -->
                <div class="panel" id="restock-requests">
                    <div class="panel-header">
                        <h2>Restock Requests Awaiting Finance Approval</h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">Items in the same request and from the same supplier are reviewed and decided together.</span>
                    </div>
                    <div style="padding:0 22px 22px; display:grid; gap:14px;">
                        <?php if (empty($pending_finance_requests)): ?>
                            <p style="color:var(--ink-soft); font-size:14px; padding:8px 0;">No restock requests are
                                currently waiting on Finance.</p>
                        <?php else: ?>
                            <?php foreach ($pending_finance_groups as $financeGroup): ?>
                                <?php
                                $r = $financeGroup['request'];
                                $groupItems = $financeGroup['items'];
                                $financeApproveId = 'financeApprove' . (int) $r['request_id'];
                                $groupRequestNumbers = implode(', ', array_map(
                                    static function ($item) {
                                        return '#' . (int) $item['request_id'];
                                    },
                                    $groupItems
                                ));
                                ?>
                                <div class="finance-req-card">
                                    <div class="finance-req-top">
                                        <div>
                                            <div class="finance-req-name">
                                                <?= count($groupItems) > 1 ? 'Restock Request — ' . count($groupItems) . ' items' : clean($r['item_name']) ?>
                                            </div>
                                            <div class="finance-req-meta">
                                                Request line<?= count($groupItems) === 1 ? '' : 's' ?> <?= clean($groupRequestNumbers) ?>
                                                &middot; Requested by
                                                <?= clean($requester_names[$r['requested_by_employee_id']] ?? ('Employee #' . $r['requested_by_employee_id'])) ?>
                                                &middot; Validated by <?= clean($validator_names[$r['validated_by']] ?? '—') ?>
                                                &middot; Supplier: <?= clean($r['supplier_name'] ?? 'Missing supplier') ?>
                                                (<?= clean($r['supplier_email'] ?? 'no email') ?>)
                                                &middot; <?= clean(date('M d, Y', strtotime($r['requested_at']))) ?>
                                                &middot; Total estimated cost: <strong><?= peso($financeGroup['total']) ?></strong>
                                            </div>
                                        </div>
                                        <span class="finance-badge">AWAITING FINANCE — <?= count($groupItems) ?> ITEM<?= count($groupItems) === 1 ? '' : 'S' ?></span>
                                    </div>
                                    <div class="finance-formality-letter">
                                        <div>
                                            <strong>Review the formality letter</strong>
                                            <span>One combined letter for all <?= count($groupItems) ?> item<?= count($groupItems) === 1 ? '' : 's' ?> in this request.</span>
                                        </div>
                                        <a href="procurement_letter.php?request_id=<?= enc_id($r['request_id']) ?>" target="_blank" rel="noopener">View Formality Letter</a>
                                    </div>
                                    <div style="overflow-x:auto;">
                                        <table class="data-table data-table--simple">
                                            <thead>
                                                <tr>
                                                    <th>Item</th>
                                                    <th>Quantity</th>
                                                    <th style="text-align:right;">Estimated cost</th>
                                                    <th>Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($groupItems as $item): ?>
                                                    <tr>
                                                        <td>
                                                            <?= clean($item['item_name']) ?>
                                                            <?= $item['linked_product_name'] ? '' : '<span style="font-weight:400;color:var(--ink-soft);font-size:12px;"> (new item — not yet in catalog)</span>' ?>
                                                        </td>
                                                        <td><?= (int) $item['quantity'] ?></td>
                                                        <td style="text-align:right;"><?= $item['estimated_cost'] !== null ? peso($item['estimated_cost']) : '—' ?></td>
                                                        <td><?= clean($item['notes'] ?: '—') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="signature-comparison">
                                        <div><strong>Before: reference signature</strong>
                                            <?php $beforeSignature = $r['requester_signature_reference'] ?: $r['requester_signature']; ?>
                                            <?php if (hasDrawnPngSignature($beforeSignature)): ?><img
                                                    src="signature_image.php?request_id=<?= rawurlencode(enc_id($r['request_id'])) ?>&amp;field=<?= !empty($r['requester_signature_reference']) ? 'requester_signature_reference' : 'requester_signature' ?>"
                                                    alt="Reference Inventory Staff signature"><?php else: ?><span
                                                    class="signature-missing">No reference signature enrolled</span><?php endif; ?>
                                        </div>
                                        <div><strong>After: signature on request</strong>
                                            <?php if (hasDrawnPngSignature($r['requester_signature'])): ?><img
                                                    src="signature_image.php?request_id=<?= rawurlencode(enc_id($r['request_id'])) ?>&amp;field=requester_signature"
                                                    alt="Submitted Inventory Staff signature"><?php else: ?><span
                                                    class="signature-missing">No submitted signature</span><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="finance-req-actions finance-restock-actions">
                                        <div class="finance-decision-actions">
                                            <label class="finance-approval-check">
                                                <input type="checkbox" name="requester_signature_confirmed" value="1"
                                                    form="<?= clean($financeApproveId) ?>" required>
                                                I reviewed all item lines and the combined formality letter, and confirmed the requester signature.
                                            </label>
                                            <div class="finance-decision-buttons">
                                                <form id="<?= clean($financeApproveId) ?>"
                                                    class="finance-approval-form" method="POST" action="finance.php"
                                                    onsubmit="return handleRestockFinanceApprove(event, this)">
                                                    <input type="hidden" name="requester_signature_confirmed" value="1"
                                                        disabled>
                                                    <?php csrfField(); ?>
                                                    <input type="hidden" name="action" value="finance_approve">
                                                    <input type="hidden" name="request_id"
                                                        value="<?= (int) $r['request_id'] ?>">
                                                    <input type="hidden" name="item_count" value="<?= count($groupItems) ?>">
                                                    <input type="hidden" name="finance_signature" value="">
                                                    <button type="submit" class="action-btn"
                                                        style="padding:8px 18px; font-size:13.5px;">Approve</button>
                                                </form>
                                                <form class="finance-reject-form" method="POST" action="finance.php"
                                                    onsubmit="return handleFinanceReject(event, this)">
                                                    <?php csrfField(); ?>
                                                    <input type="hidden" name="action" value="finance_reject">
                                                    <input type="hidden" name="request_id"
                                                        value="<?= (int) $r['request_id'] ?>">
                                                    <input type="hidden" name="reject_reason" value="">
                                                    <button type="submit" class="action-btn finance-reject-btn"
                                                        style="padding:8px 18px; font-size:13.5px;">Reject</button>
                                                </form>
                                                <form method="POST" action="finance.php"
                                                    data-confirm="Return this request to Inventory Staff for supplier or cost correction?"
                                                    data-confirm-title="Return for Correction" data-confirm-ok="Yes, return">
                                                    <?php csrfField(); ?>
                                                    <input type="hidden" name="action" value="finance_return_restock">
                                                    <input type="hidden" name="request_id"
                                                        value="<?= (int) $r['request_id'] ?>">
                                                    <input type="hidden" name="return_reason"
                                                        value="supplier details or request data need correction">
                                                    <button type="submit" class="action-btn"
                                                        style="padding:8px 18px; font-size:13.5px; background:var(--white); color:var(--ink); border:1.5px solid var(--line); box-shadow:none;">Return
                                                        for Correction</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- SUPPLIER SIGNATURE INVITATIONS -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Awaiting Supplier Signature</h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">Grouped restock requests are sent to Admin for PO processing only after the supplier signs the complete Formality Letter.</span>
                    </div>
                    <div style="padding:0 22px 22px; display:grid; gap:14px;">
                        <?php if (empty($pending_supplier_requests)): ?>
                            <p style="color:var(--ink-soft); font-size:14px; padding:8px 0;">No requests are currently
                                waiting on supplier signatures.</p>
                        <?php else: ?>
                            <?php foreach ($pending_supplier_groups as $supplierGroup): ?>
                                <?php
                                $r = $supplierGroup['request'];
                                $supplierItems = $supplierGroup['items'];
                                $supplierRequestNumbers = implode(', ', array_map(static function ($item) {
                                    return '#' . (int) $item['request_id'];
                                }, $supplierItems));
                                ?>
                                <div class="finance-req-card">
                                    <div class="finance-req-top">
                                        <div>
                                            <div class="finance-req-name"><?= count($supplierItems) > 1 ? 'Restock Request — ' . count($supplierItems) . ' items' : clean($r['item_name']) ?></div>
                                            <div class="finance-req-meta">
                                                Request<?= count($supplierItems) > 1 ? ' lines' : '' ?> <?= clean($supplierRequestNumbers) ?> &middot;
                                                <?= count($supplierItems) > 1 ? 'Total estimated cost: ' . clean(peso($supplierGroup['total'])) . ' · ' : 'Qty: ' . (int) $r['quantity'] . ' · ' ?>
                                                Supplier: <?= clean($r['supplier_name'] ?? 'Missing supplier') ?>
                                                (<?= clean($r['supplier_email'] ?? 'no email') ?>) &middot;
                                                Link expiry:
                                                <?= !empty($r['supplier_approval_expires_at']) ? clean(date('M d, Y h:i A', strtotime($r['supplier_approval_expires_at']))) : 'not set' ?>
                                            </div>
                                        </div>
                                        <span class="finance-badge">AWAITING SUPPLIER</span>
                                    </div>
                                    <?php if (count($supplierItems) > 1): ?>
                                        <ul style="margin:12px 0 0;padding-left:22px;">
                                            <?php foreach ($supplierItems as $item): ?>
                                                <li><?= clean($item['item_name']) ?> — <?= (int) $item['quantity'] ?> pcs — <?= clean(peso($item['estimated_cost'] ?? 0)) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <form method="POST" action="finance.php" style="margin-top:12px;">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="finance_resend_supplier_invite">
                                        <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                                        <button type="submit" class="action-btn"
                                            style="padding:8px 18px; font-size:13.5px;">Resend Invitation</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- PURCHASE ORDERS AWAITING FINANCE APPROVAL -->
                <div class="panel finance-po-panel" id="purchase-orders">
                    <div class="panel-header">
                        <h2>Purchase Orders Awaiting Finance Approval</h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">Approve the total cost to let Inventory
                            Staff order and receive it, or reject it outright. Stock is never affected here — it only
                            updates once the delivery is actually received.</span>
                    </div>
                    <div style="padding:0 22px 22px; display:grid; gap:14px;">
                        <?php if (empty($pending_po_requests)): ?>
                            <p style="color:var(--ink-soft); font-size:14px; padding:8px 0;">No Purchase Orders are
                                currently waiting on Finance.</p>
                        <?php else: ?>
                            <?php foreach ($pending_po_requests as $po): ?>
                                <div class="finance-req-card finance-po-approval-card<?= (!empty($po['requested_by_hrms_user_id']) || !empty($po['notes'])) ? ' finance-po-has-evidence' : '' ?>">
                                    <div class="finance-req-top">
                                        <div>
                                            <div class="finance-req-name"><?= clean($po['po_number']) ?> &mdash;
                                                <?= clean($po['supplier_name']) ?></div>
                                            <div class="finance-req-meta">
                                                Department: <strong><?= clean($po['department'] ?? 'Unassigned') ?></strong>
                                                &middot;
                                                Items: <strong><?= (int) $po['item_count'] ?></strong>
                                                &middot; Total: <strong><?= peso($po['total_amount']) ?></strong>
                                                &middot; Requested by <?= clean($po['created_by_name'] ?? '—') ?>
                                                &middot;
                                                <?= clean(date('M d, Y', strtotime($po['submitted_at'] ?? $po['created_at']))) ?>
                                            </div>
                                        </div>
                                        <span class="finance-badge">AWAITING FINANCE</span>
                                    </div>
                                    <?php if (!empty($po['requested_by_hrms_user_id']) || !empty($po['notes'])): ?>
                                    <div class="finance-po-evidence">
                                    <?php if (!empty($po['requested_by_hrms_user_id'])): ?>
                                        <?php if (hasDrawnPngSignature($po['requester_signature'] ?? '')): ?>
                                            <div class="finance-po-signature">
                                                <strong style="font-size:13px;">Requester e-signature</strong>
                                                <img src="<?= clean($po['requester_signature']) ?>" alt="Requester e-signature">
                                                <?php if (!empty($po['requester_signature_at'])): ?><small>Signed <?= clean(date('M j, Y g:i A', strtotime($po['requester_signature_at']))) ?></small><?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="finance-req-notes" style="color:var(--red-alert);">Requester e-signature is missing. This PO cannot be approved.</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($po['notes'])): ?>
                                        <div class="finance-req-notes"><?= clean($po['notes']) ?></div>
                                    <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="finance-po-actions">
                                        <?php if (!empty($po['requested_by_hrms_user_id'])): ?>
                                            <a href="purchase_order_letter.php?po_id=<?= rawurlencode(enc_id((int) $po['id'])) ?>"
                                                class="action-btn action-btn--secondary"
                                                style="padding:8px 18px; font-size:13.5px;">View Formality Letter</a>
                                        <?php endif; ?>
                                        <a href="purchase_orders.php?po=<?= enc_id($po['id']) ?>"
                                            class="action-btn action-btn--secondary"
                                            style="padding:8px 18px; font-size:13.5px;">View Details</a>
                                        <?php if (($_SESSION['role'] ?? '') === 'finance'): ?>
                                        <form method="POST" action="finance.php"
                                            class="<?= !empty($po['requested_by_hrms_user_id']) ? 'finance-po-approve-form' : '' ?>"
                                            onsubmit="return <?= !empty($po['requested_by_hrms_user_id']) ? 'handlePoFinanceApprove' : 'handleFinanceApprove' ?>(event, this)">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="finance_approve_po">
                                            <input type="hidden" name="po_id" value="<?= (int) $po['id'] ?>">
                                            <?php if (!empty($po['requested_by_hrms_user_id'])): ?>
                                                <input type="hidden" name="finance_signature" value="">
                                                <label>
                                                    <input type="checkbox" name="requester_signature_confirmed" value="1" required>
                                                    <span>I reviewed the Formality Letter and verified the requester signature.</span>
                                                </label>
                                            <?php endif; ?>
                                            <button type="submit" class="action-btn"
                                                style="padding:8px 18px; font-size:13.5px;"><?= !empty($po['requested_by_hrms_user_id']) ? 'Review, Sign & Approve' : 'Approve' ?></button>
                                        </form>
                                        <form method="POST" action="finance.php"
                                            onsubmit="return handleFinanceReject(event, this)">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="finance_reject_po">
                                            <input type="hidden" name="po_id" value="<?= (int) $po['id'] ?>">
                                            <input type="hidden" name="reject_reason" value="">
                                            <button type="submit" class="action-btn finance-reject-btn"
                                                style="padding:8px 18px; font-size:13.5px;">Reject</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>HR / Supervisor POs Awaiting Supplier E-signature</h2>
                        <span style="font-size:12.5px;color:var(--ink-soft);">Finance-approved, signed Formality Letters sent to suppliers. After supplier approval, the PO moves to Admin for receipt.</span>
                    </div>
                    <div style="padding:0 22px 22px;display:grid;gap:14px;">
                        <?php if (empty($pending_hr_po_supplier_signatures)): ?>
                            <p style="color:var(--ink-soft);font-size:14px;padding:8px 0;">No HR or Supervisor Purchase Orders are waiting on a supplier signature.</p>
                        <?php else: ?>
                            <?php foreach ($pending_hr_po_supplier_signatures as $po): ?>
                                <div class="finance-req-card">
                                    <div class="finance-req-top">
                                        <div>
                                            <div class="finance-req-name"><?= clean($po['po_number']) ?> &mdash; <?= clean($po['supplier_name']) ?></div>
                                            <div class="finance-req-meta">Department: <?= clean($po['department']) ?> · <?= (int) $po['item_count'] ?> item(s) · Total <?= peso($po['total_amount']) ?> · Supplier <?= clean($po['supplier_email'] ?: 'email unavailable') ?></div>
                                        </div>
                                        <span class="finance-badge">AWAITING SUPPLIER</span>
                                    </div>
                                    <div class="finance-req-actions">
                                        <a href="purchase_order_letter.php?po_id=<?= rawurlencode(enc_id((int) $po['id'])) ?>" class="action-btn action-btn--secondary" style="padding:8px 18px;font-size:13.5px;">View Formality Letter</a>
                                        <a href="purchase_orders.php?po=<?= enc_id((int) $po['id']) ?>" class="action-btn action-btn--secondary" style="padding:8px 18px;font-size:13.5px;">View PO Details</a>
                                        <?php if (($_SESSION['role'] ?? '') === 'finance'): ?>
                                            <form method="POST" action="finance.php">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="finance_resend_po_supplier_invite">
                                                <input type="hidden" name="po_id" value="<?= (int) $po['id'] ?>">
                                                <button type="submit" class="action-btn" style="padding:8px 18px;font-size:13.5px;">Resend Supplier Invitation</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- DISCOUNT REQUESTS AWAITING APPROVAL -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Discount Requests Awaiting Approval</h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">Manual percent/fixed discounts a cashier
                            keys in at the register — approve to apply it to their cart, or reject it. (Senior Citizen /
                            PWD discounts are legally mandated and ID-verified, so they don't need this step.)</span>
                    </div>
                    <div style="padding:0 22px 22px; display:grid; gap:14px;">
                        <?php if (empty($pending_discount_requests)): ?>
                            <p style="color:var(--ink-soft); font-size:14px; padding:8px 0;">No discount requests are
                                currently waiting on Finance.</p>
                        <?php else: ?>
                            <?php foreach ($pending_discount_requests as $dr): ?>
                                <?php
                                $dr_label = $dr['discount_type'] === 'percent'
                                    ? ((float) $dr['discount_value'] . '% Discount')
                                    : ('Fixed Discount (' . peso($dr['discount_value']) . ')');
                                ?>
                                <div class="finance-req-card">
                                    <div class="finance-req-top">
                                        <div>
                                            <div class="finance-req-name"><?= clean($dr_label) ?></div>
                                            <div class="finance-req-meta">
                                                Cart subtotal <?= peso($dr['subtotal_at_request']) ?>
                                                &middot; Discount amount
                                                <strong><?= peso($dr['computed_discount_amount']) ?></strong>
                                                &middot; Requested by
                                                <?= clean($dr['cashier_name'] ?? ('User #' . $dr['cashier_id'])) ?>
                                                &middot; <?= clean(date('M d, Y h:i A', strtotime($dr['requested_at']))) ?>
                                            </div>
                                        </div>
                                        <span class="finance-badge">AWAITING FINANCE</span>
                                    </div>
                                    <div class="finance-req-actions">
                                        <form method="POST" action="finance.php"
                                            onsubmit="return handleFinanceApprove(event, this)">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="finance_approve_discount">
                                            <input type="hidden" name="request_id" value="<?= (int) $dr['request_id'] ?>">
                                            <button type="submit" class="action-btn"
                                                style="padding:8px 18px; font-size:13.5px;">Approve</button>
                                        </form>
                                        <form method="POST" action="finance.php"
                                            onsubmit="return handleFinanceReject(event, this)">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="finance_reject_discount">
                                            <input type="hidden" name="request_id" value="<?= (int) $dr['request_id'] ?>">
                                            <input type="hidden" name="reject_reason" value="">
                                            <button type="submit" class="action-btn finance-reject-btn"
                                                style="padding:8px 18px; font-size:13.5px;">Reject</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- PRODUCT DISCOUNT CHANGES AWAITING APPROVAL -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Product Discount Changes Awaiting Approval</h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">A discount edited on a product's Edit
                            form (Inventory/Products) — approve to apply it on the shelf/register price, or reject to
                            keep the product's current discount.</span>
                    </div>
                    <div style="padding:0 22px 22px; display:grid; gap:14px;">
                        <?php if (empty($pending_product_discount_requests)): ?>
                            <p style="color:var(--ink-soft); font-size:14px; padding:8px 0;">No product discount changes are
                                currently waiting on Finance.</p>
                        <?php else: ?>
                            <?php foreach ($pending_product_discount_requests as $pdr): ?>
                                <?php
                                $new_label = $pdr['discount_type'] === 'none'
                                    ? 'Remove discount'
                                    : ($pdr['discount_type'] === 'percent'
                                        ? ((float) $pdr['discount_value'] . '% off')
                                        : ('Save ' . peso($pdr['discount_value'])));
                                $old_label = ($pdr['old_discount_type'] ?? 'none') === 'none'
                                    ? 'No discount'
                                    : (($pdr['old_discount_type'] === 'percent')
                                        ? ((float) $pdr['old_discount_value'] . '% off')
                                        : ('Save ' . peso($pdr['old_discount_value'])));
                                ?>
                                <div class="finance-req-card">
                                    <div class="finance-req-top">
                                        <div>
                                            <div class="finance-req-name">
                                                <?= clean($pdr['product_name'] ?? ('Product #' . $pdr['product_id'])) ?></div>
                                            <div class="finance-req-meta">
                                                Current: <strong><?= clean($old_label) ?></strong>
                                                &middot; Requested: <strong><?= clean($new_label) ?></strong>
                                                <?php if (!empty($pdr['discount_reason'])): ?> —
                                                    <?= clean($pdr['discount_reason']) ?>        <?php endif; ?>
                                                &middot; Requested by
                                                <?= clean($pdr['requester_name'] ?? ('User #' . $pdr['requested_by'])) ?>
                                                &middot; <?= clean(date('M d, Y h:i A', strtotime($pdr['requested_at']))) ?>
                                            </div>
                                        </div>
                                        <span class="finance-badge">AWAITING FINANCE</span>
                                    </div>
                                    <div class="finance-req-actions">
                                        <form method="POST" action="finance.php"
                                            onsubmit="return handleFinanceApprove(event, this)">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="finance_approve_product_discount">
                                            <input type="hidden" name="request_id" value="<?= (int) $pdr['request_id'] ?>">
                                            <button type="submit" class="action-btn"
                                                style="padding:8px 18px; font-size:13.5px;">Approve</button>
                                        </form>
                                        <form method="POST" action="finance.php"
                                            onsubmit="return handleFinanceReject(event, this)">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="finance_reject_product_discount">
                                            <input type="hidden" name="request_id" value="<?= (int) $pdr['request_id'] ?>">
                                            <input type="hidden" name="reject_reason" value="">
                                            <button type="submit" class="action-btn finance-reject-btn"
                                                style="padding:8px 18px; font-size:13.5px;">Reject</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="GET" action="finance.php" class="date-filter-form">
                    <div class="field-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" value="<?= clean($date_from) ?>">
                    </div>
                    <div class="field-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" value="<?= clean($date_to) ?>">
                    </div>
                    <button type="submit" class="action-btn" style="padding:10px 22px; font-size:14px">Filter</button>
                    <a href="finance.php" class="action-btn"
                        style="padding:10px 22px; font-size:14px; background:var(--white); color:var(--ink-soft); border:1.5px solid var(--line); box-shadow:none;">Reset</a>
                    <span style="font-size:13px; color:var(--ink-soft); margin-left:auto;">
                        Showing: <?= date('M d, Y', strtotime($date_from)) ?> —
                        <?= date('M d, Y', strtotime($date_to)) ?>
                    </span>
                </form>

                <!-- PROFIT & BUSINESS CAPITAL -->
                <div class="panel" id="business-capital">
                    <div class="panel-header">
                        <h2>Profit &amp; Business Capital</h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">This is what HRMS Payroll uses to check
                            whether earnings are enough to cover payroll</span>
                    </div>
                    <div class="summary-row" style="padding:0 22px 6px;">
                        <div class="stat-card accent-green">
                            <span class="stat-label">Gross Profit (selected range)</span>
                            <span class="stat-value"><?= peso($profit_period['gross_profit']) ?></span>
                            <span style="font-size:12px; color:var(--ink-soft);">Revenue
                                <?= peso($profit_period['revenue']) ?> − COGS <?= peso($profit_period['cogs']) ?></span>
                        </div>
                        <div class="stat-card accent-red">
                            <span class="stat-label">Restock Cash Paid (all-time)</span>
                            <span class="stat-value"><?= peso($cash_position['restock_cost']) ?></span>
                            <span style="font-size:12px; color:var(--ink-soft);">Direct restocks and supplier payments already paid</span>
                        </div>
                        <div class="stat-card accent-red">
                            <span class="stat-label">Operating Expenses Paid (all-time)</span>
                            <span class="stat-value"><?= peso($cash_position['operating_expenses_paid']) ?></span>
                            <span style="font-size:12px; color:var(--ink-soft);">Bills recorded as paid; deducted from available cash</span>
                        </div>
                        <div class="stat-card accent-mustard">
                            <span class="stat-label">Business Cash Position (all-time)</span>
                            <span class="stat-value"
                                style="<?= !empty($cash_position['payroll_data_available']) && $cash_position['available_cash'] < 0 ? 'color:var(--red-alert, #C8472D);' : '' ?>"><?= !empty($cash_position['payroll_data_available']) ? peso($cash_position['available_cash']) : 'Unavailable' ?></span>
                            <span style="font-size:12px; color:var(--ink-soft);">Sales Revenue − Restock Cost − Operating Expenses Paid + Capital In − Capital Out − Payroll Released</span>
                        </div>
                        <div class="stat-card accent-leaf">
                            <span class="stat-label">Payroll Released (all-time, HRMS)</span>
                            <span class="stat-value"><?= peso($cash_position['payroll_released']) ?></span>
                        </div>
                    </div>

                    <?php if (empty($cash_position['payroll_data_available'])): ?>
                        <div style="margin:16px 22px 0; background:var(--red-pale); color:var(--red-alert); border:1px solid var(--red-pale); border-radius:12px; padding:12px 16px; font-size:13.5px; line-height:1.5;">
                            ⚠️ Hindi ma-verify ang Business Cash Position dahil hindi available ang HRMS payroll records. Huwag mag-release ng bayad hangga't hindi maibalik ang koneksyon.
                        </div>
                    <?php elseif ($cash_position['available_cash'] < 0): ?>
                        <div
                            style="margin:16px 22px 0; background:var(--red-pale); color:var(--red-alert); border:1px solid var(--red-pale); border-radius:12px; padding:12px 16px; font-size:13.5px; line-height:1.5;">
                            ⚠️ Negative ang Business Cash Position — mas malaki ang naitalang cash outflow kaysa sa available na kita at capital.
                            Gamitin ang <strong>"Add Capital"</strong> sa ibaba para itala ang sariling pera ni Owner na
                            idinagdag sa negosyo.
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($profit_period['unknown_cost_qty'])): ?>
                        <div
                            style="margin:12px 22px 0; background:var(--mustard-pale); color:var(--mustard); border:1px solid var(--line); border-radius:12px; padding:10px 16px; font-size:13px; line-height:1.5;">
                            ℹ️ <?= (int) $profit_period['unknown_cost_qty'] ?> pcs in the selected range have no recorded
                            batch cost (legacy stock from before batch tracking was enabled) — these are excluded from the
                            COGS computation, so Gross Profit may appear slightly higher than actual.
                        </div>
                    <?php endif; ?>

                    <div class="two-col" style="padding:18px 22px 22px;">
                        <div>
                            <h3 style="font-size:15px; margin:0 0 12px;">Capital / Owner Transactions</h3>
                            <div class="panel-body panel-body--table" style="padding:0;">
                                <table class="data-table data-table--simple">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Reason</th>
                                            <th>By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($capital_ledger)): ?>
                                            <tr>
                                                <td colspan="5" class="empty-row">No capital transactions recorded yet.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($capital_ledger as $c): ?>
                                                <tr>
                                                    <td style="font-size:13px; color:var(--ink-soft)">
                                                        <?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                                                    <td>
                                                        <?php if ($c['type'] === 'capital_in'): ?>
                                                            <span style="color:var(--green-mid); font-weight:700;">Capital In</span>
                                                        <?php else: ?>
                                                            <span style="color:var(--red-alert); font-weight:700;">Withdrawal</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="font-weight:700;"><?= peso($c['amount']) ?></td>
                                                    <td style="font-size:13px;"><?= clean($c['reason']) ?></td>
                                                    <td style="font-size:13px; color:var(--ink-soft)">
                                                        <?= clean($c['created_by_name'] ?? '—') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div>
                            <h3 style="font-size:15px; margin:0 0 12px;">Add Capital / Owner Transaction</h3>
                            <form method="POST" action="finance.php"
                                style="background:var(--cream); border-radius:12px; padding:16px; display:grid; gap:12px;">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="add_capital_transaction">
                                <input type="hidden" name="back_qs"
                                    value="<?= clean(http_build_query(['date_from' => $date_from, 'date_to' => $date_to])) ?>">

                                <div class="field-group" style="margin:0;">
                                    <label
                                        style="font-size:12px; font-weight:700; color:var(--ink-soft); display:block; margin-bottom:6px;">Type</label>
                                    <select name="capital_type" required
                                        style="width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:10px; background:var(--white); font-family:var(--font-body);">
                                        <option value="capital_in">Add Capital (Owner cash → business, hal. para sa
                                            shortfall ng payroll)</option>
                                        <option value="capital_out">Owner Withdrawal (kumuha ng pera/profit mula sa
                                            negosyo)</option>
                                    </select>
                                </div>
                                <div class="field-group" style="margin:0;">
                                    <label
                                        style="font-size:12px; font-weight:700; color:var(--ink-soft); display:block; margin-bottom:6px;">Amount</label>
                                    <input type="number" name="amount" min="0.01" step="0.01" required
                                        placeholder="0.00"
                                        style="width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:10px; background:var(--white); font-family:var(--font-body);">
                                </div>
                                <div class="field-group" style="margin:0;">
                                    <label
                                        style="font-size:12px; font-weight:700; color:var(--ink-soft); display:block; margin-bottom:6px;">Reason</label>
                                    <input type="text" name="reason" required maxlength="255"
                                        placeholder="hal. Shortfall sa payroll — Jul 1-15"
                                        style="width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:10px; background:var(--white); font-family:var(--font-body);">
                                </div>
                                <button type="submit" class="action-btn" style="padding:10px 22px; font-size:14px;">Save
                                    Transaction</button>
                            </form>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <style>
        .finance-signature-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, .48)
        }

        .finance-signature-modal.is-open {
            display: flex
        }

        .finance-signature-dialog {
            width: min(620px, 100%);
            padding: 24px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--white);
            box-shadow: 0 24px 60px rgba(15, 23, 42, .25)
        }

        .finance-signature-dialog h2 {
            margin: 0;
            color: var(--ink);
            font-size: 20px
        }

        .finance-signature-dialog p {
            margin: 7px 0 16px;
            color: var(--ink-soft);
            font-size: 13px
        }

        #finance-signature-pad {
            display: block;
            width: 100%;
            height: 180px;
            border: 1px dashed #93c5fd;
            border-radius: 10px;
            background: #fff;
            touch-action: none
        }

        .finance-signature-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 16px
        }

        .finance-signature-actions>div {
            display: flex;
            gap: 8px
        }

        .finance-signature-actions button {
            min-height: 40px;
            padding: 0 14px;
            border-radius: 9px;
            font: 700 13px inherit;
            cursor: pointer
        }

        .finance-signature-secondary {
            border: 1px solid var(--line);
            background: var(--white);
            color: var(--text-accent)
        }

        .finance-signature-primary {
            border: 0;
            background: var(--green-deep);
            color: #fff
        }
    </style>
    <div id="finance-signature-modal" class="finance-signature-modal" aria-hidden="true">
        <div class="finance-signature-dialog" role="dialog" aria-modal="true" aria-labelledby="finance-signature-title">
            <h2 id="finance-signature-title">Sign Finance approval</h2>
            <p>Draw your signature below to approve this restock request.</p>
            <canvas id="finance-signature-pad" width="1040" height="340"
                aria-label="Finance approval signature"></canvas>
            <div class="finance-signature-actions">
                <button type="button" id="finance-signature-clear" class="finance-signature-secondary">Clear</button>
                <div><button type="button" id="finance-signature-cancel"
                        class="finance-signature-secondary">Cancel</button><button type="button"
                        id="finance-signature-save" class="finance-signature-primary">Approve with signature</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function confirmNav(e, url, opts) {
            e.preventDefault();
            Swal.fire(Object.assign({
                showCancelButton: true,
                cancelButtonText: 'Cancel'
            }, opts)).then(function (result) {
                if (result.isConfirmed) window.location.href = url;
            });
            return false;
        }
        function handleFinanceApprove(event, form) {
            event.preventDefault();
            Swal.fire({
                title: 'Approve this request?',
                text: 'It will move to Inventory/Admin for review and fulfillment.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, approve',
                confirmButtonColor: '#123C5C',
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
            return false;
        }

        function handlePoFinanceApprove(event, form) {
            event.preventDefault();
            const confirmation = form.querySelector('input[name="requester_signature_confirmed"]');
            if (!confirmation || !confirmation.checked) {
                Swal.fire({ icon: 'warning', title: 'Confirm requester signature', text: 'Review the Formality Letter and confirm the requester e-signature first.' });
                return false;
            }
            const modal = document.getElementById('finance-signature-modal');
            const canvas = document.getElementById('finance-signature-pad');
            const context = canvas.getContext('2d');
            context.clearRect(0, 0, canvas.width, canvas.height);
            canvas.dataset.drawn = 'false';
            modal.querySelector('h2').textContent = 'Sign Finance approval';
            modal.querySelector('p').textContent = 'Draw your Finance e-signature. The signed Formality Letter will then be sent to the supplier.';
            modal.classList.add('is-open');
            document.getElementById('finance-signature-cancel').onclick = () => modal.classList.remove('is-open');
            document.getElementById('finance-signature-clear').onclick = () => {
                context.clearRect(0, 0, canvas.width, canvas.height);
                canvas.dataset.drawn = 'false';
            };
            document.getElementById('finance-signature-save').onclick = () => {
                if (canvas.dataset.drawn !== 'true') {
                    Swal.fire({ icon: 'warning', title: 'Signature required', text: 'Draw your Finance e-signature before approving.' });
                    return;
                }
                form.querySelector('input[name="finance_signature"]').value = canvas.toDataURL('image/png');
                modal.classList.remove('is-open');
                form.submit();
            };
            return false;
        }

        function handleRestockFinanceApprove(event, form) {
            event.preventDefault();
            const modal = document.getElementById('finance-signature-modal');
            const canvas = document.getElementById('finance-signature-pad');
            const context = canvas.getContext('2d');
            const itemCount = Math.max(1, Number(form.querySelector('input[name="item_count"]')?.value) || 1);
            modal.querySelector('p').textContent = 'Draw your signature below to approve all ' + itemCount + ' item(s) in this restock request together.';
            context.clearRect(0, 0, canvas.width, canvas.height);
            modal.classList.add('is-open');
            document.getElementById('finance-signature-cancel').onclick = () => modal.classList.remove('is-open');
            document.getElementById('finance-signature-clear').onclick = () => context.clearRect(0, 0, canvas.width, canvas.height);
            document.getElementById('finance-signature-save').onclick = () => {
                form.querySelector('input[name="finance_signature"]').value = canvas.toDataURL('image/png');
                modal.classList.remove('is-open');
                form.submit();
            };
            return false;
        }

        const financeSignatureCanvas = document.getElementById('finance-signature-pad');
        if (financeSignatureCanvas) {
            const financeSignatureContext = financeSignatureCanvas.getContext('2d');
            financeSignatureContext.strokeStyle = '#123c5c';
            financeSignatureContext.lineWidth = 4;
            financeSignatureContext.lineCap = 'round';
            let financeDrawing = false;
            const financePoint = event => {
                const rect = financeSignatureCanvas.getBoundingClientRect();
                const source = event.touches ? event.touches[0] : event;
                return { x: (source.clientX - rect.left) * financeSignatureCanvas.width / rect.width, y: (source.clientY - rect.top) * financeSignatureCanvas.height / rect.height };
            };
            const financeStart = event => { financeDrawing = true; financeSignatureCanvas.dataset.drawn = 'true'; financeSignatureContext.beginPath(); const point = financePoint(event); financeSignatureContext.moveTo(point.x, point.y); event.preventDefault(); };
            const financeMove = event => { if (!financeDrawing) return; const point = financePoint(event); financeSignatureContext.lineTo(point.x, point.y); financeSignatureContext.stroke(); event.preventDefault(); };
            const financeStop = () => { financeDrawing = false; };
            ['mousedown', 'touchstart'].forEach(name => financeSignatureCanvas.addEventListener(name, financeStart, { passive: false }));
            ['mousemove', 'touchmove'].forEach(name => financeSignatureCanvas.addEventListener(name, financeMove, { passive: false }));
            ['mouseup', 'mouseleave', 'touchend'].forEach(name => financeSignatureCanvas.addEventListener(name, financeStop));
        }

        function handleFinanceReject(event, form) {
            event.preventDefault();
            Swal.fire({
                title: 'Reject this request?',
                input: 'text',
                inputPlaceholder: 'Reason (optional)',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, reject',
                confirmButtonColor: '#C8472D',
            }).then((result) => {
                if (result.isConfirmed) {
                    form.querySelector('input[name="reject_reason"]').value = result.value || '';
                    form.submit();
                }
            });
            return false;
        }

        document.addEventListener('submit', function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm') || form.dataset.confirming === '1') return;
            event.preventDefault();
            form.dataset.confirming = '1';
            Swal.fire({
                title: form.getAttribute('data-confirm-title') || 'Are you sure?',
                text: form.getAttribute('data-confirm') || '',
                icon: form.getAttribute('data-confirm-danger') === '1' ? 'warning' : 'question',
                showCancelButton: true,
                confirmButtonText: form.getAttribute('data-confirm-ok') || 'Yes, proceed',
                cancelButtonText: 'Cancel',
                confirmButtonColor: form.getAttribute('data-confirm-danger') === '1' ? '#C8472D' : '#123C5C'
            }).then(function (result) {
                if (result.isConfirmed) form.submit();
                else form.dataset.confirming = '0';
            });
        });
    </script>
</body>

</html>