<?php require_once __DIR__ . '/includes/public_bootstrap.php'; ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HCI GroceryOne Product Kiosk</title>
<style>body{font:18px Arial,sans-serif;background:#070914;color:#f4f7ff;margin:0}.wrap{max-width:860px;margin:auto;padding:24px}.box{background:#111a3c;border:1px solid rgba(148,163,255,.16);border-radius:18px;padding:20px}video,#reader{display:block;width:100%;max-height:55vh;background:#020617;border-radius:12px}input{width:100%;box-sizing:border-box;padding:15px;border-radius:9px;border:1px solid #64748b;font-size:1.1rem;margin:8px 0}button,a{display:inline-block;padding:13px 16px;border:0;border-radius:9px;background:#4d8dff;color:#fff;font-weight:700;text-decoration:none;margin-top:8px;cursor:pointer}.muted{color:#cbd5e1}.error{color:#fecaca}</style></head>
<body><button type="button" id="fullscreenBtn" style="position:fixed;top:12px;right:12px;opacity:.8">⛶ Fullscreen</button><main class="wrap"><h1>HCI GroceryOne Product Kiosk <span class="muted" style="font-size:.5em">/ Kiosk ng Produkto</span></h1><div class="box"><p class="muted" id="status">Starting camera… / Ina-on ang camera…</p><video id="preview" playsinline muted></video><div id="reader" style="display:none"></div><form action="scan.php" method="get"><label for="barcode">Enter barcode / Ilagay ang barcode</label><input id="barcode" name="b" inputmode="numeric" autofocus placeholder="Barcode"><button type="submit">View product / Tingnan ang produkto</button></form><a href="catalog.php">Browse catalog / Tingnan ang katalogo</a><p class="error" id="error"></p></div></main>
<script src="assets/js/html5-qrcode.min.js"></script>
<script>
(function () {
    var video = document.getElementById('preview'), status = document.getElementById('status'), error = document.getElementById('error');
    var locked = false, resetTimer;
    function found(value) { if (locked) return; locked = true; clearTimeout(resetTimer); window.location.href = 'scan.php?b=' + encodeURIComponent(value); }
    function scheduleReset() { clearTimeout(resetTimer); resetTimer = setTimeout(function () { window.location.href = 'kiosk.php'; }, 45000); }
    ['click','touchstart','keydown'].forEach(function (evt) { document.addEventListener(evt, scheduleReset); });
    scheduleReset();
    function startFallback() {
        video.style.display = 'none';
        document.getElementById('reader').style.display = 'block';
        status.textContent = 'Scanning… / Nag-i-scan…';
        var qr = new Html5Qrcode('reader');
        qr.start({facingMode:'environment'}, {fps:10, qrbox:{width:250,height:150}}, function (value) { qr.stop().catch(function () {}); found(value); }, function () {}).catch(function (e) {
            status.textContent = 'Camera permission was not granted. Use the barcode field. / Hindi pinayagan ang camera.';
            error.textContent = e && e.message ? e.message : '';
        });
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !('BarcodeDetector' in window)) { startFallback(); return; }
    navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}}).then(function (stream) {
        video.srcObject = stream; return video.play();
    }).then(function () {
        var detector = new BarcodeDetector({formats:['ean_8','ean_13','upc_a','code_128','qr_code']});
        status.textContent = 'Ready — point the kiosk camera at a barcode.';
        function scan() {
            detector.detect(video).then(function (codes) {
                if (codes.length && codes[0].rawValue) { found(codes[0].rawValue); return; }
                window.requestAnimationFrame(scan);
            }).catch(function () { window.requestAnimationFrame(scan); });
        }
        scan();
    }).catch(function (e) {
        status.textContent = 'Camera permission was not granted. Use the barcode field.';
        error.textContent = e && e.message ? e.message : '';
    });
}());
document.getElementById('fullscreenBtn').addEventListener('click', function () {
    if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(function () {});
});
</script></body></html>
