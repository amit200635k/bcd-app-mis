'use strict';

const BASE = process.env.BCD_BASE_URL || 'http://localhost:81/bcd-app';

const CREDS = {
    admin: { username: 'admin', password: 'Admin@12345' },
    surveyor: { username: 'rk_surveyor', password: 'Demo@123' },
    district: { username: 'sk_district', password: 'Demo@123' },
    block: { username: 'jb_block', password: 'Demo@123' },
    panchayat: { username: 'pm_panchayat', password: 'Demo@123' },
    village: { username: 'vp_village', password: 'Demo@123' },
};

const results = [];
const consoleErrors = [];
const pageErrors = [];
const warnings = [];

function step(name) {
    console.log(`\n  --- ${name} ---`);
}

function check(name, pass, detail) {
    results.push({ name, pass: !!pass, detail });
    const mark = pass ? 'PASS' : 'FAIL';
    console.log(`  [${mark}] ${name}${detail ? ` — ${detail}` : ''}`);
    return !!pass;
}

function ok(name, cond, detail) {
    return check(name, cond, detail);
}

// Click an element whose trimmed textContent includes `text`.
async function clickText(page, text, tag = 'a') {
    const clicked = await page.evaluate(
        (t, sel) => {
            const el = Array.from(document.querySelectorAll(sel)).find(
                (e) => e.textContent.trim().includes(t) && e.offsetParent !== null
            );
            if (el) { el.click(); return true; }
            return false;
        },
        text,
        tag
    );
    return clicked;
}

// Click an element matching a CSS selector that contains `text`.
async function clickCssContaining(page, css, text) {
    return page.evaluate(
        (sel, t) => {
            const el = Array.from(document.querySelectorAll(sel)).find(
                (e) => e.textContent.trim().includes(t) && e.offsetParent !== null
            );
            if (el) { el.click(); return true; }
            return false;
        },
        css,
        text
    );
}

async function type(page, selector, value) {
    const want = String(value);
    await page.waitForSelector(selector, { visible: true, timeout: 8000 });
    for (let attempt = 0; attempt < 3; attempt++) {
        await page.click(selector, { clickCount: 3 });
        await page.type(selector, want, { delay: 10 });
        const got = await page.evaluate((s) => {
            const el = document.querySelector(s);
            if (!el) return null;
            return (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') ? el.value : (el.textContent || '');
        }, selector).catch(() => null);
        if (got === want) return;
        console.log(`  [type retry ${attempt + 1}] ${selector} got ${JSON.stringify(got)} want ${JSON.stringify(want)}`);
    }
}

// Wait for a text fragment to appear anywhere in the document.
async function waitForText(page, text, timeout = 8000) {
    await page.waitForFunction(
        (t) => document.body && document.body.innerText.includes(t),
        { timeout },
        text
    );
}

async function hasText(page, text) {
    return page.evaluate((t) => document.body && document.body.innerText.includes(t), text);
}

// Collect page PHP warnings/notices/fatals from the rendered HTML.
async function assertNoPhpWarnings(page, name) {
    const text = await page.content();
    const m = text.match(/(<b>(?:Warning|Notice|Deprecated|Fatal error)<\/b>|PHP (?:Warning|Notice|Fatal error|Deprecated|Parse error)|Fatal error:|Parse error:)/i);
    if (m) {
        warnings.push(`${name} => ${m[0]}`);
        return check(`no PHP warnings on ${name}`, false, m[0]);
    }
    return check(`no PHP warnings on ${name}`, true);
}

// Attach global browser diagnostics for a page.
function wirePage(page, label) {
    page.on('pageerror', (err) => {
        const msg = `${label} | ${err.message}`;
        pageErrors.push(msg);
        console.log(`  [JS ERROR] ${msg}`);
    });
    page.on('console', (msg) => {
        if (msg.type() === 'error' || msg.type() === 'warning') {
            const text = msg.text();
            if (!/favicon/i.test(text) && !/Failed to load resource/i.test(text)) {
                consoleErrors.push(`${label} | ${msg.type()} | ${text}`);
                console.log(`  [CONSOLE ${msg.type().toUpperCase()}] ${label} | ${text}`);
            }
        }
    });
    page.on('requestfailed', (req) => {
        const url = req.url();
        if (/api\/index\.php/i.test(url) && !/favicon/i.test(url)) {
            consoleErrors.push(`${label} | REQUEST_FAILED | ${url}`);
        }
    });
    // Accept JS confirm()/alert() dialogs automatically.
    page.on('dialog', (d) => d.accept());
}

async function summary() {
    const passed = results.filter((r) => r.pass).length;
    const failed = results.length - passed;
    console.log('\n==================== TEST SUMMARY ====================');
    console.log(`Total: ${results.length}  Passed: ${passed}  Failed: ${failed}`);
    if (warnings.length) {
        console.log('\nPHP warnings detected:');
        warnings.forEach((w) => console.log('  - ' + w));
    }
    if (consoleErrors.length) {
        console.log('\nConsole errors detected:');
        consoleErrors.slice(0, 20).forEach((c) => console.log('  - ' + c));
    }
    if (pageErrors.length) {
        console.log('\nUncaught page errors detected:');
        pageErrors.slice(0, 20).forEach((p) => console.log('  - ' + p));
    }
    console.log('======================================================');
    return failed === 0;
}

module.exports = {
    BASE,
    CREDS,
    step,
    check,
    ok,
    clickText,
    clickCssContaining,
    type,
    waitForText,
    hasText,
    assertNoPhpWarnings,
    wirePage,
    summary,
};
