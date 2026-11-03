// @ts-check
// Evaluation page and the one-line summary for a weekly digest.
const { test, expect } = require('@playwright/test');
const { newTicket, reply, close, clearMail, feedbackMails, links, resetSettings, setting, staffLogin, php, sql, watchErrors } = require('./support');

test.beforeEach(async ({ request }) => {
  resetSettings();
  setting('comment_on', '0');
  await clearMail(request);
});

test('admin: counts per week and month, latest comments', async ({ page, request }) => {
  const errors = watchErrors(page);
  const id = newTicket('ADMIN counted');
  reply(id, 'hanna', 'Antwort.');
  close(id);
  const link = links((await feedbackMails(request, id))[0].html);
  setting('comment_on', '1');
  await page.goto(`${link[1]}?v=1`);
  await page.fill('#spf-text', 'Kommentar für die Auswertung');
  await page.click('.spf-comment button[type=submit]');

  await staffLogin(page, 'admin');
  // The entry sits in the Applications submenu (shown on hover).
  await page.goto('/scp/index.php');
  const entry = page.locator('a[href$="/scp/ajax.php/feedback/admin"]').first();
  await expect(entry).toHaveCount(1);
  await page.goto(await entry.getAttribute('href'));
  await expect(page.getByRole('heading', { name: 'Feedback: Auswertung' })).toBeVisible();
  await expect(page.locator('body')).toContainText('Status: an');
  const week = page.locator('table.list').first();
  await expect(week.locator('tbody tr').first()).toContainText('Daumen');
  await expect(page.locator('body')).toContainText('Kommentar für die Auswertung');
  const number = sql(`SELECT number FROM ost_ticket WHERE ticket_id=${id}`);
  await expect(page.locator(`a[href="tickets.php?id=${id}"]`)).toHaveText(number);
  expect(errors.filter((e) => !/favicon/.test(e))).toEqual([]);
});

test('admin: agents without admin rights see a note only', async ({ page }) => {
  await staffLogin(page, 'hanna');
  await page.goto('/scp/ajax.php/feedback/admin');
  await expect(page.locator('body')).toContainText('Die Auswertung sehen Administratoren.');
  await expect(page.locator('table.list')).toHaveCount(0);
});

test('admin: nothing without a login', async ({ request }) => {
  const res = await request.get('/scp/ajax.php/feedback/admin', { maxRedirects: 0 });
  expect(res.status()).not.toBe(200);
  expect(await res.text()).not.toContain('Auswertung');
});

test('digest: the signal returns one line', () => {
  const out = php("$lines = array(); Signal::send('feedback.digest', null, $lines); echo json_encode($lines);");
  const lines = JSON.parse(out);
  expect(lines).toHaveLength(1);
  expect(lines[0]).toMatch(/^Feedback, 7 Tage: \d+ gefragt, \d+ bewertet, \d+ gut, \d+ schlecht/);
});

test('retention: requests older than 24 months are deleted in the cron', () => {
  const id = newTicket('ADMIN old');
  sql(`INSERT INTO ost_feedback_request (ticket_id, mode, scale, state, created) VALUES (${id}, 'mail', 'thumbs', 'sent', UNIX_TIMESTAMP() - 731*86400)`);
  sql("DELETE FROM ost_feedback_hit WHERE bucket LIKE 'once:%'");
  php("Signal::send('cron', null);");
  expect(sql(`SELECT COUNT(*) FROM ost_feedback_request WHERE ticket_id=${id}`)).toBe('0');
});
