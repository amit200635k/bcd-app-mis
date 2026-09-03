'use strict';

const path = require('path');
const puppeteer = require('puppeteer');
const {
    BASE, step, ok, type, waitForText, hasText,
    assertNoPhpWarnings, wirePage, summary,
} = require('./lib.js');

const CREDS = require('./lib.js').CREDS;

const PHP_BIN = process.env.PHP_BIN || 'php';

const ROLES = ['admin', 'district', 'block', 'panchayat', 'village', 'surveyor'];

async function run() {
    const browser = await puppeteer.launch({
        headless: false,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const page = await browser.newPage();
    wirePage(page, 'login-test');

    const results = [];

    function check(name, cond) {
        const pass = !!cond;
        results.push({ name, pass });
        console.log(pass ? '  ✅' : '  ❌', name);
    }

    // ── 1. Login page renders correctly ──
    step('Login page: renders with professional design');
    await page.goto(BASE + '/mis/login.php', { waitUntil: 'networkidle0' });

    check('page title is "Sign In — BCD Survey Platform"',
        await page.title() === 'Sign In — BCD Survey Platform');

    check('login card rendered', await page.$('.login-card') !== null);
    check('login header with gradient background', await page.$('.login-header') !== null);
    check('brand icon (clipboard) present', await page.$('.brand-icon') !== null);
    check('heading "BCD Survey Platform"', await hasText(page, 'BCD Survey Platform'));
    check('subtitle "MIS Portal"', await hasText(page, 'MIS Portal'));
    check('username input field', await page.$('input[name=username]') !== null);
    check('password input field', await page.$('input[name=password]') !== null);
    check('sign-in button', await page.$('button[type=submit]') !== null);
    check('sign-in button text "Sign In"', await hasText(page, 'Sign In'));
    check('footer copyright', await hasText(page, 'BCD Survey Platform'));

    await assertNoPhpWarnings(page, 'login.php');

    // ── 2. Form validation ──
    step('Login page: form validation');
    await page.click('button[type=submit]');
    await page.waitForTimeout(500);
    check('browser enforces required fields (HTML5 validation)',
        await page.$('input:invalid') !== null);

    // ── 3. Invalid credentials ──
    step('Login page: rejects wrong credentials');
    await type(page, 'input[name=username]', 'admin');
    await type(page, 'input[name=password]', 'wrongpass');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type=submit]'),
    ]);
    check('shows error message', await hasText(page, 'Invalid credentials'));
    check('stays on login page', page.url().includes('/mis/login.php'));

    // ── 4. Valid login for each role ──
    for (const role of ROLES) {
        const creds = CREDS[role];
        if (!creds) {
            check(`skipping ${role} — no credentials`, false);
            continue;
        }

        step(`Login page: ${role} login`);

        // Re-login
        await page.goto(BASE + '/mis/login.php', { waitUntil: 'networkidle0' });
        await type(page, 'input[name=username]', creds.username);
        await type(page, 'input[name=password]', creds.password);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('button[type=submit]'),
        ]);

        check(`${role} redirects to dashboard`, /mis\/dashboard\.php/.test(page.url()));
        check(`${role} shows Dashboard heading`, await hasText(page, 'Dashboard'));
        await assertNoPhpWarnings(page, 'dashboard.php');

        // Check sidebar navigation is present
        check(`${role} sidebar present`, await page.$('.sidebar') !== null);
        check(`${role} user menu present`, await page.$('.user-menu') !== null);
        check(`${role} topbar present`, await page.$('.topbar') !== null);

        // Check role-specific sidebar links
        if (role === 'admin') {
            check(`${role} sees Admin Panel link`, await hasText(page, 'Admin Panel'));
        } else {
            check(`${role} does NOT see Admin Panel link`,
                !(await hasText(page, 'Admin Panel')));
        }

        // Logout
        await page.goto(BASE + '/mis/logout.php', { waitUntil: 'networkidle0' });
        check(`${role} redirected to login after logout`, page.url().includes('/mis/login.php'));
    }

    // ── 5. Admin panel access for each role ──
    step('Login page: admin panel access by role');
    for (const role of ROLES) {
        const creds = CREDS[role];
        if (!creds) continue;

        step(`Admin panel: ${role} attempts access`);

        // Login
        await page.goto(BASE + '/mis/login.php', { waitUntil: 'networkidle0' });
        await type(page, 'input[name=username]', creds.username);
        await type(page, 'input[name=password]', creds.password);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('button[type=submit]'),
        ]);

        // Try to access admin panel
        await page.goto(BASE + '/admin/dashboard.php', { waitUntil: 'networkidle0' });
        const body = await page.evaluate(() => document.body.innerText).catch(() => '');
        const isBlocked = /403/.test(body);

        if (role === 'admin') {
            check(`${role} CAN access admin panel`, !isBlocked);
            check(`${role} sees Admin Dashboard`, await hasText(page, 'Admin Dashboard'));
        } else {
            check(`${role} is BLOCKED from admin panel (403)`, isBlocked);
        }

        // Logout
        await page.goto(BASE + '/mis/logout.php', { waitUntil: 'networkidle0' });
    }

    // ── 6. Post-login UI professional styling ──
    step('Login page: post-login professional UI');
    await page.goto(BASE + '/mis/login.php', { waitUntil: 'networkidle0' });
    await type(page, 'input[name=username]', CREDS.admin.username);
    await type(page, 'input[name=password]', CREDS.admin.password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type=submit]'),
    ]);

    check('sidebar professional styling', await page.$('.sidebar') !== null);
    check('sidebar brand icon', await page.$('.brand-icon') !== null);
    check('sidebar collapse toggle (#sidebarCollapse)', await page.$('#sidebarCollapse') !== null);
    check('breadcrumb navigation', await page.$('.breadcrumb') !== null);
    check('user menu with avatar', await page.$('.user-menu') !== null);
    check('topbar present', await page.$('.topbar') !== null);
    check('page-title class used', await page.$('.page-title') !== null);
    check('page-subtitle present', await page.$('.page-subtitle') !== null);
    check('stat-card styling on dashboard', await page.$('.stat-card') !== null);

    // ── 7. Summary ──
    const passed = results.filter(r => r.pass).length;
    const total = results.length;
    console.log(`\n${'='.repeat(50)}`);
    console.log(`Login UI Tests: ${passed}/${total} passed`);
    console.log(`${'='.repeat(50)}`);

    await browser.close();
    process.exit(passed === total ? 0 : 1);
}

run().catch(err => {
    console.error('Test failed with error:', err);
    process.exit(1);
});