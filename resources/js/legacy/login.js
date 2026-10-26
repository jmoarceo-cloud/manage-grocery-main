import './source/theme-sync.js';

        // Password eye toggle
        const toggleBtn = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');

        // Ripple burst on submit button
        var submitBtn = document.querySelector('.btn-submit');
        if (submitBtn) {
            submitBtn.addEventListener('click', function (e) {
                var rect = submitBtn.getBoundingClientRect();
                var ripple = document.createElement('span');
                var size = Math.max(rect.width, rect.height) * 1.8;
                ripple.style.cssText =
                    'position:absolute;border-radius:50%;pointer-events:none;' +
                    'width:' + size + 'px;height:' + size + 'px;' +
                    'left:' + (e.clientX - rect.left - size / 2) + 'px;' +
                    'top:' + (e.clientY - rect.top - size / 2) + 'px;' +
                    'background:radial-gradient(circle, rgba(255,255,255,.5) 0%, rgba(255,255,255,0) 70%);' +
                    'transform:scale(0);opacity:1;transition:transform .6s ease, opacity .6s ease;';
                submitBtn.appendChild(ripple);
                requestAnimationFrame(function () {
                    ripple.style.transform = 'scale(1)';
                    ripple.style.opacity = '0';
                });
                setTimeout(function () { ripple.remove(); }, 650);
            });
        }

        toggleBtn.addEventListener('click', function () {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';

            eyeIcon.innerHTML = isHidden
                ? '<path d="M2 2l16 16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M1.5 10S4.5 4 10 4s8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/>'
                : '<path d="M1.5 10S4.5 4 10 4s8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/>';
        });
