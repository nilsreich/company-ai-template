import { test, expect, chromium } from '@playwright/test';

// Optional browser check against a local server with TELESCOPE_ENABLED=true.
// Start it first, then run: npx playwright test telescope
// (see docs/packages.md). The temporary server is stopped afterwards.
const base = process.env.TELESCOPE_BASE_URL || 'http://127.0.0.1:8082';

test('Telescope admin dashboard, JavaScript and authenticated requests API', async () => {
  const browser = await chromium.launch(
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
      ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH, args: ['--no-sandbox'] }
      : {},
  );
  try {
    const page = await browser.newPage();
    const errors: string[] = [];
    const api: number[] = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => {
      if (response.url().includes('/telescope-api/requests')) api.push(response.status());
    });
    await page.goto(`${base}/login`);
    await page.getByRole('button', { name: 'Als admin anmelden' }).click();
    await expect(page).toHaveURL(/\/admin$/);
    expect((await page.goto(`${base}/telescope`)).status()).toBe(200);
    await page.getByRole('link', { name: 'Requests', exact: true }).click();
    await expect.poll(() => api).toContain(200);
    await expect(page.locator('#telescope')).not.toHaveAttribute('v-cloak');
    expect(errors).toEqual([]);
  } finally {
    await browser.close();
  }
});

test('Telescope editor denied', async () => {
  const browser = await chromium.launch(
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
      ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH, args: ['--no-sandbox'] }
      : {},
  );
  try {
    const editor = await browser.newPage();
    await editor.goto(`${base}/login`);
    await editor.getByRole('button', { name: 'Als editor anmelden' }).click();
    await expect(editor).toHaveURL(/\/admin$/);
    expect((await editor.goto(`${base}/telescope`)).status()).toBe(403);
  } finally {
    await browser.close();
  }
});
