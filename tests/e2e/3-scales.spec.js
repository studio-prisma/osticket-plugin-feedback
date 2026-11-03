// @ts-check
// AC-6: stars and NPS get their buttons, the "bad" threshold applies.
const { test, expect } = require('@playwright/test');
const { seed, newTicket, reply, close, row, state, notes, clearMail, feedbackMails, links, setting, resetSettings } = require('./support');

async function asked(request, subject) {
  const id = newTicket(subject);
  reply(id, 'hanna', 'Antwort.');
  close(id);
  const fb = await feedbackMails(request, id);
  expect(fb).toHaveLength(1);
  return { id, mail: fb[0], link: links(fb[0].html) };
}

test.beforeEach(async ({ request }) => {
  resetSettings();
  setting('comment_on', '0');
  await clearMail(request);
});

test('AC-6: stars → five buttons; 2 stars is bad and reopens', async ({ page, request }) => {
  setting('scale', 'stars');
  const { id, mail, link } = await asked(request, 'AC-6 stars bad');
  expect(Object.keys(link).map(Number).sort()).toEqual([1, 2, 3, 4, 5]);
  expect(mail.html).toContain('5 &#x2605;');
  await page.goto(`${link[2]}?v=2`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  expect(row(id)).toMatchObject({ scale: 'stars', value: 2, bad: 1, acted: 1 });
  expect(state(id)).toBe('open');
});

test('AC-6: 3 stars is fine by default, bad with the threshold at 3', async ({ page, request }) => {
  setting('scale', 'stars');
  const a = await asked(request, 'AC-6 stars ok');
  await page.goto(`${a.link[3]}?v=3`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  expect(row(a.id)).toMatchObject({ value: 3, bad: 0 });
  expect(state(a.id)).toBe('closed');
  expect(notes(a.id).at(-1).body).toContain('★★★☆☆ (3/5)');

  setting('bad_stars', '3');
  const b = await asked(request, 'AC-6 stars threshold');
  await page.goto(`${b.link[3]}?v=3`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  expect(row(b.id)).toMatchObject({ value: 3, bad: 1 });
  expect(state(b.id)).toBe('open');
});

test('AC-6: NPS → eleven buttons; 6 is bad, 7 is not', async ({ page, request }) => {
  setting('scale', 'nps');
  const a = await asked(request, 'AC-6 nps 7');
  expect(Object.keys(a.link).map(Number).sort((x, y) => x - y)).toEqual([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
  expect(a.mail.html).toContain('weiterempfiehlst');
  await page.goto(`${a.link[7]}?v=7`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  expect(row(a.id)).toMatchObject({ scale: 'nps', value: 7, bad: 0 });
  expect(state(a.id)).toBe('closed');

  const b = await asked(request, 'AC-6 nps 6');
  await page.goto(`${b.link[6]}?v=6`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  expect(row(b.id)).toMatchObject({ value: 6, bad: 1 });
  expect(state(b.id)).toBe('open');
});

test('AC-6: a value outside the scale is refused', async ({ page, request }) => {
  setting('scale', 'stars');
  const { id, link } = await asked(request, 'AC-6 out of range');
  await page.goto(`${link[1]}?v=9`);
  // Unknown value: the choice page, nothing stored.
  await expect(page.locator('.spf-choices button')).toHaveCount(5);
  expect(row(id).value).toBeNull();
});

test('AC-6: the scale of the question counts, not a later setting', async ({ page, request }) => {
  const { id, link } = await asked(request, 'AC-6 scale changed later');
  setting('scale', 'nps');
  await page.goto(`${link[0]}?v=0`);
  await expect(page.locator('.spf-page h1')).toHaveText('Danke!');
  expect(row(id)).toMatchObject({ scale: 'thumbs', value: 0, bad: 1 });
  expect(state(id)).toBe('open');
  expect(seed.status.open).toBeGreaterThan(0);
});
