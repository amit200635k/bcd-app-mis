'use strict';

const path = require('path');
const { execFileSync } = require('child_process');
const puppeteer = require('puppeteer');
const {
    BASE, step, check, ok, clickText, type, waitForText, hasText,
    assertNoPhpWarnings, wirePage, summary,
} = require('./lib.js');

const only = process.argv[2] || 'all'; // mis | admin | all

// Reset demo DB state so tests are repeatable (runs php tests/e2e/reset.php).
function resetDb() {
    const script = path.join(__dirname, 'reset.php');
    console.log('Resetting demo state (php reset.php) …');
    try {
        execFileSync('php', [script], { stdio: ['ignore', 'pipe', 'inherit'] });
        console.log('Reset done.');
    } catch (e) {
        console.log('Reset failed (continuing anyway): ' + e.message);
    }
}

async function loginAs(page, which) {
    await page.goto(BASE + '/mis/login.php', { waitUntil: 'networkidle0' });
    await waitForText(page, 'BCD Survey Platform');
    const c = require('./lib.js').CREDS[which];
    await type(page, 'input[name=username]', c.username);
    await type(page, 'input[name=password]', c.password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type=submit]'),
    ]);
    return page.url();
}

/* ============================== MIS SUITE ============================== */
async function misSuite(page) {
    step('MIS: Login page renders');
    await page.goto(BASE + '/mis/login.php', { waitUntil: 'networkidle0' });
    ok('login page loads', await hasText(page, 'BCD Survey Platform'));
    ok('username field present', await page.$('input[name=username]') !== null);
    ok('password field present', await page.$('input[name=password]') !== null);
    await assertNoPhpWarnings(page, 'login.php');

    step('MIS: Reject wrong credentials');
    await type(page, 'input[name=username]', 'admin');
    await type(page, 'input[name=password]', 'wrongpass');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type=submit]'),
    ]);
    ok('shows invalid-credentials error', await hasText(page, 'Invalid credentials'));

    step('MIS: Login as admin');
    await type(page, 'input[name=username]', 'admin');
    await type(page, 'input[name=password]', 'Admin@12345');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type=submit]'),
    ]);
    ok('redirected to dashboard', /mis\/dashboard\.php/.test(page.url()));
    await assertNoPhpWarnings(page, 'dashboard.php');

    step('MIS: Dashboard renders stats');
    await waitForText(page, 'Dashboard');
    for (const label of ['Active Users', 'Published Forms', 'Total Records', 'Records by Status', 'Top Forms by Records']) {
        ok(`dashboard shows "${label}"`, await hasText(page, label));
    }

    step('MIS: Sidebar navigation');
    for (const link of ['Survey Builder', 'Masters', 'Monitoring', 'GIS', 'Reports', 'Users', 'Admin Panel', 'Logout']) {
        ok(`sidebar link "${link}"`, await hasText(page, link));
    }

    step('MIS: Survey Builder list');
    await clickText(page, 'Survey Builder');
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Survey Builder');
    ok('builder page loads', await hasText(page, 'New Form'));
    ok('published form listed', await hasText(page, 'AGRICULTURE_CENSUS'));
    await assertNoPhpWarnings(page, 'builder/index.php');

    step('MIS: Preview published form');
    const previewClicked = await page.evaluate(() => {
        const row = Array.from(document.querySelectorAll('tr')).find(
            (tr) => tr.textContent.includes('AGRICULTURE_CENSUS')
        );
        if (!row) return false;
        const a = row.querySelector('a[href*="preview.php"]');
        if (!a) return false;
        a.click();
        return true;
    });
    ok('preview link clicked', previewClicked);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Landowner Name');
    ok('preview renders fields', await hasText(page, 'Landowner Name'));
    ok('preview shows GPS capture', await hasText(page, 'Capture GPS'));
    await assertNoPhpWarnings(page, 'builder/preview.php');

    step('MIS: Create a new draft form');
    await page.goto(BASE + '/mis/builder/index.php', { waitUntil: 'networkidle0' });
    await page.click('[data-bs-target="#newFormModal"]');
    const code = 'E2E_' + Date.now().toString().slice(-8);
    const title = 'E2E Survey ' + code;
    await waitForText(page, 'New Survey Form');
    await type(page, 'input[name=code]', code);
    await type(page, 'input[name=title]', title);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('#newFormModal button[type=submit]'),
    ]);
    ok('redirected to builder edit page', /builder\/edit\.php/.test(page.url()));
    await waitForText(page, 'Save Structure');
    ok('draft editor loads', await hasText(page, 'Save Structure'));
    await assertNoPhpWarnings(page, 'builder/edit.php');

    step('MIS: Save dropdown field with options in builder');
    const structSaved = await page.evaluate(() => {
        if (!state) return false;
        if (!state[0]) {
            state.push({ title: 'Section 1', description: '', fields: [] });
        }
        state[0].fields.push({
            field_key: 'e2e_dropdown',
            label: 'E2E Dropdown',
            type: 'dropdown',
            mandatory: 0,
            options: [{ option_label: 'Option A', option_value: 'option_a' }],
            validations: [],
            conditions: [],
        });
        document.getElementById('structureInput').value = JSON.stringify(state);
        document.getElementById('builderForm').submit();
        return true;
    });
    ok('builder state injected', structSaved);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Save Structure');
    ok('structure saved without error', await hasText(page, 'Form structure saved.'));
    const persists = await page.evaluate(() => {
        const label = document.querySelector('[data-label="0:0"]');
        const opts = document.querySelector('[data-options="0:0"]');
        return !!label && label.value === 'E2E Dropdown' && !!opts && /Option A/.test(opts.value);
    });
    ok('dropdown field persists after reload', persists);
    await assertNoPhpWarnings(page, 'builder/edit.php');

    step('MIS: Save master-data field linked to DISTRICT group');
    const masterSaved = await page.evaluate(() => {
        if (!state || !state[0]) return false;
        state[0].fields.push({
            field_key: 'e2e_district',
            label: 'E2E District',
            type: 'master',
            mandatory: 0,
            settings: { master_group_id: 1 },
            options: [],
            validations: [],
            conditions: [],
        });
        document.getElementById('structureInput').value = JSON.stringify(state);
        document.getElementById('builderForm').submit();
        return true;
    });
    ok('master field injected into state', masterSaved);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Save Structure');
    ok('master structure saved without error', await hasText(page, 'Form structure saved.'));
    const masterPersists = await page.evaluate(() => {
        const sel = document.querySelector('[data-master="0:1"]');
        return !!sel && sel.value === '1';
    });
    ok('master group selection persists after reload', masterPersists);
    await assertNoPhpWarnings(page, 'builder/edit.php');

    step('MIS: Save location_cascade (dependent dropdowns) field');
    const cascadeSaved = await page.evaluate(() => {
        if (!state || !state[0]) return false;
        state[0].fields.push({
            field_key: 'e2e_location',
            label: 'E2E Location',
            type: 'location_cascade',
            mandatory: 1,
            settings: { levels: ['district', 'block', 'panchayat', 'village'] },
            options: [],
            validations: [],
            conditions: [],
        });
        document.getElementById('structureInput').value = JSON.stringify(state);
        document.getElementById('builderForm').submit();
        return true;
    });
    ok('cascade field injected into state', cascadeSaved);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Save Structure');
    ok('cascade structure saved without error', await hasText(page, 'Form structure saved.'));
    const cascadePersists = await page.evaluate(() => {
        const box = document.querySelector('[data-cascade="0:2"]');
        if (!box) return false;
        const checked = Array.from(box.querySelectorAll('input[data-casc-level]:checked')).map(c => c.dataset.cascLevel);
        return checked.length === 4 && checked.includes('district') && checked.includes('village');
    });
    ok('cascade levels persist after reload', cascadePersists);
    await assertNoPhpWarnings(page, 'builder/edit.php');

    step('MIS: Preview draft form (no published version)');
    await clickText(page, 'Back');
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    const draftPreview = await page.evaluate((code) => {
        const row = Array.from(document.querySelectorAll('tr')).find(
            (tr) => tr.textContent.includes(code)
        );
        if (!row) return false;
        const a = row.querySelector('a[href*="preview.php"]');
        if (!a) return false;
        a.click();
        return true;
    }, code);
    ok('draft form preview link clicked', draftPreview);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'E2E District');
    ok('draft preview renders master field', await hasText(page, 'E2E District'));
    ok('draft preview lists district items', await hasText(page, 'Ranchi'));
    ok('draft preview shows cascade levels', await hasText(page, 'E2E Location') && await hasText(page, 'Panchayat'));
    const cascadeRendered = await page.evaluate(() => {
        const cascade = document.querySelector('[data-cascade]');
        if (!cascade) return false;
        const levels = cascade.dataset.levels.split(',');
        const selects = Array.from(cascade.querySelectorAll('select')).map(s => s.dataset.level);
        return levels.length === 4 && levels.every(l => selects.includes(l));
    });
    ok('cascade renders 4 chained dropdowns', cascadeRendered);
    await assertNoPhpWarnings(page, 'builder/preview.php (draft)');

    await page.goto(BASE + '/mis/builder/index.php', { waitUntil: 'networkidle0' });
    await waitForText(page, 'Survey Builder');
    ok('draft form appears in list', await hasText(page, code));

    step('MIS: Publish draft form to live');
    const publishClicked = await page.evaluate((c) => {
        const row = Array.from(document.querySelectorAll('tr')).find((tr) => tr.textContent.includes(c));
        if (!row) return false;
        const a = row.querySelector('a[href*="publish.php"]');
        if (!a) return false;
        a.click();
        return true;
    }, code);
    ok('publish action clicked', publishClicked);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    ok('publish flash shown', await hasText(page, 'published. It is now available for mobile download.'));
    await waitForText(page, 'Survey Builder');
    const publishedRow = await page.evaluate((c) => {
        const row = Array.from(document.querySelectorAll('tr')).find((tr) => tr.textContent.includes(c));
        return !!row && /published/.test(row.textContent);
    }, code);
    ok('published status shown in list', publishedRow);
    await assertNoPhpWarnings(page, 'builder/publish.php');

    step('MIS: Edit published form (auto-clones a draft)');
    const editClicked = await page.evaluate((c) => {
        const row = Array.from(document.querySelectorAll('tr')).find((tr) => tr.textContent.includes(c));
        if (!row) return false;
        const a = row.querySelector('a[href*="edit.php"]');
        if (!a) return false;
        a.click();
        return true;
    }, code);
    ok('edit published form opened', editClicked);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Save Structure');
    ok('published badge shown in editor', await hasText(page, 'published v'));
    ok('pending-changes badge shown', await hasText(page, 'pending changes'));
    ok('Save & Sync to All button present', await page.$('#btnSaveSync') !== null);
    await assertNoPhpWarnings(page, 'builder/edit.php (published)');

    step('MIS: Save & Sync to All publishes new version');
    const syncSaved = await page.evaluate(() => {
        if (!state || !state[0]) return false;
        state[0].fields.push({
            field_key: 'e2e_synced',
            label: 'E2E Synced Field',
            type: 'textbox',
            mandatory: 0,
            options: [],
            validations: [],
            conditions: [],
        });
        document.getElementById('actionInput').value = 'save_sync';
        document.getElementById('structureInput').value = JSON.stringify(state);
        document.getElementById('builderForm').submit();
        return true;
    });
    ok('sync field injected into draft', syncSaved);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'synced to all users');
    ok('sync flash confirms broadcast', await hasText(page, 'synced to all users (web + mobile).'));
    await waitForText(page, 'Survey Builder');
    const syncedRow = await page.evaluate((c) => {
        const row = Array.from(document.querySelectorAll('tr')).find((tr) => tr.textContent.includes(c));
        if (!row) return false;
        return {
            text: row.textContent,
            hasSyncBtn: !!row.querySelector('a[href*="sync.php"]'),
        };
    }, code);
    ok('pending draft badge cleared after sync', syncedRow && !/draft/.test(syncedRow.text));
    ok('no sync button when no pending changes', syncedRow && !syncedRow.hasSyncBtn);
    await assertNoPhpWarnings(page, 'builder/sync.php');

    step('MIS: Location Masters');
    await clickText(page, 'Masters');
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Location Masters');
    ok('masters page loads', await hasText(page, 'Import CSV'));
    ok('Jharkhand districts seeded', await hasText(page, 'Ranchi'));
    await assertNoPhpWarnings(page, 'masters/index.php');

    step('MIS: Monitoring');
    await clickText(page, 'Monitoring');
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Survey Monitoring');
    ok('monitoring page loads', await hasText(page, 'Submitted'));
    const verifyVisible = await page.evaluate(() =>
        Array.from(document.querySelectorAll('button')).some((b) => b.textContent.trim() === 'Verify')
    );
    ok('verify button available on submitted records', verifyVisible);
    await assertNoPhpWarnings(page, 'monitoring.php');

    step('MIS: Verify a record (workflow action)');
    const clickedVerify = await page.evaluate(() => {
        const btn = Array.from(document.querySelectorAll('button')).find(
            (b) => b.textContent.trim() === 'Verify'
        );
        if (!btn) return false;
        btn.closest('form').submit();
        return true;
    });
    if (clickedVerify) {
        await page.waitForNavigation({ waitUntil: 'networkidle0' });
        ok('verify flash shown', await hasText(page, 'Record marked as block_verified'));
    } else {
        ok('verify action performed', false, 'no Verify button found to click');
    }

    step('MIS: Reports (survey-wise)');
    await clickText(page, 'Reports');
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Reports');
    ok('reports page loads', await hasText(page, 'Export CSV'));
    ok('survey-wise table has our form', await hasText(page, 'Agriculture Census - Crop & Land Survey'));
    await assertNoPhpWarnings(page, 'reports.php');

    step('MIS: Reports CSV export (district-wise)');
    const csvOk = await page.evaluate(async () => {
        try {
            const res = await fetch('reports.php?report=district_wise&export=csv', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const text = await res.text();
            return res.ok && /District,Total/.test(text) && /Ranchi/.test(text);
        } catch (e) {
            return false;
        }
    });
    ok('district-wise CSV downloads with Ranchi row', csvOk);

    step('MIS: Reports district-wise view');
    await page.select('select[name=report]', 'district_wise');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button.btn-primary'),
    ]);
    await waitForText(page, 'District-wise');
    ok('district-wise table renders', await hasText(page, 'Ranchi'));

    step('MIS: User management');
    await clickText(page, 'Users');
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'User Management');
    ok('users page loads', await hasText(page, 'New User'));
    ok('admin user listed', await hasText(page, 'admin'));
    await assertNoPhpWarnings(page, 'users/index.php');

    step('MIS: Create a user via modal');
    await page.click('[data-bs-target="#userModal"]');
    await page.waitForSelector('#f_full_name', { visible: true, timeout: 8000 });
    const uname = 'e2e_' + Date.now().toString().slice(-8);
    await type(page, '#f_full_name', 'E2E Tester');
    await type(page, '#f_username', uname);
    await type(page, '#f_password', 'E2e@12345');
    const roleSelected = await page.evaluate(() => {
        const sel = document.getElementById('f_roles');
        if (!sel || !sel.options.length) return false;
        const target = Array.from(sel.options).find((o) => /surveyor/i.test(o.text)) || sel.options[0];
        target.selected = true;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    });
    ok('role selectable in modal', roleSelected);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('#userModal button[type=submit]'),
    ]);
    ok('create-user flash shown', await hasText(page, 'User created. Default password: Welcome@123'));

    step('MIS: GIS dashboard');
    await clickText(page, 'GIS');
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'GIS Dashboard');
    ok('map container present', await page.$('#map') !== null);
    try {
        await page.waitForFunction(
            () => document.getElementById('gisStats') && /survey points shown/.test(document.getElementById('gisStats').textContent),
            { timeout: 10000 }
        );
        ok('GIS points loaded', true, await page.evaluate(() => document.getElementById('gisStats').textContent.trim()));
    } catch {
        ok('GIS points loaded', false, 'gisStats never reported points');
    }
    await assertNoPhpWarnings(page, 'gis/index.php');

    step('MIS: Logout');
    await clickText(page, 'Logout');
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'BCD Survey Platform');
    ok('logged out back to login', await hasText(page, 'Sign In'));
}

/* ============================== ADMIN SUITE ============================== */
async function adminSuite(page) {
    step('ADMIN: Login');
    await loginAs(page, 'admin');
    ok('admin logged in', /mis\/dashboard\.php/.test(page.url()));

    step('ADMIN: Admin panel dashboard');
    await page.goto(BASE + '/admin/dashboard.php', { waitUntil: 'networkidle0' });
    await waitForText(page, 'Admin Dashboard');
    for (const label of ['Users', 'Districts', 'Villages', 'Survey Forms', 'Records', 'Audit Entries', 'Replication Pending', 'Replication Failed']) {
        ok(`admin card "${label}"`, await hasText(page, label));
    }
    ok('shows 24 districts', await hasText(page, '24'));
    ok('shows 1152 villages', await hasText(page, '1152'));
    ok('recent audit table renders', await hasText(page, 'Recent Audit Activity'));
    await assertNoPhpWarnings(page, 'admin/dashboard.php');

    step('ADMIN: Block non-state-admin from admin panel');
    const ctx = await page.browser().createBrowserContext();
    const other = await ctx.newPage();
    wirePage(other, 'admin-denied');
    await loginAs(other, 'district');
    await other.goto(BASE + '/admin/dashboard.php', { waitUntil: 'networkidle0' });
    const body = await other.evaluate(() => document.body.innerText).catch(() => '');
    ok('district admin is blocked (403)', /403/.test(body));
    await other.close();
    await ctx.close();

    step('ADMIN: Settings save + list');
    await page.goto(BASE + '/admin/settings.php', { waitUntil: 'networkidle0' });
    await waitForText(page, 'System Settings');
    const sKey = 'test.e2e.' + Date.now().toString().slice(-6);
    await type(page, 'input[name=setting_key]', sKey);
    await type(page, 'input[name=setting_value]', 'hello-world');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button.btn-danger'),
    ]);
    ok('setting saved flash', await hasText(page, 'Setting saved.'));
    ok('setting appears in table', await hasText(page, sKey));
    await assertNoPhpWarnings(page, 'admin/settings.php');

    step('ADMIN: Broadcast notification');
    await page.goto(BASE + '/admin/notifications.php', { waitUntil: 'networkidle0' });
    await waitForText(page, 'Broadcast Notifications');
    const nTitle = 'E2E-NOTIF-' + Date.now().toString().slice(-6);
    await type(page, 'input[name=title]', nTitle);
    await type(page, 'textarea[name=body]', 'Automated broadcast from headed-Chrome E2E run.');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button.btn-danger'),
    ]);
    ok('notification sent flash', await hasText(page, 'Notification sent.'));
    ok('notification in recently sent', await hasText(page, nTitle));
    await assertNoPhpWarnings(page, 'admin/notifications.php');

    step('ADMIN: Audit logs + filter');
    await page.goto(BASE + '/admin/audit.php', { waitUntil: 'networkidle0' });
    await waitForText(page, 'Audit Logs');
    ok('audit table has rows', await hasText(page, 'auth.login'));
    await type(page, 'input[name=action]', 'login');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button.btn-dark'),
    ]);
    ok('filtered audit loads', await hasText(page, 'Audit Logs'));
    await assertNoPhpWarnings(page, 'admin/audit.php');

    step('ADMIN: Replication monitor');
    await page.goto(BASE + '/admin/replication.php', { waitUntil: 'networkidle0' });
    await waitForText(page, 'Replication Monitor');
    ok('external db targets section', await hasText(page, 'External Database Targets'));
    ok('replication queue section', await hasText(page, 'Replication Queue'));
    ok('retry/drain buttons present', await hasText(page, 'Retry Failed') && await hasText(page, 'Drain Queue'));
    await assertNoPhpWarnings(page, 'admin/replication.php');

    step('ADMIN: System health');
    await page.goto(BASE + '/admin/health.php', { waitUntil: 'networkidle0' });
    await waitForText(page, 'System Health');
    ok('health page renders', await hasText(page, 'PHP Extensions'));
    ok('database connected', await hasText(page, 'connected'));
    ok('shows PHP version', await hasText(page, '8.2'));
    await assertNoPhpWarnings(page, 'admin/health.php');

    step('ADMIN: Master Data page');
    await page.goto(BASE + '/admin/masters.php', { waitUntil: 'networkidle0' });
    await waitForText(page, 'Master Data');
    ok('master data nav link present', await hasText(page, 'Master Data'));
    ok('DISTRICT group listed', await hasText(page, 'DISTRICT'));
    ok('24 district items counted', await hasText(page, '24'));
    await assertNoPhpWarnings(page, 'admin/masters.php');

    step('ADMIN: Create master group + add item');
    const grpCode = 'E2E_GRP_' + Date.now().toString().slice(-6);
    const grpName = 'E2E Group ' + Date.now().toString().slice(-6);
    await type(page, 'input[name=code]', grpCode);
    await type(page, 'input[name=name]', grpName);
    const grpSubmitted = await page.evaluate(() => {
        const form = Array.from(document.querySelectorAll('form')).find((f) => {
            const a = f.querySelector('input[name=action]');
            return a && a.value === 'create_group';
        });
        if (!form) return false;
        form.submit();
        return true;
    });
    ok('create group form submitted', grpSubmitted);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    ok('group created flash', await hasText(page, 'Master group created.'));
    ok('group appears in list', await hasText(page, grpName));
    await assertNoPhpWarnings(page, 'admin/masters.php (after create)');

    const openGroup = await page.evaluate((name) => {
        const a = Array.from(document.querySelectorAll('a')).find(
            (x) => x.textContent.trim() === name
        );
        if (a) { a.click(); return true; }
        return false;
    }, grpName);
    ok('navigated into new group', openGroup);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Add Item');
    const itemNamed = await page.evaluate(() => {
        const form = Array.from(document.querySelectorAll('form')).find((f) => {
            const a = f.querySelector('input[name=action]');
            return a && a.value === 'add_item';
        });
        const input = form && form.querySelector('input[name=name]');
        if (!input) return false;
        input.value = 'E2E Item Alpha';
        return true;
    });
    ok('add item name filled', itemNamed);
    const itemSubmitted = await page.evaluate(() => {
        const form = Array.from(document.querySelectorAll('form')).find((f) => {
            const a = f.querySelector('input[name=action]');
            return a && a.value === 'add_item';
        });
        if (!form) return false;
        form.submit();
        return true;
    });
    ok('add item form submitted', itemSubmitted);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    ok('item added flash', await hasText(page, 'Master item added.'));
    ok('item appears in items table', await hasText(page, 'E2E Item Alpha'));

    step('ADMIN: Delete master item');
    const delItem = await page.evaluate(() => {
        const row = Array.from(document.querySelectorAll('tr')).find(
            (tr) => tr.textContent.includes('E2E Item Alpha')
        );
        if (!row) return false;
        const form = row.querySelector('form input[name=action][value=delete_item]')?.closest('form');
        if (!form) return false;
        form.submit();
        return true;
    });
    ok('delete item form submitted', delItem);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    ok('item removed after delete', !(await hasText(page, 'E2E Item Alpha')));

    step('ADMIN: Delete master group');
    await page.goto(BASE + '/admin/masters.php', { waitUntil: 'networkidle0' });
    await waitForText(page, 'Master Data');
    const delGrp = await page.evaluate((name) => {
        const row = Array.from(document.querySelectorAll('tr')).find(
            (tr) => tr.textContent.includes(name)
        );
        if (!row) return false;
        const form = row.querySelector('form input[name=action][value=delete_group]')?.closest('form');
        if (!form) return false;
        form.submit();
        return true;
    }, grpName);
    ok('delete group form submitted', delGrp);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    ok('group removed after delete', !(await hasText(page, grpName)));
    await assertNoPhpWarnings(page, 'admin/masters.php (after delete)');
}

/* ============================== MAIN ============================== */
(async () => {
    resetDb();
    const browser = await puppeteer.launch({
        headless: false,
        defaultViewport: { width: 1440, height: 900 },
        args: ['--window-size=1440,950', '--no-sandbox'],
    });
    const page = await browser.newPage();
    wirePage(page, 'main');

    try {
        if (only === 'mis' || only === 'all') {
            await misSuite(page);
        }
        if (only === 'admin' || only === 'all') {
            await adminSuite(page);
        }
    } catch (err) {
        check('uncaught runner error', false, err.message);
        console.log(err);
    }

    const clean = await summary();
    await browser.close();
    // Re-run reset so the demo stays pristine for the next live demo.
    resetDb();
    process.exit(clean ? 0 : 1);
})();
