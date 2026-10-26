<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}


define('MAPPED_FIELD_KEYS', ['position_applied', 'contact_number', 'cover_note']);


function getActiveFormFields($pdo) {
    return $pdo->query("
        SELECT * FROM application_form_fields
        WHERE is_active = 1
        ORDER BY sort_order ASC, field_id ASC
    ")->fetchAll();
}


function getAllFormFields($pdo) {
    return $pdo->query("
        SELECT * FROM application_form_fields
        ORDER BY sort_order ASC, field_id ASC
    ")->fetchAll();
}

function generateFieldKey($pdo, $label) {
    $base = strtolower(trim($label));
    $base = preg_replace('/[^a-z0-9]+/', '_', $base);
    $base = trim($base, '_');
    if ($base === '') {
        $base = 'field';
    }
    $base = substr($base, 0, 40);

    $key = $base;
    $suffix = 1;
    while (true) {
        $stmt = $pdo->prepare("SELECT field_id FROM application_form_fields WHERE field_key = ?");
        $stmt->execute([$key]);
        if (!$stmt->fetch()) {
            return $key;
        }
        $suffix++;
        $key = $base . '_' . $suffix;
    }
}

function renderFormFieldInput($field, $oldValue = '') {
    $name        = 'cf_' . clean($field['field_key']);
    $required    = $field['is_required'] ? 'required' : '';
    $placeholder = clean($field['placeholder'] ?? '');

    // Checkbox fields post as an array (cf_key[]); ibang fields ay string.
    $oldArrRaw = is_array($oldValue) ? array_map('strval', $oldValue) : explode(',', (string) $oldValue);
    $oldValue  = is_array($oldValue) ? '' : clean($oldValue);

    switch ($field['field_type']) {

        case 'textarea':
            echo "<textarea name=\"$name\" placeholder=\"$placeholder\" $required>$oldValue</textarea>";
            break;

        case 'select':
            $choices = array_filter(array_map('trim', explode(',', (string) $field['options'])));
            echo "<select name=\"$name\" $required>";
            echo '<option value="">-- Choose --</option>';
            foreach ($choices as $choice) {
                $cClean   = clean($choice);
                $selected = ($oldValue === $cClean) ? 'selected' : '';
                echo "<option value=\"$cClean\" $selected>$cClean</option>";
            }
            echo '</select>';
            break;

        case 'checkbox':
            $choices = array_filter(array_map('trim', explode(',', (string) $field['options'])));
            $oldArr  = array_map('trim', $oldArrRaw);
            echo '<div class="checkbox-group">';
            foreach ($choices as $choice) {
                $cClean  = clean($choice);
                $checked = in_array($choice, $oldArr) ? 'checked' : '';
                echo "<label class=\"checkbox-choice\"><input type=\"checkbox\" name=\"{$name}[]\" value=\"$cClean\" $checked> $cClean</label>";
            }
            echo '</div>';
            break;

        case 'tel':
            if ($field['field_key'] === 'contact_number') {
                // The applicant's own Contact Number gets the shared
                // country-selector + exact-digit-length widget instead of
                // the plain PH-only pattern used by other 'tel' fields.
                renderPhoneFieldInputs($name, is_string($oldValue) ? $oldValue : '', (bool) $field['is_required']);
            } else {
                echo "<input type=\"tel\" name=\"$name\" value=\"$oldValue\" placeholder=\"$placeholder\" inputmode=\"numeric\" maxlength=\"13\" pattern=\"(09\\d{9}|\\+639\\d{9})?\" title=\"PH mobile number, e.g. 09171234567\" oninput=\"this.value=this.value.replace(/[^0-9+]/g,'')\" onpaste=\"setTimeout(()=>{this.value=this.value.replace(/[^0-9+]/g,'')},0)\" $required>";
            }
            break;

        case 'number':
            echo "<input type=\"number\" name=\"$name\" value=\"$oldValue\" placeholder=\"$placeholder\" $required>";
            break;

        case 'date':
            echo "<input type=\"date\" name=\"$name\" value=\"$oldValue\" $required>";
            break;

        case 'email':
            echo "<input type=\"email\" name=\"$name\" value=\"$oldValue\" placeholder=\"$placeholder\" $required>";
            break;

        case 'text':
        default:
            echo "<input type=\"text\" name=\"$name\" value=\"$oldValue\" placeholder=\"$placeholder\" $required>";
            break;
    }
}

function validateFormFields($fields, $post) {
    $errors = [];
    $mapped = [];
    $custom = [];

    foreach ($fields as $field) {
        $name    = 'cf_' . $field['field_key'];
        $isCheck = $field['field_type'] === 'checkbox';
        $raw     = $isCheck ? ($post[$name] ?? []) : trim($post[$name] ?? '');
        $value   = $isCheck ? implode(', ', array_map('trim', (array) $raw)) : $raw;

        if ($field['field_key'] === 'contact_number') {
            // Country-aware Contact Number: validate the exact digit
            // length for the chosen country, then store as "+<dial> ...".
            $country = trim($post[$name . '_country'] ?? 'PH');
            if ($field['is_required'] && $value === '') {
                $errors[] = clean($field['label']) . ' is required to be completed.';
                continue;
            }
            $phoneErr = validatePhoneNumber($country, $value);
            if ($phoneErr !== null) {
                $errors[] = clean($field['label']) . ': ' . $phoneErr;
                continue;
            }
            $mapped[$field['field_key']] = $value !== '' ? formatPhoneForStorage($country, $value) : null;
            continue;
        }

        if ($field['is_required'] && $value === '') {
            $errors[] = clean($field['label']) . 'is required to be completed.';
            continue;
        }

        if ($value !== '') {
            if ($field['field_type'] === 'tel' && !preg_match('/^(09\d{9}|\+639\d{9})$/', $value)) {
                $errors[] = clean($field['label']) . ': Must be a valid mobile number (e.g., 09171234567).';
                continue;
            }
            if ($field['field_type'] === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = clean($field['label']) . ': Must be a valid email address.';
                continue;
            }
            if ($field['field_type'] === 'number' && !is_numeric($value)) {
                $errors[] = clean($field['label']) . ': nnly numeric values are allowed.';
                continue;
            }
        }

        if (in_array($field['field_key'], MAPPED_FIELD_KEYS)) {
            $mapped[$field['field_key']] = $value ?: null;
        } else {
            $custom[$field['field_key']] = $value;
        }
    }

    return ['errors' => $errors, 'mapped' => $mapped, 'custom' => $custom];
}

function formatCustomResponses($customResponsesJson, $fieldLabelMap) {
    if (empty($customResponsesJson)) {
        return [];
    }
    $data = json_decode($customResponsesJson, true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $label = $fieldLabelMap[$key] ?? ucwords(str_replace('_', ' ', $key));
        $out[] = ['label' => $label, 'value' => $value];
    }
    return $out;
}

function getFieldLabelMap($pdo) {
    $rows = $pdo->query("SELECT field_key, label FROM application_form_fields")->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[$r['field_key']] = $r['label'];
    }
    return $map;
}