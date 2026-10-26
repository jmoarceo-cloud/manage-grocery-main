import './pos';

try { if (localStorage.getItem('groceryone-theme') === 'dark' && document.querySelector('.workspace')) document.documentElement.dataset.theme = 'dark'; } catch { /* Preferences are optional. */ }
document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => { const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'; document.documentElement.dataset.theme = theme; try { localStorage.setItem('groceryone-theme', theme); } catch {} });
const drawerButton = document.querySelector('[data-sidebar-toggle]');
const closeDrawer = () => { document.body.classList.remove('sidebar-open'); drawerButton?.setAttribute('aria-expanded', 'false'); };
drawerButton?.addEventListener('click', () => { const open = document.body.classList.toggle('sidebar-open'); drawerButton.setAttribute('aria-expanded', String(open)); });
document.querySelector('[data-close-sidebar]')?.addEventListener('click', closeDrawer);
document.addEventListener('keydown', event => { if (event.key === 'Escape') closeDrawer(); });
document.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', () => { const input = document.getElementById(button.dataset.passwordToggle); const visible = input.type === 'password'; input.type = visible ? 'text' : 'password'; button.textContent = visible ? 'Hide' : 'Show'; button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password'); }));
document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => { if (!window.confirm(form.dataset.confirm)) event.preventDefault(); }));
document.querySelectorAll('form[data-busy]').forEach(form => form.addEventListener('submit', event => { if (event.defaultPrevented) return; form.querySelectorAll('button[type=submit], button:not([type])').forEach(button => { button.disabled = true; button.dataset.originalText = button.textContent; button.textContent = 'Saving…'; }); }));
document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));
document.querySelector('[data-select-labels]')?.addEventListener('change', event => document.querySelectorAll('input[name="product_ids[]"]').forEach(checkbox => { checkbox.checked = event.target.checked; }));
document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => { button.closest('dialog').close(); document.querySelector('#barcode-search')?.focus(); }));
document.querySelectorAll('[data-product-image]').forEach(image => {
    image.addEventListener('error', () => { image.hidden = true; });
    image.addEventListener('load', () => { if (image.naturalWidth > 0) image.dataset.loaded = 'true'; });
    if (image.complete && image.naturalWidth > 0) image.dataset.loaded = 'true';
    if (image.complete && image.naturalWidth === 0) image.hidden = true;
});
document.querySelectorAll('[data-logout]').forEach(form => form.addEventListener('submit', () => { try { for (const key of Object.keys(sessionStorage)) if (key.startsWith('groceryone-cart-')) sessionStorage.removeItem(key); } catch {} }));
window.addEventListener('pageshow', () => document.querySelectorAll('[data-original-text]').forEach(button => { button.disabled = false; button.textContent = button.dataset.originalText; }));

const credentialChoice = document.querySelector('[data-password-credential]');
if (credentialChoice) {
    const updateCredential = () => { const recovery = credentialChoice.value === 'recovery'; for (const [selector, visible] of [['[data-current-password]', !recovery], ['[data-password-recovery]', recovery]]) { const label = document.querySelector(selector); label.hidden = !visible; const input = label.querySelector('input'); input.disabled = !visible; input.required = visible; } };
    credentialChoice.addEventListener('change', updateCredential); updateCredential();
}const informationEditor = document.querySelector('[data-information-editor]');
if (informationEditor) {
    const fieldNames = ['description', 'brand', 'unit_name', 'storage_notes'];
    const values = () => JSON.stringify(fieldNames.map(name => informationEditor.elements.namedItem(name)?.value ?? ''));
    const initialValues = values();
    const updateReviewAvailability = () => {
        const dirty = informationEditor.dataset.hasOldInput === '1' || values() !== initialValues;
        document.querySelectorAll('[data-information-decision] button').forEach(button => { button.disabled = dirty; });
        const notice = document.querySelector('[data-information-unsaved]'); if (notice) notice.hidden = !dirty;
    };
    informationEditor.addEventListener('input', updateReviewAvailability);
    window.addEventListener('pageshow', updateReviewAvailability);
    updateReviewAvailability();
}
const profileCountry = document.querySelector('[data-profile-country]');
if (profileCountry) {
    const updatePhoneHint = () => { const digits = profileCountry.selectedOptions[0]?.dataset.digits; const hint = document.querySelector('[data-profile-phone-length]'); if (hint) hint.textContent = digits ? `Use ${digits} digits for this contact country.` : 'Choose a contact country before changing the saved number.'; };
    profileCountry.addEventListener('change', updatePhoneHint); updatePhoneHint();
}
