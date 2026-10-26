<?php
require_once 'includes/config.php';
requireCashier();

// Payment limit: instead of a flat peso cap on change, the max amount
// a cashier can enter as "payment" scales with the total — it's rounded
// up to the nearest common PH bill denomination (₱1,000). This guards
// against fat-finger typos (an extra zero or two) AND against entering
// an unnecessarily large bill when the customer could just hand over a
// smaller one instead (e.g. ₱100 total → max ₱1,000 payment, not ₱2,000).
// Adjust this single constant if the business wants a different step.
const PAYMENT_BILL_STEP = 1000.00;

function maxAllowedPayment(float $total): float {
    return ceil($total / PAYMENT_BILL_STEP) * PAYMENT_BILL_STEP;
}

$myEmployeeId = $_SESSION['employee_id'] ?? null;

// SELF TIME IN — right here on the POS page (popup), so the cashier
// no longer needs to leave pos.php and open My Profile just to clock
// in. Reuses the exact same validation/DB logic as My Profile's Time
// In (processCashierSelfTimeInOut() in includes/config.php). Called
// unconditionally (not just on POST) so the popup below also has the
// shift / Time-In-window info it needs to render correctly on a
// plain GET.
$__att              = processCashierSelfTimeInOut($hrmsPdo, $myEmployeeId);
$__todayShift       = $__att['todayShift'];
$__approvedSwap     = $__att['approvedSwap'] ?? null;
$__timeInWindowOpen = $__att['timeInWindowOpen'] ?? true;
$__timeInOpensAt    = $__att['timeInOpensAt'] ?? null;
$__shiftEnded       = $__att['shiftEnded'] ?? false;
$__lateRequestToday = $__att['lateRequestToday'] ?? null;

// ANNOUNCEMENTS — company-wide, published, not-expired only (read-only,
// via $hrmsPdo). See includes/announcements_panel.php's doc comment for
// the fail-open / scope-filtering rationale; same function, just shown
// here as a topbar bell instead of a full panel since pos.php is a
// dense 3-column terminal screen with no room for a big card list.
$__cashierAnnouncements = function_exists('getCompanyWideAnnouncementsForPos')
    ? getCompanyWideAnnouncementsForPos($hrmsPdo, 5)
    : [];
$__canTimeInLate    = $__att['canTimeInLate'] ?? false;
// Suspension (HRMS Suspend action) — see getCashierSuspensionStatus()
// in includes/config.php. Blocks Time In for the exact number of days
// HR/Admin set, and re-opens on its own once suspension_end_date
// passes — same rule processCashierSelfTimeInOut() enforces server-side.
$__suspension  = $__att['suspension'] ?? ['active' => false, 'days_remaining' => 0, 'reason' => null, 'end_date' => null];
$__isSuspended = $__suspension['active'];
// Whether the plain "Time In Now" button should be usable — either a
// Supervisor already approved a late request (bypasses everything),
// or we're inside the normal window AND the whole shift hasn't
// already ended without a Time In. Never usable while suspended.
$__canSelfTimeInNow = !$__isSuspended && ($__canTimeInLate || ($__timeInWindowOpen && !$__shiftEnded));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['self_time_in', 'request_late_timein', 'supervisor_timein_now'], true)) {
    if (!empty($__att['message'])) $_SESSION['flash_timein_success'] = $__att['message'];
    if (!empty($__att['error']))   $_SESSION['flash_timein_error']   = $__att['error'];
    redirect('pos.php');
}
$timein_flash_success = $_SESSION['flash_timein_success'] ?? '';
$timein_flash_error   = $_SESSION['flash_timein_error']   ?? '';
unset($_SESSION['flash_timein_success'], $_SESSION['flash_timein_error']);

// Cashier must be properly clocked in to sell — see
// getCashierClockGate() in includes/config.php for the exact rules
// (Day Off / not Timed In yet / already Timed Out).
$clockGate = getCashierClockGate($hrmsPdo, $myEmployeeId);

// Day Off and already-Timed-Out are NOT fixable from this page, so
// those still get the full "Selling Disabled" block screen. "Not
// Timed In yet" IS fixable here, so that case falls through and lets
// the POS load normally behind a blocking Time In popup instead (see
// the popup markup + guard right before </body>).
if (!$clockGate['allowed'] && $clockGate['code'] !== 'not_timed_in') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selling Disabled — HCI GroceryOne</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --green-deep:   #1D4ED8;
            --green-mid:    #2563EB;
            --green-leaf:   #60A5FA;
            --green-pale:   #EFF6FF;
            --mustard:      #F59E0B;
            --mustard-pale: #FFFBEB;
            --red-alert:    #DC2626;
            --red-pale:     #FEF2F2;
            --cream:        #F8FAFC;
            --white:        #FFFFFF;
            --ink:          #0F172A;
            --ink-soft:     #475569;
            --line:         #E2E8F0;
            --mustard-text: #B45309;
            --font-display: 'Plus Jakarta Sans', 'Segoe UI', Arial, sans-serif;
            --font-body:    'Plus Jakarta Sans', 'Segoe UI', Arial, sans-serif;
            --radius-md:    12px;
            --radius-lg:    16px;
            --shadow-card:  0 1px 2px rgba(18,60,92,0.06), 0 6px 16px rgba(18,60,92,0.06);
        }
        html[data-theme="dark"] {
            --green-deep:   #60A5FA;
            --green-mid:    #3B82F6;
            --green-leaf:   #93C5FD;
            --green-pale:   #172554;
            --mustard:      #FBBF24;
            --mustard-pale: #422006;
            --red-alert:    #F87171;
            --red-pale:     #450A0A;
            --cream:        #0F172A;
            --white:        #1E293B;
            --ink:          #F8FAFC;
            --ink-soft:     #CBD5E1;
            --line:         #334155;
            --mustard-text: #FDE68A;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font-body); color: var(--ink); background: var(--cream); display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px; }
        .gate-card {
            max-width: 460px; width:100%; background:var(--white); border:1.5px solid var(--line);
            border-radius: var(--radius-lg); box-shadow: var(--shadow-card); padding: 32px 28px; text-align:center;
        }
        .gate-icon {
            width:56px; height:56px; border-radius:50%; background:var(--mustard-pale); color:var(--mustard-text);
            display:flex; align-items:center; justify-content:center; font-size:26px; margin:0 auto 16px;
        }
        .gate-card h1 { font-family:var(--font-display); font-size:20px; color:var(--ink); margin:0 0 10px; }
        .gate-card p { color:var(--ink-soft); font-size:14.5px; line-height:1.5; margin:0 0 22px; }
        .gate-actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
        .gate-btn {
            display:inline-block; padding:10px 20px; border-radius:10px; font-weight:600; font-size:14px;
            text-decoration:none; border:1.5px solid var(--line); color:var(--ink);
        }
        .gate-btn.primary { background:var(--green-deep); color: #FFFFFF; border-color:var(--green-deep); }`r`n    </style>
    </head>
    <body>
        <div class="gate-card">
            <div class="gate-icon">&#9888;</div>
            <h1>Selling Not Available</h1>
            <p><?= clean($clockGate['reason']) ?></p>
            <div class="gate-actions">
                <a class="gate-btn primary" href="my_profile.php">Go to My Profile</a>
                <a class="gate-btn" href="logout.php">Logout</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Flash messages/receipt from a just-completed POST (see the
// Post/Redirect/Get block at the end of the POST handler below).
// Reading them here — instead of keeping the values from the POST
// request itself — is what stops "add to cart" (and checkout, void,
// etc.) from repeating when the cashier reloads the page: a reload
// is now a plain GET, not a resubmission of the old form data.
$success_message = $_SESSION['flash_success'] ?? '';
$error_message   = $_SESSION['flash_error']   ?? '';
$receipt         = $_SESSION['flash_receipt'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['flash_receipt']);

// If a manual (percent/fixed) discount is awaiting Finance approval,
// check on every load whether it's been decided yet — Finance approves
// or rejects it from finance.php, independently of this page.
if (!empty($_SESSION['discount']['request_id']) && ($_SESSION['discount']['status'] ?? '') === 'pending_finance') {
    $stmt = $pdo->prepare("SELECT status, reject_reason FROM discount_requests WHERE request_id = ?");
    $stmt->execute([$_SESSION['discount']['request_id']]);
    $discReq = $stmt->fetch();

    if (!$discReq || $discReq['status'] === 'cancelled') {
        unset($_SESSION['discount']);
    } elseif ($discReq['status'] === 'approved') {
        $_SESSION['discount']['status'] = 'approved';
        $success_message = ($_SESSION['discount']['label'] ?? 'Discount') . " was approved by Finance and is now applied.";
    } elseif ($discReq['status'] === 'rejected') {
        $error_message = ($_SESSION['discount']['label'] ?? 'The discount') . " request was rejected by Finance"
            . (!empty($discReq['reject_reason']) ? ' — ' . $discReq['reject_reason'] : '') . ".";
        unset($_SESSION['discount']);
    }
}

function computeDiscount(float $total): float {
    if (empty($_SESSION['discount'])) return 0.0;
    $d = $_SESSION['discount'];
    // Manual percent/fixed discounts sit at 'pending_finance' until
    // Finance approves the request — no discount is actually taken off
    // the total until then. Senior/PWD (and already-approved manual
    // discounts) carry status 'approved' and apply as before.
    if (($d['status'] ?? 'approved') !== 'approved') return 0.0;
    if ($d['mode'] === 'percent') {
        return round($total * ($d['value'] / 100), 2);
    } elseif ($d['mode'] === 'fixed') {
        return min((float)$d['value'], $total);
    }
    return 0.0;
}

$store = [];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM store_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $store = $rows;
} catch (Exception $e) {}

$store_name     = $store['store_name']     ?? 'HCI GroceryOne';
$store_address  = $store['store_address']  ?? '';
$store_phone    = $store['store_phone']    ?? '';
$store_logo     = $store['store_logo']     ?? '';
$receipt_footer = $store['receipt_footer'] ?? 'Thank you for shopping with us!';


if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    requireCSRF();
    $action = $_POST['action'];

    if (!$clockGate['allowed']) {
        // Backend enforcement to match the Time In popup on the
        // frontend — no cart/checkout action can go through while the
        // cashier isn't properly clocked in, no matter how the POST
        // was triggered.
        $error_message = clean($clockGate['reason']);

    } elseif ($action == 'add_to_cart') {
        $product_id = $_POST['product_id'];
        $quantity   = max(1, (int) $_POST['quantity']);

        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        if (!$product || (int)$product['is_active'] === 0) {
            $error_message = "Product not found.";
        } else {
            // Hindi lang basta products.stock (na total pa rin kahit
            // may laman pang expired na batch) — ang sellable stock
            // (non-expired na lang) ang dapat sundin, kaya kahit may
            // "stock" pa sa DB pero galing lahat sa expired batch,
            // hindi na ito puedeng ma-add to cart.
            $sellable = getSellableStock($pdo, $product_id);

            if ($sellable == 0) {
                $error_message = ((int) $product['stock'] > 0)
                    ? clean($product['product_name']) . " is expired — not for sale."
                    : clean($product['product_name']) . " is out of stock.";
            } else {
                $existing_qty = isset($_SESSION['cart'][$product_id]) ? $_SESSION['cart'][$product_id]['quantity'] : 0;
                $total_qty    = $existing_qty + $quantity;

                if ($total_qty > $sellable) {
                    $error_message = "Not enough non-expired stock. Only " . $sellable . " pcs available for sale.";
                } else {
                    $_SESSION['cart'][$product_id] = [
                        'product_name' => $product['product_name'],
                        'price'        => getDiscountedPrice($product)['final_price'],
                        'quantity'     => $total_qty,
                        'image'        => $product['image'],
                    ];
                    $success_message = clean($product['product_name']) . " added to cart.";
                }
            }
        }
    }

    elseif ($action == 'scan_barcode') {
        // Barcode scanner support (Item 5 — Phase 2 POS audit). A
        // keyboard-wedge scanner just types the code + Enter, so the
        // scanner JS further down submits this the same way any other
        // hidden-form action here submits — no separate AJAX endpoint
        // needed. Mirrors add_to_cart's stock/sellable checks exactly,
        // just looked up by barcode instead of product_id, and always
        // adds 1 pc per scan.
        $scanned = trim($_POST['barcode'] ?? '');

        if ($scanned === '') {
            $error_message = "No barcode scanned.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE barcode = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$scanned]);
            $product = $stmt->fetch();

            if (!$product) {
                $error_message = "Barcode not recognized: " . clean($scanned);
            } else {
                $product_id = $product['id'];
                $sellable   = getSellableStock($pdo, $product_id);

                if ($sellable == 0) {
                    $error_message = ((int) $product['stock'] > 0)
                        ? clean($product['product_name']) . " is expired — not for sale."
                        : clean($product['product_name']) . " is out of stock.";
                } else {
                    $existing_qty = isset($_SESSION['cart'][$product_id]) ? $_SESSION['cart'][$product_id]['quantity'] : 0;
                    $total_qty    = $existing_qty + 1;

                    if ($total_qty > $sellable) {
                        $error_message = "Not enough non-expired stock. Only " . $sellable . " pcs available for sale.";
                    } else {
                        $_SESSION['cart'][$product_id] = [
                            'product_name' => $product['product_name'],
                            'price'        => getDiscountedPrice($product)['final_price'],
                            'quantity'     => $total_qty,
                            'image'        => $product['image'],
                        ];
                        $success_message = clean($product['product_name']) . " scanned and added to cart.";
                    }
                }
            }
        }
    }

    elseif ($action == 'update_qty') {
        $product_id = $_POST['product_id'];
        $quantity   = (int) $_POST['quantity'];

        if (isset($_SESSION['cart'][$product_id])) {
            if ($quantity <= 0) {
                unset($_SESSION['cart'][$product_id]);
                $success_message = "Item removed from cart.";
            } else {
                $stmt = $pdo->prepare("SELECT stock, product_name FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                $sellable = getSellableStock($pdo, $product_id);
                if ($quantity > $sellable) {
                    $error_message = "Not enough non-expired stock. Only " . $sellable . " pcs available for sale.";
                } else {
                    $_SESSION['cart'][$product_id]['quantity'] = $quantity;
                }
            }
        }
    }

    elseif ($action == 'remove_item') {
        $product_id = $_POST['product_id'];
        unset($_SESSION['cart'][$product_id]);
        $success_message = "Item removed.";
    }

    elseif ($action == 'void') {
        if (!empty($_SESSION['discount']['request_id']) && ($_SESSION['discount']['status'] ?? '') === 'pending_finance') {
            $pdo->prepare("UPDATE discount_requests SET status = 'cancelled' WHERE request_id = ? AND status = 'pending'")
                ->execute([$_SESSION['discount']['request_id']]);
        }
        $_SESSION['cart'] = [];
        unset($_SESSION['discount']);
        $success_message = "Transaction voided.";
    }

    elseif ($action == 'apply_discount') {
        $discount_type  = $_POST['discount_type'] ?? 'none';
        $discount_value = (float) ($_POST['discount_value'] ?? 0);

        if ($discount_type === 'none') {
            // If a manual discount request is still pending, withdraw it
            // instead of leaving an orphaned row for Finance to review.
            if (!empty($_SESSION['discount']['request_id']) && ($_SESSION['discount']['status'] ?? '') === 'pending_finance') {
                $pdo->prepare("UPDATE discount_requests SET status = 'cancelled' WHERE request_id = ? AND status = 'pending'")
                    ->execute([$_SESSION['discount']['request_id']]);
            }
            unset($_SESSION['discount']);
            $success_message = "Discount removed.";
        } elseif ($discount_type === 'senior' || $discount_type === 'pwd') {
            // RA 9994 / RA 10754 + BIR RR 7-2010 — an OSCA/PWD ID number
            // must be captured for this discount to be valid. Cardholder
            // name is optional (nice-to-have for the receipt/records).
            // This one is legally mandated and ID-verified on the spot,
            // so — unlike the manual percent/fixed discounts below — it
            // does NOT need a separate Finance approval step.
            $id_number         = preg_replace('/[^0-9-]/', '', trim($_POST['id_number'] ?? ''));
            $id_number         = substr($id_number, 0, 20);
            $holder_name       = trim($_POST['cardholder_name'] ?? '');
            $supervisor_user   = trim($_POST['supervisor_username'] ?? '');
            $supervisor_pass   = (string) ($_POST['supervisor_password'] ?? '');
            $label             = $discount_type === 'senior' ? 'Senior Citizen (20%)' : 'PWD (20%)';

            if ($id_number === '') {
                $error_message = "OSCA/PWD ID Number is required for this discount.";
            } elseif ($supervisor_user === '' || $supervisor_pass === '') {
                // Every use of this discount needs a Supervisor (or Admin)
                // to enter their own username/password right here on the
                // POS — it's never optional and never skipped.
                $error_message = "A Supervisor's username and password are required to approve this discount.";
            } else {
                $approver = verifyCashierSupervisorDiscountApproval($hrmsPdo, $supervisor_user, $supervisor_pass);

                if (!$approver) {
                    $error_message = "That Supervisor username/password could not be verified. Please check and try again.";
                } else {
                    $_SESSION['discount'] = [
                        'type'             => $discount_type,
                        'label'            => $label,
                        'mode'             => 'percent',
                        'value'            => 20,
                        'id_number'        => $id_number,
                        'holder_name'      => $holder_name,
                        'status'           => 'approved',
                        'approved_by_name' => $approver['full_name'],
                    ];
                    $success_message = ($discount_type === 'senior' ? 'Senior Citizen' : 'PWD') . " discount applied — approved by " . $approver['full_name'] . ".";
                }
            }
        } elseif ($discount_type === 'percent' || $discount_type === 'fixed') {
            // Manual, cashier-discretionary discount — has to clear
            // Finance before it actually comes off the total. We store
            // the request and put the session discount in a
            // 'pending_finance' holding state; computeDiscount() won't
            // apply it until finance.php flips it to 'approved'.
            if (!empty($_SESSION['discount']) && ($_SESSION['discount']['status'] ?? '') === 'pending_finance') {
                $error_message = "You already have a discount request awaiting Finance approval. Please wait for a decision, or cancel it first.";
            } else {
                $v = $discount_type === 'percent' ? max(0, min(100, $discount_value)) : max(0, $discount_value);

                if ($v <= 0) {
                    $error_message = $discount_type === 'percent' ? "Please enter a valid percentage." : "Please enter a valid amount.";
                } else {
                    $label = $discount_type === 'percent' ? ($v . '% Discount') : ('Fixed Discount (₱' . number_format($v, 2) . ')');
                    $reqSubtotal = 0;
                    foreach ($_SESSION['cart'] as $item) {
                        $reqSubtotal += $item['price'] * $item['quantity'];
                    }
                    $computedAmount = computeDiscountAmountFor($discount_type, $v, $reqSubtotal);

                    $stmt = $pdo->prepare("
                        INSERT INTO discount_requests (cashier_id, discount_type, discount_value, subtotal_at_request, computed_discount_amount, status)
                        VALUES (?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([$_SESSION['user_id'], $discount_type, $v, $reqSubtotal, $computedAmount]);
                    $request_id = $pdo->lastInsertId();

                    $_SESSION['discount'] = [
                        'type'       => $discount_type,
                        'label'      => $label,
                        'mode'       => $discount_type,
                        'value'      => $v,
                        'status'     => 'pending_finance',
                        'request_id' => $request_id,
                    ];
                    $success_message = "Discount request sent to Finance for approval — " . $label . " will apply once approved.";
                }
            }
        }
    }

    elseif ($action == 'cancel_discount_request') {
        if (!empty($_SESSION['discount']['request_id']) && ($_SESSION['discount']['status'] ?? '') === 'pending_finance') {
            $pdo->prepare("UPDATE discount_requests SET status = 'cancelled' WHERE request_id = ? AND status = 'pending'")
                ->execute([$_SESSION['discount']['request_id']]);
        }
        unset($_SESSION['discount']);
        $success_message = "Discount request cancelled.";
    }

    elseif ($action == 'checkout') {
        // "Submit once" guard: sinusuri (at agad na kino-consume) ang
        // idempotency_token bago pa man i-check ang cart. Kapag na-double
        // click nang mabilis ang Checkout button (o dahan-dahan ang
        // koneksyon at na-resend ang parehong POST), ang unang request
        // lang ang may matagumpay na token — makikitang "wala/expired na"
        // ang token para sa sunod pang request, kahit pareho ang laman
        // ng cart at payment na dala nito.
        if (!verifyAndConsumeIdempotencyToken('checkout', $_POST['idempotency_token'] ?? '')) {
            $error_message = "This checkout was already submitted (or the page is stale). Please review the transaction history before retrying.";
        } elseif (empty($_SESSION['cart'])) {
            $error_message = "Your cart is empty.";
        } elseif (($_SESSION['discount']['status'] ?? '') === 'pending_finance') {
            $error_message = "This discount is still awaiting Finance approval. Please wait for a decision, or cancel it before checking out.";
        } else {
            $payment         = (float) $_POST['payment'];
            $subtotal_amount = 0;
            foreach ($_SESSION['cart'] as $product_id => &$item) {
                // Re-read price and active status at checkout; session cart
                // values may be stale after a product or discount changes.
                $priceStmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
                $priceStmt->execute([$product_id]);
                $currentProduct = $priceStmt->fetch();
                if (!$currentProduct) {
                    $error_message = "A product in your cart is no longer available.";
                    break;
                }
                $item['product_name'] = $currentProduct['product_name'];
                $item['price'] = getDiscountedPrice($currentProduct)['final_price'];
                $item['image'] = $currentProduct['image'];
                $subtotal_amount += $item['price'] * $item['quantity'];
            }
            unset($item);

            if ($error_message !== '') {
                $discount_amount = 0;
            } else {
                $discount_amount = computeDiscount($subtotal_amount);
            }
            $total_amount    = $subtotal_amount - $discount_amount;
            $discount_label  = $_SESSION['discount']['label'] ?? null;
            $discount_id_number   = $_SESSION['discount']['id_number']   ?? null;
            $discount_holder_name = $_SESSION['discount']['holder_name'] ?? null;
            $discount_approved_by = $_SESSION['discount']['approved_by_name'] ?? null;

            $max_payment = maxAllowedPayment($total_amount);

            if ($error_message !== '') {
                // The cart refresh above already reported the failure.
            } elseif ($payment < $total_amount) {
                $error_message = "Insufficient payment. Short by " . peso($total_amount - $payment) . ".";
            } elseif ($payment > $max_payment) {
                $error_message = "Amount received is too high for this total — the most that should be entered is " .
                    peso($max_payment) . ". Please ask the customer for a smaller bill, or return the excess before checkout.";
            } else {
                $change_amount = $payment - $total_amount;

                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("INSERT INTO sales (cashier_id, total_amount, discount_amount, discount_id_number, discount_holder_name, discount_approved_by_name, payment, change_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed')");
                    $stmt->execute([$_SESSION['user_id'], $total_amount, $discount_amount, $discount_id_number, $discount_holder_name, $discount_approved_by, $payment, $change_amount]);
                    $sale_id = $pdo->lastInsertId();

                    foreach ($_SESSION['cart'] as $product_id => $item) {
                        // Keep the pricing columns in the locked row. The
                        // checkout refresh above stores the current
                        // discounted price in the cart, and the final
                        // comparison must use the same discount data.
                        $stmt = $pdo->prepare("SELECT stock, product_name, price, discount_type, discount_value, is_active FROM products WHERE id = ? FOR UPDATE");
                        $stmt->execute([$product_id]);
                        $locked = $stmt->fetch();

                        if (!$locked) throw new Exception("A product in your cart was not found.");
                        if ((int)$locked['is_active'] === 0) throw new Exception($locked['product_name'] . " is no longer available.");
                        if ($locked['stock'] < $item['quantity']) throw new Exception($locked['product_name'] . " only has " . $locked['stock'] . " pcs left.");
                        $lockedPrice = getDiscountedPrice($locked)['final_price'];
                        if (abs((float) $lockedPrice - (float) $item['price']) >= 0.01) {
                            throw new Exception($locked['product_name'] . " price changed. Please review the cart and try again.");
                        }

                        // Huling check bago talaga ibawas ang stock —
                        // baka na-expire na yung batch pinagmulan ng
                        // stock na ito habang naka-cart pa (hal.
                        // natapos na ang expiry date habang naghihintay
                        // sa checkout).
                        $sellableAtCheckout = getSellableStock($pdo, $product_id);
                        if ($sellableAtCheckout < $item['quantity']) {
                            throw new Exception($locked['product_name'] . " is expired / only has " . $sellableAtCheckout . " non-expired pcs left for sale.");
                        }

                        $subtotal = $item['price'] * $item['quantity'];

                        $pdo->prepare("INSERT INTO sales_details (sale_id, product_id, quantity, subtotal) VALUES (?, ?, ?, ?)")
                            ->execute([$sale_id, $product_id, $item['quantity'], $subtotal]);
                        $sales_detail_id = $pdo->lastInsertId();

                        $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")
                            ->execute([$item['quantity'], $product_id]);

                        $after_stock = $locked['stock'] - $item['quantity'];

                        $pdo->prepare("INSERT INTO stock_logs (product_id, sale_id, change_type, quantity_change, previous_stock, new_stock, reason, user_id) VALUES (?, ?, 'sale', ?, ?, ?, ?, ?)")
                            ->execute([$product_id, $sale_id, -$item['quantity'], $locked['stock'], $after_stock, 'Sale #' . $sale_id, $_SESSION['user_id']]);

                        // FEFO: sa batch na pinaka-malapit mag-expire muna
                        // kukunin ang stock. Ni-re-record kung saang
                        // batch(es) galing ito para tamang-tama ang
                        // maibabalik na expiry kapag na-void/na-refund.
                        $consumed = deductStockFEFO($pdo, $product_id, $item['quantity']);
                        foreach ($consumed as $c) {
                            $pdo->prepare("INSERT INTO sale_batch_consumption (sales_detail_id, batch_id, quantity) VALUES (?, ?, ?)")
                                ->execute([$sales_detail_id, $c['batch_id'], $c['quantity']]);
                        }
                    }

                    $pdo->commit();

                    $receipt = [
                        'sale_id'         => $sale_id,
                        'items'           => $_SESSION['cart'],
                        'subtotal'        => $subtotal_amount,
                        'discount_amount' => $discount_amount,
                        'discount_label'  => $discount_label,
                        'discount_approved_by' => $discount_approved_by,
                        'total_amount'    => $total_amount,
                        'payment'         => $payment,
                        'change_amount'   => $change_amount,
                        'cashier_name'    => $_SESSION['full_name'],
                        'date'            => date('F d, Y h:i A'),
                    ];

                    checkLowStock($pdo);
                    $_SESSION['cart'] = [];
                    unset($_SESSION['discount']);
                    $success_message = "Transaction complete!";

                } catch (PDOException $e) {
                    $pdo->rollBack();
                    error_log("Checkout error: " . $e->getMessage());
                    $error_message = "Transaction failed. Please try again.";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error_message = clean($e->getMessage());
                }
            }
        }
    }

    elseif ($action == 'void') {
        $sale_id = (int) $_POST['sale_id'];

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
            $stmt->execute([$sale_id]);
            $sale = $stmt->fetch();

            if (!$sale) throw new Exception("Transaction not found.");
            if ($sale['status'] !== 'completed') throw new Exception("Only completed transactions can be voided.");

            $is_owner    = $sale['cashier_id'] == $_SESSION['user_id'];
            $is_same_day = date('Y-m-d', strtotime($sale['transaction_date'])) == date('Y-m-d');

            if (!$is_owner || !$is_same_day) {
                throw new Exception("You can only void your own transactions from today.");
            }

            $stmt = $pdo->prepare("SELECT sd.id AS sales_detail_id, sd.product_id, sd.quantity, p.product_name, p.stock FROM sales_details sd JOIN products p ON p.id = sd.product_id WHERE sd.sale_id = ?");
            $stmt->execute([$sale_id]);
            $sold_items = $stmt->fetchAll();

            foreach ($sold_items as $item) {
                $new_stock = $item['stock'] + $item['quantity'];
                $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")->execute([$item['quantity'], $item['product_id']]);
                $pdo->prepare("INSERT INTO stock_logs (product_id, sale_id, change_type, quantity_change, previous_stock, new_stock, reason, user_id) VALUES (?, ?, 'void', ?, ?, ?, ?, ?)")
                    ->execute([$item['product_id'], $sale_id, $item['quantity'], $item['stock'], $new_stock, 'Voided Sale #' . $sale_id, $_SESSION['user_id']]);

                // Ibalik ang stock sa mismong batch (kasama ang tamang
                // expiry) kung saan ito talaga galing.
                $c_stmt = $pdo->prepare("SELECT batch_id, quantity FROM sale_batch_consumption WHERE sales_detail_id = ?");
                $c_stmt->execute([$item['sales_detail_id']]);
                restoreStockToBatches($pdo, $c_stmt->fetchAll());
            }

            $pdo->prepare("UPDATE sales SET status = 'voided' WHERE id = ?")->execute([$sale_id]);
            $pdo->commit();

            $success_message = "Transaction #" . $sale_id . " voided. Stock restored.";

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Void error: " . $e->getMessage());
            $error_message = "Could not void transaction. Please try again.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = clean($e->getMessage());
        }
    }

    elseif ($action == 'refund') {
        $sale_id             = (int) $_POST['sale_id'];
        $refund_reason       = trim($_POST['refund_reason'] ?? '');
        // A refund moves money back out and puts stock back in — same
        // risk profile as the Senior/PWD discount, so it gets the same
        // on-the-spot Supervisor/Admin approval instead of being
        // something any cashier can do to any sale unsupervised.
        $supervisor_user     = trim($_POST['supervisor_username'] ?? '');
        $supervisor_pass     = (string) ($_POST['supervisor_password'] ?? '');

        if (empty($refund_reason)) {
            $error_message = "Please provide a reason for the refund.";
        } elseif ($supervisor_user === '' || $supervisor_pass === '') {
            $error_message = "A Supervisor's (or Admin's) username and password are required to approve this refund.";
        } else {
            $approver = verifyCashierSupervisorDiscountApproval($hrmsPdo, $supervisor_user, $supervisor_pass);

            if (!$approver) {
                $error_message = "That Supervisor/Admin username or password could not be verified. Please check and try again.";
            } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
                $stmt->execute([$sale_id]);
                $sale = $stmt->fetch();

                if (!$sale) throw new Exception("Transaction not found.");
                if ($sale['status'] !== 'completed') throw new Exception("Only completed transactions can be refunded.");

                $stmt = $pdo->prepare("SELECT sd.id AS sales_detail_id, sd.product_id, sd.quantity, p.product_name, p.stock FROM sales_details sd JOIN products p ON p.id = sd.product_id WHERE sd.sale_id = ?");
                $stmt->execute([$sale_id]);
                $sold_items = $stmt->fetchAll();

                if (empty($sold_items)) throw new Exception("No items found for this transaction.");

                
                $first_product_id = $sold_items[0]['product_id'];

                foreach ($sold_items as $item) {
                    $new_stock = $item['stock'] + $item['quantity'];
                    $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")->execute([$item['quantity'], $item['product_id']]);
                    $pdo->prepare("INSERT INTO stock_logs (product_id, sale_id, change_type, quantity_change, previous_stock, new_stock, reason, user_id) VALUES (?, ?, 'refund', ?, ?, ?, ?, ?)")
                        ->execute([$item['product_id'], $sale_id, $item['quantity'], $item['stock'], $new_stock, 'Refunded Sale #' . $sale_id . ': ' . $refund_reason, $_SESSION['user_id']]);

                    // Ibalik ang stock sa mismong batch kung saan ito galing.
                    $c_stmt = $pdo->prepare("SELECT batch_id, quantity FROM sale_batch_consumption WHERE sales_detail_id = ?");
                    $c_stmt->execute([$item['sales_detail_id']]);
                    restoreStockToBatches($pdo, $c_stmt->fetchAll());
                }

                $pdo->prepare("UPDATE sales SET status = 'refunded', refund_reason = ?, refunded_at = NOW(), refunded_by = ?, refund_approved_by_name = ? WHERE id = ?")
                    ->execute([$refund_reason, $_SESSION['user_id'], $approver['full_name'], $sale_id]);

                $notif_message = "Refund processed: Sale #" . $sale_id . " (" . peso($sale['total_amount']) . ") by " .
                                  $_SESSION['full_name'] . " — approved by " . $approver['full_name'] . ". Reason: " . $refund_reason;

                $pdo->prepare("INSERT INTO notifications (product_id, message) VALUES (?, ?)")
                    ->execute([$first_product_id, $notif_message]);

                $pdo->commit();
                $success_message = "Transaction #" . $sale_id . " refunded — approved by " . $approver['full_name'] . ". Stock restored.";

            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Refund error: " . $e->getMessage());
                $error_message = "Could not process refund. Please try again.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_message = clean($e->getMessage());
            }
            }
        }
    }

    elseif ($action == 'partial_refund') {
        $sale_id         = (int) $_POST['sale_id'];
        $refund_reason   = trim($_POST['refund_reason'] ?? '');
        $refund_qty      = $_POST['refund_qty'] ?? []; // [sales_detail_id => qty]
        // Same Supervisor/Admin approval requirement as a full refund —
        // see the 'refund' action above for why.
        $supervisor_user = trim($_POST['supervisor_username'] ?? '');
        $supervisor_pass = (string) ($_POST['supervisor_password'] ?? '');

        if (empty($refund_reason)) {
            $error_message = "Please provide a reason for the refund.";
        } elseif ($supervisor_user === '' || $supervisor_pass === '') {
            $error_message = "A Supervisor's (or Admin's) username and password are required to approve this refund.";
        } else {
            $approver = verifyCashierSupervisorDiscountApproval($hrmsPdo, $supervisor_user, $supervisor_pass);

            if (!$approver) {
                $error_message = "That Supervisor/Admin username or password could not be verified. Please check and try again.";
            } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
                $stmt->execute([$sale_id]);
                $sale = $stmt->fetch();

                if (!$sale) throw new Exception("Transaction not found.");
                if (!in_array($sale['status'], ['completed', 'partial_refunded'], true)) {
                    throw new Exception("Only completed (or already partially refunded) transactions can be refunded.");
                }

                $stmt = $pdo->prepare("SELECT sd.id AS sales_detail_id, sd.product_id, sd.quantity, sd.subtotal, p.product_name, p.stock FROM sales_details sd JOIN products p ON p.id = sd.product_id WHERE sd.sale_id = ?");
                $stmt->execute([$sale_id]);
                $sold_items = $stmt->fetchAll();

                if (empty($sold_items)) throw new Exception("No items found for this transaction.");

                $stmt = $pdo->prepare("SELECT sales_detail_id, SUM(quantity) AS refunded_qty FROM sale_refunds WHERE sale_id = ? GROUP BY sales_detail_id");
                $stmt->execute([$sale_id]);
                $already_refunded = [];
                foreach ($stmt->fetchAll() as $r) {
                    $already_refunded[$r['sales_detail_id']] = (int) $r['refunded_qty'];
                }

                $any_refunded    = false;
                $all_lines_done  = true;
                $first_product_id = $sold_items[0]['product_id'];

                foreach ($sold_items as $item) {
                    $detail_id       = $item['sales_detail_id'];
                    $prev_refunded   = $already_refunded[$detail_id] ?? 0;
                    $refundable_left = $item['quantity'] - $prev_refunded;

                    $qty = isset($refund_qty[$detail_id]) ? (int) $refund_qty[$detail_id] : 0;

                    if ($qty > 0) {
                        if ($qty > $refundable_left) {
                            throw new Exception("Refund quantity for \"" . $item['product_name'] . "\" exceeds the remaining refundable quantity.");
                        }

                        $unit_price    = $item['quantity'] > 0 ? ($item['subtotal'] / $item['quantity']) : 0;
                        $refund_amount = round($unit_price * $qty, 2);

                        $new_stock = $item['stock'] + $qty;
                        $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")->execute([$qty, $item['product_id']]);
                        $pdo->prepare("INSERT INTO stock_logs (product_id, sale_id, change_type, quantity_change, previous_stock, new_stock, reason, user_id) VALUES (?, ?, 'refund', ?, ?, ?, ?, ?)")
                            ->execute([$item['product_id'], $sale_id, $qty, $item['stock'], $new_stock, 'Partial refund Sale #' . $sale_id . ' (' . $item['product_name'] . '): ' . $refund_reason, $_SESSION['user_id']]);

                        // Ibalik lang sa batch(es) ang dami na talagang
                        // ire-refund, hindi lahat ng orihinal na binili.
                        $c_stmt = $pdo->prepare("SELECT batch_id, quantity FROM sale_batch_consumption WHERE sales_detail_id = ?");
                        $c_stmt->execute([$detail_id]);
                        restoreStockToBatchesPartial($pdo, $c_stmt->fetchAll(), $qty);

                        $pdo->prepare("INSERT INTO sale_refunds (sale_id, sales_detail_id, product_id, quantity, amount, reason, refunded_by, approved_by_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$sale_id, $detail_id, $item['product_id'], $qty, $refund_amount, $refund_reason, $_SESSION['user_id'], $approver['full_name']]);

                        $any_refunded = true;
                        $prev_refunded += $qty;
                    }

                    if ($prev_refunded < $item['quantity']) {
                        $all_lines_done = false;
                    }
                }

                if (!$any_refunded) {
                    throw new Exception("Select at least one item and quantity to refund.");
                }

                $new_status = $all_lines_done ? 'refunded' : 'partial_refunded';
                $pdo->prepare("UPDATE sales SET status = ?, refund_reason = ?, refunded_at = NOW(), refunded_by = ?, refund_approved_by_name = ? WHERE id = ?")
                    ->execute([$new_status, $refund_reason, $_SESSION['user_id'], $approver['full_name'], $sale_id]);

                $notif_message = ($all_lines_done ? "Refund processed" : "Partial refund processed") . ": Sale #" . $sale_id . " by " .
                                  $_SESSION['full_name'] . " — approved by " . $approver['full_name'] . ". Reason: " . $refund_reason;

                $pdo->prepare("INSERT INTO notifications (product_id, message) VALUES (?, ?)")
                    ->execute([$first_product_id, $notif_message]);

                $pdo->commit();
                $success_message = $all_lines_done
                    ? "Transaction #" . $sale_id . " fully refunded — approved by " . $approver['full_name'] . ". Stock restored."
                    : "Selected item(s) from Transaction #" . $sale_id . " refunded — approved by " . $approver['full_name'] . ". Stock restored.";

            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Partial refund error: " . $e->getMessage());
                $error_message = "Could not process refund. Please try again.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_message = clean($e->getMessage());
            }
            }
        }
    }

    // Post/Redirect/Get — stash the results in the session and redirect
    // to a plain GET. Without this, hitting reload/F5 after any action
    // (add to cart, checkout, void, refund...) re-sends the same POST
    // and repeats it, which was duplicating cart items on reload.
    $_SESSION['flash_success'] = $success_message;
    $_SESSION['flash_error']   = $error_message;
    if ($receipt) {
        $_SESSION['flash_receipt'] = $receipt;
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: pos.php' . ($qs !== '' ? ('?' . $qs) : ''));
    exit;
}



$search   = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');

$where  = ["p.stock > 0", "p.is_active = 1"];
$params = [];

if (!empty($search)) {
    $where[]  = "p.product_name LIKE ?";
    $params[] = '%' . $search . '%';
}
if (!empty($category)) {
    $where[]  = "p.category = ?";
    $params[] = $category;
}

$sql = "
    SELECT p.*,
        (SELECT COUNT(*) FROM product_batches pb WHERE pb.product_id = p.id) AS batch_count,
        (SELECT COALESCE(SUM(pb.quantity_remaining), 0) FROM product_batches pb
            WHERE pb.product_id = p.id AND pb.quantity_remaining > 0
              AND (pb.expiry_date IS NULL OR pb.expiry_date >= CURDATE())) AS sellable_qty
    FROM products p WHERE " . implode(' AND ', $where) . " ORDER BY p.product_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
foreach ($products as &$__p) {
    // Kung batch-tracked ang product (may laman ang product_batches),
    // ang sellable_qty (non-expired) ang gagamitin bilang totoong
    // maibebenta pang stock. Kung wala pang batch record (legacy),
    // ituring pa ring maibebenta ang buong products.stock.
    $__p['sellable_stock'] = ((int) $__p['batch_count'] > 0) ? (int) $__p['sellable_qty'] : (int) $__p['stock'];
}
unset($__p);

$categories = $pdo->query("SELECT * FROM categories ORDER BY category_name ASC")->fetchAll();

$stmt = $pdo->prepare("SELECT id, total_amount, transaction_date, status, refund_reason, refund_approved_by_name FROM sales WHERE cashier_id = ? AND DATE(transaction_date) = CURDATE() ORDER BY transaction_date DESC");
$stmt->execute([$_SESSION['user_id']]);
$todays_transactions = $stmt->fetchAll();

// Per-item lines (+ kung gaano na karami ang na-refund na sa bawat
// linya) — ginagamit ng "Partial Refund" item picker sa History tab.
$todays_txn_items = [];
if (!empty($todays_transactions)) {
    $txn_ids = array_column($todays_transactions, 'id');
    $placeholders = implode(',', array_fill(0, count($txn_ids), '?'));

    $stmt = $pdo->prepare("
        SELECT sd.id AS sales_detail_id, sd.sale_id, sd.product_id, sd.quantity, sd.subtotal, p.product_name,
               COALESCE((SELECT SUM(sr.quantity) FROM sale_refunds sr WHERE sr.sales_detail_id = sd.id), 0) AS refunded_qty
        FROM sales_details sd
        JOIN products p ON p.id = sd.product_id
        WHERE sd.sale_id IN ($placeholders)
        ORDER BY sd.id ASC
    ");
    $stmt->execute($txn_ids);
    foreach ($stmt->fetchAll() as $row) {
        $row['unit_price']     = $row['quantity'] > 0 ? $row['subtotal'] / $row['quantity'] : 0;
        $row['refundable_qty'] = $row['quantity'] - (int) $row['refunded_qty'];
        $todays_txn_items[$row['sale_id']][] = $row;
    }
}

$cart_subtotal = 0;
foreach ($_SESSION['cart'] as $item) {
    $cart_subtotal += $item['price'] * $item['quantity'];
}
$cart_discount   = computeDiscount($cart_subtotal);
$cart_total      = $cart_subtotal - $cart_discount;
$cart_count      = array_sum(array_column($_SESSION['cart'], 'quantity'));
$active_discount     = $_SESSION['discount'] ?? null;
$discount_is_pending = $active_discount && ($active_discount['status'] ?? '') === 'pending_finance';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS — <?= clean($store_name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --green-deep:   #1D4ED8;
    --green-mid:    #2563EB;
    --green-leaf:   #60A5FA;
    --green-pale:   #EFF6FF;
    --mustard:      #F59E0B;
    --mustard-pale: #FFFBEB;
    --red-alert:    #DC2626;
    --red-pale:     #FEF2F2;
    --cream:        #F8FAFC;
    --white:        #FFFFFF;
    --ink:          #0F172A;
    --ink-soft:     #475569;
    --line:         #E2E8F0;
    --mustard-text: #B45309;
    --font-display: 'Plus Jakarta Sans', 'Segoe UI', Arial, sans-serif;
    --font-body:    'Plus Jakarta Sans', 'Segoe UI', Arial, sans-serif;
    --sidebar-w:    248px;
    --sidebar-w-collapsed: 84px;
    --cart-w:       340px;
    --radius-md:    12px;
    --radius-lg:    16px;
    --shadow-card:  0 1px 2px rgba(18,60,92,0.06), 0 6px 16px rgba(18,60,92,0.06);
    /* Used for headings/values/icons that need to read as "brand deep"
       on light backgrounds, but must switch to a bright color in dark
       mode instead of staying dark (or the text disappears against
       the dark card backgrounds). Light mode: same as --green-deep. */
    --text-accent:  var(--green-deep);
}
html[data-theme="dark"] {
    --green-deep:   #60A5FA;
    --green-mid:    #3B82F6;
    --green-leaf:   #93C5FD;
    --green-pale:   #172554;
    --mustard:      #FBBF24;
    --mustard-pale: #422006;
    --red-alert:    #F87171;
    --red-pale:     #450A0A;
    --cream:        #0F172A;
    --white:        #1E293B;
    --ink:          #F8FAFC;
    --ink-soft:     #CBD5E1;
    --line:         #334155;
    --mustard-text: #FDE68A;
    /* Was missing — every other page (shift_report.php, style.css)
       darkens this in dark mode, but pos.php never did, so the
       sidebar-toggle button (and anything else using --shadow-card)
       kept the light-mode blue-tinted shadow instead of matching. */
    --shadow-card:  0 1px 2px rgba(0,0,0,0.25), 0 6px 16px rgba(0,0,0,0.25);
    --text-accent:  var(--green-leaf);
}
/* Safety net: table values (dates, amounts, plain cell text) must
   always read against the dark card background — force it here in
   case any page's own table/td rule doesn't correctly inherit --ink. */
html[data-theme="dark"] table td,
html[data-theme="dark"] .card td { color: var(--ink); }

html, body { height: 100%; font-family: var(--font-body); color: var(--ink); background: var(--cream); }
a { text-decoration: none; color: inherit; }
html, body { transition: background-color .18s ease, color .18s ease; }

.pos-shell {
    display: grid;
    grid-template-columns: var(--sidebar-w) 1fr var(--cart-w);
    grid-template-rows: 100vh;
    height: 100vh;
    overflow: hidden;
    transition: grid-template-columns 0.22s ease;
}

/* Collapsed state — same behaviour/labels as the shared sidebar
   (includes/sidebar.php) used on every other page, so POS no longer
   looks/feels like a separate app. */
.pos-shell.sidebar-collapsed {
    grid-template-columns: var(--sidebar-w-collapsed) minmax(0, 1fr) var(--cart-w) !important;
}
.pos-shell.sidebar-collapsed .sidebar {
    width: var(--sidebar-w-collapsed);
    min-width: var(--sidebar-w-collapsed);
    padding-left: 14px;
    padding-right: 14px;
}
.pos-shell.sidebar-collapsed .brand-text,
.pos-shell.sidebar-collapsed .nav-label,
.pos-shell.sidebar-collapsed .nav-section-label,
.pos-shell.sidebar-collapsed .sidebar-foot .foot-text,
.pos-shell.sidebar-collapsed .foot-logout-btn .nav-label,
.pos-shell.sidebar-collapsed .theme-toggle-btn .tt-label {
    display: none;
}
.pos-shell.sidebar-collapsed .sidebar-brand { justify-content: center; padding-left: 0; padding-right: 0; }
.pos-shell.sidebar-collapsed .nav-link,
.pos-shell.sidebar-collapsed .foot-logout-btn { justify-content: center; gap: 0; }
.pos-shell.sidebar-collapsed .sidebar-foot { justify-content: center; padding: 10px; }
.pos-shell.sidebar-collapsed .theme-toggle-btn { justify-content: center; }
.pos-shell.sidebar-collapsed .sidebar-brand { gap: 0; margin-bottom: 18px; }
.pos-shell.sidebar-collapsed .nav-link { width: 100%; padding-left: 8px; padding-right: 8px; }

.sidebar {
    /* Sidebar accent, matched to the homepage's (index.php) cyan/violet
       night palette. Scoped to .sidebar only so the rest of POS keeps
       its usual color. */
    --green-deep: #0a1024;
    --green-mid:  #6d7cff;
    --green-leaf: #55d6ff;
    --green-pale: rgba(85,214,255,.16);

    position: relative;
    background:
        radial-gradient(420px 260px at 100% 0%, rgba(85,214,255,.18), transparent 60%),
        radial-gradient(360px 320px at 0% 100%, rgba(176,108,255,.16), transparent 60%),
        var(--green-deep);
    color: #FFFFFF;
    display: flex;
    flex-direction: column;
    padding: 30px 24px;
    overflow-y: auto;
    overflow-x: hidden;
    z-index: 2;
    box-shadow: 2px 0 12px rgba(0,0,0,0.18);
    transition: width 0.22s ease, padding 0.22s ease, box-shadow 0.22s ease;
}
.sidebar {
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.sidebar::-webkit-scrollbar { display: none; }
html[data-theme="dark"] .sidebar {
    /* Matched to HRMS's dark-mode sidebar (hr_sidebar.php) and the
       shared style.css — same two-layer cyan + violet glow on the
       same near-black base, so the sidebar reads identically across
       every module (Cashier, Shift Report, HRMS, etc.) in dark mode. */
    background:
        radial-gradient(420px 260px at 100% 0%, rgba(85,214,255,.16), transparent 60%),
        radial-gradient(360px 320px at 0% 100%, rgba(176,108,255,.14), transparent 60%),
        #05070f;
}

/* Collapse toggle — little pill button docked on the sidebar edge,
   identical to the one every other page uses. */
.sidebar-toggle {
    /* Fixed (not absolute) so it's always anchored to the sidebar's
       actual edge — this button sits as a sibling of .sidebar, not a
       child, so "right: -13px" here used to resolve against the
       whole page instead of the sidebar box, leaving it floating in
       the wrong spot or clipped behind the cart panel. */
    position: fixed;
    top: 34px;
    left: calc(var(--sidebar-w) - 13px);
    width: 26px;
    height: 26px;
    border-radius: 50%;
    border: 1px solid var(--line);
    background: var(--white);
    color: var(--text-accent);
    box-shadow: var(--shadow-card);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 20;
    padding: 0;
    transition: left 0.22s ease, background 0.15s ease;
}
.pos-shell.sidebar-collapsed .sidebar-toggle { left: calc(var(--sidebar-w-collapsed) - 13px); }
.sidebar-toggle:hover { background: var(--green-pale); }
.sidebar-toggle svg { width: 13px; height: 13px; transition: transform 0.22s ease; }
.pos-shell.sidebar-collapsed .sidebar-toggle svg { transform: rotate(180deg); }

.theme-toggle-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 9px 12px;
    margin-top: 8px;
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 10px;
    background: rgba(255,255,255,.05);
    color: #FFFFFF;
    font-family: var(--font-body);
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
}
.theme-toggle-btn:hover { background: rgba(255,255,255,.1); }
.theme-toggle-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
.theme-toggle-btn .tt-icon-dark { display: none; }
html[data-theme="dark"] .theme-toggle-btn .tt-icon-light { display: none; }
html[data-theme="dark"] .theme-toggle-btn .tt-icon-dark { display: block; }
.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 0 4px 26px;
    margin-bottom: 22px;
    border-bottom: 1px solid rgba(255,255,255,0.14);
}
.brand-mark {
    width: 46px; height: 46px;
    background: var(--white);
    border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
    box-shadow: 0 4px 10px rgba(0,0,0,.14);
}
.brand-mark img { width: 100%; height: 100%; object-fit: cover; border-radius: 13px; }
.brand-mark svg { width: 30px; height: 30px; }
.brand-text { display: flex; flex-direction: column; line-height: 1.35; }
.brand-name    { font-family: var(--font-display); font-weight: 700; font-size: 18px; }
.brand-tagline { font-size: 11px; color: var(--green-leaf); font-weight: 600; letter-spacing: .02em; }

.nav-section-label {
    font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
    color: rgba(255,255,255,.42); padding: 0 14px; margin: 4px 0 10px;
}
.nav-link {
    display: flex; align-items: center; gap: 13px;
    padding: 13px 15px;
    border-radius: 12px;
    font-size: 14.5px; font-weight: 600;
    color: rgba(255,255,255,0.82);
    margin-bottom: 6px;
    transition: background .15s, color .15s, transform .15s;
    position: relative;
}
.nav-link:hover     { background: rgba(255,255,255,0.1); color: #FFFFFF; }
.nav-link.is-active { background: var(--green-leaf); color: var(--green-deep); box-shadow: 0 4px 12px rgba(0,0,0,.16); }
.nav-icon-box {
    width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,.08);
}
.nav-link.is-active .nav-icon-box { background: rgba(255,255,255,.35); }
.nav-icon { width: 17px; height: 17px; flex-shrink: 0; }

.sidebar-account-group {
    display: flex; flex-direction: column; gap: 6px;
    margin-top: auto;
}
.foot-logout-btn {
    display: flex; align-items: center; gap: 13px;
    padding: 13px 15px; border-radius: 12px;
    background: rgba(255,255,255,.06);
    color: rgba(255,255,255,0.82); font-weight: 700; font-size: 14.5px;
    transition: background .15s ease, color .15s ease;
}
.foot-logout-btn:hover { background: rgba(200,71,45,0.18); color: #ffb3a3; }
.foot-logout-btn:hover .nav-icon-box { background: rgba(200,71,45,0.28); }
.foot-logout-btn .nav-icon { width: 17px; height: 17px; }

.sidebar-foot {
    padding: 14px;
    border-radius: 14px;
    background: rgba(255,255,255,.06);
    display: flex; align-items: center; gap: 12px;
}
.sidebar-foot .foot-avatar {
    width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(150deg, var(--green-leaf), #7c6cff);
    color: var(--text-accent);
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-display); font-weight: 700; font-size: 15px;
    overflow: hidden;
}
.sidebar-foot .foot-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.sidebar-foot .foot-text { display: flex; flex-direction: column; line-height: 1.3; min-width: 0; }
.sidebar-foot .foot-name { font-size: 13.5px; font-weight: 700; color: #FFFFFF; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sidebar-foot .foot-role { font-size: 11.5px; color: rgba(255,255,255,0.6); font-weight: 600; }

.pos-main {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--cream);
}
.pos-topbar {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 32px;
    background: var(--white);
    border-bottom: 1px solid var(--line);
    flex-shrink: 0;
}
.topbar-icon {
    width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
    background: var(--green-pale); color: var(--text-accent);
    display: flex; align-items: center; justify-content: center;
}
.topbar-icon svg { width: 20px; height: 20px; }
.pos-topbar h1 {
    font-family: var(--font-display);
    font-size: 20px; font-weight: 700;
    color: var(--text-accent);
    margin-right: auto;
}
.cashier-name { font-size: 13px; color: var(--ink-soft); font-weight: 600; }

.pos-filters {
    display: flex;
    gap: 10px;
    padding: 14px 20px;
    background: var(--white);
    border-bottom: 1px solid var(--line);
    flex-shrink: 0;
    flex-wrap: wrap;
}
.search-input {
    flex: 1; min-width: 160px;
    padding: 9px 14px;
    border: 1.5px solid var(--line);
    border-radius: 9px;
    font-family: var(--font-body);
    font-size: 13.5px;
    background: var(--cream); color: var(--ink);
    transition: border-color .15s;
}
.search-input:focus { outline: none; border-color: var(--green-mid); background: var(--white); }

.cat-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
.cat-tab {
    padding: 7px 13px;
    border-radius: 20px;
    font-size: 12.5px; font-weight: 600;
    border: 1.5px solid var(--line);
    background: var(--white); color: var(--ink-soft);
    cursor: pointer;
    transition: all .15s;
    white-space: nowrap;
}
.cat-tab:hover { border-color: var(--green-mid); color: var(--green-mid); }
.cat-tab.active { background: var(--green-deep); color: #FFFFFF; border-color: var(--green-deep); }

.pos-alert {
    margin: 12px 20px 0;
    padding: 10px 14px;
    border-radius: 10px;
    font-size: 13.5px; font-weight: 500;
    flex-shrink: 0;
}
.pos-alert.success { background: #e6f4ea; color: #1a7a3b; }
html[data-theme="dark"] .pos-alert.success { background: var(--green-pale); color: var(--green-leaf); }
.pos-alert.error   { background: var(--red-pale); color: var(--red-alert); }

.product-grid-wrap {
    flex: 1;
    overflow-y: auto;
    padding: 16px 20px 24px;
}
.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 14px;
}

.product-card {
    background: var(--white);
    border: 1.5px solid var(--line);
    border-radius: 14px;
    overflow: hidden;
    cursor: pointer;
    transition: border-color .15s, box-shadow .15s, transform .15s;
    position: relative;
}
.product-card:hover {
    border-color: var(--green-mid);
    box-shadow: 0 6px 18px rgba(31,92,63,0.12);
    transform: translateY(-2px);
}
.product-card.is-expired {
    cursor: not-allowed;
    opacity: .65;
    border-color: var(--red-alert);
}
.product-card.is-expired:hover {
    border-color: var(--red-alert);
    box-shadow: none;
    transform: none;
}
.product-expired-badge {
    position: absolute; top: 6px; left: 6px;
    background: var(--red-alert); color: #fff;
    font-size: 10px; font-weight: 700;
    padding: 2px 7px; border-radius: 20px;
    z-index: 2;
}
.product-img-wrap {
    width: 100%; aspect-ratio: 1;
    background: var(--green-pale);
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
    position: relative;
}
.product-img-wrap img { width: 100%; height: 100%; object-fit: cover; }
.product-img-placeholder { font-size: 36px; opacity: .5; }
.product-info { padding: 10px 11px; }
.product-name {
    font-size: 13px; font-weight: 600; color: var(--ink);
    margin-bottom: 4px; line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.product-price { font-size: 14px; font-weight: 700; color: var(--green-mid); }
.product-stock { font-size: 11px; font-weight: 600; color: var(--ink-soft); margin-top: 3px; }
.product-stock.low { color: var(--mustard); }
.product-stock.expired { color: var(--red-alert); }
.empty-products { grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: var(--ink-soft); font-size: 14px; }

.pos-cart {
    background: var(--white);
    border-left: 1px solid var(--line);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.cart-tabs {
    display: flex;
    border-bottom: 1px solid var(--line);
    flex-shrink: 0;
}
.cart-tab-btn {
    flex: 1;
    padding: 14px 10px;
    text-align: center;
    background: none; border: none;
    font-family: var(--font-body);
    font-size: 13.5px; font-weight: 700;
    color: var(--ink-soft);
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: color .15s, border-color .15s;
    display: flex; align-items: center; justify-content: center;
    gap: 7px;
}
.cart-tab-btn:hover { color: var(--green-mid); }
.cart-tab-btn.active { color: var(--text-accent); border-bottom-color: var(--green-mid); }
.tab-count {
    background: var(--green-pale); color: var(--text-accent);
    font-size: 11px; font-weight: 700;
    padding: 1px 7px; border-radius: 10px;
}
.cart-tab-btn.active .tab-count { background: var(--green-mid); color: #FFFFFF; }

.tab-panel { display: none; flex: 1; flex-direction: column; overflow: hidden; min-height: 0; }
.tab-panel.active { display: flex; }

.cart-header {
    padding: 14px 18px 10px;
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: space-between;
}
.cart-header h2 {
    font-family: var(--font-display);
    font-size: 15px; font-weight: 700;
    color: var(--text-accent);
}
.clear-btn {
    font-size: 12px; font-weight: 600;
    color: var(--red-alert);
    background: none; border: none;
    cursor: pointer; font-family: inherit; padding: 0;
}
.clear-btn:hover { text-decoration: underline; }

.cart-items { flex: 1; overflow-y: auto; padding: 4px 0; }
.cart-empty {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    height: 100%;
    color: var(--ink-soft); font-size: 14px; gap: 10px;
}
.cart-empty svg { width: 40px; height: 40px; opacity: .3; }

.cart-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 18px;
    border-bottom: 1px solid var(--line);
}
.cart-item:last-child { border-bottom: none; }

.cart-item-img {
    width: 40px; height: 40px;
    border-radius: 8px; object-fit: cover;
    background: var(--green-pale); flex-shrink: 0;
}
.cart-item-img-placeholder {
    width: 40px; height: 40px;
    border-radius: 8px; background: var(--green-pale);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.cart-item-info { flex: 1; min-width: 0; }
.cart-item-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cart-item-price { font-size: 12px; color: var(--ink-soft); margin-top: 2px; }

.qty-control { display: flex; align-items: center; gap: 6px; }
.qty-input {
    width: 44px;
    text-align: center;
    border: 1.5px solid var(--line);
    border-radius: 7px;
    font-family: var(--font-body);
    font-size: 13px; font-weight: 700;
    padding: 5px 0; color: var(--ink);
    background: var(--white);
}
.qty-input:focus { outline: none; border-color: var(--green-mid); }

.qty-step {
    width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center;
    border: 1.5px solid var(--line);
    border-radius: 6px;
    background: var(--white);
    color: var(--ink-soft);
    font-size: 14px; font-weight: 700;
    cursor: pointer; padding: 0; line-height: 1;
    transition: background .15s, border-color .15s, color .15s;
}
.qty-step:hover { background: var(--green-pale); border-color: var(--green-mid); color: var(--text-accent); }

.remove-btn {
    background: none; border: none;
    color: var(--ink-soft); cursor: pointer;
    font-size: 18px; padding: 0 2px;
    transition: color .15s; line-height: 1;
}
.remove-btn:hover { color: var(--red-alert); }

.discount-section {
    margin: 10px 18px 0;
    padding: 0;
    border-top: 1px solid var(--line);
    flex-shrink: 0;
}
.discount-toggle {
    display: flex; align-items: center; justify-content: space-between;
    cursor: pointer; user-select: none;
    padding: 12px 2px;
    gap: 10px;
}
.discount-toggle-label {
    font-size: 13.5px; font-weight: 700;
    color: var(--text-accent);
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap; row-gap: 6px;
}
.discount-toggle-label .disc-icon { font-size: 15px; line-height: 1; }
.discount-active-badge {
    font-size: 11px; font-weight: 700;
    background: var(--mustard-pale); color: var(--mustard-text);
    padding: 3px 10px; border-radius: 20px;
    white-space: nowrap;
}
.discount-chevron { font-size: 12px; color: var(--ink-soft); transition: transform .2s; flex-shrink: 0; }
.discount-chevron.open { transform: rotate(180deg); }
.discount-body { display: none; }
.discount-body.open {
    display: flex; flex-direction: column; gap: 14px;
    padding: 4px 4px 16px;
}

.discount-quick-btns { display: flex; gap: 8px; }
.disc-quick-btn {
    flex: 1; padding: 9px 6px;
    border-radius: 9px; font-size: 12.5px; font-weight: 700;
    font-family: var(--font-body); cursor: pointer;
    border: 1.5px solid var(--line);
    background: var(--white); color: var(--ink-soft);
    transition: all .15s;
}
.disc-quick-btn:hover { border-color: var(--mustard); color: var(--mustard-text); background: var(--mustard-pale); }
.disc-quick-btn.selected { background: var(--mustard-pale); border-color: var(--mustard); color: var(--mustard-text); }
.disc-quick-btn:disabled { opacity: .6; cursor: not-allowed; }

.senior-pwd-panel {
    display: flex; flex-direction: column; gap: 10px;
    padding: 12px; border-radius: 10px;
    background: var(--cream); border: 1px solid var(--line);
}
.senior-pwd-label { font-size: 13px; font-weight: 700; color: var(--ink); margin: 0; }
#seniorPwdForm { display: flex; flex-direction: column; gap: 10px; }
#customDiscForm { display: flex; flex-direction: column; gap: 10px; }

.discount-type-tabs {
    display: flex; background: var(--cream);
    border-radius: 9px; padding: 3px;
    gap: 3px;
}
.disc-type-tab {
    flex: 1; padding: 8px 0;
    border-radius: 7px; font-size: 12.5px; font-weight: 700;
    font-family: var(--font-body); border: none;
    background: none; color: var(--ink-soft);
    cursor: pointer; transition: all .15s; text-align: center;
}
.disc-type-tab.active { background: var(--white); color: var(--text-accent); box-shadow: 0 1px 3px rgba(0,0,0,.08); }

.discount-input-row { display: flex; gap: 8px; align-items: stretch; }
.discount-value-input {
    flex: 1; min-width: 0; padding: 10px 12px;
    border: 1.5px solid var(--line); border-radius: 9px;
    font-family: var(--font-body); font-size: 14px; font-weight: 600;
    color: var(--ink); background: var(--cream);
}
.discount-value-input:focus { outline: none; border-color: var(--green-mid); background: var(--white); }
.apply-disc-btn {
    padding: 10px 16px; border-radius: 9px; border: none;
    background: var(--green-mid); color: #FFFFFF;
    font-family: var(--font-body); font-size: 13px; font-weight: 700;
    cursor: pointer; transition: background .15s; white-space: nowrap;
    flex-shrink: 0;
}
.apply-disc-btn:hover { background: var(--green-deep); }
.remove-disc-btn {
    padding: 10px 14px; border-radius: 9px;
    border: 1.5px solid var(--red-pale);
    background: var(--white); color: var(--red-alert);
    font-family: var(--font-body); font-size: 12px; font-weight: 700;
    cursor: pointer; transition: background .15s; white-space: nowrap;
    flex-shrink: 0;
}
.remove-disc-btn:hover { background: var(--red-pale); }


.cart-footer {
    border-top: 1px solid var(--line);
    padding: 14px 18px;
    flex-shrink: 0; background: var(--white);
}
.cart-summary { margin-bottom: 12px; }
.summary-row {
    display: flex; justify-content: space-between;
    align-items: center; font-size: 13px;
    padding: 3px 0; color: var(--ink-soft);
}
.summary-row.subtotal-row { color: var(--ink); }
.summary-row.discount-row { color: var(--mustard-text); font-weight: 600; }
.summary-row.total-row {
    font-family: var(--font-display);
    font-size: 20px; font-weight: 800;
    color: var(--text-accent);
    padding-top: 8px; margin-top: 4px;
    border-top: 1px solid var(--line);
}

.payment-input-wrap { margin-bottom: 10px; }
.payment-label { font-size: 12px; font-weight: 700; color: var(--ink-soft); margin-bottom: 6px; display: block; }
.payment-input {
    width: 100%; padding: 11px 14px;
    border: 1.5px solid var(--line); border-radius: 10px;
    font-family: var(--font-display); font-size: 17px; font-weight: 700;
    color: var(--ink); background: var(--cream); transition: border-color .15s;
}
.payment-input:focus { outline: none; border-color: var(--green-mid); background: var(--white); }

.change-row {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 12px; padding: 8px 12px;
    background: var(--green-pale); border-radius: 9px;
}
.change-label { font-size: 13px; font-weight: 600; color: var(--text-accent); }
.change-value { font-family: var(--font-display); font-size: 16px; font-weight: 800; color: var(--text-accent); }

.checkout-btn {
    width: 100%; padding: 14px; border: none;
    border-radius: 11px; background: var(--green-mid);
    color: #FFFFFF; font-family: var(--font-display);
    font-size: 16px; font-weight: 700; cursor: pointer;
    transition: background .15s;
    box-shadow: 0 4px 14px rgba(47,122,80,0.25);
}
.checkout-btn:hover { background: var(--green-deep); }
.checkout-btn:disabled { opacity: .5; cursor: not-allowed; }

.history-list { flex: 1; overflow-y: auto; padding: 4px 0; }
.history-empty {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    height: 100%; color: var(--ink-soft); font-size: 14px;
    gap: 10px; padding: 20px; text-align: center;
}
.history-item {
    padding: 12px 18px;
    border-bottom: 1px solid var(--line);
}
.history-item:last-child { border-bottom: none; }
.history-item-top {
    display: flex; align-items: center;
    justify-content: space-between; gap: 10px;
}
.history-info { display: flex; flex-direction: column; gap: 2px; }
.history-id { font-size: 13.5px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 6px; }
.history-meta { font-size: 12px; color: var(--ink-soft); }
.history-amount { font-size: 14px; font-weight: 700; color: var(--green-mid); }
.history-actions { display: flex; align-items: center; gap: 6px; }

.status-badge {
    font-size: 10.5px; font-weight: 700;
    padding: 2px 8px; border-radius: 20px;
    display: inline-block;
}
.status-badge.completed { background: var(--green-pale); color: var(--text-accent); }
.status-badge.voided    { background: var(--red-pale); color: var(--red-alert); }
.status-badge.refunded  { background: var(--mustard-pale); color: var(--mustard-text); }
.status-badge.partial_refunded { background: var(--mustard-pale); color: var(--mustard-text); }

/* Refund reason display */
.history-refund-reason {
    margin-top: 6px;
    display: flex; align-items: flex-start; gap: 5px;
    font-size: 11.5px; color: var(--mustard-text);
    background: var(--mustard-pale);
    padding: 6px 10px; border-radius: 8px;
    font-style: italic; line-height: 1.4;
}
.history-refund-reason svg { width: 12px; height: 12px; flex-shrink: 0; margin-top: 2px; }

.void-btn {
    font-size: 12px; font-weight: 700;
    color: var(--red-alert);
    background: none; border: 1.5px solid var(--red-pale);
    border-radius: 8px; padding: 6px 11px;
    cursor: pointer; font-family: inherit;
    transition: background .15s;
}
.void-btn:hover { background: var(--red-pale); }

.refund-btn {
    font-size: 12px; font-weight: 700;
    color: var(--mustard-text);
    background: none; border: 1.5px solid var(--mustard);
    border-radius: 8px; padding: 6px 11px;
    cursor: pointer; font-family: inherit;
    display: inline-flex; align-items: center; gap: 5px;
    transition: background .15s, border-color .15s, transform .1s;
}
.refund-btn:hover { background: var(--mustard-pale); border-color: var(--mustard-text); }
.refund-btn:active { transform: scale(.96); }
.refund-btn svg { width: 12px; height: 12px; flex-shrink: 0; }

/* Refund reason form (inline, toggled) */
@keyframes refundFormIn {
    from { opacity: 0; transform: translateY(-6px) scale(.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.refund-form-wrap {
    display: none;
    margin-top: 10px;
    padding: 14px;
    background: linear-gradient(180deg, #fffaf1, var(--mustard-pale));
    border-radius: 12px;
    border: 1.5px solid var(--mustard);
    box-shadow: 0 6px 16px rgba(180,120,0,0.12);
}
html[data-theme="dark"] .refund-form-wrap { background: linear-gradient(180deg, var(--mustard-pale), var(--mustard-pale)); }
.refund-form-wrap.open {
    display: block;
    animation: refundFormIn .18s ease-out;
}
.refund-form-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 700; color: var(--mustard-text);
    margin-bottom: 8px; letter-spacing: .2px;
}
.refund-form-label .req { color: var(--red-alert); font-weight: 800; }
.refund-reason-input {
    width: 100%; padding: 10px 12px;
    border: 1.5px solid var(--mustard);
    border-radius: 9px; font-family: var(--font-body);
    font-size: 13.5px; color: var(--ink); line-height: 1.4;
    background: var(--white); resize: none;
    margin-bottom: 10px;
    transition: border-color .15s, box-shadow .15s;
}
.refund-reason-input::placeholder { color: var(--ink-soft); opacity: .75; }
.refund-reason-input:focus {
    outline: none; border-color: var(--mustard-text);
    box-shadow: 0 0 0 3px rgba(138,88,0,0.12);
}
.refund-form-actions { display: flex; gap: 8px; }
.confirm-refund-btn {
    flex: 1; padding: 10px; border: none;
    border-radius: 9px; background: var(--mustard);
    color: #FFFFFF; font-family: var(--font-body);
    font-size: 13.5px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 6px;
    box-shadow: 0 3px 10px rgba(196,127,26,0.35);
    transition: background .15s, transform .1s, box-shadow .15s;
}
.confirm-refund-btn:hover { background: #c47f1a; box-shadow: 0 4px 12px rgba(196,127,26,0.45); transform: translateY(-1px); }
.confirm-refund-btn:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(196,127,26,0.35); }
.cancel-refund-btn {
    padding: 10px 14px; border-radius: 9px;
    border: 1.5px solid var(--line); background: var(--white);
    color: var(--ink-soft); font-family: var(--font-body);
    font-size: 13.5px; font-weight: 700; cursor: pointer;
    transition: background .15s, border-color .15s, color .15s;
}
.cancel-refund-btn:hover { background: var(--cream); border-color: var(--ink-soft); color: var(--ink); }

/* Partial refund (per-item) */
.partial-refund-btn {
    display: flex; align-items: center; gap: 4px;
    padding: 6px 10px; border-radius: 8px;
    border: 1.5px solid var(--line); background: var(--white);
    color: var(--ink-soft); font-size: 11px; font-weight: 700;
    cursor: pointer; transition: .15s;
}
.partial-refund-btn:hover { background: var(--green-pale); border-color: var(--green-mid); color: var(--text-accent); }
.partial-refund-btn svg { width: 12px; height: 12px; flex-shrink: 0; }

.partial-refund-form-wrap {
    max-height: 0; overflow: hidden; opacity: 0;
    transition: max-height .18s ease-out, opacity .15s ease-out, margin-top .18s ease-out;
}
.partial-refund-form-wrap.open {
    max-height: 600px; opacity: 1; margin-top: 10px;
    animation: refundFormIn .18s ease-out;
}
.partial-refund-items {
    display: flex; flex-direction: column; gap: 8px;
    margin-bottom: 10px;
}
.partial-refund-item-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 10px; padding: 8px 10px;
    background: var(--cream); border-radius: 9px;
    border: 1px solid var(--line);
}
.partial-refund-item-row.fully-refunded { opacity: .5; }
.partial-refund-item-name { font-size: 12.5px; font-weight: 700; color: var(--ink); }
.partial-refund-item-meta { font-size: 11px; color: var(--ink-soft); margin-top: 2px; }
.partial-refund-qty-input {
    width: 56px; padding: 6px 8px; text-align: center;
    border: 1.5px solid var(--line); border-radius: 7px;
    font-size: 13px; font-weight: 700; color: var(--ink);
    background: var(--white);
}
.partial-refund-qty-input:focus { outline: none; border-color: var(--green-mid); }
.partial-refund-qty-input:disabled { background: var(--cream); color: var(--ink-soft); cursor: not-allowed; }

.history-footer-note {
    padding: 12px 18px;
    font-size: 11.5px; color: var(--ink-soft);
    border-top: 1px solid var(--line); flex-shrink: 0;
}

/* ── MODAL / RECEIPT ── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.5); z-index: 300;
    align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }

.receipt-box {
    background: var(--white); border-radius: 18px;
    padding: 32px 28px; width: 100%; max-width: 380px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    max-height: 90vh; overflow-y: auto;
}
.receipt-header { text-align: center; margin-bottom: 16px; }
.receipt-logo {
    width: 64px; height: 64px; margin: 0 auto 10px;
    border-radius: 12px; overflow: hidden;
    display: flex; align-items: center; justify-content: center;
    background: var(--green-pale);
}
.receipt-logo img { width: 100%; height: 100%; object-fit: cover; }
.receipt-logo-placeholder {
    width: 64px; height: 64px; background: var(--green-pale);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    margin: 0 auto 10px; color: var(--green-mid); font-size: 28px;
}
.receipt-store-name { font-family: var(--font-display); font-size: 20px; font-weight: 800; color: var(--text-accent); }
.receipt-store-sub  { font-size: 12px; color: var(--ink-soft); margin-top: 2px; line-height: 1.4; }
.receipt-txn-info   { font-size: 12px; color: var(--ink-soft); margin-top: 8px; }
.receipt-divider { border: none; border-top: 1px dashed var(--line); margin: 14px 0; }
.receipt-items { margin-bottom: 4px; }
.receipt-item { display: flex; justify-content: space-between; font-size: 13.5px; padding: 4px 0; }
.receipt-item .item-name { color: var(--ink); font-weight: 500; }
.receipt-item .item-sub  { color: var(--ink-soft); font-weight: 600; }
.receipt-totals { margin-top: 8px; }
.receipt-row { display: flex; justify-content: space-between; font-size: 13.5px; padding: 4px 0; color: var(--ink-soft); }
.receipt-row.discount { color: var(--mustard-text); font-weight: 600; }
.receipt-row.total {
    font-family: var(--font-display); font-size: 18px; font-weight: 800;
    color: var(--text-accent); border-top: 2px solid var(--line);
    padding-top: 10px; margin-top: 6px;
}
.receipt-row.change { color: var(--green-mid); font-weight: 700; font-size: 14px; }
.receipt-footer-text {
    text-align: center; font-size: 12px; color: var(--ink-soft);
    margin-top: 14px; font-style: italic;
}
.receipt-actions { display: flex; gap: 10px; margin-top: 18px; }
.btn-print {
    flex: 1; padding: 11px; border-radius: 10px;
    border: 1.5px solid var(--line); background: var(--white);
    font-family: var(--font-body); font-size: 14px; font-weight: 600;
    cursor: pointer; color: var(--ink-soft); transition: background .15s;
}
.btn-print:hover { background: var(--cream); }
.btn-new {
    flex: 1; padding: 11px; border-radius: 10px; border: none;
    background: var(--green-mid); color: #FFFFFF;
    font-family: var(--font-body); font-size: 14px; font-weight: 600;
    cursor: pointer; transition: background .15s;
}
.btn-new:hover { background: var(--green-deep); }

@media print {
    @page { size: 80mm auto; margin: 0; }

    /* width:100% (instead of a fixed 80mm) lets the receipt center itself
       on whatever paper size the selected printer actually uses. Thermal
       printers that honor the 80mm @page size above are unaffected; a
       normal printer/PDF using Letter/A4 will now center the 80mm box
       on the page instead of pinning it to the left edge. */
    html, body { width: 100%; background: var(--white); }
    .pos-shell    { display: none; }
    .modal-overlay {
        position: static; background: none; display: flex !important;
        inset: auto; justify-content: center;
    }
    .receipt-box {
        box-shadow: none; border-radius: 0; max-height: none;
        width: 80mm; max-width: 80mm; margin: 0 auto; padding: 4mm;
    }
    .receipt-actions { display: none; }

    .receipt-store-name { font-size: 15px; }
    .receipt-store-sub,
    .receipt-txn-info,
    .receipt-footer-text { font-size: 10px; }
    .receipt-item, .receipt-row { font-size: 11px; padding: 2px 0; }
    .receipt-row.total { font-size: 14px; padding-top: 6px; margin-top: 4px; }

    /* Hide the SweetAlert2 toast ("Transaction complete!") so only the receipt prints */
    .swal2-container { display: none !important; }
}

/* SweetAlert2 confirm dialogs (logout, void sale, etc.) — restyled to
   match the POS's own card look instead of the large CDN default.
   !important since SweetAlert injects its own <style> tag, which can
   otherwise load after this one. */
.swal2-popup:not(.swal2-toast) {
    font-family: var(--font-body) !important;
    border-radius: var(--radius-lg) !important;
    width: auto !important;
    max-width: 360px !important;
    padding: 26px 22px 22px !important;
    background: var(--white) !important;
    box-shadow: var(--shadow-card) !important;
}
.swal2-popup:not(.swal2-toast) .swal2-icon { width: 50px !important; height: 50px !important; margin: 4px auto 10px !important; }
.swal2-popup:not(.swal2-toast) .swal2-title {
    font-family: var(--font-display) !important;
    font-size: 18px !important;
    font-weight: 700 !important;
    color: var(--ink) !important;
    padding: 0 !important;
    margin-bottom: 4px !important;
}
.swal2-popup:not(.swal2-toast) .swal2-html-container {
    font-size: 13.5px !important;
    line-height: 1.5 !important;
    color: var(--ink-soft) !important;
    margin: 6px 2px 0 !important;
}
.swal2-popup:not(.swal2-toast) .swal2-actions { margin-top: 20px !important; gap: 8px !important; }
.swal2-styled.swal2-confirm,
.swal2-styled.swal2-cancel {
    border-radius: 10px !important;
    font-family: var(--font-body) !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    padding: 9px 16px !important;
    box-shadow: none !important;
}
.swal2-styled.swal2-cancel {
    background: var(--cream) !important;
    color: var(--ink-soft) !important;
}
.swal2-styled:focus { outline: none !important; box-shadow: 0 0 0 3px rgba(0,0,0,.08) !important; }

/* Toasts (e.g. "Added to cart", "Transaction complete!") use SweetAlert2's
   own compact row layout, which the confirm-dialog rules above were
   clobbering (centered 50px icon, 26px padding, 360px width) since both
   share the .swal2-popup class. Scoped out above with :not(.swal2-toast);
   here we just brand the toast's font/corners/shadow, keeping its layout. */
.swal2-popup.swal2-toast {
    font-family: var(--font-body) !important;
    border-radius: 10px !important;
    box-shadow: var(--shadow-card) !important;
}
.swal2-popup.swal2-toast .swal2-title {
    font-family: var(--font-body) !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    color: var(--ink) !important;
}
.swal2-popup.swal2-toast .swal2-html-container {
    color: var(--ink-soft) !important;
}
</style>
</head>
<body>

<div class="app-shell pos-shell">
<script>
(function () {
    // Apply saved collapse state + light/dark theme ASAP (before the
    // rest of the page renders) so POS matches the app-wide saved theme
    // without a flash. document.currentScript's parent is .pos-shell
    // itself, which already exists in the DOM at this point even
    // though its children (aside, etc.) haven't been parsed yet.
    var shell = document.currentScript.parentElement;
    if (localStorage.getItem('siaa_sidebar_collapsed') === '1') {
        if (shell) shell.classList.add('sidebar-collapsed');
        document.body.classList.add('sidebar-collapsed');
    }
    var themeKey = 'siaa_theme_<?= clean((string)($_SESSION['user_id'] ?? 'guest')) ?>';
    var savedTheme = localStorage.getItem('manage-grocery-theme');
    if (savedTheme === 'dark' || (savedTheme !== 'light' && localStorage.getItem(themeKey) === 'dark')) {
        document.documentElement.setAttribute('data-theme', 'dark');
    } else if (savedTheme === 'light') {
        document.documentElement.removeAttribute('data-theme');
    }
})();
</script>
    <?php $active = 'pos'; include __DIR__ . '/includes/sidebar.php'; ?>
    <button type="button" class="pos-mobile-backdrop" id="posNavBackdrop" aria-label="Close navigation"></button>
    <button type="button" class="pos-cart-backdrop" id="posCartBackdrop" aria-label="Close cart"></button>

    <div class="pos-main">
        <div class="pos-topbar">
            <button type="button" class="pos-mobile-menu" id="posMenuToggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
            <div class="topbar-icon"><svg viewBox="0 0 20 20" fill="none"><rect x="2" y="4" width="16" height="13" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M2 8h16" stroke="currentColor" stroke-width="1.6"/><path d="M6 12h2M10 12h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></div>
            <h1>Point of Sale</h1>
            <span class="cashier-name">Cashier: <?= clean($_SESSION['full_name']) ?></span>
            <button type="button" class="pos-mobile-cart" id="posCartToggle" aria-label="Open cart" aria-controls="posCart" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 1.9-1.4L22 8H6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10" cy="20" r="1.3" fill="currentColor"/><circle cx="18" cy="20" r="1.3" fill="currentColor"/></svg>
                <span>Cart</span>
                <span class="pos-mobile-cart-count"><?= (int)$cart_count ?></span>
            </button>

        </div>

        <form method="GET" action="pos.php" id="searchForm">
            <div class="pos-filters">
                <input type="text" name="search" id="searchInput" class="search-input" placeholder="Search products..." value="<?= clean($search) ?>" autocomplete="off">
                <?php if (!empty($category)): ?>
                    <input type="hidden" name="category" value="<?= clean($category) ?>">
                <?php endif; ?>
                <div class="cat-tabs">
                    <a href="pos.php<?= !empty($search) ? '?search='.urlencode($search) : '' ?>"
                       class="cat-tab <?= empty($category) ? 'active' : '' ?>">All</a>
                    <?php foreach ($categories as $cat): ?>
                        <a href="pos.php?category=<?= urlencode($cat['category_name']) ?><?= !empty($search) ? '&search='.urlencode($search) : '' ?>"
                           class="cat-tab <?= $category == $cat['category_name'] ? 'active' : '' ?>">
                            <?= clean($cat['category_name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>
        <script>
        // Live search — auto-submits the search form a moment after the
        // cashier stops typing, so results (matching ANY part of the
        // product name, so even a single first letter) show up without
        // needing to press Enter. Debounced so it doesn't reload the page
        // on every single keystroke while still typing.
        (function () {
            var input = document.getElementById('searchInput');
            var form  = document.getElementById('searchForm');
            if (!input || !form) return;
            var timer = null;
            input.addEventListener('input', function () {
                if (timer) clearTimeout(timer);
                timer = setTimeout(function () { form.submit(); }, 450);
            });
            // Keep the cursor at the end of the text after the page
            // reloads from a live search, so typing feels continuous.
            if (input.value) {
                input.focus();
                var v = input.value;
                input.value = '';
                input.value = v;
            }
        })();
        </script>

        <?php if (!empty($success_message)): ?>
        <script>
        Swal.fire({
            icon: 'success',
            title: <?= json_encode(html_entity_decode(strip_tags($success_message), ENT_QUOTES)) ?>,
            toast: true, position: 'top-end', showConfirmButton: false,
            timer: 2200, timerProgressBar: true
        });
        </script>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
        <script>
        Swal.fire({
            icon: 'error',
            title: <?= json_encode(html_entity_decode(strip_tags($error_message), ENT_QUOTES)) ?>,
            toast: true, position: 'top-end', showConfirmButton: false,
            timer: 3200, timerProgressBar: true
        });
        </script>
        <?php endif; ?>
        <?php if (!empty($timein_flash_success)): ?>
        <script>
        Swal.fire({
            icon: 'success',
            title: <?= json_encode(clean($timein_flash_success)) ?>,
            toast: true, position: 'top-end', showConfirmButton: false,
            timer: 2200, timerProgressBar: true
        });
        </script>
        <?php endif; ?>

        <div class="product-grid-wrap">
            <div class="product-grid">
                <?php if (empty($products)): ?>
                    <div class="empty-products">No products found.</div>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <?php
                        $img_path       = 'uploads/products/' . $p['image'];
                        $has_image      = $p['image'] && $p['image'] != 'default.png' && file_exists($img_path);
                        $sellable_stock = (int) $p['sellable_stock'];
                        // May stock pa raw (products.stock), pero kung
                        // wala nang non-expired na batch, hindi na ito
                        // dapat mabenta — flag as expired / not for sale.
                        $is_expired_stock = ($sellable_stock <= 0 && (int) $p['stock'] > 0);
                        $is_low           = !$is_expired_stock && $sellable_stock <= LOW_STOCK;
                        ?>
                        <div class="product-card <?= $is_expired_stock ? 'is-expired' : '' ?>"
                             <?= $is_expired_stock ? '' : 'onclick="addToCart(' . $p['id'] . ')"' ?>>
                            <div class="product-img-wrap">
                                <?php if ($has_image): ?>
                                    <img src="<?= clean($img_path) ?>" alt="<?= clean($p['product_name']) ?>">
                                <?php else: ?>
                                    <div class="product-img-placeholder">🛒</div>
                                <?php endif; ?>
                                <?php if ($is_expired_stock): ?>
                                    <div class="product-expired-badge">🚫 Expired</div>
                                <?php endif; ?>
                            </div>
                            <div class="product-info">
                                <div class="product-name"><?= clean($p['product_name']) ?></div>
                                <?php $disc = getDiscountedPrice($p); ?>
                                <?php if ($disc['has_discount']): ?>
                                    <div class="product-price">
                                        <span style="text-decoration:line-through;color:var(--ink-soft);font-size:12px;margin-right:4px;"><?= peso($disc['original_price']) ?></span>
                                        <span style="color:var(--red-alert)">🏷️ <?= peso($disc['final_price']) ?></span>
                                    </div>
                                <?php else: ?>
                                    <div class="product-price"><?= peso($p['price']) ?></div>
                                <?php endif; ?>
                                <?php if ($is_expired_stock): ?>
                                    <div class="product-stock expired">🚫 Expired — Not for Sale</div>
                                <?php else: ?>
                                    <div class="product-stock <?= $is_low ? 'low' : '' ?>">
                                        <?= $is_low ? '⚠️ ' : '' ?><?= $sellable_stock ?> pcs left
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="pos-cart" id="posCart" aria-label="Current cart and transaction history">

        <div class="cart-tabs">
            <button type="button" class="cart-tab-btn active" id="tabBtnCart" onclick="switchTab('cart')">
                Cart
                <?php if ($cart_count > 0): ?><span class="tab-count"><?= $cart_count ?></span><?php endif; ?>
            </button>
            <button type="button" class="cart-tab-btn" id="tabBtnHistory" onclick="switchTab('history')">
                History
                <?php if (!empty($todays_transactions)): ?><span class="tab-count"><?= count($todays_transactions) ?></span><?php endif; ?>
            </button>
            <button type="button" class="pos-cart-close" id="posCartClose" aria-label="Close cart">×</button>
        </div>

        <div class="tab-panel active" id="panelCart">

            <div class="cart-header">
                <h2>Current Order</h2>
                <div class="cart-header-actions">
                    <?php if (!empty($_SESSION['cart'])): ?>
                        <form method="POST" id="voidForm">
                        <?php csrfField(); ?>
                          <input type="hidden" name="action" value="void">
                          <button type="button" class="clear-btn" style="color:var(--red-alert)" onclick="confirmVoid()">Void</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="cart-items">
                <?php if (empty($_SESSION['cart'])): ?>
                    <div class="cart-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4zM3 6h18M16 10a4 4 0 01-8 0"/>
                        </svg>
                        <span>Cart is empty</span>
                        <span style="font-size:12px">Click a product to add it</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($_SESSION['cart'] as $pid => $item): ?>
                        <?php
                        $img_path  = 'uploads/products/' . ($item['image'] ?? '');
                        $has_image = !empty($item['image']) && $item['image'] != 'default.png' && file_exists($img_path);
                        ?>
                        <div class="cart-item">
                            <?php if ($has_image): ?>
                                <img src="<?= clean($img_path) ?>" class="cart-item-img" alt="">
                            <?php else: ?>
                                <div class="cart-item-img-placeholder">🛒</div>
                            <?php endif; ?>

                            <div class="cart-item-info">
                                <div class="cart-item-name"><?= clean($item['product_name']) ?></div>
                                <div class="cart-item-price"><?= peso($item['price']) ?> × <?= $item['quantity'] ?> = <?= peso($item['price'] * $item['quantity']) ?></div>
                            </div>

                            <div class="qty-control">
                                <form method="POST" style="display:contents" id="qtyForm<?= $pid ?>">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="update_qty">
                                    <input type="hidden" name="product_id" value="<?= $pid ?>">
                                    <button type="button" class="qty-step" onclick="stepQty('qtyForm<?= $pid ?>', -1)" title="Decrease">−</button>
                                    <input type="number" name="quantity" value="<?= $item['quantity'] ?>"
                                           class="qty-input" min="0"
                                           onchange="this.form.submit()">
                                    <button type="button" class="qty-step" onclick="stepQty('qtyForm<?= $pid ?>', 1)" title="Increase">+</button>
                                </form>
                                <form method="POST" style="display:contents">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="remove_item">
                                    <input type="hidden" name="product_id" value="<?= $pid ?>">
                                    <button type="submit" class="remove-btn" title="Remove">×</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($_SESSION['cart'])): ?>
            <div class="discount-section">
                <div class="discount-toggle" onclick="toggleDiscount()">
                    <span class="discount-toggle-label">
                        <span class="disc-icon">🏷️</span>
                        Discount / Promo
                        <?php if ($discount_is_pending): ?>
                            <span class="discount-active-badge" style="background:var(--mustard-pale,#FBEFD8); color:var(--mustard-text);">⏳ Awaiting Finance</span>
                        <?php elseif ($active_discount): ?>
                            <span class="discount-active-badge"><?= clean($active_discount['label']) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="discount-chevron" id="discChevron">▼</span>
                </div>
                <div class="discount-body" id="discountBody">
                    <?php if ($discount_is_pending): ?>
                        <div style="background:var(--mustard-pale); color:var(--mustard-text); border:1px solid var(--line); border-radius:10px; padding:10px 12px; font-size:13px; line-height:1.5;">
                            ⏳ <strong><?= clean($active_discount['label']) ?></strong> was sent to Finance and is awaiting approval.
                            The total below won't reflect it until it's approved — reload this page to check for a decision, or cancel it.
                        </div>
                        <form method="POST">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="cancel_discount_request">
                            <button type="submit" class="remove-disc-btn">Cancel Request</button>
                        </form>
                    <?php else: ?>
                    <div class="discount-quick-btns">
                        <button type="button" id="seniorQuickBtn" class="disc-quick-btn <?= ($active_discount['type'] ?? '') === 'senior' ? 'selected' : '' ?>" onclick="selectSeniorPwd('senior')" <?= $active_discount ? 'disabled' : '' ?>>
                            👴 Senior (20%)
                        </button>
                        <button type="button" id="pwdQuickBtn" class="disc-quick-btn <?= ($active_discount['type'] ?? '') === 'pwd' ? 'selected' : '' ?>" onclick="selectSeniorPwd('pwd')" <?= $active_discount ? 'disabled' : '' ?>>
                            ♿ PWD (20%)
                        </button>
                    </div>
                    <div class="senior-pwd-panel" id="seniorPwdPanel" style="display:none">
                        <div class="senior-pwd-label" id="seniorPwdLabel">👴 Senior Citizen — 20% discount</div>
                        <form method="POST" id="seniorPwdForm" onsubmit="return false;">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="apply_discount">
                            <input type="hidden" name="discount_type" id="seniorPwdTypeHidden" value="senior">
                            <input type="hidden" name="supervisor_username" id="discSupUsernameHidden" value="">
                            <input type="hidden" name="supervisor_password" id="discSupPasswordHidden" value="">
                            <input type="text" name="id_number" id="discIdNumberInput"
                                   class="discount-value-input" placeholder="OSCA/PWD ID Number (required)"
                                   inputmode="numeric" maxlength="20"
                                   onkeydown="blockDiscountSigns(event)"
                                   oninput="sanitizeDiscountInput(this)"
                                   required>
                            <div class="discount-input-row">
                                <input type="text" name="cardholder_name" id="discCardholderInput"
                                       class="discount-value-input" placeholder="Cardholder Name (optional)">
                                <button type="button" class="apply-disc-btn" onclick="openDiscApprovalModal()">Apply</button>
                                <button type="button" class="remove-disc-btn" onclick="cancelSeniorPwd()">Cancel</button>
                            </div>
                        </form>
                    </div>
                    <form method="POST" id="customDiscForm">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="apply_discount">
                        <input type="hidden" name="discount_type" id="discTypeHidden" value="percent">
                        <div class="discount-type-tabs">
                            <button type="button" class="disc-type-tab active" id="tabPercent" onclick="setDiscType('percent')" <?= $active_discount ? 'disabled' : '' ?>>% Percent</button>
                            <button type="button" class="disc-type-tab" id="tabFixed" onclick="setDiscType('fixed')" <?= $active_discount ? 'disabled' : '' ?>>₱ Fixed</button>
                        </div>
                        <div class="discount-input-row">
                            <input type="number" name="discount_value" id="discValueInput"
                                   class="discount-value-input" placeholder="e.g. 10" min="0" step="0.01"
                                   inputmode="decimal"
                                   onkeydown="blockPaymentSigns(event)"
                                   oninput="sanitizePaymentInput(this)"
                                   <?= $active_discount ? 'disabled' : '' ?>>
                            <?php if ($active_discount): ?>
                                <form method="POST" style="display:contents">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="apply_discount">
                                    <input type="hidden" name="discount_type" value="none">
                                    <button type="submit" class="remove-disc-btn">Remove</button>
                                </form>
                            <?php else: ?>
                                <button type="submit" class="apply-disc-btn">Apply</button>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="cart-footer">
                <div class="cart-summary">
                    <?php if ($cart_discount > 0): ?>
                    <div class="summary-row subtotal-row">
                        <span>Subtotal</span>
                        <span><?= peso($cart_subtotal) ?></span>
                    </div>
                    <div class="summary-row discount-row">
                        <span>🏷️ <?= clean($active_discount['label']) ?></span>
                        <span><?= peso($cart_discount) ?> off</span>
                    </div>
                    <?php endif; ?>
                    <div class="summary-row total-row">
                        <span>Total</span>
                        <span><?= peso($cart_total) ?></span>
                    </div>
                </div>

                <?php if ($discount_is_pending): ?>
                    <div style="text-align:center; font-size:12.5px; color:var(--mustard-text); margin-bottom:8px;">
                        ⏳ Waiting for Finance to approve the discount before you can check out.
                    </div>
                    <button class="checkout-btn" disabled>Checkout</button>
                <?php elseif (!empty($_SESSION['cart'])): ?>
                <form method="POST" action="pos.php" id="checkoutForm">
                    <?php csrfField(); ?>
                    <?php idempotencyField('checkout'); ?>
                    <input type="hidden" name="action" value="checkout">
                    <div class="payment-input-wrap">
                        <label class="payment-label">CASH RECEIVED (₱)</label>
                        <input type="number" name="payment" id="paymentInput" class="payment-input"
                               step="0.01" min="<?= $cart_total ?>" placeholder="0.00"
                               inputmode="decimal"
                               onkeydown="blockPaymentSigns(event)"
                               oninput="sanitizePaymentInput(this); updateChange()"
                               onpaste="return false" required>
                    </div>
                    <div class="change-row" id="changeRow" style="display:none">
                        <span class="change-label">Change</span>
                        <span class="change-value" id="changeDisplay">₱ 0.00</span>
                    </div>
                    <button type="submit" class="checkout-btn" id="checkoutBtn" disabled>
                        Checkout
                    </button>
                </form>
                <?php else: ?>
                    <button class="checkout-btn" disabled>Checkout</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="tab-panel" id="panelHistory">

            <div class="cart-header">
                <h2>Today's Transactions</h2>
            </div>

            <div class="history-list">
                <?php if (empty($todays_transactions)): ?>
                    <div class="history-empty">
                        <span>No transactions yet today.</span>
                        <span style="font-size:12px">Completed sales will show up here.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($todays_transactions as $txn): ?>
                        <div class="history-item">
                            <div class="history-item-top">
                                <div class="history-info">
                                    <span class="history-id">
                                        Txn #<?= $txn['id'] ?>
                                        <span class="status-badge <?= $txn['status'] ?>">
                                            <?= $txn['status'] === 'partial_refunded' ? 'Partially Refunded' : ucfirst($txn['status']) ?>
                                        </span>
                                    </span>
                                    <span class="history-meta"><?= date('h:i A', strtotime($txn['transaction_date'])) ?></span>
                                </div>
                                <div class="history-actions">
                                    <span class="history-amount"><?= peso($txn['total_amount']) ?></span>

                                    <?php
                                        $__items = $todays_txn_items[$txn['id']] ?? [];
                                        $__has_refundable_item = false;
                                        foreach ($__items as $__it) {
                                            if ($__it['refundable_qty'] > 0) { $__has_refundable_item = true; break; }
                                        }
                                    ?>

                                    <?php if ($txn['status'] === 'completed'): ?>
                                        <button type="button" class="refund-btn" onclick="toggleRefundForm(<?= $txn['id'] ?>)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                            Refund
                                        </button>
                                    <?php endif; ?>

                                    <?php if (in_array($txn['status'], ['completed', 'partial_refunded'], true) && $__has_refundable_item): ?>
                                        <button type="button" class="partial-refund-btn" onclick="togglePartialRefundForm(<?= $txn['id'] ?>)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/><path d="m16 8-8.5 8.5"/></svg>
                                            Refund item(s)
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (in_array($txn['status'], ['refunded', 'partial_refunded'], true) && !empty($txn['refund_reason'])): ?>
                                <div class="history-refund-reason">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                    <span><strong>Reason:</strong> <?= clean($txn['refund_reason']) ?><?= !empty($txn['refund_approved_by_name']) ? ' &middot; Approved by ' . clean($txn['refund_approved_by_name']) : '' ?></span>
                                </div>
                            <?php endif; ?>

                            <!--  refund reason (whole transaction) -->
                            <?php if ($txn['status'] === 'completed'): ?>
                            <div class="refund-form-wrap" id="refundForm<?= $txn['id'] ?>">
                                <form method="POST">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="refund">
                                    <input type="hidden" name="sale_id" value="<?= $txn['id'] ?>">
                                    <label class="refund-form-label">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                        Reason for refund <span class="req">*</span>
                                    </label>
                                    <textarea name="refund_reason" class="refund-reason-input" rows="2"
                                              placeholder="e.g. Item was damaged, wrong order, customer changed mind..." required></textarea>
                                    <label class="refund-form-label">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 14 0v1"/></svg>
                                        Supervisor/Admin approval required <span class="req">*</span>
                                    </label>
                                    <input type="text" name="supervisor_username" class="discount-value-input" placeholder="Supervisor/Admin Username" autocomplete="off" required>
                                    <input type="password" name="supervisor_password" class="discount-value-input" placeholder="Supervisor/Admin Password" autocomplete="off" required style="margin-top:6px;">
                                    <div class="refund-form-actions">
                                        <button type="button" class="cancel-refund-btn" onclick="toggleRefundForm(<?= $txn['id'] ?>)">Cancel</button>
                                        <button type="submit" class="confirm-refund-btn">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                            Confirm Refund
                                        </button>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>

                            <!-- partial (per-item) refund -->
                            <?php if (in_array($txn['status'], ['completed', 'partial_refunded'], true) && $__has_refundable_item): ?>
                            <div class="partial-refund-form-wrap" id="partialRefundForm<?= $txn['id'] ?>">
                                <form method="POST">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="partial_refund">
                                    <input type="hidden" name="sale_id" value="<?= $txn['id'] ?>">

                                    <div class="partial-refund-items">
                                        <?php foreach ($__items as $__it): ?>
                                            <?php $__fully_done = $__it['refundable_qty'] <= 0; ?>
                                            <div class="partial-refund-item-row<?= $__fully_done ? ' fully-refunded' : '' ?>">
                                                <div>
                                                    <div class="partial-refund-item-name"><?= clean($__it['product_name']) ?></div>
                                                    <div class="partial-refund-item-meta">
                                                        <?= (int) $__it['quantity'] ?>x bought
                                                        <?php if ($__it['refunded_qty'] > 0): ?>
                                                            &middot; <?= (int) $__it['refunded_qty'] ?> already refunded
                                                        <?php endif; ?>
                                                        &middot; <?= peso($__it['unit_price']) ?>/pc
                                                    </div>
                                                </div>
                                                <input type="number" class="partial-refund-qty-input"
                                                       name="refund_qty[<?= $__it['sales_detail_id'] ?>]"
                                                       min="0" max="<?= (int) $__it['refundable_qty'] ?>" value="0"
                                                       <?= $__fully_done ? 'disabled' : '' ?>>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <label class="refund-form-label">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                        Reason for refund <span class="req">*</span>
                                    </label>
                                    <textarea name="refund_reason" class="refund-reason-input" rows="2"
                                              placeholder="e.g. Customer only wants to return 1 item..." required></textarea>
                                    <label class="refund-form-label">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 14 0v1"/></svg>
                                        Supervisor/Admin approval required <span class="req">*</span>
                                    </label>
                                    <input type="text" name="supervisor_username" class="discount-value-input" placeholder="Supervisor/Admin Username" autocomplete="off" required>
                                    <input type="password" name="supervisor_password" class="discount-value-input" placeholder="Supervisor/Admin Password" autocomplete="off" required style="margin-top:6px;">
                                    <div class="refund-form-actions">
                                        <button type="button" class="cancel-refund-btn" onclick="togglePartialRefundForm(<?= $txn['id'] ?>)">Cancel</button>
                                        <button type="submit" class="confirm-refund-btn" onclick="return confirmPartialRefund(this)">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                            Confirm Refund
                                        </button>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="history-footer-note">
                You can only void your own transactions from today. Refunds (whole transaction or just specific items) are recorded and stock is restored.
            </div>
        </div>

    </div>
</div>


<?php if ($receipt): ?>
<div class="modal-overlay open" id="receiptModal">
    <div class="receipt-box" id="receiptContent">
        <div class="receipt-header">
            <?php
            $logo_path = 'uploads/store/' . $store_logo;
            $has_logo  = !empty($store_logo) && file_exists($logo_path);
            ?>
            <?php if ($has_logo): ?>
                <div class="receipt-logo"><img src="<?= clean($logo_path) ?>" alt="<?= clean($store_name) ?>"></div>
            <?php else: ?>
                <div class="receipt-logo-placeholder">🛒</div>
            <?php endif; ?>

            <div class="receipt-store-name"><?= clean($store_name) ?></div>
            <?php if (!empty($store_address) || !empty($store_phone)): ?>
                <div class="receipt-store-sub">
                    <?= clean($store_address) ?>
                    <?php if (!empty($store_phone)): ?><br><?= clean($store_phone) ?><?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="receipt-txn-info">
                Receipt #<?= $receipt['sale_id'] ?> &mdash; <?= $receipt['date'] ?><br>
                Cashier: <?= clean($receipt['cashier_name']) ?>
            </div>
        </div>

        <hr class="receipt-divider">

        <div class="receipt-items">
            <?php foreach ($receipt['items'] as $item): ?>
                <div class="receipt-item">
                    <span class="item-name"><?= clean($item['product_name']) ?> × <?= $item['quantity'] ?></span>
                    <span class="item-sub"><?= peso($item['price'] * $item['quantity']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <hr class="receipt-divider">

        <div class="receipt-totals">
            <?php if ($receipt['discount_amount'] > 0): ?>
            <div class="receipt-row">
                <span>Subtotal</span>
                <span><?= peso($receipt['subtotal']) ?></span>
            </div>
            <div class="receipt-row discount">
                <span>🏷️ <?= clean($receipt['discount_label']) ?></span>
                <span><?= peso($receipt['discount_amount']) ?> off</span>
            </div>
            <?php if (!empty($receipt['discount_approved_by'])): ?>
            <div class="receipt-row" style="font-size:11px; color:var(--mustard-text);">
                <span>Approved by <?= clean($receipt['discount_approved_by']) ?></span>
                <span></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <div class="receipt-row total">
                <span>Total</span>
                <span><?= peso($receipt['total_amount']) ?></span>
            </div>
            <div class="receipt-row">
                <span>Cash</span>
                <span><?= peso($receipt['payment']) ?></span>
            </div>
            <div class="receipt-row change">
                <span>Change</span>
                <span><?= peso($receipt['change_amount']) ?></span>
            </div>
        </div>

        <?php if (!empty($receipt_footer)): ?>
            <div class="receipt-footer-text"><?= clean($receipt_footer) ?></div>
        <?php endif; ?>

        <div class="receipt-actions">
            <button class="btn-print" onclick="window.print()">🖨 Print</button>
            <button class="btn-new" onclick="window.location.href='pos.php'">New Sale</button>
        </div>
    </div>
</div>
<?php endif; ?>


<form method="POST" action="pos.php" id="addToCartForm" style="display:none">
    <?php csrfField(); ?>
    <input type="hidden" name="action" value="add_to_cart">
    <input type="hidden" name="product_id" id="addProductId">
    <input type="hidden" name="quantity" value="1">
</form>

<form method="POST" action="pos.php" id="scanBarcodeForm" style="display:none">
    <?php csrfField(); ?>
    <input type="hidden" name="action" value="scan_barcode">
    <input type="hidden" name="barcode" id="scannedBarcode">
</form>


<script>

function confirmVoid() {
    Swal.fire({
        icon: 'warning',
        title: 'Void current transaction?',
        text: 'Cart will be cleared.',
        showCancelButton: true,
        confirmButtonText: 'Yes, void it',
        confirmButtonColor: 'var(--red-alert)',
        cancelButtonText: 'Cancel'
    }).then(function (result) {
        if (result.isConfirmed) {
            document.getElementById('voidForm').submit();
        }
    });
}

function stepQty(formId, delta) {
    const form  = document.getElementById(formId);
    const input = form.querySelector('input[name="quantity"]');
    const next  = Math.max(0, (parseInt(input.value, 10) || 0) + delta);
    input.value = next;
    form.submit();
}

function addToCart(productId) {
    document.getElementById('addProductId').value = productId;
    document.getElementById('addToCartForm').submit();
}

// =====================================================
// BARCODE SCANNER (keyboard-wedge) SUPPORT — Item 5, Phase 2 POS audit
// -----------------------------------------------------
// A keyboard-wedge scanner behaves like a very fast typist that ends
// with Enter: characters land as normal keydown/keypress events, so no
// scanner-specific browser API is needed. The only thing that tells a
// scan apart from someone actually typing is speed — each character
// arrives only a few milliseconds after the last. This listens
// globally (not just on the search box) so a cashier can scan an item
// at any time without having to click into a field first, but backs
// off while the person is actually typing into a text input/textarea
// or a SweetAlert modal is open, so it never steals real keystrokes.
(function () {
    var buffer = '';
    var lastCharTime = 0;
    var SCAN_MAX_GAP_MS = 50;   // max ms between keystrokes to still count as "scanning"
    var SCAN_MIN_LENGTH = 3;    // ignore anything shorter — not a real barcode

    document.addEventListener('keydown', function (e) {
        var target = e.target;
        var tag = (target && target.tagName || '').toUpperCase();
        var isTypingField = tag === 'INPUT' || tag === 'TEXTAREA' || (target && target.isContentEditable);
        var modalOpen = document.querySelector('.swal2-container') !== null;

        if (modalOpen) {
            buffer = '';
            return;
        }

        var now = Date.now();
        var gap = now - lastCharTime;
        lastCharTime = now;

        if (e.key === 'Enter') {
            if (buffer.length >= SCAN_MIN_LENGTH && gap <= SCAN_MAX_GAP_MS) {
                // Fast keystrokes ending in Enter, well outside normal
                // human typing speed — this is a scan.
                if (isTypingField && target.id === 'searchInput') {
                    // Scanning while focused on the search box: don't
                    // also let the browser submit that form as a text
                    // search, since we're handling it as a scan instead.
                    e.preventDefault();
                    target.value = '';
                }
                document.getElementById('scannedBarcode').value = buffer;
                document.getElementById('scanBarcodeForm').submit();
            }
            buffer = '';
            return;
        }

        if (gap > SCAN_MAX_GAP_MS) {
            // Too slow to be the scanner — a human started typing.
            // Reset instead of accumulating a stale/garbled buffer.
            buffer = '';
        }

        if (e.key.length === 1) {
            // Only single printable characters (digits/letters/symbols
            // a barcode can contain) — ignore Shift, Tab, arrow keys, etc.
            buffer += e.key;

            // If this is landing inside an ordinary text field (not the
            // search box), it's almost certainly a human typing a
            // product name/quantity/etc, not a scan — don't intercept.
            if (isTypingField && target.id !== 'searchInput') {
                buffer = '';
            }
        }
    });
})();


function blockPaymentSigns(event) {
    // Block e/E (exponent), +, -, and space keys so only numbers and one decimal point can be typed
    const blockedKeys = ['e', 'E', '+', '-', ' '];
    if (blockedKeys.includes(event.key)) {
        event.preventDefault();
    }
}

function blockDiscountSigns(event) {
    // Discount value: only digits and "-" are allowed — block letters, e/E, +, ., and space
    const blockedKeys = ['e', 'E', '+', '.', ' '];
    if (blockedKeys.includes(event.key)) {
        event.preventDefault();
    }
}

function sanitizeDiscountInput(el) {
    // Strip anything that isn't a digit or a dash
    el.value = el.value.replace(/[^0-9-]/g, '');
}

function sanitizePaymentInput(el) {
    // Strip anything that isn't a digit or decimal point, and allow only one decimal point
    let value = el.value.replace(/[^0-9.]/g, '');
    const firstDot = value.indexOf('.');
    if (firstDot !== -1) {
        value = value.slice(0, firstDot + 1) + value.slice(firstDot + 1).replace(/\./g, '');
    }
    el.value = value;
}

<?php if ($discount_is_pending): ?>
// Poll for Finance's decision by reloading periodically — cheap and
// simple for a register screen that's usually left open anyway.
setTimeout(function () { window.location.reload(); }, 15000);
<?php endif; ?>

const PAYMENT_BILL_STEP = <?= PAYMENT_BILL_STEP ?>; // keep in sync with the PHP constant above

function maxAllowedPayment(total) {
    return Math.ceil(total / PAYMENT_BILL_STEP) * PAYMENT_BILL_STEP;
}

function updateChange() {
    const payment   = parseFloat(document.getElementById('paymentInput').value) || 0;
    const total     = <?= $cart_total ?>;
    const changeEl  = document.getElementById('changeDisplay');
    const changeRow = document.getElementById('changeRow');
    const btn       = document.getElementById('checkoutBtn');
    const input     = document.getElementById('paymentInput');
    let   errEl     = document.getElementById('paymentLimitError');

    if (!errEl) {
        errEl = document.createElement('div');
        errEl.id = 'paymentLimitError';
        errEl.style.cssText = 'color:#c0392b; font-size:12px; font-weight:600; margin-top:-4px; margin-bottom:10px;';
        input.closest('.payment-input-wrap').insertAdjacentElement('afterend', errEl);
    }

    if (payment >= total && total > 0) {
        const change     = payment - total;
        const maxPayment = maxAllowedPayment(total);

        if (payment > maxPayment) {
            changeRow.style.display = 'none';
            errEl.textContent = 'Amount too high for this total — the most that should be entered is ₱' +
                maxPayment.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) +
                '. Please ask the customer for a smaller bill.';
            errEl.style.display = 'block';
            btn.disabled = true;
            return;
        }

        errEl.style.display = 'none';
        changeEl.textContent = '₱ ' + change.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        changeRow.style.display = 'flex';
        btn.disabled = false;
    } else {
        errEl.style.display = 'none';
        changeRow.style.display = 'none';
        btn.disabled = true;
    }
}

// Submit-once guard: agad i-disable ang button (at palitan ang label)
// sa mismong sandali ng submit, bago pa man makarating ang request sa
// server. Ito ang unang linya ng depensa laban sa mabilis na double-click;
// ang idempotency_token naman sa server side ang huling linya (kaso ng
// mabagal na koneksyon o resend).
(function () {
    const form = document.getElementById('checkoutForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        const btn = document.getElementById('checkoutBtn');
        if (btn.dataset.submitted === '1') {
            // Naka-submit na dati (o naka-disable na) ang button kaya
            // huwag nang tuluyan pang isend ang parehong form ulit.
            e.preventDefault();
            return;
        }
        btn.dataset.submitted = '1';
        btn.disabled = true;
        btn.textContent = 'Processing...';
    });
})();

function switchTab(tab) {
    const panelCart    = document.getElementById('panelCart');
    const panelHistory = document.getElementById('panelHistory');
    const btnCart      = document.getElementById('tabBtnCart');
    const btnHistory   = document.getElementById('tabBtnHistory');

    if (tab === 'cart') {
        panelCart.classList.add('active');
        panelHistory.classList.remove('active');
        btnCart.classList.add('active');
        btnHistory.classList.remove('active');
    } else {
        panelHistory.classList.add('active');
        panelCart.classList.remove('active');
        btnHistory.classList.add('active');
        btnCart.classList.remove('active');
    }
}

function toggleDiscount() {
    const body    = document.getElementById('discountBody');
    const chevron = document.getElementById('discChevron');
    body.classList.toggle('open');
    chevron.classList.toggle('open');
}

function selectSeniorPwd(type) {
    document.getElementById('seniorPwdTypeHidden').value = type;
    document.getElementById('seniorPwdLabel').textContent = type === 'senior'
        ? '👴 Senior Citizen — 20% discount'
        : '♿ PWD — 20% discount';
    document.getElementById('seniorPwdPanel').style.display = 'block';
    document.getElementById('seniorQuickBtn').classList.toggle('selected', type === 'senior');
    document.getElementById('pwdQuickBtn').classList.toggle('selected', type === 'pwd');
    document.getElementById('discIdNumberInput').focus();
}

function cancelSeniorPwd() {
    document.getElementById('seniorPwdPanel').style.display = 'none';
    document.getElementById('seniorQuickBtn').classList.remove('selected');
    document.getElementById('pwdQuickBtn').classList.remove('selected');
    document.getElementById('discIdNumberInput').value = '';
    document.getElementById('discCardholderInput').value = '';
    document.getElementById('discSupUsernameHidden').value = '';
    document.getElementById('discSupPasswordHidden').value = '';
}

function setDiscType(type) {
    document.getElementById('discTypeHidden').value = type;
    document.getElementById('tabPercent').classList.toggle('active', type === 'percent');
    document.getElementById('tabFixed').classList.toggle('active', type === 'fixed');
    document.getElementById('discValueInput').placeholder = type === 'percent' ? 'e.g. 10' : 'e.g. 50.00';
}

function toggleRefundForm(saleId) {
    const form = document.getElementById('refundForm' + saleId);
    form.classList.toggle('open');
    if (form.classList.contains('open')) {
        form.querySelector('textarea').focus();
    }
}

function togglePartialRefundForm(saleId) {
    const form = document.getElementById('partialRefundForm' + saleId);
    form.classList.toggle('open');
    if (form.classList.contains('open')) {
        const firstInput = form.querySelector('.partial-refund-qty-input:not([disabled])');
        if (firstInput) firstInput.focus();
    }
}

// Sinisiguro na may kahit isang item na may quantity > 0 bago
// isubmit ang partial refund form.
function confirmPartialRefund(btn) {
    const form = btn.closest('form');
    const qtyInputs = form.querySelectorAll('.partial-refund-qty-input');
    let totalQty = 0;
    qtyInputs.forEach(function (input) {
        totalQty += parseInt(input.value, 10) || 0;
    });
    if (totalQty <= 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Select at least one item',
            text: 'Enter a quantity greater than 0 for the item(s) the customer wants to refund.',
            confirmButtonColor: 'var(--green-mid)'
        });
        return false;
    }
    return true;
}

// Auto-dismiss alerts
setTimeout(function() {
    document.querySelectorAll('.pos-alert').forEach(function(a) {
        a.style.transition = 'opacity .4s';
        a.style.opacity = '0';
        setTimeout(function() { a.remove(); }, 400);
    });
}, 3000);

<?php if ($receipt): ?>
// The sale was already committed to the database before this receipt
// was shown, so nothing is lost if the cashier skips printing. This
// just auto-starts a new sale once the print dialog is closed, instead
// of making the cashier click "New Sale" every time.
window.addEventListener('afterprint', function () {
    window.location.href = 'pos.php';
});
<?php endif; ?>
</script>

<?php if (!$clockGate['allowed']): // always code === 'not_timed_in' at this point ?>
<style>
.timein-popup-backdrop {
    position: fixed; top: 0; right: 0; bottom: 0; left: var(--sidebar-w);
    background: linear-gradient(135deg, rgba(15,23,42,.34), rgba(30,41,59,.27));
    backdrop-filter: blur(5px) saturate(.9);
    -webkit-backdrop-filter: blur(5px) saturate(.9);
    display: flex; align-items: center; justify-content: center; z-index: 9999; padding: 20px;
    border-left: 1px solid rgba(255,255,255,.14);
    transition: left 0.22s ease, background .22s ease;
}
body.sidebar-collapsed .timein-popup-backdrop,
.app-shell.sidebar-collapsed .timein-popup-backdrop { left: var(--sidebar-w-collapsed); }
/* SweetAlert2 (e.g. the "Log out?" confirmation from the sidebar,
   which stays clickable behind this popup) must render on top of the
   Time In popup, not underneath it — its default z-index is lower. */
.swal2-container { z-index: 10000 !important; }
@media (max-width: 900px) {
    /* On narrow layouts the sidebar collapses/stacks instead of sitting
       in its own fixed-width column, so the popup should cover the full
       width again rather than leaving a gap the sidebar no longer fills. */
    .timein-popup-backdrop { left: 0; border-left: 0; }
}
@media (max-width: 900px) {
    /* Mirrors style.css's auto-shrink at this breakpoint — was missing
       here, so POS never narrowed to the icon-only rail like every
       other page, making the sidebar feel inconsistent across pages. */
    .pos-shell { grid-template-columns: var(--sidebar-w-collapsed) 1fr var(--cart-w); }
    .pos-shell .sidebar { padding: 24px 12px; }
    .pos-shell .brand-text, .pos-shell .nav-label, .pos-shell .nav-section-label,
    .pos-shell .sidebar-foot .foot-text, .pos-shell .foot-logout-btn .nav-label,
    .pos-shell .theme-toggle-btn .tt-label { display: none; }
    .pos-shell .sidebar-brand { justify-content: center; padding-left: 0; padding-right: 0; }
    .pos-shell .nav-link, .pos-shell .foot-logout-btn { justify-content: center; gap: 0; }
    .pos-shell .sidebar-foot { justify-content: center; padding: 10px; }
    .pos-shell .theme-toggle-btn { justify-content: center; }
}
.timein-popup-card {
    max-width: 440px; width: min(100%, 440px); background: var(--white); border: 1px solid rgba(37,99,235,.14);
    border-radius: 22px; box-shadow: 0 24px 60px rgba(15,23,42,.24), 0 4px 14px rgba(37,99,235,.12);
    padding: 34px 32px 30px; text-align: center;
}
.timein-popup-icon {
    width: 60px; height: 60px; border-radius: 18px; background: linear-gradient(135deg,#eff6ff,#dbeafe); color: var(--green-deep);
    display: flex; align-items: center; justify-content: center; margin: 0 auto 14px;
    box-shadow: inset 0 0 0 1px rgba(37,99,235,.12);
}
.timein-popup-icon svg { width: 28px; height: 28px; }
.timein-popup-card h2 { font-family: var(--font-display); font-size: 21px; color: var(--ink); letter-spacing: -.02em; margin: 0 0 9px; }
.timein-popup-card p { color: var(--ink-soft); font-size: 14px; line-height: 1.55; margin: 0 0 8px; }
.timein-popup-shift { font-size: 13.5px; color: var(--ink-soft); margin: 14px 0 20px; padding: 10px 12px; border-radius: 11px; background: var(--green-pale); }
.timein-popup-shift strong { color: var(--ink); }
.timein-popup-btn {
    width: 100%; padding: 13px 14px; border: none; border-radius: 11px; background: linear-gradient(135deg,#2563eb,#1d4ed8);
    color: #FFFFFF; font-family: var(--font-body); font-size: 14.5px; font-weight: 700; cursor: pointer;
    box-shadow: 0 8px 18px rgba(37,99,235,.22);
}
.timein-popup-btn:hover { background: linear-gradient(135deg,#1d4ed8,#1e40af); transform: translateY(-1px); }
.timein-popup-btn:disabled { opacity: .6; cursor: not-allowed; }
.timein-popup-status { font-size: 12.5px; color: var(--ink-soft); margin-top: 12px; line-height: 1.5; padding: 9px 11px; border-radius: 10px; background: var(--cream); }
.timein-popup-status[style*="red"] { color: var(--red-alert) !important; background: var(--red-pale); border: 1px solid color-mix(in srgb, var(--red-alert) 20%, transparent); }
.timein-popup-tabs { display: flex; gap: 6px; margin: 16px 0 12px; }
.timein-popup-tab {
    flex: 1; padding: 8px 6px; border: 1px solid var(--line); background: var(--cream);
    border-radius: 8px; cursor: pointer; font-size: 12.5px; font-weight: 600; color: var(--ink-soft);
}
.timein-popup-tab.is-active { background: var(--green-deep); color: #FFFFFF; border-color: var(--green-deep); }
.timein-popup-tab-panel { display: none; text-align: left; }
.timein-popup-tab-panel.is-active { display: block; }
.timein-popup-label { display: block; font-size: 12.5px; font-weight: 600; color: var(--ink); margin: 10px 0 4px; }
.timein-popup-input { width: 100%; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; font-size: 13.5px; box-sizing: border-box; font-family: var(--font-body); }
@media (max-width: 520px) {
    .timein-popup-backdrop { padding: 14px; }
    .timein-popup-card { width: 100%; max-width: none; padding: 26px 20px 22px; border-radius: 18px; }
    .timein-popup-card h2 { font-size: 19px; }
    .timein-popup-card p { font-size: 13.5px; }
}
</style>
<div class="timein-popup-backdrop">
    <div class="timein-popup-card">
        <div class="timein-popup-icon">
            <svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.6"/><path d="M10 6v4l2.6 1.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <h2>Time In First</h2>
        <p>You need to Time In first before you can process orders today.</p>
        <?php if ($__todayShift): ?>
            <div class="timein-popup-shift">
                Today's shift: <strong><?= clean($__todayShift['shift_name']) ?></strong>
                (<?= clean(date('h:i A', strtotime($__todayShift['start_time']))) ?>–<?= clean(date('h:i A', strtotime($__todayShift['end_time']))) ?>)
            </div>
        <?php elseif ($__approvedSwap): ?>
            <div class="timein-popup-shift">Covering shift today (Shift Swap approved by HR)<?php if (!empty($__approvedSwap['shift_name'])): ?>: <strong><?= clean($__approvedSwap['shift_name']) ?></strong><?php endif; ?></div>
        <?php endif; ?>

        <?php if ($__isSuspended): ?>
            <button type="button" class="timein-popup-btn" disabled>Time In Disabled (Suspended)</button>
            <div class="timein-popup-status">
                You are currently suspended (<?= $__suspension['days_remaining'] ?> day<?= $__suspension['days_remaining'] == 1 ? '' : 's' ?> remaining, until <strong><?= clean(date('M d, Y', strtotime($__suspension['end_date']))) ?></strong>)<?php if (!empty($__suspension['reason'])): ?> — Reason: <?= clean($__suspension['reason']) ?><?php endif; ?>.
                Time In, Time In Requests, and Supervisor overrides are all disabled for the duration. Please contact your Admin or HR if you believe this is a mistake.
            </div>
        <?php elseif ($__canSelfTimeInNow): ?>
            <?php if ($__canTimeInLate): ?>
                <div class="timein-popup-status" style="color:var(--green-mid); margin-bottom:8px;">✅ Your Supervisor approved a Time In request for today — you can Time In now without a late deduction.</div>
            <?php endif; ?>
            <form method="POST" id="posSelfTimeInForm">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="self_time_in">
                <input type="hidden" name="latitude" id="posTiLatitude" value="">
                <input type="hidden" name="longitude" id="posTiLongitude" value="">
                <input type="hidden" name="location_accuracy" id="posTiAccuracy" value="">
                <input type="hidden" name="location_address" id="posTiAddress" value="">
                <button type="submit" id="posTimeInBtn" class="timein-popup-btn">Time In Now</button>
                <div id="posGeoStatus" class="timein-popup-status">📍 Your GPS location will be captured when you Time In. Please <strong>Allow</strong> location access in your browser.</div>
            </form>
        <?php else: ?>
            <?php if ($__shiftEnded): ?>
                <button type="button" class="timein-popup-btn" disabled>Time In Closed</button>
                <div class="timein-popup-status">Your shift has already ended for today without a Time In. Use one of the options below.</div>
            <?php else: ?>
                <button type="button" class="timein-popup-btn" disabled>Time In Closed</button>
                <div class="timein-popup-status">Time In will open at <strong><?= clean(date('h:i A', strtotime($__timeInOpensAt))) ?></strong> — please come back at that time, or use one of the options below.</div>
            <?php endif; ?>

            <?php $__reqOpen = (!$__lateRequestToday || $__lateRequestToday['status'] !== 'pending'); ?>
            <?php if ($__lateRequestToday && $__lateRequestToday['status'] === 'pending'): ?>
                <div class="timein-popup-status" style="margin-top:10px;">⏳ Time In request sent — waiting for your Supervisor to approve (either remotely, or right here via "Supervisor Time In Now").</div>
            <?php elseif ($__lateRequestToday && $__lateRequestToday['status'] === 'rejected'): ?>
                <div class="timein-popup-status" style="margin-top:10px;">Your last Time In request was rejected. You may send a new one below.</div>
            <?php endif; ?>

            <div class="timein-popup-tabs">
                <?php if ($__reqOpen): ?>
                    <button type="button" class="timein-popup-tab is-active" data-tab="posRequestTab">Request Time In</button>
                <?php endif; ?>
                <button type="button" class="timein-popup-tab<?= $__reqOpen ? '' : ' is-active' ?>" data-tab="posSupervisorTab">Supervisor Time In Now</button>
            </div>

            <?php if ($__reqOpen): ?>
            <div id="posRequestTab" class="timein-popup-tab-panel is-active">
                <form method="POST">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="request_late_timein">
                    <label class="timein-popup-label">Time you'd like to Time In</label>
                    <input type="time" name="requested_time" value="<?= clean(date('H:i')) ?>" required class="timein-popup-input">
                    <label class="timein-popup-label">Reason</label>
                    <textarea name="late_reason" rows="2" required minlength="3" class="timein-popup-input" placeholder="e.g. Overtime, traffic, personal emergency, etc."></textarea>
                    <button type="submit" class="timein-popup-btn" style="margin-top:10px;">Send Request</button>
                </form>
            </div>
            <?php endif; ?>

            <div id="posSupervisorTab" class="timein-popup-tab-panel<?= $__reqOpen ? '' : ' is-active' ?>">
                <p class="timein-popup-status" style="margin:0 0 8px;">For the Supervisor: enter your own HRMS login right here to Time In this cashier on the spot.</p>
                <form method="POST" id="posSupervisorTimeInForm">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="supervisor_timein_now">
                    <input type="hidden" name="latitude" id="posSupLatitude" value="">
                    <input type="hidden" name="longitude" id="posSupLongitude" value="">
                    <input type="hidden" name="location_accuracy" id="posSupAccuracy" value="">
                    <input type="hidden" name="location_address" id="posSupAddress" value="">
                    <label class="timein-popup-label">Supervisor Username</label>
                    <input type="text" name="supervisor_username" required class="timein-popup-input" autocomplete="off">
                    <label class="timein-popup-label">Supervisor Password</label>
                    <input type="password" name="supervisor_password" required class="timein-popup-input" autocomplete="off">
                    <button type="submit" id="posSupervisorTimeInBtn" class="timein-popup-btn" style="margin-top:10px;">Time In This Cashier Now</button>
                    <div id="posSupGeoStatus" class="timein-popup-status"></div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    var form = document.getElementById('posSelfTimeInForm');
    if (!form) return;
    var btn      = document.getElementById('posTimeInBtn');
    var statusEl = document.getElementById('posGeoStatus');

    function captureBestPosition(done, failed) {
        var best = null;
        var watchId = null;
        var finished = false;
        var timer = setTimeout(function () { finish(best); }, 15000);

        function finish(position) {
            if (finished) return;
            finished = true;
            clearTimeout(timer);
            if (watchId !== null) navigator.geolocation.clearWatch(watchId);
            if (position) done(position);
            else failed({ code: 2 });
        }

        watchId = navigator.geolocation.watchPosition(function (position) {
            if (!best || position.coords.accuracy < best.coords.accuracy) best = position;
            statusEl.textContent = 'Improving GPS accuracy… current estimate about ' + Math.round(best.coords.accuracy) + ' meters.';
            if (best.coords.accuracy <= 50) finish(best);
        }, function (error) {
            if (!best && error.code === error.PERMISSION_DENIED) finish(null);
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    }

    form.addEventListener('submit', function (e) {
        if (form.dataset.ready === '1') return;
        e.preventDefault();

        if (!navigator.geolocation) {
            statusEl.style.color = 'var(--red-alert)';
            statusEl.textContent = 'Location is not supported in this browser, so Time In cannot proceed.';
            return;
        }

        btn.disabled = true;
        statusEl.style.color = 'var(--ink-soft)';
        statusEl.textContent = 'Getting your location… please allow location access.';

        captureBestPosition(
            function (pos) {
                var lat = pos.coords.latitude, lng = pos.coords.longitude, accuracy = pos.coords.accuracy;
                document.getElementById('posTiLatitude').value  = lat;
                document.getElementById('posTiLongitude').value = lng;
                document.getElementById('posTiAccuracy').value = accuracy;

                function finishSubmit() {
                    form.dataset.ready = '1';
                    statusEl.style.color = 'var(--ink-soft)';
                    statusEl.textContent = 'GPS acquired (accuracy about ' + Math.round(accuracy) + ' meters). Timing in…';
                    form.submit();
                }

                var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' +
                          encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
                var controller = typeof AbortController === 'function' ? new AbortController() : null;
                var addressTimeout = controller ? setTimeout(function () { controller.abort(); }, 3000) : null;
                fetch(url, { headers: { 'Accept': 'application/json' }, signal: controller ? controller.signal : undefined })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (d && d.display_name) document.getElementById('posTiAddress').value = d.display_name; })
                    .catch(function () { /* offline / failed — just use coords */ })
                    .then(function () { if (addressTimeout) clearTimeout(addressTimeout); finishSubmit(); }, finishSubmit);
            },
            function (err) {
                btn.disabled = false;
                statusEl.style.color = 'var(--red-alert)';
                statusEl.textContent = (err && err.code === err.PERMISSION_DENIED)
                    ? 'Location (GPS) access is required before Time In. Please allow location in your browser and try again.'
                    : 'Could not get location. Make sure GPS/location is turned ON, then try again.';
            },
            
        );
    });
})();

// Request Time In / Supervisor Time In Now tabs
(function () {
    var tabs   = document.querySelectorAll('.timein-popup-tab');
    var panels = document.querySelectorAll('.timein-popup-tab-panel');
    if (!tabs.length) return;
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('is-active'); });
            panels.forEach(function (p) { p.classList.remove('is-active'); });
            tab.classList.add('is-active');
            var panel = document.getElementById(tab.dataset.tab);
            if (panel) panel.classList.add('is-active');
        });
    });
})();

// Supervisor Time In Now — capture GPS from this device (the
// cashier's terminal, being operated by the Supervisor) before
// submitting, same as a normal self Time In.
(function () {
    var supForm   = document.getElementById('posSupervisorTimeInForm');
    if (!supForm) return;
    var supBtn    = document.getElementById('posSupervisorTimeInBtn');
    var supStatus = document.getElementById('posSupGeoStatus');

    supForm.addEventListener('submit', function (e) {
        if (supForm.dataset.ready === '1') return;
        e.preventDefault();

        function submitNow() {
            supForm.dataset.ready = '1';
            supForm.submit();
        }

        if (!navigator.geolocation) { submitNow(); return; }

        supBtn.disabled = true;
        supStatus.textContent = 'Getting location…';
        navigator.geolocation.getCurrentPosition(
            function (pos) {
                document.getElementById('posSupLatitude').value  = pos.coords.latitude;
                document.getElementById('posSupLongitude').value = pos.coords.longitude;
                document.getElementById('posSupAccuracy').value = pos.coords.accuracy;
                var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' +
                          encodeURIComponent(pos.coords.latitude) + '&lon=' + encodeURIComponent(pos.coords.longitude);
                var controller = typeof AbortController === 'function' ? new AbortController() : null;
                var addressTimeout = controller ? setTimeout(function () { controller.abort(); }, 3000) : null;
                fetch(url, { headers: { 'Accept': 'application/json' }, signal: controller ? controller.signal : undefined })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (d && d.display_name) document.getElementById('posSupAddress').value = d.display_name; })
                    .catch(function () {})
                    .then(function () { if (addressTimeout) clearTimeout(addressTimeout); submitNow(); }, submitNow);
            },
            function () { submitNow(); }, // GPS denied — still allow the override, just without location
            { enableHighAccuracy: true, timeout: 8000 }
        );
    });
})();
<?php if (!empty($timein_flash_error)): ?>
Swal.fire({ icon: 'error', title: <?= json_encode(clean($timein_flash_error)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
<?php endif; ?>
</script>
<?php endif; ?>

<style>
.disc-approval-backdrop {
    display: none; position: fixed; top: 0; right: 0; bottom: 0; left: var(--sidebar-w);
    background: rgba(31,26,18,.55); backdrop-filter: blur(2px);
    align-items: center; justify-content: center; z-index: 9999; padding: 20px;
}
@media (max-width: 900px) { .disc-approval-backdrop { left: 0; } }
.disc-approval-backdrop.open { display: flex; }
.disc-approval-card {
    max-width: 400px; width: 100%; background: var(--white); border: 1.5px solid var(--line);
    border-radius: var(--radius-lg); box-shadow: var(--shadow-card); padding: 28px 26px; text-align: center;
}
.disc-approval-icon {
    width: 54px; height: 54px; border-radius: 50%; background: var(--mustard-pale, #FBEFD8); color: var(--mustard-text);
    display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; font-size: 24px;
}
.disc-approval-card h2 { font-family: var(--font-display); font-size: 18px; color: var(--ink); margin: 0 0 8px; }
.disc-approval-card p { color: var(--ink-soft); font-size: 13px; line-height: 1.5; margin: 0 0 6px; }
.disc-approval-input {
    width: 100%; padding: 12px; border: 1px solid var(--line); border-radius: 10px;
    font-size: 15px; font-weight: 500; text-align: left;
    box-sizing: border-box; font-family: var(--font-body); margin: 8px 0 0;
}
.disc-approval-input:focus { outline: none; border-color: var(--green-mid); }
.disc-approval-error { font-size: 12.5px; color: var(--red-alert); margin-top: 6px; line-height: 1.5; display: none; }
.disc-approval-btn {
    width: 100%; padding: 12px; border: none; border-radius: 10px; background: var(--green-mid);
    color: #FFFFFF; font-family: var(--font-body); font-size: 14.5px; font-weight: 600; cursor: pointer; margin-top: 10px;
}
.disc-approval-btn:hover { background: var(--green-deep); }
.disc-approval-btn-cancel { background: var(--cream); color: var(--ink-soft); }
.disc-approval-btn-cancel:hover { background: #ece5d6; }
</style>
<div class="disc-approval-backdrop" id="discApprovalBackdrop">
    <div class="disc-approval-card">
        <div class="disc-approval-icon">🔒</div>
        <h2>Supervisor Approval Required</h2>
        <p>Ask a Supervisor to enter their own username and password here to approve this discount.</p>
        <input type="text" id="discSupUsernameInput" class="disc-approval-input"
               placeholder="Supervisor username" autocomplete="off">
        <input type="password" id="discSupPasswordInput" class="disc-approval-input"
               placeholder="Supervisor password" autocomplete="off">
        <div id="discApprovalError" class="disc-approval-error"></div>
        <button type="button" class="disc-approval-btn" onclick="confirmDiscApproval()">Confirm</button>
        <button type="button" class="disc-approval-btn disc-approval-btn-cancel" onclick="closeDiscApprovalModal()">Cancel</button>
    </div>
</div>
<script>
function openDiscApprovalModal() {
    const idInput = document.getElementById('discIdNumberInput');
    if (idInput && idInput.value.trim() === '') {
        idInput.focus();
        idInput.reportValidity();
        return;
    }
    const userInput = document.getElementById('discSupUsernameInput');
    const passInput  = document.getElementById('discSupPasswordInput');
    const errorEl    = document.getElementById('discApprovalError');
    userInput.value = '';
    passInput.value = '';
    errorEl.style.display = 'none';
    document.getElementById('discApprovalBackdrop').classList.add('open');
    setTimeout(function () { userInput.focus(); }, 50);
}
function closeDiscApprovalModal() {
    document.getElementById('discApprovalBackdrop').classList.remove('open');
}
function confirmDiscApproval() {
    const userInput = document.getElementById('discSupUsernameInput');
    const passInput  = document.getElementById('discSupPasswordInput');
    const errorEl    = document.getElementById('discApprovalError');
    const username = userInput.value.trim();
    const password = passInput.value;

    if (username === '' || password === '') {
        errorEl.textContent = 'Enter the Supervisor\'s username and password.';
        errorEl.style.display = 'block';
        (username === '' ? userInput : passInput).focus();
        return;
    }

    document.getElementById('discSupUsernameHidden').value = username;
    document.getElementById('discSupPasswordHidden').value = password;
    document.getElementById('discApprovalBackdrop').classList.remove('open');
    document.getElementById('seniorPwdForm').submit();
}
</script>

<script>
(function () {
    const shell = document.querySelector('.pos-shell');
    const menuToggle = document.getElementById('posMenuToggle');
    const navBackdrop = document.getElementById('posNavBackdrop');
    const cartToggle = document.getElementById('posCartToggle');
    const cartBackdrop = document.getElementById('posCartBackdrop');
    const cartClose = document.getElementById('posCartClose');
    const cart = document.getElementById('posCart');
    if (!shell || !menuToggle || !navBackdrop || !cartToggle || !cartBackdrop || !cartClose || !cart) return;

    function setNavigationOpen(open) {
        shell.classList.toggle('mobile-nav-open', open);
        menuToggle.setAttribute('aria-expanded', String(open));
        menuToggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    }

    function setCartOpen(open) {
        shell.classList.toggle('mobile-cart-open', open);
        cartToggle.setAttribute('aria-expanded', String(open));
        cart.setAttribute('aria-hidden', String(!open && window.matchMedia('(max-width: 760px)').matches));
        cartBackdrop.setAttribute('aria-hidden', String(!open));
        if (open) cartClose.focus();
    }

    menuToggle.addEventListener('click', function () {
        setNavigationOpen(!shell.classList.contains('mobile-nav-open'));
    });
    navBackdrop.addEventListener('click', function () { setNavigationOpen(false); });
    cartToggle.addEventListener('click', function () {
        setCartOpen(!shell.classList.contains('mobile-cart-open'));
    });
    cartBackdrop.addEventListener('click', function () { setCartOpen(false); });
    cartClose.addEventListener('click', function () { setCartOpen(false); cartToggle.focus(); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setNavigationOpen(false);
            if (shell.classList.contains('mobile-cart-open')) {
                setCartOpen(false);
                cartToggle.focus();
            }
        }
    });
    window.addEventListener('resize', function () {
        if (window.matchMedia('(min-width: 761px)').matches) {
            shell.classList.remove('mobile-cart-open');
            cartToggle.setAttribute('aria-expanded', 'false');
        }
        cart.setAttribute('aria-hidden', String(
            window.matchMedia('(max-width: 760px)').matches &&
            !shell.classList.contains('mobile-cart-open')
        ));
        cartBackdrop.setAttribute('aria-hidden', String(!shell.classList.contains('mobile-cart-open')));
        if (window.matchMedia('(min-width: 1024px)').matches) setNavigationOpen(false);
    });
    cart.setAttribute('aria-hidden', String(window.matchMedia('(max-width: 760px)').matches));
    cartBackdrop.setAttribute('aria-hidden', 'true');
})();
</script>

<style>
    /* Keep POS visually identical to the shared cashier rail used by Shift Report. */
    .pos-shell { --sidebar-w:248px; grid-template-columns:var(--sidebar-w) 1fr var(--cart-w); }
    .pos-shell .sidebar { width:var(--sidebar-w); padding:24px 16px 18px; background:linear-gradient(180deg,#fff 0%,#f8fafc 100%); color:#1e293b; border-right:1px solid #e2e8f0; box-shadow:8px 0 26px rgba(15,23,42,.06); }
    .pos-shell .sidebar-brand { gap:12px; padding:0 8px 22px; margin-bottom:20px; border-bottom-color:#e2e8f0; }
    .pos-shell .brand-mark { width:44px; height:44px; border-radius:14px; background:linear-gradient(135deg,#1d4ed8,#0284c7); border:1px solid rgba(96,165,250,.3); box-shadow:0 10px 24px rgba(37,99,235,.18); }
    .pos-shell .brand-name { color:#0f172a; font-size:16px; } .pos-shell .brand-name span { color:#2563eb; }
    .pos-shell .brand-tagline,.pos-shell .nav-section-label { color:#64748b; }
    .pos-shell .nav-link { min-height:44px; padding:8px 11px; border:1px solid transparent; border-radius:13px; color:#334155; }
    .pos-shell .nav-icon-box { width:32px; height:32px; border-radius:10px; color:#2563eb; background:#eff6ff; border:1px solid #dbeafe; }
    .pos-shell .nav-link:hover { color:#1d4ed8; background:#f8fbff; border-color:#dbeafe; transform:translateX(3px); }
    .pos-shell .nav-link.is-active { color:#fff; background:linear-gradient(100deg,#2563eb,#38bdf8); border-color:rgba(96,165,250,.7); box-shadow:0 9px 22px rgba(37,99,235,.2); transform:translateX(2px); }
    .pos-shell .nav-link.is-active .nav-icon-box { color:#1d4ed8; background:rgba(255,255,255,.82); border-color:rgba(255,255,255,.55); }
    .pos-shell .sidebar-foot { padding:10px; border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; }
    .pos-shell .sidebar-foot .foot-name { color:#0f172a; font-size:12.5px; } .pos-shell .sidebar-foot .foot-role { color:#64748b; font-size:10.5px; }
    .pos-shell .theme-toggle-btn { border-color:#dbe3ef; border-radius:11px; background:#fff; color:#334155; }
    .pos-shell.sidebar-collapsed { grid-template-columns:var(--sidebar-w-collapsed) 1fr var(--cart-w); }
    html[data-theme="dark"] .pos-shell .sidebar { background:linear-gradient(180deg,#0b1530 0%,#080f24 58%,#070c1b 100%); color:#e8efff; border-right-color:rgba(126,155,255,.16); }
    html[data-theme="dark"] .pos-shell .brand-name { color:#e8efff; } html[data-theme="dark"] .pos-shell .brand-name span { color:#55d6ff; }
    html[data-theme="dark"] .pos-shell .nav-link { color:#91a0bd; } html[data-theme="dark"] .pos-shell .nav-icon-box { color:#91a9e7; background:rgba(125,151,220,.1); border-color:rgba(151,174,242,.1); }

    .pos-mobile-backdrop,.pos-cart-backdrop,.pos-mobile-menu,.pos-mobile-cart,.pos-cart-close { display:none; }
    .pos-topbar { min-width:0; }
    .pos-topbar h1 { letter-spacing:-.025em; }
    .pos-main button:focus-visible,.pos-main input:focus-visible,.pos-main a:focus-visible {
        outline:3px solid rgba(37,99,235,.32); outline-offset:2px;
    }
    .product-card { border-width:1px; border-radius:15px; box-shadow:0 2px 8px rgba(15,23,42,.035); }
    .product-card:hover { box-shadow:0 9px 24px rgba(37,99,235,.12); }
    .product-name { letter-spacing:-.01em; }
    .cart-header-actions { display:flex; align-items:center; gap:12px; }
    .pos-cart-close { width:36px; height:36px; align-items:center; justify-content:center; border:1px solid var(--line); border-radius:11px; color:var(--ink-soft); background:var(--white); font:600 24px/1 var(--font-body); cursor:pointer; }

    @media (max-width:1023px) {
        .pos-shell,.pos-shell.sidebar-collapsed {
            grid-template-columns:minmax(0,1fr) minmax(300px,36vw) !important;
            grid-template-rows:100dvh; height:100dvh;
        }
        .pos-shell .sidebar {
            position:fixed !important; inset:0 auto 0 0; width:min(300px,86vw) !important;
            min-width:0 !important; height:100dvh; padding:22px 16px 18px !important;
            z-index:9988 !important; transform:translateX(-105%) !important;
            transition:transform .28s cubic-bezier(.2,.8,.2,1),box-shadow .28s ease !important;
            box-shadow:16px 0 40px rgba(15,23,42,.18) !important;
        }
        .pos-shell.mobile-nav-open .sidebar { transform:translateX(0) !important; }
        .pos-mobile-backdrop {
            position:fixed; inset:0; z-index:9987; border:0; padding:0;
            background:rgba(15,23,42,.42); backdrop-filter:blur(2px);
        }
        .pos-shell.mobile-nav-open .pos-mobile-backdrop { display:block; }
        .pos-mobile-menu {
            display:inline-flex; flex:0 0 40px; width:40px; height:40px; align-items:center; justify-content:center;
            border:1px solid var(--line); border-radius:12px; color:var(--ink); background:var(--white); cursor:pointer;
        }
        .pos-mobile-menu svg { width:20px; height:20px; }
        .pos-shell .sidebar-toggle { display:none !important; }
        .pos-shell .brand-text,.pos-shell .nav-label,.pos-shell .nav-section-label,
        .pos-shell .theme-toggle-btn .tt-label { display:initial !important; }
        .pos-shell .sidebar-foot .foot-text { display:flex !important; }
        .pos-shell .sidebar-brand { justify-content:flex-start !important; }
        .pos-shell .nav-link,.pos-shell .foot-logout-btn { justify-content:flex-start !important; gap:10px !important; }
        .pos-shell .sidebar-foot,.pos-shell .theme-toggle-btn { justify-content:flex-start !important; }
        .pos-shell.sidebar-collapsed .sidebar-brand {
            width:auto !important; height:auto !important; min-height:0 !important; margin:0 0 20px !important;
            padding:0 8px 22px !important; justify-content:flex-start !important; gap:12px !important;
            border-bottom:1px solid #e2e8f0 !important;
        }
        .pos-shell.sidebar-collapsed .sidebar-brand .brand-mark { width:44px !important; height:44px !important; margin:0 !important; }
        .pos-shell.sidebar-collapsed .brand-text,.pos-shell.sidebar-collapsed .nav-label,
        .pos-shell.sidebar-collapsed .nav-section-label,.pos-shell.sidebar-collapsed .theme-toggle-btn .tt-label {
            display:initial !important;
        }
        .pos-shell.sidebar-collapsed .sidebar-foot .foot-text { display:flex !important; }
        .pos-shell.sidebar-collapsed .nav-link,.pos-shell.sidebar-collapsed .foot-logout-btn { justify-content:flex-start !important; gap:10px !important; }
        .pos-shell.sidebar-collapsed .sidebar-foot,.pos-shell.sidebar-collapsed .theme-toggle-btn { justify-content:flex-start !important; }
        .pos-shell .pos-main { min-width:0; }
        .pos-shell .pos-cart { min-width:0; }
    }
    @media (max-width:760px) {
        html,body { height:100%; overflow:hidden; }
        .pos-shell,.pos-shell.sidebar-collapsed {
            display:block; height:100dvh; min-height:0; overflow:hidden;
        }
        .pos-shell .pos-main { width:100%; height:100dvh; min-height:0; }
        .pos-topbar { min-height:64px; gap:10px; padding:11px 14px; }
        .pos-topbar h1 { min-width:0; font-size:17px; }
        .topbar-icon { width:34px; height:34px; border-radius:10px; }
        .cashier-name { display:none; }
        .pos-mobile-cart {
            display:inline-flex; flex:0 0 auto; min-height:40px; align-items:center; justify-content:center; gap:6px;
            padding:0 10px; border:1px solid #cfe0ff; border-radius:12px; color:#1d4ed8;
            background:#eff6ff; font:700 12px/1 var(--font-body); cursor:pointer;
        }
        .pos-mobile-cart svg { width:16px; height:16px; }
        .pos-mobile-cart-count { min-width:19px; height:19px; display:inline-flex; align-items:center; justify-content:center; padding:0 5px; border-radius:10px; background:#2563eb; color:#fff; font-size:10px; }
        .pos-filters { gap:8px; padding:11px 12px; }
        .search-input { min-width:100%; min-height:42px; border-radius:11px; }
        .cat-tabs { max-width:100%; flex-wrap:nowrap; overflow-x:auto; padding:1px 0 4px; scrollbar-width:thin; }
        .cat-tab { padding:7px 11px; }
        .product-grid-wrap { min-height:0; padding:12px 12px 18px; }
        .product-grid { grid-template-columns:repeat(auto-fill,minmax(128px,1fr)); gap:10px; }
        .product-info { padding:9px; }
        .product-name { font-size:12px; }
        .product-price { font-size:13px; }
        .pos-cart {
            position:fixed; z-index:9989; inset:auto 0 0; width:100%; height:min(82dvh,760px); max-height:82dvh;
            border:1px solid var(--line); border-bottom:0; border-radius:22px 22px 0 0;
            box-shadow:0 -18px 50px rgba(15,23,42,.2); transform:translateY(105%);
            visibility:hidden; pointer-events:none;
            transition:transform .28s cubic-bezier(.2,.8,.2,1),visibility .28s;
        }
        .pos-shell.mobile-cart-open .pos-cart { transform:translateY(0); visibility:visible; pointer-events:auto; }
        .pos-cart-backdrop {
            position:fixed; inset:0; z-index:9988; border:0; padding:0;
            background:rgba(15,23,42,.44); backdrop-filter:blur(2px);
        }
        .pos-shell.mobile-cart-open .pos-cart-backdrop { display:block; }
        .pos-cart-close { display:inline-flex; }
        .cart-header { padding:11px 16px 9px; }
        .cart-tabs { flex-shrink:0; }
        .cart-footer { padding:12px 16px max(14px,env(safe-area-inset-bottom)); }
        .cart-items,.history-list { overscroll-behavior:contain; }
    }
    @media (prefers-reduced-motion:reduce) {
        .pos-shell .sidebar,.pos-shell .pos-cart,.pos-mobile-menu,.pos-mobile-cart { transition:none !important; }
    }
    </style>
</body>
</html>