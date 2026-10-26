<?php
require_once 'includes/config.php';
requireInventoryStaff();

$upload_folder = 'uploads/products/';

if (!is_dir($upload_folder)) {
    mkdir($upload_folder, 0755, true);
}
$success_message = $_SESSION['flash_success'] ?? '';
$error_message = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$auto_ai_product_id = 0;

// =====================================================
// LIMITS
// =====================================================
define('MAX_PRICE', 99999.99);
define('MAX_STOCK', 99999);

// =====================================================
// HANDLE FORM SUBMISSIONS
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    requireCSRF();
    $action = $_POST['action'];


    // -------------------------------------------------
    // ADD PRODUCT
    // -------------------------------------------------
    if ($action == 'add') {

        $product_name = trim($_POST['product_name']);
        $barcode = trim($_POST['barcode'] ?? '');
        $category = trim($_POST['category']);
        $price = trim($_POST['price']);
        $stock = trim($_POST['stock']);
        $supplier_id = trim($_POST['supplier_id'] ?? '');
        $expiry_date = trim($_POST['expiry_date'] ?? '');
        $discount_type = trim($_POST['discount_type'] ?? 'none');
        $discount_value = trim($_POST['discount_value'] ?? '0');
        $discount_reason = trim($_POST['discount_reason'] ?? '');

        if (!in_array($discount_type, ['none', 'percent', 'fixed'], true)) {
            $discount_type = 'none';
        }
        if ($discount_type === 'none' || !is_numeric($discount_value) || (float) $discount_value <= 0) {
            $discount_type = 'none';
            $discount_value = 0;
            $discount_reason = '';
        } elseif ($discount_type === 'percent' && (float) $discount_value > 100) {
            $discount_value = 100;
        }

        $barcode_taken = false;
        if ($barcode !== '') {
            $stmt = $pdo->prepare("SELECT id FROM products WHERE barcode = ? LIMIT 1");
            $stmt->execute([$barcode]);
            $barcode_taken = (bool) $stmt->fetch();
        }

        if (empty($product_name)) {
            $error_message = "Product name is required.";
        } elseif ($barcode !== '' && !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $barcode)) {
            $error_message = "Barcode must be 3-64 characters using only letters, numbers, dots, underscores, or hyphens.";
        } elseif ($barcode_taken) {
            $error_message = "This barcode is already assigned to another product.";
        } elseif (empty($category)) {
            $error_message = "Please select a category.";
        } elseif (!is_numeric($price) || $price < 0) {
            $error_message = "Price must be a valid positive number.";
        } elseif ((float) $price > MAX_PRICE) {
            $error_message = "Price cannot exceed ₱" . number_format(MAX_PRICE, 2) . ".";
        } elseif ($discount_type === 'fixed' && (float) $discount_value >= (float) $price) {
            $error_message = "Fixed discount cannot be equal to or more than the price.";
        } elseif (!ctype_digit((string) $stock)) {
            $error_message = "Stock must be a whole number (no decimals or signs).";
        } elseif ((int) $stock < 0) {
            $error_message = "Stock cannot be negative.";
        } elseif ((int) $stock > MAX_STOCK) {
            $error_message = "Stock cannot exceed " . number_format(MAX_STOCK) . " pcs.";
        } elseif (!empty($expiry_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date)) {
            $error_message = "Invalid expiry date format.";
        } elseif (!empty($expiry_date) && strtotime($expiry_date) < strtotime('today')) {
            $error_message = "Expiry date cannot be in the past.";
        } elseif ($supplier_id !== '' && !getSupplierById($pdo, $supplier_id)) {
            $error_message = "Please pick a valid supplier from the list.";
        } else {

            $image_name = 'default.png';

            if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {

                if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    $error_message = "Image upload failed (error code: " . $_FILES['image']['error'] . "). Please try a smaller file.";
                } else {
                    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                    if (!in_array($ext, $allowed)) {
                        $error_message = "Image must be JPG, PNG, GIF, or WEBP.";
                    } elseif ($_FILES['image']['size'] > 2097152) {
                        $error_message = "Image is too large. 2MB maximum.";
                    } elseif (@getimagesize($_FILES['image']['tmp_name']) === false) {
                        // Item 5 (file upload hardening) — this only checked the
                        // extension before, so a renamed non-image file (or a
                        // corrupted one) would pass straight through. getimagesize()
                        // actually decodes the image header, same guard already
                        // used for avatar uploads.
                        $error_message = "That file doesn't look like a valid image.";
                    } else {
                        $candidate_name = 'product_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $destination = $upload_folder . $candidate_name;

                        if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                            $image_name = $candidate_name;
                        } else {
                            error_log("move_uploaded_file failed: tmp={$_FILES['image']['tmp_name']} dest=$destination");
                            $error_message = "Could not save the uploaded image. Please check folder permissions and try again.";
                        }
                    }
                }
            }

            if (empty($error_message)) {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("INSERT INTO products (product_name, barcode, category, price, stock, image, supplier_id, expiry_date, discount_type, discount_value, discount_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $product_name,
                        $barcode ?: null,
                        $category,
                        $price,
                        $stock,
                        $image_name,
                        $supplier_id !== '' ? (int) $supplier_id : null,
                        $expiry_date ?: null,
                        $discount_type,
                        $discount_value,
                        $discount_reason ?: null
                    ]);
                    $new_product_id = $pdo->lastInsertId();
                    if ($barcode === '') {
                        $pdo->prepare('UPDATE products SET barcode = ? WHERE id = ?')
                            ->execute([generateInternalProductBarcode((int) $new_product_id), $new_product_id]);
                    }

                    // Kung may starting stock, gawin itong unang batch —
                    // dito nakatali ang expiry date na ito, hindi sa buong
                    // product. Susunod na restock, bago ulit na batch.
                    if ((int) $stock > 0) {
                        addProductBatch($pdo, $new_product_id, (int) $stock, 0, $expiry_date ?: null, currentPosUserId(), 'Initial stock on product creation');
                    }

                    $pdo->commit();
                    checkLowStock($pdo);
                    $success_message = "Product added successfully.";
                    if ($image_name !== 'default.png' && in_array(strtolower(pathinfo($image_name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true)) {
                        $auto_ai_product_id = (int) $new_product_id;
                    }
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("Add product error: " . $e->getMessage());
                    $error_message = $e->getCode() === '23000'
                        ? "This barcode is already assigned to another product."
                        : "Could not save the product. Please try again.";
                }
            }
        }
    }


    // -------------------------------------------------
    // EDIT PRODUCT
    // -------------------------------------------------
    elseif ($action == 'edit') {

        $product_id = $_POST['product_id'];
        $product_name = trim($_POST['product_name']);
        $barcode = trim($_POST['barcode'] ?? '');
        if ($barcode === '' && ctype_digit((string) $product_id)) {
            $barcode = generateInternalProductBarcode((int) $product_id);
        }
        $category = trim($_POST['category']);
        $price = trim($_POST['price']);

        // Supplier field now posts one of:
        //   ''          -> explicitly cleared ("-- No supplier --")
        //   '__legacy__' -> leave the existing (unmatched free-text)
        //                    supplier alone, nothing changed
        //   <numeric id> -> link to that real supplier record
        $posted_supplier = trim($_POST['supplier_id'] ?? '');
        if ($posted_supplier === '__legacy__') {
            $supplier_mode = 'keep';
        } elseif ($posted_supplier === '') {
            $supplier_mode = 'clear';
        } elseif (ctype_digit($posted_supplier)) {
            $supplier_mode = 'set';
        } else {
            $supplier_mode = 'invalid';
        }
        // Stock at expiry ay hindi na dito ina-edit — pinamamahalaan na
        // sila ng batches (Restock/Adjust sa Inventory, at Manage Batches).
        // Sinasadya na hindi na sila kinukuha mula sa $_POST dito.
        $discount_type = trim($_POST['discount_type'] ?? 'none');
        $discount_value = trim($_POST['discount_value'] ?? '0');
        $discount_reason = trim($_POST['discount_reason'] ?? '');

        if (!in_array($discount_type, ['none', 'percent', 'fixed'], true)) {
            $discount_type = 'none';
        }
        if ($discount_type === 'none' || !is_numeric($discount_value) || (float) $discount_value <= 0) {
            $discount_type = 'none';
            $discount_value = 0;
            $discount_reason = '';
        } elseif ($discount_type === 'percent' && (float) $discount_value > 100) {
            $discount_value = 100;
        }

        $barcode_taken = false;
        if ($barcode !== '') {
            $stmt = $pdo->prepare("SELECT id FROM products WHERE barcode = ? AND id != ? LIMIT 1");
            $stmt->execute([$barcode, $product_id]);
            $barcode_taken = (bool) $stmt->fetch();
        }

        if (empty($product_name)) {
            $error_message = "Product name is required.";
        } elseif ($barcode !== '' && !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $barcode)) {
            $error_message = "Barcode must be 3-64 characters using only letters, numbers, dots, underscores, or hyphens.";
        } elseif ($barcode_taken) {
            $error_message = "This barcode is already assigned to another product.";
        } elseif (empty($category)) {
            $error_message = "Please select a category.";
        } elseif (!is_numeric($price) || $price < 0) {
            $error_message = "Price must be a valid positive number.";
        } elseif ((float) $price > MAX_PRICE) {
            $error_message = "Price cannot exceed ₱" . number_format(MAX_PRICE, 2) . ".";
        } elseif ($discount_type === 'fixed' && (float) $discount_value >= (float) $price) {
            $error_message = "Fixed discount cannot be equal to or more than the price.";
        } elseif ($discount_type !== 'none' && isProductExpired($pdo, $product_id)) {
            $error_message = "This product has already expired and is no longer for sale, so it can't be given a discount.";
        } elseif ($supplier_mode === 'invalid' || ($supplier_mode === 'set' && !getSupplierById($pdo, $posted_supplier))) {
            $error_message = "Please pick a valid supplier from the list.";
        } else {

            $stmt = $pdo->prepare("SELECT image, discount_type, discount_value, discount_reason FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            $current = $stmt->fetch();

            if (!$current) {
                $error_message = "Product not found.";
            } else {

                // A change to the discount needs Finance approval before it
                // takes effect — see product_discount_requests. Compare the
                // submitted discount against what's on record so a save
                // that leaves the discount untouched doesn't create a
                // pointless approval request.
                $discount_changed = (
                    $discount_type !== ($current['discount_type'] ?? 'none') ||
                    round((float) $discount_value, 2) !== round((float) ($current['discount_value'] ?? 0), 2) ||
                    trim((string) $discount_reason) !== trim((string) ($current['discount_reason'] ?? ''))
                );

                if ($discount_changed) {
                    $stmt = $pdo->prepare("SELECT request_id FROM product_discount_requests WHERE product_id = ? AND status = 'pending'");
                    $stmt->execute([$product_id]);
                    if ($stmt->fetch()) {
                        $error_message = "This product already has a discount change awaiting Finance approval. Please wait for a decision, or cancel it first.";
                    }
                }

                $image_name = $current['image'];
                $image_replaced = false;

                if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {

                    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                        $error_message = "Image upload failed (error code: " . $_FILES['image']['error'] . "). Please try a smaller file.";
                    } else {
                        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                        if (!in_array($ext, $allowed)) {
                            $error_message = "Image must be JPG, PNG, GIF, or WEBP.";
                        } elseif ($_FILES['image']['size'] > 2097152) {
                            $error_message = "Image is too large. 2MB maximum.";
                        } elseif (@getimagesize($_FILES['image']['tmp_name']) === false) {
                            // Item 5 (file upload hardening) — same content
                            // check as the create-product flow above; an
                            // extension alone can't be trusted.
                            $error_message = "That file doesn't look like a valid image.";
                        } else {
                            $candidate_name = 'product_' . bin2hex(random_bytes(8)) . '.' . $ext;
                            $destination = $upload_folder . $candidate_name;

                            if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                                if ($image_name != 'default.png' && file_exists($upload_folder . $image_name)) {
                                    unlink($upload_folder . $image_name);
                                }
                                $image_name = $candidate_name;
                                $image_replaced = true;
                            } else {
                                error_log("move_uploaded_file failed: tmp={$_FILES['image']['tmp_name']} dest=$destination");
                                $error_message = "Could not save the uploaded image. Please check folder permissions and try again.";
                            }
                        }
                    }
                }

                if (empty($error_message)) {
                    try {
                        // Discount columns are intentionally left out here —
                        // they only change once Finance approves the request
                        // inserted below. Everything else saves right away.
                        //
                        // Supplier: 'keep' mode leaves supplier_id/supplier
                        // untouched (the legacy free-text stays as-is until
                        // someone picks a real supplier for it). 'clear' and
                        // 'set' both write supplier_id, and blank out the old
                        // free-text column since it's now either empty or
                        // redundant with the real link.
                        $supplier_sql = '';
                        $supplier_params = [];
                        if ($supplier_mode !== 'keep') {
                            $supplier_sql = ', supplier_id=?, supplier=?';
                            $supplier_params[] = $supplier_mode === 'set' ? (int) $posted_supplier : null;
                            $supplier_params[] = null;
                        }

                        $stmt = $pdo->prepare("UPDATE products SET product_name=?, barcode=?, category=?, price=?, image=?{$supplier_sql} WHERE id=?");
                        $stmt->execute(array_merge(
                            [
                                $product_name,
                                $barcode ?: null,
                                $category,
                                $price,
                                $image_name,
                            ],
                            $supplier_params,
                            [$product_id]
                        ));

                        if ($discount_changed) {
                            $stmt = $pdo->prepare("
                                INSERT INTO product_discount_requests
                                    (product_id, requested_by, discount_type, discount_value, discount_reason, price_at_request, old_discount_type, old_discount_value, old_discount_reason)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $product_id,
                                currentPosUserId(),
                                $discount_type,
                                $discount_value,
                                $discount_reason ?: null,
                                $price,
                                $current['discount_type'] ?? 'none',
                                $current['discount_value'] ?? 0,
                                $current['discount_reason'] ?? null,
                            ]);
                            $success_message = "Product updated. The discount change was sent to Finance for approval and won't show on the register until it's approved.";
                        } else {
                            $success_message = "Product updated successfully.";
                        }

                        checkLowStock($pdo);
                        if ($image_replaced && in_array(strtolower(pathinfo($image_name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true)) {
                            $auto_ai_product_id = (int) $product_id;
                        }
                    } catch (PDOException $e) {
                        error_log("Edit product error: " . $e->getMessage());
                        $error_message = $e->getCode() === '23000'
                            ? "This barcode is already assigned to another product."
                            : "Could not update the product. Please try again.";
                    }
                }
            }
        }
    }


    // -------------------------------------------------
    // DELETE PRODUCT
    // -------------------------------------------------
    elseif ($action == 'delete') {

        $product_id = $_POST['product_id'];

        try {
            $stmt = $pdo->prepare("SELECT image FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();

            if (!$product) {
                $error_message = "Product not found.";
            } else {

                // Preserve products referenced by sales or stock history;
                // both tables restrict hard deletion through foreign keys.
                $check = $pdo->prepare("
                    SELECT
                        EXISTS(SELECT 1 FROM sales_details WHERE product_id = ?) OR
                        EXISTS(SELECT 1 FROM stock_logs WHERE product_id = ?)
                ");
                $check->execute([$product_id, $product_id]);
                $hasHistory = (bool) $check->fetchColumn();

                if ($hasHistory) {
                    // Keep the product row and image so historical records remain readable.
                    $pdo->prepare("UPDATE products SET is_active = 0 WHERE id = ?")->execute([$product_id]);
                    $success_message = "Product archived because it has sales or stock history. It is now hidden from active products.";
                } else {
                    // HARD DELETE — walang history, ligtas burahin nang buo
                    $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$product_id]);

                    if ($product['image'] != 'default.png' && file_exists($upload_folder . $product['image'])) {
                        unlink($upload_folder . $product['image']);
                    }
                    $success_message = "Product deleted.";
                }
            }

        } catch (PDOException $e) {
            error_log("Delete product error: " . $e->getMessage());
            $error_message = "Could not delete this product. Please try again.";
        }
    }


    // -------------------------------------------------
    // CANCEL PRODUCT DISCOUNT REQUEST
    // -------------------------------------------------
    elseif ($action == 'cancel_product_discount_request') {

        $request_id = (int) ($_POST['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM product_discount_requests WHERE request_id = ?");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();

        if (!$req) {
            $error_message = "Discount request not found.";
        } elseif ($req['status'] !== 'pending') {
            $error_message = "Only requests still awaiting Finance approval can be cancelled.";
        } else {
            $pdo->prepare("UPDATE product_discount_requests SET status = 'cancelled' WHERE request_id = ?")
                ->execute([$request_id]);
            $success_message = "Discount request cancelled.";
        }
    }


    // -------------------------------------------------
    // RESTORE PRODUCT (un-archive)
    // -------------------------------------------------
    elseif ($action == 'restore') {

        $product_id = $_POST['product_id'];

        try {
            $pdo->prepare("UPDATE products SET is_active = 1 WHERE id = ?")->execute([$product_id]);
            $success_message = "Product restored.";
        } catch (PDOException $e) {
            error_log("Restore product error: " . $e->getMessage());
            $error_message = "Could not restore this product. Please try again.";
        }
    }

    // Post/Redirect/Get — without this, hitting reload/F5 after adding,
    // editing, deleting, or restoring a product re-sends the same POST
    // and repeats it (e.g. duplicating a newly-added product).
    //
    // For a successful edit specifically, drop the ?edit= param from the
    // redirect target so the Edit Product form auto-closes once saved —
    // otherwise it kept reopening with the same product on every save
    // (redirecting back to the same ?edit=<id> URL it was submitted to).
    // On a validation error, keep the query string as-is so the form
    // stays open with the entered values for the person to fix.
    if (in_array($action, ['add', 'edit'], true) && empty($error_message) && $auto_ai_product_id > 0) {
        header('Location: product_details_edit.php?product_id=' . enc_id($auto_ai_product_id) . '&ai=1');
        exit;
    }
    $_SESSION['flash_success'] = $success_message;
    $_SESSION['flash_error'] = $error_message;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    if ($action === 'edit' && empty($error_message)) {
        $qs = '';
    }
    header('Location: products.php' . ($qs !== '' ? ('?' . $qs) : ''));
    exit;
}


// =====================================================
// GET PRODUCTS LIST
// =====================================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Show archived products kapag explicit na hiniling
$show_archived = isset($_GET['view']) && $_GET['view'] == 'archived';

// Expiry filter (30 days ahead)
$today = date('Y-m-d');
$expiry_soon = date('Y-m-d', strtotime('+30 days'));

// ---------------------------------------------------
// FILTER + SORT INPUTS
// ---------------------------------------------------
$filter_category = isset($_GET['category']) ? trim($_GET['category']) : '';
$filter_stock = isset($_GET['stock']) ? trim($_GET['stock']) : '';   // in | low | out
$filter_expiry = isset($_GET['expiry']) ? trim($_GET['expiry']) : ''; // expired | soon
$filter_discount = isset($_GET['discount']) ? trim($_GET['discount']) : ''; // active
$supplierFilterToken = trim((string) ($_GET['supplier_id'] ?? ''));
if (in_array($supplierFilterToken, ['none', 'unlinked'], true)) {
    $filter_supplier = $supplierFilterToken;
} else {
    $decodedSupplierId = dec_id($supplierFilterToken);
    $filter_supplier = $decodedSupplierId > 0
        ? (string) $decodedSupplierId
        : (ctype_digit($supplierFilterToken) ? $supplierFilterToken : '');
}

$sort_options = [
    'newest' => 'date_added DESC',
    'name_asc' => 'product_name ASC',
    'name_desc' => 'product_name DESC',
    'price_asc' => 'price ASC',
    'price_desc' => 'price DESC',
    'stock_asc' => 'stock ASC',
    'stock_desc' => 'stock DESC',
];
$sort = isset($_GET['sort']) && array_key_exists($_GET['sort'], $sort_options) ? $_GET['sort'] : 'newest';

// ---------------------------------------------------
// BUILD QUERY DYNAMICALLY
// ---------------------------------------------------
$where = ['is_active = ?'];
$params = [$show_archived ? 0 : 1];

if (!empty($search)) {
    $where[] = '(product_name LIKE ? OR category LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if (!empty($filter_category)) {
    $where[] = 'category = ?';
    $params[] = $filter_category;
}
if ($filter_stock === 'out') {
    $where[] = 'stock = 0';
} elseif ($filter_stock === 'low') {
    $where[] = 'stock > 0 AND stock <= ' . (int) LOW_STOCK;
} elseif ($filter_stock === 'in') {
    $where[] = 'stock > ' . (int) LOW_STOCK;
}
if ($filter_discount === 'active') {
    $where[] = "discount_type != 'none' AND discount_value > 0";
}
if (ctype_digit($filter_supplier)) {
    $where[] = 'p.supplier_id = ?';
    $params[] = (int) $filter_supplier;
} elseif ($filter_supplier === 'none') {
    $where[] = "(p.supplier_id IS NULL AND (p.supplier IS NULL OR p.supplier = ''))";
} elseif ($filter_supplier === 'unlinked') {
    $where[] = "(p.supplier_id IS NULL AND p.supplier IS NOT NULL AND p.supplier <> '')";
}

// LEFT JOIN so the current supplier name always comes straight from the
// Suppliers directory (via supplier_id) — rename a supplier there and
// every product linked to it shows the new name automatically.
$sql = 'SELECT p.*, sup.company_name AS supplier_company_name, sup.status AS supplier_company_status,
                COALESCE(pd.review_status, \'draft\') AS product_details_review_status
         FROM products p
         LEFT JOIN suppliers sup ON sup.id = p.supplier_id
         LEFT JOIN product_details pd ON pd.product_id = p.id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $sort_options[$sort];
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
$verification_counts = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM products WHERE is_active = 1) AS total,
        (SELECT COUNT(*) FROM product_details WHERE review_status = 'verified') AS verified
")->fetch();

$stmt = $pdo->query("SELECT * FROM categories ORDER BY category_name ASC");
$categories = $stmt->fetchAll();

// Same active-supplier list the Purchase Order module uses — keeps
// Products' "Supplier" field tied to the actual Supplier directory
// instead of free text.
$active_suppliers = getActiveSuppliers($pdo);

// Full supplier list (incl. inactive) for the filter dropdown, each with
// how many active products currently point to it — this is the "where do
// I find this supplier's products" view.
$supplier_product_counts = $pdo->query("
    SELECT s.id, s.company_name, s.status, COUNT(p2.id) AS product_count
    FROM suppliers s
    LEFT JOIN products p2 ON p2.supplier_id = s.id AND p2.is_active = 1
    GROUP BY s.id, s.company_name, s.status
    ORDER BY s.company_name ASC
")->fetchAll();

// Products whose typed-in supplier name never matched anything in the
// directory (or the directory match hasn't been run yet) — surfaced so
// they can be linked instead of staying invisible free text.
$unlinked_supplier_count = (int) $pdo->query("
    SELECT COUNT(*) FROM products
    WHERE is_active = 1 AND supplier_id IS NULL AND supplier IS NOT NULL AND supplier <> ''
")->fetchColumn();

// Count expiring soon for banner (active products lang)
$stmt_expiring = $pdo->prepare("SELECT COUNT(*) FROM products WHERE is_active = 1 AND expiry_date IS NOT NULL AND expiry_date BETWEEN ? AND ?");
$stmt_expiring->execute([$today, $expiry_soon]);
$expiring_count = $stmt_expiring->fetchColumn();

// Count archived products (para sa toolbar link/badge)
$archived_count = $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 0")->fetchColumn();

$show_add_form = isset($_GET['action']) && $_GET['action'] == 'add';

$edit_product = null;
$edit_product_pending_discount = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([dec_id($_GET['edit'])]);
    $edit_product = $stmt->fetch();

    if ($edit_product) {
        $stmt = $pdo->prepare("SELECT * FROM product_discount_requests WHERE product_id = ? AND status = 'pending' ORDER BY requested_at DESC LIMIT 1");
        $stmt->execute([$edit_product['id']]);
        $edit_product_pending_discount = $stmt->fetch() ?: null;
    }
}

// Kung expired na ang product na ito, i-lock ang Discount fields sa form
// (see isProductExpired() sa includes/config.php) — walang saysay
// mag-discount ng item na hindi naman pwedeng ibenta.
$edit_product_is_expired = $edit_product ? isProductExpired($pdo, $edit_product['id']) : false;

// getExpiryStatus() now lives in includes/config.php (shared with
// inventory.php and product_batches.php).

// Products with a discount change currently awaiting Finance —
// keyed by product_id so the listing table can flag them.
$pending_discount_by_product = [];
try {
    $rows = $pdo->query("SELECT product_id FROM product_discount_requests WHERE status = 'pending'")->fetchAll();
    foreach ($rows as $r) {
        $pending_discount_by_product[$r['product_id']] = true;
    }
} catch (Exception $e) {
}

// Nearest-expiring batch per product (expiry ngayon ay per-batch na,
// hindi na iisang column lang sa products) + bilang ng active batches.
$nearest_expiry_map = [];
$batch_count_map = [];
$batch_rows = $pdo->query("
    SELECT product_id, MIN(expiry_date) AS nearest_expiry, COUNT(*) AS batch_count
    FROM product_batches
    WHERE quantity_remaining > 0
    GROUP BY product_id
")->fetchAll();
foreach ($batch_rows as $row) {
    $nearest_expiry_map[$row['product_id']] = $row['nearest_expiry'];
    $batch_count_map[$row['product_id']] = (int) $row['batch_count'];
}

// Expiry filter is applied here (not in SQL) since expiry now lives
// per-batch rather than as a single products column.
if ($filter_expiry === 'expired' || $filter_expiry === 'soon') {
    $products = array_values(array_filter($products, function ($p) use ($nearest_expiry_map, $filter_expiry) {
        $expiry = $nearest_expiry_map[$p['id']] ?? null;
        if (empty($expiry))
            return false;
        $diff_days = (strtotime($expiry) - strtotime('today')) / 86400;
        if ($filter_expiry === 'expired')
            return $diff_days < 0;
        return $diff_days >= 0 && $diff_days <= 30; // "soon" = expiring within the next 30 days
    }));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products — HCI GroceryOne</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Pinapantay ang height ng bawat row sa Products table anuman ang laki
   ng laman nito (hal. 1-line lang na "—" sa Expiry vs 2-line na
   "Expired" + "1 batch"). Sa mismong <td> ilalagay ang fixed height
   (hindi sa <tr>) dahil hindi laging sinusunod ng browser ang height
   ng <tr> bilang minimum sa mga row na mas maikli ang laman — pero
   pag nasa <td> mismo ito, sapilitang sumusunod dito ang buong row. */
        .data-table td {
            height: 56px;
            box-sizing: content-box;
        }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .search-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
        }

        .alert--success {
            background: #e6f4ea;
            color: #1a7a3b;
        }

        .alert--error {
            background: #fbe7e2;
            color: #c8472d;
        }

        .alert--warning {
            background: #fff8e1;
            color: #b45309;
        }

        html[data-theme="dark"] .alert--success {
            background: var(--green-pale);
            color: var(--green-leaf);
        }

        html[data-theme="dark"] .alert--error {
            background: var(--red-pale);
            color: var(--red-alert);
        }

        html[data-theme="dark"] .alert--warning {
            background: var(--mustard-pale);
            color: var(--mustard);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .field-group--full {
            grid-column: 1 / -1;
        }

        .field-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
        }

        .field-hint {
            font-size: 11px;
            color: var(--ink-soft);
            margin-top: 2px;
        }

        .field-input {
            padding: 10px 14px;
            border: 1.5px solid var(--line);
            border-radius: 10px;
            font-family: var(--font-body);
            font-size: 14px;
            color: var(--ink);
            background: var(--cream);
            width: 100%;
            transition: border-color .15s;
        }

        .field-input:focus {
            outline: none;
            border-color: var(--green-mid);
            background: var(--white);
        }

        /* File uploads need their own treatment because the browser's native
   picker button does not inherit the visual language of text inputs. */
        .field-input[type="file"] {
            display: block;
            height: 38px;
            min-height: 38px;
            padding: 4px 8px;
            cursor: pointer;
            color: var(--ink-soft);
            background: var(--white);
            border: 1.5px solid var(--green-mid);
        }

        .field-input[type="file"]::file-selector-button {
            margin-right: 10px;
            min-height: 28px;
            padding: 5px 12px;
            border: 1px solid var(--green-mid);
            border-radius: 7px;
            background: var(--white);
            color: var(--green-deep);
            font: 600 13px/1.2 var(--font-body);
            cursor: pointer;
            transition: background-color .15s ease, transform .15s ease;
        }

        .field-input[type="file"]::file-selector-button:hover {
            background: var(--green-mid);
            color: #fff;
        }

        .field-input[type="file"]::file-selector-button:active {
            transform: translateY(1px);
        }

        .field-input[type="file"]:focus-visible {
            outline: 2px solid var(--green-mid);
            outline-offset: 2px;
        }

        html[data-theme="dark"] .field-input[type="file"] {
            color: var(--ink-soft);
        }

        .image-upload-tools {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 8px;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--cream);
        }

        .image-upload-tools .field-hint {
            margin-top: 0;
        }

        .image-upload-tools .action-btn {
            min-height: 38px;
            padding: 9px 16px;
            font-size: 13px;
            flex-shrink: 0;
        }

        .product-image-focus {
            padding: 14px 16px 12px;
            border: 1.5px solid var(--line);
            border-radius: 14px;
            background: linear-gradient(180deg, rgba(255, 255, 255, .9), rgba(239, 246, 255, .7));
        }

        .product-image-focus .field-label {
            font-size: 15px;
            margin-bottom: 4px;
        }

        .product-image-focus .field-input[type="file"] {
            min-height: 58px;
            padding: 12px 14px;
            font-size: 15px;
            border: 2px dashed var(--green-mid);
            background: rgba(255, 255, 255, .9);
        }

        .product-image-focus .image-upload-tools {
            margin-top: 10px;
        }

        .product-edit-form .product-image-focus .current-image-wrap {
            min-height: 184px;
            margin: 10px 0 0;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--white);
        }

        .product-edit-form .product-image-focus .current-image-wrap img {
            width: min(240px, 100%);
            height: 160px;
            object-fit: contain;
            background: var(--cream);
        }

        html[data-theme="dark"] .product-image-focus {
            background: linear-gradient(180deg, #1C1F24, #16191E);
            border-color: #2A2E35;
        }

        html[data-theme="dark"] .product-image-focus .field-label {
            color: #ECEDEE;
        }

        html[data-theme="dark"] .product-image-focus .field-input[type="file"] {
            color: #A6ACB5;
            background: #121417;
            border-color: #4C8DFF;
        }

        html[data-theme="dark"] .product-image-focus .field-input[type="file"]::file-selector-button {
            color: #ECEDEE;
            background: #1C1F24;
            border-color: #4C8DFF;
        }

        html[data-theme="dark"] .product-image-focus .field-input[type="file"]::file-selector-button:hover {
            color: #fff;
            background: #2F80ED;
        }

        html[data-theme="dark"] .product-image-focus .image-upload-tools {
            background: #121417;
            border-color: #2A2E35;
        }

        html[data-theme="dark"] .product-edit-form .product-image-focus .current-image-wrap {
            background: #121417;
            border-color: #2A2E35;
        }

        @media (max-width: 620px) {
            .product-edit-form .product-image-focus .current-image-wrap {
                align-items: flex-start;
                flex-direction: column;
            }

            .product-edit-form .product-image-focus .current-image-wrap img {
                width: min(100%, 320px);
                height: 190px;
            }
        }

        .image-upload-tools #openProductCamera {
            background: var(--green-mid);
            color: #fff;
            border-color: var(--green-mid);
            box-shadow: 0 2px 8px rgba(37, 99, 235, .2);
        }

        .image-upload-tools #openProductCamera:hover {
            background: var(--green-deep);
            border-color: var(--green-deep);
            color: #fff;
        }

        .barcode-entry {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 8px;
        }

        .barcode-entry .action-btn {
            min-height: 42px;
        }

        @media (max-width: 620px) {
            .barcode-entry {
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            }

            .barcode-entry input[type="text"] {
                grid-column: 1 / -1;
            }
        }

        .barcode-scan-modal .camera-modal__card {
            width: min(560px, 100%);
        }

        .barcode-reader {
            position: relative;
            min-height: 260px;
            overflow: hidden;
            border-radius: 12px;
            background: #0f172a;
        }

        .barcode-reader::before {
            position: absolute;
            z-index: 3;
            inset: 0;
            background: rgba(34, 197, 94, .3);
            content: "";
            opacity: 0;
            pointer-events: none;
        }

        .barcode-reader video {
            width: 100%;
            max-height: 420px;
            object-fit: contain;
        }

        .barcode-reader::after {
            position: absolute;
            z-index: 4;
            inset: 50% auto auto 50%;
            width: 90%;
            height: 75%;
            border: 2px solid #22c55e;
            border-radius: 14px;
            box-shadow: 0 0 0 999px rgba(2, 8, 23, .18), 0 0 16px rgba(34, 197, 94, .55);
            content: "";
            pointer-events: none;
            transform: translate(-50%, -50%);
        }

        .barcode-reader.is-detected::before {
            animation: barcode-scan-flash .3s ease-out;
        }

        .barcode-reader.is-detected::after {
            border-color: #86efac;
        }

        @keyframes barcode-scan-flash {
            0% { opacity: .8; }
            100% { opacity: 0; }
        }

        .barcode-scan-controls {
            align-items: center;
        }

        .barcode-scan-controls input[type="range"] {
            width: min(180px, 44vw);
            accent-color: var(--green-mid);
        }

        .barcode-scan-controls [hidden] {
            display: none !important;
        }

        .camera-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 300;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(15, 23, 42, .58);
        }

        .camera-modal.open {
            display: flex;
        }

        .camera-modal__card {
            width: min(520px, 100%);
            padding: 20px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: var(--white);
            box-shadow: 0 24px 70px rgba(15, 23, 42, .28);
        }

        .camera-modal__title {
            margin: 0 0 12px;
            color: var(--text-accent);
            font-family: var(--font-display);
            font-size: 19px;
        }

        .camera-modal video,
        .camera-modal canvas {
            display: block;
            width: 100%;
            max-height: 360px;
            border-radius: 12px;
            background: #0f172a;
            object-fit: contain;
        }

        .camera-modal canvas {
            display: none;
        }

        .camera-modal__note {
            margin: 10px 0 0;
            color: var(--ink-soft);
            font-size: 12px;
        }

        .camera-modal__actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        /* Hide number input arrows/spinners */
        .field-input[type=number]::-webkit-inner-spin-button,
        .field-input[type=number]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .field-input[type=number] {
            -moz-appearance: textfield;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .panel-body>form>.form-actions {
            align-items: stretch;
        }

        .panel-body>form>.form-actions>.action-btn {
            min-width: 132px;
            min-height: 44px;
        }

        .panel-body>form>.form-actions>.action-btn:first-child {
            min-width: 170px;
        }

        .panel-body>form>.form-actions.form-actions--edit>.action-btn {
            width: 100%;
            background: var(--green-mid);
            color: #fff;
            border: 1px solid var(--green-mid);
            box-shadow: 0 3px 10px rgba(37, 99, 235, .22);
        }

        .panel-body>form>.form-actions.form-actions--edit {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: stretch;
        }

        .panel-body>form>.form-actions.form-actions--edit>.action-btn:hover {
            background: var(--green-deep);
            border-color: var(--green-deep);
            color: #fff;
        }

        .product-edit-form .form-grid .field-group>.form-actions .action-btn,
        .product-edit-form .image-upload-tools .action-btn {
            background: var(--green-mid);
            color: #fff;
            border-color: var(--green-mid);
        }

        .product-edit-form .form-grid .field-group>.form-actions .action-btn:hover,
        .product-edit-form .image-upload-tools .action-btn:hover {
            background: var(--green-deep);
            border-color: var(--green-deep);
            color: #fff;
        }

        .product-add-form>.form-actions>.action-btn--secondary {
            background: var(--green-mid);
            color: #fff;
            border-color: var(--green-mid);
            box-shadow: 0 2px 8px rgba(37, 99, 235, .2);
        }

        .product-add-form>.form-actions>.action-btn--secondary:hover {
            background: var(--green-deep);
            border-color: var(--green-deep);
            color: #fff;
        }

        .product-add-form>.form-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .product-add-form>.form-actions>.action-btn {
            width: 100%;
            min-width: 0;
        }

        .product-edit-form .field-input[type="file"]::file-selector-button {
            background: var(--green-mid);
            color: #fff;
            border-color: var(--green-mid);
        }

        .product-edit-form .field-input[type="file"]::file-selector-button:hover {
            background: var(--green-deep);
            color: #fff;
        }

        .product-edit-form .field-input[type="file"] {
            height: 56px;
            min-height: 56px;
            padding: 9px 10px;
            background: #eff6ff;
            color: var(--ink-soft);
            border: 1px dashed #93c5fd;
            border-radius: 8px;
        }

        .panel-body {
            padding: 20px 22px 24px;
        }

        .action-btn--small {
            padding: 9px 16px;
            font-size: 13px;
        }

        .toolbar>.action-btn--qr-labels {
            background: var(--green-mid);
            color: #fff;
            border: 1px solid var(--green-mid);
            box-shadow: 0 3px 10px rgba(37, 99, 235, .22);
        }

        .toolbar>.action-btn--qr-labels:hover {
            background: var(--green-deep);
            border-color: var(--green-deep);
            color: #fff;
        }

        .action-btn--secondary {
            background: var(--white);
            color: var(--green-deep);
            border: 1.5px solid var(--green-mid);
            box-shadow: 0 1px 3px rgba(37, 99, 235, .12);
        }

        .action-btn--secondary:hover {
            background: var(--green-mid);
            border-color: var(--green-mid);
            color: #fff;
        }

        html[data-theme="dark"] .action-btn--secondary {
            background: var(--white);
            color: var(--green-leaf);
            border-color: var(--green-mid);
        }

        .form-grid .field-group>.form-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .form-grid .field-group>.form-actions .action-btn {
            width: 100%;
            min-height: 42px;
            padding: 10px 12px;
            font-size: 12px;
            line-height: 1.25;
            text-align: center;
            white-space: nowrap;
        }

        @media (max-width: 620px) {
            .form-grid .field-group>.form-actions {
                grid-template-columns: 1fr;
            }

            .panel-body>form>.form-actions {
                flex-direction: column;
            }

            .panel-body>form>.form-actions>.action-btn,
            .panel-body>form>.form-actions>.action-btn:first-child {
                width: 100%;
            }

            .panel-body>form>.form-actions.form-actions--edit>.action-btn {
                width: 100%;
            }
        }

        .product-thumb {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--cream);
        }

        .product-thumb-placeholder {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--green-pale);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--green-leaf);
            font-size: 18px;
        }

        .current-image-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }

        .current-image-wrap img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid var(--line);
        }

        .current-image-wrap span {
            font-size: 13px;
            color: var(--ink-soft);
        }

        .actions-cell {
            display: table-cell;
            vertical-align: middle;
        }

        .actions-wrap {
            display: flex;
            gap: 18px;
            align-items: center;
        }

        .link-btn {
            font-size: 19px;
            font-weight: 600;
            color: var(--green-mid);
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
        }

        .link-btn:hover {
            color: var(--text-accent);
        }

        .link-btn--danger {
            color: var(--red-alert);
        }

        .link-btn--danger:hover {
            color: #a8341f;
        }

        .inline-form {
            display: inline;
        }

        .empty-row {
            text-align: center;
            color: var(--ink-soft);
            padding: 24px 0 !important;
            font-size: 14px;
        }

        .expiry-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .view-tabs {
            display: flex;
            gap: 4px;
            padding: 4px;
            width: fit-content;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--white);
        }

        .view-tab {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink-soft);
            text-decoration: none;
            border: 1px solid transparent;
            transition: background-color .15s ease, color .15s ease, border-color .15s ease;
        }

        .view-tab:hover {
            background: var(--green-pale);
            color: var(--green-deep);
        }

        .view-tab.is-active {
            background: var(--green-mid);
            color: #fff;
            border-color: var(--green-mid);
            box-shadow: 0 3px 8px rgba(37, 99, 235, .22);
        }

        .archived-badge {
            display: inline-flex;
            align-items: center;
            font-size: 11px;
            font-weight: 700;
            background: #dbeafe;
            color: var(--green-deep);
            border-radius: 999px;
            padding: 2px 8px;
            margin-left: 6px;
        }

        .view-tab.is-active .archived-badge {
            background: rgba(255, 255, 255, .2);
            color: #fff;
        }

        .price-original {
            text-decoration: line-through;
            color: var(--ink-soft);
            font-size: 12px;
            display: block;
        }

        .price-discounted {
            color: var(--red-alert);
            font-weight: 700;
        }

        .discount-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 10px;
            font-weight: 700;
            background: #fbe7e2;
            color: #c8472d;
            border-radius: 999px;
            padding: 1px 7px;
            margin-top: 2px;
        }

        html[data-theme="dark"] .discount-badge {
            background: var(--red-pale);
            color: var(--red-alert);
        }

        .discount-fields {
            display: flex;
            flex-direction: row;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .discount-fields .field-group {
            flex: 1;
            min-width: 130px;
        }

        .discount-pending-notice {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            background: #FBEFD8;
            color: #8a5c10;
            border: 1px solid #f0e0b8;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 4px;
        }

        html[data-theme="dark"] .discount-pending-notice {
            background: var(--mustard-pale);
            color: var(--mustard);
            border-color: var(--line);
        }

        .discount-locked {
            opacity: .55;
            pointer-events: none;
        }

        .discount-locked .field-input {
            background: var(--cream);
        }

        .discount-awaiting-badge {
            font-size: 10.5px;
            font-weight: 700;
            background: #FBEFD8;
            color: #8a5c10;
            padding: 2px 8px;
            border-radius: 20px;
            display: inline-block;
            white-space: nowrap;
        }

        html[data-theme="dark"] .discount-awaiting-badge {
            background: var(--mustard-pale);
            color: var(--mustard);
        }
    </style>
</head>

<body>

    <div class="app-shell">

        <!-- SIDEBAR -->
        <?php $active = 'products';
        include 'includes/sidebar.php'; ?>

        <!-- MAIN -->
        <div class="main-area">

            <header class="topbar">
                <div class="topbar-icon"><?= $__icon_products ?></div>
                <h1>Products</h1>
                <div class="topbar-user">
                    <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img
                                src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>"
                                alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'A') ?><?php endif; ?></span>
                    <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Administrator') ?></span>
                </div>
            </header>

            <main class="content">

                <?php if (!empty($success_message)): ?>
                    <script>
                        Swal.fire({ icon: 'success', title: <?= json_encode(strip_tags($success_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 2200, timerProgressBar: true });
                    </script>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <script>
                        Swal.fire({ icon: 'error', title: <?= json_encode(clean($error_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
                    </script>
                <?php endif; ?>

                <!-- EXPIRY WARNING BANNER -->
                <?php if (!$show_archived && $expiring_count > 0): ?>
                    <div class="alert alert--warning">
                        ⚠️ <strong><?= $expiring_count ?> product<?= $expiring_count > 1 ? 's' : '' ?></strong> will expire
                        within 30 days. Check the Expiry Date column below.
                    </div>
                <?php endif; ?>
                <?php if (!$show_archived): ?>
                    <div class="alert alert--success">
                        <strong><?= (int) ($verification_counts['verified'] ?? 0) ?> /
                            <?= (int) ($verification_counts['total'] ?? 0) ?> products verified</strong>
                        <span> — review the remaining product details before publishing customer-facing nutrition
                            information.</span>
                    </div>
                <?php endif; ?>

                <!-- VIEW TABS (Active / Archived) -->
                <div class="view-tabs">
                    <a href="products.php" class="view-tab <?= !$show_archived ? 'is-active' : '' ?>">Active
                        Products</a>
                    <a href="products.php?view=archived" class="view-tab <?= $show_archived ? 'is-active' : '' ?>">
                        Archived
                        <?php if ($archived_count > 0): ?>
                            <span class="archived-badge"><?= $archived_count ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <!-- SEARCH + FILTERS + ADD BUTTON -->
                <div class="toolbar">
                    <form method="GET" action="products.php" class="search-form filter-form" id="productFilterForm">
                        <?php if ($show_archived): ?>
                            <input type="hidden" name="view" value="archived">
                        <?php endif; ?>
                        <input type="text" name="search" id="productSearchInput" placeholder="Search products..."
                            value="<?= clean($search) ?>" class="field-input" style="width:200px;" autocomplete="off">
                        <button type="submit" class="action-btn action-btn--small">Search</button>

                        <select name="category" class="field-input filter-select"
                            onchange="document.getElementById('productFilterForm').submit()">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= clean($cat['category_name']) ?>"
                                    <?= $filter_category === $cat['category_name'] ? 'selected' : '' ?>>
                                    <?= clean($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="stock" class="field-input filter-select"
                            onchange="document.getElementById('productFilterForm').submit()">
                            <option value="">All Stock</option>
                            <option value="in" <?= $filter_stock === 'in' ? 'selected' : '' ?>>In Stock</option>
                            <option value="low" <?= $filter_stock === 'low' ? 'selected' : '' ?>>Low Stock
                                (≤<?= LOW_STOCK ?>)</option>
                            <option value="out" <?= $filter_stock === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                        </select>

                        <select name="expiry" class="field-input filter-select"
                            onchange="document.getElementById('productFilterForm').submit()">
                            <option value="">All Expiry</option>
                            <option value="soon" <?= $filter_expiry === 'soon' ? 'selected' : '' ?>>Expiring Soon</option>
                            <option value="expired" <?= $filter_expiry === 'expired' ? 'selected' : '' ?>>Expired</option>
                        </select>

                        <select name="discount" class="field-input filter-select"
                            onchange="document.getElementById('productFilterForm').submit()">
                            <option value="">All Products</option>
                            <option value="active" <?= $filter_discount === 'active' ? 'selected' : '' ?>>🏷️ On Discount
                            </option>
                        </select>

                        <select name="supplier_id" class="field-input filter-select"
                            onchange="document.getElementById('productFilterForm').submit()">
                            <option value="">All Suppliers</option>
                            <option value="none" <?= $filter_supplier === 'none' ? 'selected' : '' ?>>No supplier set
                            </option>
                            <?php if ($unlinked_supplier_count > 0): ?>
                                <option value="unlinked" <?= $filter_supplier === 'unlinked' ? 'selected' : '' ?>>⚠ Not linked
                                    to directory (<?= $unlinked_supplier_count ?>)</option>
                            <?php endif; ?>
                            <?php foreach ($supplier_product_counts as $sup): ?>
                                <option value="<?= enc_id($sup['id']) ?>" <?= $filter_supplier === (string) $sup['id'] ? 'selected' : '' ?>>
                                    <?= clean($sup['company_name']) ?>    <?= $sup['status'] !== 'active' ? ' (inactive)' : '' ?>
                                    (<?= (int) $sup['product_count'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="sort" class="field-input filter-select"
                            onchange="document.getElementById('productFilterForm').submit()">
                            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                            <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High
                            </option>
                            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low
                            </option>
                            <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name: A to Z</option>
                            <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name: Z to A</option>
                            <option value="stock_asc" <?= $sort === 'stock_asc' ? 'selected' : '' ?>>Stock: Low to High
                            </option>
                            <option value="stock_desc" <?= $sort === 'stock_desc' ? 'selected' : '' ?>>Stock: High to Low
                            </option>
                        </select>

                        <?php if (!empty($search) || !empty($filter_category) || !empty($filter_stock) || !empty($filter_expiry) || !empty($filter_discount) || !empty($filter_supplier) || $sort !== 'newest'): ?>
                            <a href="products.php<?= $show_archived ? '?view=archived' : '' ?>"
                                class="action-btn action-btn--small action-btn--secondary">Clear Filters</a>
                        <?php endif; ?>
                    </form>
                    <?php if (!$show_archived): ?>
                        <a href="shelf_labels.php" class="action-btn action-btn--secondary action-btn--qr-labels">Generate
                            QR Labels</a>
                        <a href="product_barcode_labels.php" class="action-btn action-btn--secondary">Print POS Barcodes</a>
                        <a href="products.php?action=add" class="action-btn">+ Add Product</a>
                    <?php endif; ?>
                </div>


                <!-- ADD FORM -->
                <?php if ($show_add_form): ?>
                    <div class="panel">
                        <div class="panel-header">
                            <h2>Add New Product</h2>
                        </div>
                        <div class="panel-body">
                            <form method="POST" action="products.php?action=add" enctype="multipart/form-data"
                                class="product-add-form">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="add">
                                <div class="form-grid">

                                    <div class="field-group field-group--full product-image-focus">
                                        <label class="field-label">Product Image <span
                                                style="color:var(--ink-soft); font-weight:400">(start here — JPG, PNG, WEBP,
                                                max 2MB)</span></label>
                                        <input type="file" id="addProductImage" name="image"
                                            accept="image/jpeg,image/png,image/gif,image/webp" capture="environment"
                                            class="field-input">
                                        <div class="image-upload-tools">
                                            <button type="button" id="openProductCamera"
                                                class="action-btn action-btn--secondary">Use Camera</button>
                                            <span class="field-hint">Start here: upload or capture the product front photo.
                                                Then fill in the business details below.</span>
                                        </div>
                                        <div id="aiPreviewNotice" class="field-hint"
                                            style="display:none; color:var(--green-mid); font-weight:600; min-height:20px;">
                                        </div>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Product Name <span
                                                style="color:var(--red-alert)">*</span></label>
                                        <input type="text" name="product_name" class="field-input"
                                            placeholder="e.g. Coke 1.5L" required>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Barcode / UPC / Store Code</label>
                                        <div class="barcode-entry">
                                            <input type="text" id="addProductBarcode" name="barcode" class="field-input"
                                                placeholder="Scan or type manufacturer barcode" maxlength="64"
                                                autocomplete="off">
                                            <button type="button" id="scanProductBarcode"
                                                class="action-btn action-btn--secondary"
                                                aria-label="Scan product barcode or QR code"
                                                title="Scan product barcode or QR code">Scan</button>
                                            <button type="button" id="uploadProductBarcodeImage"
                                                class="action-btn action-btn--secondary">Add file</button>
                                            <input type="file" id="productBarcodeImage" accept="image/*" hidden>
                                        </div>
                                        <span class="field-hint">If left blank, an internal POS barcode is assigned on save.
                                            Internal codes are not manufacturer UPC/EANs.</span>
                                        <span id="addBarcodeLookupNotice" class="field-hint" aria-live="polite"></span>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Category <span
                                                style="color:var(--red-alert)">*</span></label>
                                        <select name="category" class="field-input" required>
                                            <option value="">-- Select Category --</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= clean($cat['category_name']) ?>">
                                                    <?= clean($cat['category_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Price (₱) <span
                                                style="color:var(--red-alert)">*</span></label>
                                        <input type="number" name="price" id="addPriceInput" step="0.01" min="0.01"
                                            max="99999.99" class="field-input" placeholder="0.00" required
                                            oninput="this.value = this.value.replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1'); syncDiscountValueLimit('addDiscountType','addDiscountValue','addPriceInput')">
                                        <span class="field-hint">Max: ₱99,999.99</span>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Stock <span
                                                style="color:var(--red-alert)">*</span></label>
                                        <input type="number" name="stock" min="0" max="99999" class="field-input"
                                            placeholder="0" required
                                            oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                        <span class="field-hint">Max: 99,999 pcs</span>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Supplier <span
                                                style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                                        <?php if (empty($active_suppliers)): ?>
                                            <input type="text" class="field-input" value="No active suppliers yet" disabled
                                                style="opacity:.7">
                                            <span class="field-hint">No suppliers in the directory yet. <a
                                                    href="suppliers.php?action=add">Add a supplier</a> first, then it'll show up
                                                here.</span>
                                        <?php else: ?>
                                            <select name="supplier_id" class="field-input">
                                                <option value="">-- No supplier --</option>
                                                <?php foreach ($active_suppliers as $sup): ?>
                                                    <option value="<?= (int) $sup['id'] ?>"><?= clean($sup['company_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span class="field-hint">Not on the list? <a href="suppliers.php?action=add">Add it
                                                    in Suppliers</a>.</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Expiry Date <span
                                                style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                                        <input type="date" name="expiry_date" class="field-input"
                                            min="<?= date('Y-m-d') ?>">
                                        <span class="field-hint">This becomes the batch's expiry. Leave blank if no expiry
                                            (e.g. cleaning supplies).</span>
                                    </div>

                                    <div class="field-group field-group--full discount-fields">
                                        <div class="field-group">
                                            <label class="field-label">Discount <span
                                                    style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                                            <select name="discount_type" id="addDiscountType" class="field-input"
                                                onchange="syncDiscountValueLimit('addDiscountType','addDiscountValue','addPriceInput')">
                                                <option value="none">No discount</option>
                                                <option value="percent">Percent off (%)</option>
                                                <option value="fixed">Fixed amount off (₱)</option>
                                            </select>
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label">Discount Value</label>
                                            <input type="number" name="discount_value" id="addDiscountValue" step="0.01"
                                                min="0" max="100" class="field-input" placeholder="0"
                                                oninput="clampDiscountValue(this)">
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label">Reason <span
                                                    style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                                            <input type="text" name="discount_reason" class="field-input"
                                                placeholder="e.g. Nearing expiry, Holiday promo" maxlength="100">
                                        </div>
                                    </div>

                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="action-btn">Save Product</button>
                                    <a href="products.php" class="action-btn action-btn--secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>


                <!-- EDIT FORM -->
                <?php if ($edit_product): ?>
                    <?php if ($edit_product_pending_discount): ?>
                        <!-- Lives outside the Edit Product form below on purpose — see the
                 Cancel Request button further down for why. Purely functional
                 (no visible content of its own); the visible button that
                 submits it is form="cancel-discount-form" inside the notice. -->
                        <form id="cancel-discount-form" method="POST"
                            action="products.php?edit=<?= enc_id($edit_product['id']) ?>"
                            onsubmit="return confirmAction(event, this, {icon:'question', title:'Cancel Discount Request?', text:'This will withdraw the pending discount change for <?= json_encode((string) $edit_product['product_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>.', confirmButtonText:'Yes, cancel it', confirmButtonColor:'#C8472D'});">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="cancel_product_discount_request">
                            <input type="hidden" name="request_id"
                                value="<?= (int) $edit_product_pending_discount['request_id'] ?>">
                        </form>
                    <?php endif; ?>
                    <div class="panel">
                        <div class="panel-header">
                            <h2>Edit Product: <?= clean($edit_product['product_name']) ?></h2>
                        </div>
                        <div class="panel-body">
                            <form method="POST" action="products.php?edit=<?= enc_id($edit_product['id']) ?>"
                                enctype="multipart/form-data" class="product-edit-form">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="product_id" value="<?= $edit_product['id'] ?>">
                                <div class="form-grid">

                                    <div class="field-group field-group--full product-image-focus">
                                        <label class="field-label">Product Image <span
                                                style="color:var(--ink-soft); font-weight:400">(optional replacement — JPG,
                                                PNG, WEBP, max 2MB)</span></label>
                                        <?php if (!empty($edit_product['image']) && $edit_product['image'] != 'default.png'): ?>
                                            <div class="current-image-wrap">
                                                <img src="uploads/products/<?= clean($edit_product['image']) ?>"
                                                    alt="Current product image">
                                                <span>Current image</span>
                                            </div>
                                        <?php endif; ?>
                                        <input type="file" id="editProductImage" name="image"
                                            accept="image/jpeg,image/png,image/gif,image/webp" capture="environment"
                                            class="field-input">
                                        <div class="image-upload-tools">
                                            <button type="button" id="openProductCamera"
                                                class="action-btn action-btn--secondary">Use Camera</button>
                                            <span class="field-hint">Upload or capture a clear front-package photo. Leave
                                                empty to keep the current image.</span>
                                        </div>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Product Name <span
                                                style="color:var(--red-alert)">*</span></label>
                                        <input type="text" name="product_name" class="field-input"
                                            value="<?= clean($edit_product['product_name']) ?>" required>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Barcode / UPC / Store Code</label>
                                        <div class="barcode-entry">
                                            <input type="text" id="editProductBarcode" name="barcode" class="field-input"
                                                value="<?= clean($edit_product['barcode'] ?? '') ?>"
                                                placeholder="Scan or type manufacturer barcode" maxlength="64"
                                                autocomplete="off">
                                            <button type="button" id="scanProductBarcode"
                                                class="action-btn action-btn--secondary"
                                                aria-label="Scan product barcode or QR code"
                                                title="Scan product barcode or QR code">Scan</button>
                                            <button type="button" id="uploadProductBarcodeImage"
                                                class="action-btn action-btn--secondary">Add file</button>
                                            <input type="file" id="productBarcodeImage" accept="image/*" hidden>
                                        </div>
                                        <span class="field-hint">Internal codes are for this store and are not manufacturer
                                            UPC/EANs.</span>
                                        <span id="editBarcodeLookupNotice" class="field-hint" aria-live="polite"></span>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Category <span
                                                style="color:var(--red-alert)">*</span></label>
                                        <select name="category" class="field-input" required>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= clean($cat['category_name']) ?>"
                                                    <?= $cat['category_name'] == $edit_product['category'] ? 'selected' : '' ?>>
                                                    <?= clean($cat['category_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Price (₱) <span
                                                style="color:var(--red-alert)">*</span></label>
                                        <input type="number" name="price" id="editPriceInput" step="0.01" min="0.01"
                                            max="99999.99" class="field-input" value="<?= $edit_product['price'] ?>"
                                            required
                                            oninput="this.value = this.value.replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1'); syncDiscountValueLimit('editDiscountType','editDiscountValue','editPriceInput')">
                                        <span class="field-hint">Max: ₱99,999.99</span>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Stock</label>
                                        <input type="text" class="field-input" value="<?= $edit_product['stock'] ?> pcs"
                                            disabled style="opacity:.7">
                                        <span class="field-hint">Stock is now managed per batch — use Restock/Adjust in <a
                                                href="inventory.php">Inventory</a>.</span>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Supplier <span
                                                style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                                        <?php
                                        $current_supplier_id = $edit_product['supplier_id'] ?? null;
                                        $current_supplier_row = $current_supplier_id ? getSupplierById($pdo, $current_supplier_id) : null;
                                        $legacy_text = (!$current_supplier_id && !empty($edit_product['supplier'])) ? $edit_product['supplier'] : '';
                                        // Already-linked supplier that's since been deactivated — keep it
                                        // selectable/visible so saving the form doesn't silently unlink it.
                                        $current_is_inactive_only = $current_supplier_row && $current_supplier_row['status'] !== 'active';
                                        ?>
                                        <?php if (empty($active_suppliers) && $legacy_text === '' && !$current_is_inactive_only): ?>
                                            <input type="text" class="field-input" value="No active suppliers yet" disabled
                                                style="opacity:.7">
                                            <span class="field-hint">No suppliers in the directory yet. <a
                                                    href="suppliers.php?action=add">Add a supplier</a> first, then it'll show up
                                                here.</span>
                                        <?php else: ?>
                                            <select name="supplier_id" class="field-input">
                                                <option value="">-- No supplier --</option>
                                                <?php if ($legacy_text !== ''): ?>
                                                    <option value="__legacy__" selected>
                                                        <?= clean($legacy_text) ?> (not in Supplier directory — pick one below to
                                                        link)
                                                    </option>
                                                <?php endif; ?>
                                                <?php if ($current_is_inactive_only): ?>
                                                    <option value="<?= (int) $current_supplier_row['id'] ?>" selected>
                                                        <?= clean($current_supplier_row['company_name']) ?> (inactive)
                                                    </option>
                                                <?php endif; ?>
                                                <?php foreach ($active_suppliers as $sup): ?>
                                                    <option value="<?= (int) $sup['id'] ?>" <?= (!$legacy_text && !$current_is_inactive_only && (int) $sup['id'] === (int) $current_supplier_id) ? 'selected' : '' ?>>
                                                        <?= clean($sup['company_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span class="field-hint">Not on the list? <a href="suppliers.php?action=add">Add it
                                                    in Suppliers</a>.</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="field-group field-group--full">
                                        <label class="field-label">Expiry / Batches</label>
                                        <input type="text" class="field-input" value="Managed per batch" disabled
                                            style="opacity:.7">
                                        <div class="form-actions">
                                            <a href="product_batches.php?product_id=<?= enc_id($edit_product['id']) ?>"
                                                class="action-btn action-btn--secondary">View / manage batches &amp; expiry
                                                dates →</a>
                                            <a href="product_details_edit.php?product_id=<?= enc_id($edit_product['id']) ?>"
                                                class="action-btn action-btn--secondary">Edit product details and
                                                description →</a>
                                        </div>
                                    </div>

                                    <?php $edit_product_discount_locked = $edit_product_pending_discount || $edit_product_is_expired; ?>
                                    <div class="field-group field-group--full discount-fields">
                                        <?php if ($edit_product_is_expired): ?>
                                            <div class="field-group field-group--full discount-pending-notice">
                                                <span>
                                                    🚫 <strong>This product has expired and is no longer for sale.</strong>
                                                    A discount can't be applied while it's expired — restock with a new batch
                                                    first if it's back for sale.
                                                </span>
                                            </div>
                                        <?php elseif ($edit_product_pending_discount): ?>
                                            <div class="field-group field-group--full discount-pending-notice">
                                                <span>
                                                    ⏳ <strong>Discount change awaiting Finance approval</strong> —
                                                    <?php if ($edit_product_pending_discount['discount_type'] === 'none'): ?>
                                                        requested to <strong>remove</strong> the current discount.
                                                    <?php else: ?>
                                                        requested
                                                        <strong><?= $edit_product_pending_discount['discount_type'] === 'percent' ? clean((string) (float) $edit_product_pending_discount['discount_value']) . '% off' : 'Save ' . peso($edit_product_pending_discount['discount_value']) ?></strong><?= !empty($edit_product_pending_discount['discount_reason']) ? ' — ' . clean($edit_product_pending_discount['discount_reason']) : '' ?>.
                                                    <?php endif; ?>
                                                    The fields below stay locked, and the product keeps its current discount,
                                                    until Finance decides.
                                                </span>
                                                <!-- This button submits #cancel-discount-form, which lives OUTSIDE
                                             the main Edit Product form below (see just before the form opens).
                                             It used to be a real nested <form> right here, but a <form> can't
                                             be nested inside another <form> — the browser was silently
                                             auto-closing the big Edit Product form the moment it hit this one,
                                             orphaning every field after it (discount value/reason, image
                                             upload, the Update Product button itself). Same form="..." trick
                                             already used for the resume batch checkboxes in HRMS/employees.php. -->
                                                <button type="submit" form="cancel-discount-form"
                                                    class="action-btn action-btn--secondary"
                                                    style="padding:7px 14px; font-size:12.5px; white-space:nowrap;">Cancel
                                                    Request</button>
                                            </div>
                                        <?php endif; ?>
                                        <div
                                            class="field-group<?= $edit_product_discount_locked ? ' discount-locked' : '' ?>">
                                            <label class="field-label">Discount <span
                                                    style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                                            <select name="discount_type" id="editDiscountType" class="field-input"
                                                onchange="syncDiscountValueLimit('editDiscountType','editDiscountValue','editPriceInput')"
                                                <?= $edit_product_is_expired ? 'disabled' : '' ?>>
                                                <option value="none" <?= ($edit_product['discount_type'] ?? 'none') === 'none' ? 'selected' : '' ?>>No discount</option>
                                                <option value="percent" <?= ($edit_product['discount_type'] ?? '') === 'percent' ? 'selected' : '' ?>>Percent off (%)</option>
                                                <option value="fixed" <?= ($edit_product['discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed amount off (₱)</option>
                                            </select>
                                        </div>
                                        <div
                                            class="field-group<?= $edit_product_discount_locked ? ' discount-locked' : '' ?>">
                                            <label class="field-label">Discount Value</label>
                                            <input type="number" name="discount_value" id="editDiscountValue" step="0.01"
                                                min="0"
                                                max="<?= ($edit_product['discount_type'] ?? 'none') === 'fixed' ? (float) $edit_product['price'] : 100 ?>"
                                                class="field-input"
                                                value="<?= (float) ($edit_product['discount_value'] ?? 0) ?>"
                                                oninput="clampDiscountValue(this)" <?= $edit_product_is_expired ? 'disabled' : '' ?>>
                                        </div>
                                        <div
                                            class="field-group<?= $edit_product_discount_locked ? ' discount-locked' : '' ?>">
                                            <label class="field-label">Reason <span
                                                    style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                                            <input type="text" name="discount_reason" class="field-input"
                                                value="<?= clean($edit_product['discount_reason'] ?? '') ?>"
                                                placeholder="e.g. Nearing expiry, Holiday promo" maxlength="100"
                                                <?= $edit_product_is_expired ? 'disabled' : '' ?>>
                                        </div>
                                    </div>

                                </div>
                                <div class="form-actions form-actions--edit">
                                    <button type="submit" class="action-btn">Update Product</button>
                                    <a href="products.php" class="action-btn action-btn--secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>


                <!-- PRODUCTS TABLE -->
                <div class="panel">
                    <div class="panel-header">
                        <h2><?= $show_archived ? 'Archived Products' : 'All Products' ?> (<?= count($products) ?>)</h2>
                    </div>
                    <div class="panel-body panel-body--table">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Image</th>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Supplier</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Details review</th>
                                    <th>Expiry Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($products)): ?>
                                    <tr>
                                        <td colspan="9" class="empty-row">
                                            <?= $show_archived
                                                ? 'No archived products.'
                                                : 'No products yet. Click "+ Add Product" to get started.' ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($products as $product): ?>
                                        <tr>
                                            <td>
                                                <?php
                                                $img_path = $upload_folder . $product['image'];
                                                if (!empty($product['image']) && $product['image'] != 'default.png' && file_exists($img_path)):
                                                    ?>
                                                    <img src="uploads/products/<?= clean($product['image']) ?>"
                                                        alt="<?= clean($product['product_name']) ?>" class="product-thumb">
                                                <?php else: ?>
                                                    <div class="product-thumb-placeholder">🛒</div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-weight:600"><?= clean($product['product_name']) ?></td>
                                            <td><?= clean($product['category']) ?></td>
                                            <td>
                                                <?php if (!empty($product['supplier_company_name'])): ?>
                                                    <?= clean($product['supplier_company_name']) ?>            <?php if ($product['supplier_company_status'] !== 'active'): ?>
                                                        <span
                                                            style="color:var(--ink-soft);font-size:11px">(inactive)</span><?php endif; ?>
                                                <?php elseif (!empty($product['supplier'])): ?>
                                                    <span title="Not linked to a Supplier record — open Edit to link it."
                                                        style="color:var(--mustard, #b45309);font-size:12px">⚠
                                                        <?= clean($product['supplier']) ?></span>
                                                <?php else: ?>
                                                    <span style="color:var(--ink-soft);font-size:12px">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php $disc = getDiscountedPrice($product); ?>
                                                <?php if ($disc['has_discount']): ?>
                                                    <span class="price-original"><?= peso($disc['original_price']) ?></span>
                                                    <span class="price-discounted"><?= peso($disc['final_price']) ?></span>
                                                    <br>
                                                    <span class="discount-badge"
                                                        title="<?= clean($product['discount_reason'] ?? '') ?>">
                                                        🏷️
                                                        <?= $product['discount_type'] === 'percent' ? clean((string) (float) $product['discount_value']) . '% off' : 'Save ' . peso($product['discount_value']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <?= peso($product['price']) ?>
                                                <?php endif; ?>
                                                <?php if (!empty($pending_discount_by_product[$product['id']])): ?>
                                                    <br><span class="discount-awaiting-badge">⏳ Discount awaiting Finance</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($product['stock'] == 0): ?>
                                                    <span style="color:var(--red-alert); font-weight:700">Out of stock</span>
                                                <?php elseif ($product['stock'] <= LOW_STOCK): ?>
                                                    <span style="color:var(--mustard); font-weight:700"><?= $product['stock'] ?> pcs
                                                        ⚠️</span>
                                                <?php else: ?>
                                                    <?= $product['stock'] ?> pcs
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $detailsReviewStatus = (string) ($product['product_details_review_status'] ?? 'draft');
                                                $detailsReviewLabel = str_replace('_', ' ', $detailsReviewStatus);
                                                $detailsReviewColor = [
                                                    'draft' => 'var(--ink-soft)',
                                                    'needs_review' => 'var(--mustard, #b45309)',
                                                    'verified' => 'var(--green, #1a7a3b)',
                                                    'rejected' => 'var(--red-alert, #c8472d)',
                                                ][$detailsReviewStatus] ?? 'var(--ink-soft)';
                                                ?>
                                                <a href="product_details_edit.php?product_id=<?= enc_id($product['id']) ?>"
                                                    style="color:<?= clean($detailsReviewColor) ?>;font-weight:700;text-transform:capitalize">
                                                    <?= clean($detailsReviewLabel) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <?php
                                                $nearest_expiry = $nearest_expiry_map[$product['id']] ?? null;
                                                $batch_count = $batch_count_map[$product['id']] ?? 0;
                                                $status = getExpiryStatus($nearest_expiry);
                                                if ($status):
                                                    ?>
                                                    <span class="expiry-badge" style="color:<?= $status['color'] ?>">
                                                        <?= $status['icon'] ?>             <?= $status['label'] ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color:var(--ink-soft); font-size:12px">—</span>
                                                <?php endif; ?>
                                                <?php if ($batch_count > 0): ?>
                                                    <br><a href="product_batches.php?product_id=<?= enc_id($product['id']) ?>"
                                                        class="link-btn" style="font-size:11px;"><?= $batch_count ?>
                                                        batch<?= $batch_count > 1 ? 'es' : '' ?></a>
                                                <?php endif; ?>
                                            </td>
                                            <td class="actions-cell">
                                                <div class="actions-wrap">
                                                    <?php if ($show_archived): ?>
                                                        <form method="POST" action="products.php?view=archived" class="inline-form"
                                                            onsubmit="return confirmAction(event, this, {icon:'question', title:'Restore Product?', text:'Restore <?= json_encode((string) $product['product_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>? It will appear in Active Products again.', confirmButtonText:'Yes, restore', confirmButtonColor:'#123C5C'});">
                                                            <?php csrfField(); ?>
                                                            <input type="hidden" name="action" value="restore">
                                                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                                            <button type="submit" class="link-btn" title="Restore"
                                                                aria-label="Restore"><i
                                                                    class="fa-solid fa-rotate-left"></i></button>
                                                        </form>
                                                    <?php else: ?>
                                                        <a href="products.php?edit=<?= enc_id($product['id']) ?>" class="link-btn"
                                                            title="Edit" aria-label="Edit"><i
                                                                class="fa-regular fa-pen-to-square"></i></a>
                                                        <form method="POST" action="products.php" class="inline-form"
                                                            onsubmit="return confirmAction(event, this, {icon:'warning', title:'Delete Product?', text:'Delete <?= json_encode((string) $product['product_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>? This cannot be undone.', confirmButtonText:'Yes, delete', confirmButtonColor:'#C8472D'});">
                                                            <?php csrfField(); ?>
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                                            <button type="submit" class="link-btn link-btn--danger" title="Delete"
                                                                aria-label="Delete"><i class="fa-regular fa-trash-can"></i></button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
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
    <?php if ($show_add_form || $edit_product): ?>
        <div class="camera-modal" id="productCameraModal" role="dialog" aria-modal="true"
            aria-labelledby="productCameraTitle">
            <div class="camera-modal__card">
                <h2 class="camera-modal__title" id="productCameraTitle">Take Product Photo</h2>
                <video id="productCameraVideo" autoplay playsinline muted></video>
                <canvas id="productCameraCanvas"></canvas>
                <p class="camera-modal__note" id="productCameraNote">Place the product label inside the frame and use good
                    lighting.</p>
                <div class="camera-modal__actions">
                    <button type="button" id="closeProductCamera" class="action-btn action-btn--secondary">Cancel</button>
                    <button type="button" id="retakeProductPhoto" class="action-btn action-btn--secondary"
                        hidden>Retake</button>
                    <button type="button" id="captureProductPhoto" class="action-btn">Capture Photo</button>
                    <button type="button" id="useProductPhoto" class="action-btn" hidden>Use Photo</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($show_add_form || $edit_product): ?>
        <div class="camera-modal barcode-scan-modal" id="barcodeScanModal" role="dialog" aria-modal="true"
            aria-labelledby="barcodeScanTitle">
            <div class="camera-modal__card">
                <h2 class="camera-modal__title" id="barcodeScanTitle">Scan Product Barcode / QR</h2>
                <div class="barcode-reader" id="barcodeReader"></div>
                <p class="camera-modal__note" id="barcodeScanNote" aria-live="polite">Keep the full barcode inside the
                    frame. Move closer slowly until the bars look sharp, then hold steady. Use zoom or flashlight if
                    available.</p>
                <div class="camera-modal__actions barcode-scan-controls">
                    <label for="barcodeScanZoom" id="barcodeScanZoomLabel" hidden>Zoom</label>
                    <input type="range" id="barcodeScanZoom" min="1" max="1" step="0.1" value="1" aria-label="Camera zoom"
                        hidden>
                    <button type="button" id="barcodeScanTorch" class="action-btn action-btn--secondary" disabled>Flashlight
                        unavailable</button>
                    <button type="button" id="closeBarcodeScan" class="action-btn action-btn--secondary">Cancel</button>
                </div>
            </div>
        </div>
        <script src="assets/js/html5-qrcode.min.js"></script>
    <?php endif; ?>

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
        function confirmAction(e, form, opts) {
            e.preventDefault();
            Swal.fire(Object.assign({
                showCancelButton: true,
                cancelButtonText: 'Cancel'
            }, opts)).then(function (result) {
                if (result.isConfirmed) form.submit();
            });
            return false;
        }

        // Caps the Discount Value field: "Percent off" can't go above 100, and
        // "Fixed amount off" can't go above (or equal) the product's own price
        // — a discount that big or bigger would make the item free or negative.
        function syncDiscountValueLimit(selectId, inputId, priceInputId) {
            var select = document.getElementById(selectId);
            var input = document.getElementById(inputId);
            if (!select || !input) return;

            if (select.value === 'percent') {
                input.max = 100;
                if (parseFloat(input.value) > 100) input.value = 100;
            } else if (select.value === 'fixed') {
                var priceEl = priceInputId ? document.getElementById(priceInputId) : null;
                var price = priceEl ? parseFloat(priceEl.value) : NaN;
                if (!isNaN(price) && price > 0) {
                    // Just under the price, since a discount equal to the price
                    // isn't allowed either (it would make the item free).
                    var cap = Math.max(0, price - 0.01);
                    input.max = cap;
                    if (parseFloat(input.value) > cap) input.value = cap.toFixed(2);
                } else {
                    input.removeAttribute('max');
                }
            } else {
                input.removeAttribute('max');
            }
        }
        function clampDiscountValue(input) {
            var max = input.getAttribute('max');
            if (max !== null && input.value !== '' && parseFloat(input.value) > parseFloat(max)) {
                input.value = max;
            }
        }
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            syncDiscountValueLimit('addDiscountType', 'addDiscountValue', 'addPriceInput');
            syncDiscountValueLimit('editDiscountType', 'editDiscountValue', 'editPriceInput');
        });
    </script>

    <script>
        // Live search: auto-submits the search form a moment after the user
        // stops typing, so matching products show up right away (even from
        // the first letter) instead of needing to click "Search".
        (function () {
            var input = document.getElementById('productSearchInput');
            if (!input) return;
            var timer = null;
            input.addEventListener('input', function () {
                if (timer) clearTimeout(timer);
                timer = setTimeout(function () { input.form.submit(); }, 450);
            });
        })();
    </script>

    <?php if ($show_add_form || $edit_product): ?>
        <script>
            (function () {
                var input = document.getElementById('addProductImage') || document.getElementById('editProductImage');
                var openButton = document.getElementById('openProductCamera');
                var modal = document.getElementById('productCameraModal');
                var video = document.getElementById('productCameraVideo');
                var canvas = document.getElementById('productCameraCanvas');
                var note = document.getElementById('productCameraNote');
                var closeButton = document.getElementById('closeProductCamera');
                var captureButton = document.getElementById('captureProductPhoto');
                var retakeButton = document.getElementById('retakeProductPhoto');
                var useButton = document.getElementById('useProductPhoto');
                var previewNotice = document.getElementById('aiPreviewNotice');
                var stream = null;
                var photoFile = null;
                var productForm = input && input.form ? input.form : null;
                if (!input || !openButton || !modal) return;

                function setPreviewNotice(message, isError) {
                    if (!previewNotice) return;
                    previewNotice.textContent = message || '';
                    previewNotice.style.display = message ? 'block' : 'none';
                    previewNotice.style.color = isError ? 'var(--red-alert)' : 'var(--green-mid)';
                }

                function applyAiPreview(file) {
                    if (!file || !productForm || !file.type || !file.type.startsWith('image/')) {
                        return;
                    }

                    var csrfInput = productForm.querySelector('input[name="csrf_token"]');
                    if (!csrfInput) {
                        return;
                    }

                    var formData = new FormData();
                    formData.append('label_image', file);
                    formData.append('photo_type', 'front');
                    formData.append('preview_only', '1');
                    formData.append('csrf_token', csrfInput.value);

                    setPreviewNotice('AI is reading the front photo and pre-filling the product name and category…');

                    fetch('ai_product_ingest.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                        .then(function (response) { return response.json().then(function (body) { return { ok: response.ok, body: body }; }); })
                        .then(function (result) {
                            var payload = result.body || {};
                            if (!result.ok || !payload.ok) {
                                setPreviewNotice(payload.error || 'AI could not read that front photo yet. Please review the fields manually.', true);
                                return;
                            }

                            var productNameInput = productForm.querySelector('input[name="product_name"]');
                            var categorySelect = productForm.querySelector('select[name="category"]');
                            var suggestedName = payload.suggested_name || '';
                            var suggestedCategory = payload.suggested_category || '';

                            if (productNameInput && suggestedName) {
                                productNameInput.value = suggestedName;
                            }
                            if (categorySelect && suggestedCategory) {
                                var matched = Array.from(categorySelect.options).find(function (option) {
                                    return option.value.trim().toLowerCase() === suggestedCategory.trim().toLowerCase();
                                });
                                if (matched) {
                                    categorySelect.value = matched.value;
                                }
                            }

                            setPreviewNotice(suggestedName && suggestedCategory ? 'AI suggested: ' + suggestedName + ' · ' + suggestedCategory : 'AI reviewed the front photo and filled the available product details.');
                        })
                        .catch(function () {
                            setPreviewNotice('AI preview could not finish. You can still fill the product details manually.', true);
                        });
                }

                function stopCamera() {
                    if (stream) {
                        stream.getTracks().forEach(function (track) { track.stop(); });
                        stream = null;
                    }
                    video.srcObject = null;
                }
                function resetCamera() {
                    stopCamera();
                    photoFile = null;
                    modal.classList.remove('open');
                    video.style.display = 'block';
                    canvas.style.display = 'none';
                    captureButton.hidden = false;
                    retakeButton.hidden = true;
                    useButton.hidden = true;
                    note.textContent = 'Place the product label inside the frame and use good lighting.';
                }
                openButton.addEventListener('click', function () {
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        note.textContent = 'Camera access is unavailable here. Please choose an image file instead.';
                        modal.classList.add('open');
                        return;
                    }
                    modal.classList.add('open');
                    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
                        .then(function (activeStream) {
                            stream = activeStream;
                            video.srcObject = stream;
                        })
                        .catch(function () {
                            note.textContent = 'Camera could not start. Allow camera access or choose an image file instead.';
                        });
                });
                captureButton.addEventListener('click', function () {
                    if (!video.videoWidth || !video.videoHeight) {
                        note.textContent = 'Wait for the camera preview, then try again.';
                        return;
                    }
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob(function (blob) {
                        if (!blob) {
                            note.textContent = 'The photo could not be captured. Please try again.';
                            return;
                        }
                        photoFile = new File([blob], 'product-camera.jpg', { type: 'image/jpeg' });
                        stopCamera();
                        video.style.display = 'none';
                        canvas.style.display = 'block';
                        captureButton.hidden = true;
                        retakeButton.hidden = false;
                        useButton.hidden = false;
                        note.textContent = 'Review the photo, then choose Use Photo.';
                    }, 'image/jpeg', 0.88);
                });
                retakeButton.addEventListener('click', function () {
                    resetCamera();
                    openButton.click();
                });
                useButton.addEventListener('click', function () {
                    if (!photoFile) return;
                    var transfer = new DataTransfer();
                    transfer.items.add(photoFile);
                    input.files = transfer.files;
                    resetCamera();
                    applyAiPreview(photoFile);
                });
                input.addEventListener('change', function () {
                    if (this.files && this.files[0]) {
                        applyAiPreview(this.files[0]);
                    }
                });
                closeButton.addEventListener('click', resetCamera);
                modal.addEventListener('click', function (event) {
                    if (event.target === modal) resetCamera();
                });
                window.addEventListener('beforeunload', stopCamera);
            }());
        </script>
    <?php endif; ?>
    <?php if ($show_add_form || $edit_product): ?>
        <script>
            (function () {
                var barcodeInput = document.getElementById('addProductBarcode') || document.getElementById('editProductBarcode');
                if (!barcodeInput || !barcodeInput.form) return;

                var productForm = barcodeInput.form;
                var notice = document.getElementById('addBarcodeLookupNotice') || document.getElementById('editBarcodeLookupNotice');
                var productNameInput = productForm.querySelector('input[name="product_name"]');
                var categorySelect = productForm.querySelector('select[name="category"]');
                var categories = categorySelect ? Array.from(categorySelect.options)
                    .filter(function (option) { return option.value !== ''; })
                    .map(function (option) { return option.value; }) : [];
                var manuallyEditedName = false;
                var manuallyEditedCategory = false;
                var lookupTimer = null;
                var activeRequest = null;

                if (productNameInput) {
                    productNameInput.addEventListener('input', function () { manuallyEditedName = true; });
                }
                if (categorySelect) {
                    categorySelect.addEventListener('change', function () { manuallyEditedCategory = true; });
                }

                function setNotice(message, isError) {
                    if (!notice) return;
                    notice.textContent = message;
                    notice.style.color = isError ? 'var(--red-alert)' : 'var(--green-mid)';
                }

                function normalizeCategory(value) {
                    return String(value || '')
                        .replace(/^[a-z]{2,3}:/i, '')
                        .replace(/[_-]+/g, ' ')
                        .trim()
                        .toLowerCase();
                }

                function findCategory(product) {
                    var candidates = [];
                    if (Array.isArray(product.categories_tags)) {
                        candidates = candidates.concat(product.categories_tags);
                    }
                    if (typeof product.categories === 'string') {
                        candidates = candidates.concat(product.categories.split(','));
                    }

                    for (var index = candidates.length - 1; index >= 0; index--) {
                        var candidate = normalizeCategory(candidates[index]);
                        var match = categories.find(function (category) {
                            return normalizeCategory(category) === candidate;
                        });
                        if (match) return match;
                    }
                    return '';
                }

                barcodeInput.addEventListener('input', function () {
                    if (lookupTimer) clearTimeout(lookupTimer);
                    if (activeRequest) {
                        activeRequest.abort();
                        activeRequest = null;
                    }

                    var barcode = barcodeInput.value.trim();
                    if (!barcode) {
                        setNotice('', false);
                        return;
                    }
                    if (/^20\d{11}$/.test(barcode)) {
                        setNotice('This is an internal POS barcode, not a manufacturer UPC/EAN.', false);
                        return;
                    }
                    if (!/^\d{8}$|^\d{12,14}$/.test(barcode)) {
                        setNotice('Enter a valid 8, 12, 13, or 14-digit barcode to look up product details, or keep the assigned store code.', true);
                        return;
                    }

                    lookupTimer = setTimeout(function () {
                        var request = new AbortController();
                        activeRequest = request;
                        setNotice('Looking up barcode…', false);
                        fetch('product_barcode_lookup.php?barcode=' + encodeURIComponent(barcode), {
                            signal: request.signal
                        })
                            .then(function (response) {
                                return response.json().then(function (payload) {
                                    if (!response.ok || !payload.ok) {
                                        throw new Error(payload.error || 'Barcode lookup could not be completed.');
                                    }
                                    return payload;
                                });
                            })
                            .then(function (payload) {
                                if (request !== activeRequest || barcodeInput.value.trim() !== barcode) return;
                                if (!payload.found || !payload.product) {
                                    setNotice('No product details were found for this barcode. You can enter them manually.', true);
                                    return;
                                }

                                var product = payload.product;
                                var name = String(product.product_name || product.product_name_en || '').trim();
                                var brand = String(product.brands || '').split(',')[0].trim();
                                var quantity = String(product.quantity || '').trim();
                                if (name && brand && name.toLowerCase().indexOf(brand.toLowerCase()) === -1) {
                                    name = brand + ' ' + name;
                                }
                                if (name && quantity && name.toLowerCase().indexOf(quantity.toLowerCase()) === -1) {
                                    name += ' ' + quantity;
                                }

                                var filled = [];
                                if (name && productNameInput && !manuallyEditedName) {
                                    productNameInput.value = name;
                                    filled.push('name');
                                }
                                var category = findCategory(product);
                                if (category && categorySelect && !manuallyEditedCategory) {
                                    categorySelect.value = category;
                                    filled.push('category');
                                }

                                if (filled.length) {
                                    setNotice('Barcode found. Product ' + filled.join(' and ') + ' filled from Open Food Facts; please review.', false);
                                } else if ((name && manuallyEditedName) || (category && manuallyEditedCategory)) {
                                    setNotice('Barcode found. Your manually edited product details were kept.', false);
                                } else {
                                    setNotice('Barcode found, but no matching product name or category could be filled. You can enter them manually.', true);
                                }
                            })
                            .catch(function (error) {
                                if (error.name === 'AbortError' || request !== activeRequest) return;
                                setNotice(error.message || 'Barcode lookup could not be completed. Check your connection or enter the product details manually.', true);
                            })
                            .finally(function () {
                                if (activeRequest === request) activeRequest = null;
                            });
                    }, 500);
                });
            }());
        </script>
    <?php endif; ?>
    <?php if ($show_add_form || $edit_product): ?>
        <script>
            (function () {
                var openButton = document.getElementById('scanProductBarcode');
                var uploadButton = document.getElementById('uploadProductBarcodeImage');
                var imageInput = document.getElementById('productBarcodeImage');
                var modal = document.getElementById('barcodeScanModal');
                var closeButton = document.getElementById('closeBarcodeScan');
                var torchButton = document.getElementById('barcodeScanTorch');
                var zoomInput = document.getElementById('barcodeScanZoom');
                var zoomLabel = document.getElementById('barcodeScanZoomLabel');
                var note = document.getElementById('barcodeScanNote');
                var readerElement = document.getElementById('barcodeReader');
                var barcodeInput = document.getElementById('addProductBarcode') || document.getElementById('editProductBarcode');
                var notice = document.getElementById('addBarcodeLookupNotice') || document.getElementById('editBarcodeLookupNotice');
                if (!openButton || !modal || !window.Html5Qrcode) return;

                var formats = Object.keys(Html5QrcodeSupportedFormats)
                    .filter(function (key) { return Number.isNaN(Number(key)); })
                    .map(function (key) { return Html5QrcodeSupportedFormats[key]; });
                var scanner = new Html5Qrcode('barcodeReader', { formatsToSupport: formats, verbose: false });
                var scanning = false;
                var torchEnabled = false;
                var lastScannedCode = '';
                var lastScannedAt = 0;

                function scanBox(viewfinderWidth, viewfinderHeight) {
                    return {
                        width: Math.floor(viewfinderWidth * 0.9),
                        height: Math.floor(viewfinderHeight * 0.75)
                    };
                }

                function signalSuccessfulScan() {
                    if (typeof navigator.vibrate === 'function') {
                        navigator.vibrate([70, 50, 70]);
                    }
                }

                function updateBarcodeCameraControls() {
                    var capabilities = scanner.getRunningTrackCapabilities();
                    var hasTorch = !!(capabilities && capabilities.torch);
                    torchButton.disabled = !hasTorch;
                    torchButton.textContent = hasTorch ? (torchEnabled ? 'Turn flashlight off' : 'Turn flashlight on') : 'Flashlight unavailable';
                    var zoom = capabilities && capabilities.zoom;
                    var hasZoom = !!zoom && typeof zoom.min === 'number' && typeof zoom.max === 'number' && zoom.max > zoom.min;
                    zoomInput.hidden = !hasZoom;
                    zoomLabel.hidden = !hasZoom;
                    if (hasZoom) {
                        zoomInput.min = String(zoom.min);
                        zoomInput.max = String(zoom.max);
                        zoomInput.step = String(zoom.step || Math.max((zoom.max - zoom.min) / 20, 0.1));
                        zoomInput.value = String(zoom.min);
                    }
                }

                function extractProductCode(decoded) {
                    var value = String(decoded || '').trim();
                    var digitalLink = value.match(/(?:^|\/)01\/(\d{14})(?:\/|\?|$)/);
                    if (digitalLink) return digitalLink[1];
                    var gs1 = value.match(/(?:\(01\)|01)(\d{14})/);
                    if (gs1) return gs1[1];
                    if (/^\d{8}$|^\d{12,14}$/.test(value)) return value;
                    if (/^[A-Za-z0-9._-]{3,64}$/.test(value)) return value;
                    return '';
                }

                function setImageNotice(message, isError) {
                    note.textContent = message;
                    if (notice) {
                        notice.textContent = message;
                        notice.style.color = isError ? 'var(--red-alert)' : 'var(--green-mid)';
                    }
                }

                function readImageWithFallback(file) {
                    return scanner.scanFile(file, true).catch(function () {
                        var fallbackModuleUrl = new URL('assets/js/zxing-image-fallback.js', window.location.href).href;
                        return import(fallbackModuleUrl).then(function (module) {
                            return module.readBarcodeImageWithZXing(file);
                        }).then(function (results) {
                            var result = results.find(function (item) { return item.isValid && item.text; });
                            if (!result) throw new Error('The high-sensitivity decoder found no supported code.');
                            return result.text;
                        });
                    });
                }

                function closeScanner() {
                    if (!scanning) {
                        modal.classList.remove('open');
                        return;
                    }
                    scanner.stop().then(function () {
                        scanning = false;
                        modal.classList.remove('open');
                    }).catch(function () {
                        scanning = false;
                        modal.classList.remove('open');
                    });
                }

                uploadButton.addEventListener('click', function () {
                    imageInput.click();
                });

                imageInput.addEventListener('change', function () {
                    var file = imageInput.files && imageInput.files[0];
                    if (!file) return;
                    if (file.type && !file.type.startsWith('image/')) {
                        setImageNotice('Choose an image file containing a barcode or QR code.', true);
                        imageInput.value = '';
                        return;
                    }
                    setImageNotice('Reading barcode image…', false);
                    readImageWithFallback(file).then(function (decoded) {
                        var code = extractProductCode(decoded);
                        if (!code) {
                            setImageNotice('The image code does not contain a supported product barcode value.', true);
                            return;
                        }
                        barcodeInput.value = code;
                        barcodeInput.dispatchEvent(new Event('input', { bubbles: true }));
                        setImageNotice('Product code loaded. Review it in the Barcode / UPC field.', false);
                        barcodeInput.focus();
                    }).catch(function (reason) {
                        setImageNotice('No readable barcode was found. Try a clearer close-up image. ' + String(reason && reason.message ? reason.message : reason), true);
                    }).then(function () {
                        imageInput.value = '';
                    });
                });

                torchButton.addEventListener('click', function () {
                    if (torchButton.disabled || !scanner.isScanning) return;
                    torchEnabled = !torchEnabled;
                    scanner.applyVideoConstraints({ advanced: [{ torch: torchEnabled }] }).then(updateBarcodeCameraControls).catch(function () {
                        torchEnabled = false;
                        setImageNotice('This camera could not change its flashlight setting.', true);
                        updateBarcodeCameraControls();
                    });
                });

                zoomInput.addEventListener('input', function () {
                    if (!scanner.isScanning) return;
                    scanner.applyVideoConstraints({ advanced: [{ zoom: Number(zoomInput.value) }] }).catch(function () {
                        setImageNotice('This camera could not apply zoom. Move the camera closer instead.', true);
                    });
                });

                openButton.addEventListener('click', function () {
                    modal.classList.add('open');
                    torchEnabled = false;
                    note.textContent = 'Keep the full barcode inside the frame. Move closer slowly until the bars look sharp, then hold steady. Use zoom or flashlight if available.';
                    scanner.start(
                        { facingMode: 'environment' },
                        {
                            fps: 15,
                            aspectRatio: 16 / 9,
                            qrbox: scanBox,
                            videoConstraints: {
                                width: { ideal: 1280 },
                                height: { ideal: 720 },
                                facingMode: { ideal: 'environment' },
                                focusMode: { ideal: 'continuous' }
                            }
                        },
                        function (decoded) {
                            var code = extractProductCode(decoded);
                            if (!code) {
                                note.textContent = 'That code did not contain a supported product identifier. Try the printed EAN / UPC barcode.';
                                return;
                            }
                            var now = Date.now();
                            if (code === lastScannedCode && now - lastScannedAt < 2000) return;
                            lastScannedCode = code;
                            lastScannedAt = now;
                            barcodeInput.value = code;
                            barcodeInput.dispatchEvent(new Event('input', { bubbles: true }));
                            note.textContent = 'Detected: ' + code + '. Review it in the Barcode / UPC field.';
                            readerElement.classList.add('is-detected');
                            signalSuccessfulScan();
                            scanner.stop().then(function () {
                                scanning = false;
                                window.setTimeout(function () {
                                    modal.classList.remove('open');
                                    readerElement.classList.remove('is-detected');
                                    barcodeInput.focus();
                                }, 650);
                            }).catch(function (reason) {
                                scanning = false;
                                setImageNotice('Detected: ' + code + ', but the camera did not stop cleanly. ' + String(reason && reason.message ? reason.message : reason), true);
                                window.setTimeout(function () {
                                    modal.classList.remove('open');
                                    readerElement.classList.remove('is-detected');
                                    barcodeInput.focus();
                                }, 650);
                            });
                        },
                        function () { }
                    ).then(function () {
                        scanning = true;
                        updateBarcodeCameraControls();
                    }).catch(function () {
                        note.textContent = 'Camera could not start. Allow camera access or enter the barcode manually.';
                    });
                });

                closeButton.addEventListener('click', closeScanner);
                modal.addEventListener('click', function (event) {
                    if (event.target === modal) closeScanner();
                });
                window.addEventListener('beforeunload', function () {
                    if (scanning) scanner.stop().catch(function () { });
                });
            }());
        </script>
    <?php endif; ?>
</body>

</html>