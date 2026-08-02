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

    step('MIS: Save condition on e2e_location (IF e2e_dropdown = option_a THEN show)');
    const condSaved = await page.evaluate(() => {
        if (!state || !state[0]) return false;
        const loc = state[0].fields.find((f) => f.field_key === 'e2e_location');
        if (!loc) return false;
        loc.conditions = [
            { target_field_key: 'e2e_dropdown', operator: 'equals', condition_value: 'option_a', action: 'show' },
        ];
        document.getElementById('structureInput').value = JSON.stringify(state);
        document.getElementById('builderForm').submit();
        return true;
    });
    ok('condition injected into builder state', condSaved);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Save Structure');
    ok('condition structure saved without error', await hasText(page, 'Form structure saved.'));
    const condPersists = await page.evaluate(() => {
        const target = document.querySelector('[data-cond-target="0:2:0"]');
        const op = document.querySelector('[data-cond-op="0:2:0"]');
        const val = document.querySelector('[data-cond-val="0:2:0"]');
        const act = document.querySelector('[data-cond-action="0:2:0"]');
        return !!target && target.value === 'e2e_dropdown' &&
            !!op && op.value === 'equals' &&
            !!val && val.value === 'option_a' &&
            !!act && act.value === 'show';
    });
    ok('condition editor row persists after reload', condPersists);
    await assertNoPhpWarnings(page, 'builder/edit.php (condition save)');

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
    const cascadeInDom = await page.evaluate(() => {
        const w = document.querySelector('[data-field-key="e2e_location"]');
        const casc = document.querySelector('[data-cascade]');
        return !!w && !!casc && /Panchayat/.test(casc.textContent);
    });
    ok('draft preview has cascade block in DOM (hidden by condition)', cascadeInDom);
    const cascadeRendered = await page.evaluate(() => {
        const cascade = document.querySelector('[data-cascade]');
        if (!cascade) return false;
        const levels = cascade.dataset.levels.split(',');
        const selects = Array.from(cascade.querySelectorAll('select')).map(s => s.dataset.level);
        return levels.length === 4 && levels.every(l => selects.includes(l));
    });
    ok('cascade renders 4 chained dropdowns', cascadeRendered);

    step('MIS: Draft preview applies conditional logic');
    const condHiddenInitially = await page.evaluate(() => {
        const w = document.querySelector('[data-field-key="e2e_location"]');
        return !!w && w.classList.contains('d-none');
    });
    ok('e2e_location hidden until trigger matches', condHiddenInitially);
    const triggerPicked = await page.evaluate(() => {
        const sel = document.querySelector('[data-field-key="e2e_dropdown"] select');
        if (!sel) return false;
        sel.value = 'option_a';
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    });
    ok('trigger option selected', triggerPicked);
    await new Promise((r) => setTimeout(r, 200));
    const condVisibleNow = await page.evaluate(() => {
        const w = document.querySelector('[data-field-key="e2e_location"]');
        return !!w && !w.classList.contains('d-none');
    });
    ok('e2e_location becomes visible after trigger', condVisibleNow);
    const condHiddenAgain = await page.evaluate(() => {
        const sel = document.querySelector('[data-field-key="e2e_dropdown"] select');
        if (!sel) return false;
        sel.value = '';
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    });
    ok('trigger cleared', condHiddenAgain);
    await new Promise((r) => setTimeout(r, 200));
    const condHiddenAfterClear = await page.evaluate(() => {
        const w = document.querySelector('[data-field-key="e2e_location"]');
        return !!w && w.classList.contains('d-none');
    });
    ok('e2e_location hides again when trigger cleared', condHiddenAfterClear);
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

    step("MIS: Gov't Building editor loads (form 40)");
    await page.goto(BASE + '/mis/builder/edit.php?id=40', { waitUntil: 'networkidle0' });
    await page.waitForFunction(() => typeof state !== 'undefined' && state.length >= 17, { timeout: 15000 });
    const govtInfo = await page.evaluate(() => {
        const sections = state.length;
        const fields = state.reduce((n, s) => n + (s.fields || []).length, 0);
        const rendered = document.querySelectorAll('[data-label]').length;
        const dropInputs = Array.from(document.querySelectorAll('[data-options]'));
        const masterSel = Array.from(document.querySelectorAll('[data-master]')).map((s) => s.value);
        const casc = Array.from(document.querySelectorAll('[data-cascade]')).map((box) => Array.from(box.querySelectorAll('input[data-casc-level]:checked')).map((c) => c.dataset.cascLevel));
        return {
            sections, fields, rendered,
            dropInputs: dropInputs.length,
            dropWithOptions: dropInputs.filter((el) => el.value.trim().length > 0).length,
            masterSel, casc,
        };
    });
    ok('govt building editor loads 17 sections', govtInfo.sections === 17);
    ok('govt building editor renders 132 fields', govtInfo.fields === 132 && govtInfo.rendered === 132);
    ok('dropdown fields render their options', govtInfo.dropInputs >= 39 && govtInfo.dropWithOptions >= 39);
    ok('master fields keep their master group', govtInfo.masterSel.length === 3 && govtInfo.masterSel.includes('8') && govtInfo.masterSel.includes('9'));
    ok('location cascade shows all 4 levels', govtInfo.casc.length === 1 && govtInfo.casc[0].length === 4);
    await assertNoPhpWarnings(page, 'builder/edit.php (govt building)');

    step("MIS: Gov't Building preview renders dropdowns");
    await page.goto(BASE + '/mis/builder/preview.php?id=40', { waitUntil: 'networkidle0' });
    await page.waitForFunction(() => document.querySelectorAll('select').length > 0, { timeout: 10000 });
    const previewInfo = await page.evaluate(() => {
        const selects = Array.from(document.querySelectorAll('select'));
        return {
            selects: selects.length,
            withOptions: selects.filter((s) => s.options.length > 1).length,
            cascades: document.querySelectorAll('[data-cascade]').length,
        };
    });
    ok('preview renders dropdown/master selects with options', previewInfo.selects >= 42 && previewInfo.withOptions >= 40);
    ok('preview has location cascade block', previewInfo.cascades === 1);
    await assertNoPhpWarnings(page, 'builder/preview.php (govt building)');

    step("MIS: Gov't Building preview location cascade chains");
    const chain = await page.evaluate(async () => {
        const block = document.querySelector('[data-cascade]');
        if (!block) return { ok: false, error: 'no cascade block' };
        const sel = (level) => block.querySelector(`select[data-level="${level}"]`);
        const count = (level) => sel(level).options.length;
        const pick = (level) => {
            const opt = sel(level).options[1];
            if (!opt) return null;
            sel(level).value = opt.value;
            sel(level).dispatchEvent(new Event('change', { bubbles: true }));
            return opt.value;
        };
        const wait = (ms) => new Promise((r) => setTimeout(r, ms));
        const d = pick('district');
        await wait(700);
        const b = count('block');
        pick('block');
        await wait(700);
        const p = count('panchayat');
        pick('panchayat');
        await wait(700);
        const v = count('village');
        return { ok: true, district: d, blocks: b, panchayats: p, villages: v };
    });
    ok('district selection populates blocks', chain.ok && chain.district && chain.blocks > 1);
    ok('block selection populates panchayats', chain.blocks > 1 && chain.panchayats > 1);
    ok('panchayat selection populates villages', chain.panchayats > 1 && chain.villages > 1);

    step('MIS: Masters lists all master types + add-master link');
    await clickText(page, 'Masters');
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Master Groups');
    ok('masters page loads', await hasText(page, 'Import CSV'));
    ok('all master groups listed', await hasText(page, 'DEPARTMENT') && await hasText(page, 'BUILDING_SUBCATEGORY'));
    ok('new master group button present', await hasText(page, 'New Master Group'));
    ok('Jharkhand districts seeded', await hasText(page, 'Ranchi'));
    await assertNoPhpWarnings(page, 'masters/index.php');

    step('MIS: Monitoring shows submitter + view link');
    await clickText(page, 'Monitoring');
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Survey Monitoring');
    ok('monitoring page loads', await hasText(page, 'Submitted'));
    ok('monitoring shows surveyor name', await hasText(page, 'Ravi Kumar'));
    const viewLink = await page.evaluate(() => !!document.querySelector('a[href*="records.php?id="]'));
    ok('monitoring has record view link', viewLink);
    const verifyVisible = await page.evaluate(() =>
        Array.from(document.querySelectorAll('button')).some((b) => b.textContent.trim() === 'Verify')
    );
    ok('verify button available on submitted records', verifyVisible);
    await assertNoPhpWarnings(page, 'monitoring.php');

    step('MIS: View submitted record detail');
    const openedDetail = await page.evaluate(() => {
        const a = document.querySelector('a[href*="records.php?id="]');
        if (!a) return false;
        a.click();
        return true;
    });
    ok('record view link opened', openedDetail);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Record Detail');
    ok('record detail shows answers', await hasText(page, 'Landowner Name') && await hasText(page, 'Ravi Kumar'));
    ok('record detail shows submitter + status', await hasText(page, 'Submitted by') && await hasText(page, 'Submitted'));
    await assertNoPhpWarnings(page, 'records.php');
    await clickText(page, 'Back to Monitoring');
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await waitForText(page, 'Survey Monitoring');

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

    step('MIS: Detailed report (all-column filters)');
    await page.goto(BASE + '/mis/detail_report.php', { waitUntil: 'networkidle0' });
    await waitForText(page, 'Detailed Report');
    await waitForText(page, 'Filter by column values');
    const kpiLower = await page.evaluate(() => document.body.innerText.toLowerCase());
    ok('detail report shows KPI cards', kpiLower.includes('total buildings') && kpiLower.includes('total rooms') && kpiLower.includes('departments'));
    ok('detail report shows column filter panel', await hasText(page, 'Filter by column values'));
    ok('category exact filter present', await page.$('select[name=building_category]') !== null);
    ok('built-up range filter present', await page.$('input[name=built_up_area_min]') !== null && await page.$('input[name=built_up_area_max]') !== null);
    ok('building-name text filter present', await page.$('input[name=building_name]') !== null);
    await assertNoPhpWarnings(page, 'detail_report.php (default)');

    step('MIS: Detail report filters narrow results');
    const catOpts = await page.evaluate(() =>
        Array.from(document.querySelector('select[name=building_category]').options)
            .map((o) => o.value).filter((v) => v !== '')
    );
    ok('category filter has options', catOpts.length > 0, `options=${catOpts.length}`);
    if (catOpts.length > 0) {
        await page.select('select[name=building_category]', catOpts[0]);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('button.btn-primary'),
        ]);
        await waitForText(page, 'Detailed Report');
        const stillSelected = await page.evaluate((v) =>
            Array.from(document.querySelectorAll('select[name=building_category] option')).some((o) => o.selected && o.value === v),
            catOpts[0]
        );
        ok('category filter applied + remembered', stillSelected);
        await assertNoPhpWarnings(page, 'detail_report.php (filtered)');
    }

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
    await page.waitForNetworkIdle({ idleTime: 300 });
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
    const portalTicked = await page.evaluate(() => {
        const cb = document.getElementById('portal_mis');
        if (!cb) return false;
        cb.checked = true;
        return true;
    });
    ok('portal checkbox available + ticked', portalTicked);
    const formSelected = await page.evaluate(() => {
        const sel = document.getElementById('f_forms');
        if (!sel || !sel.options.length) return false;
        sel.options[0].selected = true;
        return true;
    });
    ok('form access selectable in modal', formSelected);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('#userModal button[type=submit]'),
    ]);
    ok('create-user flash shown', await hasText(page, 'User created. Default password: Welcome@123'));

    step('MIS: Edit user shows saved portal + form access');
    const dbgRows = await page.evaluate(() =>
        Array.from(document.querySelectorAll('tr')).map((tr) => ({
            text: tr.textContent.slice(0, 80),
            username: tr.querySelector('td:nth-child(2)')?.textContent.trim(),
            hasBtn: !!tr.querySelector('button[onclick*="openModal"]'),
            onclick: tr.querySelector('button[onclick*="openModal"]')?.getAttribute('onclick'),
        }))
    );
    console.log('  [DEBUG] uname=' + uname + ' rows=' + JSON.stringify(dbgRows.filter((r) => r.text.includes('e2e_'))));
    const editUser = await page.evaluate((username) => {
        const rows = Array.from(document.querySelectorAll('tr'));
        const byName = rows.find((tr) => tr.textContent.includes('E2E Tester'));
        const byUname = rows.find((tr) => tr.textContent.includes(username));
        const row = byName || byUname;
        if (!row) return { clicked: false, byName: !!byName, byUname: !!byUname, count: rows.length };
        const btn = row.querySelector('button[onclick*="openModal"]');
        if (!btn) return { clicked: false, byName: !!byName, byUname: !!byUname, count: rows.length };
        btn.click();
        return { clicked: true, byName: !!byName, byUname: !!byUname, count: rows.length };
    }, uname);
    console.log('  [DEBUG] editUser=' + JSON.stringify(editUser));
    ok('user edit opened', editUser.clicked);
    await page.waitForSelector('#f_full_name', { visible: true, timeout: 8000 });
    await page.waitForNetworkIdle({ idleTime: 300 });
    await new Promise((r) => setTimeout(r, 800));
    const accessPersisted = await page.evaluate(() => {
        const portalMis = document.getElementById('portal_mis');
        const formsSel = document.getElementById('f_forms');
        const selectedForms = formsSel ? Array.from(formsSel.selectedOptions).map((o) => o.value) : [];
        return portalMis && portalMis.checked && selectedForms.length >= 1;
    });
    ok('portal + form access persist in edit modal', accessPersisted);
    await page.keyboard.press('Escape');

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

    step('ADMIN: Roles & Access page');
    await page.goto(BASE + '/admin/access.php', { waitUntil: 'networkidle0' });
    await waitForText(page, 'Roles & Access');
    ok('access page loads', await hasText(page, 'Roles & Access'));
    ok('Manage buttons present', await hasText(page, 'Manage'));
    const manageClicked = await page.evaluate(() => {
        const btn = Array.from(document.querySelectorAll('button')).find((b) => b.textContent.includes('Manage') && b.getAttribute('onclick')?.includes(', false)'));
        if (!btn) return false;
        btn.click();
        return true;
    });
    ok('manage access modal opened', manageClicked);
    await page.waitForSelector('#ac_portal_mis', { visible: true, timeout: 8000 });
    const accessModalShown = await page.evaluate(() => {
        const mis = document.getElementById('ac_portal_mis');
        const admin = document.getElementById('ac_portal_admin');
        const forms = document.getElementById('ac_forms');
        return !!mis && !!admin && !!forms && forms.options.length >= 1;
    });
    ok('portal + form access controls rendered', accessModalShown);
    const accessToggled = await page.evaluate(() => {
        const adminCb = document.getElementById('ac_portal_admin');
        if (!adminCb) return false;
        adminCb.checked = true;
        const forms = document.getElementById('ac_forms');
        if (forms && forms.options.length) forms.options[0].selected = true;
        const form = document.querySelector('#accessModal form');
        if (!form) return false;
        form.submit();
        return true;
    });
    ok('access assignment submitted', accessToggled);
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    ok('access saved flash', await hasText(page, 'Access updated.'));
    await assertNoPhpWarnings(page, 'admin/access.php');

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
