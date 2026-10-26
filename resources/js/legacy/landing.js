(function () {
        var pageProgress = document.querySelector('.page-scroll-progress > span');
        if (pageProgress) {
            var pageProgressPending = false;

            function updatePageProgress() {
                var scrollableHeight = document.documentElement.scrollHeight - window.innerHeight;
                var progress = scrollableHeight > 0
                    ? Math.max(0, Math.min(1, window.scrollY / scrollableHeight))
                    : 0;
                pageProgress.style.transform = 'scaleX(' + progress + ')';
            }

            function schedulePageProgress() {
                if (pageProgressPending) return;
                pageProgressPending = true;
                window.requestAnimationFrame(function () {
                    pageProgressPending = false;
                    updatePageProgress();
                });
            }

            window.addEventListener('scroll', schedulePageProgress, { passive: true });
            window.addEventListener('resize', schedulePageProgress);
            updatePageProgress();
        }

        var toggle = document.getElementById('theme-toggle');
        var root = document.documentElement;
        if (toggle) {
            function updateButton() {
                var isDark = root.dataset.theme === 'dark';
                toggle.textContent = isDark ? 'Light mode' : 'Dark mode';
                toggle.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
                toggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            }
            updateButton();
            toggle.addEventListener('click', function () {
                var theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
                root.dataset.theme = theme;
                try {
                    localStorage.setItem('manage-grocery-theme', theme);
                } catch (error) {
                    // The selected theme still applies for this page view.
                }
                updateButton();
            });
        }

        var menuToggle = document.getElementById('nav-menu-toggle');
        var navLinks = document.getElementById('landing-nav-links');
        if (menuToggle && navLinks) {
            menuToggle.addEventListener('click', function () {
                var isOpen = menuToggle.getAttribute('aria-expanded') === 'true';
                menuToggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
                menuToggle.setAttribute('aria-label', isOpen ? 'Open navigation menu' : 'Close navigation menu');
                navLinks.classList.toggle('is-open', !isOpen);
            });
            navLinks.addEventListener('click', function (event) {
                if (event.target.closest('a')) {
                    menuToggle.setAttribute('aria-expanded', 'false');
                    menuToggle.setAttribute('aria-label', 'Open navigation menu');
                    navLinks.classList.remove('is-open');
                }
            });
        }

        var revealItems = document.querySelectorAll('.scroll-reveal');
        if ('IntersectionObserver' in window) {
            var revealObserver = new IntersectionObserver(function (entries, observer) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.08, rootMargin: '0px 0px -8% 0px' });
            revealItems.forEach(function (item) {
                revealObserver.observe(item);
            });
        } else {
            revealItems.forEach(function (item) {
                item.classList.add('is-visible');
            });
        }

        var workflowList = document.querySelector('.workflow-list');
        if (workflowList) {
            var workflowSteps = Array.prototype.slice.call(workflowList.querySelectorAll('.workflow-step'));
            var workflowStepsContainer = workflowList.querySelector('.workflow-steps');
            var previewStage = workflowList.querySelector('.workflow-preview-stage');
            var previewProgress = workflowList.querySelector('.workflow-preview-progress > span');

            if (workflowStepsContainer && previewStage && workflowSteps.length) {
                workflowList.classList.add('is-scroll-story');
                var workflowProgressTrack = document.createElement('span');
                workflowProgressTrack.className = 'workflow-progress-track';
                workflowProgressTrack.setAttribute('aria-hidden', 'true');
                var workflowProgressFill = document.createElement('span');
                workflowProgressFill.className = 'workflow-progress-fill';
                workflowProgressTrack.appendChild(workflowProgressFill);
                var workflowProgressHead = document.createElement('span');
                workflowProgressHead.className = 'workflow-progress-head';
                workflowProgressHead.setAttribute('aria-hidden', 'true');
                workflowStepsContainer.prepend(workflowProgressTrack, workflowProgressHead);
                workflowSteps.forEach(function (step, index) {
                    var originalPreview = step.querySelector('.workflow-visual');
                    if (!originalPreview) return;

                    var previewPanel = document.createElement('div');
                    previewPanel.className = 'workflow-preview-panel';
                    previewPanel.setAttribute('aria-hidden', 'true');
                    previewPanel.appendChild(originalPreview.cloneNode(true));
                    previewStage.appendChild(previewPanel);
                    step.dataset.workflowStep = String(index);
                });

                var previewPanels = Array.prototype.slice.call(
                    previewStage.querySelectorAll('.workflow-preview-panel')
                );
                var activeStep = -1;

                function setActiveWorkflowStep(index) {
                    if (index < 0 || index >= workflowSteps.length || index === activeStep) return;
                    activeStep = index;

                    workflowSteps.forEach(function (step, stepIndex) {
                        var isActive = stepIndex === index;
                        step.classList.toggle('is-active', isActive);
                        if (isActive) {
                            step.setAttribute('aria-current', 'step');
                        } else {
                            step.removeAttribute('aria-current');
                        }
                    });

                    previewPanels.forEach(function (panel, panelIndex) {
                        var isActive = panelIndex === index;
                        panel.classList.toggle('is-active', isActive);
                        panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                    });

                }

                function updateActiveWorkflowStep() {
                    var viewportMiddle = window.innerHeight / 2;
                    var nearestIndex = 0;
                    var nearestDistance = Infinity;
                    var stepCenters = [];

                    workflowSteps.forEach(function (step, index) {
                        var bounds = step.getBoundingClientRect();
                        var pseudo = window.getComputedStyle(step, '::before');
                        var markerTop = Number.parseFloat(pseudo.top);
                        var markerHeight = Number.parseFloat(pseudo.height);
                        var markerCenterOffset = window.matchMedia('(max-width: 768px)').matches
                            ? (Number.isFinite(markerTop) ? markerTop : 0)
                                + (Number.isFinite(markerHeight) ? markerHeight / 2 : 0)
                            : bounds.height / 2;
                        var markerCenter = bounds.top + markerCenterOffset;
                        stepCenters.push(markerCenter);
                        var distance = Math.abs(markerCenter - viewportMiddle);
                        if (distance < nearestDistance) {
                            nearestDistance = distance;
                            nearestIndex = index;
                        }
                    });

                    setActiveWorkflowStep(nearestIndex);

                    var firstCenter = stepCenters[0];
                    var lastCenter = stepCenters[stepCenters.length - 1];
                    var progress = lastCenter > firstCenter
                        ? Math.max(0, Math.min(1, (viewportMiddle - firstCenter) / (lastCenter - firstCenter)))
                        : 0;
                    var stepsBounds = workflowStepsContainer.getBoundingClientRect();
                    var firstMarker = window.getComputedStyle(workflowSteps[0], '::before');
                    var markerLeft = Number.parseFloat(firstMarker.left);
                    var markerWidth = Number.parseFloat(firstMarker.width);
                    workflowStepsContainer.style.setProperty(
                        '--workflow-progress-start',
                        (firstCenter - stepsBounds.top) + 'px'
                    );
                    workflowStepsContainer.style.setProperty(
                        '--workflow-progress-end',
                        (lastCenter - stepsBounds.top) + 'px'
                    );
                    workflowStepsContainer.style.setProperty(
                        '--workflow-progress-left',
                        ((workflowSteps[0].getBoundingClientRect().left - stepsBounds.left)
                            + (Number.isFinite(markerLeft) ? markerLeft : 0)
                            + (Number.isFinite(markerWidth) ? markerWidth / 2 : 0)) + 'px'
                    );
                    workflowProgressFill.style.transform = 'scaleY(' + progress + ')';
                    workflowProgressHead.style.top =
                        ((firstCenter - stepsBounds.top) + (lastCenter - firstCenter) * progress) + 'px';
                    if (previewProgress) {
                        previewProgress.style.transform = 'scaleY(' + progress + ')';
                    }
                }

                var workflowUpdatePending = false;
                function scheduleWorkflowUpdate() {
                    if (workflowUpdatePending) return;
                    workflowUpdatePending = true;
                    window.requestAnimationFrame(function () {
                        workflowUpdatePending = false;
                        updateActiveWorkflowStep();
                    });
                }

                window.addEventListener('scroll', scheduleWorkflowUpdate, { passive: true });
                window.addEventListener('resize', scheduleWorkflowUpdate);
                updateActiveWorkflowStep();
            }
        }
    }());
