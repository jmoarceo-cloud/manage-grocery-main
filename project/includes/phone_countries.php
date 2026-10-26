<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// ============================================================
// includes/phone_countries.php
// ------------------------------------------------------------
// Shared "Contact Number" field: a country dial-code selector
// paired with a number-only input that is validated (both in
// the browser and on the server) to be EXACTLY the number of
// digits that country's numbers use — no missing digits, no
// extra digits, letters/symbols stripped as the user types.
//
// Stored format in the database: "+<dial> <digits>", e.g.
// "+63 9171234567". Legacy rows that only have raw digits (no
// "+") are treated as Philippine numbers when re-opened for
// editing, so old data still displays sensibly.
//
// Required directly by both includes/config.php (POS) and
// HRMS/includes/config.php (via ../../includes/phone_countries.php)
// so suppliers, employees, and job applicants all share the exact
// same country list and validation rules.
// ============================================================

if (!defined('PHONE_COUNTRIES')) {

define('PHONE_COUNTRIES', [
    'PH' => ['name' => 'Philippines',            'dial' => '63',  'length' => 10, 'example' => '9171234567'],
    'US' => ['name' => 'United States / Canada',  'dial' => '1',   'length' => 10, 'example' => '2025551234'],
    'GB' => ['name' => 'United Kingdom',           'dial' => '44',  'length' => 10, 'example' => '7911123456'],
    'AU' => ['name' => 'Australia',                'dial' => '61',  'length' => 9,  'example' => '412345678'],
    'JP' => ['name' => 'Japan',                    'dial' => '81',  'length' => 10, 'example' => '9012345678'],
    'KR' => ['name' => 'South Korea',              'dial' => '82',  'length' => 10, 'example' => '1012345678'],
    'CN' => ['name' => 'China',                    'dial' => '86',  'length' => 11, 'example' => '13800138000'],
    'HK' => ['name' => 'Hong Kong',                'dial' => '852', 'length' => 8,  'example' => '51234567'],
    'SG' => ['name' => 'Singapore',                'dial' => '65',  'length' => 8,  'example' => '81234567'],
    'MY' => ['name' => 'Malaysia',                 'dial' => '60',  'length' => 9,  'example' => '123456789'],
    'ID' => ['name' => 'Indonesia',                'dial' => '62',  'length' => 10, 'example' => '8123456789'],
    'TH' => ['name' => 'Thailand',                 'dial' => '66',  'length' => 9,  'example' => '812345678'],
    'VN' => ['name' => 'Vietnam',                  'dial' => '84',  'length' => 9,  'example' => '912345678'],
    'IN' => ['name' => 'India',                    'dial' => '91',  'length' => 10, 'example' => '9123456789'],
    'AE' => ['name' => 'United Arab Emirates',     'dial' => '971', 'length' => 9,  'example' => '501234567'],
    'SA' => ['name' => 'Saudi Arabia',             'dial' => '966', 'length' => 9,  'example' => '512345678'],
    'QA' => ['name' => 'Qatar',                    'dial' => '974', 'length' => 8,  'example' => '33123456'],
    'KW' => ['name' => 'Kuwait',                   'dial' => '965', 'length' => 8,  'example' => '50123456'],
    'BH' => ['name' => 'Bahrain',                  'dial' => '973', 'length' => 8,  'example' => '36001234'],
    'OM' => ['name' => 'Oman',                     'dial' => '968', 'length' => 8,  'example' => '92123456'],
    'DE' => ['name' => 'Germany',                  'dial' => '49',  'length' => 10, 'example' => '15123456789'],
    'FR' => ['name' => 'France',                   'dial' => '33',  'length' => 9,  'example' => '612345678'],
    'IT' => ['name' => 'Italy',                    'dial' => '39',  'length' => 10, 'example' => '3123456789'],
    'ES' => ['name' => 'Spain',                    'dial' => '34',  'length' => 9,  'example' => '612345678'],
    'NL' => ['name' => 'Netherlands',              'dial' => '31',  'length' => 9,  'example' => '612345678'],
    'NZ' => ['name' => 'New Zealand',              'dial' => '64',  'length' => 9,  'example' => '211234567'],
    'BR' => ['name' => 'Brazil',                   'dial' => '55',  'length' => 11, 'example' => '11912345678'],
    'MX' => ['name' => 'Mexico',                   'dial' => '52',  'length' => 10, 'example' => '5512345678'],
    'ZA' => ['name' => 'South Africa',             'dial' => '27',  'length' => 9,  'example' => '821234567'],
    'NG' => ['name' => 'Nigeria',                  'dial' => '234', 'length' => 10, 'example' => '8021234567'],
    'EG' => ['name' => 'Egypt',                    'dial' => '20',  'length' => 10, 'example' => '1012345678'],
    'TW' => ['name' => 'Taiwan',                   'dial' => '886', 'length' => 9,  'example' => '912345678'],
]);

/**
 * Split a stored value like "+63 9171234567" back into
 * ['PH', '9171234567'] so an edit form can preselect the right
 * country. Longest dial codes are matched first so "+1..." isn't
 * mistaken for a country whose code merely starts with "1".
 * Legacy digits-only values (no leading "+") are assumed PH.
 */
function parseStoredPhone(?string $stored): array {
    $stored = trim((string) $stored);
    if ($stored === '') {
        return ['PH', ''];
    }
    if ($stored[0] === '+') {
        $digits = preg_replace('/[^0-9]/', '', $stored);
        $dials = [];
        foreach (PHONE_COUNTRIES as $iso => $c) {
            $dials[$iso] = $c['dial'];
        }
        uasort($dials, function ($a, $b) { return strlen($b) <=> strlen($a); });
        foreach ($dials as $iso => $dial) {
            if (strpos($digits, $dial) === 0) {
                return [$iso, substr($digits, strlen($dial))];
            }
        }
    }
    return ['PH', preg_replace('/[^0-9]/', '', $stored)];
}

/** Build the "+<dial> <digits>" string to save in the database. */
function formatPhoneForStorage(string $iso, string $number): string {
    $iso = strtoupper($iso);
    $countries = PHONE_COUNTRIES;
    $digits = preg_replace('/[^0-9]/', '', $number);
    if ($digits === '') {
        return '';
    }
    $dial = $countries[$iso]['dial'] ?? $countries['PH']['dial'];
    return '+' . $dial . ' ' . $digits;
}

/**
 * Returns null when valid, or an error message string when not.
 * An empty number is treated as "not provided" — callers that
 * require the field should check for emptiness separately.
 */
function validatePhoneNumber(string $iso, string $number): ?string {
    $iso = strtoupper($iso);
    $countries = PHONE_COUNTRIES;
    $digits = preg_replace('/[^0-9]/', '', $number);
    if ($digits === '') {
        return null;
    }
    if (!isset($countries[$iso])) {
        return "Please select a valid country for the contact number.";
    }
    $needed = $countries[$iso]['length'];
    if (strlen($digits) !== $needed) {
        return "Contact number for {$countries[$iso]['name']} must be exactly {$needed} digits — numbers only, no missing or extra digits.";
    }
    return null;
}

/**
 * Render the <select> (name = "{$name}_country") + <input>
 * (name = "{$name}") pair. $storedValue is the raw DB value
 * (already in "+63 ..." form, or a legacy digits-only value).
 * $inputClass/$selectClass let each page match its own existing
 * input styling; wrap the call in the page's normal field-group
 * markup.
 */
/** Returns the raw `<option>` markup for the country dropdown (used by
 * renderPhoneFieldInputs() and by pages that build phone rows in JS,
 * like apply.php's "+ Add another reference"). */
function phoneCountryOptionsHtml(string $selectedIso = 'PH'): string {
    $countries = PHONE_COUNTRIES;
    if (!isset($countries[$selectedIso])) {
        $selectedIso = 'PH';
    }
    $html = '';
    foreach ($countries as $iso => $c) {
        $html .= '<option value="' . $iso . '" data-length="' . $c['length'] . '" data-example="' . htmlspecialchars($c['example']) . '" ' . ($iso === $selectedIso ? 'selected' : '') . '>+' . $c['dial'] . ' ' . htmlspecialchars($c['name']) . '</option>';
    }
    return $html;
}

function renderPhoneFieldInputs(string $name, ?string $storedValue, bool $required = false, string $inputClass = '', string $selectClass = ''): void {
    [$selIso, $selNumber] = parseStoredPhone($storedValue);
    $countries = PHONE_COUNTRIES;
    if (!isset($countries[$selIso])) {
        $selIso = 'PH';
    }
    ?>
    <div class="phone-field-row" style="display:flex; gap:8px; flex-wrap:wrap;">
        <select name="<?= htmlspecialchars($name) ?>_country" class="phone-country-select <?= htmlspecialchars($selectClass) ?>" style="flex:0 0 auto;">
            <?= phoneCountryOptionsHtml($selIso) ?>
        </select>
        <input type="tel" name="<?= htmlspecialchars($name) ?>" class="phone-number-input <?= htmlspecialchars($inputClass) ?>"
               value="<?= htmlspecialchars($selNumber) ?>" inputmode="numeric"
               maxlength="<?= $countries[$selIso]['length'] ?>"
               placeholder="<?= htmlspecialchars($countries[$selIso]['example']) ?>"
               style="flex:1 1 160px;"
               <?= $required ? 'required' : '' ?>>
    </div>
    <div style="font-size:11.5px; color:var(--ink-soft,#3D4C5C); margin-top:4px;">
        Numbers only. Must be exactly <span class="phone-length-hint"><?= $countries[$selIso]['length'] ?></span> digits for the selected country.
    </div>
    <?php
}

/**
 * Emits the JS that (a) strips non-digits as the user types/pastes
 * and truncates to the exact required length, so it's never
 * possible to type more digits than the selected country allows,
 * and (b) keeps maxlength/placeholder in sync when the country is
 * changed. Call this ONCE per page, after rendering all phone
 * fields (renderPhoneFieldInputs can be called many times on the
 * same page — the script wires up every ".phone-country-select" +
 * adjacent ".phone-number-input" pair it finds).
 */
function renderPhoneFieldScript(): void {
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    ?>
    <script>
    (function () {
        // Event delegation (not per-element listeners) so this also
        // works for phone fields added dynamically after page load —
        // e.g. the "+ Add another reference" rows on the job
        // application form.
        function findPair(el) {
            var row = el.closest('.phone-field-row');
            if (!row) return null;
            var select = row.querySelector('.phone-country-select');
            var input  = row.querySelector('.phone-number-input');
            return (select && input) ? { select: select, input: input, row: row } : null;
        }
        function applyRules(select, input) {
            var opt = select.options[select.selectedIndex];
            var len = parseInt(opt.getAttribute('data-length'), 10) || 15;
            input.setAttribute('maxlength', len);
            input.setAttribute('placeholder', opt.getAttribute('data-example') || '');
            if (input.value.length > len) {
                input.value = input.value.slice(0, len);
            }
            var row = select.closest('.phone-field-row');
            var hint = row && row.parentElement ? row.parentElement.querySelector('.phone-length-hint') : null;
            if (hint) hint.textContent = len;
        }
        document.addEventListener('change', function (e) {
            if (!e.target.classList || !e.target.classList.contains('phone-country-select')) return;
            var pair = findPair(e.target);
            if (pair) applyRules(pair.select, pair.input);
        });
        document.addEventListener('input', function (e) {
            if (!e.target.classList || !e.target.classList.contains('phone-number-input')) return;
            var pair = findPair(e.target);
            if (!pair) return;
            var opt = pair.select.options[pair.select.selectedIndex];
            var len = parseInt(opt.getAttribute('data-length'), 10) || 15;
            var digitsOnly = e.target.value.replace(/[^0-9]/g, '').slice(0, len);
            if (digitsOnly !== e.target.value) e.target.value = digitsOnly;
        });
        // Initialize whatever phone fields already exist on the page.
        document.querySelectorAll('.phone-country-select').forEach(function (select) {
            var pair = findPair(select);
            if (pair) applyRules(pair.select, pair.input);
        });
    })();
    </script>
    <?php
}

} // end PHONE_COUNTRIES guard
