# Frontend stuck on Starting...

## Follow-up: authenticated-session regression and bundled boot

The first check covered only a logged-out browser. A successful public login
screen did not establish that a browser with an existing session could start.
The follow-up reproduced `Cannot read properties of undefined (reading 'slice')`
with an authenticated response matching `AuthenticationController::actionCheck`.
That endpoint returns `hash`, but the restored application read `license` and
assigned `undefined` to `App.user.l`. The news window and main view then called
`App.user.l.slice(...)` before removing the loading screen.

Both application entry classes now read `hash`, accept the older `license`
field for compatibility, and always provide a string. The regression check
includes current, legacy, and absent hash fields with a simulated existing
session. Backend responses remain mocked; this is not a test of production
credentials or database contents.

The default manifests now load `ext/build/ext-all.js`, a generated
`resources/mbilling-bundle.js`, and `app.js`. The bundle contains 732 source
files in dependency order. Both application assets receive a content-derived
version in their URLs. Local browser checks went from 748 to 18 script elements;
this removes the source-request waterfall without requiring Sencha Cmd on a
server. It is an unminified concatenation, not a replacement implementation of
Sencha Cmd, and retains source license notices. Total transfer size still matters.

`index.html` reports same-origin script failures, invalid authentication JSON,
HTTP failures, and a startup timeout visibly instead of leaving only
"Starting...". Errors use text output and do not render raw PHP responses as HTML.
The German ExtJS locale now uses the existing framework locale file instead of
requesting a nonexistent file under `resources/locale`.

Rebuild and verify generated assets after editing a bundled source:

```sh
node script/build-frontend.mjs
node script/build-frontend.mjs --check
node script/test-frontend-boot.mjs
```

The matrix checks 27 manifests, eight languages, authenticated response variants,
desktop and source loading, malformed JSON, HTTP 500, and a missing script.
It verifies initialization, not theme appearance, real database queries,
credentials, or telephone calls. Decorative image failures remain separately
reported.

When dependencies change, refresh the inventory from a successful source-mode
browser check. In a POSIX shell, with Playwright configured as below:

```sh
BOOT_SOURCE=1 BOOT_CAPTURE=.tmp-boot-capture.json node script/check-frontend-boot.mjs
node script/build-frontend.mjs --capture=.tmp-boot-capture.json
node script/test-frontend-boot.mjs
```

Use `BOOT_AUTH=hash`, `BOOT_AUTH=legacy`, or `BOOT_AUTH=missing` for a specific
simulated authenticated check. Use `BOOT_SOURCE=1` to compare direct source
loading with the default bundle. These are test-runner environment variables,
not production authentication options.

Deploy all changed files together, including the bundle and theme manifests.
Inspect the server's current branch and uncommitted files before updating; do
not reset a customized installation. The default updater still uses `main`, so
a development checkout requires an intentional deployment rather than running
that updater. After deployment, reload with cache disabled and inspect the new
bundle request and any visible `BOOT_*` error. Without server access, repository
verification cannot establish that a running installation has received the fix.

## Findings from the 2026-10-28 incident

The reported blocking requests were these six source files:

```text
classic/src/ux/desktop/App.js
classic/src/ux/layout/ResponsiveColumn.js
classic/src/ux/form/SearchField.js
ext/packages/charts/src/draw/ContainerBase.js
ext/packages/charts/src/chart/legend/LegendBase.js
ext/packages/charts/src/draw/SurfaceBase.js
```

Commit `24e83751` configured theme manifests and `app.js` to load the application
from source instead of Sencha Cmd bundles. Its loader paths expected UX classes
under `classic/src/ux` and chart bases under the shared charts source directory.
The six files above were absent there until `46eb149d` added them. A dependency
404 can prevent application initialization and leave the loading screen visible.
Commit `9b042ab6` separately fixed the case of the `plan` view directory for Linux.

At verification, the public deployment at `http://cm5.emat.solutions/mbilling/`
returned HTTP 200 for all six files. A fresh Chrome session without extensions
reached the logged-out login screen, removed the loading element, and had no
JavaScript exceptions or pending Ext.Loader classes. It contained 748 script
elements. An initial 30-second check timed out; a subsequent check allowing up
to 120 seconds succeeded. Source loading still creates substantial network
overhead. This does not establish the behavior after authentication, database
health, or telephony health.

Local checks also reached login with `black-neptune`, `black-crisp`, and
`black-triton`. Missing decorative images, including
`resources/images/lock-screen-background.jpg`, were observed without preventing
login. They are distinct from missing JavaScript dependencies.

Errors mentioning `chrome-extension://`, injected content scripts, and extension
message channels should be checked in a fresh browser profile with extensions
disabled. A page-level polyfill cannot reliably fix an extension's isolated
JavaScript context. The reported `unload` permissions warning did not prevent
login in the clean-browser check.

## Repeatable check

Requires Node.js 22.16+ and Playwright with an available Chromium installation.
With Playwright available to Node:

```sh
node script/check-frontend-boot.mjs black-neptune
node script/check-frontend-boot.mjs black-crisp
node script/check-frontend-boot.mjs black-triton
```

For a separately installed Playwright, set `PLAYWRIGHT_MODULE` to its module
specifier or `file:///.../index.mjs`. Set `CHROME_BINARY` to an existing Chrome
executable if needed. To check a deployed public login, set `BOOT_URL` to its
full URL and run the same script. Live mode uses a fresh, unauthenticated browser;
it does not submit credentials. Requests to other origins are blocked.

Local mode serves repository assets and mocks only the initial PHP environment
and logged-out authentication response. Filesystem checks enforce exact casing
even on Windows. Success requires a rendered login and removal of the loading
screen. Missing JS/JSON/CSS, JavaScript errors, request failures, or a timeout
produce a nonzero exit status. Missing decorative images are reported separately
and do not fail an otherwise successful boot.

## Deployment checks

On the server, inspect `git branch --show-current`, `git rev-parse HEAD`, and
`git status --short` before changing anything. Confirm all six paths exist and
are publicly served as JavaScript. Inspect the Network panel with cache disabled
and reload in a clean browser profile.

At this review, `protected/commands/update.sh` fetched and reset to `origin/main`,
while these fixes were only in `development` in the local remote-tracking refs.
Updating through that script can therefore discard a manually installed
development fix until a tested release is merged into `main`. Do not use the
updater to repair this incident without checking the release commit first.

If the same six requests still return 404, verify deployment completeness,
document root, path casing, and the running revision before changing the loader
again. If the requests succeed but initialization remains slow, measure the
source-request waterfall. A production Sencha bundle should be evaluated and
tested as a separate packaging change.
