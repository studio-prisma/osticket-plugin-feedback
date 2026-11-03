// @ts-check
// Security gate of the spec: token forgery, expiry, CSRF, XSS in the comment,
// rate limit, headers. AC-8: a bad link gets a neutral page and stores nothing.
const { test, expect } = require('@playwright/test');
const {
  seed, sql, newTicket, reply, close, row, state, notes, clearMail, feedbackMails, links, resetSettings, setting, T,
} = require('./support');

async function asked(request, subject) {
  const id = newTicket(subject);
  reply(id, 'hanna', 'Antwort.');
  close(id);
  const fb = await feedbackMails(request, id);
  expect(fb).toHaveLength(1);
  return { id, link: links(fb[0].html) };
}

const flip = (c) => (c === 'A' ? 'B' : 'A');

test.beforeEach(async ({ request }) => {
  resetSettings();
  await clearMail(request);
});

test('AC-8: a manipulated token → neutral page, nothing stored', async ({ page, request }) => {
  const { id, link } = await asked(request, 'SEC forged');
  const url = link[1];
  const tok = url.split('/').pop();
  const [nonce, mac] = tok.split('.');
  const variants = [
    `${nonce}.${flip(mac[0])}${mac.slice(1)}`,
    `${flip(nonce[0])}${nonce.slice(1)}.${mac}`,
    `${mac}.${nonce}`,
    `${nonce}.${mac}x`,
    'x'.repeat(22) + '.' + 'y'.repeat(22),
  ];
  for (const v of variants) {
    const res = await request.get(url.replace(tok, v) + '?v=1');
    expect([400, 404]).toContain(res.status());
    const html = await res.text();
    expect(html).not.toContain(sql(`SELECT number FROM ost_ticket WHERE ticket_id=${id}`));
  }
  const res = await page.goto(url.replace(tok, variants[0]) + '?v=1');
  expect(res.status()).toBe(404);
  await expect(page.locator('.spf-page h1')).toHaveText('Link nicht gültig');
  expect(row(id).value).toBeNull();
});

test('AC-8: an expired link → neutral page, nothing stored', async ({ page, request }) => {
  const { id, link } = await asked(request, 'SEC expired');
  // Expiry is part of the MAC: the stored expiry moves, the link is re-signed for it.
  const { tokenFor } = require('./support');
  sql(`UPDATE ${T} SET expires=UNIX_TIMESTAMP()-5 WHERE ticket_id=${id}`);
  const expired = link[1].replace(/[^/]+$/, tokenFor(id));
  const res = await page.goto(`${expired}?v=1`);
  expect(res.status()).toBe(404);
  await expect(page.locator('.spf-page h1')).toHaveText('Link nicht gültig');
  expect(row(id).value).toBeNull();
  // The original link carries the old expiry in its MAC and fails as well.
  expect((await request.get(`${link[1]}?v=1`)).status()).toBe(404);
});

test('AC-8: a token only rates its own ticket', async ({ page, request }) => {
  const a = await asked(request, 'SEC ticket A');
  const b = await asked(request, 'SEC ticket B');
  await page.goto(`${a.link[0]}?v=0`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  expect(row(a.id).value).toBe(0);
  expect(row(b.id).value).toBeNull();
  expect(state(b.id)).toBe('closed');
});

test('CSRF: a POST without the session token stores nothing', async ({ request }) => {
  const { id, link } = await asked(request, 'SEC csrf');
  const res = await request.post(link[1], { form: { do: 'rate', v: '1' }, maxRedirects: 0 });
  expect(res.status()).not.toBe(200);
  expect(row(id).value).toBeNull();
  // A token from another session doesn't help either.
  const other = await request.get(`${link[1]}?v=1`);
  const token = (await other.text()).match(/name="__CSRFToken__" value="([^"]+)"/)[1];
  const { request: pw } = require('@playwright/test');
  const fresh = await pw.newContext();
  const res2 = await fresh.post(link[1], { form: { __CSRFToken__: token, do: 'rate', v: '1' }, maxRedirects: 0 });
  expect(res2.status()).not.toBe(200);
  expect(row(id).value).toBeNull();
  await fresh.dispose();
});

test('XSS: a comment is stored as text and escaped everywhere', async ({ page, request }) => {
  setting('on_bad', 'notify');
  const { id, link } = await asked(request, 'SEC xss');
  await page.goto(`${link[1]}?v=1`);
  const evil = '<script>window.__pwned=1</script><img src=x onerror="window.__pwned=2">"\'&';
  await page.fill('#spf-text', evil);
  await page.click('.spf-comment button[type=submit]');
  await expect(page.locator('.spf-page')).toContainText('angekommen');
  expect(row(id).comment).toContain('<script>');
  const body = notes(id).at(-1).body;
  expect(body).not.toContain('<script');
  expect(body).not.toContain('<img');
  // osTicket's sanitizer decodes entities and strips tags; angle brackets come as look-alikes.
  expect(body).toContain('‹script›window.__pwned=1‹/script›');
  // The evaluation page shows it as text.
  const { staffLogin } = require('./support');
  await staffLogin(page, 'admin');
  await page.goto('/scp/ajax.php/feedback/admin');
  await expect(page.locator('body')).toContainText('<script>window.__pwned=1</script>');
  expect(await page.evaluate(() => window.__pwned)).toBeUndefined();
});

test('comment: capped at 1000 characters, only after a rating', async ({ page, request }) => {
  const { id, link } = await asked(request, 'SEC long comment');
  // Without a rating a comment is refused.
  await page.goto(`${link[1]}?v=1`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  await page.evaluate(() => { document.getElementById('spf-text').removeAttribute('maxlength'); });
  await page.fill('#spf-text', 'x'.repeat(5000));
  await page.click('.spf-comment button[type=submit]');
  expect(JSON.parse(row(id).comment)).toHaveLength(1000);

  const other = await asked(request, 'SEC comment before rating');
  const view = await request.get(`${other.link[1]}?v=1`);
  const csrf = (await view.text()).match(/name="__CSRFToken__" value="([^"]+)"/)[1];
  const res = await request.post(other.link[1], { form: { __CSRFToken__: csrf, do: 'comment', comment: 'hallo' } });
  expect(res.status()).toBe(404);
  expect(row(other.id).comment).toBe('');
});

test('rate limit: per IP, 429 after 60 requests in 10 minutes', async ({ request }) => {
  const { link } = await asked(request, 'SEC rate');
  sql('DELETE FROM ost_feedback_hit');
  let first429 = 0;
  for (let i = 1; i <= 62; i++) {
    // Changing tokens: the IP bucket must hold on its own.
    const res = await request.get(link[1].replace(/[^/]+$/, `${'a'.repeat(22)}.${String(i).padStart(22, 'b')}`) + '?v=1');
    if (res.status() === 429 && !first429) first429 = i;
  }
  expect(first429).toBe(61);
  const res = await request.get(`${link[1]}?v=1`);
  expect(res.status()).toBe(429);
  expect(res.headers()['retry-after']).toBe('600');
});

test('rate limit: per token, 429 after 20 requests', async ({ request }) => {
  const { id, link } = await asked(request, 'SEC rate token');
  sql('DELETE FROM ost_feedback_hit');
  let first429 = 0;
  for (let i = 1; i <= 22; i++) {
    const res = await request.get(link[1]);
    if (res.status() === 429 && !first429) first429 = i;
  }
  expect(first429).toBe(21);
  expect(row(id).value).toBeNull();
});

test('headers: no caching, no framing, no referrer; no redirect anywhere', async ({ request }) => {
  const { link } = await asked(request, 'SEC headers');
  const res = await request.get(`${link[1]}?v=1&return=https://evil.example/`, { maxRedirects: 0 });
  expect(res.status()).toBe(200);
  const h = res.headers();
  expect(h['cache-control']).toContain('no-store');
  expect(h['x-frame-options']).toBe('DENY');
  expect(h['referrer-policy']).toBe('no-referrer');
  expect(h['x-content-type-options']).toBe('nosniff');
  expect(h['location']).toBeUndefined();
  expect(await res.text()).not.toContain('evil.example');
});

test('the form posts to the configured help desk URL, not to the Host header', async ({ request }) => {
  const { link } = await asked(request, 'SEC host');
  const res = await request.get(`${link[1]}?v=1`, { headers: { Host: 'evil.example' } });
  const html = await res.text();
  expect(html).toMatch(/<form id="spf-confirm" method="post" action="\/ajax\.php\/feedback\//);
  expect(seed.status.closed).toBeGreaterThan(0);
});
