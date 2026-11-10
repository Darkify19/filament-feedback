#!/usr/bin/env node
// Drives the running dev host (server.php) in headless Chrome. One command per run:
//
//   node driver.mjs shot  <user> <path> [out.png]           log in, open path, screenshot
//   node driver.mjs post  <user> <body> [module] [mood]     open the Feedback slide-over, fill it, Send
//   node driver.mjs reply <user> <text> [status]            inbox -> open newest post -> reply + status -> Save
//   node driver.mjs click <user> <path> <step>... [--out f.png]  run steps in turn: a Playwright
//                     locator is clicked, 'type=<text>' types into the focused field key by key,
//                     'key=<Key>' presses a key (Enter, Escape, …)
//   node driver.mjs type  <user> <path> <locator> <text> [--out f.png]  type into a field key by key
//   node driver.mjs eval  <user> <path> <js>                print the result of a page expression
//
// <user> is a users.id: 1 = Ana (staff), 2 = Ben (triager). Screenshots land in $FFB_SHOTS
// (default: OS temp dir). Base URL: $FFB_URL (default http://127.0.0.1:8123).
// FFB_PHONE=1 renders at 400px wide; FFB_DARK=1 asks for the dark color scheme.
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
const page = await browser.newPage({
    viewport: process.env.FFB_PHONE ? { width: 400, height: 860 } : { width: 1280, height: 860 },
    colorScheme: process.env.FFB_DARK ? 'dark' : 'light',
});
page.on('pageerror', (e) => console.error('pageerror:', e.message));
// Accept native confirms (wire:confirm on Unsend); Playwright dismisses them by default.
page.on('dialog', (dialog) => dialog.accept());

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
        // Modules: the picker's "Add" list (typing #Module in the message works too).
        if (module) {
            await modal.locator('.ffb-picker-add').click();
            await modal.locator('.ffb-picker-options .ffb-picker-option').filter({ hasText: new RegExp(`^\\s*${module}\\s*$`) }).click();
        }
        // Moods are ToggleButtons: a hidden input + <label>; click the label text.
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
    } else if (cmd === 'click') {
        const out = args.includes('--out') ? args.splice(args.indexOf('--out'), 2)[1] : 'ffb-click.png';
        const [path, ...locators] = args;
        await login(path);
        for (const step of locators) {
            if (step.startsWith('type=')) await page.keyboard.type(step.slice(5), { delay: 40 });
            else if (step.startsWith('key=')) await page.keyboard.press(step.slice(4));
            else await page.locator(step).first().click();
            await settle();
        }
        await shot(out);
    } else if (cmd === 'type') {
        const out = args.includes('--out') ? args.splice(args.indexOf('--out'), 2)[1] : 'ffb-type.png';
        const [path, locator, text] = args;
        await login(path);
        await page.locator(locator).first().click();
        // Key by key, so input/keydown listeners (the # suggestions) see it like a person typing.
        await page.keyboard.type(text, { delay: 40 });
        await page.waitForTimeout(300);
        await shot(out);
    } else if (cmd === 'eval') {
        const [path, js] = args;
        await login(path);
        console.log(JSON.stringify(await page.evaluate(js), null, 2));
    } else {
        console.error('usage: node driver.mjs shot|post|reply|click|type|eval <user-id> ...');
        process.exitCode = 2;
    }
} catch (e) {
    await shot('ffb-error.png').catch(() => {});
    throw e;
} finally {
    await browser.close();
}
