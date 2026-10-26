<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--public-bg:#f8fafc;--public-card:#fff;--public-ink:#334155;--public-heading:#0f172a;--public-muted:#64748b;--public-line:#e2e8f0;--public-accent:#1d4ed8;--public-soft:#eff6ff}
*{box-sizing:border-box}body.public-page{margin:0;background:var(--public-bg);color:var(--public-ink);font:15px/1.6 'Plus Jakarta Sans',Arial,sans-serif;background-image:linear-gradient(rgba(37,99,235,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(37,99,235,.045) 1px,transparent 1px);background-size:32px 32px}
.public-wrap{max-width:1180px;margin:auto;padding:32px clamp(18px,5vw,72px) 72px}.public-nav{height:74px;display:flex;align-items:center;justify-content:space-between;padding:0 clamp(18px,5vw,72px);background:rgba(255,255,255,.92);border-bottom:1px solid var(--public-line);position:sticky;top:0;z-index:5;backdrop-filter:blur(18px)}.public-nav a{color:var(--public-accent);font-weight:800;text-decoration:none}.public-card{background:var(--public-card);border:1px solid var(--public-line);border-radius:22px;padding:24px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.public-button{display:inline-block;padding:12px 16px;border:0;border-radius:10px;background:var(--public-accent);color:#fff!important;font-weight:800;text-decoration:none;cursor:pointer}
</style>
