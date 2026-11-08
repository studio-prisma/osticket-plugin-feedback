// @ts-check
// 0.2: who decides – the agent's tick, the ticket menu, the API flag, mass changes.
const { test, expect } = require('@playwright/test');
const { seed, T, sql, php, q, newTicket, reply, setStatus, row, notes, setting, clearMail, feedbackMails, resetSettings,
  staffLogin, watchErrors } = require('./support');

test.beforeEach(async ({ request }) => {
  resetSettings();
  await clearMail(request);
});

/** Status change with a request context: agent (seed key or null), $_POST['feedback_ask'], PATH_INFO. */
function closeWith(id, who, ask, pathInfo = '') {
  const as = who ? `global $thisstaff; $thisstaff = Staff::lookup(${seed.agents[who]});` : '';
  const post = ask === null ? '' : `$_POST['feedback_ask'] = ${q(ask)};`;
  const path = pathInfo ? `$_SERVER['PATH_INFO'] = ${q(pathInfo)};` : '';
  return php(`${as} ${post} ${path} $t = Ticket::lookup(${id}); $e = array();`
    + ` echo $t->setStatus(${seed.status.closed}, '', $e) ? 'ok' : json_encode($e);`);
}

const feedbackNotes = (id) => notes(id).filter((n) => n.title === 'Zufriedenheitsfrage');

test('web: tick removed → no mail, declined for good, with a note', async ({ request }) => {
  const id = newTicket('0.2 web decline');
  reply(id, 'hanna', 'Erledigt.');
  expect(closeWith(id, 'hanna', '0')).toBe('ok');
  expect(await feedbackMails(request, id)).toHaveLength(0);
  expect(row(id).state).toBe('declined');
  expect(feedbackNotes(id).at(-1).body).toContain('beim Schließen abgewählt');

  setStatus(id, 'hanna', seed.status.open);
  expect(closeWith(id, 'hanna', null)).toBe('ok');
  expect(await feedbackMails(request, id)).toHaveLength(0);
});

test('web: tick on → asks, the note names the source', async ({ request }) => {
  const id = newTicket('0.2 web ask');
  reply(id, 'hanna', 'Erledigt.');
  expect(closeWith(id, 'hanna', '1')).toBe('ok');
  expect(await feedbackMails(request, id)).toHaveLength(1);
  await expect.poll(() => feedbackNotes(id).length).toBe(1);
  expect(feedbackNotes(id)[0].body).toContain('Ausgelöst durch: Schließen im Agentenbereich');
});

test('API with "requested": nothing without the flag, asks with 1, declines with 0', async ({ request }) => {
  setting('no_session', 'requested');
  const a = newTicket('0.2 api none');
  reply(a, 'hanna', 'Erledigt.');
  expect(closeWith(a, null, null)).toBe('ok');
  expect(await feedbackMails(request, a)).toHaveLength(0);
  expect(row(a)).toBeNull();

  const b = newTicket('0.2 api ask');
  reply(b, 'hanna', 'Erledigt.');
  expect(closeWith(b, null, '1')).toBe('ok');
  expect(await feedbackMails(request, b)).toHaveLength(1);
  await expect.poll(() => feedbackNotes(b).length).toBe(1);
  expect(feedbackNotes(b)[0].body).toContain('API oder Cron');

  const c = newTicket('0.2 api decline');
  reply(c, 'hanna', 'Erledigt.');
  expect(closeWith(c, null, '0')).toBe('ok');
  expect(row(c).state).toBe('declined');
  expect(feedbackNotes(c).at(-1).body).toContain('(API oder Cron)');
});

test('mass status change with "requested": no question', async ({ request }) => {
  setting('no_session', 'requested');
  const id = newTicket('0.2 mass');
  reply(id, 'hanna', 'Erledigt.');
  expect(closeWith(id, 'hanna', null, '/tickets/status/closed')).toBe('ok');
  expect(await feedbackMails(request, id)).toHaveLength(0);
  expect(row(id)).toBeNull();
});

test('a ticket that would not be asked gets no decline row', async () => {
  const id = newTicket('0.2 alert decline', { dept: seed.depts.alerts });
  reply(id, 'admin', 'Gesehen.');
  expect(closeWith(id, 'admin', '0')).toBe('ok');
  expect(row(id)).toBeNull();
  expect(feedbackNotes(id)).toHaveLength(0);
});

test('UI: the tick appears for a closing status, the menu switches the question off and on', async ({ page, request }) => {
  const errors = watchErrors(page);
  const id = newTicket('0.2 ui');
  reply(id, 'admin', 'Antwort.');
  await staffLogin(page, 'admin');
  await page.goto(`/scp/tickets.php?id=${id}`);

  // Reply form: hidden until a closed status is chosen.
  await page.locator('a#post-reply, a[href="#reply"]').first().click().catch(() => {});
  const tick = page.locator('form#reply .spf-ask');
  await expect(tick).toHaveCount(1);
  await page.selectOption('form#reply select[name=reply_status_id]', String(seed.status.closed));
  await expect(tick).toBeVisible();
  await expect(tick.locator('input[type=checkbox]')).toBeChecked();
  await page.selectOption('form#reply select[name=reply_status_id]', String(seed.status.open));
  await expect(tick).toBeHidden();

  // More menu → "No satisfaction question" (real POST with the panel's CSRF header).
  const off = page.locator('#action-dropdown-more li.spf-menu a');
  await expect(off).toHaveCount(1);
  await expect(off).toContainText('Keine Zufriedenheitsfrage');
  await Promise.all([
    page.waitForEvent('load'),
    page.evaluate(() => { document.querySelector('#action-dropdown-more li.spf-menu a').click(); }),
  ]);
  await expect.poll(() => (row(id) || {}).state).toBe('declined');
  expect(notes(id).some((n) => n.body.includes('für dieses Ticket abgeschaltet'))).toBe(true);

  // After the reload: no tick, the menu offers to allow again.
  await page.goto(`/scp/tickets.php?id=${id}`);
  await expect(page.locator('form#reply .spf-ask')).toHaveCount(0);
  const on = page.locator('#action-dropdown-more li.spf-menu a');
  await expect(on).toContainText('wieder erlauben');
  await Promise.all([
    page.waitForEvent('load'),
    page.evaluate(() => { document.querySelector('#action-dropdown-more li.spf-menu a').click(); }),
  ]);
  await expect.poll(() => row(id)).toBeNull();
  expect(notes(id).some((n) => n.body.includes('wieder erlaubt'))).toBe(true);
  expect(errors).toEqual([]);
});

test('UI: the toggle endpoint refuses a request without the CSRF token', async ({ page }) => {
  const id = newTicket('0.2 csrf');
  reply(id, 'admin', 'Antwort.');
  await staffLogin(page, 'admin');
  const res = await page.request.post(`/scp/ajax.php/feedback/ticket/${id}/decline`);
  expect(res.status()).toBeGreaterThanOrEqual(400);
  expect(row(id)).toBeNull();
});

test('UI: status dialog carries the tick; removing it closes without a question', async ({ page, request }) => {
  const errors = watchErrors(page);
  const id = newTicket('0.2 ui dialog');
  reply(id, 'admin', 'Antwort.');
  await clearMail(request);
  await staffLogin(page, 'admin');
  await page.goto(`/scp/tickets.php?id=${id}`);
  await page.evaluate((href) => { document.querySelector(`a[href="${href}"]`).click(); },
    `#tickets/${id}/status/close/${seed.status.closed}`);
  const tick = page.locator('form#status .spf-ask');
  await expect(tick).toBeVisible();
  await tick.locator('input[type=checkbox]').uncheck();
  await page.locator('form#status [type=submit]').first().click();
  await expect.poll(() => (row(id) || {}).state).toBe('declined');
  expect(await feedbackMails(request, id)).toHaveLength(0);
  expect(errors).toEqual([]);
});

test('UI: no tick and no menu entry where the rules never ask (Alerts)', async ({ page }) => {
  const id = newTicket('0.2 ui alert', { dept: seed.depts.alerts });
  reply(id, 'admin', 'Gesehen.');
  await staffLogin(page, 'admin');
  await page.goto(`/scp/tickets.php?id=${id}`);
  await expect(page.locator('#reply')).toHaveCount(1);
  await expect(page.locator('.spf-ask')).toHaveCount(0);
  await expect(page.locator('#action-dropdown-more li.spf-menu')).toHaveCount(0);
});
