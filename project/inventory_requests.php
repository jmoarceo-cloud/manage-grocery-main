<?php
// ============================================================
// inventory_requests.php — Inventory Staff (and Admin) side of
// the Inventory Request workflow. Supervisors submit requests
// from HRMS/supervisor_inventory.php (INSERT into this same
// grocery_pos.inventory_requests table) — this page is where
// Inventory Staff validates them, and later where the delivery
// actually gets received and restocked.
//
// Workflow (v2, confirmed Aug 2026 — see the flow diagram):
// Inventory Staff submits -> 'pending_finance' (supervisor requests
// first pass through 'pending_validation') -> Finance approves ->
// 'pending_supplier' -> supplier signs by email -> the linked Purchase
// Order becomes 'ordered' -> Admin receives the PO delivery -> 'fulfilled'.
// Inventory Staff requests skip the validation step because they
// already submit directly from Procurement; Supervisor requests are
// validated by Inventory Staff before Finance review.
// The Purchase Order receiving action records the delivery and updates
// inventory. Legacy ready_to_receive requests retain their original path.
// ============================================================

require_once 'includes/config.php';
requireInventoryStaff();

$success_message = $_SESSION['flash_success'] ?? '';
$error_message = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Inventory Staff's own HRMS employee_id (set at login — see login.php).
// Used to attribute requests THEY submit, same requested_by_employee_id
// column Supervisors already use from HRMS/supervisor_inventory.php.
$myEmployeeId = (int) ($_SESSION['employee_id'] ?? 0);
$myDepartment = '';
if ($hrmsPdo && $myEmployeeId > 0) {
    try {
        $departmentStmt = $hrmsPdo->prepare("SELECT department FROM employees WHERE employee_id = ? LIMIT 1");
        $departmentStmt->execute([$myEmployeeId]);
        $myDepartment = trim((string) $departmentStmt->fetchColumn());
    } catch (PDOException $e) {
        error_log("Inventory request department lookup skipped: " . $e->getMessage());
    }
}
$requestedProductToken = (string) ($_GET['product_id'] ?? '');
$requestedProductId = dec_id($requestedProductToken);
if ($requestedProductId <= 0 && ctype_digit($requestedProductToken)) {
    $requestedProductId = (int) $requestedProductToken;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';
    $request_id = (int) ($_POST['request_id'] ?? 0);

    // These two actions don't operate on an existing $req row the way
    // approve/reject/fulfill below do, so handle them first.
    if ($action === 'submit_multi_request') {
        $rowsProduct = $_POST['item_product_id'] ?? [];
        $rowsName = $_POST['item_name'] ?? [];
        $rowsQuantity = $_POST['item_quantity'] ?? [];
        $rowsCost = $_POST['item_estimated_cost'] ?? [];
        $rowsProduct = is_array($rowsProduct) ? $rowsProduct : [];
        $rowsName = is_array($rowsName) ? $rowsName : [];
        $rowsQuantity = is_array($rowsQuantity) ? $rowsQuantity : [];
        $rowsCost = is_array($rowsCost) ? $rowsCost : [];
        $notes = trim($_POST['notes'] ?? '');
        $confirmed_name = trim($_POST['requester_full_name'] ?? '');
        $requester_signature = trim($_POST['requester_signature'] ?? '');
        $account_password = (string) ($_POST['account_password'] ?? '');
        $letter_text = trim((string) ($_POST['procurement_letter_text'] ?? ''));
        $supplier_id = (int) ($_POST['supplier_id'] ?? 0);

        $userStmt = $pdo->prepare("SELECT full_name, password FROM users WHERE id = ? AND employee_id = ? AND is_active = 1 LIMIT 1");
        $userStmt->execute([currentPosUserId(), $myEmployeeId]);
        $requesterUser = $userStmt->fetch();

        $requestItems = [];
        $rowCount = max(count($rowsProduct), count($rowsName), count($rowsQuantity), count($rowsCost));
        for ($i = 0; $i < $rowCount; $i++) {
            $pid = trim((string) ($rowsProduct[$i] ?? ''));
            $name = trim((string) ($rowsName[$i] ?? ''));
            $quantity = (int) ($rowsQuantity[$i] ?? 0);
            $estimatedCost = trim((string) ($rowsCost[$i] ?? ''));
            if ($pid === '' && $name === '' && $quantity <= 0 && $estimatedCost === '') {
                continue;
            }
            $requestItems[] = [
                'product_id' => $pid !== '' ? (int) $pid : null,
                'item_name' => $name,
                'quantity' => $quantity,
                'estimated_cost' => $estimatedCost,
            ];
        }

        if ($myEmployeeId <= 0) {
            $error_message = "Your account isn't linked to an HRMS employee record, so a request can't be attributed to you. Please contact Admin.";
        } elseif (!$requesterUser || !password_verify($account_password, $requesterUser['password'])) {
            $error_message = "Your account password is incorrect. Confirm your identity before submitting.";
        } elseif (!preg_match('/^(?:[A-Z][a-z]+)(?: [A-Z][a-z]+)+$/', $confirmed_name) || $confirmed_name !== trim($requesterUser['full_name'])) {
            $error_message = "Enter your full name exactly in proper-name format, for example: Mary Joy Dela Cruz.";
        } elseif (!hasDrawnPngSignature($requester_signature)) {
            $error_message = "Draw your requester e-signature before submitting.";
        } elseif ($letter_text === '') {
            $error_message = "Review and complete the Formality Letter before submitting.";
        } elseif (!$requestItems) {
            $error_message = "Add at least one item to the restock request.";
        } elseif (count($requestItems) > 50) {
            $error_message = "A grouped restock request can contain up to 50 lines.";
        } elseif ($supplier_id <= 0) {
            $error_message = "Select a registered supplier with a valid email address before submitting this request.";
        } elseif ($myDepartment === '') {
            $error_message = "Your HRMS employee record has no department. Ask Admin to assign your department before requesting stock.";
        } else {
            $supplierStmt = $pdo->prepare("SELECT company_name, email FROM suppliers WHERE id = ? AND status = 'active' LIMIT 1");
            $supplierStmt->execute([$supplier_id]);
            $supplier = $supplierStmt->fetch();
            if (!$supplier || !filter_var(trim((string) $supplier['email']), FILTER_VALIDATE_EMAIL)) {
                $error_message = "The selected supplier must be active and have a valid email address before submitting.";
            }

            foreach ($requestItems as &$item) {
                if ($item['quantity'] <= 0) {
                    $error_message = 'Quantity must be a whole number greater than 0 for each item.';
                    break;
                }
                if ($item['product_id'] !== null) {
                    $productStmt = $pdo->prepare("SELECT product_name, cost_price, supplier_id FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
                    $productStmt->execute([$item['product_id']]);
                    $product = $productStmt->fetch();
                    if (!$product || empty($product['supplier_id']) || (int) $product['supplier_id'] !== $supplier_id) {
                        $error_message = "Every selected product must be active and linked to the selected supplier.";
                        break;
                    }
                    $item['item_name'] = (string) $product['product_name'];
                    $item['estimated_cost'] = round((float) $product['cost_price'] * $item['quantity'], 2);
                    if ($item['estimated_cost'] > 99999999.99) {
                        $error_message = 'The estimated total is above the supported request amount.';
                        break;
                    }
                } elseif ($item['item_name'] === '' || mb_strlen($item['item_name']) > 150 || $item['estimated_cost'] === '' || !is_numeric($item['estimated_cost']) || !is_finite((float) $item['estimated_cost']) || (float) $item['estimated_cost'] < 0 || (float) $item['estimated_cost'] > 99999999.99) {
                    $error_message = "New items need a name and a valid estimated unit cost.";
                    break;
                } else {
                    $item['estimated_cost'] = procurementRequestLineEstimateFromUnitCost(
                        (float) $item['estimated_cost'],
                        $item['quantity']
                    );
                    if ($item['estimated_cost'] > 99999999.99) {
                        $error_message = 'The estimated total is above the supported request amount.';
                        break;
                    }
                }
            }
            unset($item);

            if ($error_message === '') {
                $groupBytes = bin2hex(random_bytes(16));
                $groupId = substr($groupBytes, 0, 8) . '-' . substr($groupBytes, 8, 4) . '-4' . substr($groupBytes, 13, 3)
                    . '-a' . substr($groupBytes, 17, 3) . '-' . substr($groupBytes, 20, 12);
                try {
                    $pdo->beginTransaction();
                    $insert = $pdo->prepare("
                        INSERT INTO inventory_requests
                            (requested_by_employee_id, request_group_id, request_department, product_id, supplier_id, item_name, quantity,
                             estimated_cost, validated_by, validated_at, notes, status, requester_full_name,
                             requester_signature, requester_signature_at, procurement_letter)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, 'pending_finance', ?, ?, NOW(), ?)
                    ");
                    $createdRequestIds = [];
                    foreach ($requestItems as $item) {
                        $letter = "Date: " . date('F j, Y') . "\n\nPROCUREMENT FORMALITY LETTER\n\n"
                            . "Item name: " . $item['item_name'] . "\n"
                            . "Quantity: " . $item['quantity'] . " pcs\n"
                            . "Supplier: " . $supplier['company_name'] . "\n"
                            . "Estimated total cost: " . peso($item['estimated_cost']) . "\n\n"
                            . "Requested by: " . $requesterUser['full_name'] . "\n\n" . $letter_text;
                        $letterHtml = '<h1>Formality Letter</h1><div style="white-space:pre-wrap">' . htmlspecialchars($letter, ENT_QUOTES, 'UTF-8') . '</div>';
                        $insert->execute([
                            $myEmployeeId, $groupId, $myDepartment, $item['product_id'], $supplier_id, $item['item_name'], $item['quantity'],
                            $item['estimated_cost'], $notes ?: null, $requesterUser['full_name'],
                            $requester_signature, $letterHtml,
                        ]);
                        $createdRequestIds[] = (int) $pdo->lastInsertId();
                    }
                    $pdo->commit();
                    if ($createdRequestIds) {
                        pushRoleNotification(
                            $pdo,
                            ['finance', 'admin'],
                            'inventory_request',
                            count($createdRequestIds) . ' restock item(s) are awaiting Finance approval from ' . $requesterUser['full_name'] . '.',
                            'finance.php#restock-requests',
                            'inventory_request',
                            $createdRequestIds[0],
                            currentPosUserId()
                        );
                    }
                    $success_message = count($requestItems) . " restock item(s) submitted for Finance approval.";
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("Submit multi-item inventory request error: " . $e->getMessage());
                    $error_message = "Could not submit this request. Please try again.";
                }
            }
        }
        checkLowStock($pdo);
        $_SESSION['flash_success'] = $success_message;
        $_SESSION['flash_error'] = $error_message;
        redirect('inventory_requests.php?status=pending_finance');
    } elseif ($action === 'submit_request') {
        $product_id = $_POST['product_id'] ?? '';
        $item_name = trim($_POST['item_name'] ?? '');
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $confirmed_name = trim($_POST['requester_full_name'] ?? '');
        $requester_signature = trim($_POST['requester_signature'] ?? '');
        $account_password = (string) ($_POST['account_password'] ?? '');
        $letter_text = trim((string) ($_POST['procurement_letter_text'] ?? ''));
        $est_cost_input = trim($_POST['estimated_cost'] ?? '');
        // Optional — left blank keeps this request cash-only, exactly the
        // original behavior (see the ACCOUNTS PAYABLE MODULE comment in
        // includes/config.php). Attaching a supplier here means its
        // eventual receipt/fulfillment cost becomes an Accounts Payable
        // liability instead of immediate cash.
        $supplier_id = $_POST['supplier_id'] ?? '';
        $supplier_id = ($supplier_id !== '') ? (int) $supplier_id : null;

        $userStmt = $pdo->prepare("SELECT full_name, password FROM users WHERE id = ? AND employee_id = ? AND is_active = 1 LIMIT 1");
        $userStmt->execute([currentPosUserId(), $myEmployeeId]);
        $requesterUser = $userStmt->fetch();
        $signatureRaw = '';
        if (preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $requester_signature)) {
            $signatureRaw = base64_decode(substr($requester_signature, 22), true);
        }
        $pid = $product_id !== '' ? (int) $product_id : null;
        if ($myEmployeeId <= 0) {
            $error_message = "Your account isn't linked to an HRMS employee record, so a request can't be attributed to you. Please contact Admin.";
        } elseif (!$requesterUser || !password_verify($account_password, $requesterUser['password'])) {
            $error_message = "Your account password is incorrect. Confirm your identity before submitting.";
        } elseif (!preg_match('/^(?:[A-Z][a-z]+)(?: [A-Z][a-z]+)+$/', $confirmed_name) || $confirmed_name !== trim($requesterUser['full_name'])) {
            $error_message = "Enter your full name exactly in proper-name format, for example: Mary Joy Dela Cruz.";
        } elseif (!hasDrawnPngSignature($requester_signature)) {
            $error_message = "Draw your requester e-signature before submitting.";
        } elseif ($letter_text === '') {
            $error_message = "Review and complete the Formality Letter before submitting.";
        } elseif ($quantity <= 0) {
            $error_message = "Please enter a valid quantity.";
        } elseif ($item_name === '') {
            $error_message = "Please provide an item name (pick from the list, or type one for a new item).";
        } elseif ($supplier_id === null) {
            $error_message = "Select a registered supplier with a valid email address before submitting this request.";
        } elseif ($est_cost_input !== '' && (!is_numeric($est_cost_input) || $est_cost_input < 0)) {
            $error_message = "Estimated cost must be a valid positive number.";
        } else {
            // Auto-compute the estimated cost Finance will review from the
            // linked product's current cost price; for a brand-new item
            // (no product_id, so no cost_price to read) Inventory Staff
            // enters it directly in the form.
            $estimated_cost = null;
            if ($pid !== null) {
                $cstmt = $pdo->prepare("SELECT product_name, cost_price, supplier_id FROM products WHERE id = ? AND is_active = 1");
                $cstmt->execute([$pid]);
                $selectedProduct = $cstmt->fetch();
                if (!$selectedProduct) {
                    $error_message = "The selected product is no longer active.";
                } elseif (empty($selectedProduct['supplier_id'])) {
                    $error_message = "This product has no registered supplier. Link it to a supplier in Products before requesting procurement.";
                } elseif ((int) $selectedProduct['supplier_id'] !== $supplier_id) {
                    $error_message = "The selected product is not linked to the selected supplier.";
                } else {
                    $item_name = (string) $selectedProduct['product_name'];
                    $estimated_cost = round(((float) $selectedProduct['cost_price']) * $quantity, 2);
                }
            }
            if ($error_message === '' && $estimated_cost === null && $est_cost_input !== '') {
                $estimated_cost = round((float) $est_cost_input, 2);
            }

            if ($error_message === '') {
                try {
                    $supplierStmt = $pdo->prepare("SELECT company_name, email FROM suppliers WHERE id = ? AND status = 'active' LIMIT 1");
                    $supplierStmt->execute([$supplier_id]);
                    $supplier = $supplierStmt->fetch();
                    if (!$supplier || !filter_var(trim((string) $supplier['email']), FILTER_VALIDATE_EMAIL)) {
                        $error_message = "The selected supplier must be active and have a valid email address before submitting.";
                    }
                    if ($error_message === '') {
                        $supplierName = (string) $supplier['company_name'];
                        $authoritativeLetter = "Date: " . date('F j, Y') . "\n\nPROCUREMENT FORMALITY LETTER\n\n"
                            . "Item name: " . $item_name . "\n"
                            . "Quantity: " . $quantity . " pcs\n"
                            . "Supplier: " . $supplierName . "\n"
                            . "Estimated total cost: " . peso($estimated_cost ?? 0) . "\n\n"
                            . "Requested by: " . $requesterUser['full_name'] . "\n\n"
                            . trim($letter_text);
                        $stmt = $pdo->prepare("
                        INSERT INTO inventory_requests (requested_by_employee_id, request_department, product_id, supplier_id, item_name, quantity, estimated_cost, notes, status, requester_full_name, requester_signature, requester_signature_at, procurement_letter)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_finance', ?, ?, NOW(), ?)
                    ");
                        $letter = '<h1>Formality Letter</h1><div style="white-space:pre-wrap">' . htmlspecialchars($authoritativeLetter, ENT_QUOTES, 'UTF-8') . '</div>';
                        $stmt->execute([$myEmployeeId, $myDepartment ?: null, $pid, $supplier_id, $item_name, $quantity, $estimated_cost, $notes ?: null, $requesterUser['full_name'], $requester_signature, $letter]);
                        $requestId = (int) $pdo->lastInsertId();
                        pushRoleNotification(
                            $pdo,
                            ['finance', 'admin'],
                            'inventory_request',
                            'Restock request #' . $requestId . ' is awaiting Finance approval: ' . $item_name . '.',
                            'finance.php#restock-requests',
                            'inventory_request',
                            $requestId,
                            currentPosUserId()
                        );
                        $success_message = "Inventory request submitted for Finance approval.";
                    }
                } catch (PDOException $e) {
                    error_log("Submit inventory request error (inventory_requests.php): " . $e->getMessage());
                    $error_message = "Could not submit this request. Please try again.";
                }
            }
        }
        checkLowStock($pdo);
        $_SESSION['flash_success'] = $success_message;
        $_SESSION['flash_error'] = $error_message;
        redirect('inventory_requests.php?status=pending_finance');
    } elseif ($action === 'cancel_own_request') {
        try {
            $pdo->prepare("DELETE FROM inventory_requests WHERE request_id = ? AND requested_by_employee_id = ? AND status IN ('pending_validation', 'pending_finance')")
                ->execute([$request_id, $myEmployeeId]);
            $success_message = "Request cancelled.";
        } catch (PDOException $e) {
            error_log("Cancel own inventory request error: " . $e->getMessage());
            $error_message = "Could not cancel this request.";
        }
        $_SESSION['flash_success'] = $success_message;
        $_SESSION['flash_error'] = $error_message;
        redirect('inventory_requests.php');
    } elseif ($action === 'create_po_from_requests') {
        $_SESSION['flash_error'] = 'Restock Purchase Orders are now created automatically after Finance approval. Open Purchase Orders to track Finance and supplier approval.';
        redirect('purchase_orders.php?status=approved');
    }

    $stmt = $pdo->prepare("SELECT * FROM inventory_requests WHERE request_id = ?");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch();

    if (!$req) {
        $error_message = "Request not found.";
    } elseif ($action === 'validate') {
        if ($req['status'] !== 'pending_validation') {
            $error_message = "Only requests awaiting validation can be validated.";
        } elseif ((int) $req['requested_by_employee_id'] === $myEmployeeId) {
            $error_message = "You cannot validate your own restock request. Another Procurement/Inventory Staff user must review it.";
        } else {
            $est_cost_input = trim($_POST['estimated_cost'] ?? '');
            // Requests must have an active supplier email so Finance can
            // send the supplier-signature invitation after approval.
            $supplier_id = $_POST['supplier_id'] ?? '';
            $supplier_id = ($supplier_id !== '') ? (int) $supplier_id : null;
            if ($est_cost_input === '' || !is_numeric($est_cost_input) || $est_cost_input < 0) {
                $error_message = "Please enter a valid estimated cost for Finance to review.";
            } else {
                $validatedCost = round((float) $est_cost_input, 2);
                if (!empty($req['product_id'])) {
                    $productStmt = $pdo->prepare("SELECT cost_price, supplier_id FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
                    $productStmt->execute([(int) $req['product_id']]);
                    $validatedProduct = $productStmt->fetch();
                    if (!$validatedProduct) {
                        $error_message = "The linked product is no longer active.";
                    } elseif (empty($validatedProduct['supplier_id'])) {
                        $error_message = "The linked product has no registered supplier. Link it to a supplier before validating.";
                    } elseif ($supplier_id !== null && $supplier_id !== (int) $validatedProduct['supplier_id']) {
                        $error_message = "The selected supplier does not match the product's registered supplier.";
                    } else {
                        $supplier_id = (int) $validatedProduct['supplier_id'];
                        $validatedCost = round((float) $validatedProduct['cost_price'] * (int) $req['quantity'], 2);
                    }
                }
                if ($error_message === '' && $supplier_id !== null) {
                    $supplierStmt = $pdo->prepare("SELECT id, email FROM suppliers WHERE id = ? AND status = 'active' LIMIT 1");
                    $supplierStmt->execute([$supplier_id]);
                    $supplier = $supplierStmt->fetch();
                    if (!$supplier || !filter_var(trim((string) $supplier['email']), FILTER_VALIDATE_EMAIL)) {
                        $error_message = "Select an active supplier with a valid email address before validating this request.";
                    }
                } elseif ($error_message === '') {
                    $error_message = "Select a supplier before validating this request.";
                }
                if ($error_message === '') {
                    $requestDepartment = trim((string) ($req['request_department'] ?? ''));
                    if ($requestDepartment === '' && $hrmsPdo) {
                        try {
                            $departmentStmt = $hrmsPdo->prepare("SELECT department FROM employees WHERE employee_id = ? LIMIT 1");
                            $departmentStmt->execute([(int) $req['requested_by_employee_id']]);
                            $requestDepartment = trim((string) $departmentStmt->fetchColumn());
                        } catch (PDOException $e) {
                            error_log("Legacy request department lookup skipped: " . $e->getMessage());
                        }
                    }
                    if ($requestDepartment === '') {
                        $error_message = "The requester has no HRMS department assigned. Ask Admin to correct the employee record before validating.";
                    } else {
                        $update = $pdo->prepare("UPDATE inventory_requests SET status = 'pending_finance', request_department = ?, estimated_cost = ?, supplier_id = ?, validated_by = ?, validated_at = NOW() WHERE request_id = ? AND status = 'pending_validation'");
                        $update->execute([$requestDepartment, $validatedCost, $supplier_id, currentPosUserId(), $request_id]);
                        if ($update->rowCount() === 1) {
                            pushRoleNotification(
                                $pdo,
                                ['finance', 'admin'],
                                'inventory_request',
                                'Restock request #' . $request_id . ' is awaiting Finance approval: ' . $req['item_name'] . '.',
                                'finance.php#restock-requests',
                                'inventory_request',
                                $request_id,
                                currentPosUserId()
                            );
                            $success_message = "Request validated — now with Finance for approval.";
                        } else {
                            $error_message = "This request changed before validation completed. Refresh the page and review its current status.";
                        }
                    }
                }
            }
        }
    } elseif ($action === 'reject_validation') {
        if ($req['status'] !== 'pending_validation') {
            $error_message = "Only requests awaiting validation can be rejected here.";
        } else {
            $reject_reason = trim($_POST['reject_reason'] ?? '');
            $newNotes = trim(($req['notes'] ?? '') . (!empty($reject_reason) ? ' | Rejected: ' . $reject_reason : ' | Rejected'));
            $pdo->prepare("UPDATE inventory_requests SET status = 'rejected', validated_by = ?, validated_at = NOW(), decided_by = ?, decided_at = NOW(), notes = ? WHERE request_id = ?")
                ->execute([currentPosUserId(), currentPosUserId(), $newNotes, $request_id]);
            $success_message = "Request rejected.";
        }
    } elseif ($action === 'fulfill') {
        if ($req['status'] !== 'ready_to_receive' || !hasDrawnPngSignature($req['supplier_signature'] ?? '')) {
            $error_message = "Only supplier-approved requests can be fulfilled.";
        } elseif (empty($req['product_id'])) {
            $error_message = "This request isn't linked to an existing product yet. Add it via Products first, then use \"Mark Fulfilled (Manual)\" once you've restocked it.";
        } else {
            $quantity = trim($_POST['quantity'] ?? '');
            $expiry_date = trim($_POST['expiry_date'] ?? '');

            if (!ctype_digit((string) $quantity) || $quantity <= 0) {
                $error_message = "Quantity must be a whole number greater than 0.";
            } elseif (!empty($expiry_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date)) {
                $error_message = "Invalid expiry date format.";
            } elseif (!empty($expiry_date) && strtotime($expiry_date) < strtotime('today')) {
                $error_message = "Expiry date cannot be in the past.";
            } else {
                $quantity = (int) $quantity;
                $pstmt = $pdo->prepare("SELECT product_name, stock, cost_price FROM products WHERE id = ?");
                $pstmt->execute([$req['product_id']]);
                $product = $pstmt->fetch();

                if (!$product) {
                    $error_message = "Linked product no longer exists.";
                } else {
                    // The recorded product cost is authoritative. Never
                    // trust a browser-submitted unit cost for restocking.
                    $unit_cost = (float) $product['cost_price'];
                    $previous_stock = (int) $product['stock'];
                    $new_stock = $previous_stock + $quantity;
                    $total_cost = $unit_cost * $quantity;

                    try {
                        $pdo->beginTransaction();

                        $received = $pdo->prepare("UPDATE inventory_requests SET status = 'fulfilled', decided_by = ?, decided_at = NOW(), received_by = ?, received_at = NOW() WHERE request_id = ? AND status = 'ready_to_receive' AND supplier_signature IS NOT NULL");
                        $received->execute([currentPosUserId(), currentPosUserId(), $request_id]);
                        if ($received->rowCount() !== 1) {
                            throw new RuntimeException('Inventory request is no longer ready to receive.');
                        }

                        $pdo->prepare("UPDATE products SET stock = stock + :qty, cost_price = :cp WHERE id = :id")
                            ->execute([':qty' => $quantity, ':cp' => $unit_cost, ':id' => $req['product_id']]);

                        addProductBatch($pdo, $req['product_id'], $quantity, $unit_cost, $expiry_date ?: null, currentPosUserId(), 'Inventory Request #' . $request_id . ' fulfillment');

                        $pdo->prepare("INSERT INTO stock_logs (product_id, change_type, quantity_change, previous_stock, new_stock, reason, total_cost, user_id) VALUES (:pid, 'restock', :qc, :ps, :ns, :r, :tc, :uid)")
                            ->execute([
                                ':pid' => $req['product_id'],
                                ':qc' => $quantity,
                                ':ps' => $previous_stock,
                                ':ns' => $new_stock,
                                ':r' => 'Inventory Request #' . $request_id . ' fulfillment',
                                ':tc' => $total_cost,
                                ':uid' => currentPosUserId(),
                            ]);

                        $notif_message = "Inventory request fulfilled: " . $product['product_name'] . " — +" . $quantity . " pcs. New stock: " . $new_stock . " pcs.";
                        $pdo->prepare("INSERT INTO notifications (product_id, message) VALUES (:pid, :msg)")
                            ->execute([':pid' => $req['product_id'], ':msg' => $notif_message]);
                        $requesterUserStmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? AND is_active = 1 LIMIT 1");
                        $requesterUserStmt->execute([(int) $req['requested_by_employee_id']]);
                        if ($requesterUserId = $requesterUserStmt->fetchColumn()) {
                            $pdo->prepare("INSERT INTO notifications (user_id, category, type, message, link_url, source_type, source_id) VALUES (?, 'workflow', 'inventory_request', ?, 'inventory_requests.php?status=fulfilled', 'inventory_request', ?)")
                                ->execute([$requesterUserId, 'Inventory request #' . $request_id . ' was received and restocked.', $request_id]);
                        }

                        $pdo->commit();
                        // Accounts Payable schema maintenance may execute DDL,
                        // which implicitly commits in MySQL. Create the
                        // liability only after the stock transaction is
                        // safely committed.
                        if (!empty($req['supplier_id'])) {
                            recordAccountsPayable($pdo, 'inventory_request', $request_id, $req['supplier_id'], 'Inventory Request #' . $request_id . ' fulfillment', $total_cost, currentPosUserId());
                        }
                        $success_message = "Fulfilled: " . clean($product['product_name']) . " (+" . $quantity . " pcs).";
                    } catch (Exception $e) {
                        try {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                        } catch (PDOException $rollbackError) {
                            error_log("Fulfill inventory request rollback skipped: " . $rollbackError->getMessage());
                        }
                        error_log("Fulfill inventory request error: " . $e->getMessage());
                        $error_message = "Could not fulfill this request. Please try again.";
                    }
                }
            }
        }
    } elseif ($action === 'mark_fulfilled_manual') {
        if ($req['status'] !== 'ready_to_receive' || !hasDrawnPngSignature($req['supplier_signature'] ?? '')) {
            $error_message = "Only supplier-approved requests can be marked fulfilled.";
        } else {
            try {
                $pdo->beginTransaction();

                $received = $pdo->prepare("UPDATE inventory_requests SET status = 'fulfilled', decided_by = ?, decided_at = NOW(), received_by = ?, received_at = NOW() WHERE request_id = ? AND status = 'ready_to_receive' AND supplier_signature IS NOT NULL");
                $received->execute([currentPosUserId(), currentPosUserId(), $request_id]);
                if ($received->rowCount() !== 1) {
                    throw new RuntimeException('Inventory request is no longer ready to receive.');
                }

                $pdo->commit();
                // Keep AP schema work outside the fulfillment transaction.
                if (!empty($req['supplier_id'])) {
                    recordAccountsPayable($pdo, 'inventory_request', $request_id, $req['supplier_id'], 'Inventory Request #' . $request_id . ' fulfillment', $req['estimated_cost'], currentPosUserId());
                }
                $success_message = "Request marked as fulfilled.";
            } catch (Exception $e) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (PDOException $rollbackError) {
                    error_log("Mark fulfilled manual rollback skipped: " . $rollbackError->getMessage());
                }
                error_log("Mark fulfilled manual error: " . $e->getMessage());
                $error_message = "Could not mark this request as fulfilled. Please try again.";
            }
        }
    }

    checkLowStock($pdo);

    $_SESSION['flash_success'] = $success_message;
    $_SESSION['flash_error'] = $error_message;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: inventory_requests.php' . ($qs !== '' ? ('?' . $qs) : ''));
    exit;
}

// Filters
$status_filter = $_GET['status'] ?? 'pending_validation';
$valid_statuses = ['pending_validation', 'pending_finance', 'pending_supplier', 'ready_to_receive', 'approved', 'rejected', 'fulfilled', 'all'];
if (!in_array($status_filter, $valid_statuses, true))
    $status_filter = 'pending_validation';

$where = ($status_filter === 'all') ? '' : 'WHERE ir.status = :status';
$sql = "
    SELECT ir.*, p.product_name AS linked_product_name, p.stock AS linked_product_stock, p.cost_price AS linked_product_cost_price,
           s.company_name AS supplier_name
    FROM inventory_requests ir
    LEFT JOIN products p ON p.id = ir.product_id
    LEFT JOIN suppliers s ON s.id = ir.supplier_id
    $where
    ORDER BY ir.requested_at DESC
    LIMIT 100
";
$stmt = $pdo->prepare($sql);
if ($status_filter !== 'all') {
    $stmt->execute([':status' => $status_filter]);
} else {
    $stmt->execute();
}
$requests = $stmt->fetchAll();

// Counts per status, for the filter-tab badges
$counts = ['pending_validation' => 0, 'pending_finance' => 0, 'pending_supplier' => 0, 'ready_to_receive' => 0, 'approved' => 0, 'rejected' => 0, 'fulfilled' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) AS c FROM inventory_requests GROUP BY status")->fetchAll() as $row) {
    if (isset($counts[$row['status']]))
        $counts[$row['status']] = (int) $row['c'];
}
if (($_SESSION['role'] ?? '') === 'admin') {
    $counts['approved'] = (int) $pdo->query("
        SELECT COUNT(DISTINCT COALESCE(request_group_id, CONCAT('request:', request_id)))
        FROM inventory_requests
        WHERE status = 'approved'
    ")->fetchColumn();
}
$total_all = array_sum($counts);

$requestDisplayRows = [];
if (($_SESSION['role'] ?? '') === 'admin' && $status_filter === 'approved') {
    foreach ($requests as $request) {
        $groupKey = !empty($request['request_group_id'])
            ? 'group:' . $request['request_group_id']
            : 'request:' . (int) $request['request_id'];
        if (!isset($requestDisplayRows[$groupKey])) {
            $requestDisplayRows[$groupKey] = [
                'request' => $request,
                'items' => [],
            ];
        }
        $requestDisplayRows[$groupKey]['items'][] = $request;
    }
} else {
    foreach ($requests as $request) {
        $requestDisplayRows[] = [
            'request' => $request,
            'items' => [$request],
        ];
    }
}

// Products list — for the "Submit an Inventory Request" form below
// (Inventory Staff requesting a restock of their own, skips straight
// to 'pending_finance' — see the submit_request action above).
$allProducts = [];
$lowStockProducts = [];
try {
    $allProducts = $pdo->query("SELECT id, product_name, category, stock, cost_price, supplier_id FROM products WHERE is_active = 1 ORDER BY product_name")->fetchAll();
    $lowStockProducts = array_values(array_filter($allProducts, fn($p) => (int) $p['stock'] <= LOW_STOCK));
} catch (PDOException $e) {
    error_log("inventory_requests.php products list skipped: " . $e->getMessage());
}

// Suppliers — for the optional "Supplier" dropdown on the Submit form and
// the Validate modal (see getActiveSuppliers() in includes/config.php).
$active_suppliers = getActiveSuppliers($pdo);

// Requesting Supervisors' names live in HRMS, not this database —
// look them up separately and map employee_id => name.
$requester_names = [];
if ($hrmsPdo && !empty($requests)) {
    $ids = array_unique(array_column($requests, 'requested_by_employee_id'));
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $hrmsPdo->prepare("SELECT employee_id, first_name, last_name FROM employees WHERE employee_id IN ($ph)");
            $stmt->execute(array_values($ids));
            foreach ($stmt->fetchAll() as $row) {
                $requester_names[$row['employee_id']] = $row['first_name'] . ' ' . $row['last_name'];
            }
        } catch (PDOException $e) {
            error_log("inventory_requests.php requester lookup skipped: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Requests — HCI GroceryOne</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .filter-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .filter-tab {
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 13.5px;
            font-weight: 600;
            border: 1.5px solid var(--line);
            background: var(--white);
            color: var(--ink-soft);
            text-decoration: none;
            transition: all .15s;
        }

        .filter-tab:hover {
            border-color: var(--green-mid);
            color: var(--green-mid);
        }

        .filter-tab.active {
            background: var(--green-deep);
            color: #FFFFFF;
            border-color: var(--green-deep);
        }

        .filter-tab .badge {
            display: inline-block;
            margin-left: 6px;
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 11.5px;
            font-weight: 700;
            background: var(--green-pale);
            color: var(--text-accent);
        }

        .filter-tab.active .badge {
            background: rgba(255, 255, 255, .25);
            color: #FFFFFF;
        }

        .req-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .req-badge.pending_validation {
            background: var(--mustard-pale);
            color: #8a5c10;
        }

        .req-badge.pending_finance {
            background: #E6F0FB;
            color: #1F4E8C;
        }

        .req-badge.pending_supplier {
            background: #fff7ed;
            color: #9a3412;
        }

        .req-badge.ready_to_receive {
            background: #e6f4ea;
            color: #1a7a3b;
        }

        .req-badge.approved {
            background: #e8eeff;
            color: #2d4ecf;
        }

        .req-badge.rejected {
            background: var(--red-pale);
            color: var(--red-alert);
        }

        .req-badge.fulfilled {
            background: #e6f4ea;
            color: #1a7a3b;
        }

        html[data-theme="dark"] .req-badge.pending_validation {
            color: var(--mustard);
        }

        html[data-theme="dark"] .req-badge.pending_finance {
            background: #182449;
            color: #8fa4ff;
        }

        html[data-theme="dark"] .req-badge.approved {
            background: #182449;
            color: #8fa4ff;
        }

        html[data-theme="dark"] .req-badge.fulfilled {
            background: var(--green-pale);
            color: var(--green-leaf);
        }

        .action-btn--small {
            padding: 6px 12px;
            font-size: 12.5px;
            border-radius: 8px;
        }

        .action-btn--outline {
            background: var(--white);
            border: 1.5px solid var(--line);
            color: var(--ink);
        }

        .action-btn--outline:hover {
            background: var(--green-pale);
            box-shadow: none;
        }

        .actions-cell {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .request-reject-btn {
            background: #dc2626 !important;
            color: #fff !important;
            border: 1px solid #b91c1c !important;
            box-shadow: 0 2px 8px rgba(220, 38, 38, .2) !important;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(20, 30, 25, .45);
            align-items: center;
            justify-content: center;
            z-index: 100;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: var(--white);
            border-radius: 18px;
            padding: 26px 28px;
            width: 420px;
            max-width: 92vw;
        }

        .modal-box h3 {
            font-family: var(--font-display);
            margin: 0 0 4px;
        }

        .modal-box .modal-sub {
            color: var(--ink-soft);
            font-size: 13px;
            margin-bottom: 18px;
        }

        .field-group {
            margin-bottom: 14px;
        }

        .field-label {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--ink-soft);
            display: block;
            margin-bottom: 6px;
        }

        .field-input {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid var(--line);
            border-radius: 10px;
            font: inherit;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 22px;
        }

        .btn-cancel {
            flex: 1;
            padding: 10px;
            border-radius: 10px;
            border: 1.5px solid var(--line);
            background: var(--white);
            font-weight: 600;
            cursor: pointer;
        }

        .btn-confirm {
            flex: 1;
            padding: 10px;
            border-radius: 10px;
            border: none;
            background: var(--green-deep);
            color: #FFFFFF;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-confirm.danger {
            background: var(--red-alert);
        }

        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 13.5px;
            font-weight: 600;
        }

        .alert--success {
            background: #e6f4ea;
            color: #1a7a3b;
        }

        html[data-theme="dark"] .alert--success {
            background: var(--green-pale);
            color: var(--green-leaf);
        }

        .alert--error {
            background: var(--red-pale);
            color: var(--red-alert);
        }

        .procurement-letter-preview {
            margin-top: 12px;
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--cream);
            color: var(--ink);
            white-space: pre-wrap;
            font-size: 13px;
            line-height: 1.6;
        }

        html[data-theme="dark"] .procurement-letter-preview {
            border-color: var(--line);
            background: var(--white);
            color: var(--ink);
        }
    </style>
</head>

<body>

    <div class="app-shell">

        <?php $active = 'inventory_requests';
        include 'includes/sidebar.php'; ?>

        <div class="main-area">
            <header class="topbar">
                <div class="topbar-icon"><?= $__icon_requests ?></div>
                <h1>Inventory Requests</h1>
                <div class="topbar-user">
                    <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img
                                src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>"
                                alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'I') ?><?php endif; ?></span>
                    <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Inventory Staff') ?></span>
                </div>
            </header>

            <main class="content">

                <?php if (!empty($success_message)): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            Swal.fire({
                                icon: 'success',
                                title: 'Completed',
                                text: <?= json_encode(strip_tags($success_message), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 3600,
                                timerProgressBar: true
                            });
                        });
                    </script>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            Swal.fire({
                                icon: 'error',
                                title: 'Action failed',
                                text: <?= json_encode(strip_tags($error_message), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 4200,
                                timerProgressBar: true
                            });
                        });
                    </script>
                <?php endif; ?>

                <div class="panel" style="margin-bottom:22px;" id="submitRequestPanel">
                    <div class="panel-header">
                        <h2>Submit a Restock Request</h2>
                    </div>
                    <div class="panel-body" style="padding:18px 22px;">
                        <p style="color:var(--ink-soft); font-size:13px; margin:0 0 16px;">
                            Submit one or more restock items together. Finance approval creates the linked Purchase
                            Order, and the supplier e-signature moves it to Orders. Admin receives the delivery from
                            the Purchase Order so stock and the request history update together.
                        </p>
                        <form method="POST" id="requestSubmitForm"
                            style="display:grid; grid-template-columns:1.4fr 1fr 1fr 1fr 1fr; gap:14px; align-items:end;">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="submit_multi_request">
                            <div style="grid-column:1 / -1;">
                                <label class="field-label">Requested items</label>
                                <div id="requestItemRows"></div>
                                <button type="button" class="action-btn action-btn--small action-btn--outline" onclick="addRequestItemRow()">+ Add another item</button>
                                <div class="field-hint">Items in this request share one supplier and are sent together to Finance. Existing products must be linked to that supplier.</div>
                            </div>
                            <div class="field-group" style="margin-bottom:0;">
                                <label class="field-label">Full name confirmation</label>
                                <input type="text" name="requester_full_name" class="field-input" required
                                    pattern="[A-Z][a-z]+( [A-Z][a-z]+)+"
                                    title="Use proper capitalization, for example: Mary Joy Dela Cruz"
                                    placeholder="<?= clean($_SESSION['full_name'] ?? '') ?>">
                            </div>
                            <div class="field-group" style="margin-bottom:0;">
                                <label class="field-label">Account password</label>
                                <input type="password" name="account_password" class="field-input" required
                                    autocomplete="current-password">
                            </div>
                            <div class="field-group" style="margin-bottom:0; grid-column:span 2;">
                                <label class="field-label">Requester e-signature</label>
                                <canvas id="requesterSignaturePad" width="420" height="110"
                                    style="width:100%; height:110px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; touch-action:none;"></canvas>
                                <input type="hidden" name="requester_signature" id="requesterSignature">
                                <button type="button" class="action-btn action-btn--small action-btn--outline"
                                    id="clearRequesterSignature">Clear signature</button>
                            </div>
                            <div class="field-group" style="margin-bottom:0;">
                                <label class="field-label">Supplier <span style="font-weight:400;">(required — supplier
                                        must sign by email)</span></label>
                                <select name="supplier_id" id="reqSupplierSelect" class="field-input" required
                                    onchange="onReqSupplierChange()">
                                    <option value="">— Select a supplier —</option>
                                    <?php foreach ($active_suppliers as $sup): ?>
                                        <option value="<?= (int) $sup['id'] ?>"><?= clean($sup['company_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="supplierProductHint"
                                    style="font-size:12px; color:var(--ink-soft); margin-top:6px;">Select an active
                                    supplier with a valid email address. Its linked products will be shown.</div>
                            </div>
                            <div class="field-group" style="margin-bottom:0;">
                                <label class="field-label">Notes <span
                                        style="font-weight:400;">(optional)</span></label>
                                <input type="text" name="notes" class="field-input" placeholder="Reason / urgency">
                            </div>
                            <div class="field-group" style="margin-bottom:0; grid-column:1 / -1;">
                                <label class="field-label">Formality Letter</label>
                                <p style="color:var(--ink-soft); font-size:12.5px; margin:0 0 8px;">
                                    Review the generated letter below and edit any wording or details before confirming
                                    the request.
                                </p>
                                <textarea name="procurement_letter_text" id="procurementLetterText" class="field-input"
                                    rows="9" required></textarea>
                                <div style="margin-top:8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                    <button type="button" class="action-btn action-btn--small action-btn--outline"
                                        id="refreshProcurementLetter">Generate from request details</button>
                                    <span style="font-size:12px; color:var(--ink-soft);">The editable text above is the
                                        version sent for Finance and Supplier review.</span>
                                </div>
                                <div id="procurementLetterPreview" class="procurement-letter-preview"></div>
                            </div>
                            <div style="grid-column:1 / -1;">
                                <button type="submit" class="btn-confirm" style="width:auto; padding:10px 22px;">Submit
                                    Request</button>
                            </div>
                        </form>
                        <template id="requestItemTemplate">
                            <div class="request-item-row" style="display:grid; grid-template-columns:2fr 1fr 1fr 1fr auto; gap:10px; align-items:end; margin:10px 0; padding:12px; border:1px solid var(--line); border-radius:10px;">
                                <div class="field-group" style="margin:0;">
                                    <label class="field-label">Product or new item</label>
                                    <select name="item_product_id[]" class="field-input request-product-select" form="requestSubmitForm" onchange="onRequestItemChange(this)">
                                        <option value="">— New item —</option>
                                        <?php foreach ($allProducts as $p): ?>
                                            <option value="<?= (int) $p['id'] ?>" data-name="<?= clean($p['product_name']) ?>" data-cost="<?= (float) $p['cost_price'] ?>" data-supplier="<?= (int) ($p['supplier_id'] ?? 0) ?>"><?= clean($p['product_name']) ?> (<?= (int) $p['stock'] ?> in stock)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="item_name[]" class="field-input request-item-name" form="requestSubmitForm" placeholder="Required for a new item" style="margin-top:6px;">
                                </div>
                                <div class="field-group" style="margin:0;">
                                    <label class="field-label">Quantity</label>
                                    <input type="number" name="item_quantity[]" class="field-input request-item-quantity" form="requestSubmitForm" min="1" required oninput="updateRequestItemCost(this.closest('.request-item-row'))">
                                </div>
                                <div class="field-group" style="margin:0;">
                                    <label class="field-label">Estimated unit cost (₱)</label>
                                    <input type="number" name="item_estimated_cost[]" class="field-input request-item-cost" form="requestSubmitForm" min="0" step="0.01" placeholder="For new items">
                                </div>
                                <button type="button" class="action-btn action-btn--small action-btn--outline" onclick="this.closest('.request-item-row').remove(); document.getElementById('refreshProcurementLetter').click()">Remove</button>
                            </div>
                        </template>
                    </div>
                </div>

                <?php if (!empty($lowStockProducts)): ?>
                    <div class="panel" style="margin-bottom:22px;">
                        <div class="panel-header">
                            <h2>Low Stock Alert <span class="req-badge rejected">&le; <?= LOW_STOCK ?> units</span></h2>
                        </div>
                        <div class="panel-body panel-body--table">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lowStockProducts as $p): ?>
                                        <tr>
                                            <td><?= clean($p['product_name']) ?></td>
                                            <td><?= clean($p['category']) ?></td>
                                            <td><span class="req-badge rejected"><?= (int) $p['stock'] ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="filter-tabs">
                    <a href="inventory_requests.php?status=pending_validation"
                        class="filter-tab <?= $status_filter == 'pending_validation' ? 'active' : '' ?>">Needs
                        Validation<span class="badge"><?= $counts['pending_validation'] ?></span></a>
                    <a href="inventory_requests.php?status=pending_finance"
                        class="filter-tab <?= $status_filter == 'pending_finance' ? 'active' : '' ?>">Awaiting
                        Finance<span class="badge"><?= $counts['pending_finance'] ?></span></a>
                    <a href="inventory_requests.php?status=ready_to_receive"
                        class="filter-tab <?= $status_filter == 'ready_to_receive' ? 'active' : '' ?>">Approved — Ready
                        to Receive<span class="badge"><?= $counts['ready_to_receive'] ?? 0 ?></span></a>
                    <a href="inventory_requests.php?status=pending_supplier"
                        class="filter-tab <?= $status_filter == 'pending_supplier' ? 'active' : '' ?>">Awaiting
                        Supplier<span class="badge"><?= $counts['pending_supplier'] ?? 0 ?></span></a>
                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                        <a href="inventory_requests.php?status=approved"
                            class="filter-tab <?= $status_filter == 'approved' ? 'active' : '' ?>">Supplier Approved — Purchase Orders<span class="badge"><?= $counts['approved'] ?? 0 ?></span></a>
                    <?php endif; ?>
                    <a href="inventory_requests.php?status=fulfilled"
                        class="filter-tab <?= $status_filter == 'fulfilled' ? 'active' : '' ?>">Fulfilled<span
                            class="badge"><?= $counts['fulfilled'] ?></span></a>
                    <a href="inventory_requests.php?status=rejected"
                        class="filter-tab <?= $status_filter == 'rejected' ? 'active' : '' ?>">Rejected<span
                            class="badge"><?= $counts['rejected'] ?></span></a>
                    <a href="inventory_requests.php?status=all"
                        class="filter-tab <?= $status_filter == 'all' ? 'active' : '' ?>">All<span
                            class="badge"><?= $total_all ?></span></a>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Requests (<?= count($requestDisplayRows) ?>)</h2>
                    </div>
                    <div class="panel-body panel-body--table">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Requested By</th>
                                    <th>Requesting Department</th>
                                    <th>Item</th>
                                    <th>Qty</th>
                                    <th>Est. Cost</th>
                                    <th>Supplier</th>
                                    <th>Notes</th>
                                    <th>Status</th>
                                    <th>Requested</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($requestDisplayRows)): ?>
                                    <tr>
                                        <td colspan="<?= ($_SESSION['role'] ?? '') === 'admin' ? 10 : 9 ?>" class="empty-row">No requests here.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($requestDisplayRows as $displayRow): ?>
                                        <?php
                                        $r = $displayRow['request'];
                                        $groupItems = $displayRow['items'];
                                        $groupedAdminReview = ($_SESSION['role'] ?? '') === 'admin'
                                            && $status_filter === 'approved'
                                            && !empty($r['request_group_id'])
                                            && count($groupItems) > 1;
                                        $groupRequestNumbers = implode(', ', array_map(static function ($item) {
                                            return '#' . (int) $item['request_id'];
                                        }, $groupItems));
                                        $groupTotal = array_sum(array_map(static function ($item) {
                                            return (float) ($item['estimated_cost'] ?? 0);
                                        }, $groupItems));
                                        $groupPurchaseOrderIds = array_values(array_unique(array_filter(array_map(static function ($item) {
                                            return (int) ($item['purchase_order_id'] ?? 0);
                                        }, $groupItems))));
                                        ?>
                                        <tr>
                                            <td style="font-weight:600">
                                                <?= clean($requester_names[$r['requested_by_employee_id']] ?? ('Employee #' . $r['requested_by_employee_id'])) ?>
                                            </td>
                                            <td><?= clean($r['request_department'] ?: 'Department pending HRMS lookup') ?></td>
                                            <td>
                                                <?php if ($groupedAdminReview): ?>
                                                    <strong><?= count($groupItems) ?> items · Requests <?= clean($groupRequestNumbers) ?></strong>
                                                    <ul style="margin:6px 0 0;padding-left:18px;">
                                                        <?php foreach ($groupItems as $item): ?>
                                                            <li style="margin:3px 0;">
                                                                <?= clean($item['item_name']) ?><?= empty($item['product_id']) ? ' (new item)' : '' ?>
                                                                <span style="color:var(--ink-soft);">(<?= (int) $item['quantity'] ?> pcs<?= $item['estimated_cost'] !== null ? ' · ' . clean(peso($item['estimated_cost'])) : '' ?>)</span>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php else: ?>
                                                    <?= clean($r['item_name']) ?>
                                                <?php endif; ?>
                                                <?php if (!$groupedAdminReview && !empty($r['request_group_id'])): ?>
                                                    <span style="display:block;font-size:11px;color:var(--ink-soft);">Multi-item request <?= clean(substr((string) $r['request_group_id'], 0, 8)) ?></span>
                                                <?php endif; ?>
                                                <?php if (!$groupedAdminReview && empty($r['product_id'])): ?>
                                                    <span style="font-size:11px; color:var(--ink-soft);">(new item — not in
                                                        catalog)</span>
                                                <?php elseif (!$groupedAdminReview): ?>
                                                    <span style="font-size:11px; color:var(--ink-soft);">(current stock:
                                                        <?= (int) $r['linked_product_stock'] ?> pcs)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-weight:700">
                                                <?php if ($groupedAdminReview): ?>
                                                    <?= array_sum(array_map(static function ($item) { return (int) $item['quantity']; }, $groupItems)) ?> pcs total
                                                <?php else: ?>
                                                    <?= (int) $r['quantity'] ?> pcs
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size:13px;">
                                                <?= $groupedAdminReview ? peso($groupTotal) : ($r['estimated_cost'] !== null ? peso($r['estimated_cost']) : '—') ?>
                                                <?php if ($groupedAdminReview): ?><span style="display:block;font-size:11px;color:var(--ink-soft);">combined total</span><?php endif; ?>
                                            </td>
                                            <td style="font-size:13px;">
                                                <?= !empty($r['supplier_name']) ? clean($r['supplier_name']) : '<span style="color:var(--ink-soft);">Not assigned</span>' ?>
                                            </td>
                                            <td style="font-size:13px; color:var(--ink-soft); max-width:220px;">
                                                <?php if ($groupedAdminReview): ?>
                                                    <?php foreach ($groupItems as $item): ?>
                                                        <?php if (trim((string) ($item['notes'] ?? '')) !== ''): ?>
                                                            <div><strong><?= clean($item['item_name']) ?>:</strong> <?= clean($item['notes']) ?></div>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                    <?php if (!array_filter($groupItems, static function ($item) { return trim((string) ($item['notes'] ?? '')) !== ''; })): ?>—<?php endif; ?>
                                                <?php else: ?>
                                                    <?= clean($r['notes'] ?? '') ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><span
                                                    class="req-badge <?= clean($r['status']) ?>"><?= $r['status'] === 'pending_finance' ? 'AWAITING FINANCE' : ($r['status'] === 'pending_validation' ? 'NEEDS VALIDATION' : strtoupper($r['status'])) ?></span>
                                            </td>
                                            <td style="font-size:13px; color:var(--ink-soft)">
                                                <?= date('M d, Y h:i A', strtotime($r['requested_at'])) ?></td>
                                            <td class="actions-cell">
                                                <?php if ($groupPurchaseOrderIds): ?>
                                                    <?php foreach ($groupPurchaseOrderIds as $purchaseOrderId): ?>
                                                        <a class="action-btn action-btn--small action-btn--outline" href="purchase_orders.php?po=<?= enc_id($purchaseOrderId) ?>">Track Purchase Order</a>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                    <a class="action-btn action-btn--small action-btn--outline"
                                                        href="procurement_letter.php?request_id=<?= enc_id($r['request_id']) ?>"
                                                        target="_blank" rel="noopener">View Full Formality Letter</a>
                                                <?php if ($r['status'] === 'pending_validation'): ?>
                                                    <button class="action-btn action-btn--small"
                                                        onclick="openValidate(<?= (int) $r['request_id'] ?>, <?= htmlspecialchars(json_encode((string) $r['item_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>, <?= (int) $r['quantity'] ?>, <?= (int) $r['linked_product_stock'] ?: 0 ?>, <?= (float) ($r['linked_product_cost_price'] ?? 0) ?>, <?= empty($r['product_id']) ? 'true' : 'false' ?>, <?= (int) ($r['supplier_id'] ?? 0) ?>)">Validate
                                                        / Confirm</button>
                                                    <button
                                                        class="action-btn action-btn--small action-btn--outline request-reject-btn"
                                                        onclick="openReject(<?= $r['request_id'] ?>)">Reject</button>
                                                <?php elseif ($r['status'] === 'pending_finance'): ?>
                                                    <?php if ((int) $r['requested_by_employee_id'] === $myEmployeeId): ?>
                                                        <form method="POST" style="display:inline;"
                                                            data-confirm="Cancel this inventory request?"
                                                            data-confirm-title="Cancel Request" data-confirm-danger="1"
                                                            data-confirm-ok="Yes, cancel">
                                                            <?php csrfField(); ?>
                                                            <input type="hidden" name="action" value="cancel_own_request">
                                                            <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
                                                            <button type="submit"
                                                                class="action-btn action-btn--small action-btn--outline">Cancel</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span style="font-size:12px; color:var(--ink-soft);">Awaiting Finance</span>
                                                    <?php endif; ?>
                                                <?php elseif ($r['status'] === 'pending_supplier'): ?>
                                                    <span style="font-size:12px; color:var(--ink-soft);">Awaiting supplier
                                                        signature</span>
                                                <?php elseif ($r['status'] === 'ready_to_receive' && !empty($r['product_id'])): ?>
                                                    <button class="action-btn action-btn--small"
                                                        onclick="openFulfill(<?= (int) $r['request_id'] ?>, <?= htmlspecialchars(json_encode((string) $r['item_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>, <?= (int) $r['quantity'] ?>, <?= json_encode((float) ($r['linked_product_cost_price'] ?? 0)) ?>)">Receive
                                                        &amp; Restock</button>
                                                <?php elseif ($r['status'] === 'ready_to_receive' && empty($r['product_id'])): ?>
                                                    <form method="POST" style="display:inline;"
                                                        data-confirm="Add this item through Products first, then mark this request as fulfilled?"
                                                        data-confirm-title="Mark as Fulfilled" data-confirm-ok="Yes, fulfill">
                                                        <?php csrfField(); ?>
                                                        <input type="hidden" name="action" value="mark_fulfilled_manual">
                                                        <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
                                                        <button type="submit"
                                                            class="action-btn action-btn--small action-btn--outline">Mark Fulfilled
                                                            (Manual)</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span style="font-size:12px; color:var(--ink-soft);">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <!-- REJECT MODAL (validation stage) -->
    <div class="modal-overlay" id="rejectModal">
        <div class="modal-box">
            <h3>Reject Request</h3>
            <p class="modal-sub">Optional: tell the requester why.</p>
            <form method="POST" action="inventory_requests.php">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="reject_validation">
                <input type="hidden" name="request_id" id="rejectRequestId">
                <div class="field-group">
                    <label class="field-label">Reason (optional)</label>
                    <input type="text" name="reject_reason" class="field-input"
                        placeholder="e.g. Not needed this month">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="btn-confirm danger">Reject Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- VALIDATE MODAL -->
    <div class="modal-overlay" id="validateModal">
        <div class="modal-box">
            <h3>Validate Request</h3>
            <p class="modal-sub" id="validateItemName"></p>
            <form method="POST" action="inventory_requests.php">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="validate">
                <input type="hidden" name="request_id" id="validateRequestId">
                <div class="field-group">
                    <label class="field-label">Requested Quantity</label>
                    <input type="text" id="validateQuantity" class="field-input" disabled>
                </div>
                <div class="field-group" id="validateStockGroup">
                    <label class="field-label">Actual Current Stock</label>
                    <input type="text" id="validateStock" class="field-input" disabled>
                </div>
                <div class="field-group">
                    <label class="field-label">Estimated Cost for Finance (₱)</label>
                    <input type="number" name="estimated_cost" id="validateEstCost" class="field-input" min="0"
                        step="0.01" required>
                </div>
                <div class="field-group">
                    <label class="field-label">Supplier <span style="font-weight:400;">(required for supplier
                            approval)</span></label>
                    <select name="supplier_id" id="validateSupplier" class="field-input" required>
                        <option value="">— Select a supplier —</option>
                        <?php foreach ($active_suppliers as $sup): ?>
                            <option value="<?= (int) $sup['id'] ?>"><?= clean($sup['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('validateModal')">Cancel</button>
                    <button type="submit" class="btn-confirm">Validate / Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <!-- FULFILL MODAL -->
    <div class="modal-overlay" id="fulfillModal">
        <div class="modal-box">
            <h3>Fulfill Request</h3>
            <p class="modal-sub" id="fulfillItemName"></p>
            <form method="POST" action="inventory_requests.php">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="fulfill">
                <input type="hidden" name="request_id" id="fulfillRequestId">
                <div class="field-group">
                    <label class="field-label">Quantity to Add</label>
                    <input type="number" name="quantity" id="fulfillQuantity" class="field-input" min="1" required>
                </div>
                <div class="field-group">
                    <label class="field-label">Cost per Unit (₱) <span
                            style="color:var(--ink-soft); font-weight:400">(from recorded product price)</span></label>
                    <input type="text" id="fulfillCostPrice" class="field-input" readonly aria-readonly="true">
                </div>
                <div class="field-group">
                    <label class="field-label">Expiry Date of This Batch <span
                            style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                    <input type="date" name="expiry_date" class="field-input" min="<?= date('Y-m-d') ?>">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('fulfillModal')">Cancel</button>
                    <button type="submit" class="btn-confirm">Fulfill (Restock)</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const requesterPad = document.getElementById('requesterSignaturePad');
        if (requesterPad) {
            const requesterCtx = requesterPad.getContext('2d');
            let requesterDrawing = false;
            let requesterHasSignature = false;
            function requesterPoint(e) {
                const r = requesterPad.getBoundingClientRect();
                const t = e.touches ? e.touches[0] : e;
                return { x: (t.clientX - r.left) * requesterPad.width / r.width, y: (t.clientY - r.top) * requesterPad.height / r.height };
            }
            const letterText = document.getElementById('procurementLetterText');
            const letterPreview = document.getElementById('procurementLetterPreview');
            function procurementLetterDraft() {
                const items = Array.from(document.querySelectorAll('.request-item-row')).map(function (row) {
                    const name = row.querySelector('.request-item-name')?.value.trim() || '[item name]';
                    const qty = row.querySelector('.request-item-quantity')?.value || '[quantity]';
                    const unitCost = parseFloat(row.querySelector('.request-item-cost')?.value || '0');
                    const total = (unitCost * (parseInt(qty, 10) || 0)).toFixed(2);
                    return '• ' + name + ' — ' + qty + ' pcs (unit ₱ ' + unitCost.toFixed(2) + ', total ₱ ' + total + ')';
                }).join('\n') || '[requested items]';
                const supplier = document.getElementById('reqSupplierSelect')?.selectedOptions[0]?.textContent.trim() || 'No supplier selected';
                const notes = document.querySelector('input[name="notes"]')?.value.trim();
                const requester = <?= json_encode((string) ($_SESSION['full_name'] ?? 'Inventory Staff'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                return 'Date: <?= date('F j, Y') ?>\n\nPROCUREMENT FORMALITY LETTER\n\nThis letter formally requests the following restock items:\n' + items + '\n\nRequested by: ' + requester + '\nSupplier: ' + supplier + (notes ? '\nNotes: ' + notes : '') + '\n\nThis request is subject to Finance and Supplier approval before receiving and restocking.';
            }
            function refreshProcurementLetter(force) {
                if (!letterText) return;
                if (force || !letterText.value.trim()) letterText.value = procurementLetterDraft();
                if (letterPreview) letterPreview.textContent = letterText.value;
            }
            ['input', 'change'].forEach(function (eventName) {
                document.getElementById('requestSubmitForm')?.addEventListener(eventName, function (e) {
                    if (e.target !== letterText) refreshProcurementLetter(true);
                    if (letterPreview && letterText) letterPreview.textContent = letterText.value;
                });
            });
            document.getElementById('refreshProcurementLetter')?.addEventListener('click', function () {
                refreshProcurementLetter(true);
            });
            refreshProcurementLetter(false);
            function requesterStart(e) { e.preventDefault(); requesterDrawing = true; const p = requesterPoint(e); requesterCtx.beginPath(); requesterCtx.moveTo(p.x, p.y); }
            function requesterMove(e) { if (!requesterDrawing) return; e.preventDefault(); requesterHasSignature = true; const p = requesterPoint(e); requesterCtx.lineTo(p.x, p.y); requesterCtx.strokeStyle = '#123c5c'; requesterCtx.lineWidth = 2; requesterCtx.lineCap = 'round'; requesterCtx.stroke(); }
            ['mousedown', 'touchstart'].forEach(ev => requesterPad.addEventListener(ev, requesterStart, { passive: false }));
            ['mousemove', 'touchmove'].forEach(ev => requesterPad.addEventListener(ev, requesterMove, { passive: false }));
            ['mouseup', 'mouseleave', 'touchend'].forEach(ev => requesterPad.addEventListener(ev, () => { requesterDrawing = false; }));
            document.getElementById('clearRequesterSignature').addEventListener('click', () => { requesterHasSignature = false; requesterCtx.clearRect(0, 0, requesterPad.width, requesterPad.height); });
            document.getElementById('requestSubmitForm').addEventListener('submit', (event) => {
                if (!requesterHasSignature) {
                    event.preventDefault();
                    Swal.fire({ icon: 'warning', title: 'Signature required', text: 'Please draw your e-signature before submitting the request.', confirmButtonText: 'Okay' });
                    requesterPad.focus();
                    return false;
                }
                document.getElementById('requesterSignature').value = requesterPad.toDataURL('image/png');
            });
        }
        // If we arrived here from inventory.php's "Request Restock" button
        // (?product_id=..), pre-select that product in the form below so the
        // user only has to fill in quantity/notes and hit Submit — instead of
        // landing on the page with nothing pre-filled and no obvious next step.
        document.addEventListener('DOMContentLoaded', function () {
            addRequestItemRow();
            const refreshButton = document.getElementById('refreshProcurementLetter');
            if (refreshButton) refreshButton.click();
            const pid = <?= json_encode((string) $requestedProductId) ?>;
            if (!pid) return;
            const sel = document.querySelector('.request-product-select');
            if (sel) {
                sel.value = pid;
                if (sel.value === pid) {
                    onRequestItemChange(sel);
                }
            }
            const panel = document.getElementById('submitRequestPanel');
            if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            const qty = document.querySelector('.request-item-quantity');
            if (qty) qty.focus();
        });
        function addRequestItemRow() {
            const template = document.getElementById('requestItemTemplate');
            document.getElementById('requestItemRows').appendChild(template.content.cloneNode(true));
            onReqSupplierChange();
            const refreshButton = document.getElementById('refreshProcurementLetter');
            if (refreshButton) refreshButton.click();
        }
        function onRequestItemChange(select) {
            const row = select.closest('.request-item-row');
            const option = select.options[select.selectedIndex];
            const nameInput = row.querySelector('.request-item-name');
            const costInput = row.querySelector('.request-item-cost');
            const supplier = document.getElementById('reqSupplierSelect');
            if (select.value) {
                nameInput.value = option.getAttribute('data-name') || '';
                nameInput.readOnly = true;
                costInput.readOnly = true;
                const linkedSupplier = option.getAttribute('data-supplier') || '';
                if (linkedSupplier && supplier && !supplier.value) supplier.value = linkedSupplier;
                onReqSupplierChange();
                updateRequestItemCost(row);
            } else {
                nameInput.value = '';
                nameInput.readOnly = false;
                costInput.value = '';
                costInput.readOnly = false;
                nameInput.focus();
            }
            const refreshButton = document.getElementById('refreshProcurementLetter');
            if (refreshButton) refreshButton.click();
        }
        function onReqSupplierChange() {
            const supplier = document.getElementById('reqSupplierSelect');
            const selectedSupplier = supplier ? supplier.value : '';
            let visibleProducts = 0;
            document.querySelectorAll('.request-product-select').forEach(function (productSelect) {
                Array.from(productSelect.options).forEach(function (option, index) {
                    if (index === 0) return;
                    const matches = !selectedSupplier || option.getAttribute('data-supplier') === selectedSupplier;
                    option.hidden = !matches;
                    option.disabled = !matches;
                    if (matches) visibleProducts++;
                });
                if (productSelect.value && productSelect.options[productSelect.selectedIndex].disabled) {
                    productSelect.value = '';
                    onRequestItemChange(productSelect);
                }
            });
            const hint = document.getElementById('supplierProductHint');
            if (hint) {
                hint.textContent = selectedSupplier
                    ? (visibleProducts ? visibleProducts + ' linked product' + (visibleProducts === 1 ? '' : 's') + ' shown for this supplier.' : 'No products are linked to this supplier yet.')
                    : 'Choose a supplier to show only its linked products.';
            }
        }
        function updateRequestItemCost(row) {
            const select = row.querySelector('.request-product-select');
            if (!select.value) return;
            const unitCost = parseFloat(select.options[select.selectedIndex].getAttribute('data-cost') || '0');
            row.querySelector('.request-item-cost').value = unitCost.toFixed(2);
        }
        function openReject(id) {
            document.getElementById('rejectRequestId').value = id;
            document.getElementById('rejectModal').classList.add('open');
        }
        function openValidate(id, name, qty, currentStock, unitCost, isNewItem, supplierId) {
            document.getElementById('validateRequestId').value = id;
            document.getElementById('validateItemName').textContent = name;
            document.getElementById('validateQuantity').value = qty + ' pcs';
            const stockGroup = document.getElementById('validateStockGroup');
            if (isNewItem) {
                stockGroup.style.display = 'none';
            } else {
                stockGroup.style.display = '';
                document.getElementById('validateStock').value = currentStock + ' pcs';
            }
            const estInput = document.getElementById('validateEstCost');
            estInput.value = isNewItem ? '' : (unitCost * qty).toFixed(2);
            // Preselect the request's existing supplier (e.g. if the Supervisor's
            // original submission — or a previous validation attempt — already
            // had one attached), so Inventory Staff isn't re-picking it blind.
            const supplierSelect = document.getElementById('validateSupplier');
            supplierSelect.value = (supplierId && supplierId > 0) ? String(supplierId) : '';
            document.getElementById('validateModal').classList.add('open');
        }
        function openFulfill(id, name, qty, unitCost) {
            document.getElementById('fulfillRequestId').value = id;
            document.getElementById('fulfillItemName').textContent = name;
            document.getElementById('fulfillQuantity').value = qty;
            document.getElementById('fulfillCostPrice').value = '₱ ' + Number(unitCost || 0).toFixed(2);
            document.getElementById('fulfillModal').classList.add('open');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
        }
        document.querySelectorAll('.modal-overlay').forEach(function (el) {
            el.addEventListener('click', function (e) {
                if (e.target === el) closeModal(el.id);
            });
        });
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