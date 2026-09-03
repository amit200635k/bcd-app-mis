'use strict';
const puppeteer = require('puppeteer');
const { BASE, CREDS } = require('./lib.js');

(async () => {
    const browser = await puppeteer.launch({ headless: false, defaultViewport: { width: 1400, height: 900 } });
    const page = await browser.newPage();
    await page.goto(BASE + '/mis/login.php', { waitUntil: 'networkidle0' });
    await page.type('input[name=username]', CREDS.admin.username);
    await page.type('input[name=password]', CREDS.admin.password);
    await page.evaluate(() => document.querySelector('form').submit());
    await page.waitForNavigation({ waitUntil: 'networkidle0' });
    await page.goto(BASE + '/admin/access.php', { waitUntil: 'networkidle0' });
    const btns = await page.evaluate(() => Array.from(document.querySelectorAll('button'))
        .filter((b) => b.textContent.includes('Manage'))
        .map((b) => ({ onclick: b.getAttribute('onclick'), html: b.outerHTML })));
    console.log('MANAGE BUTTONS:');
    btns.forEach((b) => console.log('  ', b.onclick));
    const clicked = await page.evaluate(() => {
        const btn = Array.from(document.querySelectorAll('button')).find((b) => b.textContent.includes('Manage') && b.getAttribute('onclick')?.includes(', false)'));
        if (!btn) return false;
        btn.click();
        return true;
    });
    console.log('clicked:', clicked);
    await new Promise((r) => setTimeout(r, 2500));
    const state = await page.evaluate(() => {
        const modal = document.getElementById('accessModal');
        const mis = document.getElementById('ac_portal_mis');
        return {
            modalExists: !!modal,
            modalClasses: modal ? modal.className : null,
            misExists: !!mis,
            misVisible: mis ? getComputedStyle(mis).visibility : null,
            misDisplay: mis ? getComputedStyle(mis).display : null,
            modalDisplay: modal ? getComputedStyle(modal).display : null,
            bodyText: document.body.innerText.slice(0, 120),
        };
    });
    console.log('MODAL STATE:', JSON.stringify(state, null, 2));
    await browser.close();
})();