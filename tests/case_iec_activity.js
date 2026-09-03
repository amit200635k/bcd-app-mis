'use strict';

/**
 * Case file: exercise the IEC_ACTIVITY_SURVEY form in the BROWSER (headed
 * Chrome) and push 5 test submissions through the real REST API.
 *
 * 1. Logs into the MIS portal.
 * 2. Opens the form's builder preview and DRIVES THE CONDITIONAL UI live:
 *    per-activity status dropdowns appear only for their activity type,
 *    "Size of Hoarding / Billboard" appears only for Hoardings + Done,
 *    "Hoarding Size Details" reveals on Custom Size, sub-categories chain off
 *    positive statuses.
 * 3. Submits 5 records (different activity branches) via /v1/records using
 *    browser-side fetch with a bearer token, asserting the JH/… survey_code.
 * 4. Shows the results in MIS Monitoring (Survey ID column) and opens one
 *    Record Detail page.
 *
 * Records are KEPT by default:
 *   node tests/case_iec_activity.js              # run + keep data
 *   node tests/case_iec_activity.js --cleanup    # delete this case's records
 *
 * Existing E2E suite (tests/e2e) is NOT touched.
 */

const fs = require('fs');
const path = require('path');
const puppeteer = require('./e2e/node_modules/puppeteer');
const {
    BASE,
    CREDS,
    step,
    check,
    waitForText,
    hasText,
    assertNoPhpWarnings,
    wirePage,
    summary,
} = require('./e2e/lib.js');

const ROOT = path.join(__dirname, '..');
const LATEST_FILE = path.join(__dirname, '.case_iec.latest.json');
const CLEANUP = process.argv.includes('--cleanup');
const UUID_PREFIX = 'iec-case-';

const api = (p) => `${BASE}/api/v1${p}`;
const ts = Date.now().toString(36);

let token = null;
const createdIds = [];
const createdCodes = [];

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

async function apiLogin(pageCtx) {
    // Login from INSIDE the browser so everything stays browser-based.
    return pageCtx.evaluate(async (url, username, password) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password }),
        });
        const json = await res.json();
        if (!res.ok) throw new Error(`API login failed: ${res.status}`);
        return json.data.access_token;
    }, api('/auth/login'), CREDS.admin.username, CREDS.admin.password);
}

async function apiGetFormId(pageCtx) {
    const form = await pageCtx.evaluate(async (url, tok) => {
        const res = await fetch(url, { headers: { Authorization: `Bearer ${tok}` } });
        const json = await res.json();
        if (!res.ok) throw new Error(`GET forms failed: ${res.status}`);
        const found = (json.data.forms || json.data || []).find((f) => f.code === 'IEC_ACTIVITY_SURVEY');
        if (!found) throw new Error('IEC_ACTIVITY_SURVEY not found in /forms');
        return { id: found.id, version: found.version ?? null };
    }, api('/forms'), token);
    return form;
}

async function apiPostRecord(pageCtx, payload) {
    const out = await pageCtx.evaluate(async (url, tok, body) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${tok}` },
            body: JSON.stringify(body),
        });
        const json = await res.json().catch(() => ({}));
        return { status: res.status, ok: res.ok, data: json.data ?? null, errors: json.errors ?? null };
    }, api('/records'), token, payload);
    return out;
}

/** Is the field wrapper currently visible (conditional engine)? */
const fieldVisible = (page, key) =>
    page.evaluate((k) => {
        const w = document.querySelector(`[data-field-key="${k}"]`);
        return !!w && !w.classList.contains('d-none');
    }, key);

async function run() {
    const browser = await puppeteer.launch({ headless: false, defaultViewport: { width: 1280, height: 860 } });
    const page = await browser.newPage();
    wirePage(page, 'case_iec_activity');

    try {
        // 1. MIS login -----------------------------------------------------
        step('MIS login');
        await page.goto(`${BASE}/mis/login.php`, { waitUntil: 'networkidle0' });
        await page.type('input[name="username"]', CREDS.admin.username);
        await page.type('input[name="password"]', CREDS.admin.password);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('button[type="submit"]'),
        ]);
        check('Logged into MIS dashboard', await hasText(page, 'Dashboard'));

        // Resolve the form id dynamically (ids are not stable across DBs).
        token = await apiLogin(page);
        check('API bearer token issued in browser', typeof token === 'string' && token.length > 20);
        const form = await apiGetFormId(page);
        check('IEC_ACTIVITY_SURVEY resolved via /forms', Number.isInteger(form.id), `form_id=${form.id}`);

        // 2. Drive the conditional UI in the builder preview ---------------
        step('Conditional UI live in builder preview');
        await page.goto(`${BASE}/mis/builder/preview.php?id=${form.id}`, { waitUntil: 'networkidle0' });
        await waitForText(page, 'IEC Activity Survey');
        check('Preview renders the IEC form', await hasText(page, 'ACTIVITY TYPE'));

        // Nothing is selected yet → every per-activity status stays hidden.
        check('nn_status hidden initially', !(await fieldVisible(page, 'nn_status')));
        check('hb_status hidden initially', !(await fieldVisible(page, 'hb_status')));
        check('hb_size hidden initially', !(await fieldVisible(page, 'hb_size')));

        const pickActivity = async (value) => {
            await page.evaluate((v) => {
                const w = document.querySelector('[data-field-key="activity_type"]');
                const s = w.querySelector('select');
                s.value = v;
                s.dispatchEvent(new Event('change', { bubbles: true }));
                s.dispatchEvent(new Event('input', { bubbles: true }));
            }, value);
            await new Promise((r) => setTimeout(r, 60));
        };
        const pickField = async (key, value) => {
            await page.evaluate((k, v) => {
                const w = document.querySelector(`[data-field-key="${k}"]`);
                const s = w.querySelector('select');
                s.value = v;
                s.dispatchEvent(new Event('change', { bubbles: true }));
                s.dispatchEvent(new Event('input', { bubbles: true }));
            }, key, value);
            await new Promise((r) => setTimeout(r, 60));
        };

        // Nukkad Natak → its own status shows (+ required star), others stay hidden.
        await pickActivity('nukkad_natak');
        check('nukkad selected → nn_status shown', await fieldVisible(page, 'nn_status'));
        check('nukkad selected → nn_status gets required star', await page.evaluate(() => {
            const w = document.querySelector('[data-field-key="nn_status"]');
            return !!w.querySelector('.cond-star');
        }));
        check('nukkad selected → hb_status still hidden', !(await fieldVisible(page, 'hb_status')));

        // Hoardings/Billboards → size dropdown still hidden until status Done.
        await pickActivity('hoardings_billboards');
        check('hoardings selected → hb_status shown, nn_status re-hidden',
            (await fieldVisible(page, 'hb_status')) && !(await fieldVisible(page, 'nn_status')));
        check('hoardings selected → hb_size still hidden (status not Done)', !(await fieldVisible(page, 'hb_size')));
        await pickField('hb_status', 'done');
        check('hoardings + Done → hb_size SHOWN', await fieldVisible(page, 'hb_size'));
        await pickField('hb_size', 'custom_size');
        check('Custom Size → hb_size_details SHOWN', await fieldVisible(page, 'hb_size_details'));
        await pickField('hb_size', 'large');
        check('Large → hb_size_details re-hidden', !(await fieldVisible(page, 'hb_size_details')));

        // Success Stories → sub-category only once Published.
        await pickActivity('success_stories');
        await pickField('ss_status', 'published');
        check('success stories published → ss_subcategory shown', await fieldVisible(page, 'ss_subcategory'));
        await pickActivity('others');
        check('others selected → ot_status shown, story sub-cat re-hidden',
            (await fieldVisible(page, 'ot_status')) && !(await fieldVisible(page, 'ss_subcategory')));
        await assertNoPhpWarnings(page, 'mis/builder/preview.php');

        // 3. Push 5 submissions through /v1/records from the browser -------
        step('Submit 5 test records via REST (browser fetch)');
        const today = new Date().toISOString().slice(0, 10);
        const subs = [
            { act: 'nukkad_natak', stKey: 'nn_status', stVal: 'performance_held', district: 20, pop: 350, place: 'Albert Ekka Chowk, Ranchi', lat: 23.3632, lng: 85.3321 },
            { act: 'hoardings_billboards', stKey: 'hb_status', stVal: 'done', extra: { hb_size: 'large' }, district: 6, pop: 1200, place: 'Ghatshila Main Road', lat: 22.5869, lng: 86.4744 },
            { act: 'success_stories', stKey: 'ss_status', stVal: 'published', extra: { ss_subcategory: 'newspaper_stories' }, district: 12, pop: 5000, place: 'Jamtara Press Club', lat: 24.0146, lng: 86.8057 },
            { act: 'video_dissemination', stKey: 'vd_status', stVal: 'aired', extra: { vd_subcategory: 'nagar_nigam_led_screens' }, district: 10, pop: 800, place: 'Gumla Nagar Nigam Ground', lat: 23.0417, lng: 84.5439 },
            { act: 'others', stKey: 'ot_status', stVal: 'done', district: 5, pop: 220, place: 'Dumka Haat Bazaar', lat: 24.2684, lng: 87.3152 },
        ];
        const CODE_RE = /^JH\/\d{2}\/\d{2}\/\d{2}\/\d{4,}\/\d{4}$/;

        for (let i = 0; i < subs.length; i++) {
            const s = subs[i];
            const answers = {
                survey_date: today,
                location: { district_id: s.district },
                place_name: s.place,
                activity_type: s.act,
                [s.stKey]: s.stVal,
                ...(s.extra || {}),
                target_population: s.pop,
                geo_location: { lat: s.lat, lng: s.lng },
                photo_1: '',
                photo_2: '',
                photo_3: '',
                remarks: `Browser case submission #${i + 1} (${s.act}).`,
            };
            const out = await apiPostRecord(page, {
                record_uuid: `${UUID_PREFIX}${ts}-${i + 1}`,
                form_id: form.id,
                form_version_id: form.version,
                status: 'submitted',
                device_id: `IEC-CASE-${i + 1}`,
                gps: { latitude: s.lat, longitude: s.lng, accuracy: 5 },
                answers,
            });
            check(`Submission ${i + 1} accepted (${s.act})`, out.ok && out.status === 201, `status=${out.status} errors=${JSON.stringify(out.errors)}`);
            const code = out.data?.survey_code ?? '';
            check(`Submission ${i + 1} carries JH/… survey_code`, CODE_RE.test(code), code);
            createdIds.push(Number(out.data?.record_id));
            createdCodes.push(code);
        }
        check('All 5 survey codes unique', new Set(createdCodes).size === 5, createdCodes.join(' | '));

        // 4. Show them in Monitoring ---------------------------------------
        step('Results in MIS Monitoring + Record Detail');
        await page.goto(`${BASE}/mis/monitoring.php?form_id=${form.id}&status=submitted`, { waitUntil: 'networkidle0' });
        await waitForText(page, 'Survey Monitoring');
        check('Monitoring lists the IEC form', await hasText(page, 'IEC Activity Survey'));
        check('Survey ID column present', await hasText(page, 'Survey ID'));
        let codesShown = 0;
        for (const code of createdCodes) {
            if (await hasText(page, code)) codesShown++;
        }
        check('All 5 survey codes listed in monitoring', codesShown === 5, `shown=${codesShown}`);

        const clickedView = await page.evaluate((code) => {
            const rows = Array.from(document.querySelectorAll('tbody tr'));
            const row = rows.find((r) => r.textContent.includes(code));
            const link = row && row.querySelector('a[href*="records.php?id="]');
            if (link) link.click();
            return !!link;
        }, createdCodes[createdCodes.length - 1]);
        check('Opened record detail from Survey ID row', clickedView);
        await page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {});
        await waitForText(page, 'Record Detail');
        const lastCode = createdCodes[createdCodes.length - 1];
        check('Detail shows the stamped survey_code badge', await hasText(page, lastCode));
        check('Detail shows activity label (case-insensitive)', await page.evaluate(() =>
            document.body.innerText.toLowerCase().includes('activity type')));
        check('Detail shows submitter block', await hasText(page, 'Submitted by'));
        await assertNoPhpWarnings(page, 'mis/records.php');

        fs.writeFileSync(LATEST_FILE, JSON.stringify({ recordIds: createdIds, codes: createdCodes }));
    } catch (err) {
        check('case_iec_activity completed', false, err.message);
        console.log(err.stack);
    } finally {
        await browser.close();
    }

    const okAll = await summary();
    console.log('\n  Kept records:');
    createdCodes.forEach((c, i) => console.log(`    #${createdIds[i]}  ${c}`));
    console.log(`  View: ${BASE}/mis/monitoring.php`);
    console.log(`  Clean up later: node tests/case_iec_activity.js --cleanup`);
    process.exit(okAll ? 0 : 1);
}

function runCleanup() {
    // Delete every record pushed by this case (uuid prefix match).
    const tmp = path.join(ROOT, 'tests', '.case_iec_cleanup.php');
    fs.writeFileSync(tmp, `<?php
require '${ROOT.replace(/\\/g, '/')}/common/bootstrap.php';
$pdo = \\App\\Database\\Connection::instance();
$rows = $pdo->query("SELECT id, record_uuid FROM survey_records WHERE record_uuid LIKE '${UUID_PREFIX}%'")->fetchAll();
foreach ($rows as $r) {
    $pdo->prepare('DELETE FROM sync_queue WHERE record_uuid = ?')->execute([$r['record_uuid']]);
    $pdo->prepare('DELETE FROM gps_logs WHERE record_id = ?')->execute([$r['id']]);
    $pdo->prepare('DELETE FROM record_workflow_logs WHERE record_id = ?')->execute([$r['id']]);
    $pdo->prepare('DELETE FROM survey_images WHERE record_id = ?')->execute([$r['id']]);
    $pdo->prepare('DELETE FROM survey_answers WHERE record_id = ?')->execute([$r['id']]);
    $pdo->prepare('DELETE FROM survey_records WHERE id = ?')->execute([$r['id']]);
    echo 'deleted #' . $r['id'] . PHP_EOL;
}
echo count($rows) . " record(s) removed." . PHP_EOL;
`);
    const { execSync } = require('child_process');
    try {
        execSync(`"${process.env.PHP_BIN || 'php'}" "${tmp}"`, { cwd: ROOT, stdio: 'inherit' });
    } finally {
        fs.unlinkSync(tmp);
    }
    if (fs.existsSync(LATEST_FILE)) fs.unlinkSync(LATEST_FILE);
}

(async () => {
    if (CLEANUP) {
        runCleanup();
        process.exit(0);
    }
    await run();
})().catch((err) => {
    console.error('case_iec_activity failed:', err);
    process.exit(1);
});
