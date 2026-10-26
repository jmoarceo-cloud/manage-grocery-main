<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
$root = dirname(__DIR__);
$failures = [];
function check(bool $ok, string $message): void { global $failures; if (!$ok) $failures[] = $message; }
function source(string $file): string { global $root; $data = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file)); if ($data === false) throw new RuntimeException($file); return $data; }
foreach (['product_detail.php','scan.php','scanner.php','kiosk.php'] as $file) {
    $contents = source($file);
    check(strpos($contents, 'public_bootstrap') !== false, "$file must use public bootstrap");
    if ($file === 'scanner.php') {
        check(strpos($contents, "includes/config.php") !== false, 'private scanner must use authenticated config');
        check(strpos($contents, 'requireLogin()') !== false, 'private scanner must require employee login');
    } else {
        check(strpos($contents, "includes/config.php") === false, "$file must not use authenticated config");
    }
}
check(strpos(source('scan.php'), '{3,64}') !== false, 'scan barcode minimum must be three characters');
check(strpos(source('scanner.php'), 'html5-qrcode.min.js') !== false, 'scanner must include offline fallback');
check(strpos(source('scanner.php'), 'formatsToSupport') !== false, 'scanner must configure barcode and QR formats');
foreach (['CODE_39', 'CODE_93', 'ITF', 'CODABAR', 'DATA_MATRIX', 'PDF_417'] as $format) {
    check(strpos(source('scanner.php'), 'Html5QrcodeSupportedFormats.' . $format) !== false, "scanner must support {$format} codes");
}
check(strpos(source('scanner.php'), 'scanFile(variants[index++], true)') !== false, 'scanner must allow barcode and QR image scanning');
check(strpos(source('scanner.php'), 'readWithZxingWasm') !== false && strpos(source('scanner.php'), "new URL('assets/js/zxing-image-fallback.js', window.location.href).href") !== false, 'scanner must resolve the shared local ZXing module from the system root');
check(strpos(source('products.php'), 'readImageWithFallback') !== false && strpos(source('products.php'), "new URL('assets/js/zxing-image-fallback.js', window.location.href).href") !== false, 'Add Product image scanning must resolve the same local ZXing module');
check(strpos(source('assets/js/zxing-image-fallback.js'), 'maxNumberOfSymbols: 1') !== false && strpos(source('assets/js/zxing-image-fallback.js'), 'tryHarder: true') !== false, 'shared image fallback must scan supported formats in high-sensitivity mode');
check(strpos(source('scanner.php'), 'createImageBitmap(file).catch(loadHtmlImage)') !== false, 'scanner image upload must fall back to HTML image decoding if ImageBitmap is unavailable');
check(strpos(source('scanner.php'), 'rotations = [0, 90, 180, 270]') !== false, 'scanner image decoding must try all quarter-turn orientations');
check(strpos(source('scanner.php'), 'grayscale(1) contrast(1.7) brightness(1.08)') !== false, 'scanner image decoding must improve contrast before scanning');
check(strpos(source('scanner.php'), 'decodeEan13FromCanvas') !== false, 'scanner image decoding must attempt a check-digit-validated EAN-13 fallback');
check(strpos(source('scanner.php'), 'manualBarcode') !== false, 'scanner must route a locally decoded EAN-13 barcode');
check(strpos(source('scanner.php'), 'fps: 20') !== false && strpos(source('scanner.php'), 'width: { ideal: 1920 }') !== false && strpos(source('scanner.php'), 'height: { ideal: 1080 }') !== false && strpos(source('scanner.php'), 'aspectRatio: 16 / 9') !== false && strpos(source('scanner.php'), "focusMode: { ideal: 'continuous' }") !== false, 'live scanner must request 1920x1080 video and continuous autofocus');
check(strpos(source('scanner.php'), 'readWithZxingWasm(blobs[0])') !== false && strpos(source('scanner.php'), 'readWithZxingWasm(blobs[1])') !== false && strpos(source('scanner.php'), 'startLiveFrameDecoding') !== false, 'live camera must try high-sensitivity ZXing on raw and enhanced video frames');
check(strpos(source('scanner.php'), 'zoomInput.addEventListener(\'input\'') !== false && strpos(source('scanner.php'), "advanced: [{ zoom: Number(zoomInput.value) }]") !== false, 'live camera zoom control must apply supported zoom changes');
check(strpos(source('scanner.php'), 'qrbox: function (viewfinderWidth, viewfinderHeight)') !== false && strpos(source('scanner.php'), 'viewfinderWidth * 0.9') !== false && strpos(source('scanner.php'), 'viewfinderHeight * 0.75') !== false, 'live scanner must decode a large cropped region');
check(strpos(source('scanner.php'), 'Detected: ') !== false && strpos(source('scanner.php'), 'navigator.vibrate([70, 50, 70])') !== false && strpos(source('scanner.php'), 'is-detected') !== false, 'live scanner must provide scan confirmation feedback');
check(strpos(source('scanner.php'), 'getRunningTrackCapabilities') !== false && strpos(source('scanner.php'), "advanced: [{ torch: torchEnabled }]") !== false && strpos(source('scanner.php'), "advanced: [{ zoom: Number(zoomInput.value) }]") !== false, 'scanner must expose supported flashlight and zoom controls');
check(strpos(source('scanner.php'), 'var crops = [1, 0.72, 0.5]') !== false && strpos(source('scanner.php'), 'var maxDimension = 2400;') !== false, 'scanner image decoding must try full-width barcodes at enhanced resolution');
check(strpos(source('products.php'), 'barcodeScanZoom') !== false && strpos(source('products.php'), 'barcodeScanTorch') !== false && strpos(source('products.php'), "focusMode: { ideal: 'continuous' }") !== false, 'product barcode scanner must expose focus, zoom, and flashlight assistance');
check(strpos(source('products.php'), 'width: { ideal: 1280 }') !== false && strpos(source('products.php'), 'height: { ideal: 720 }') !== false && strpos(source('products.php'), 'aspectRatio: 16 / 9') !== false && strpos(source('products.php'), 'qrbox: scanBox') !== false, 'product barcode scanner must request 1280x720 video and a large cropped region');
check(strpos(source('products.php'), 'Detected: ') !== false && strpos(source('products.php'), 'navigator.vibrate([70, 50, 70])') !== false && strpos(source('products.php'), 'barcode-scan-flash') !== false, 'product barcode scanner must provide scan confirmation feedback');
check(strpos(source('scanner.php'), "url.origin === window.location.origin") !== false, 'scanner must only route same-origin product QR URLs');
check(strpos(source('scanner.php'), "url.searchParams.getAll('id').length === 1") !== false, 'scanner must reject ambiguous QR token parameters');
check(strpos(source('scanner.php'), "scanPath.pathname + '?id='") !== false, 'scanner must route valid product QR tokens to the local scan endpoint');
check(strpos(source('scanner.php'), 'extractGs1ProductCode') !== false && strpos(source('scanner.php'), '01\\/(\\d{14})') !== false && strpos(source('scanner.php'), "['gtin', 'ean', 'barcode', '01']") !== false, 'catalog scanner must extract GTINs from GS1 and product URL QR values');
check(strpos(source('scan.php'), '/^0(\\d{13})$/') !== false, 'catalog lookup must match zero-padded GTIN-14 to a stored EAN-13');
check(strpos(source('shelf_labels.php'), 'QR_ECLEVEL_H') !== false && strpos(source('shelf_labels.php'), '$quietZone = 4;') !== false, 'printed shelf QR labels must use ECC H and a four-module quiet zone');
check(strpos(source('includes/public_bootstrap.php'), "img-src 'self' data: blob:") !== false, 'scanner image uploads must be permitted by the public-page CSP');
check(strpos(source('includes/public_bootstrap.php'), "'wasm-unsafe-eval'") !== false, 'local ZXing-WASM decoding must be allowed by the page CSP');
check(strpos(source('includes/config.php'), "'wasm-unsafe-eval'") !== false, 'Add Product scanner must be allowed to use its local ZXing-WASM fallback');
check(strpos(source('kiosk.php'), 'fullscreenBtn') !== false && strpos(source('kiosk.php'), '45000') !== false, 'kiosk must provide fullscreen and reset behavior');
check(is_file($root . '/assets/js/html5-qrcode.min.js'), 'offline scanner library must exist');
check(is_file($root . '/assets/js/zxing-image-fallback.js') && is_file($root . '/assets/js/zxing-wasm/dist/es/reader/index.js') && is_file($root . '/assets/js/zxing-wasm/dist/es/share.js') && is_file($root . '/assets/js/zxing-wasm/dist/reader/zxing_reader.wasm') && is_file($root . '/assets/js/zxing-wasm/LICENSE'), 'local ZXing-WASM fallback and its license must be bundled');
if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n"); exit(1); }
echo "consumer_layer_smoke: OK\n";
