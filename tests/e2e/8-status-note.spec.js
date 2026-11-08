// @ts-check
// 0.1.1: closing statuses that never ask, and the note after a sent question.
const { test, expect } = require('@playwright/test');
const { seed, newTicket, reply, close, setStatus, row, notes, setting, clearMail, feedbackMails, links, resetSettings }
  = require('./support');

test.beforeEach(async ({ request }) => {
  resetSettings();
  await clearMail(request);
});

const sentNote = (id) => notes(id).find((n) => n.title === 'Zufriedenheitsfrage');

test('excluded closing status: no mail, no row, a later normal close still asks', async ({ request }) => {
  setting('exclude_statuses', JSON.stringify({ [seed.status.resolved]: 'Resolved' }));
  const id = newTicket('0.1.1 excluded status');
  reply(id, 'hanna', 'Dublette, bitte im anderen Ticket weiter.');
  expect(setStatus(id, 'hanna', seed.status.resolved)).toBe('ok');
  expect(await feedbackMails(request, id)).toHaveLength(0);
  expect(row(id)).toBeNull();
  expect(sentNote(id)).toBeUndefined();

  setStatus(id, 'hanna', seed.status.open);
  expect(close(id)).toBe('ok');
  expect(await feedbackMails(request, id)).toHaveLength(1);
});

test('short note (default): recipient, scale and validity, no links', async ({ request }) => {
  const id = newTicket('0.1.1 short note');
  reply(id, 'hanna', 'Erledigt.');
  close(id);
  expect(await feedbackMails(request, id)).toHaveLength(1);
  await expect.poll(() => sentNote(id)).toBeDefined();
  const n = sentNote(id);
  expect(n.body).toContain('Zufriedenheitsfrage per Mail gesendet an alice@acme.example');
  expect(n.body).toContain('Skala: Daumen');
  expect(n.body).not.toContain('/ajax.php/feedback/');
});

test('full note: the mail as sent, with working links and the button words', async ({ request }) => {
  setting('note_sent', 'full');
  const id = newTicket('0.1.1 full note');
  reply(id, 'hanna', 'Erledigt.');
  close(id);
  const fb = await feedbackMails(request, id);
  expect(fb).toHaveLength(1);
  await expect.poll(() => sentNote(id)).toBeDefined();
  const n = sentNote(id);
  expect(n.body).toContain('Gesendete Mail');
  expect(n.body).toContain('Nicht gut');
  // The same links as in the mail.
  const sent = links(fb[0].html);
  expect(n.body).toContain(sent[0]);
  expect(n.body).toContain(sent[1]);
});

test('note switched off: nothing at the ticket', async ({ request }) => {
  setting('note_sent', 'off');
  const id = newTicket('0.1.1 no note');
  reply(id, 'hanna', 'Erledigt.');
  close(id);
  expect(await feedbackMails(request, id)).toHaveLength(1);
  expect(sentNote(id)).toBeUndefined();
});
