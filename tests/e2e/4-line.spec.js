// @ts-check
// AC-7: "line" mode. No own mail; the question is under the reply that
// closes the ticket and nowhere else. The stored thread stays untouched.
const { test, expect } = require('@playwright/test');
const {
  seed, newTicket, reply, close, row, number, state, storedReplies, clearMail, mails, feedbackMails, links, setting,
  resetSettings, staffLogin, watchErrors,
} = require('./support');

const replyMails = async (request, id) => (await mails(request, id)).filter((m) => m.to.includes('alice@acme.example')
  && !/Kurze Frage/.test(m.subject));

test.beforeEach(async ({ request }) => {
  resetSettings();
  setting('mode', 'line');
  await clearMail(request);
});

test('AC-7: the closing reply carries the line, no own mail', async ({ page, request }) => {
  const id = newTicket('AC-7 closing reply');
  expect(reply(id, 'hanna', 'Normale Zwischenantwort.')).toBe('ok');
  expect(reply(id, 'hanna', 'Erledigt, das Ticket ist zu.', seed.status.closed)).toBe('ok');
  expect(await feedbackMails(request, id)).toHaveLength(0);
  const sent = await replyMails(request, id);
  expect(sent).toHaveLength(2);
  const [first, last] = sent;
  expect(first.html).toContain('Normale Zwischenantwort');
  expect(Object.keys(links(first.html))).toHaveLength(0);
  expect(last.html).toContain('Erledigt, das Ticket ist zu.');
  expect(last.html).toContain('Ticket erledigt. Wie zufrieden bist du mit der Lösung?');
  const l = links(last.html);
  expect(Object.keys(l).sort()).toEqual(['0', '1']);
  expect(row(id)).toMatchObject({ state: 'sent', mode: 'line' });
  // Only the mail has it; the thread stays as the agent wrote it.
  for (const body of storedReplies(id)) expect(body).not.toContain('feedback');
  // And the link works.
  await page.goto(`${l[1]}?v=1`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  expect(row(id).value).toBe(1);
});

test('AC-7: closing without a reply asks nobody', async ({ request }) => {
  const id = newTicket('AC-7 close only');
  reply(id, 'hanna', 'Antwort.');
  close(id);
  expect(await feedbackMails(request, id)).toHaveLength(0);
  expect(row(id)).toBeNull();
});

test('AC-7: a closing reply by an AI agent gets no line', async ({ request }) => {
  const id = newTicket('AC-7 AI closes');
  reply(id, 'hanna', 'Antwort.');
  expect(reply(id, 'bot', 'Automatisch erledigt.', seed.status.closed)).toBe('ok');
  for (const m of await replyMails(request, id)) expect(Object.keys(links(m.html))).toHaveLength(0);
  expect(row(id)).toBeNull();
});

test('AC-7: only once per ticket, also in line mode', async ({ request }) => {
  const id = newTicket('AC-7 twice');
  reply(id, 'hanna', 'Zu.', seed.status.closed);
  reply(id, 'hanna', 'Wieder offen.', seed.status.open);
  reply(id, 'hanna', 'Wieder zu.', seed.status.closed);
  const withLine = (await replyMails(request, id)).filter((m) => Object.keys(links(m.html)).length > 0);
  expect(withLine).toHaveLength(1);
});

test('AC-7: in the agent panel, "Post Reply" with status Closed', async ({ page, request }) => {
  const errors = watchErrors(page);
  const id = newTicket('AC-7 agent panel');
  reply(id, 'hanna', 'Erste Antwort.');
  await clearMail(request);
  await staffLogin(page, 'hanna');
  await page.goto(`/scp/tickets.php?id=${id}#reply`);
  await page.click('#post-reply-tab');
  const editor = page.locator('#reply .redactor-in');
  await editor.click();
  // Typed, not fill(): Redactor copies its content into the textarea on key events.
  await editor.pressSequentially('Gelöst über das Agentenpanel.');
  // The copy on key events is not reliable under load: set the field it submits as well.
  await page.evaluate(() => {
    const t = document.querySelector('#reply textarea[name="response"]');
    if (t && !t.value.includes('Agentenpanel')) t.value = '<p>Gelöst über das Agentenpanel.</p>';
  });
  await page.selectOption('#reply select[name="reply_status_id"]', String(seed.status.closed));
  await page.click('#reply input[type=submit][value="Post Reply"]');
  await expect.poll(() => state(id), { timeout: 15000 }).toBe('closed');
  const sent = await replyMails(request, id);
  expect(sent).toHaveLength(1);
  expect(sent[0].html).toContain('Gelöst über das Agentenpanel.');
  expect(Object.keys(links(sent[0].html)).sort()).toEqual(['0', '1']);
  for (const body of storedReplies(id)) expect(body).not.toContain('feedback');
  expect(errors.filter((e) => !/favicon/.test(e))).toEqual([]);
  expect(number(id)).not.toBe('');
});
