// @ts-check
// AC-3, AC-4, AC-5, AC-8: what a click does.
const { test, expect } = require('@playwright/test');
const {
  seed, sql, newTicket, reply, close, row, state, assignee, notes, clearMail, mails, feedbackMails, links, setting,
  resetSettings, cron, watchErrors, T,
} = require('./support');

/** A closed ticket with a reply by $who, and the rating links from its mail. */
async function asked(request, subject, who = 'hanna') {
  const id = newTicket(subject);
  reply(id, who, 'Antwort vom Agenten.');
  close(id, 'paul');
  const fb = await feedbackMails(request, id);
  expect(fb).toHaveLength(1);
  return { id, link: links(fb[0].html) };
}

test.beforeEach(async ({ request }) => {
  resetSettings();
  await clearMail(request);
});

test('AC-3: 👍 → thank-you page, note at the ticket, no status change', async ({ page, request }) => {
  const errors = watchErrors(page);
  const { id, link } = await asked(request, 'AC-3 good');
  await page.goto(`${link[1]}?v=1`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  await expect(page.locator('.spf-page')).toContainText(`Ticket ${sql(`SELECT number FROM ost_ticket WHERE ticket_id=${id}`)}`);
  await expect(page.locator('#spf-text')).toBeVisible();
  expect(row(id)).toMatchObject({ value: 1, bad: 0, acted: 0 });
  expect(state(id)).toBe('closed');
  const n = notes(id);
  expect(n.at(-1).title).toBe('Kundenbewertung');
  expect(n.at(-1).body).toContain('Bewertung: Daumen hoch (zufrieden)');
  // The page shows no ticket content.
  await expect(page.locator('body')).not.toContainText('AC-3 good');
  await expect(page.locator('body')).not.toContainText('Antwort vom Agenten');
  expect(errors).toEqual([]);
});

test('AC-3: a comment after 👍 becomes a note', async ({ page, request }) => {
  const { id, link } = await asked(request, 'AC-3 comment');
  await page.goto(`${link[1]}?v=1`);
  await page.fill('#spf-text', 'Schnell und freundlich, danke 👍');
  await page.click('.spf-comment button[type=submit]');
  await expect(page.locator('.spf-page')).toContainText('dein Kommentar ist angekommen');
  expect(row(id).comment).toContain('Schnell und freundlich');
  const n = notes(id);
  expect(n.at(-1).title).toBe('Kommentar zur Bewertung');
  expect(n.at(-1).body).toContain('Schnell und freundlich, danke');
  expect(state(id)).toBe('closed');
});

test('AC-4: 👎 (default) → open, assigned to the last agent who replied, one assignment mail with rating and comment',
  async ({ page, request }) => {
    const { id, link } = await asked(request, 'AC-4 bad', 'hanna');
    await clearMail(request);
    await page.goto(`${link[0]}?v=0`);
    await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
    await expect(page.locator('label[for=spf-text]')).toContainText('Was ist schiefgelaufen?');
    // Waits for the comment.
    expect(row(id)).toMatchObject({ value: 0, bad: 1, acted: 0 });
    expect(state(id)).toBe('closed');
    await page.fill('#spf-text', 'Problem besteht weiter <b>immer noch</b>');
    await page.click('.spf-comment button[type=submit]');
    await expect(page.locator('.spf-page')).toContainText('dein Kommentar ist angekommen');

    expect(state(id)).toBe('open');
    expect(assignee(id)).toBe(seed.agents.hanna);
    expect(row(id).acted).toBe(1);
    const sent = await mails(request, id);
    const toHanna = sent.filter((m) => m.to.includes('hanna@agents.example.net'));
    expect(toHanna).toHaveLength(1);
    expect(toHanna[0].html).toContain('Daumen runter');
    expect(toHanna[0].html).toContain('nicht zufrieden');
    // Rating and comment as %{comments}; brackets as look-alikes (the same text becomes the note).
    expect(toHanna[0].html).toContain('Problem besteht weiter ‹b›immer noch‹/b›');
    // Nothing goes to the customer.
    expect(sent.filter((m) => m.to.includes('alice@acme.example'))).toHaveLength(0);
  });

test('AC-4: 👎 without a comment acts after the wait, in the cron', async ({ page, request }) => {
  const { id, link } = await asked(request, 'AC-4 cron');
  await page.goto(`${link[0]}?v=0`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  cron();
  expect(state(id)).toBe('closed');
  sql(`UPDATE ${T} SET action_due=UNIX_TIMESTAMP()-1 WHERE ticket_id=${id}`);
  cron();
  expect(state(id)).toBe('open');
  expect(assignee(id)).toBe(seed.agents.hanna);
});

test('AC-4: "done without a comment" acts at once', async ({ page, request }) => {
  const { id, link } = await asked(request, 'AC-4 skip');
  await page.goto(`${link[0]}?v=0`);
  await page.click('.spf-comment button[name=skip]');
  await expect(page.locator('.spf-page')).toContainText('angekommen');
  expect(state(id)).toBe('open');
});

test('AC-4: comment field off → 👎 acts on the click', async ({ page, request }) => {
  setting('comment_on', '0');
  const { id, link } = await asked(request, 'AC-4 no comment field');
  await page.goto(`${link[0]}?v=0`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  await expect(page.locator('#spf-text')).toHaveCount(0);
  expect(state(id)).toBe('open');
  expect(assignee(id)).toBe(seed.agents.hanna);
});

test('AC-5: "note + mail" → ticket stays closed, note and mail to the responsible agent', async ({ page, request }) => {
  setting('on_bad', 'notify');
  setting('comment_on', '0');
  const { id, link } = await asked(request, 'AC-5 notify', 'hanna');
  await clearMail(request);
  await page.goto(`${link[0]}?v=0`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  expect(state(id)).toBe('closed');
  const n = notes(id);
  expect(n.at(-1).title).toBe('Kundenbewertung');
  expect(n.at(-1).body).toContain('Bewertung: Daumen runter (nicht zufrieden)');
  // Responsible = the closing agent (paul), osTicket keeps him as staff_id.
  const sent = await mails(request, id);
  expect(sent).toHaveLength(1);
  expect(sent[0].to).toEqual(['paul@agents.example.net']);
  expect(sent[0].subject).toContain('Schlechte Bewertung');
  expect(sent[0].html).toContain('Das Ticket bleibt geschlossen');
});

test('AC-8: changing the rating overwrites it; the bad action still runs once', async ({ page, request }) => {
  setting('comment_on', '0');
  const { id, link } = await asked(request, 'AC-8 change');
  await page.goto(`${link[1]}?v=1`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  await page.goto(`${link[0]}?v=0`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  expect(row(id)).toMatchObject({ value: 0, bad: 1, acted: 1 });
  expect(state(id)).toBe('open');
  close(id, 'hanna');
  await page.goto(`${link[0]}?v=1`);
  await page.goto(`${link[0]}?v=0`);
  expect(state(id)).toBe('closed');
  expect(row(id).value).toBe(0);
  expect(notes(id).some((n) => n.body.includes('Bewertung geändert'))).toBe(true);
});

test('AC-8: a page view alone (link scanner, no JavaScript) stores nothing', async ({ request }) => {
  const { id, link } = await asked(request, 'AC-8 scanner');
  const res = await request.get(`${link[0]}?v=0`);
  expect(res.status()).toBe(200);
  expect(await res.text()).toContain('Bewertung abschicken');
  expect(row(id).value).toBeNull();
  expect(state(id)).toBe('closed');
});

test('AC-8: without JavaScript the button stores the rating', async ({ browser, request }) => {
  const { id, link } = await asked(request, 'AC-8 no js');
  const ctx = await browser.newContext({ javaScriptEnabled: false });
  const page = await ctx.newPage();
  await page.goto(`${link[1]}?v=1`);
  expect(row(id).value).toBeNull();
  await page.click('#spf-confirm button[type=submit]');
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  expect(row(id).value).toBe(1);
  await ctx.close();
});

test('AC-8: link without a value shows the choice', async ({ page, request }) => {
  const { id, link } = await asked(request, 'AC-8 choice');
  await page.goto(link[1]);
  await expect(page.locator('.spf-choices button')).toHaveCount(2);
  expect(row(id).value).toBeNull();
  await page.locator('.spf-choices button').first().click();
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  expect(row(id).value).toBe(1);
});
