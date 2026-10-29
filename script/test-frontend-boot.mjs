import { spawn } from 'node:child_process';
import { readdir } from 'node:fs/promises';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const scenarios = (await readdir(root)).filter(path => /^[a-z]+-[a-z]+\.json$/.test(path)).map(path => ({name:path.slice(0, -5), theme:path.slice(0, -5)}));
for (const language of ['en', 'es', 'pt_BR', 'fr', 'de', 'it', 'pl', 'ru']) scenarios.push({name:`language-${language}`, env:{BOOT_LANGUAGE:language}});
for (const auth of ['hash', 'legacy', 'missing']) scenarios.push({name:`session-${auth}`, env:{BOOT_AUTH:auth}});
scenarios.push(
    {name:'desktop-session', env:{BOOT_AUTH:'hash', BOOT_DESKTOP:'1'}},
    {name:'source-session', env:{BOOT_AUTH:'hash', BOOT_SOURCE:'1'}},
    {name:'invalid-auth-response', env:{BOOT_AUTH:'invalid', BOOT_EXPECT_FAILURE:'BOOT_AUTH_RESPONSE'}},
    {name:'auth-http500', env:{BOOT_AUTH:'http500', BOOT_EXPECT_FAILURE:'BOOT_AUTH_HTTP_500'}},
    {name:'missing-script', env:{BOOT_FAIL_SCRIPT:'ext/build/ext-all.js', BOOT_EXPECT_FAILURE:'BOOT_SCRIPT'}}
);
let failures = 0;
async function worker() {
    while (scenarios.length) {
        const scenario = scenarios.shift();
        const env = {...process.env};
        for (const key of Object.keys(env)) if (key.startsWith('BOOT_')) delete env[key];
        Object.assign(env, scenario.env);
        const result = await new Promise(resolveResult => {
            const child = spawn(process.execPath, ['script/check-frontend-boot.mjs', scenario.theme || 'black-neptune'], {cwd:root, env, windowsHide:true});
            let output = '';
            child.stdout.on('data', chunk => output += chunk);
            child.stderr.on('data', chunk => output += chunk);
            child.on('error', error => resolveResult({code:1, output:String(error)}));
            child.on('close', code => resolveResult({code, output}));
        });
        console.log(`${result.code === 0 ? 'PASS' : 'FAIL'} ${scenario.name}`);
        if (result.code !== 0) { failures++; console.log(result.output); }
    }
}
await Promise.all([worker(), worker()]);
console.log(`Frontend boot matrix: ${failures} failures`);
process.exitCode = failures ? 1 : 0;
