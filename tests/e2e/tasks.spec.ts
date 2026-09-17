import { test, expect, Page } from '@playwright/test';

async function login(page: Page, role: string) {
  await page.goto('/login');
  await page.getByRole('button', { name: `Als ${role} anmelden` }).click();
  await expect(page).toHaveURL(/\/admin$/);
}

test('Upload, echter Queue-Worker, Korrektur, Freigabe und JSON', async ({ browser, page }) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await login(page, 'editor');
  await page.goto('/admin/tasks/create');
  await page.locator('input[type=file]').setInputFiles({ name: 'browser-task.txt', mimeType: 'text/plain', buffer: Buffer.from('Aufgabe: Browser-Test\n<script>window.documentInjected=true</script>') });
  await expect(page.locator('[data-filepond-item-state=processing-complete]')).toBeVisible();
  await page.getByRole('button', { name: 'Erstellen', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/tasks\/\d+$/);
  const detail = new URL(page.url()).pathname;
  const id = detail.split('/').pop();
  await expect(page.getByText('succeeded', { exact: true })).toBeVisible({ timeout: 35000 });
  await expect(page.locator('xpath=//*[@*[name()="wire:poll.3s"]]')).toHaveCount(0);
  expect(await page.evaluate(() => (window as any).documentInjected)).toBeUndefined();
  await expect(page.getByRole('button', { name: 'Freigeben', exact: true })).toHaveCount(0);
  expect((await page.request.get(`/tasks/${id}/export`)).status()).toBe(403);
  await page.getByRole('link', { name: 'Werte korrigieren' }).click();
  await page.getByRole('textbox', { name: /Titel/ }).fill('Browser korrigiert');
  await page.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(page.getByText('Gespeichert', { exact: true })).toBeVisible();
  const reviewer = await browser.newContext(); const reviewPage = await reviewer.newPage();
  await login(reviewPage, 'reviewer'); await reviewPage.goto(detail);
  await expect(reviewPage.getByText('Browser korrigiert', { exact: true })).toBeVisible();
  await reviewPage.getByRole('button', { name: 'Freigeben', exact: true }).click();
  await reviewPage.getByRole('button', { name: 'Bestätigen', exact: true }).click();
  await expect(reviewPage.getByText('approved', { exact: true })).toBeVisible();
  const downloadPromise = reviewPage.waitForEvent('download');
  await reviewPage.getByRole('link', { name: 'JSON exportieren' }).click();
  const download = await downloadPromise;
  const stream = await download.createReadStream(); const chunks: Buffer[] = [];
  for await (const chunk of stream!) chunks.push(Buffer.from(chunk));
  expect(Buffer.concat(chunks).toString('utf8')).toContain('Demo-Zusammenfassung');
  expect((await reviewPage.request.get(`${detail}/edit`)).status()).toBe(403);
  expect((await page.request.get(`/tasks/${id}/export`)).status()).toBe(403);
  expect((await page.request.get('/admin/users')).status()).toBe(403);
  await page.screenshot({ path: 'test-results/task-flow.png', fullPage: true });
  expect(errors).toEqual([]);
  await reviewer.close();
});

test('Private Aufgabenrouten verlangen Anmeldung', async ({ request, page }) => {
  for (const suffix of ['download', 'export', 'status']) {
    const response = await request.get(`/tasks/1/${suffix}`, { maxRedirects: 0 });
    expect(response.status()).toBe(302); expect(response.headers().location).toContain('/login');
  }
  await page.goto('/login'); await expect(page.getByRole('link', { name: 'Mit Microsoft anmelden' })).toBeVisible();
});
