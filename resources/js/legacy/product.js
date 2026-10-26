import './source/theme-sync.js';
(function () {
    var root = document.documentElement;
    var toggle = document.querySelector('.theme-toggle');
    var saved = localStorage.getItem('manage-grocery-theme');
    if (saved === 'dark') root.dataset.theme = 'dark';
    if (!toggle) return;
    function updateToggleState() {
        var dark = root.dataset.theme === 'dark';
        toggle.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
        toggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }
    updateToggleState();
    toggle.addEventListener('click', function () {
        var dark = root.dataset.theme !== 'dark';
        root.dataset.theme = dark ? 'dark' : 'light';
        localStorage.setItem('manage-grocery-theme', dark ? 'dark' : 'light');
        toggle.querySelector('span').textContent = dark ? 'Light' : 'Dark';
        updateToggleState();
    });
}());
