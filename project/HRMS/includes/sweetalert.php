<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// ============================================================
// includes/sweetalert.php
// Shared SweetAlert2 confirmation handler for HRMS. Included at
// the bottom of every HRMS page (see the various
// `include 'includes/sweetalert.php';` calls) so that ANY <a> or
// <form> anywhere in HRMS that has a `data-confirm="..."`
// attribute automatically shows a small "Are you sure?" popup
// before it actually navigates/submits — same small popup style
// already used on the POS side (see includes/sidebar.php's
// confirmNav()) instead of the plain, unstyled browser
// confirm()/window.confirm().
//
// RECOVERY NOTE (Aug 2026): this file had gotten overwritten with
// the entire contents of the POS side's my_profile.php by mistake
// (same 1489 lines, duplicate <html>/<body>/functions and all) —
// so every page that includes this file was silently getting NO
// confirm handler at all (data-confirm attributes were present in
// the markup everywhere, but nothing was listening for them), while
// also risking "Cannot redeclare function" fatals wherever a
// function name collided. Rebuilt here from every data-confirm*
// usage across HRMS plus the doc comments that referenced it
// (payroll/includes/footer.php, applicant_review.php).
//
// Supported attributes (put these directly on the <a> or <form>):
//   data-confirm          (required) confirmation message/body text
//   data-confirm-title    dialog title (default: "Are you sure?")
//   data-confirm-icon     SweetAlert icon (default: "warning" if
//                          data-confirm-danger="1", else "question")
//   data-confirm-danger   "1" — styles the confirm button red, for
//                          destructive/rejecting actions
//   data-confirm-ok       confirm button text (default: "Yes, proceed",
//                          or "Yes, remove"/etc. per page)
//   data-confirm-color    custom confirm button color (hex) — used by
//                          the Logout links so they stay brand-blue
//                          (#123C5C) even though they're not "danger"
//   data-reason            "required" | "optional" — also asks for a
//                          short text reason in the same dialog; on
//                          confirm it's written into a hidden
//                          input[name="reason"] on the form before
//                          submitting (creating it if it doesn't
//                          already exist)
//
// Forms also get free double-submit protection: a submit button is
// locked (disabled) the moment its form's 'submit' event fires,
// data-confirm or not — a page can cancel this itself (e.g. a
// client-side validation failure) by resetting
// form.dataset.submitting = '0' and re-enabling its own buttons; see
// applicant_review.php's capture-phase file-size check for an example.
//
// BUGFIX (Aug 2026): this used to lock the button on 'click' instead
// of 'submit', BEFORE the browser had actually committed to
// submitting the form. Disabling a submit button while it is still
// mid-activation cancels that activation — so the button became
// disabled and the form silently never submitted at all (no request,
// no confirm popup, nothing — exactly the "button does nothing" report
// on the Schedule Final Interview button, but it affected every
// data-confirm submit button in HRMS). Locking on 'submit' instead
// avoids this: by the time 'submit' fires, native HTML5 validation has
// already passed, so there's nothing left to accidentally cancel.
// ============================================================
?>
<style>
.swal2-container.swal2-backdrop-show {
    -webkit-backdrop-filter: blur(5px);
    backdrop-filter: blur(5px);
}
/* SweetAlert2 confirm dialogs (logout, etc.) — restyled (Aug 2026) to
   look like a native part of the site instead of the default CDN
   look (generic sans-serif, plain blue/gray buttons, no brand color).
   Everything below reads the SAME CSS variables every HRMS page
   already defines in its own :root (--green-deep, --mustard, --ink,
   --font-display, etc. — see hr_dashboard.php / employees.php / etc.),
   so this automatically matches both the light admin theme and the
   dark HR/supervisor theme (html[data-theme="dark"] just overrides
   those variables — see includes/hr_sidebar.php) with zero extra work.
   !important since SweetAlert injects its own <style> tag, which can
   otherwise load after this one. */
    .swal2-popup:not(.swal2-toast) {
        width: auto !important;
        max-width: 360px !important;
        padding: 26px 22px 22px !important;
        border-radius: 18px !important;
        background: var(--white) !important;
        font-family: var(--font-body) !important;
        box-shadow: 0 12px 32px rgba(18, 60, 92, 0.18) !important;
    }

    .swal2-popup:not(.swal2-toast) .swal2-icon {
        width: 50px !important;
        height: 50px !important;
        min-width: 50px !important;
        min-height: 50px !important;
        flex: 0 0 50px !important;
        overflow: hidden !important;
        margin: 4px auto 10px !important;
        border-color: var(--green-mid) !important;
        color: var(--green-mid) !important;
    }

    .swal2-popup:not(.swal2-toast) .swal2-icon.swal2-warning {
        border-color: var(--red-alert) !important;
        color: var(--red-alert) !important;
    }

    .swal2-popup:not(.swal2-toast) .swal2-icon.swal2-success {
        border-color: var(--green-leaf) !important;
        color: var(--green-leaf) !important;
    }

    .swal2-popup:not(.swal2-toast) .swal2-icon.swal2-success [class^='swal2-success-line'] {
        background-color: var(--green-leaf) !important;
    }

    .swal2-popup:not(.swal2-toast) .swal2-icon.swal2-success .swal2-success-ring {
        border-color: rgba(79, 195, 209, .3) !important;
    }

    .swal2-popup:not(.swal2-toast) .swal2-title {
        display: block !important;
        position: relative !important;
        z-index: 1 !important;
        width: 100% !important;
        overflow: visible !important;
        font-family: var(--font-display) !important;
        font-size: 18px !important;
        font-weight: 700 !important;
        color: var(--ink) !important;
        padding: 0 !important;
        margin: 0 0 4px !important;
        line-height: 1.25 !important;
    }

    .swal2-popup:not(.swal2-toast) .swal2-html-container {
        font-family: var(--font-body) !important;
        font-size: 13.5px !important;
        line-height: 1.5 !important;
        color: var(--ink-soft) !important;
        margin: 6px 2px 0 !important;
    }

    .swal2-popup:not(.swal2-toast) .swal2-textarea,
    .swal2-popup:not(.swal2-toast) .swal2-input {
        font-family: var(--font-body) !important;
        font-size: 13.5px !important;
        color: var(--ink) !important;
        background: var(--white) !important;
        border: 1.5px solid var(--line) !important;
        border-radius: 10px !important;
        box-shadow: none !important;
    }

    .swal2-popup:not(.swal2-toast) .swal2-textarea:focus,
    .swal2-popup:not(.swal2-toast) .swal2-input:focus {
        border-color: var(--green-leaf) !important;
        box-shadow: 0 0 0 3px var(--green-pale) !important;
    }

    .swal2-popup:not(.swal2-toast) .swal2-actions {
        margin-top: 20px !important;
        gap: 8px !important;
    }

    .swal2-styled.swal2-confirm,
    .swal2-styled.swal2-cancel {
        font-family: var(--font-body) !important;
        border-radius: 10px !important;
        font-size: 13px !important;
        font-weight: 700 !important;
        padding: 9px 18px !important;
        box-shadow: none !important;
        border: none !important;
        transition: filter .15s ease, transform .15s ease;
    }

    .swal2-styled.swal2-confirm {
        background: linear-gradient(135deg, var(--green-mid), var(--green-deep)) !important;
        color: #FFFFFF !important;
        box-shadow: 0 2px 8px rgba(18, 60, 92, 0.22) !important;
    }

    .swal2-styled.swal2-confirm:hover {
        filter: brightness(1.08);
    }

    .swal2-styled.swal2-confirm.swal2-confirm-danger {
        background: linear-gradient(135deg, #E5644A, var(--red-alert)) !important;
        box-shadow: 0 2px 8px rgba(200, 71, 45, 0.22) !important;
    }

    .swal2-styled.swal2-cancel {
        background: var(--white) !important;
        color: var(--ink) !important;
        border: 1.5px solid var(--line) !important;
    }

    .swal2-styled.swal2-cancel:hover {
        background: var(--cream) !important;
        border-color: var(--green-leaf) !important;
    }

    .swal2-styled:focus {
        outline: none !important;
        box-shadow: 0 0 0 3px rgba(18, 60, 92, .15) !important;
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    (function () {
        // Guard in case some page ends up including this file twice.
        if (window.__hrmsConfirmBound) return;
        window.__hrmsConfirmBound = true;

        function fireConfirm(el, onConfirm, onCancel) {
            var danger = el.getAttribute('data-confirm-danger') === '1';
            var reasonMode = el.getAttribute('data-reason'); // 'required' | 'optional' | null
            var customColor = el.getAttribute('data-confirm-color'); // rare opt-in override

            var opts = {
                title: el.getAttribute('data-confirm-title') || 'Are you sure?',
                text: el.getAttribute('data-confirm') || '',
                icon: el.getAttribute('data-confirm-icon') || (danger ? 'warning' : 'question'),
                showCancelButton: true,
                cancelButtonText: 'Cancel',
                confirmButtonText: el.getAttribute('data-confirm-ok') || (danger ? 'Yes, proceed' : 'Yes'),
                // Color comes from the CSS classes below (brand gradient for
                // normal actions, red gradient for danger ones) so dialogs
                // automatically match the site's palette in both the light
                // and dark HRMS themes. Only a page that explicitly opts in
                // via data-confirm-color gets an inline override instead.
                customClass: { confirmButton: danger ? 'swal2-confirm-danger' : '' }
            };
            if (customColor) {
                opts.confirmButtonColor = customColor;
            }

            if (reasonMode) {
                opts.input = 'textarea';
                opts.inputPlaceholder = reasonMode === 'required' ? 'Reason (required)…' : 'Reason (optional)…';
                opts.inputValidator = function (value) {
                    if (reasonMode === 'required' && !(value || '').trim()) {
                        return 'Please enter a reason.';
                    }
                };
            }

            Swal.fire(opts).then(function (result) {
                if (!result.isConfirmed) {
                    if (onCancel) onCancel();
                    return;
                }
                if (reasonMode) {
                    var input = el.querySelector('input[name="reason"]');
                    if (!input) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'reason';
                        el.appendChild(input);
                    }
                    input.value = result.value || '';
                }
                onConfirm();
            });
        }

        function lockForm(form) {
            form.dataset.submitting = '1';
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (b) {
                b.disabled = true;
            });
        }
        function unlockForm(form) {
            form.dataset.submitting = '0';
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (b) {
                b.disabled = false;
            });
        }

        // Links: <a data-confirm="...">.
        document.addEventListener('click', function (e) {
            var a = e.target.closest('a[data-confirm]');
            if (!a) return;
            e.preventDefault();
            fireConfirm(a, function () {
                window.location.href = a.href;
            });
        });

        // Forms: everything happens on 'submit', which only fires AFTER
        // native HTML5 validation (required fields, min/max, etc.) has
        // already passed — so locking here can never race with validation
        // the way locking on 'click' used to.
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.dataset.submitting === '1') { e.preventDefault(); return; }

            if (form.hasAttribute('data-confirm')) {
                e.preventDefault();
                lockForm(form);
                fireConfirm(form, function () {
                    form.submit(); // native submit — bypasses this listener, buttons already disabled
                }, function () {
                    unlockForm(form); // cancelled — let them try again
                });
            } else {
                // Plain form, no confirm needed: just lock to block a
                // double-click on slow connections. Locking here (inside
                // 'submit', not 'click') is safe — this click already
                // successfully triggered submission, so disabling the
                // button now doesn't cancel it.
                lockForm(form);
            }
        });
    })();
</script>