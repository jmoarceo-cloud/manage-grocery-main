import './source/theme-sync.js';

(function () {
    var input = document.getElementById('catalogSearchInput');
    var form  = document.getElementById('catalogSearchForm');
    if (!input || !form) return;
    var timer = null;
    input.addEventListener('input', function () {
        if (timer) clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 450);
    });
}());


(function () {
    var modal = document.getElementById('accessModal');
    var frame = document.getElementById('accessFrame');
    var openLink = document.querySelector('[data-private-login], [data-private-exit]');
    if (!modal || !frame || !openLink) return;
    function close() { modal.classList.remove('is-open'); frame.src = 'about:blank'; }
    openLink.addEventListener('click', function (event) {
        event.preventDefault();
        frame.src = openLink.href;
        modal.classList.add('is-open');
    });
    document.querySelector('[data-close-access]').addEventListener('click', close);
    modal.addEventListener('click', function (event) { if (event.target === modal) close(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') close(); });
    frame.addEventListener('load', function () {
        try {
            var url = frame.contentWindow.location.href;
            var privateCatalog = url.indexOf('catalog.php?private=1') !== -1 || url.indexOf('/catalog?private=1') !== -1;
            var publicCatalog = url.indexOf('catalog.php') !== -1 || /\/catalog(?:\?|$)/.test(url);
            if (privateCatalog || (openLink.hasAttribute('data-private-exit') && publicCatalog)) {
                window.location.href = openLink.hasAttribute('data-private-exit') && publicCatalog
                    ? window.location.pathname
                    : window.location.pathname + '?private=1';
            }
        } catch (error) {}
    });
}());
