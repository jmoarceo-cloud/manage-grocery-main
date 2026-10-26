<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// =====================================================
// ID_CRYPT — encrypts/obscures numeric database IDs before they are
// ever put into a URL query string (hal. ?id=482, ?employee_id=17),
// para hindi plain/guessable na numero ang lumalabas sa address bar.
// ------------------------------------------------------
// Bakit ito importante: kung plain integer lang (?id=482) ang nasa
// URL, kahit sino ay puwedeng basta-basta magpalit ng numero
// (482 -> 483, 484, ...) at subukang tingnan/i-edit ang record ng
// IBANG tao — tinatawag itong IDOR (Insecure Direct Object
// Reference). Sa pag-encrypt ng ID bago ito ilagay sa URL, hindi na
// mahuhulaan o mababago ng user ang token para tumukoy sa ibang
// record — kailangan pa ring dumaan sa normal na access-control
// check ng bawat page (requireLogin/requireAdmin/atbp.) bilang
// pangalawang linya ng depensa, pero wala nang "just guess the next
// number" na madaling paraan.
//
//   enc_id($id)    -> opaque, URL-safe authenticated token (hal. "v2.N2x9Qk...")
//                      Random ang nonce kada tawag, kaya magkaiba ang
//                      token kahit paulit-ulit mong i-encrypt ang
//                      parehong ID. AES-GCM also rejects tampered tokens.
//   dec_id($token) -> ibinabalik ang orihinal na integer, o 0 kung
//                      wala/mali/na-tampered ang token (parehong
//                      pakikitunguhan ng mga pages dati sa
//                      "walang laman/0" na $_GET['id']).
//
// GINAGAMIT ITO NG PAREHONG:
//   - POS side:  includes/config.php (require_once dito mismo, same folder)
//   - HRMS side: HRMS/includes/config.php (require_once pataas dalawang
//                folder papunta rito, para IISANG key lang laging
//                ginagamit — kaya kahit saang app nanggaling ang
//                token, pareho itong madi-decrypt ng dalawang app).
// =====================================================

if (!defined('ID_CRYPT_KEY')) {
    // Keep this key in the environment so it is not exposed in source
    // control. The same key must be used by POS and HRMS.
    $idCryptSecret = function_exists('env') ? env('ID_CRYPT_KEY', '') : '';
    if ($idCryptSecret === '') {
        $idCryptSecret = getenv('ID_CRYPT_KEY') ?: '';
    }
    if ($idCryptSecret === '') {
        throw new RuntimeException('ID_CRYPT_KEY is not configured.');
    }
    define('ID_CRYPT_KEY', hash('sha256', $idCryptSecret, true));
}

// I-encrypt ang isang numeric ID papunta sa isang opaque, URL-safe
// token. Ligtas itong ilagay diretso sa loob ng href="...?id=..." —
// wala nang kailangang urlencode() pa dahil base64url ang ginamit
// (walang +, /, o = na character).
function enc_id($id): string
{
    $id = (int) $id;
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt((string) $id, 'aes-256-gcm', ID_CRYPT_KEY, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false || strlen($tag) !== 16) {
        return '';
    }
    // Version + nonce + authentication tag + ciphertext, base64url-encoded.
    return 'v2.' . rtrim(strtr(base64_encode($nonce . $tag . $cipher), '+/', '-_'), '=');
}

// Kabaligtaran ng enc_id() — ibinabalik ang orihinal na integer ID.
// 0 ang ibinabalik (hindi exception) kapag walang laman, mali ang
// format, o na-tamper/nasira ang token — kaya ligtas itong gamitin
// nang direkta bilang kapalit ng dating (int) ($_GET['id'] ?? 0).
function dec_id($token): int
{
    $token = (string) $token;
    if ($token === '')
        return 0;

    if (strncmp($token, 'v2.', 3) === 0) {
        $encoded = substr($token, 3);
        $padded = $encoded . str_repeat('=', (4 - (strlen($encoded) % 4)) % 4);
        $raw = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($raw === false || strlen($raw) <= 28)
            return 0;

        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', ID_CRYPT_KEY, OPENSSL_RAW_DATA, $nonce, $tag);
    } else {
        // Accept pre-v2 CBC links during migration; all newly emitted IDs use GCM.
        $padded = $token . str_repeat('=', (4 - (strlen($token) % 4)) % 4);
        $raw = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($raw === false || strlen($raw) <= 16)
            return 0;

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', ID_CRYPT_KEY, OPENSSL_RAW_DATA, $iv);
    }

    if ($plain === false || !ctype_digit($plain))
        return 0;
    return (int) $plain;
}

// Convenience — kunin agad ang decrypted ID mula sa $_GET (o $_POST)
// gamit ang isang key, may default na 0 kapag wala. Ginagamit sa mga
// page bilang: $id = get_enc_id($_GET, 'id');
function get_enc_id(array $source, string $key): int
{
    return dec_id($source[$key] ?? '');
}