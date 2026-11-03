// Shared helpers. Credentials and IDs come from the files dev/up.sh writes
// into dev/local/ (git-ignored). Nothing secret lives in the repo.
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

// SPF_LOCAL points the suite at a second stack (see dev/up.sh).
const LOCAL = process.env.SPF_LOCAL ? path.resolve(process.env.SPF_LOCAL) : path.resolve(__dirname, '../../dev/local');
const COMPOSE = path.resolve(__dirname, '../../dev/docker-compose.yml');

function readEnv(file) {
  const out = {};
  if (!fs.existsSync(file)) return out;
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
    if (m) out[m[1]] = m[2];
  }
  return out;
}

const env = Object.assign(readEnv(path.join(LOCAL, 'credentials.env')), process.env);
const seedFile = path.join(LOCAL, 'seed.json');
const seed = fs.existsSync(seedFile) ? JSON.parse(fs.readFileSync(seedFile, 'utf8')) : null;
if (!seed) throw new Error('dev/local/seed.json missing, run dev/up.sh first');

const BASE = `http://127.0.0.1:${env.SPF_HTTP_PORT || '8095'}`;
const MAIL = `http://127.0.0.1:${env.SPF_MAIL_PORT || '8031'}/api/v1`;
const NS = `plugin.${seed.plugin.id}.instance.${seed.plugin.instance}`;
const T = 'ost_feedback_request';

function compose(args) {
  return execFileSync('docker', [
    'compose', '-p', env.SPF_PROJECT || 'spf-dev', '--env-file', path.join(LOCAL, 'credentials.env'), '-f', COMPOSE, ...args,
  ], { encoding: 'utf8' });
}

/** Runs SQL in the test database; only for state the UI can't set up quickly. */
function sql(statement) {
  return compose(['exec', '-T', 'db', 'mariadb', '-uosticket', `-p${env.SPF_DB_PASSWORD}`, 'osticket', '-N', '-e', statement]).trim();
}

/**
 * Runs PHP inside the osTicket container with osTicket and the plugin loaded.
 * The plugin's end-of-request mail runs when this process exits, like after a
 * web request. Returns stdout, trimmed.
 */
function php(code) {
  const boot = "chdir('/var/www/html'); $_SERVER['SCRIPT_NAME']='/x.php'; $_SERVER['REMOTE_ADDR']='127.0.0.1';"
    + " $_SERVER['HTTP_HOST']='localhost'; require 'main.inc.php'; ";
  return compose(['exec', '-T', 'osticket', 'php', '-r', boot + code]).trim();
}

const q = (s) => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

/** One plugin setting; null removes it (default applies). */
function setting(key, value) {
  sql(`DELETE FROM ost_config WHERE namespace='${NS}' AND \`key\`='${key}'`);
  if (value !== null) {
    sql(`INSERT INTO ost_config (namespace, \`key\`, value, updated) VALUES ('${NS}', '${key}', ${q(value)}, NOW())`);
  }
}

/** Back to the seed's settings: on, German, Alerts excluded, rest default. */
function resetSettings() {
  sql(`DELETE FROM ost_config WHERE namespace='${NS}' AND \`key\` NOT IN ('enabled','language','exclude_depts')`);
  setting('enabled', '1');
  // The whole suite comes from one IP; the rate limit has its own spec.
  sql('DELETE FROM ost_feedback_hit');
}

/** A new customer ticket; returns its ID. */
function newTicket(subject, opts = {}) {
  const dept = opts.dept || seed.depts.support;
  const email = opts.email || 'alice@acme.example';
  const id = Number(php(`$e = array(); $t = Ticket::create(array('email' => ${q(email)}, 'name' => 'Alice Acme',`
    + ` 'subject' => ${q(subject)}, 'message' => 'Kundennachricht', 'deptId' => ${dept}, 'ip' => '127.0.0.1', 'source' => 'API'),`
    + ` $e, 'api', false, false); echo $t ? $t->getId() : 0;`));
  if (!id) throw new Error(`ticket "${subject}" not created`);
  if (opts.parent) sql(`UPDATE ost_ticket SET ticket_pid=${opts.parent} WHERE ticket_id=${id}`);
  return id;
}

/** A reply by an agent (seed key), optionally setting a status in the same step. */
function reply(id, who, text, statusId = 0) {
  return php(`global $thisstaff; $thisstaff = Staff::lookup(${seed.agents[who]}); $t = Ticket::lookup(${id}); $e = array();`
    + ` $r = $t->postReply(array('response' => ${q(`<p>${text}</p>`)}, 'reply-to' => 'all', 'signature' => 'none',`
    + ` 'reply_status_id' => ${statusId}), $e, true); echo $r ? 'ok' : json_encode($e);`);
}

/** Status change as an agent (seed key) or, with null, without one (cron, API). */
function setStatus(id, who, statusId) {
  const as = who ? `global $thisstaff; $thisstaff = Staff::lookup(${seed.agents[who]});` : '';
  return php(`${as} $t = Ticket::lookup(${id}); $e = array(); echo $t->setStatus(${statusId}, '', $e) ? 'ok' : json_encode($e);`);
}

const close = (id, who = 'hanna') => setStatus(id, who, seed.status.closed);

function row(id) {
  const out = sql(`SELECT id, state, mode, scale, IFNULL(value,'null'), bad, acted, IFNULL(comment,''), IFNULL(action_due,0) FROM ${T} WHERE ticket_id=${id}`);
  if (!out) return null;
  const [rid, state, mode, scale, value, bad, acted, comment, due] = out.split('\t');
  return { id: Number(rid), state, mode, scale, value: value === 'null' ? null : Number(value), bad: Number(bad),
    acted: Number(acted), comment, due: Number(due) };
}

const number = (id) => sql(`SELECT number FROM ost_ticket WHERE ticket_id=${id}`);
const state = (id) => sql(`SELECT s.state FROM ost_ticket t JOIN ost_ticket_status s ON s.id=t.status_id WHERE t.ticket_id=${id}`);
const assignee = (id) => Number(sql(`SELECT staff_id FROM ost_ticket WHERE ticket_id=${id}`));

/** Internal notes of a ticket, newest last: [{title, body}]. */
function notes(id) {
  // Two columns: mariadb -N separates them with a tab.
  const out = sql(`SELECT IFNULL(e.title,''), REPLACE(REPLACE(e.body, '\\n', ' '), '\\t', ' ') FROM ost_thread_entry e
    JOIN ost_thread h ON h.id=e.thread_id WHERE h.object_type='T' AND h.object_id=${id} AND e.type='N' ORDER BY e.id`);
  return out ? out.split('\n').map((l) => { const [title, body] = l.split('\t'); return { title, body }; }) : [];
}

/** Bodies of the agent replies stored in the thread. */
function storedReplies(id) {
  const out = sql(`SELECT REPLACE(e.body, '\\n', ' ') FROM ost_thread_entry e JOIN ost_thread h ON h.id=e.thread_id
    WHERE h.object_type='T' AND h.object_id=${id} AND e.type='R' ORDER BY e.id`);
  return out ? out.split('\n') : [];
}

async function clearMail(request) {
  await request.delete(`${MAIL}/messages`);
}

/** Mails whose subject names the ticket: [{to, subject, html, text}]. */
async function mails(request, id) {
  const list = await (await request.get(`${MAIL}/search?query=${encodeURIComponent(number(id))}`)).json();
  const out = [];
  for (const m of list.messages || []) {
    const full = await (await request.get(`${MAIL}/message/${m.ID}`)).json();
    out.push({ to: m.To.map((t) => t.Address.toLowerCase()), subject: m.Subject, html: full.HTML || '', text: full.Text || '' });
  }
  return out.reverse();
}

const feedbackMails = async (request, id) => (await mails(request, id)).filter((m) => /Kurze Frage zu deinem Ticket/.test(m.subject));

/** Rating links in a mail, by value. */
function links(html) {
  const out = {};
  for (const m of html.matchAll(/href="([^"]*\/ajax\.php\/feedback\/[^"?]+)\?v=(\d+)"/g)) out[Number(m[2])] = m[1].replace(/&amp;/g, '&');
  return out;
}

/** A link for a ticket without a mail: the token as the plugin would build it. */
function tokenFor(id) {
  return php(`$r = FeedbackStore::byNonce(db_result(db_query("SELECT nonce FROM ${T} WHERE ticket_id=${id}")));`
    + ` echo FeedbackToken::make(FeedbackPlugin::key(), ${id}, (int) $r['expires'], $r['nonce']);`);
}

const cron = () => php("Signal::send('cron', null);");

const logins = {
  admin: () => [env.SPF_ADMIN_USER, env.SPF_ADMIN_PASS],
  hanna: () => [env.SPF_AGENT_USER, env.SPF_AGENT_PASS],
};

async function staffLogin(page, who) {
  const [user, pass] = logins[who]();
  await page.goto('/scp/login.php');
  await page.fill('input[name="userid"]', user);
  await page.fill('input[name="passwd"]', pass);
  await page.click('#login button[type="submit"]');
  await page.waitForURL(/\/scp\/(index|tickets)\.php|\/scp\/$/);
}

/** Collects uncaught page errors and console errors. */
function watchErrors(page) {
  const errors = [];
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(`console: ${m.text()}`); });
  return errors;
}

module.exports = {
  env, seed, BASE, MAIL, T, sql, php, q, setting, resetSettings, newTicket, reply, setStatus, close, row, number, state,
  assignee, notes, storedReplies, clearMail, mails, feedbackMails, links, tokenFor, cron, staffLogin, watchErrors, LOCAL,
};
