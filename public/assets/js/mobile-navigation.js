(function () {
    function initializeMobileNavigation() {
        var shell = document.querySelector('.app-shell');
        var sidebar = shell && shell.querySelector('.sidebar');
        var topbar = shell && shell.querySelector('.main-area > .topbar, .main-area > .pos-topbar, .pos-main > .pos-topbar');
        if (!shell || !sidebar || !topbar || shell.dataset.mobileNavigationReady === 'true') return;

        shell.dataset.mobileNavigationReady = 'true';
        sidebar.id = sidebar.id || 'posSidebar';

        var menuButton = topbar.querySelector('.mobile-menu-toggle');
        if (!menuButton) {
            if (topbar.querySelector('.pos-mobile-menu, .report-mobile-menu')) return;
            menuButton = document.createElement('button');
            menuButton.type = 'button';
            menuButton.className = 'mobile-menu-toggle';
            menuButton.innerHTML = '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
            topbar.insertBefore(menuButton, topbar.firstChild);
        }
        menuButton.setAttribute('aria-label', 'Open navigation menu');
        menuButton.setAttribute('aria-controls', sidebar.id);
        menuButton.setAttribute('aria-expanded', 'false');

        var backdrop = shell.querySelector('.sidebar-backdrop');
        if (!backdrop) {
            backdrop = document.createElement('button');
            backdrop.type = 'button';
            backdrop.className = 'sidebar-backdrop';
            backdrop.setAttribute('aria-label', 'Close navigation menu');
            backdrop.hidden = true;
            shell.insertBefore(backdrop, sidebar.nextSibling);
        }

        var mobileQuery = window.matchMedia('(max-width: 1023px)');

        function closeMenu(returnFocus) {
            shell.classList.remove('mobile-nav-open');
            document.body.classList.remove('mobile-nav-open');
            menuButton.setAttribute('aria-expanded', 'false');
            menuButton.setAttribute('aria-label', 'Open navigation menu');
            backdrop.hidden = true;
            if (returnFocus) menuButton.focus();
        }

        function openMenu() {
            if (!mobileQuery.matches) return;
            backdrop.hidden = false;
            shell.classList.add('mobile-nav-open');
            document.body.classList.add('mobile-nav-open');
            menuButton.setAttribute('aria-expanded', 'true');
            menuButton.setAttribute('aria-label', 'Close navigation menu');
            var firstLink = sidebar.querySelector('.sidebar-nav a[href]');
            if (firstLink) firstLink.focus();
        }

        menuButton.addEventListener('click', function () {
            if (shell.classList.contains('mobile-nav-open')) {
                closeMenu(true);
            } else {
                openMenu();
            }
        });
        backdrop.addEventListener('click', function () { closeMenu(true); });
        sidebar.addEventListener('click', function (event) {
            if (event.target.closest('.sidebar-nav a[href]') && mobileQuery.matches) {
                closeMenu(false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && shell.classList.contains('mobile-nav-open')) {
                closeMenu(true);
            }
        });
        mobileQuery.addEventListener('change', function (event) {
            if (!event.matches) closeMenu(false);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeMobileNavigation, { once: true });
    } else {
        initializeMobileNavigation();
    }
})();
