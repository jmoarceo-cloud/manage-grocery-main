(function () {
    function initializeMobileNavigation() {
        var shell = document.querySelector('.app-shell');
        var sidebar = shell && shell.querySelector('.sidebar');
        if (!shell || !sidebar || shell.dataset.mobileNavigationReady === 'true') return;

        shell.dataset.mobileNavigationReady = 'true';
        sidebar.id = sidebar.id || 'hrmsSidebar';

        initializeSidebarCollapse(shell, sidebar);

        var topbar = shell.querySelector('.main-area .topbar');
        if (topbar) {
            upgradeLegacyTopbar(topbar, sidebar);
        }

        var menuButton = document.createElement('button');
        menuButton.type = 'button';
        menuButton.className = 'mobile-menu-toggle';
        menuButton.setAttribute('aria-label', 'Open navigation menu');
        menuButton.setAttribute('aria-controls', sidebar.id);
        menuButton.setAttribute('aria-expanded', 'false');
        menuButton.innerHTML = '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';

        if (topbar) {
            topbar.insertBefore(menuButton, topbar.firstChild);
        } else {
            menuButton.classList.add('mobile-menu-toggle--floating');
            document.body.appendChild(menuButton);
        }

        var backdrop = document.createElement('button');
        backdrop.type = 'button';
        backdrop.className = 'sidebar-backdrop';
        backdrop.setAttribute('aria-label', 'Close navigation menu');
        backdrop.hidden = true;
        shell.insertBefore(backdrop, sidebar.nextSibling);

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

        backdrop.addEventListener('click', function () {
            closeMenu(true);
        });

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

    function initializeSidebarCollapse(shell, sidebar) {
        var toggle = shell.querySelector('.sidebar-toggle');
        if (!toggle) return;

        var storageKey = 'siaa_sidebar_collapsed';
        toggle.setAttribute('aria-controls', sidebar.id);

        function updateToggle(collapsed) {
            var label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
            toggle.setAttribute('title', label);
            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }

        updateToggle(shell.classList.contains('sidebar-collapsed'));
        toggle.addEventListener('click', function () {
            var collapsed = shell.classList.toggle('sidebar-collapsed');
            window.localStorage.setItem(storageKey, collapsed ? '1' : '0');
            updateToggle(collapsed);
        });
    }

    function upgradeLegacyTopbar(topbar, sidebar) {
        if (topbar.querySelector('.topbar-profile')) {
            topbar.classList.add('topbar--standard');
            return;
        }

        var heading = Array.prototype.find.call(topbar.children, function (child) {
            return child.matches('h1');
        });
        var dashboardHeading = topbar.querySelector('.dash-hero h1');
        var userInfo = Array.prototype.find.call(topbar.children, function (child) {
            return child.matches('.user-info');
        });
        if ((!heading && !dashboardHeading) || !userInfo) return;

        topbar.classList.add('topbar--standard');

        if (heading) {
            var breadcrumb = document.createElement('div');
            breadcrumb.className = 'topbar-breadcrumbs';

            var section = document.createElement('span');
            section.textContent = 'HRMS & Payroll';
            breadcrumb.appendChild(section);

            var separator = document.createElement('span');
            separator.setAttribute('aria-hidden', 'true');
            separator.textContent = '/';
            breadcrumb.appendChild(separator);

            var titleText = heading.firstChild;
            if (titleText && titleText.nodeType === Node.TEXT_NODE) {
                titleText.textContent = titleText.textContent.replace(/^HRMS\s*&\s*Payroll\s*[—-]\s*/i, '');
            }
            breadcrumb.appendChild(heading);
            topbar.insertBefore(breadcrumb, userInfo);
        } else {
            topbar.classList.add('topbar--dashboard');
        }

        var actions = document.createElement('div');
        actions.className = 'topbar-actions';

        var notificationLink = Array.prototype.find.call(
            sidebar.querySelectorAll('.sidebar-nav a[href]'),
            function (link) {
                return /notifications/i.test(link.textContent);
            }
        );
        if (notificationLink) {
            var notifications = document.createElement('a');
            notifications.className = 'topbar-icon-action';
            notifications.href = notificationLink.href;
            notifications.setAttribute('aria-label', 'Notifications');
            notifications.title = 'Notifications';
            notifications.innerHTML = '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M6 8a4 4 0 0 1 8 0c0 3.2 1 4.5 1.5 5H4.5C5 12.5 6 11.2 6 8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8.2 15.5a1.8 1.8 0 0 0 3.6 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
            actions.appendChild(notifications);
        }

        var profile = document.createElement('details');
        profile.className = 'topbar-profile';

        var summary = document.createElement('summary');
        var avatar = userInfo.querySelector('.user-avatar');
        if (avatar) {
            summary.appendChild(avatar);
        } else {
            avatar = document.createElement('span');
            avatar.className = 'user-avatar';
            avatar.setAttribute('aria-hidden', 'true');
            avatar.textContent = userInfo.textContent.trim().charAt(0).toUpperCase() || '?';
            summary.appendChild(avatar);
        }

        var profileText = userInfo.textContent.replace(/\s+/g, ' ').trim();
        var roleMatch = profileText.match(/\(([^)]+)\)\s*$/);
        var userName = roleMatch ? profileText.slice(0, roleMatch.index).trim() : profileText;
        var roleName = roleMatch ? roleMatch[1].trim() : '';
        var profileCopy = document.createElement('span');
        profileCopy.className = 'topbar-profile-copy';

        var name = document.createElement('strong');
        name.textContent = userName;
        profileCopy.appendChild(name);

        if (roleName) {
            var role = document.createElement('span');
            role.textContent = roleName;
            profileCopy.appendChild(role);
        }
        summary.appendChild(profileCopy);

        var svgNamespace = 'http://www.w3.org/2000/svg';
        var chevron = document.createElementNS(svgNamespace, 'svg');
        chevron.setAttribute('class', 'topbar-profile-chevron');
        chevron.setAttribute('viewBox', '0 0 20 20');
        chevron.setAttribute('fill', 'none');
        chevron.setAttribute('aria-hidden', 'true');
        var chevronPath = document.createElementNS(svgNamespace, 'path');
        chevronPath.setAttribute('d', 'm5 7.5 5 5 5-5');
        chevronPath.setAttribute('stroke', 'currentColor');
        chevronPath.setAttribute('stroke-width', '1.7');
        chevronPath.setAttribute('stroke-linecap', 'round');
        chevronPath.setAttribute('stroke-linejoin', 'round');
        chevron.appendChild(chevronPath);
        summary.appendChild(chevron);
        profile.appendChild(summary);

        var menu = document.createElement('div');
        menu.className = 'topbar-profile-menu';
        var profileLink = Array.prototype.find.call(
            sidebar.querySelectorAll('.sidebar-nav a[href]'),
            function (link) {
                return /profile/i.test(link.textContent);
            }
        );
        if (profileLink) {
            appendProfileMenuLink(menu, profileLink.href, 'My profile');
        } else {
            var adminDashboardLink = sidebar.querySelector('.sidebar-nav a.nav-link--back[href]');
            if (adminDashboardLink) {
                appendProfileMenuLink(menu, adminDashboardLink.href, 'Back to Admin Dashboard');
            }
        }

        var logoutLink = sidebar.querySelector('.sidebar-nav a.nav-link--logout[href]');
        if (logoutLink) {
            var profileLogoutLink = appendProfileMenuLink(menu, logoutLink.href, 'Log out');
            profileLogoutLink.setAttribute('data-confirm-logout', 'true');
        }
        if (menu.childElementCount) {
            profile.appendChild(menu);
        }

        document.addEventListener('pointerdown', function (event) {
            if (!profile.contains(event.target)) profile.open = false;
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && profile.open) {
                profile.open = false;
                summary.focus();
            }
        });

        actions.appendChild(profile);
        topbar.replaceChild(actions, userInfo);
    }

    function appendProfileMenuLink(menu, href, label) {
        var link = document.createElement('a');
        link.href = href;
        link.textContent = label;
        menu.appendChild(link);
        return link;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeMobileNavigation, { once: true });
    } else {
        initializeMobileNavigation();
    }
})();
