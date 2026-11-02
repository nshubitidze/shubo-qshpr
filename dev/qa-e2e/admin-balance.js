// Admin: the Quickshipper balance in the header. Logs in, opens the dashboard
// and then Sales → Orders, and reads the balance shown at the top of both.
// Read-only: nothing is created anywhere (the balance is one GET /v1/Account).
//
// Needs "Show Balance in Admin Header" = Yes and a Quickshipper login saved.
//
//   BASE_URL=http://localhost:8080 ADMIN_PATH=<admin front name> ADMIN_USER=<user> \
//   ADMIN_PASSWORD=<password> ./run.sh admin-balance.js
//
// EXPECT_HIDDEN=1 checks the opposite: the setting is off and nothing is shown.

const { chromium } = require('playwright');

const BASE = (process.env.BASE_URL || 'http://localhost').replace(/\/$/, '');
const ADMIN = `${BASE}/${(process.env.ADMIN_PATH || 'admin').replace(/^\/|\/$/g, '')}`;
const USER = process.env.ADMIN_USER || '';
const PASSWORD = process.env.ADMIN_PASSWORD || '';
const EXPECT_HIDDEN = process.env.EXPECT_HIDDEN === '1';
const SHOTS = __dirname + '/shots';
const BALANCE = '.page-header-actions .shubo-qshpr-balance';
const VALUE = `${BALANCE} .admin__action-dropdown [data-role="balance"]`;

const shot = (page, name) => page.screenshot({ path: `${SHOTS}/admin-balance-${name}.png` }).catch(() => {});

(async () => {
    if (!USER || !PASSWORD) {
        console.log('FAIL  set ADMIN_USER and ADMIN_PASSWORD');
        process.exit(2);
    }

    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL: BASE, viewport: { width: 1440, height: 900 } });
    let failed = false;
    const check = (name, ok, detail = '') => {
        console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`);
        failed = failed || !ok;
    };
    const balanceText = async () => {
        await page.waitForFunction(
            (selector) => document.querySelector(selector)?.textContent.trim() !== '…',
            VALUE,
            { timeout: 30000 }
        );
        // Magento's "Incoming Message" popup covers the header until closed.
        await page.waitForLoadState('networkidle');
        const popupClose = page.locator('.modal-popup._show .action-close');
        if (await popupClose.count() > 0) {
            await popupClose.first().click();
            await page.waitForSelector('.modals-overlay', { state: 'detached', timeout: 5000 }).catch(() => {});
        }
        await page.click(`${BALANCE} .admin__action-dropdown`);
        const menu = (await page.locator(`${BALANCE} .admin__action-dropdown-menu`).innerText()).replace(/\s+/g, ' ').trim();
        await page.click(`${BALANCE} .admin__action-dropdown`);

        return `${(await page.locator(VALUE).textContent()).trim()} | menu: ${menu}`;
    };

    try {
        await page.goto(`${ADMIN}/`, { waitUntil: 'domcontentloaded' });
        await page.fill('#username', USER);
        await page.fill('#login', PASSWORD);
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('.action-login')]);
        check('admin login', await page.locator('#menu-magento-sales-sales').count() > 0);

        if (EXPECT_HIDDEN) {
            check('balance hidden when switched off', await page.locator(BALANCE).count() === 0);
        } else {
            const dashboard = await balanceText();
            await shot(page, '1-dashboard');
            check('dashboard header shows the balance', /\d/.test(dashboard), dashboard);

            const link = page.locator(`${BALANCE} .admin__action-dropdown-menu a[target="_blank"]`);
            const href = await link.getAttribute('href');
            check('balance links to the Quickshipper dashboard in a new tab',
                /quickshipper\.(ge|app)/.test(href || '') && await link.getAttribute('target') === '_blank', href);

            const ordersUrl = await page.locator('#menu-magento-sales-sales a[href*="/sales/order/"]').first().getAttribute('href');
            await page.goto(ordersUrl, { waitUntil: 'domcontentloaded' });
            const orders = await balanceText();
            await shot(page, '2-orders');
            check('orders page header shows the balance', /\d/.test(orders), orders);
        }
    } catch (e) {
        await shot(page, 'error');
        check('admin balance flow completed', false, e.message.split('\n')[0]);
    }

    await browser.close();
    console.log(failed ? '\nFAILED' : '\nALL PASS');
    process.exit(failed ? 1 : 0);
})();
