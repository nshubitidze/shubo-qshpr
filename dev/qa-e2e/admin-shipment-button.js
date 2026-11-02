// Admin: press a Quickshipper button on a shipment page the way a merchant
// does — Sales → Shipments → the order's newest shipment → "Cancel Quickshipper
// Delivery" or "Book Courier Again" → OK — and report what Magento shows
// afterwards (messages, buttons, tracking numbers, shipment comments).
//
// Both buttons act at Quickshipper: cancel ends a real booking, rebook BOOKS A
// NEW COURIER (sandbox credit is used). Run it only when that is intended.
//
//   BASE_URL=http://localhost:8080 ADMIN_PATH=<admin front name> ADMIN_USER=<user> \
//   ADMIN_PASSWORD=<password> ORDER_NUMBER=<increment id> BUTTON=cancel|rebook \
//   ./run.sh admin-shipment-button.js
//
// Admin URLs carry secret keys, so the script navigates by clicking, as a person
// would, instead of opening URLs directly.

const { chromium } = require('playwright');

const BASE = (process.env.BASE_URL || 'http://localhost').replace(/\/$/, '');
const ADMIN = `${BASE}/${(process.env.ADMIN_PATH || 'admin').replace(/^\/|\/$/g, '')}`;
const USER = process.env.ADMIN_USER || '';
const PASSWORD = process.env.ADMIN_PASSWORD || '';
const ORDER_NUMBER = process.env.ORDER_NUMBER || '';
const BUTTON = process.env.BUTTON || '';
const BUTTONS = { cancel: '#shubo_qshpr_cancel', rebook: '#shubo_qshpr_rebook' };
const SHOTS = __dirname + '/shots';

const shot = (page, name) => page.screenshot({ path: `${SHOTS}/admin-button-${BUTTON}-${name}.png`, fullPage: true }).catch(() => {});
const texts = (page, selector) => page.$$eval(selector, (els) => els.map((el) => el.innerText.replace(/\s+/g, ' ').trim()));

(async () => {
    if (!USER || !PASSWORD || !ORDER_NUMBER || !BUTTONS[BUTTON]) {
        console.log('FAIL  set ADMIN_USER, ADMIN_PASSWORD, ORDER_NUMBER and BUTTON=cancel|rebook');
        process.exit(2);
    }

    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL: BASE });
    let failed = false;
    const check = (name, ok, detail = '') => {
        console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`);
        failed = failed || !ok;
    };
    const report = async () => {
        (await texts(page, '.message-success')).forEach((m) => console.log(`      success: ${m}`));
        (await texts(page, '.message-warning')).forEach((m) => console.log(`      warning: ${m}`));
        (await texts(page, '.message-error')).forEach((m) => console.log(`      error:   ${m}`));
        (await texts(page, '.page-actions-buttons button')).forEach((b) => console.log(`      button:  ${b}`));
        (await texts(page, '#shipment_tracking_info tbody tr')).forEach((t) => console.log(`      track:   ${t}`));
        (await texts(page, '.note-list-comment')).slice(0, 4).forEach((c) => console.log(`      comment: ${c}`));
    };

    try {
        await page.goto(`${ADMIN}/`, { waitUntil: 'domcontentloaded' });
        await page.fill('#username', USER);
        await page.fill('#login', PASSWORD);
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('.action-login')]);
        check('admin login', await page.locator('#menu-magento-sales-sales').count() > 0);

        // Sales → Shipments (the menu link carries the secret key).
        const shipmentsUrl = await page.locator('#menu-magento-sales-sales a[href*="/sales/shipment/"]').first().getAttribute('href');
        await page.goto(shipmentsUrl, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#fulltext', { timeout: 60000 });
        await page.fill('#fulltext', ORDER_NUMBER);
        await page.press('#fulltext', 'Enter');
        const row = page.locator('tr.data-row', { hasText: ORDER_NUMBER }).first();
        await row.waitFor({ timeout: 60000 });
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), row.locator('a.action-menu-item').click()]);
        check('shipment opened', (await page.title()).includes('#'), await page.title());
        await shot(page, '1-before');

        const button = page.locator(BUTTONS[BUTTON]);
        check(`button "${BUTTON}" shown`, await button.count() > 0);
        await button.click();
        // Magento's confirm modal.
        await page.locator('.modal-popup._show .action-accept').click();
        await page.waitForLoadState('domcontentloaded');
        await page.waitForSelector('.page-actions-buttons', { timeout: 120000 });
        await shot(page, '2-after');
        await report();
        check('no error message', (await texts(page, '.message-error')).length === 0);
    } catch (e) {
        await shot(page, 'error');
        check('admin button flow completed', false, e.message.split('\n')[0]);
    } finally {
        await browser.close();
    }

    console.log(failed ? '\nFAILED' : '\nALL PASS');
    process.exit(failed ? 1 : 0);
})();
