'use strict';

/**
 * Case file: push a full form-40 (Government Building Survey) record
 * INCLUDING images, browser-based (headed Chrome) so you can watch it work.
 *
 * It logs into the MIS portal, opens the form-40 builder preview (so you see
 * the rendered form), pushes the record + photos through the real REST API,
 * then shows the result in the MIS Monitoring list and the Record Detail page
 * with the uploaded images rendered.
 *
 * The created record + files are KEPT by default so you can inspect them.
 *   node tests/case_form40.js              # push + view (keeps data)
 *   node tests/case_form40.js --cleanup    # push + delete record/files after
 *   node tests/case_form40.js --cleanup <id>  # delete a previously pushed record
 *
 * Existing E2E suite (tests/e2e) is NOT touched.
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
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
const LATEST_FILE = path.join(__dirname, '.case_form40.latest.json');
const CLEANUP = process.argv.includes('--cleanup');
const cleanupId = process.argv.find((a) => /^\d+$/.test(a)) ? Number(process.argv.find((a) => /^\d+$/.test(a))) : null;

const api = (p) => `${BASE}/api/v1${p}`;

const ts = Date.now().toString(36);
const BUILDING = `FORM40CASE_${ts}`;
const UUID = `case40-${ts}`;

let token = null;
let recordId = null;
let recordUuid = null;

// ---------------------------------------------------------------------------
// API helpers (run via Node's native fetch — same REST API the mobile app uses)
// ---------------------------------------------------------------------------

async function apiLogin() {
    const res = await fetch(api('/auth/login'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: CREDS.admin.username, password: CREDS.admin.password }),
    });
    if (!res.ok) throw new Error(`API login failed: ${res.status} ${await res.text()}`);
    return (await res.json()).data.access_token;
}

async function apiGet(path) {
    const res = await fetch(api(path), { headers: { Authorization: `Bearer ${token}` } });
    if (!res.ok) throw new Error(`GET ${path} failed: ${res.status} ${await res.text()}`);
    return (await res.json()).data;
}

async function apiPostRecord(payload) {
    const res = await fetch(api('/records'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify(payload),
    });
    const json = await res.json();
    if (!res.ok) throw new Error(`POST /records failed: ${res.status} ${JSON.stringify(json)}`);
    return json.data;
}

async function apiUploadPhoto(buffer, mime, filename, fieldKey, category) {
    const form = new FormData();
    form.append('files[]', new Blob([buffer], { type: mime }), filename);
    form.append('field_key', fieldKey);
    form.append('category', category);
    const res = await fetch(api(`/records/${recordId}/photos`), {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
        body: form,
    });
    const json = await res.json();
    if (!res.ok) throw new Error(`photo ${fieldKey} failed: ${res.status} ${JSON.stringify(json)}`);
    return json.data.images;
}

// ---------------------------------------------------------------------------
// Main flow
// ---------------------------------------------------------------------------

(async () => {
    // Cleanup-only mode: delete a previously pushed record + its files.
    if (CLEANUP) {
        let id = cleanupId;
        if (id === null && fs.existsSync(LATEST_FILE)) {
            id = JSON.parse(fs.readFileSync(LATEST_FILE, 'utf8')).recordId;
        }
        if (id === null) {
            console.log('No record to clean up (pass an id: node tests/case_form40.js --cleanup <id>).');
            process.exit(0);
        }
        runCleanup(id);
        console.log(`Cleaned up record ${id}.`);
        process.exit(0);
    }

    const browser = await puppeteer.launch({ headless: false, defaultViewport: { width: 1280, height: 860 } });
    const page = await browser.newPage();
    wirePage(page, 'case_form40');

    try {
        // 1. Log into the MIS portal.
        step('MIS login');
        await page.goto(`${BASE}/mis/login.php`, { waitUntil: 'networkidle0' });
        await page.type('input[name="username"]', CREDS.admin.username);
        await page.type('input[name="password"]', CREDS.admin.password);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('button[type="submit"]'),
        ]);
        check('Logged into MIS dashboard', await hasText(page, 'Dashboard'));

        // 2. Open the form-40 builder preview so you can SEE the rendered form.
        step('Form 40 preview rendered in browser');
        await page.goto(`${BASE}/mis/builder/preview.php?id=40`, { waitUntil: 'networkidle0' });
        await waitForText(page, 'Government Building Survey');
        check('Form 40 preview renders', await hasText(page, 'GOVT_BUILDING_SURVEY'));
        const sectionCount = await page.evaluate(
            () => document.querySelectorAll('h6.text-success').length
        );
        check('Preview shows sections', sectionCount >= 10, `sections=${sectionCount}`);
        await assertNoPhpWarnings(page, 'mis/builder/preview.php');

        // Generate the extra photos in the browser canvas and capture the
        // preview as photo_front (so the uploaded image is the form itself).
        const canvasImages = await page.evaluate(() => {
            function make(spec) {
                const c = document.createElement('canvas');
                c.width = spec.w;
                c.height = spec.h;
                const x = c.getContext('2d');
                x.fillStyle = spec.bg;
                x.fillRect(0, 0, c.width, c.height);
                x.fillStyle = spec.fg;
                x.font = `bold ${spec.font}px Arial`;
                x.textAlign = 'center';
                x.textBaseline = 'middle';
                x.fillText(spec.text, c.width / 2, c.height / 2);
                return c.toDataURL('image/png');
            }
            return [
                { key: 'photo_rear', data: make({ w: 560, h: 320, bg: '#2563eb', fg: '#ffffff', font: 34, text: 'REAR VIEW' }) },
                { key: 'photo_name_board', data: make({ w: 560, h: 200, bg: '#111827', fg: '#facc15', font: 28, text: 'NAME BOARD' }) },
                { key: 'supervisor_signature', data: make({ w: 560, h: 200, bg: '#ffffff', fg: '#111827', font: 24, text: 'Signed — Supervisor' }) },
            ];
        });
        const previewShot = await page.screenshot({ fullPage: false });

        // 3. Authenticate to the REST API as the same admin.
        step('Push record to form id 40 via REST API');
        token = await apiLogin();
        check('API login issues bearer token', typeof token === 'string' && token.length > 20);

        const def = await apiGet('/forms/40');
        check('Form 40 definition accessible via API', (def.form && def.form.id === 40) === true);
        const versionId = def.version;
        check('Form 40 published version resolved', Number.isInteger(versionId), `version_id=${versionId}`);

        const answers = {
            survey_id: `CASE-${ts.toUpperCase()}`,
            survey_date: '2026-08-02',
            surveyor_name: 'Case Runner',
            surveyor_id: 'CASE-001',
            department: { master_id: 34, name: 'Health' },
            state: 'jharkhand',
            location: { district_id: 20, district: 'Ranchi', block_id: 77, block: 'Ranchi' },
            subdivision: 'Sadar',
            habitation: 'Main Habitation',
            latitude: 23.3441,
            longitude: 85.3096,
            elevation: 625.4,
            gps_accuracy: 4.5,
            landmark: 'Near Clock Tower',
            plus_code: '7JQW+HG Ranchi',
            nearest_road: 'Main Road',
            building_name: BUILDING,
            building_code: `BC-${ts.slice(-6).toUpperCase()}`,
            asset_id: `QR-${ts.toUpperCase()}`,
            dept_owner: { master_id: 37, name: 'Hospital' },
            office_type: 'directorate',
            building_category: 'office',
            building_subcategory: { master_id: 49, name: 'Hospital' },
            ownership_type: 'government',
            occupancy_status: 'occupied',
            controlling_authority: 'Collectorate Ranchi',
            head_of_office: 'Mr. Case Officer',
            contact_number: '9000000000',
            email: 'case.file@example.com',
            office_timing: '10:00 - 17:00',
            construction_year: 2015,
            last_renovation_year: 2020,
            num_floors: 3,
            basement_available: 'no',
            built_up_area: 2500.5,
            plot_area: 4000,
            carpet_area: 2000,
            building_height: 12.5,
            num_rooms: 25,
            num_toilets: 6,
            num_halls: 2,
            num_staircases: 2,
            num_lifts: 1,
            structure_type: 'rcc',
            roof_type: 'rcc',
            wall_material: 'brick',
            flooring_type: 'Vitrified Tiles',
            foundation_type: 'RCC Column',
            roof_condition: 'good',
            structural_condition: 'good',
            earthquake_resistant: 'yes',
            fire_resistant: 'no',
            utilities: ['electricity', 'water_supply', 'sanitation'],
            ramp_available: 'yes',
            wheelchair_accessible: 'yes',
            accessible_toilet: 'yes',
            braille_signage: 'no',
            parking_available: 'yes',
            parking_capacity: 15,
            occupied_by: 'District Office',
            num_employees: 60,
            avg_daily_visitors: 200,
            working_days: 'mon_fri',
            maintenance_agency: 'PWD',
            last_maintenance_date: '2026-07-01',
            maintenance_frequency: 'monthly',
            current_condition: 'good',
            fire_exit: 'yes',
            emergency_assembly_area: 'yes',
            disaster_plan: 'yes',
            flood_zone: 'no',
            earthquake_zone: 'zone_iv',
            boundary_length: 310.5,
            area_calc: 3800,
            nearby_road: 'NH-33',
            distance_main_road: 0.2,
            flood_zone_lookup: 'none',
            land_parcel_id: 'LP-7788',
            furniture_count: 120,
            computer_count: 30,
            printer_count: 5,
            vehicle_count: 4,
            generator_available: 'yes',
            solar_panels: 12,
            water_tank_capacity: 5000,
            cctv_cameras: 8,
            land_ownership: 'government',
            land_record_number: 'LR-2026/001',
            mutation_number: 'M-8877',
            building_approval: 'yes',
            completion_certificate: 'yes',
            occupancy_certificate: 'yes',
            capture_room_details: 'no',
            floor_number: 1,
            floor_name: 'ground',
            wing_block: 'A',
            room_number: 'A-101',
            room_name: 'Server Room',
            room_type: 'office',
            room_usage: 'it_room',
            room_area: 120,
            room_occupancy: 4,
            room_condition: 'good',
            air_conditioned: 'yes',
            room_furniture: 'yes',
            room_internet: 'yes',
            general_remarks: 'Browser-based case file submission for form 40.',
            recommendation: 'Keep in good maintenance cycle.',
            supervisor_name: 'District Supervisor',
            verification_date: '2026-08-02',
            // Photo/signature fields are stored as empty answers so the
            // /photos uploads can link to them (answer_id + value_json path).
            photo_front: '',
            photo_rear: '',
            photo_left: '',
            photo_right: '',
            photo_entrance: '',
            photo_name_board: '',
            photo_roof: '',
            photo_interior: '',
            photo_electrical_panel: '',
            photo_water_tank: '',
            photo_toilets: '',
            photo_parking: '',
            photo_boundary_wall: '',
            damage_photos: '',
            room_photo: '',
            supervisor_signature: '',
            surveyor_signature: '',
        };

        const created = await apiPostRecord({
            record_uuid: UUID,
            form_id: 40,
            form_version_id: versionId,
            status: 'submitted',
            answers,
            gps: { latitude: 23.3441, longitude: 85.3096, accuracy: 4.5, altitude: 625.4, captured_at: '2026-08-02 10:15:00' },
        });
        recordId = Number(created.record_id);
        recordUuid = created.record_uuid;
        check('Record created for form 40', Number.isInteger(recordId) && recordId > 0, `record_id=${recordId}`);
        check('Submitted status applied', created.status === 'submitted', created.status);
        fs.writeFileSync(LATEST_FILE, JSON.stringify({ recordId, recordUuid, building: BUILDING }));

        // 4. Upload images linked to photo fields + a signature.
        step('Upload images (photo_front / rear / name_board / signature)');
        const toData = (b) => Buffer.from(b.split(',')[1], 'base64');
        const shots = [
            { buf: previewShot, mime: 'image/png', name: 'photo_front.png', key: 'photo_front', cat: 'photo' },
            { buf: toData(canvasImages[0].data), mime: 'image/png', name: 'photo_rear.png', key: 'photo_rear', cat: 'photo' },
            { buf: toData(canvasImages[1].data), mime: 'image/png', name: 'photo_name_board.png', key: 'photo_name_board', cat: 'photo' },
            { buf: toData(canvasImages[2].data), mime: 'image/png', name: 'supervisor_signature.png', key: 'supervisor_signature', cat: 'signature' },
        ];
        let uploadedCount = 0;
        for (const s of shots) {
            const images = await apiUploadPhoto(s.buf, s.mime, s.name, s.key, s.cat);
            check(`Uploaded ${s.key}`, images.length === 1 && images[0].answer_id !== null, images[0]?.file_path);
            uploadedCount += images.length;
        }
        check('All 4 files uploaded', uploadedCount === 4, `count=${uploadedCount}`);

        // 5. Verify via the API that answers + images persisted.
        step('Verify record via API');
        const detail = await apiGet(`/records/${recordId}`);
        const answerKeys = (detail.answers || []).map((a) => a.field_key);
        check('Record detail returns answers', answerKeys.includes('building_name') && answerKeys.includes('department'));
        const buildingAnswer = (detail.answers || []).find((a) => a.field_key === 'building_name');
        check('building_name persisted', (buildingAnswer?.value_text || '') === BUILDING, buildingAnswer?.value_text);
        check('Record detail returns 4 images', (detail.images || []).length === 4, `images=${detail.images?.length}`);
        const photo = (detail.images || []).find((i) => i.category === 'photo');
        check('Images carry a web path', typeof photo?.file_path === 'string' && photo.file_path.includes('uploads/survey/'));

        // 6. Show it in the browser: Monitoring (form 40) → Record Detail.
        step('Show record in MIS Monitoring (form 40)');
        await page.goto(`${BASE}/mis/monitoring.php?form_id=40&status=submitted`, { waitUntil: 'networkidle0' });
        await waitForText(page, `#${recordId}`);
        check('Record appears in monitoring for form 40', await hasText(page, `#${recordId}`));
        check('Form title shown in monitoring', await hasText(page, 'Government Building Survey'));
        await assertNoPhpWarnings(page, 'mis/monitoring.php');

        const clickedView = await page.evaluate((rid) => {
            const rows = Array.from(document.querySelectorAll('tr'));
            const row = rows.find((r) => r.textContent.includes('#' + rid));
            const link = row && row.querySelector('a[href*="records.php?id="]');
            if (link) link.click();
            return !!link;
        }, recordId);
        check('View button opens record detail', clickedView);

        await page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {});
        await waitForText(page, 'Record Detail');
        check('Record detail shows building name', await hasText(page, BUILDING));
        check('Record detail shows answers table', await hasText(page, 'Submitted Answers'));
        check('Record detail lists attached files', await hasText(page, 'Attached Files (4)'));
        const renderedImgs = await page.evaluate(
            () => document.querySelectorAll('img[src*="uploads/survey/"]').length
        );
        check('Uploaded images render on detail page', renderedImgs >= 4, `imgs=${renderedImgs}`);
        check('Submitter name shown', await hasText(page, 'State Administrator'));
        await assertNoPhpWarnings(page, 'mis/records.php');
    } catch (err) {
        check('case_form40 completed', false, err.message);
        console.log(err.stack);
    } finally {
        await browser.close();
    }

    const okAll = await summary();
    console.log(`\n  Record kept for viewing: ${BASE}/mis/records.php?id=${recordId}`);
    console.log(`  Clean up later:  node tests/case_form40.js --cleanup ${recordId}`);
    if (CLEANUP && recordId) {
        runCleanup(recordId);
        console.log(`  Cleaned up record ${recordId}.`);
    }
    process.exit(okAll ? 0 : 1);
})().catch((err) => {
    console.error('case_form40 failed:', err);
    process.exit(1);
});

function runCleanup(id) {
    const phpCode = [
        "require 'common/bootstrap.php';",
        "$pdo = \\App\\Database\\Connection::instance();",
        "$id = (int)$argv[1];",
        "$pdo->exec('DELETE FROM survey_answers WHERE record_id = '.$id);",
        "$pdo->exec('DELETE FROM survey_images WHERE record_id = '.$id);",
        "$pdo->exec('DELETE FROM gps_logs WHERE record_id = '.$id);",
        "$pdo->exec('DELETE FROM record_workflow_logs WHERE record_id = '.$id);",
        "$pdo->exec('DELETE FROM survey_records WHERE id = '.$id);",
    ].join(' ');
    const tmp = path.join(ROOT, 'tests', '.case_form40_cleanup.php');
    fs.writeFileSync(tmp, '<?php ' + phpCode);
    try {
        execSync(`php ${tmp} ${id}`, { cwd: ROOT, stdio: 'pipe' });
    } finally {
        fs.unlinkSync(tmp);
    }
    fs.rmSync(path.join(ROOT, 'uploads', 'survey', String(id)), { recursive: true, force: true });
    if (fs.existsSync(LATEST_FILE)) {
        const latest = JSON.parse(fs.readFileSync(LATEST_FILE, 'utf8'));
        if (latest.recordId === id) fs.unlinkSync(LATEST_FILE);
    }
}
