<script>
        (function () {
            try {
                var savedTheme = localStorage.getItem('manage-grocery-theme');
                document.documentElement.dataset.theme = savedTheme ||
                    (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            } catch (error) {
                document.documentElement.dataset.theme = 'light';
            }
        }());
    </script>
