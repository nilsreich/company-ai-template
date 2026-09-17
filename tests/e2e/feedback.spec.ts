import { test, expect, chromium } from '@playwright/test';
import http from 'node:http';

// Local browser verification. LIVE_GITHUB_FEEDBACK_TEST=1 creates one marked issue.
// A loopback-only proxy supplies the secure localhost context for getDisplayMedia.
// Run: npx playwright test feedback
const live = process.env.LIVE_GITHUB_FEEDBACK_TEST === '1';

test('Feedback capture, redaction and internal storage', async () => {
  const upstream = new URL(process.env.E2E_BASE_URL || 'http://192.168.178.200:8080');
  expect(upstream.protocol).toBe('http:');
  const proxy = http.createServer((request, response) => {
    const outgoing = http.request(
      {
        hostname: upstream.hostname,
        port: upstream.port ? Number(upstream.port) : 80,
        path: request.url,
        method: request.method,
        headers: request.headers as http.OutgoingHttpHeaders,
      },
      reply => {
        response.writeHead(reply.statusCode ?? 502, reply.headers);
        reply.pipe(response);
      },
    );
    outgoing.on('error', () => {
      response.writeHead(502);
      response.end();
    });
    request.pipe(outgoing);
  });
  await new Promise<void>(resolve => proxy.listen(0, '127.0.0.1', resolve));
  const base = `http://localhost:${(proxy.address() as { port: number }).port}`;
  const browser = await chromium.launch({
    executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || '/usr/bin/chromium-browser',
    headless: true,
    args: ['--no-sandbox', '--auto-accept-this-tab-capture'],
  });
  try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors: string[] = [];
    const requests: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => requests.push(request.url()));
    await page.goto(`${base}/login`);
    expect(await page.locator('#app-feedback').count()).toBe(0);
    await page.getByRole('button', { name: 'Als editor anmelden', exact: true }).click();
    await page.waitForURL('**/admin');
    await page.getByRole('button', { name: 'Feedback geben', exact: true }).click();
    expect(await page.evaluate(() => window.isSecureContext)).toBe(true);
    await page.getByRole('button', { name: 'Screenshot aufnehmen', exact: true }).click();
    try {
      await page.locator('#feedback-preview').waitFor({ state: 'visible', timeout: 20000 });
    } catch (error) {
      console.log(
        await page.evaluate(() => ({
          status: document.getElementById('feedback-status')?.textContent,
          error: (window as any).feedbackCaptureError,
          tracks: (window as any).feedbackCaptureTracks?.map((track: MediaStreamTrack) => track.readyState),
        })),
      );
      throw error;
    }
    expect(
      await page.evaluate(() => (window as any).feedbackCaptureTracks.every((track: MediaStreamTrack) => track.readyState === 'ended')),
    ).toBe(true);
    const canvas = page.locator('#feedback-canvas');
    await canvas.scrollIntoViewIfNeeded();
    const box = (await canvas.boundingBox())!;
    await page.mouse.move(box.x + box.width * 0.1, box.y + box.height * 0.1);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.3, box.y + box.height * 0.3, { steps: 6 });
    await page.mouse.up();
    expect(
      await canvas.evaluate(element =>
        Array.from((element as HTMLCanvasElement).getContext('2d')!.getImageData(element.width * 0.2, element.height * 0.2, 1, 1).data),
      ),
    ).toEqual([0, 0, 0, 255]);
    const capturedPng = await canvas.evaluate(element => (element as HTMLCanvasElement).toDataURL('image/png').split(',')[1]);
    expect(requests.some(url => /marker\.io/.test(url))).toBe(false);
    expect(requests.some(url => url === `${base}/feedback`)).toBe(false);
    const title = `[Integrationstest] Internes Feedback ${new Date().toISOString()}`;
    await page.getByLabel('Kurztitel', { exact: true }).fill(title);
    await page
      .getByLabel('Beschreibung', { exact: true })
      .fill(
        'Automatisierter Funktionstest mit dem lokalen Demo-Konto. Native Bildschirmaufnahme, Schwärzung und interne Speicherung geprüft. Keine Kundendaten. Dieses Test-Issue kann geschlossen werden.',
      );
    await page.getByRole('checkbox').check();
    if (!live) {
      await page.route('**/feedback', route =>
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ id: 'browser-fixture', status: 'sent', issue_url: 'https://github.com/example/feedback/issues/1' }),
        }),
      );
    }
    const resultPromise = page.waitForResponse(response => response.url() === `${base}/feedback` && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Feedback absenden', exact: true }).click();
    const response = await resultPromise;
    const result = await response.json();
    expect(response.status()).toBe(200);
    expect(result.status, JSON.stringify(result)).toBe('sent');
    await page.getByRole('link', { name: 'Issue auf GitHub öffnen ↗' }).waitFor();
    if (live) {
      const editorDownload = await page.request.get(`${base}/feedback/${result.id}/screenshot`);
      expect(editorDownload.status()).toBe(403);
      const guestContext = await browser.newContext();
      const guestDownload = await guestContext.request.get(`${base}/feedback/${result.id}/screenshot`, { maxRedirects: 0 });
      expect(guestDownload.status()).toBe(302);
      const adminPage = await guestContext.newPage();
      await adminPage.goto(`${base}/login`);
      await adminPage.getByRole('button', { name: 'Als admin anmelden', exact: true }).click();
      await adminPage.waitForURL('**/admin');
      const adminDownload = await guestContext.request.get(`${base}/feedback/${result.id}/screenshot`);
      expect(adminDownload.status()).toBe(200);
      expect(Buffer.from(await adminDownload.body())).toEqual(Buffer.from(capturedPng as string, 'base64'));
      expect(adminDownload.headers()['cache-control']).toContain('no-store');
      await guestContext.close();
    }
    await page.getByRole('button', { name: 'Feedback schließen' }).click();
    await page.getByRole('button', { name: 'Feedback geben', exact: true }).click();
    expect(await page.getByLabel('Kurztitel', { exact: true }).inputValue()).toBe('');
    expect(await page.getByRole('button', { name: 'Feedback absenden', exact: true }).isEnabled()).toBe(true);
    await page.getByRole('button', { name: 'Feedback schließen' }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: 'Feedback geben', exact: true }).click();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    expect(errors).toEqual([]);
    console.log(JSON.stringify({ live, nativeCapture: true, tracksStopped: true, redaction: true, screenshotKeptInternal: live, mobile: true, result }, null, 2));
  } finally {
    await browser.close();
    await new Promise<void>(resolve => proxy.close(() => resolve()));
  }
});
