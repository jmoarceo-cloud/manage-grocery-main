<script>
        (function () {
            try {
                var savedTheme = localStorage.getItem('manage-grocery-theme');
                var theme = savedTheme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.dataset.theme = theme === 'dark' ? 'dark' : 'light';
            } catch (error) {
                document.documentElement.dataset.theme = 'light';
            }
        }());
    </script>
