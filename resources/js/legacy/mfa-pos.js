(function () {
            var root = document.documentElement;
            var button = document.getElementById('themeToggle');
            var saved = localStorage.getItem('siaa-theme');
            if (saved === 'dark') root.dataset.theme = 'dark';
            function update() {
                var dark = root.dataset.theme === 'dark';
                button.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
                button.querySelector('span').textContent = dark ? 'Light' : 'Dark';
            }
            update();
            button.addEventListener('click', function () {
                var dark = root.dataset.theme === 'dark';
                root.dataset.theme = dark ? 'light' : 'dark';
                localStorage.setItem('siaa-theme', dark ? 'light' : 'dark');
                update();
            });
        }());
import('./source/theme-sync.js');
