import { createServer } from 'node:http';
import { readFile, readdir, writeFile } from 'node:fs/promises';
import { resolve, extname, sep } from 'node:path';

// PHP/authentication are mocked locally; BOOT_AUTH exercises an existing session.
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
const expectedFailure = process.env.BOOT_EXPECT_FAILURE;
const types = { '.html': 'text/html', '.js': 'application/javascript', '.json': 'application/json', '.css': 'text/css' };
const server = createServer(async (req, res) => {
    const pathname = decodeURIComponent(new URL(req.url, 'http://localhost').pathname).replace(/^\/mbilling\//, '');
    if (pathname === 'index.php') {
        res.setHeader('Content-Type', 'application/javascript');
        return res.end(`window.lang="en";window.theme=${JSON.stringify(theme)};window.theme_color=${JSON.stringify(theme.split('-')[0])};window.layout=0;window.wallpaper="Azul";window.reCaptchaKey="";window.show_signup_button=0;window.enable_signup=0;`);
    }
    if (pathname === 'index.php/authentication/check') {
        res.setHeader('Content-Type', 'application/json');
        if (process.env.BOOT_AUTH === 'invalid') return res.end('<html>PHP error</html>');
        if (process.env.BOOT_AUTH === 'http500') return res.writeHead(500).end('Server error');
        if (process.env.BOOT_AUTH) {
            const response = {success:true,id:1,name:'Boot test',username:'boot-test',menu:[],language:'en',theme,currency:'USD',credit:0,isAdmin:true,isClient:false,isAgent:false,isClientAgent:false,id_group:1,user_type:1,decimal:2,email:'boot@example.invalid',userCount:1,base_country:'USA',version:'8',social_media_network:'',checkGoogleAuthenticator:false,logo:'resources/images/loading.gif'};
            if (process.env.BOOT_AUTH === 'legacy') response.license = 'test-license';
            else if (process.env.BOOT_AUTH !== 'missing') response.hash = 'test-hash';
            return res.end(JSON.stringify(response));
        }
        return res.end('{"success":false}');
    }
    if (pathname.startsWith('index.php/')) {
        res.setHeader('Content-Type', 'application/json');
        if (pathname === 'index.php/statusSystem/read') return res.end(JSON.stringify({success:true, count:1, rows:[{id:1,cpuPercent:0,cpuMediaUso:0,memUsed:0,memTotal:1,disk_free:1,disk_perc:0,uptime:'Test',totalActiveUsers:1,monthprofit:0,maximumcc:0,monthRefill:0}]}));
        return res.end('{"success":true,"rows":[],"count":0}');
    }
    try {
        const relative = pathname || 'index.html';
        if (process.env.BOOT_FAIL_SCRIPT === relative) throw new Error('Simulated missing dependency');
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
        if (process.env.BOOT_SOURCE && relative.endsWith('.json') && !relative.includes('/')) {
            const manifest = JSON.parse(data);
            manifest.js = [{path:'ext/build/ext-all.js'}, {path:'classic/src/Application.js'}, {path:'app.js'}];
            res.end(JSON.stringify(manifest));
        } else if (process.env.BOOT_DESKTOP && relative === 'icons.js') {
            res.end(data.toString() + '\nwindow.isDesktop = true;');
        } else if (process.env.BOOT_CAPTURE && relative === 'ext/build/ext-all.js') {
            res.end(data.toString() + `\nwindow.__bootDefinitions = []; var originalDefine = Ext.define; Ext.define = function(name, data) { window.__bootDefinitions.push({name:name, path:document.currentScript && new URL(document.currentScript.src).pathname.replace('/mbilling/', ''), override:data && data.override}); return originalDefine.apply(this, arguments); };`);
        } else res.end(data);
    } catch {
        res.writeHead(404).end('Not found');
    }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
let browser;
try {
    browser = await chromium.launch({ headless: true, ...(process.env.CHROME_BINARY ? { executablePath: process.env.CHROME_BINARY } : {}) });
    const page = await browser.newPage();
    if (process.env.BOOT_LANGUAGE) await page.addInitScript(lang => localStorage.setItem('lang', lang), process.env.BOOT_LANGUAGE);
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
    let observedResult = false;
    try {
        await page.waitForFunction(({auth, failure}) => failure ? document.querySelector('#loading-msg[role=alert]')?.textContent.includes(failure) : window.Ext && Ext.ComponentQuery && (auth ? (window.App?.mainView?.rendered || window.App?.mainView?.viewport?.rendered) : Ext.ComponentQuery.query('login').some(component => component.rendered)) && !document.getElementById('loading'), {auth:!!process.env.BOOT_AUTH, failure:expectedFailure}, { timeout: liveUrl ? 120000 : 30000 });
        observedResult = true;
    } catch { errors.push('Expected boot result did not appear before the timeout'); }
    const state = await page.evaluate(() => ({ application: !!window.App, loading: !!document.getElementById('loading'), login: !!(window.Ext && Ext.ComponentQuery && Ext.ComponentQuery.query('login').length), main: window.App?.mainView?.xtype, isDesktop: window.isDesktop, isTablet: window.isTablet, scripts: document.scripts.length, theme: window.theme, loader: window.Ext?.Loader ? { missingQueue: Ext.Loader.missingQueue, isLoading: Ext.Loader.isLoading } : null }));
    if (process.env.BOOT_DESKTOP && process.env.BOOT_AUTH && state.main !== 'maindesktop') errors.push('Desktop workspace did not render');
    console.log(JSON.stringify({ theme, elapsedMs: Date.now() - startedAt, missing: [...missing], errors, failedRequests, pending: [...pending], state }, null, 2));
    if (process.env.BOOT_CAPTURE) {
        await writeFile(process.env.BOOT_CAPTURE, JSON.stringify(await page.evaluate(() => ({ definitions: window.__bootDefinitions, history: Ext.Loader.history })), null, 2));
    }
    // Missing decorative images should be reported without failing a successful boot.
    const missingDependencies = [...missing].filter(path => /\.(?:js|json|css)$/.test(path));
    process.exitCode = expectedFailure ? (observedResult ? 0 : 1) : (missingDependencies.length || errors.length || failedRequests.length ? 1 : 0);
} finally {
    await browser?.close();
    await new Promise(resolve => server.close(resolve));
}
