// Local browser verification. LIVE_GITHUB_FEEDBACK_TEST=1 creates one marked issue.
// A loopback-only proxy supplies the secure localhost context for getDisplayMedia.
const http = require('node:http');
const assert = require('node:assert/strict');
const { chromium } = require('@playwright/test');

(async () => {
    const upstream = new URL(process.env.E2E_BASE_URL || 'http://192.168.178.200:8080');
    assert.equal(upstream.protocol, 'http:');
    const live = process.env.LIVE_GITHUB_FEEDBACK_TEST === '1';
    const proxy = http.createServer((request, response) => {
        const outgoing = http.request({ hostname: upstream.hostname, port: upstream.port || 80, path: request.url, method: request.method, headers: request.headers }, (reply) => {
            response.writeHead(reply.statusCode, reply.headers);
            reply.pipe(response);
        });
        outgoing.on('error', () => { response.writeHead(502); response.end(); });
        request.pipe(outgoing);
    });
    await new Promise((resolve) => proxy.listen(0, '127.0.0.1', resolve));
    const base = `http://localhost:${proxy.address().port}`;
    const browser = await chromium.launch({
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || '/usr/bin/chromium-browser',
        headless: true, args: ['--no-sandbox', '--auto-accept-this-tab-capture'],
    });
    try {
        const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        const errors = [];
        const requests = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('request', (request) => requests.push(request.url()));
        // Observe the actual native API and returned tracks without replacing capture.
        await page.addInitScript(() => {
            if (!navigator.mediaDevices?.getDisplayMedia) return;
            const nativeCapture = navigator.mediaDevices.getDisplayMedia.bind(navigator.mediaDevices);
            navigator.mediaDevices.getDisplayMedia = async (options) => {
                let stream;
                try { stream = await nativeCapture(options); } catch (error) { window.feedbackCaptureError = { name: error.name, message: error.message }; throw error; }
                window.feedbackCaptureTracks = stream.getTracks();
                return stream;
            };
        });
        await page.goto(`${base}/login`);
        assert.equal(await page.locator('#app-feedback').count(), 0);
        await page.getByRole('button', { name: 'Als editor anmelden', exact: true }).click();
        await page.waitForURL('**/admin');
        await page.getByRole('button', { name: 'Feedback geben', exact: true }).click();
        assert.equal(await page.evaluate(() => isSecureContext), true);
        await page.getByRole('button', { name: 'Screenshot aufnehmen', exact: true }).click();
        try {
            await page.locator('#feedback-preview').waitFor({ state: 'visible', timeout: 20000 });
        } catch (error) {
            console.log(await page.evaluate(() => ({ status: document.getElementById('feedback-status').textContent, error: window.feedbackCaptureError, tracks: window.feedbackCaptureTracks?.map(track => track.readyState) })));
            throw error;
        }
        assert.equal(await page.evaluate(() => window.feedbackCaptureTracks.every((track) => track.readyState === 'ended')), true);
        const canvas = page.locator('#feedback-canvas');
        await canvas.scrollIntoViewIfNeeded();
        const box = await canvas.boundingBox();
        await page.mouse.move(box.x + box.width * 0.1, box.y + box.height * 0.1);
        await page.mouse.down();
        await page.mouse.move(box.x + box.width * 0.3, box.y + box.height * 0.3, { steps: 6 });
        await page.mouse.up();
        assert.deepEqual(await canvas.evaluate((element) => Array.from(element.getContext('2d').getImageData(element.width * 0.2, element.height * 0.2, 1, 1).data)), [0, 0, 0, 255]);
        const capturedPng = await canvas.evaluate((element) => element.toDataURL('image/png').split(',')[1]);
        assert.equal(requests.some((url) => /marker\.io/.test(url)), false);
        assert.equal(requests.some((url) => url === `${base}/feedback`), false);
        const title = `[Integrationstest] Internes Feedback ${new Date().toISOString()}`;
        await page.getByLabel('Kurztitel', { exact: true }).fill(title);
        await page.getByLabel('Beschreibung', { exact: true }).fill('Automatisierter Funktionstest mit dem lokalen Demo-Konto. Native Bildschirmaufnahme, Schwärzung und interne Speicherung geprüft. Keine Kundendaten. Dieses Test-Issue kann geschlossen werden.');
        await page.getByRole('checkbox').check();
        if (!live) {
            await page.route('**/feedback', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: 'browser-fixture', status: 'sent', issue_url: 'https://github.com/example/feedback/issues/1' }) }));
        }
        const resultPromise = page.waitForResponse((response) => response.url() === `${base}/feedback` && response.request().method() === 'POST');
        await page.getByRole('button', { name: 'Feedback absenden', exact: true }).click();
        const response = await resultPromise;
        const result = await response.json();
        assert.equal(response.status(), 200);
        assert.equal(result.status, 'sent', JSON.stringify(result));
        await page.getByRole('link', { name: 'Issue auf GitHub öffnen ↗' }).waitFor();
        if (live) {
            const editorDownload = await page.request.get(`${base}/feedback/${result.id}/screenshot`);
            assert.equal(editorDownload.status(), 403);
            const guestContext = await browser.newContext();
            const guestDownload = await guestContext.request.get(`${base}/feedback/${result.id}/screenshot`, { maxRedirects: 0 });
            assert.equal(guestDownload.status(), 302);
            const adminPage = await guestContext.newPage();
            await adminPage.goto(`${base}/login`);
            await adminPage.getByRole('button', { name: 'Als admin anmelden', exact: true }).click();
            await adminPage.waitForURL('**/admin');
            const adminDownload = await guestContext.request.get(`${base}/feedback/${result.id}/screenshot`);
            assert.equal(adminDownload.status(), 200);
            assert.deepEqual(await adminDownload.body(), Buffer.from(capturedPng, 'base64'));
            assert.ok(adminDownload.headers()['cache-control'].includes('no-store'));
            await guestContext.close();
        }
        await page.getByRole('button', { name: 'Feedback schließen' }).click();
        await page.getByRole('button', { name: 'Feedback geben', exact: true }).click();
        assert.equal(await page.getByLabel('Kurztitel', { exact: true }).inputValue(), '');
        assert.equal(await page.getByRole('button', { name: 'Feedback absenden', exact: true }).isEnabled(), true);
        await page.getByRole('button', { name: 'Feedback schließen' }).click();
        await page.setViewportSize({ width: 390, height: 844 });
        await page.getByRole('button', { name: 'Feedback geben', exact: true }).click();
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.deepEqual(errors, []);
        console.log(JSON.stringify({ live, nativeCapture: true, tracksStopped: true, redaction: true, screenshotKeptInternal: live, mobile: true, result }, null, 2));
    } finally {
        await browser.close();
        await new Promise((resolve) => proxy.close(resolve));
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
