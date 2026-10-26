(function () {
    'use strict';

    function mountSharedTopbar() {
        var configNode = document.getElementById('sharedTopbarConfig');
        if (!configNode) return;

        var config;
        try {
            config = JSON.parse(configNode.textContent || '{}');
        } catch (error) {
            console.error('Could not read shared topbar configuration.', error);
            return;
        }

        var headers = document.querySelectorAll('.topbar, .pos-topbar, .scanner-nav');

        headers.forEach(function (header) {
            var existingProfile = header.querySelector('.topbar-profile');
            var existingBell = header.querySelector('.topbar-icon-action');
            var showNotifications = config.showNotifications !== false;
            var unreadCount = Math.max(0, Number(config.notificationUnreadCount) || 0);
            var notificationLabel = config.notificationsLabel || 'Notifications';
            if (!showNotifications && existingBell) {
                existingBell.remove();
                existingBell = null;
            }
            if (existingBell) {
                if (config.notificationsUrl) existingBell.href = config.notificationsUrl;
                updateNotificationBadge(existingBell, unreadCount, notificationLabel);
            }
            if (existingProfile && (existingBell || !showNotifications)) {
                header.classList.add('topbar--standard');
                var existingActions = header.querySelector(':scope > .topbar-actions');
                if (existingActions) existingActions.classList.add('topbar-actions--shared');
                ensureThemeToggle(existingProfile, config.themeStorageKey);
                return;
            }

            header.classList.add('topbar--standard');
            var actions = header.querySelector(':scope > .topbar-actions');
            if (!actions) {
                actions = document.createElement('div');
                actions.className = 'topbar-actions';
                header.appendChild(actions);
            }
            actions.classList.add('topbar-actions--shared');
            var headerAction = header.querySelector(':scope > .btn-primary, :scope > .action-btn');
            if (headerAction && headerAction.style.marginLeft === 'auto') {
                actions.style.marginLeft = '0';
            }

            header.querySelectorAll('.topbar-user, .topbar-right, .user-info').forEach(function (legacyProfile) {
                legacyProfile.setAttribute('aria-hidden', 'true');
                legacyProfile.style.display = 'none';
            });
            var legacyCashier = header.querySelector(':scope > .cashier-name');
            if (legacyCashier) {
                legacyCashier.setAttribute('aria-hidden', 'true');
                legacyCashier.style.display = 'none';
            }

            if (!existingBell && showNotifications) {
                var notificationLink = document.createElement('a');
                notificationLink.className = 'topbar-icon-action';
                notificationLink.href = config.notificationsUrl;
                notificationLink.setAttribute('aria-label', 'Open ' + notificationLabel.toLowerCase());
                notificationLink.title = notificationLabel;
                notificationLink.innerHTML =
                    '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                    '<path d="M6 8a4 4 0 0 1 8 0c0 3.2 1 4.5 1.5 5H4.5C5 12.5 6 11.2 6 8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>' +
                    '<path d="M8.2 15.5a1.8 1.8 0 0 0 3.6 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>' +
                    '</svg>';
                updateNotificationBadge(notificationLink, unreadCount, notificationLabel);
                actions.appendChild(notificationLink);
            }

            if (!existingProfile) {
                var profile = document.createElement('details');
                profile.className = 'topbar-profile';
                var summary = document.createElement('summary');
                summary.setAttribute('aria-label', 'Open account menu');

                var avatar = document.createElement('span');
                avatar.className = 'user-avatar';
                if (config.avatarUrl) {
                    var image = document.createElement('img');
                    image.src = config.avatarUrl;
                    image.alt = '';
                    avatar.appendChild(image);
                } else {
                    avatar.textContent = config.initial || '?';
                }
                summary.appendChild(avatar);

                var copy = document.createElement('span');
                copy.className = 'topbar-profile-copy';
                var name = document.createElement('strong');
                name.textContent = config.name || 'User';
                var role = document.createElement('span');
                role.textContent = config.role || 'Account';
                copy.appendChild(name);
                copy.appendChild(role);
                summary.appendChild(copy);

                var chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                chevron.setAttribute('class', 'topbar-profile-chevron');
                chevron.setAttribute('viewBox', '0 0 20 20');
                chevron.setAttribute('fill', 'none');
                chevron.setAttribute('aria-hidden', 'true');
                chevron.innerHTML = '<path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>';
                summary.appendChild(chevron);
                profile.appendChild(summary);

                var menu = document.createElement('div');
                menu.className = 'topbar-profile-menu';
                var profileLink = document.createElement('a');
                profileLink.href = config.profileUrl;
                profileLink.textContent = 'Account profile';
                var logoutLink = document.createElement('a');
                logoutLink.href = config.logoutUrl;
                logoutLink.textContent = 'Log out';
                logoutLink.setAttribute('data-confirm-logout', 'true');
                menu.appendChild(profileLink);
                menu.appendChild(logoutLink);
                profile.appendChild(menu);
                actions.appendChild(profile);
            }

            ensureThemeToggle(header.querySelector('.topbar-profile'), config.themeStorageKey);

            if (!header.hasAttribute('data-topbar-dismiss-bound')) {
                header.setAttribute('data-topbar-dismiss-bound', 'true');
                header.addEventListener('click', function (event) {
                    if (!event.target.closest('.topbar-profile') || event.target.closest('.topbar-profile-menu a')) {
                        header.querySelectorAll('.topbar-profile[open]').forEach(function (openProfile) {
                            openProfile.removeAttribute('open');
                        });
                    }
                });
                header.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        header.querySelectorAll('.topbar-profile[open]').forEach(function (openProfile) {
                            openProfile.removeAttribute('open');
                        });
                    }
                });
            }
        });
    }

    function ensureThemeToggle(profile, storageKey) {
        if (!profile || !storageKey) return;

        var menu = profile.querySelector('.topbar-profile-menu');
        if (!menu) {
            menu = document.createElement('div');
            menu.className = 'topbar-profile-menu';
            profile.appendChild(menu);
        }

        var button = menu.querySelector('.topbar-profile-theme-toggle');
        if (!button) {
            button = document.createElement('button');
            button.type = 'button';
            button.className = 'topbar-profile-theme-toggle theme-toggle';
            button.setAttribute('aria-pressed', 'false');
            button.innerHTML = '<span>Dark mode</span>';

            var logoutLink = menu.querySelector('[data-confirm-logout], a:last-child');
            menu.insertBefore(button, logoutLink || null);
        }

        button.setAttribute('data-theme-storage-key', storageKey);
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        button.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
        button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        button.setAttribute('title', isDark ? 'Switch to light mode' : 'Switch to dark mode');
        var label = button.querySelector('span');
        if (label) label.textContent = isDark ? 'Light mode' : 'Dark mode';
    }

    function updateNotificationBadge(link, count, label) {
        var badge = link.querySelector('.topbar-notification-count');
        var accessibleLabel = label || 'Notifications';
        if (count <= 0) {
            if (badge) badge.remove();
            link.setAttribute('aria-label', 'Open ' + accessibleLabel);
            link.title = accessibleLabel;
            return;
        }
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'topbar-notification-count';
            badge.setAttribute('aria-hidden', 'true');
            link.appendChild(badge);
        }
        badge.textContent = count > 99 ? '99+' : String(count);
        link.setAttribute('aria-label', 'Open ' + accessibleLabel + ', ' + count + ' pending');
        link.title = count + ' pending ' + accessibleLabel;
    }

    document.addEventListener('click', function (event) {
        var logoutLink = event.target.closest('a[data-confirm-logout]');
        if (!logoutLink) return;
        if (logoutLink.hasAttribute('data-confirm')) return;
        event.preventDefault();
        var logoutUrl = logoutLink.href;
        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                icon: 'question',
                title: 'Are you sure you want to log out?',
                showCancelButton: true,
                cancelButtonText: 'Cancel',
                confirmButtonText: 'Log Out',
                confirmButtonColor: '#123C5C'
            }).then(function (result) {
                if (result.isConfirmed) window.location.href = logoutUrl;
            });
        } else if (window.confirm('Are you sure you want to log out?')) {
            window.location.href = logoutUrl;
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountSharedTopbar, { once: true });
    } else {
        mountSharedTopbar();
    }
})();
