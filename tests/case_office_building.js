'use strict';

/**
 * Case file: exercise the OFFICE_BUILDING_SURVEY form in the BROWSER (headed
 * Chrome) and push 5 test submissions through the real REST API.
 *
 * 1. Logs into the MIS portal.
 * 2. Opens the form's builder preview and drives it live: master dropdowns
 *    (Department / DOM Physical Status / DOM Occupancy Status) render their
 *    items, and Building Age AUTO-CALCULATES from Construction Year on
 *    change (settings.calc) and clears when the year is emptied.
 * 3. Submits 5 records (different districts/levels/statuses, one with a fake
 *    building_age to prove the server recomputes it) via /v1/records using
 *    browser-side fetch with a bearer token, asserting the JH/… survey_code.
 * 4. Shows the results in MIS Monitoring (Survey ID column) and opens a
 *    Record Detail page, verifying the server-stamped age.
 *
 * Records are KEPT by default:
 *   node tests/case_office_building.js              # run + keep data
 *   node tests/case_office_building.js --cleanup    # delete this case's records
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
const LATEST_FILE = path.join(__dirname, '.case_office.latest.json');
const CLEANUP = process.argv.includes('--cleanup');
const UUID_PREFIX = 'office-case-';

const api = (p) => `${BASE}/api/v1${p}`;
const ts = Date.now().toString(36);
const YEAR = new Date().getFullYear();

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
        const found = (json.data.forms || json.data || []).find((f) => f.code === 'OFFICE_BUILDING_SURVEY');
        if (!found) throw new Error('OFFICE_BUILDING_SURVEY not found in /forms');
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

async function run() {
    const browser = await puppeteer.launch({ headless: false, defaultViewport: { width: 1280, height: 860 } });
    const page = await browser.newPage();
    wirePage(page, 'case_office_building');

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
        check('OFFICE_BUILDING_SURVEY resolved via /forms', Number.isInteger(form.id), `form_id=${form.id}`);

        // 2. Drive the preview UI live ------------------------------------
        step('Builder preview: masters + live age calculation');
        await page.goto(`${BASE}/mis/builder/preview.php?id=${form.id}`, { waitUntil: 'networkidle0' });
        await waitForText(page, 'Office Building Survey');
        check('Preview renders all 26 field wrappers', await page.evaluate(() =>
            document.querySelectorAll('[data-field-key]').length === 26));

        const selectInfo = await page.evaluate(() => {
            const opts = (k) => {
                const w = document.querySelector(`[data-field-key="${k}"]`);
                const s = w && w.querySelector('select');
                return s ? s.options.length : -1;
            };
            return {
                department: opts('department'),
                physical_status: opts('physical_status'),
                occupancy_status: opts('occupancy_status'),
            };
        });
        check('Department master dropdown has items', selectInfo.department > 1, `options=${selectInfo.department}`);
        check('Physical Status dropdown has the 8 DOM items', selectInfo.physical_status === 9, `options=${selectInfo.physical_status}`);
        check('Occupancy Status dropdown has the 5 DOM items', selectInfo.occupancy_status === 6, `options=${selectInfo.occupancy_status}`);

        const setYear = (y) => page.evaluate((v) => {
            const w = document.querySelector('[data-field-key="construction_year"]');
            const inp = w.querySelector('input');
            inp.value = v;
            inp.dispatchEvent(new Event('input', { bubbles: true }));
        }, y);
        const ageValue = () => page.evaluate(() => {
            const w = document.querySelector('[data-field-key="building_age"]');
            const inp = w.querySelector('input');
            return inp.value;
        });

        await setYear('2000');
        check(`Age auto-calculates on change (${YEAR} - 2000)`, (await ageValue()) === String(YEAR - 2000), await ageValue());
        await setYear('1990');
        check('Age recalculates when the year changes', (await ageValue()) === String(YEAR - 1990), await ageValue());
        await setYear('');
        check('Age clears when the year is emptied', (await ageValue()) === '', await ageValue());
        await assertNoPhpWarnings(page, 'mis/builder/preview.php');

        // 3. Push 5 submissions through /v1/records from the browser -------
        step('Submit 5 test records via REST (browser fetch)');
        const today = new Date().toISOString().slice(0, 10);
        const subs = [
            { level: 'state', name: 'Swarna Jayanti Bhawan Secretariat', district: 20, phys: 'Good', occ: 'Fully Occupied', dept: 'Education', roads: ['nh'], floors: 5, rooms: 48, tm: 4, tf: 6, lat: 23.3728, lng: 85.3348 },
            { level: 'district', name: 'East Singhbhum Collectorate', district: 6, phys: 'Fair / Minor Repair Required', occ: 'Partially Occupied', dept: 'Education', roads: ['nh', 'sh'], floors: 3, rooms: 32, tm: 3, tf: 3, lat: 22.8046, lng: 86.2029 },
            { level: 'sub_division', name: 'Jamtara Sub-Division Office', district: 12, phys: 'Poor', occ: 'Vacant', dept: 'Education', roads: ['sh', 'odr'], floors: 2, rooms: 18, tm: 2, tf: 2, lat: 24.0146, lng: 86.8057 },
            { level: 'block', name: 'Gumla Block Office', district: 10, phys: 'Major Repair Required', occ: 'Not in Use', dept: 'Education', roads: ['odr', 'rural'], floors: 1, rooms: 12, tm: 1, tf: 1, lat: 23.0417, lng: 84.5439 },
            { level: 'panchayat', name: 'Dumka Panchayat Bhavan', district: 5, phys: 'Under Construction', occ: 'Under Construction', dept: 'Education', roads: ['pmgsy'], floors: 1, rooms: 8, tm: 1, tf: 1, year: 2001, fakeAge: '999', lat: 24.2684, lng: 87.3152 },
        ];
        const CODE_RE = /^JH\/\d{2}\/\d{2}\/\d{2}\/\d{4,}\/\d{4}$/;

        for (let i = 0; i < subs.length; i++) {
            const s = subs[i];
            const answers = {
                building_level: s.level,
                building_name: s.name,
                department: s.dept,
                office_name: `${s.name} Office`,
                office_incharge: `In-charge ${i + 1}`,
                contact_mobile: `9876543${(210 + i)}`,
                contact_email: `office${i + 1}@jh.gov.in`,
                location: { district_id: s.district },
                address: `${s.name}, Jharkhand`,
                landmark: `Near Block Chowk ${i + 1}`,
                approach_roads: s.roads,
                construction_year: s.year ?? 1995 + i * 5,
                ...(s.fakeAge ? { building_age: s.fakeAge } : {}),
                construction_cost: 5000000 + i * 1000000,
                no_floors: s.floors,
                no_rooms: s.rooms,
                toilets_male: s.tm,
                toilets_female: s.tf,
                physical_status: s.phys,
                occupancy_status: s.occ,
                remarks: `Browser case submission #${i + 1} (${s.level}).`,
                geo_location: { lat: s.lat, lng: s.lng },
                photo_front: '',
                photo_campus: '',
                photo_back: '',
            };
            const out = await apiPostRecord(page, {
                record_uuid: `${UUID_PREFIX}${ts}-${i + 1}`,
                form_id: form.id,
                form_version_id: form.version,
                status: 'submitted',
                device_id: `OFFICE-CASE-${i + 1}`,
                gps: { latitude: s.lat, longitude: s.lng, accuracy: 5 },
                answers,
            });
            check(`Submission ${i + 1} accepted (${s.level})`, out.ok && out.status === 201, `status=${out.status} errors=${JSON.stringify(out.errors)}`);
            const code = out.data?.survey_code ?? '';
            check(`Submission ${i + 1} carries JH/… survey_code`, CODE_RE.test(code), code);
            createdIds.push(Number(out.data?.record_id));
            createdCodes.push(code);
        }
        check('All 5 survey codes unique', new Set(createdCodes).size === 5, createdCodes.join(' | '));

        // 4. Show them in Monitoring + verify server-side age stamp --------
        step('Results in MIS Monitoring + Record Detail');
        await page.goto(`${BASE}/mis/monitoring.php?form_id=${form.id}&status=submitted`, { waitUntil: 'networkidle0' });
        await waitForText(page, 'Survey Monitoring');
        check('Monitoring lists the office form', await hasText(page, 'Office Building Survey'));
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
        // Submission #5 sent building_age=999 with year 2001 — the server must show 25.
        check(`Server recomputed the faked age (${YEAR} - 2001)`, await page.evaluate((y) =>
            document.body.innerText.includes(String(y)), YEAR - 2001),
        `expected text "${YEAR - 2001}"`);
        check('Faked age 999 is NOT shown', !(await hasText(page, '999')));
        check('Detail shows master status label', await hasText(page, 'Under Construction'));
        check('Detail shows submitter block', await hasText(page, 'Submitted by'));
        await assertNoPhpWarnings(page, 'mis/records.php');

        fs.writeFileSync(LATEST_FILE, JSON.stringify({ recordIds: createdIds, codes: createdCodes }));
    } catch (err) {
        check('case_office_building completed', false, err.message);
        console.log(err.stack);
    } finally {
        await browser.close();
    }

    const okAll = await summary();
    console.log('\n  Kept records:');
    createdCodes.forEach((c, i) => console.log(`    #${createdIds[i]}  ${c}`));
    console.log(`  View: ${BASE}/mis/monitoring.php`);
    console.log(`  Clean up later: node tests/case_office_building.js --cleanup`);
    process.exit(okAll ? 0 : 1);
}

function runCleanup() {
    // Delete every record pushed by this case (uuid prefix match).
    const tmp = path.join(ROOT, 'tests', '.case_office_cleanup.php');
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
    console.error('case_office_building failed:', err);
    process.exit(1);
});
