// Admin: ship one order the way a merchant does — Sales → Orders → the order →
// Ship → Submit Shipment — and report what Magento shows afterwards (messages,
// tracking number, order comments).
//
// For an order placed with Quickshipper this BOOKS A REAL COURIER at
// Quickshipper (sandbox credit is used): run it only when that is intended.
//
//   BASE_URL=http://localhost:8080 ADMIN_PATH=<admin front name> ADMIN_USER=<user> \
//   ADMIN_PASSWORD=<password> ORDER_NUMBER=<increment id> ./run.sh admin-ship.js
//
// Admin URLs carry secret keys, so the script navigates by clicking, as a person
// would, instead of opening URLs directly.

const { chromium } = require('playwright');

const BASE = (process.env.BASE_URL || 'http://localhost').replace(/\/$/, '');
const ADMIN = `${BASE}/${(process.env.ADMIN_PATH || 'admin').replace(/^\/|\/$/g, '')}`;
const USER = process.env.ADMIN_USER || '';
const PASSWORD = process.env.ADMIN_PASSWORD || '';
const ORDER_NUMBER = process.env.ORDER_NUMBER || '';
const SHOTS = __dirname + '/shots';

const shot = (page, name) => page.screenshot({ path: `${SHOTS}/admin-ship-${name}.png`, fullPage: true }).catch(() => {});
const texts = (page, selector) => page.$$eval(selector, (els) => els.map((el) => el.innerText.replace(/\s+/g, ' ').trim()));

(async () => {
    if (!USER || !PASSWORD || !ORDER_NUMBER) {
        console.log('FAIL  set ADMIN_USER, ADMIN_PASSWORD and ORDER_NUMBER');
        process.exit(2);
    }

    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL: BASE });
    let failed = false;
    const check = (name, ok, detail = '') => {
        console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`);
        failed = failed || !ok;
    };

    try {
        await page.goto(`${ADMIN}/`, { waitUntil: 'domcontentloaded' });
        await page.fill('#username', USER);
        await page.fill('#login', PASSWORD);
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('.action-login')]);
        check('admin login', await page.locator('#menu-magento-sales-sales').count() > 0);

        // Sales → Orders (the menu link carries the secret key).
        const ordersUrl = await page.locator('#menu-magento-sales-sales a[href*="/sales/order/"]').first().getAttribute('href');
        await page.goto(ordersUrl, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#fulltext', { timeout: 60000 });
        await page.fill('#fulltext', ORDER_NUMBER);
        await page.press('#fulltext', 'Enter');
        const row = page.locator('tr.data-row', { hasText: ORDER_NUMBER }).first();
        await row.waitFor({ timeout: 60000 });
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), row.locator('a.action-menu-item').click()]);
        check('order opened', (await page.title()).includes(ORDER_NUMBER), await page.title());

        // Ship → Submit Shipment.
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('#order_ship')]);
        await shot(page, '1-new-shipment');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 120000 }),
            page.click('button.submit-button'),
        ]);
        await shot(page, '2-after-submit');

        const success = await texts(page, '.message-success');
        const warnings = await texts(page, '.message-warning');
        const errors = await texts(page, '.message-error');
        success.forEach((m) => console.log(`      success: ${m}`));
        warnings.forEach((m) => console.log(`      warning: ${m}`));
        errors.forEach((m) => console.log(`      error:   ${m}`));
        check('shipment submitted without an error', errors.length === 0 && success.length > 0);

        const comments = await texts(page, '#order_history_block .note-list-comment');
        comments.slice(0, 3).forEach((c) => console.log(`      order comment: ${c}`));
        check('order comment about the booking', comments.some((c) => c.includes('Quickshipper')));
    } catch (e) {
        await shot(page, 'error');
        check('admin ship flow completed', false, e.message.split('\n')[0]);
    } finally {
        await browser.close();
    }

    console.log(failed ? '\nFAILED' : '\nALL PASS');
    process.exit(failed ? 1 : 0);
})();
