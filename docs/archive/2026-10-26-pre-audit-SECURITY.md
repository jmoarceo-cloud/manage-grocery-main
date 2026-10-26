> Historical draft preserved before the 2026-10-26 audit correction.
> Its schema, security, runtime and legal-compliance claims are unverified or
> contradicted by the current audit. Do not use it for implementation,
> operations, retention decisions or release evidence. Read [the audit](../AUDIT.md)
> and the current [document](../SECURITY.md) instead.

# Manage-Grocery Security Specification & Hardening

This document establishes the defensive controls, cryptography standards, session policies, and upload hardening rules implemented across Manage-Grocery.

---

## 1. Authentication & Two-Factor Authentication (MFA)

### Password & Credential Hashing
- Password hashes are generated and verified exclusively using PHP's native `password_hash()` and `password_verify()` with `PASSWORD_DEFAULT` (Bcrypt, work factor 12).
- Plaintext passwords and legacy MD5/SHA-1 hashes are prohibited.

### Time-based One-Time Passwords (TOTP MFA)
- Implemented via RFC 6238 standard (`app/Middleware/TwoFactorMiddleware.php` and `includes/twofactor.php`).
- Mandatory policy for Manager and Administrator accounts; optional/policy-gated for Cashier and Employee roles.
- MFA secret keys are stored per user in `users.two_factor_secret`; single-use recovery codes are cryptographically generated and hashed upon provisioning.

---

## 2. Session Management & Fixation Protection

- **Cookie Security Attributes**:
  - `session.cookie_httponly = 1`: Prohibits client-side scripts from reading session cookies.
  - `session.cookie_samesite = 'Lax'`: Mitigates cross-site request forgery attacks on top-level navigations.
  - `session.cookie_secure`: Enforced when running over HTTPS.
- **Session Fixation Defense**:
  - `session_regenerate_id(true)` is executed upon every authentication state change (login, privilege escalation, role change).
  - Idle sessions expire after 30 minutes of inactivity; absolute timeout occurs after 8 hours.

---

## 3. SQL Injection (SQLi) Defense

- **Primary Defense**: Exclusive reliance on PDO prepared statements with explicit parameter binding. Direct SQL string concatenation is strictly prohibited:
  ```php
  // Compliant pattern:
  $stmt = $pdo->prepare("SELECT id, name, price, stock_quantity FROM products WHERE barcode = :barcode AND status = 'active'");
  $stmt->execute(['barcode' => $sanitizedBarcode]);
  $product = $stmt->fetch();
  ```
- **Dynamic Filtering & Ordering**: Column sorting in reports and export queries is validated against an immutable whitelist of allowed database column identifiers before query construction.

---

## 4. Cross-Site Scripting (XSS) Prevention

- All dynamic data rendered into HTML contexts is escaped using `htmlspecialchars($data, ENT_QUOTES, 'UTF-8')`.
- Global template helper `e($string)` is enforced across all views in `app/Views/`.
- Content Security Policy (CSP) headers restrict script execution and disallow inline script evaluation where possible.

---

## 5. Cross-Site Request Forgery (CSRF) Defense

- State-changing HTTP verbs (`POST`, `PUT`, `DELETE`) are verified by `CsrfMiddleware`.
- Each authenticated session maintains a cryptographically secure 32-byte pseudo-random token (`bin2hex(random_bytes(32))`).
- View forms embed the token via hidden input:
  ```html
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
  ```
- Requests failing token verification are rejected with an HTTP 419 Page Expired response and logged to security audit files.

---

## 6. File Upload Hardening & Storage Architecture

- **Storage Location**: Uploaded files (product photos, supplier delivery receipts, applicant resumes) are stored strictly outside the web document root in `storage/uploads/`.
- **MIME & Magic-Byte Verification**: Uploaded file types are inspected using PHP's `finfo` extension (`finfo_file(FILEINFO_MIME_TYPE)`), rejecting spoofed extensions.
- **Filename Randomization**: Uploaded files are renamed using SHA-256 random hashes; original client filenames are never used on disk.
- **Script Execution Prohibition**: Direct script execution in storage folders is blocked by `.htaccess`:
  ```apache
  RemoveHandler .php .phtml .php3 .php4 .php5 .cgi .pl
  php_flag engine off
  Options -Indexes -ExecCGI
  ```
- **Authorized Download Proxy**: Private files (receipts, resumes) are served exclusively via authenticated controller streaming (`/storage/download?file=...`). Direct URL linking to physical disk paths is disallowed.

