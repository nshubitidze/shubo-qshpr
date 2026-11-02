// Storefront checkout with Quickshipper rates (guest, Luma):
//  1. add a product, open checkout, type a Tbilisi address
//     → Quickshipper couriers appear once the street is typed
//  2. change ONLY the street to another Tbilisi address → prices are asked
//     again (Luma re-asks by default only for country/postcode/city changes)
//  3. change to a Batumi address → they disappear (Quickshipper is intra-city)
//  4. back to Tbilisi, pick a courier, pay with Check / Money order
//     → success page; prints the order number and the chosen method
//
// Needs on the store: Quickshipper enabled with sandbox credentials, pickup
// coordinates and Shipping Origin in Tbilisi, Check / Money order enabled.
//
//   BASE_URL=http://localhost:8080 PRODUCT_ID=1 ./run.sh checkout-rates.js
//
// RATES_ONLY=1 stops after step 1 (no order): shows the prices for a product,
// e.g. the weighted parcels from dev/checks/create-test-products.php.
// LOGOS=0 skips the courier logo check (for a store with logos switched off).
// COD=hidden|shown checks Magento's Cash On Delivery payment at the payment step
// (needs it enabled): hidden when the chosen courier cannot collect cash.
// COURIER=<Quickshipper courier id> (e.g. 2 = Wolt) orders with that courier,
// to the second Tbilisi address (a shorter trip), instead of the cheapest.

const { chromium } = require('playwright');

const BASE = (process.env.BASE_URL || 'http://localhost').replace(/\/$/, '');
const PRODUCT_ID = process.env.PRODUCT_ID || '1';
const RATES_ONLY = process.env.RATES_ONLY === '1';
const COURIER = process.env.COURIER || '';
const SHOTS = __dirname + '/shots';
const RATE = '#checkout-shipping-method-load input[type=radio][value^="shuboqshpr_"]';

const TBILISI = { street: 'ია კარგარეთელის ქუჩა 6', city: 'თბილისი', postcode: '0179' };
const TBILISI_2 = { street: 'Iakob Nikoladzis 7', city: 'თბილისი', postcode: '0179' };
const BATUMI = { street: 'ვაჟა-ფშაველას ქუჩა 33', city: 'ბათუმი', postcode: '6000' };

const shot = (page, name) => page.screenshot({ path: `${SHOTS}/checkout-rates-${name}.png`, fullPage: true }).catch(() => {});

// Luma takes a typed value when the field is left, so each field is left
// with Tab, as a customer does.
async function typeField(page, selector, value) {
    await page.fill(selector, value);
    await page.press(selector, 'Tab');
}

async function typeAddress(page, address) {
    await typeField(page, 'input[name=city]', address.city);
    await typeField(page, 'input[name=postcode]', address.postcode);
    await typeField(page, 'input[name="street[0]"]', address.street);
}

async function quickshipperRates(page) {
    return page.$$eval(RATE, (inputs) => inputs.map((input) => {
        const row = input.closest('tr');
        const cell = (cls) => [...row.querySelectorAll(cls)].map((td) => td.innerText.trim()).filter(Boolean).join(' ');

        return `${input.value}: ${cell('.col-method').trim()} ${cell('.col-price').trim()}`;
    }));
}

(async () => {
    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL: BASE, locale: 'ka-GE' });
    const page = await context.newPage();
    const estimates = [];
    page.on('request', (r) => {
        if (r.url().includes('estimate-shipping-methods')) {
            estimates.push(r.postData() || '');
        }
    });
    let failed = false;
    const check = (name, ok, detail = '') => {
        console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`);
        failed = failed || !ok;
    };

    try {
        const formKey = 'QshprE2eFormKey1';
        await context.addCookies([{ name: 'form_key', value: formKey, url: BASE }]);
        const add = await context.request.post(`${BASE}/checkout/cart/add/product/${PRODUCT_ID}/`, {
            form: { form_key: formKey, qty: '1' },
        });
        check('product added to cart', add.status() < 400, `HTTP ${add.status()}`);

        await page.goto('/checkout/', { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#customer-email', { timeout: 60000 });
        await page.fill('#customer-email', 'qshpr-e2e@example.com');
        await page.fill('input[name=firstname]', 'Qshpr');
        await page.fill('input[name=lastname]', 'E2E');
        await page.locator('select[name=country_id]').selectOption('GE');
        await page.fill('input[name=telephone]', '+995555123456');

        // 1. Tbilisi: couriers appear. The sandbox can take ~10 s on its first
        // quote after a quiet period and then shows nothing, so one retype.
        await typeAddress(page, TBILISI);
        let appeared = await page.waitForSelector(RATE, { timeout: 30000 }).then(() => true, () => false);
        if (!appeared) {
            await typeAddress(page, { ...TBILISI, street: TBILISI.street + ' ' });
            appeared = await page.waitForSelector(RATE, { timeout: 30000 }).then(() => true, () => false);
        }
        const rates = appeared ? await quickshipperRates(page) : [];
        check('Tbilisi address → Quickshipper couriers offered', rates.length > 0, rates.join(' | '));
        if (process.env.LOGOS !== '0') {
            // The rows render before the images arrive from Quickshipper's
            // image server, which can take a few seconds.
            await page.waitForFunction(
                () => [...document.querySelectorAll('#checkout-shipping-method-load img.shubo-qshpr-logo')]
                    .every((img) => img.complete),
                null,
                { timeout: 20000 }
            ).catch(() => {});
            const logos = await page.$$eval('#checkout-shipping-method-load img.shubo-qshpr-logo', (imgs) => imgs.map(
                (img) => `${img.complete && img.naturalWidth > 0 ? 'loaded' : 'not loaded'} ${img.getAttribute('src')}`
            ));
            check('courier logos shown and loaded beside the couriers',
                logos.length === rates.length && logos.every((l) => l.startsWith('loaded')), logos.join(' | '));
        }
        await shot(page, '1-tbilisi');
        if (RATES_ONLY) {
            throw Object.assign(new Error('rates only'), { ratesOnly: true });
        }

        // 2. Street only → a new estimate for the new street.
        const before = estimates.length;
        await typeField(page, 'input[name="street[0]"]', TBILISI_2.street);
        await page.waitForTimeout(4000);  // Luma waits 2 s after the last change
        const newStreet = estimates.slice(before).some((body) => body.includes(TBILISI_2.street));
        check('street-only change → prices asked again for the new street', newStreet,
            `${estimates.length - before} new estimate request(s)`);
        const stillOffered = await page.waitForSelector(RATE, { timeout: 30000 }).then(() => true, () => false);
        check('second Tbilisi street → couriers offered', stillOffered,
            stillOffered ? (await quickshipperRates(page)).join(' | ') : '');

        // 3. Batumi: intercity from a Tbilisi pickup — no couriers.
        await typeAddress(page, BATUMI);
        const gone = await page.waitForSelector(RATE, { state: 'detached', timeout: 30000 }).then(() => true, () => false);
        check('Batumi address → Quickshipper not offered', gone);
        await shot(page, '2-batumi');

        // 4. Back to Tbilisi, pick a courier, place the order.
        await typeAddress(page, COURIER ? TBILISI_2 : TBILISI);
        const pick = COURIER ? `${RATE.replace('^="shuboqshpr_"', `^="shuboqshpr_${COURIER}_"`)}` : RATE;
        await page.waitForSelector(pick, { timeout: 60000 });
        const chosen = await page.locator(pick).first().getAttribute('value');
        await page.locator(pick).first().check();
        await shot(page, '3-chosen');
        await page.click('button[data-role=opc-continue]');

        // With a single payment method Luma hides its radio and selects it
        // itself, so wait for the input to exist, not to be visible.
        const checkmo = page.locator('input#checkmo');
        await checkmo.waitFor({ state: 'attached', timeout: 60000 });
        if (await checkmo.isVisible() && !(await checkmo.isChecked())) {
            await checkmo.click();
        }
        if (process.env.COD) {
            const codOffered = await page.locator('input#cashondelivery').count() > 0;
            check(`Cash On Delivery ${process.env.COD} for courier ${chosen}`,
                process.env.COD === 'hidden' ? !codOffered : codOffered);
        }
        const summaryShipping = await page.locator('.opc-block-summary .totals.shipping').innerText().catch(() => '');
        await page.locator('.payment-method._active button.action.primary.checkout').click();
        await page.waitForURL('**/checkout/onepage/success/**', { timeout: 120000 });
        const orderNumber = (await page.locator('.checkout-success .order-number strong, .checkout-success p span').first().innerText()).trim();
        await shot(page, '4-success');
        check('order placed with a Quickshipper method', /^\d+$/.test(orderNumber),
            `order ${orderNumber}, method ${chosen}, summary "${summaryShipping.replace(/\s+/g, ' ').trim()}"`);
    } catch (e) {
        if (e.ratesOnly) {
            await browser.close();
            console.log(failed ? '\nFAILED' : '\nALL PASS');
            process.exit(failed ? 1 : 0);
        }
        await shot(page, 'error');
        check('checkout flow completed', false, e.message.split('\n')[0]);
    }

    await browser.close();
    console.log(failed ? '\nFAILED' : '\nALL PASS');
    process.exit(failed ? 1 : 0);
})();
