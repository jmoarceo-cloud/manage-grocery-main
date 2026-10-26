(function () {
    'use strict';

    var GLOBAL_THEME_KEY = 'manage-grocery-theme';
    var root = document.documentElement;

    function getLegacyKeys() {
        return Array.from(document.querySelectorAll('[data-theme-storage-key]'))
            .map(function (control) {
                return control.getAttribute('data-theme-storage-key');
            })
            .filter(Boolean);
    }

    function readTheme() {
        var saved = null;
        try {
            saved = localStorage.getItem(GLOBAL_THEME_KEY);
            if (saved === 'dark' || saved === 'light') return saved;
            var legacyKeys = getLegacyKeys();
            for (var i = 0; i < legacyKeys.length; i++) {
                saved = localStorage.getItem(legacyKeys[i]);
                if (saved === 'dark' || saved === 'light') return saved;
            }
        } catch (error) {
            return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        }
        return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function updateControls(theme) {
        var isDark = theme === 'dark';
        document.querySelectorAll('.theme-toggle, .theme-toggle-btn').forEach(function (button) {
            button.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
            button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            button.setAttribute('title', isDark ? 'Switch to light mode' : 'Switch to dark mode');
            var label = button.querySelector('span');
            if (label) label.textContent = button.classList.contains('theme-toggle-btn')
                ? (isDark ? 'Dark mode' : 'Light mode')
                : (isDark ? 'Light mode' : 'Dark mode');
            var lightIcon = button.querySelector('.tt-icon-light');
            var darkIcon = button.querySelector('.tt-icon-dark');
            if (lightIcon) lightIcon.hidden = isDark;
            if (darkIcon) darkIcon.hidden = !isDark;
        });
    }

    function applyTheme(theme, persist) {
        var normalized = theme === 'dark' ? 'dark' : 'light';
        if (normalized === 'dark') {
            root.setAttribute('data-theme', 'dark');
        } else {
            root.removeAttribute('data-theme');
        }
        updateControls(normalized);
        if (!persist) return;
        try {
            localStorage.setItem(GLOBAL_THEME_KEY, normalized);
            getLegacyKeys().forEach(function (key) {
                localStorage.setItem(key, normalized);
            });
        } catch (error) {
            // The visible theme remains usable when browser storage is unavailable.
        }
    }

    applyTheme(readTheme(), true);

    document.addEventListener('click', function (event) {
        var target = event.target;
        var button = target && target.closest
            ? target.closest('.theme-toggle, .theme-toggle-btn')
            : null;
        if (!button) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        applyTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark', true);
    }, true);

    window.addEventListener('storage', function (event) {
        if (event.key === GLOBAL_THEME_KEY && (event.newValue === 'dark' || event.newValue === 'light')) {
            applyTheme(event.newValue, false);
        }
    });
})();
