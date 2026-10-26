(function () {
    var storageKey = 'siaa_pending_scroll_restore';
    var maxAgeMs = 30000;
    var navigationPending = false;

    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }

    function currentScrollPosition() {
        return {
            x: window.scrollX || document.documentElement.scrollLeft || 0,
            y: window.scrollY || document.documentElement.scrollTop || 0
        };
    }

    function saveScrollPosition() {
        var position = currentScrollPosition();
        try {
            sessionStorage.setItem(storageKey, JSON.stringify({
                x: position.x,
                y: position.y,
                savedAt: Date.now()
            }));
        } catch (error) {
            console.warn('Could not save the current scroll position.', error);
        }
    }

    function isInternalNavigation(url) {
        if (url.origin !== window.location.origin) return false;
        if (
            url.pathname === window.location.pathname
            && url.search === window.location.search
            && url.hash
        ) {
            return false;
        }
        return true;
    }

    document.addEventListener('click', function (event) {
        if (!event.isTrusted) return;
        if (!(event.target instanceof Element)) return;
        var target = event.target.closest('a[href], button, input[type="submit"], input[type="image"]');
        if (!target || target.hasAttribute('data-no-scroll-restore')) return;

        if (target.matches('a[href]')) {
            if (target.target === '_blank' || target.hasAttribute('download')) return;
            var href = target.getAttribute('href') || '';
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
            if (!isInternalNavigation(new URL(target.href, window.location.href))) return;
        } else if (target.form && target.form.target === '_blank') {
            return;
        }

        navigationPending = true;
        saveScrollPosition();
    }, true);

    document.addEventListener('submit', function (event) {
        if (!event.isTrusted) return;
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-scroll-restore')) return;
        if (form.target === '_blank') return;

        var action = form.getAttribute('action') || window.location.href;
        if (!isInternalNavigation(new URL(action, window.location.href))) return;
        navigationPending = true;
        saveScrollPosition();
    }, true);

    window.addEventListener('pagehide', function () {
        if (navigationPending) saveScrollPosition();
    });

    window.addEventListener('pageshow', function () {
        var saved;
        try {
            saved = sessionStorage.getItem(storageKey);
            sessionStorage.removeItem(storageKey);
        } catch (error) {
            console.warn('Could not restore the saved scroll position.', error);
            return;
        }
        if (!saved) return;

        var position;
        try {
            position = JSON.parse(saved);
        } catch (error) {
            console.warn('The saved scroll position was invalid.', error);
            return;
        }
        if (
            !position
            || !Number.isFinite(Number(position.x))
            || !Number.isFinite(Number(position.y))
            || !Number.isFinite(Number(position.savedAt))
            || Date.now() - Number(position.savedAt) > maxAgeMs
            || Date.now() < Number(position.savedAt)
        ) {
            return;
        }

        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                window.scrollTo(Number(position.x), Number(position.y));
            });
        });
    });
})();
