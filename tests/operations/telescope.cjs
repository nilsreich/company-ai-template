// Optional browser check against a local server with TELESCOPE_ENABLED=true.
const { chromium, expect } = require('@playwright/test');

(async () => {
  const browser = await chromium.launch({
    ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
      ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH, args: ['--no-sandbox'] }
      : {}),
  });
  const base = process.env.TELESCOPE_BASE_URL || 'http://127.0.0.1:8082';
  try {
    const page = await browser.newPage();
    const errors = [];
    const api = [];
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
    console.log('PASS Telescope admin dashboard, JavaScript and authenticated requests API');

    const editor = await browser.newPage();
    await editor.goto(`${base}/login`);
    await editor.getByRole('button', { name: 'Als editor anmelden' }).click();
    await expect(editor).toHaveURL(/\/admin$/);
    expect((await editor.goto(`${base}/telescope`)).status()).toBe(403);
    console.log('PASS Telescope editor denied');
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
