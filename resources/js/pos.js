const app = document.querySelector('#pos-app');
if (app) {
    const money = value => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value);
    const storageKey = `groceryone-cart-${app.dataset.cashier}`;
    let state = { key: crypto.randomUUID(), items: [] };
    try { const saved = JSON.parse(sessionStorage.getItem(storageKey)); if (saved?.key && Array.isArray(saved.items)) state = saved; } catch { /* Storage is optional. */ }
    let busy = false;
    const search = document.querySelector('#barcode-search');
    const results = document.querySelector('#search-results');
    const form = document.querySelector('#payment-form');
    const approved = document.querySelector('#approved-discount-request');
    const manualForm = document.querySelector('#manual-discount-form');
    const error = document.querySelector('#pos-error');
    const save = () => { try { sessionStorage.setItem(storageKey, JSON.stringify(state)); } catch { /* Checkout remains usable. */ } };
    const showError = message => { error.textContent = message; error.hidden = !message; };
    const text = (tag, content, className = '') => { const node = document.createElement(tag); node.textContent = content; if (className) node.className = className; return node; };
    const unitPrice = item => {
        let cents = Math.round(Number(item.price) * 100);
        if (item.discount_type === 'percent') cents -= Math.round(cents * Math.min(Number(item.discount_value), 100) / 100);
        if (item.discount_type === 'fixed') cents -= Math.round(Number(item.discount_value) * 100);
        return Math.max(0, cents) / 100;
    };
    function render() {
        const lines = document.querySelector('#cart-lines'); lines.replaceChildren();
        let subtotal = 0, count = 0;
        for (const item of state.items) {
            const tr = document.createElement('tr'); const amount = unitPrice(item) * item.quantity; subtotal += amount; count += item.quantity;
            const label = text('td', item.product_name); label.append(text('small', item.barcode || 'Product', 'muted block'));
            const quantityCell = document.createElement('td'); const quantity = document.createElement('input');
            quantity.type = 'number'; quantity.min = '1'; quantity.max = String(Math.min(9999, Number(item.sellable_stock))); quantity.value = String(item.quantity); quantity.className = 'quantity-input'; quantity.setAttribute('aria-label', `Quantity for ${item.product_name}`);
            quantity.addEventListener('change', () => { const value = Number(quantity.value); if (!Number.isInteger(value) || value < 1 || value > Number(quantity.max)) { quantity.value = String(item.quantity); return; } item.quantity = value; save(); render(); });
            quantityCell.append(quantity); const removeCell = document.createElement('td'); const remove = text('button', '×', 'icon-button danger'); remove.type = 'button'; remove.setAttribute('aria-label', `Remove ${item.product_name}`); remove.addEventListener('click', () => { state.items = state.items.filter(entry => entry.id !== item.id); save(); render(); }); removeCell.append(remove);
            tr.append(label, quantityCell, text('td', money(amount), 'number'), removeCell); lines.append(tr);
        }
        if (!state.items.length) { const tr = document.createElement('tr'); const td = text('td', 'Scan your first item to build the basket.', 'empty-state'); td.colSpan = 4; tr.append(td); lines.append(tr); }
        const type = form.elements.discount_type.value;
        document.querySelector('#discount-details').hidden = !['senior', 'pwd'].includes(type);
        document.querySelector('#approval-details').hidden = type !== 'approved';
        approved.required = type === 'approved';
        document.querySelector('#cart-subtotal').textContent = money(subtotal);
        let preview = Math.round(subtotal * 100);
        if (['senior', 'pwd'].includes(type)) preview -= Math.round(preview * .2);
        const selected = approved.selectedOptions[0];
        if (type === 'approved' && selected?.dataset.subtotal === String(Math.round(subtotal * 100))) preview -= Math.min(preview, Number(selected.dataset.discount));
        document.querySelector('#cart-total').textContent = money(preview / 100);
        document.querySelector('#cart-count').textContent = `${count} items`;
        document.querySelector('#pay-button').disabled = busy || !state.items.length;
    }
    function add(product) {
        if (busy) return;
        if (Number(product.sellable_stock) < 1) { showError('This product has no sellable stock.'); return; }
        const existing = state.items.find(item => item.id === product.id);
        if (existing) { if (existing.quantity >= Number(product.sellable_stock)) { showError('The available quantity has already been added.'); return; } existing.quantity++; } else state.items.push({ ...product, quantity: 1 });
        save(); render(); results.replaceChildren(); search.value = ''; search.focus(); showError('');
    }
    document.querySelector('#product-search').addEventListener('submit', async event => {
        event.preventDefault(); if (busy) return; results.replaceChildren(text('p', 'Finding products…', 'muted'));
        try {
            const response = await fetch(`${app.dataset.lookup}?q=${encodeURIComponent(search.value)}`, { headers: { Accept: 'application/json' } });
            const data = await response.json(); if (!response.ok) throw new Error(data.message || 'Search is temporarily unavailable.');
            results.replaceChildren(); if (data.products.length === 1) { add(data.products[0]); return; }
            if (!data.products.length) results.append(text('p', 'No matching products. Try another name or barcode.', 'muted'));
            for (const product of data.products) { const button = text('button', `${product.product_name} · ${money(unitPrice(product))} · ${product.sellable_stock} available`, 'search-result'); button.type = 'button'; button.addEventListener('click', () => add(product)); results.append(button); }
        } catch (failure) { results.replaceChildren(); showError(failure.message); }
    });
    function completed(receipt) {
        const summary = document.querySelector('#receipt-summary'); summary.replaceChildren(text('p', receipt.receipt_no), text('strong', money(Number(receipt.total)), 'completion-total'), text('p', `Change: ${money(Number(receipt.change))}`));
        document.querySelector('#receipt-link').href = `${app.dataset.receiptBase}/${receipt.sale_id}`;
        state = { key: crypto.randomUUID(), items: [] }; save(); form.reset(); manualForm.reset(); document.querySelector('#discount-request-status').hidden = true; render(); refreshApprovals(); document.querySelector('#receipt-dialog').showModal();
    }
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (busy || !state.items.length) return; busy = true; render(); showError('');
        const data = Object.fromEntries(new FormData(form)); data.request_key = state.key; data.items = state.items.map(item => ({ id: item.id, quantity: item.quantity }));
        if (!['senior', 'pwd'].includes(data.discount_type)) for (const key of ['discount_id_number', 'discount_holder_name', 'supervisor_username', 'supervisor_password']) delete data[key];
        if (data.discount_type !== 'approved') delete data.discount_request_id;
        for (const key of Object.keys(data)) if (data[key] === '') delete data[key];
        try {
            const response = await fetch(app.dataset.checkout, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(data) });
            const result = await response.json(); if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join(' ') : result.message || 'The sale could not be completed.'); completed(result.receipt);
        } catch (failure) { showError(`${failure.message} Your basket is retained; retry the same payment if needed.`); } finally { busy = false; render(); }
    });
    form.elements.discount_type.addEventListener('change', render);
    approved.addEventListener('change', render);
    async function refreshApprovals() {
        const previous = approved.value;
        try {
            const response = await fetch(app.dataset.discountRequests, { headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Approvals could not be loaded.');
            const empty = text('option', data.requests.length ? 'Choose an approved request' : 'No unused approved requests'); empty.value = '';
            approved.replaceChildren(empty);
            for (const request of data.requests) {
                const option = text('option', `#${request.request_id} · ${money(Number(request.computed_discount_amount))} off · basket ${money(Number(request.subtotal_at_request))}`);
                option.value = String(request.request_id); option.dataset.subtotal = String(Math.round(Number(request.subtotal_at_request) * 100)); option.dataset.discount = String(Math.round(Number(request.computed_discount_amount) * 100)); approved.append(option);
            }
            if ([...approved.options].some(option => option.value === previous)) approved.value = previous;
            const notice = document.querySelector('#discount-request-status');
            if (data.requests.some(request => String(request.request_id) === notice.dataset.requestId)) notice.textContent = `Request #${notice.dataset.requestId} is approved. Choose it in Payment summary to apply the discount.`;
            render();
        } catch (failure) { showError(failure.message); }
    }
    document.querySelector('#refresh-discounts').addEventListener('click', refreshApprovals);
    manualForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !state.items.length) { showError('Add items to the current basket before requesting a discount.'); return; }
        const button = manualForm.querySelector('button'); if (button.disabled) return; button.disabled = true; showError('');
        const payload = Object.fromEntries(new FormData(manualForm)); payload.items = state.items.map(item => ({ id: item.id, quantity: item.quantity }));
        try {
            const response = await fetch(app.dataset.discountRequests, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(payload) });
            const data = await response.json(); if (!response.ok) throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'The request could not be sent.');
            const notice = document.querySelector('#discount-request-status'); notice.dataset.requestId = String(data.request_id); notice.textContent = `Request #${data.request_id} is awaiting Finance. Refresh approved requests after the decision.`; notice.hidden = false;
        } catch (failure) { showError(failure.message); } finally { button.disabled = false; }
    });
    document.addEventListener('keydown', event => { if (event.key === 'F2') { event.preventDefault(); search.focus(); search.select(); } if (event.key === 'F9') { event.preventDefault(); const payment = document.querySelector('#amount-received'); if (payment.value && form.reportValidity()) form.requestSubmit(); else payment.focus(); } });
    render();
    refreshApprovals();
    if (state.items.length) fetch(`${app.dataset.requestBase}/${encodeURIComponent(state.key)}`, { headers: { Accept: 'application/json' } }).then(response => response.ok ? response.json() : null).then(result => { if (result?.receipt) completed(result.receipt); }).catch(() => {});
}
