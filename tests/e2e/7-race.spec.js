// @ts-check
// Security PoCs beyond the spec's list: parallel clicks, markup in the
// ticket subject, a guessed nonce.
const { test, expect } = require('@playwright/test');
const { request: pw } = require('@playwright/test');
const { sql, newTicket, reply, close, row, state, clearMail, mails, feedbackMails, links, resetSettings, setting, T } = require('./support');

test.beforeEach(async ({ request }) => {
  resetSettings();
  await clearMail(request);
});

test('parallel 👎 clicks: one reopen, one assignment, one note', async ({ request }) => {
  setting('comment_on', '0');
  const id = newTicket('RACE parallel');
  reply(id, 'hanna', 'Antwort.');
  close(id, 'paul');
  const link = links((await feedbackMails(request, id))[0].html);
  await clearMail(request);
  // Five sessions, each with its own CSRF token, all POST at once.
  const ctxs = await Promise.all([1, 2, 3, 4, 5].map(() => pw.newContext()));
  const forms = [];
  for (const c of ctxs) {
    const html = await (await c.get(`${link[0]}?v=0`)).text();
    forms.push(html.match(/name="__CSRFToken__" value="([^"]+)"/)[1]);
  }
  const res = await Promise.all(ctxs.map((c, i) => c.post(link[0], { form: { __CSRFToken__: forms[i], do: 'rate', v: '0' } })));
  for (const r of res) expect(r.status()).toBe(200);
  expect(state(id)).toBe('open');
  expect(row(id).acted).toBe(1);
  const assigned = Number(sql(`SELECT COUNT(*) FROM ost_thread_event e JOIN ost_event v ON v.id=e.event_id JOIN ost_thread h ON h.id=e.thread_id
    WHERE h.object_type='T' AND h.object_id=${id} AND v.name='assigned'`));
  expect(assigned).toBe(1);
  const toHanna = (await mails(request, id)).filter((m) => m.to.includes('hanna@agents.example.net'));
  expect(toHanna).toHaveLength(1);
  await Promise.all(ctxs.map((c) => c.dispose()));
});

test('markup in the ticket subject does not become markup in the feedback mail', async ({ request }) => {
  const id = newTicket('Subj <img src=x onerror=alert(1)> <b>fett</b>');
  reply(id, 'hanna', 'Antwort.');
  close(id);
  const fb = await feedbackMails(request, id);
  expect(fb).toHaveLength(1);
  expect(fb[0].html).not.toMatch(/<img src=x/i);
  expect(fb[0].html).not.toContain('<b>fett</b>');
  expect(fb[0].subject).not.toMatch(/[\r\n]/);
});

test('a nonce that exists, with a MAC for another ticket, is refused', async ({ request }) => {
  const a = newTicket('RACE nonce A');
  reply(a, 'hanna', 'Antwort.');
  close(a);
  const b = newTicket('RACE nonce B');
  reply(b, 'hanna', 'Antwort.');
  close(b);
  const la = links((await feedbackMails(request, a))[0].html)[1];
  const lb = links((await feedbackMails(request, b))[0].html)[1];
  const nonceA = la.split('/').pop().split('.')[0];
  const macB = lb.split('/').pop().split('.')[1];
  const res = await request.get(la.replace(/[^/]+$/, `${nonceA}.${macB}`) + '?v=1');
  expect(res.status()).toBe(404);
  expect(row(a).value).toBeNull();
  expect(row(b).value).toBeNull();
  expect(Number(sql(`SELECT COUNT(*) FROM ${T} WHERE value IS NOT NULL AND ticket_id IN (${a},${b})`))).toBe(0);
});
