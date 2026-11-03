// @ts-check
// AC-1, AC-2, AC-9: who gets the question, and only once.
const { test, expect } = require('@playwright/test');
const { seed, sql, newTicket, reply, close, setStatus, row, clearMail, feedbackMails, links, resetSettings, T } = require('./support');

test.beforeEach(async ({ request }) => {
  resetSettings();
  await clearMail(request);
});

test('AC-1: agent reply, then "closed": exactly one feedback mail with thumbs', async ({ request }) => {
  const id = newTicket('AC-1 reply then close');
  expect(reply(id, 'hanna', 'Erledigt, siehe Anleitung.')).toBe('ok');
  expect(close(id)).toBe('ok');
  const fb = await feedbackMails(request, id);
  expect(fb).toHaveLength(1);
  expect(fb[0].to).toEqual(['alice@acme.example']);
  expect(Object.keys(links(fb[0].html)).sort()).toEqual(['0', '1']);
  expect(fb[0].html).toContain('EXAMPLE SERVICE DESK');
  expect(fb[0].html).toContain('Gut');
  expect(fb[0].html).toContain('KI-Assistent');
  expect(row(id)).toMatchObject({ state: 'sent', mode: 'mail', scale: 'thumbs', value: null });
});

test('AC-1: closing reply (reply with status change) asks too', async ({ request }) => {
  const id = newTicket('AC-1 closing reply');
  expect(reply(id, 'hanna', 'Gelöst.', seed.status.closed)).toBe('ok');
  expect(await feedbackMails(request, id)).toHaveLength(1);
});

test('AC-1: "Resolved" is a closed status as well', async ({ request }) => {
  const id = newTicket('AC-1 resolved');
  reply(id, 'hanna', 'Gelöst.');
  expect(setStatus(id, 'hanna', seed.status.resolved)).toBe('ok');
  expect(await feedbackMails(request, id)).toHaveLength(1);
});

test('AC-1: closed without an agent session (cron, API) still asks', async ({ request }) => {
  const id = newTicket('AC-1 closed by system');
  reply(id, 'hanna', 'Gelöst.');
  expect(setStatus(id, null, seed.status.closed)).toBe('ok');
  expect(await feedbackMails(request, id)).toHaveLength(1);
});

test('AC-2: closed without a reply: no mail', async ({ request }) => {
  const id = newTicket('AC-2 no reply');
  expect(close(id)).toBe('ok');
  expect(await feedbackMails(request, id)).toHaveLength(0);
  expect(row(id)).toBeNull();
});

test('AC-2: Alerts department: no mail', async ({ request }) => {
  const id = newTicket('AC-2 alert', { dept: seed.depts.alerts });
  reply(id, 'admin', 'Gesehen.');
  close(id, 'admin');
  expect(await feedbackMails(request, id)).toHaveLength(0);
});

test('AC-2: closed by an AI agent: no mail', async ({ request }) => {
  const id = newTicket('AC-2 AI closes');
  reply(id, 'hanna', 'Erste Antwort.');
  expect(close(id, 'bot')).toBe('ok');
  expect(await feedbackMails(request, id)).toHaveLength(0);
  expect(row(id)).toBeNull();
});

test('AC-2: closed in an AI Actions run (no session, event rewritten to the AI agent): no mail', async ({ request }) => {
  const id = newTicket('AC-2 AI run');
  reply(id, 'hanna', 'Erste Antwort.');
  // What AI Actions does: close without $thisstaff, then rewrite the event to its agent.
  const { php } = require('./support');
  php(`$t = Ticket::lookup(${id}); $e = array(); $t->setStatus(${seed.status.closed}, '', $e);`
    + ` db_query("UPDATE ost_thread_event SET staff_id=${seed.agents.bot}, username='mailbot' WHERE thread_id=" . (int) $t->getThreadId());`);
  expect(await feedbackMails(request, id)).toHaveLength(0);
  expect(row(id)).toBeNull();
});

test('AC-2: only replies by an AI agent count as no reply', async ({ request }) => {
  const id = newTicket('AC-2 AI replies only');
  reply(id, 'bot', 'Automatische Antwort.');
  close(id);
  expect(await feedbackMails(request, id)).toHaveLength(0);
});

test('AC-2: sub-ticket: no mail', async ({ request }) => {
  const parent = newTicket('AC-2 parent');
  const id = newTicket('AC-2 child', { parent });
  reply(id, 'hanna', 'Antwort im Unter-Ticket.');
  close(id);
  expect(await feedbackMails(request, id)).toHaveLength(0);
});

test('AC-2: own domain and excluded domain: no mail', async ({ request }) => {
  const own = newTicket('AC-2 own domain', { email: 'colleague@example.com' });
  reply(own, 'hanna', 'Intern.');
  close(own);
  expect(await feedbackMails(request, own)).toHaveLength(0);

  const { setting } = require('./support');
  setting('exclude_domains', 'partner.example');
  const partner = newTicket('AC-2 partner', { email: 'bob@mail.partner.example' });
  reply(partner, 'hanna', 'Antwort.');
  close(partner);
  expect(await feedbackMails(request, partner)).toHaveLength(0);
});

test('AC-2: switched off: no mail', async ({ request }) => {
  const { setting } = require('./support');
  setting('enabled', '0');
  const id = newTicket('AC-2 off');
  reply(id, 'hanna', 'Antwort.');
  close(id);
  expect(await feedbackMails(request, id)).toHaveLength(0);
});

test('AC-9: closed twice: no second mail', async ({ request }) => {
  const id = newTicket('AC-9 twice');
  reply(id, 'hanna', 'Antwort.');
  close(id);
  expect(setStatus(id, 'hanna', seed.status.open)).toBe('ok');
  reply(id, 'hanna', 'Noch eine Antwort.');
  close(id);
  expect(await feedbackMails(request, id)).toHaveLength(1);
  expect(Number(sql(`SELECT COUNT(*) FROM ${T} WHERE ticket_id=${id}`))).toBe(1);
});

test('a skipped close leaves no trace: a later close with a reply asks', async ({ request }) => {
  const id = newTicket('skip then ask');
  close(id);
  setStatus(id, 'hanna', seed.status.open);
  reply(id, 'hanna', 'Jetzt mit Antwort.');
  close(id);
  expect(await feedbackMails(request, id)).toHaveLength(1);
});

test('a request a dying run left behind goes out with the cron', async ({ request }) => {
  const { cron, php } = require('./support');
  const id = newTicket('cron pickup');
  reply(id, 'hanna', 'Antwort.');
  // Queued as the close handler does, but this process "dies" before its shutdown mail.
  php(`$t = Ticket::lookup(${id}); $t->status_id = ${seed.status.closed}; $t->closed = SqlFunction::NOW();`
    + ` db_query("UPDATE ost_ticket SET status_id=${seed.status.closed}, closed=NOW() WHERE ticket_id=${id}");`
    + ` db_query("INSERT INTO ${T} (ticket_id, mode, scale, state, closer_id, created) VALUES (${id}, 'mail', 'thumbs', 'pending', 0, UNIX_TIMESTAMP())");`);
  expect(row(id).state).toBe('pending');
  cron();
  expect(await feedbackMails(request, id)).toHaveLength(1);
  expect(row(id).state).toBe('sent');
});
