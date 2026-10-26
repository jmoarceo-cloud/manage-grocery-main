<?php
require_once __DIR__ . '/includes/config.php';
$returnKey = (string) ($_GET['return'] ?? '');
$isCatalogKioskRequest = $returnKey === 'catalog';
$hasPrivateCatalogAccess = !empty($_SESSION['catalog_private_kiosk'])
    && !empty($_SESSION['catalog_private_authorized'])
    && (int) ($_SESSION['catalog_private_granted_at'] ?? 0) > time() - 1800;
if ($isCatalogKioskRequest && !$hasPrivateCatalogAccess) {
    unset($_SESSION['catalog_private_authorized'], $_SESSION['catalog_private_granted_at']);
    redirect('catalog_supervisor_login.php?embedded=1');
}
if (!$isCatalogKioskRequest) {
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'inventory_staff', 'cashier', 'supervisor'], true)) {
        redirectToRoleHome();
    }
} else {
    $_SESSION['catalog_private_granted_at'] = time();
}
require_once __DIR__ . '/includes/public_bootstrap.php';
?>
<?php
$returnTargets = [
    'admin' => 'dashboard.php',
    'inventory' => 'inventory_dashboard.php',
    'cashier' => 'pos.php',
];
$scanReturnKey = isset($returnTargets[$returnKey]) ? $returnKey : '';
$returnTarget = $returnTargets[$returnKey] ?? 'catalog.php';
if ($isCatalogKioskRequest && $hasPrivateCatalogAccess) {
    $returnTarget = 'catalog.php?private=1';
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Scan barcode</title>
<?php include __DIR__ . '/includes/public_theme.php'; ?>
<?php if (!$isCatalogKioskRequest && !empty($_SESSION['user_id'])): ?>
<?php
$__scannerName = trim((string) ($_SESSION['full_name'] ?? ''));
$__scannerRole = function_exists('roleLabel')
    ? roleLabel((string) ($_SESSION['role'] ?? ''))
    : ucfirst(str_replace('_', ' ', (string) ($_SESSION['role'] ?? 'User')));
$__scannerAvatar = $_SESSION['profile_picture'] ?? null;
if (
    !is_string($__scannerAvatar)
    || !preg_match('/^[A-Za-z0-9._-]+$/', $__scannerAvatar)
    || !is_file(__DIR__ . '/uploads/avatars/' . $__scannerAvatar)
) {
    $__scannerAvatar = null;
}
$__scannerTopbarConfig = [
    'name' => $__scannerName !== '' ? $__scannerName : 'User',
    'role' => $__scannerRole,
    'initial' => function_exists('mb_strtoupper') ? (mb_strtoupper(mb_substr($__scannerName, 0, 1)) ?: '?') : (strtoupper(substr($__scannerName, 0, 1)) ?: '?'),
    'avatarUrl' => $__scannerAvatar
        ? systemUrl('uploads/avatars/' . rawurlencode(basename($__scannerAvatar)))
        : '',
    'showNotifications' => in_array($_SESSION['role'] ?? '', ['admin', 'inventory_staff', 'cashier'], true),
    'notificationUnreadCount' => in_array($_SESSION['role'] ?? '', ['admin', 'inventory_staff', 'cashier'], true)
        ? getVisibleUnreadNotificationCount($pdo, (string) ($_SESSION['role'] ?? ''), currentPosUserId())
        : 0,
    'notificationsUrl' => systemUrl('notifications.php'),
    'notificationsLabel' => 'POS notifications',
    'profileUrl' => ($_SESSION['role'] ?? '') === 'admin'
        ? systemUrl('HRMS/admin_profile.php')
        : systemUrl('my_profile.php'),
    'logoutUrl' => systemUrl('logout.php'),
];
$__scannerTopbarCss = __DIR__ . '/assets/css/topbar.css';
$__scannerTopbarJs = __DIR__ . '/assets/js/shared-topbar.js';
?>
<link rel="stylesheet" href="<?= htmlspecialchars(systemUrl('assets/css/topbar.css') . '?v=' . (filemtime($__scannerTopbarCss) ?: time()), ENT_QUOTES, 'UTF-8') ?>">
<script type="application/json" id="sharedTopbarConfig"><?= json_encode($__scannerTopbarConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars(systemUrl('assets/js/shared-topbar.js') . '?v=' . (filemtime($__scannerTopbarJs) ?: time()), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<style>
:root { --surface:var(--public-card); --border:var(--public-line); --accent:var(--public-accent); --text-heading:var(--public-heading); --text-body:var(--public-ink); --text-muted:var(--public-muted); --font-display:'Fraunces',Georgia,serif; }
.scanner-page { min-height:100vh; padding-bottom:42px; }
.scanner-shell { max-width:760px; margin:0 auto; padding:0 24px; }
.scanner-nav { position:sticky; top:0; z-index:5; display:flex; align-items:center; justify-content:space-between; gap:16px; min-height:74px; margin:0 calc(50% - 50vw); padding:0 clamp(18px,5vw,72px); background:color-mix(in srgb,var(--surface) 92%,transparent); border-bottom:1px solid var(--border); box-shadow:0 2px 10px rgba(15,23,42,.05); backdrop-filter:blur(16px); }
.scanner-nav-left { display:flex; align-items:center; gap:16px; min-width:0; }
.scanner-nav a { color:var(--text-body); font-weight:700; }
.scanner-back { display:inline-flex; align-items:center; gap:6px; height:40px; padding:0 15px; border:1px solid var(--accent); border-radius:12px; background:#fff; color:var(--text-body); font-size:.8125rem; font-weight:700; white-space:nowrap; transition:.2s var(--ease); box-shadow:0 2px 8px rgba(15,23,42,.06); }
.scanner-back:hover,
.scanner-back:focus-visible { background:var(--accent); color:#fff; border-color:var(--accent); transform:translateY(-1px); box-shadow:0 3px 10px color-mix(in srgb,var(--accent) 22%,transparent); }
.scanner-back svg { width:15px; height:15px; flex-shrink:0; }
.scanner-divider { width:1px; height:24px; background:var(--border); }
.scanner-brand { display:flex; align-items:center; gap:10px; }
.scanner-logo { width:38px; height:38px; display:grid; place-items:center; border-radius:11px; background:linear-gradient(135deg,var(--accent),#0284c7); box-shadow:0 6px 16px color-mix(in srgb,var(--accent) 22%,transparent); }
.scanner-logo svg { width:22px; height:22px; display:block; }
.scanner-brand-name { font-family:var(--font-display); font-size:1.0625rem; font-weight:700; color:var(--text-heading); letter-spacing:-.01em; }
.scanner-brand-name span { color:var(--accent); }
.scanner-nav-title { position:absolute; left:50%; transform:translateX(-50%); color:var(--accent); font-size:.9rem; font-weight:800; white-space:nowrap; }
.scanner-card { margin-top:24px; padding:clamp(22px,5vw,42px); background:var(--surface); border:1px solid var(--border); border-radius:22px; box-shadow:0 18px 50px rgba(15,23,42,.08); text-align:center; }
.scanner-card h1 { margin:0; color:var(--text-heading); font-size:clamp(1.7rem,4vw,2.4rem); }
.scanner-intro { color:var(--text-muted); margin:10px 0 24px; }
.scanner-stage { position:relative; min-height:clamp(340px,58vh,560px); display:grid; place-items:center; overflow:hidden; border-radius:22px; background:#0b1220; box-shadow:inset 0 0 0 1px rgba(255,255,255,.12), 0 16px 34px rgba(15,23,42,.18); }
.scanner-stage #reader { width:100%; min-height:340px; }
.scanner-stage #reader video { display:block; width:100%; min-height:340px; aspect-ratio:16/9; object-fit:contain; background:#0b1220; }
.scanner-controls { display:flex; justify-content:center; align-items:center; flex-wrap:wrap; gap:12px; margin-top:18px; }
.scanner-controls label { color:var(--text-body); font-size:.875rem; font-weight:700; }
.scanner-controls select, .scanner-controls button, .scanner-upload { min-height:42px; padding:0 14px; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text-body); font:inherit; }
.scanner-controls button, .scanner-upload { display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-weight:700; }
.scanner-controls button:hover, .scanner-upload:hover { border-color:var(--accent); color:var(--accent); }
.scanner-controls button:disabled { opacity:.55; cursor:not-allowed; }
.scanner-controls input[type="range"] { width:min(160px,40vw); accent-color:var(--accent); }
.scanner-controls [hidden] { display:none !important; }
.scanner-upload input { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; clip-path:inset(50%); }
.scanner-stage::before { content:""; position:absolute; z-index:2; width:90%; height:75%; border:3px solid #22c55e; border-radius:18px; box-shadow:0 0 0 999px rgba(2,8,23,.22),0 0 18px rgba(34,197,94,.5); pointer-events:none; }
.scanner-stage::after { content:"Place barcode or QR in the frame"; position:absolute; z-index:3; bottom:18px; left:50%; transform:translateX(-50%); color:#fff; background:rgba(2,8,23,.72); border-radius:999px; padding:8px 14px; font-size:.78rem; font-weight:700; white-space:nowrap; pointer-events:none; }
.scanner-stage.is-detected { animation:scanner-success-flash .3s ease-out; }
.scanner-stage.is-detected::before { border-color:#86efac; }
.scanner-stage.is-detected::after { content:"Detected"; background:#15803d; }
@keyframes scanner-success-flash { 0% { background-color:rgba(34,197,94,.4); } 100% { background-color:#0b1220; } }
.scanner-error { min-height:24px; color:#b42318; margin:12px 0 0; }
@media (max-width:560px) { .scanner-shell{padding:0 14px}.scanner-nav{padding:0 14px;min-height:68px}.scanner-nav-left{gap:12px}.scanner-back{font-size:0;padding:0 12px}.scanner-back svg{width:17px;height:17px}.scanner-brand-name{font-size:.95rem}.scanner-nav-title{display:none}.scanner-card{margin-top:18px;padding:20px 14px}.scanner-stage{min-height:420px}.scanner-stage::after{font-size:.7rem;bottom:14px} }
</style></head>
<body class="public-page"><main class="scanner-page"><div class="scanner-shell"><nav class="scanner-nav"><div class="scanner-nav-left"><a class="scanner-back" href="<?= public_escape($returnTarget) ?>"><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M12 4l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span><?= $returnTarget === 'catalog.php' ? 'Back to Catalog' : 'Back to dashboard' ?></span></a><div class="scanner-divider"></div><div class="scanner-brand"><div class="scanner-logo"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="#ffffff"/><path d="M10 18h28v4a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2v-4z" fill="#dbeafe"/><path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/></svg></div><span class="scanner-brand-name">Manage <span>Grocery</span></span></div></div><span class="scanner-nav-title">Scan QR / Barcode</span></nav><section class="scanner-card"><h1>Scan a QR code or barcode</h1><p class="scanner-intro" id="status">Starting camera…</p><div class="scanner-stage"><div id="reader"></div></div><div class="scanner-controls"><label for="camera">Camera</label><select id="camera" aria-label="Choose camera"></select><button id="restart" type="button">Restart camera</button><button id="torch" type="button" disabled>Flashlight unavailable</button><label for="zoom" id="zoom-label" hidden>Zoom</label><input id="zoom" type="range" min="1" max="1" step="0.1" value="1" aria-label="Camera zoom" hidden><label class="scanner-upload">Scan an image<input id="image-file" type="file" accept="image/*" capture="environment"></label></div><p class="scanner-error" id="error" role="status" aria-live="polite"></p><p class="scanner-intro">Image scans automatically try rotations and contrast enhancement. For live scans, keep the code in view and avoid glare.</p></section></div></main>
<script src="assets/js/html5-qrcode.min.js"></script>
<script>
(function () {
  var status = document.getElementById('status');
  var error = document.getElementById('error');
  var cameraSelect = document.getElementById('camera');
  var imageInput = document.getElementById('image-file');
  var torchButton = document.getElementById('torch');
  var zoomInput = document.getElementById('zoom');
  var zoomLabel = document.getElementById('zoom-label');
  var scanReturnKey = <?= json_encode($scanReturnKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  var locked = false;
  var torchEnabled = false;
  var liveDecodeTimer = null;
  var liveDecodeBusy = false;
  var liveDecodeGeneration = 0;
  var liveDecoderUnavailable = false;
  var formats = [
    Html5QrcodeSupportedFormats.QR_CODE,
    Html5QrcodeSupportedFormats.AZTEC,
    Html5QrcodeSupportedFormats.CODABAR,
    Html5QrcodeSupportedFormats.CODE_39,
    Html5QrcodeSupportedFormats.CODE_93,
    Html5QrcodeSupportedFormats.CODE_128,
    Html5QrcodeSupportedFormats.DATA_MATRIX,
    Html5QrcodeSupportedFormats.MAXICODE,
    Html5QrcodeSupportedFormats.ITF,
    Html5QrcodeSupportedFormats.PDF_417,
    Html5QrcodeSupportedFormats.RSS_14,
    Html5QrcodeSupportedFormats.RSS_EXPANDED,
    Html5QrcodeSupportedFormats.EAN_8,
    Html5QrcodeSupportedFormats.EAN_13,
    Html5QrcodeSupportedFormats.UPC_A,
    Html5QrcodeSupportedFormats.UPC_E,
    Html5QrcodeSupportedFormats.UPC_EAN_EXTENSION
  ];
  var reader = new Html5Qrcode('reader', { formatsToSupport: formats, verbose: false });
  var lastScannedCode = '';
  var lastScannedAt = 0;

  function extractGs1ProductCode(value) {
    var scanned = String(value || '').trim();
    var digitalLink = scanned.match(/(?:^|\/)01\/(\d{14})(?:\/|[?#]|$)/);
    if (digitalLink) return digitalLink[1];
    var gs1 = scanned.match(/(?:\(01\)|(?:^|\x1d)01)(\d{14})/);
    if (gs1) return gs1[1];
    var namedPathCode = scanned.match(/(?:^|\/)(?:gtin|ean|barcode)\/(\d{8,14})(?:\/|[?#]|$)/i);
    if (namedPathCode) return namedPathCode[1];
    try {
      var url = new URL(scanned);
      var codeParameters = ['gtin', 'ean', 'barcode', '01'];
      for (var index = 0; index < codeParameters.length; index++) {
        var parameterCode = url.searchParams.get(codeParameters[index]);
        if (parameterCode && /^\d{8,14}$/.test(parameterCode)) return parameterCode;
      }
    } catch (e) {
      if (!(e instanceof TypeError)) throw e;
    }
    return '';
  }

  function routeResult(value) {
    var scanned = String(value || '').trim();
    if (!scanned) return '';
    try {
      var url = new URL(scanned);
      var scanPath = new URL('scan.php', window.location.href);
      var token = url.searchParams.get('id');
      if (url.origin === window.location.origin && url.pathname === scanPath.pathname
          && token && /^[A-Za-z0-9_-]{20,128}$/.test(token)
          && url.searchParams.getAll('id').length === 1
          && Array.from(url.searchParams.keys()).every(function (key) { return key === 'id'; })) {
        return scanPath.pathname + '?id=' + encodeURIComponent(token);
      }
    } catch (e) {
      if (!(e instanceof TypeError)) throw e;
    }
    var gs1Code = extractGs1ProductCode(scanned);
    if (gs1Code) return 'scan.php?b=' + encodeURIComponent(gs1Code);
    if (/^[0-9A-Za-z._-]{3,64}$/.test(scanned)) {
      return 'scan.php?b=' + encodeURIComponent(scanned);
    }
    return '';
  }

  function found(value) {
    if (locked) return;
    var scanned = String(value || '').trim();
    var now = Date.now();
    if (scanned === lastScannedCode && now - lastScannedAt < 2000) return;
    lastScannedCode = scanned;
    lastScannedAt = now;
    var destination = routeResult(value);
    if (!destination) {
      error.textContent = 'This code is not a supported barcode or a product QR from this site. Keep scanning or choose another image.';
      return;
    }
    if (scanReturnKey) {
      var destinationUrl = new URL(destination, window.location.href);
      destinationUrl.searchParams.set('return', scanReturnKey);
      destination = destinationUrl.pathname + destinationUrl.search;
    }
    locked = true;
    status.textContent = 'Detected: ' + String(value || '').trim() + '. Opening product…';
    error.textContent = '';
    document.querySelector('.scanner-stage').classList.add('is-detected');
    if (typeof navigator.vibrate === 'function') {
      navigator.vibrate([70, 50, 70]);
    }
    function openDestination() {
      window.setTimeout(function () { window.location.href = destination; }, 650);
    }
    if (reader.isScanning) {
      reader.stop().then(openDestination).catch(function (reason) {
        error.textContent = 'The code was detected, but the camera did not stop cleanly. ' + cameraError(reason);
        openDestination();
      });
    } else {
      openDestination();
    }
  }

  function cameraError(reason) {
    var message = String(reason && reason.message ? reason.message : reason || '');
    if (/NotAllowedError|Permission denied|Permission dismissed/i.test(message)) {
      return 'Camera access was denied. Allow camera permission for this site in your browser settings, then restart the camera.';
    }
    if (/NotFoundError|no camera|No cameras/i.test(message)) {
      return 'No camera was found. Connect or enable a webcam/phone-camera app, then restart the camera.';
    }
    if (/NotReadableError|Could not start video source|in use/i.test(message)) {
      return 'The selected camera could not start. Close other apps using it or choose another camera.';
    }
    return 'The camera could not start. Check browser camera permission and choose a different camera if available.';
  }

  function updateTorchAvailability() {
    var capabilities = reader.getRunningTrackCapabilities();
    var supported = !!capabilities && !!capabilities.torch;
    torchButton.disabled = !supported;
    torchButton.textContent = supported ? (torchEnabled ? 'Turn flashlight off' : 'Turn flashlight on') : 'Flashlight unavailable';
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

  function startCamera(cameraId, preserveStatus) {
    if (locked) return;
    stopLiveFrameDecoding();
    liveDecoderUnavailable = false;
    if (!preserveStatus) {
      error.textContent = '';
      status.textContent = 'Starting camera…';
    }
    var camera = cameraId || cameraSelect.value;
    var source = camera || { facingMode: 'environment' };
    var videoConstraints = {
      width: { ideal: 1920 },
      height: { ideal: 1080 },
      focusMode: { ideal: 'continuous' }
    };
    if (camera) {
      videoConstraints.deviceId = { exact: camera };
    } else {
      videoConstraints.facingMode = { ideal: 'environment' };
    }
    reader.start(source, {
      fps: 20,
      aspectRatio: 16 / 9,
      qrbox: function (viewfinderWidth, viewfinderHeight) {
        return {
          width: Math.floor(viewfinderWidth * 0.9),
          height: Math.floor(viewfinderHeight * 0.75)
        };
      },
      videoConstraints: videoConstraints
    }, found, function () {})
      .then(function () {
        updateTorchAvailability();
        startLiveFrameDecoding();
        if (!preserveStatus) status.textContent = 'Scanning the framed camera area… Hold the code in the green frame.';
      })
      .catch(function (reason) { status.textContent = 'Camera unavailable. You can choose another camera or scan an image.'; error.textContent = cameraError(reason); });
  }

  function canvasFile(canvas, name) {
    return new Promise(function (resolve, reject) {
      canvas.toBlob(function (blob) {
        if (!blob) {
          reject(new Error('Could not prepare image for scanning.'));
          return;
        }
        resolve(new File([blob], name, { type: 'image/png' }));
      }, 'image/png');
    });
  }

  function loadImage(file) {
    function loadHtmlImage() {
      return new Promise(function (resolve, reject) {
        var objectUrl = URL.createObjectURL(file);
        var image = new Image();
        image.onload = function () {
          image.scannerObjectUrl = objectUrl;
          resolve(image);
        };
        image.onerror = function () {
          URL.revokeObjectURL(objectUrl);
          reject(new Error('The selected image could not be opened by this browser.'));
        };
        image.src = objectUrl;
      });
    }

    if (typeof createImageBitmap !== 'function') return loadHtmlImage();
    return createImageBitmap(file).catch(loadHtmlImage);
  }

  function readWithZxingWasm(file) {
    var fallbackModuleUrl = new URL('assets/js/zxing-image-fallback.js', window.location.href).href;
    return import(fallbackModuleUrl).then(function (module) {
      return module.readBarcodeImageWithZXing(file);
    });
  }

  function stopLiveFrameDecoding() {
    liveDecodeGeneration++;
    if (liveDecodeTimer !== null) {
      window.clearTimeout(liveDecodeTimer);
      liveDecodeTimer = null;
    }
  }

  function canvasBlob(canvas) {
    return new Promise(function (resolve, reject) {
      canvas.toBlob(function (blob) {
        if (blob) resolve(blob);
        else reject(new Error('Could not prepare a camera frame for enhanced scanning.'));
      }, 'image/jpeg', 0.92);
    });
  }

  function decodeLiveCameraFrame(video) {
    if (!video.videoWidth || !video.videoHeight) return Promise.resolve(false);
    var cropWidth = Math.max(1, Math.round(video.videoWidth * 0.9));
    var cropHeight = Math.max(1, Math.round(video.videoHeight * 0.75));
    var scale = Math.min(1.5, 1920 / Math.max(cropWidth, cropHeight));
    var frame = document.createElement('canvas');
    frame.width = Math.max(1, Math.round(cropWidth * scale));
    frame.height = Math.max(1, Math.round(cropHeight * scale));
    var context = frame.getContext('2d', { willReadFrequently: true });
    if (!context) return Promise.resolve(false);
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    context.drawImage(
      video,
      Math.floor((video.videoWidth - cropWidth) / 2),
      Math.floor((video.videoHeight - cropHeight) / 2),
      cropWidth,
      cropHeight,
      0,
      0,
      frame.width,
      frame.height
    );

    var barcode = decodeEan13FromCanvas(frame);
    if (barcode) {
      found(barcode);
      return Promise.resolve(locked);
    }

    var enhanced = document.createElement('canvas');
    enhanced.width = frame.width;
    enhanced.height = frame.height;
    var enhancedContext = enhanced.getContext('2d', { willReadFrequently: true });
    if (!enhancedContext) return Promise.resolve(false);
    enhancedContext.filter = 'grayscale(1) contrast(1.7) brightness(1.08)';
    enhancedContext.drawImage(frame, 0, 0);
    barcode = decodeEan13FromCanvas(enhanced);
    if (barcode) {
      found(barcode);
      return Promise.resolve(locked);
    }

    return Promise.all([canvasBlob(frame), canvasBlob(enhanced)]).then(function (blobs) {
      return readWithZxingWasm(blobs[0]).then(function (results) {
        var result = results.find(function (item) { return item.isValid && item.text; });
        if (result) return result.text;
        return readWithZxingWasm(blobs[1]).then(function (enhancedResults) {
          var enhancedResult = enhancedResults.find(function (item) { return item.isValid && item.text; });
          return enhancedResult ? enhancedResult.text : '';
        });
      });
    }).then(function (value) {
      if (value) found(value);
      return locked;
    });
  }

  function startLiveFrameDecoding() {
    stopLiveFrameDecoding();
    var generation = liveDecodeGeneration;
    function scanFrame() {
      if (generation !== liveDecodeGeneration || !reader.isScanning || locked || liveDecoderUnavailable) return;
      var video = document.querySelector('#reader video');
      if (!video || liveDecodeBusy) {
        liveDecodeTimer = window.setTimeout(scanFrame, 300);
        return;
      }
      liveDecodeBusy = true;
      decodeLiveCameraFrame(video).catch(function (reason) {
        if (!liveDecoderUnavailable) {
          liveDecoderUnavailable = true;
          error.textContent = 'Enhanced live scanning could not start. Standard camera scanning is still active. ' + String(reason && reason.message ? reason.message : reason);
        }
      }).then(function () {
        liveDecodeBusy = false;
        if (generation === liveDecodeGeneration && reader.isScanning && !locked && !liveDecoderUnavailable) {
          liveDecodeTimer = window.setTimeout(scanFrame, 750);
        }
      });
    }
    liveDecodeTimer = window.setTimeout(scanFrame, 350);
  }

  function makeBinaryVariant(sourceCanvas) {
    var sourceContext = sourceCanvas.getContext('2d', { willReadFrequently: true });
    if (!sourceContext) return null;
    var imageData = sourceContext.getImageData(0, 0, sourceCanvas.width, sourceCanvas.height);
    var pixels = imageData.data;
    var histogram = new Array(256).fill(0);
    var pixelCount = sourceCanvas.width * sourceCanvas.height;
    var weightedTotal = 0;
    for (var pixel = 0; pixel < pixels.length; pixel += 4) {
      var luminance = Math.round(pixels[pixel] * 0.299 + pixels[pixel + 1] * 0.587 + pixels[pixel + 2] * 0.114);
      pixels[pixel] = pixels[pixel + 1] = pixels[pixel + 2] = luminance;
      histogram[luminance]++;
      weightedTotal += luminance;
    }
    var backgroundWeight = 0;
    var backgroundTotal = 0;
    var bestVariance = -1;
    var threshold = 128;
    for (var level = 0; level < 256; level++) {
      backgroundWeight += histogram[level];
      if (!backgroundWeight) continue;
      var foregroundWeight = pixelCount - backgroundWeight;
      if (!foregroundWeight) break;
      backgroundTotal += level * histogram[level];
      var backgroundMean = backgroundTotal / backgroundWeight;
      var foregroundMean = (weightedTotal - backgroundTotal) / foregroundWeight;
      var variance = backgroundWeight * foregroundWeight * Math.pow(backgroundMean - foregroundMean, 2);
      if (variance > bestVariance) {
        bestVariance = variance;
        threshold = level;
      }
    }
    for (var index = 0; index < pixels.length; index += 4) {
      var value = pixels[index] > threshold ? 255 : 0;
      pixels[index] = pixels[index + 1] = pixels[index + 2] = value;
    }
    var binaryCanvas = document.createElement('canvas');
    binaryCanvas.width = sourceCanvas.width;
    binaryCanvas.height = sourceCanvas.height;
    var binaryContext = binaryCanvas.getContext('2d');
    if (!binaryContext) return null;
    binaryContext.putImageData(imageData, 0, 0);
    return binaryCanvas;
  }

  function decodeEan13FromCanvas(canvas) {
    var context = canvas.getContext('2d', { willReadFrequently: true });
    if (!context) return '';
    var imageData = context.getImageData(0, 0, canvas.width, canvas.height).data;
    var histogram = new Array(256).fill(0);
    var luminance = new Uint8Array(canvas.width * canvas.height);
    for (var pixel = 0, offset = 0; pixel < luminance.length; pixel++, offset += 4) {
      var value = Math.round(imageData[offset] * 0.299 + imageData[offset + 1] * 0.587 + imageData[offset + 2] * 0.114);
      luminance[pixel] = value;
      histogram[value]++;
    }
    var total = luminance.length;
    var weightedTotal = 0;
    for (var level = 0; level < 256; level++) weightedTotal += level * histogram[level];
    var backgroundWeight = 0;
    var backgroundTotal = 0;
    var bestVariance = -1;
    var threshold = 128;
    for (var thresholdLevel = 0; thresholdLevel < 256; thresholdLevel++) {
      backgroundWeight += histogram[thresholdLevel];
      if (!backgroundWeight) continue;
      var foregroundWeight = total - backgroundWeight;
      if (!foregroundWeight) break;
      backgroundTotal += thresholdLevel * histogram[thresholdLevel];
      var meanBackground = backgroundTotal / backgroundWeight;
      var meanForeground = (weightedTotal - backgroundTotal) / foregroundWeight;
      var variance = backgroundWeight * foregroundWeight * Math.pow(meanBackground - meanForeground, 2);
      if (variance > bestVariance) {
        bestVariance = variance;
        threshold = thresholdLevel;
      }
    }

    var leftOdd = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
    var leftEven = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
    var right = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];
    var parity = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];
    var rowFractions = [0.34, 0.4, 0.46, 0.52, 0.58, 0.64, 0.7];

    for (var rowIndex = 0; rowIndex < rowFractions.length; rowIndex++) {
      var row = Math.min(canvas.height - 1, Math.floor(canvas.height * rowFractions[rowIndex]));
      var runs = [];
      var previous = luminance[row * canvas.width] <= threshold;
      var runStart = 0;
      for (var x = 1; x <= canvas.width; x++) {
        var black = x < canvas.width && luminance[row * canvas.width + x] <= threshold;
        if (black === previous && x < canvas.width) continue;
        runs.push({ black: previous, start: runStart, width: x - runStart });
        previous = black;
        runStart = x;
      }

      for (var runIndex = 0; runIndex + 2 < runs.length; runIndex++) {
        var first = runs[runIndex];
        var second = runs[runIndex + 1];
        var third = runs[runIndex + 2];
        if (!first.black || second.black || !third.black) continue;
        var moduleWidth = (first.width + second.width + third.width) / 3;
        if (moduleWidth < 0.75 || Math.max(first.width, second.width, third.width) > moduleWidth * 1.65
            || Math.min(first.width, second.width, third.width) < moduleWidth * 0.45) continue;
        if (first.start + moduleWidth * 95 > canvas.width) continue;

        var bits = '';
        for (var module = 0; module < 95; module++) {
          var sampleX = Math.min(canvas.width - 1, Math.floor(first.start + (module + 0.5) * moduleWidth));
          bits += luminance[row * canvas.width + sampleX] <= threshold ? '1' : '0';
        }
        if (bits.slice(0, 3) !== '101' || bits.slice(45, 50) !== '01010' || bits.slice(92) !== '101') continue;

        var parityBits = '';
        var digits = '';
        var valid = true;
        for (var leftPosition = 0; leftPosition < 6; leftPosition++) {
          var leftPattern = bits.slice(3 + leftPosition * 7, 10 + leftPosition * 7);
          var oddDigit = leftOdd.indexOf(leftPattern);
          var evenDigit = leftEven.indexOf(leftPattern);
          if (oddDigit >= 0) {
            parityBits += 'L';
            digits += String(oddDigit);
          } else if (evenDigit >= 0) {
            parityBits += 'G';
            digits += String(evenDigit);
          } else {
            valid = false;
            break;
          }
        }
        var leadingDigit = parity.indexOf(parityBits);
        if (!valid || leadingDigit < 0) continue;
        digits = String(leadingDigit) + digits;
        for (var rightPosition = 0; rightPosition < 6; rightPosition++) {
          var rightPattern = bits.slice(50 + rightPosition * 7, 57 + rightPosition * 7);
          var rightDigit = right.indexOf(rightPattern);
          if (rightDigit < 0) {
            valid = false;
            break;
          }
          digits += String(rightDigit);
        }
        if (!valid || digits.length !== 13) continue;
        var checksum = 0;
        for (var digitPosition = 0; digitPosition < 12; digitPosition++) {
          checksum += Number(digits[digitPosition]) * (digitPosition % 2 === 0 ? 1 : 3);
        }
        if ((10 - checksum % 10) % 10 === Number(digits[12])) return digits;
      }
    }
    return '';
  }

  function makeImageVariants(file) {
    return loadImage(file).then(function (image) {
      var maxDimension = 2400;
      var scale = Math.min(4, maxDimension / Math.max(image.width, image.height));
      var width = Math.max(1, Math.round(image.width * scale));
      var height = Math.max(1, Math.round(image.height * scale));
      var variants = [];
      var manualBarcode = '';
      var rotations = [0, 90, 180, 270];
      var crops = [1, 0.72, 0.5];
      variants.push(Promise.resolve(file));

      crops.forEach(function (cropFraction) {
        rotations.forEach(function (degrees) {
          var quarterTurn = degrees === 90 || degrees === 270;
          var cropWidth = Math.max(1, Math.round(width * cropFraction));
          var cropHeight = Math.max(1, Math.round(height * cropFraction));
          var canvas = document.createElement('canvas');
          canvas.width = quarterTurn ? cropHeight : cropWidth;
          canvas.height = quarterTurn ? cropWidth : cropHeight;
          var context = canvas.getContext('2d', { willReadFrequently: true });
          if (!context) return;
          context.imageSmoothingEnabled = true;
          context.imageSmoothingQuality = 'high';
          context.save();
          context.translate(canvas.width / 2, canvas.height / 2);
          context.rotate(degrees * Math.PI / 180);
          context.filter = 'grayscale(1) contrast(1.7) brightness(1.08)';
          context.drawImage(
            image,
            Math.floor((image.width - cropWidth / scale) / 2),
            Math.floor((image.height - cropHeight / scale) / 2),
            cropWidth / scale,
            cropHeight / scale,
            -cropWidth / 2,
            -cropHeight / 2,
            cropWidth,
            cropHeight
          );
          context.restore();
          if (!manualBarcode) manualBarcode = decodeEan13FromCanvas(canvas);
          variants.push(canvasFile(canvas, 'scan-' + cropFraction + '-' + degrees + '.png'));
          if (cropFraction === 1) {
            var binaryCanvas = makeBinaryVariant(canvas);
            if (binaryCanvas) {
              if (!manualBarcode) manualBarcode = decodeEan13FromCanvas(binaryCanvas);
              variants.push(canvasFile(binaryCanvas, 'scan-binary-' + degrees + '.png'));
            }
          }
        });
      });
      if (typeof image.close === 'function') image.close();
      if (image.scannerObjectUrl) URL.revokeObjectURL(image.scannerObjectUrl);
      return Promise.all(variants).then(function (files) {
        return { files: files, manualBarcode: manualBarcode };
      });
    });
  }

  function loadCameras() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      status.textContent = 'Camera access is unavailable in this browser. You can still scan an image.';
      return;
    }
    Html5Qrcode.getCameras().then(function (cameras) {
      cameraSelect.replaceChildren();
      cameras.forEach(function (camera, index) {
        var option = document.createElement('option');
        option.value = camera.id;
        option.textContent = camera.label || 'Camera ' + (index + 1);
        cameraSelect.appendChild(option);
      });
      if (!cameras.length) throw new Error('No cameras found');
      var preferred = cameras.find(function (camera) { return /back|rear|environment/i.test(camera.label); }) || cameras[0];
      cameraSelect.value = preferred.id;
      startCamera(preferred.id);
    }).catch(function (reason) {
      status.textContent = 'Camera unavailable. You can choose another camera or scan an image.';
      error.textContent = cameraError(reason);
    });
  }

  cameraSelect.addEventListener('change', function () {
    if (reader.isScanning) {
      reader.stop().then(function () { startCamera(cameraSelect.value); })
        .catch(function (reason) { error.textContent = cameraError(reason); });
    } else {
      startCamera(cameraSelect.value);
    }
  });
  document.getElementById('restart').addEventListener('click', function () {
    if (reader.isScanning) {
      reader.stop().then(function () { startCamera(cameraSelect.value); })
        .catch(function (reason) { error.textContent = cameraError(reason); });
    } else {
      startCamera(cameraSelect.value);
    }
  });
  zoomInput.addEventListener('input', function () {
    if (!reader.isScanning) return;
    reader.applyVideoConstraints({ advanced: [{ zoom: Number(zoomInput.value) }] }).catch(function (reason) {
      error.textContent = 'The selected camera could not apply zoom. ' + cameraError(reason);
    });
  });
  torchButton.addEventListener('click', function () {
    if (torchButton.disabled || !reader.isScanning) return;
    torchEnabled = !torchEnabled;
    reader.applyVideoConstraints({ advanced: [{ torch: torchEnabled }] }).then(updateTorchAvailability).catch(function (reason) {
      torchEnabled = false;
      error.textContent = 'The camera could not change its flashlight setting. ' + cameraError(reason);
      updateTorchAvailability();
    });
  });
  imageInput.addEventListener('change', function () {
    var file = imageInput.files && imageInput.files[0];
    if (!file) return;
    error.textContent = '';
    status.textContent = 'Checking image…';
    stopLiveFrameDecoding();
    var stopCamera = reader.isScanning ? reader.stop() : Promise.resolve();
    stopCamera.then(function () { return makeImageVariants(file); }).then(function (prepared) {
      if (prepared.manualBarcode) {
        found(prepared.manualBarcode);
        if (locked) return;
      }
      var variants = prepared.files;
      var index = 0;
      function scanNext() {
        if (locked || index >= variants.length) {
          return Promise.resolve(false);
        }
        status.textContent = 'Enhancing image and checking orientation ' + (index + 1) + ' of ' + variants.length + '…';
        return reader.scanFile(variants[index++], true).then(function (value) {
          found(value);
          return locked ? true : scanNext();
        }).catch(function () { return scanNext(); });
      }
      return scanNext().then(function (detected) {
        if (detected) return true;
        status.textContent = 'Trying the high-sensitivity local image decoder…';
        return readWithZxingWasm(file).then(function (results) {
          var result = results.find(function (item) { return item.isValid && item.text; });
          if (result) {
            found(result.text);
            return locked;
          }
          return false;
        }).catch(function (reason) {
          error.textContent = 'The extra local image decoder could not finish. ' + String(reason && reason.message ? reason.message : reason);
          return false;
        });
      }).then(function (detected) {
        if (!detected && !locked && !error.textContent) {
          status.textContent = 'No supported barcode or QR code found in the image.';
          error.textContent = 'Try a closer photo with the whole code visible and less glare. Live scanning can also read the code.';
        }
      });
    }).catch(function () {
      status.textContent = 'Could not prepare that image for scanning.';
      error.textContent = 'Choose a standard JPG, PNG, or HEIC image and try again.';
    }).then(function () {
      imageInput.value = '';
      if (!locked && cameraSelect.value) startCamera(cameraSelect.value, true);
    }, function () {
      imageInput.value = '';
      if (!locked && cameraSelect.value) startCamera(cameraSelect.value, true);
    });
  });
  loadCameras();
}());
</script></body></html>
