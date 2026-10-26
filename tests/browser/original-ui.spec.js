import { test, expect } from '@playwright/test';
import fs from 'node:fs/promises';
import { comparePixels } from './support/original-pixels.js';

const qa='http://127.0.0.1:8017';

test('original landing presentation matches the Blade migration on desktop and mobile in both themes',async({browser,request})=>{
    expect((await(await request.get(`${qa}/__qa/target`)).json()).isolated).toBe(true);
    await fs.mkdir('docs/ui',{recursive:true});
    const results=[];
    for(const [label,viewport] of [['desktop',{width:1440,height:1000}],['mobile',{width:390,height:844}]]){
        for(const theme of ['light','dark']){
            const context=await browser.newContext({viewport,reducedMotion:'reduce'});
            try{
                await context.addInitScript(value=>localStorage.setItem('manage-grocery-theme',value),theme);
                const page=await context.newPage();const errors=[];
                page.on('pageerror',error=>errors.push(error.message));
                expect((await page.goto(`${qa}/`)).status()).toBe(200);
                await page.evaluate(()=>document.fonts.ready);
                await page.waitForLoadState('networkidle');
                await page.evaluate(()=>Promise.all(document.getAnimations().filter(animation=>Number.isFinite(animation.effect?.getComputedTiming().endTime)).map(animation=>animation.finished.catch(()=>{}))));
                await expect(page.locator('#hero-title')).toHaveText('One store.Connected work.');
                await expect(page.locator('.workflow-step')).toHaveCount(12);
                const ids=await page.locator('#featured .product-card').evaluateAll(cards=>cards.map(card=>card.dataset.productId));
                expect(ids.length).toBeGreaterThan(0);
                expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
                const native=await page.screenshot({path:`docs/ui/original-style-home-${label}-${theme}.png`,animations:'disabled'});
                const reference=await context.newPage();
                const query=new URLSearchParams();for(const id of ids)query.append('ids[]',id);
                expect((await reference.goto(`${qa}/__qa/original-landing?${query}`)).status()).toBe(200);
                await reference.evaluate(()=>document.fonts.ready);
                await reference.waitForLoadState('networkidle');
                await reference.evaluate(()=>Promise.all(document.getAnimations().filter(animation=>Number.isFinite(animation.effect?.getComputedTiming().endTime)).map(animation=>animation.finished.catch(()=>{}))));
                const original=await reference.screenshot({path:`docs/ui/source-home-reference-${label}-${theme}.png`,animations:'disabled'});
                const geometry=async target=>target.evaluate(()=>Object.fromEntries(['.landing-hero','.hero-copy','.catalog-window','.window-title','.window-product','.window-product-name','.window-footer','#hero-title'].map(selector=>{const el=document.querySelector(selector),rect=el.getBoundingClientRect(),style=getComputedStyle(el);return[selector,{x:rect.x,y:rect.y,width:rect.width,height:rect.height,font:style.font,transform:style.transform}]})));
                await fs.writeFile('storage/testing/original-ui-geometry.json',JSON.stringify({viewport:label,theme,native:await geometry(page),reference:await geometry(reference)},null,2));
                const difference=await comparePixels(page,native,original);
                results.push({viewport:label,theme,...difference});
                await fs.writeFile('storage/testing/original-ui-measurements.json',JSON.stringify(results,null,2));
                expect(difference.ratio,`${label}/${theme} original presentation difference`).toBeLessThan(0.002);
                expect(errors).toEqual([]);
                if(label==='mobile'){
                    await page.getByRole('button',{name:'Open navigation menu',exact:true}).click();
                    await expect(page.locator('#nav-menu-toggle')).toHaveAttribute('aria-expanded','true');
                    await page.getByRole('button',{name:theme==='light'?'Switch to dark mode':'Switch to light mode',exact:true}).click();
                    await expect(page.locator('html')).toHaveAttribute('data-theme',theme==='light'?'dark':'light');
                }
            }finally{await context.close();}
        }
    }
    await fs.writeFile('docs/audit/ORIGINAL_UI_BROWSER.json',JSON.stringify({date:'2026-10-26',reference:'Original presentation extracted as text; no legacy bootstrap/configuration executed; nonce-verified synthetic products',reduced_motion:true,results},null,2)+'\n');
});

test('original login presentation matches Blade in both themes and viewports with working password controls', async ({ browser, request }) => {
    expect((await (await request.get(`${qa}/__qa/target`)).json()).isolated).toBe(true);
    const results = [];
    for (const [label, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
        for (const theme of ['light', 'dark']) {
            const context = await browser.newContext({ viewport, reducedMotion: 'reduce' });
            try {
                await context.addInitScript(value => localStorage.setItem('manage-grocery-theme', value), theme);
                const page = await context.newPage();
                const errors = [], external = [];
                page.on('pageerror', error => errors.push(error.message));
                page.on('request', request => { if (new URL(request.url()).origin !== qa) external.push(request.url()); });
                expect((await page.goto(`${qa}/login`)).status()).toBe(200);
                await page.evaluate(() => document.fonts.ready);
                await page.waitForLoadState('networkidle');
                await expect(page.getByRole('heading', { name: 'Welcome back', exact: true })).toBeVisible();
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
                const native = await page.screenshot({ path: `docs/ui/original-style-login-${label}-${theme}.png`, animations: 'disabled' });
                const reference = await context.newPage();
                expect((await reference.goto(`${qa}/__qa/original-login`)).status()).toBe(200);
                await reference.evaluate(() => document.fonts.ready);
                await reference.waitForLoadState('networkidle');
                const original = await reference.screenshot({ path: `docs/ui/source-login-reference-${label}-${theme}.png`, animations: 'disabled' });
                const difference = await comparePixels(page, native, original);
                results.push({ viewport: label, theme, ...difference });
                await fs.writeFile('storage/testing/original-login-measurements.json', JSON.stringify(results, null, 2));
                expect(difference.ratio, `${label}/${theme} original login difference`).toBeLessThan(0.002);
                await page.getByLabel('Password', { exact: true }).fill('Synthetic visual control');
                await page.getByRole('button', { name: 'Show password', exact: true }).click();
                await expect(page.locator('#password')).toHaveAttribute('type', 'text');
                await page.locator('#togglePassword').click();
                await expect(page.locator('#password')).toHaveAttribute('type', 'password');
                await page.locator('#themeToggle').click();
                expect(await page.locator('html').getAttribute('data-theme')).toBe(theme === 'light' ? 'dark' : null);
                expect(errors).toEqual([]);
                expect(external).toEqual([]);
            } finally { await context.close(); }
        }
    }
    await fs.writeFile('docs/audit/ORIGINAL_LOGIN_BROWSER.json', JSON.stringify({ date: '2026-10-26', reference: 'Static original initial login presentation from source text, with original shared CSS and theme script; no legacy PHP executed', reduced_motion: true, results }, null, 2) + '\n');
});

test('original catalog layout supports categories search allergen filter and private access link on desktop and mobile', async ({ browser, request }) => {
    expect((await (await request.get(`${qa}/__qa/target`)).json()).isolated).toBe(true);
    for (const [label, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
        const context = await browser.newContext({ viewport, reducedMotion: 'reduce' });
        try {
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            for (const theme of ['light', 'dark']) {
                expect((await page.goto(`${qa}/catalog`)).status()).toBe(200);
                await page.evaluate(value => localStorage.setItem('manage-grocery-theme', value), theme);
                await page.reload();
                await page.evaluate(() => document.fonts.ready);
                await expect(page.getByRole('heading', { name: 'Shop at Grocery Store' })).toBeVisible();
                await expect(page.getByRole('heading', { name: 'Allergen safety', exact: true })).toBeVisible();
                await expect(page.getByRole('link', { name: 'Open private catalog access', exact: true })).toHaveAttribute('href', /\/project\/catalog_supervisor_login.php\?embedded=1$/);
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
                await page.screenshot({ path: `docs/ui/original-style-catalog-${label}-${theme}.png`, fullPage: true, animations: 'disabled' });
            }
            await page.getByPlaceholder('Search products...', { exact: true }).fill('Synthetic pantry');
            await page.waitForURL(/search=Synthetic/);
            await expect(page.locator('.product-grid .product-card')).toHaveCount(1);
            await page.getByRole('link', { name: 'Clear filters', exact: true }).click();
            await page.getByLabel('Hide products containing', { exact: true }).selectOption('milk');
            await page.waitForURL(/exclude_allergen=milk/);
            await expect(page.getByRole('note')).toContainText('verified label information only');
            expect(errors).toEqual([]);
        } finally { await context.close(); }
    }
});

test('original product presentation matches Blade with safe verified facts in both themes and viewports', async ({ browser, request }) => {
    expect((await (await request.get(`${qa}/__qa/target`)).json()).isolated).toBe(true);
    const lookup = await request.get(`${qa}/catalog/scan?b=4006381333931`, { maxRedirects: 0 });
    expect(lookup.status()).toBe(302);
    const productUrl = new URL(lookup.headers().location, qa);
    const id = productUrl.pathname.split('/').pop();
    const results = [];
    for (const [label, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
        for (const theme of ['light', 'dark']) {
            const context = await browser.newContext({ viewport, reducedMotion: 'reduce' });
            try {
                await context.addInitScript(value => localStorage.setItem('manage-grocery-theme', value), theme);
                const page = await context.newPage();
                const errors = [], external = [];
                page.on('pageerror', error => errors.push(error.message));
                page.on('request', request => { if (new URL(request.url()).origin !== qa) external.push(request.url()); });
                expect((await page.goto(productUrl.href)).status()).toBe(200);
                await page.evaluate(() => document.fonts.ready);
                await page.waitForLoadState('networkidle');
                await expect(page.getByRole('heading', { name: 'Synthetic label sample', exact: true })).toBeVisible();
                await expect(page.locator('.stock')).toHaveText('Out of stock');
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
                const native = await page.screenshot({ path: `docs/ui/original-style-product-${label}-${theme}.png`, fullPage: true, animations: 'disabled' });
                const reference = await context.newPage();
                expect((await reference.goto(`${qa}/__qa/original-product/${id}`)).status()).toBe(200);
                await reference.evaluate(() => document.fonts.ready);
                await reference.waitForLoadState('networkidle');
                const original = await reference.screenshot({ path: `docs/ui/source-product-reference-${label}-${theme}.png`, fullPage: true, animations: 'disabled' });
                const difference = await comparePixels(page, native, original);
                results.push({ viewport: label, theme, ...difference });
                await fs.writeFile('storage/testing/original-product-measurements.json', JSON.stringify(results, null, 2));
                expect(difference.ratio, `${label}/${theme} original product difference`).toBeLessThan(0.002);
                await page.getByRole('button', { name: theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode', exact: true }).click();
                expect(await page.locator('html').getAttribute('data-theme')).toBe(theme === 'light' ? 'dark' : null);
                await expect(page.getByRole('link', { name: 'Back to catalog', exact: true })).toHaveAttribute('href', `${qa}/catalog`);
                expect(external).toEqual([]);
                expect(errors).toEqual([]);
            } finally { await context.close(); }
        }
    }
    await fs.writeFile('docs/audit/ORIGINAL_PRODUCT_BROWSER.json', JSON.stringify({ date: '2026-10-26', reference: 'Pinned original presentation only; native synthetic public data; no original bootstrap/config/query/scan logging executed', reduced_motion: true, capture: 'full page', results }, null, 2) + '\n');
});

test('original comparison initial view and result table match source presentation across themes and viewports', async ({ browser, request }) => {
    expect((await (await request.get(`${qa}/__qa/target`)).json()).isolated).toBe(true);
    const results = [];
    for (const [label, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
        for (const theme of ['light', 'dark']) {
            const context = await browser.newContext({ viewport, reducedMotion: 'reduce' });
            try {
                await context.addInitScript(value => localStorage.setItem('manage-grocery-theme', value), theme);
                const page = await context.newPage(), reference = await context.newPage();
                const errors = [], external = [];
                page.on('pageerror', error => errors.push(error.message));
                page.on('request', request => { if (new URL(request.url()).origin !== qa) external.push(request.url()); });
                expect((await page.goto(`${qa}/compare`)).status()).toBe(200);
                await page.waitForLoadState('networkidle');
                expect((await reference.goto(`${qa}/__qa/original-comparison`)).status()).toBe(200);
                await reference.waitForLoadState('networkidle');
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
                const native = await page.screenshot({ path: `docs/ui/original-style-comparison-${label}-${theme}.png`, animations: 'disabled' });
                const original = await reference.screenshot({ path: `docs/ui/source-comparison-reference-${label}-${theme}.png`, animations: 'disabled' });
                const initial = await comparePixels(page, native, original);
                results.push({ state: 'initial viewport', viewport: label, theme, ...initial });
                await fs.writeFile('storage/testing/original-comparison-measurements.json', JSON.stringify(results, null, 2));
                expect(initial.ratio, `${label}/${theme} initial comparison difference`).toBeLessThan(0.002);
                const ids = await page.getByLabel('Comparison product 1', { exact: true }).locator('option').evaluateAll(options => options.map(option => option.value).filter(Boolean).slice(0, 2));
                expect(ids).toHaveLength(2);
                await page.getByLabel('Comparison product 1', { exact: true }).selectOption(ids[0]);
                await page.getByLabel('Comparison product 2', { exact: true }).selectOption(ids[1]);
                await page.getByRole('button', { name: 'Compare products', exact: true }).click();
                await expect(page.locator('.comparison-table')).toBeVisible();
                await expect(page.getByLabel('Comparison product 1', { exact: true })).toHaveValue(ids[0]);
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
                const query = new URLSearchParams(); ids.forEach(id => query.append('ids[]', id));
                expect((await reference.goto(`${qa}/__qa/original-comparison?${query}`)).status()).toBe(200);
                await reference.waitForLoadState('networkidle');
                const nativeTable = await page.locator('.table-wrap').screenshot({ path: `docs/ui/original-style-comparison-table-${label}-${theme}.png` });
                const originalTable = await reference.locator('.table-wrap').screenshot({ path: `docs/ui/source-comparison-table-reference-${label}-${theme}.png` });
                const table = await comparePixels(page, nativeTable, originalTable);
                results.push({ state: 'selected result table', viewport: label, theme, ...table });
                await fs.writeFile('storage/testing/original-comparison-measurements.json', JSON.stringify(results, null, 2));
                expect(table.ratio, `${label}/${theme} selected table difference`).toBeLessThan(0.002);
                expect(errors).toEqual([]);
                expect(external).toEqual([]);
            } finally { await context.close(); }
        }
    }
    await fs.writeFile('docs/audit/ORIGINAL_COMPARISON_BROWSER.json', JSON.stringify({ date: '2026-10-26', reference: 'Pinned source presentation using native synthetic verified data; no original bootstrap/config/queries executed', captures: 'Initial viewport and selected table only; native selected picker state is intentionally retained', reduced_motion: true, results }, null, 2) + '\n');
});

test('original POS and HR verification screens match source presentation with private pending authentication', async ({ browser, request }) => {
    const target = await (await request.get(`${qa}/__qa/target`)).json();
    expect(target.isolated && target.mail_isolated).toBe(true);
    const results = [];
    for (const domain of ['pos', 'hr']) {
        const authentication = await browser.newContext();
        let state;
        try {
            const page = await authentication.newPage();
            await page.goto(`${qa}/login`);
            await page.getByLabel('Username', { exact: true }).fill(`demo_mfa_${domain}_ui`);
            await page.getByLabel('Password', { exact: true }).fill('Synthetic-demo-only!123');
            await page.getByRole('button', { name: 'Login', exact: true }).click();
            await page.waitForURL(`${qa}/two-factor`);
            await expect(page.getByRole('heading', { name: "Verify it's you", exact: true })).toBeVisible();
            state = await authentication.storageState();
        } finally { await authentication.close(); }
        // Reuse this password-verified pending session in memory. No repeat mail,
        // recovery-code consumption or authenticated workspace is involved.
        for (const [label, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
            for (const theme of ['light', 'dark']) {
                const context = await browser.newContext({ viewport, reducedMotion: 'reduce', storageState: state });
                try {
                    await context.addInitScript(value => localStorage.setItem('manage-grocery-theme', value), theme);
                    const page = await context.newPage();
                    const errors = [], external = [];
                    page.on('pageerror', error => errors.push(error.message));
                    page.on('request', request => { if (new URL(request.url()).origin !== qa) external.push(request.url()); });
                    const response = await page.goto(`${qa}/two-factor`);
                    expect(response.status()).toBe(200);
                    expect(response.headers()['cache-control']).toContain('no-store');
                    await page.evaluate(() => document.fonts.ready);
                    await page.waitForLoadState('networkidle');
                    await page.bringToFront();
                    await page.getByLabel('Verification code', { exact: true }).focus();
                    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
                    const native = await page.screenshot({ path: `docs/ui/original-style-mfa-${domain}-${label}-${theme}.png`, fullPage: true, animations: 'disabled', caret: 'hide' });
                    const reference = await context.newPage();
                    expect((await reference.goto(`${qa}/__qa/original-mfa`)).status()).toBe(200);
                    await reference.evaluate(() => document.fonts.ready);
                    await reference.waitForLoadState('networkidle');
                    await reference.bringToFront();
                    await reference.locator('.otp-input').focus();
                    const original = await reference.screenshot({ path: `docs/ui/source-mfa-${domain}-reference-${label}-${theme}.png`, fullPage: true, animations: 'disabled', caret: 'hide' });
                    const difference = await comparePixels(page, native, original);
                    results.push({ domain, viewport: label, theme, ...difference });
                    await fs.writeFile('storage/testing/original-mfa-measurements.json', JSON.stringify(results, null, 2));
                    expect(difference.ratio, `${domain}/${label}/${theme} original verification difference`).toBeLessThan(0.002);
                    await page.locator('details.backup-box > summary').click();
                    await expect(page.getByLabel('Backup code', { exact: true })).toBeVisible();
                    expect(external).toEqual([]);
                    expect(errors).toEqual([]);
                } finally { await context.close(); }
            }
        }
    }
    await fs.writeFile('docs/audit/ORIGINAL_MFA_BROWSER.json', JSON.stringify({ date: '2026-10-26', reference: 'Pinned original POS/HR verification presentation; same native synthetic pending identity and local fonts; no legacy runtime executed', capture: 'Full page, normal enrolled verification with empty OTP and closed backup panel; caret hidden', limits: 'Transport/enrollment/recovery-code display states are outside pixel comparison; Cancel uses existing native POST logout and native resend retry remains', results }, null, 2) + '\n');
});
