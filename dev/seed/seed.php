<?php
/**
 * Seeds the local test instance. Runs inside the osticket container:
 *
 *   php /seed/seed.php
 *
 * Idempotent per stack: refuses to run twice (the database lives on tmpfs,
 * dev/down.sh + dev/up.sh starts over).
 *
 * Data set (tickets are created by the specs themselves):
 *
 *   Customer   alice@acme.example (Acme Corp), with a portal account
 *   Agents     the installer's admin; hanna and paul (Support, "Expanded
 *              Access", human); "Mail Bot", an AI agent the way AI Actions
 *              creates them (backend "ai-agent", no password)
 *   Depts      Support (installer) and "Alerts", excluded by the plugin
 *   Plugin     installed, enabled, German texts, Alerts excluded
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

chdir('/var/www/html');
$_SERVER['SCRIPT_NAME'] = '/seed.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
require 'main.inc.php';

function fail(string $msg): void
{
    fwrite(STDERR, "seed: $msg\n");
    fwrite(STDERR, "seed: partial data is left behind, start over with dev/down.sh and dev/up.sh\n");
    exit(1);
}

function q(string $sql): void
{
    if (!db_query($sql)) {
        fail('query failed: ' . $sql . ': ' . db_error());
    }
}

function scalar(string $sql)
{
    $res = db_query($sql);
    $row = $res ? db_fetch_row($res) : null;
    return $row ? $row[0] : null;
}

$agentUser = getenv('SPF_AGENT_USER') ?: fail('SPF_AGENT_USER missing');
$agentPass = getenv('SPF_AGENT_PASS') ?: fail('SPF_AGENT_PASS missing');
$clientPass = getenv('SPF_CLIENT_PASS') ?: fail('SPF_CLIENT_PASS missing');

if (scalar("SELECT COUNT(*) FROM " . ORGANIZATION_TABLE . " WHERE name='Acme Corp'") > 0) {
    echo "seed: already seeded, nothing to do\n";
    exit(0);
}

// The installer's welcome ticket would only blur the counts.
foreach (Ticket::objects() as $t) {
    $t->delete();
}

// ------------------------------------------------------------- settings

$cfg->updateAll(array(
    'enable_captcha'      => 0,
    'client_registration' => 'public',
    // osTicket checks mail domains by DNS; the test domains have none.
    'verify_email_addrs'  => 0,
    // Assignment alerts to the assigned agent (AC-4 reads that mail).
    'assigned_alert_active' => 1,
    'assigned_alert_staff'  => 1,
));

// ------------------------------------------------------- org and customer

$org = Organization::fromVars(array('name' => 'Acme Corp'));
$user = User::fromVars(array('email' => 'alice@acme.example', 'name' => 'Alice Acme'));
if (!$org || !$user) {
    fail('org or user');
}
$user->org_id = (int) $org->getId();
$user->save();
$errors = array();
if (!UserAccount::register($user, array(
    'passwd1' => $clientPass, 'passwd2' => $clientPass, 'timezone' => 'UTC', 'backend' => 'client',
), $errors)) {
    fail('account: ' . json_encode($errors));
}

// ---------------------------------------------------------- departments

$support = (int) scalar("SELECT id FROM " . DEPT_TABLE . " WHERE name='Support'");
$errors = array();
$alerts = Dept::create();
if (!$alerts->update(array('name' => 'Alerts', 'ispublic' => 1, 'email_id' => 0, 'tpl_id' => 0, 'sla_id' => 0,
        'manager_id' => 0, 'pid' => 0, 'status' => 'active', 'group_membership' => 0, 'assign_members_only' => 0,
        'signature' => '', 'flags' => 0), $errors)) {
    fail('dept Alerts: ' . json_encode($errors));
}
$alertsId = (int) $alerts->getId();

// ---------------------------------------------------------------- agents

$adminId = (int) scalar("SELECT staff_id FROM " . STAFF_TABLE . " ORDER BY staff_id LIMIT 1");
q(sprintf("INSERT IGNORE INTO %s (staff_id, dept_id, role_id, flags) VALUES (%d, %d, 1, 1)", STAFF_DEPT_TABLE, $adminId, $alertsId));

function agent(string $username, string $first, string $last, int $dept, string $pass): int
{
    $roleId = (int) scalar(sprintf("SELECT id FROM %s WHERE name='Expanded Access'", ROLE_TABLE));
    $a = Staff::create(array(
        'username'  => $username,
        'firstname' => $first,
        'lastname'  => $last,
        'email'     => $username . '@agents.example.net',
        'dept_id'   => $dept,
        'role_id'   => $roleId,
        'isactive'  => 1,
        'isadmin'   => 0,
        'isvisible' => 1,
    ));
    // Saved first: setPassword() clears reset tokens by staff ID.
    if (!$a->save()) {
        fail("agent $username");
    }
    if ($pass !== '') {
        $a->setPassword($pass);
        $a->save();
    }
    return (int) $a->getId();
}

$agents = array(
    'admin' => $adminId,
    'hanna' => agent($agentUser, 'Hanna', 'Human', $support, $agentPass),
    'paul'  => agent('paul', 'Paul', 'Second', $support, $agentPass),
    'bot'   => agent('mailbot', 'Mail', 'Bot', $support, ''),
);
// What AI Actions does for its AI agents.
q(sprintf("UPDATE %s SET backend='ai-agent' WHERE staff_id=%d", STAFF_TABLE, $agents['bot']));

// ---------------------------------------------------------------- plugin

$plugin = $ost->plugins->install('feedback');
if (!$plugin) {
    fail('plugin install');
}
$pid = (int) $plugin->getId();
q(sprintf("UPDATE %s SET isactive=1 WHERE id=%d", PLUGIN_TABLE, $pid));
q(sprintf("INSERT INTO %s (plugin_id, flags, name, notes, created, updated) VALUES (%d, 1, 'default', '', NOW(), NOW())",
    PLUGIN_INSTANCE_TABLE, $pid));
$iid = (int) db_insert_id();
$ns = sprintf('plugin.%d.instance.%d', $pid, $iid);
foreach (array('enabled' => '1', 'language' => 'de', 'exclude_depts' => json_encode(array($alertsId => 'Alerts'))) as $k => $v) {
    q(sprintf("INSERT INTO %s (namespace, `key`, value, updated) VALUES ('%s', '%s', %s, NOW())",
        CONFIG_TABLE, $ns, $k, db_input($v)));
}
FeedbackStore::ensure();

$status = array();
foreach (array('open' => 'Open', 'resolved' => 'Resolved', 'closed' => 'Closed') as $k => $name) {
    $status[$k] = (int) scalar(sprintf("SELECT id FROM %s WHERE name=%s", TICKET_STATUS_TABLE, db_input($name)));
}

echo json_encode(array(
    'plugin' => array('id' => $pid, 'instance' => $iid),
    'agents' => $agents,
    'depts'  => array('support' => $support, 'alerts' => $alertsId),
    'status' => $status,
), JSON_PRETTY_PRINT) . "\n";
