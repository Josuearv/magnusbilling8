import { createServer } from 'node:http';
import { readFile, readdir } from 'node:fs/promises';
import { resolve, extname, sep } from 'node:path';

// PHP/authentication are mocked locally; this verifies the logged-out frontend only.
// Set BOOT_URL to check a deployed public login instead (no credentials are used).
// Run with PLAYWRIGHT_MODULE pointing to playwright's index.mjs when it is not installed locally.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = resolve(import.meta.dirname, '..');
const theme = process.argv[2] || 'black-neptune';
if (!/^[a-z]+-[a-z]+$/.test(theme)) throw new Error('Invalid theme name');
const liveUrl = process.env.BOOT_URL;
const missing = new Set();
const errors = [];
const pending = new Set();
const failedRequests = [];
const types = { '.html': 'text/html', '.js': 'application/javascript', '.json': 'application/json', '.css': 'text/css' };
const server = createServer(async (req, res) => {
    const pathname = decodeURIComponent(new URL(req.url, 'http://localhost').pathname).replace(/^\/mbilling\//, '');
    if (pathname === 'index.php') {
        res.setHeader('Content-Type', 'application/javascript');
        return res.end(`window.lang="en";window.theme=${JSON.stringify(theme)};window.theme_color=${JSON.stringify(theme.split('-')[0])};window.layout=0;window.wallpaper="Azul";window.reCaptchaKey="";window.show_signup_button=0;window.enable_signup=0;`);
    }
    if (pathname === 'index.php/authentication/check') {
        res.setHeader('Content-Type', 'application/json');
        return res.end('{"success":false}');
    }
    try {
        const relative = pathname || 'index.html';
        const file = resolve(root, relative);
        if (!file.startsWith(root + sep)) throw new Error('Outside repository');
        // Enforce Linux case sensitivity even when this check runs on Windows.
        let directory = root;
        for (const part of relative.split('/')) {
            if (!(await readdir(directory)).includes(part)) throw new Error('Missing path');
            directory = resolve(directory, part);
        }
        const data = await readFile(file);
        res.setHeader('Content-Type', types[extname(file)] || 'application/octet-stream');
        res.end(data);
    } catch {
        res.writeHead(404).end('Not found');
    }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
let browser;
try {
    browser = await chromium.launch({ headless: true, ...(process.env.CHROME_BINARY ? { executablePath: process.env.CHROME_BINARY } : {}) });
    const page = await browser.newPage();
    const url = liveUrl || `http://127.0.0.1:${server.address().port}/mbilling/`;
    const origin = new URL(url).origin;
    page.on('pageerror', error => errors.push(error.stack));
    page.on('request', request => pending.add(request.url()));
    page.on('requestfinished', request => pending.delete(request.url()));
    page.on('requestfailed', request => {
        pending.delete(request.url());
        if (new URL(request.url()).origin === origin) failedRequests.push({ url: request.url(), error: request.failure()?.errorText });
    });
    page.on('response', response => {
        if (response.status() >= 400 && new URL(response.url()).origin === origin) missing.add(new URL(response.url()).pathname);
    });
    await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const startedAt = Date.now();
    await page.goto(url);
    try {
        await page.waitForFunction(() => window.Ext && Ext.ComponentQuery && Ext.ComponentQuery.query('login').some(component => component.rendered) && !document.getElementById('loading'), null, { timeout: liveUrl ? 120000 : 30000 });
    } catch { errors.push('Login did not render and dismiss the loading screen before the timeout'); }
    const state = await page.evaluate(() => ({ application: !!window.App, loading: !!document.getElementById('loading'), login: !!(window.Ext && Ext.ComponentQuery && Ext.ComponentQuery.query('login').length), scripts: document.scripts.length, theme: window.theme, loader: window.Ext?.Loader ? { missingQueue: Ext.Loader.missingQueue, isLoading: Ext.Loader.isLoading } : null }));
    console.log(JSON.stringify({ theme, elapsedMs: Date.now() - startedAt, missing: [...missing], errors, failedRequests, pending: [...pending], state }, null, 2));
    // Missing decorative images should be reported without failing a successful boot.
    const missingDependencies = [...missing].filter(path => /\.(?:js|json|css)$/.test(path));
    process.exitCode = missingDependencies.length || errors.length || failedRequests.length ? 1 : 0;
} finally {
    await browser?.close();
    await new Promise(resolve => server.close(resolve));
}
