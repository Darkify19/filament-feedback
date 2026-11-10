#!/usr/bin/env node
// Drives the running dev host (server.php) in headless Chrome. One command per run:
//
//   node driver.mjs shot  <user> <path> [out.png]           log in, open path, screenshot
//   node driver.mjs post  <user> <body> [module] [mood]     open the Feedback slide-over, fill it, Send
//   node driver.mjs reply <user> <text> [status]            inbox -> open newest post -> reply + status -> Save
//   node driver.mjs eval  <user> <path> <js>                print the result of a page expression
//
// <user> is a users.id: 1 = Ana (staff), 2 = Ben (triager). Screenshots land in $FFB_SHOTS
// (default: OS temp dir). Base URL: $FFB_URL (default http://127.0.0.1:8123).
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright-core';

const BASE = process.env.FFB_URL ?? 'http://127.0.0.1:8123';
const SHOTS = process.env.FFB_SHOTS ?? tmpdir();
const [cmd, user, ...args] = process.argv.slice(2);

const browser = await chromium.launch({
    // System Chrome; set CHROME_PATH on a box without it (any Chromium binary works).
    ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : { channel: 'chrome' }),
});
const page = await browser.newPage({ viewport: { width: 1280, height: 860 } });
page.on('pageerror', (e) => console.error('pageerror:', e.message));

const go = async (path) => {
    await page.goto(`${BASE}${path}`);
    await page.waitForLoadState('networkidle');
};
const settle = () => page.waitForLoadState('networkidle').then(() => page.waitForTimeout(400));
// Filament's role=dialog wrapper has no box of its own, so Playwright calls it hidden
// even when open; the visible part is .fi-modal-window inside the open modal.
const openModal = async () => {
    const modal = page.locator('.fi-modal-open .fi-modal-window').last();
    await modal.waitFor();
    return modal;
};
const shot = async (name) => {
    const file = join(SHOTS, name);
    await page.screenshot({ path: file });
    console.log('screenshot:', file);
};

try {
    // /_as/{id} logs in and redirects to ?to=, so one hop lands on the page.
    const login = (path) => go(`/_as/${user}?to=${encodeURIComponent(path)}`);

    if (cmd === 'shot') {
        const [path = '/testing', out = 'ffb-shot.png'] = args;
        await login(path);
        await shot(out);
    } else if (cmd === 'post') {
        const [body, module, mood = 'Frustrated'] = args;
        await login('/testing');
        await page.locator('.ffb-launcher button').click();
        const modal = await openModal();
        // ToggleButtons render a hidden input + <label>; click the label text.
        if (module) await modal.locator('label').filter({ hasText: new RegExp(`^\\s*${module}\\s*$`) }).click();
        await modal.locator('label').filter({ hasText: mood }).click();
        await modal.locator('textarea').fill(body);
        await shot('ffb-post-filled.png');
        await modal.getByRole('button', { name: 'Send' }).click();
        await settle();
        await shot('ffb-post-sent.png');
    } else if (cmd === 'reply') {
        const [text, status = 'Planned'] = args;
        await login('/testing/feedback');
        await page.locator('.fi-ta-row').first().click();
        const modal = await openModal();
        await modal.locator('textarea').fill(text);
        await modal.locator('label').filter({ hasText: status }).click();
        await shot('ffb-reply-filled.png');
        await modal.getByRole('button', { name: 'Save' }).click();
        await settle();
        await shot('ffb-reply-saved.png');
    } else if (cmd === 'eval') {
        const [path, js] = args;
        await login(path);
        console.log(JSON.stringify(await page.evaluate(js), null, 2));
    } else {
        console.error('usage: node driver.mjs shot|post|reply|eval <user-id> ...');
        process.exitCode = 2;
    }
} catch (e) {
    await shot('ffb-error.png').catch(() => {});
    throw e;
} finally {
    await browser.close();
}
