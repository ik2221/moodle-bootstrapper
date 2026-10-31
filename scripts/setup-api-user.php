<?php
if (PHP_SAPI === 'cli') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Run inside the moodle-cli container. Idempotent: safe to run repeatedly.
//
// Run this script with this shell command:
//
//     docker compose run --rm moodle-cli
//
// The Honest Caveats for "Idempotent"
// -----------------------------------
//
// 1. The password is not re-applied on re-run.
//
//    If you change $API_USER['password'] in the script and re-run, the DB
//    password won't update — the user already exists, and the script skips
//    creation. If you need to rotate the password, that's a deliberate
//    separate action (via UI or a one-off SQL/PHP call), not something the
//    script does.
//
// 2. Capabilities are re-assigned every run.
//
//    assign_capability is idempotent in effect, but it does write to
//    mdl_role_capabilities. If someone manually changed a capability in the UI
//    between runs, the script will overwrite it back to your configured value.
//    That's usually what you want (the script is the source of truth), but
//    worth knowing.
//
// 3. If you edit $FUNCTIONS and remove a name, the script won't remove it from
//    the service. It only adds. Removed functions stay attached until you
//    delete them manually. Same for capabilities — the script never revokes
//    anything.
//

define('CLI_SCRIPT', true);
require_once('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/accesslib.php');

// ---------------- CONFIG ----------------
$API_USER = [
    'username'  => 'api_client',
    'password'  => getenv('API_USER_PASSWORD') ?: 'changeme',
    'firstname' => 'API',
    'lastname'  => 'Client',
    'email'     => 'api-client@example.com',
];
$ROLE_SHORTNAME = 'webserviceuser';
$ROLE_NAME      = 'Web Service User';
$SERVICE_SHORT  = 'moodle_api';
$SERVICE_NAME   = 'Moodle API Service';

// Verify the Attendance Plugin method names in:
// Site admin → Plugins → Web services → API Documentation
$FUNCTIONS = [
    'core_webservice_get_site_info',     // General site info / health check

    // Methods needed strictly for data sync
    'core_course_get_categories',        // Sync departments
    'core_course_get_courses_by_field',  // Sync courses
    'core_group_get_course_groups',      // Sync academic groups
    'core_enrol_get_enrolled_users',     // Sync students (enrollment)
    'mod_attendance_get_sessions',       // Sync sessions (lectures)
    'mod_attendance_get_session',        // Sync attendance logs
    'gradereport_user_get_grade_items',  // Sync grades

    // Extra methods
    // TODO: Add method descriptions
    'core_course_get_courses',
    'core_course_create_courses',
    'core_user_create_users',
    'core_user_get_users',
    'enrol_manual_enrol_users',
    'mod_attendance_get_courses_with_today_sessions',
    'mod_attendance_get_session_info',
    'mod_attendance_get_user_attendances',
];

$CAPABILITIES = [

    // Web services
    'webservice/rest:use'             => CAP_ALLOW,

    // Course Categories
    'moodle/category:viewhiddencategories' => CAP_ALLOW,

    // General
    'moodle/site:accessallgroups' => CAP_ALLOW,

    // Course access / editing
    'moodle/course:create'            => CAP_ALLOW,
    'moodle/course:managegroups'      => CAP_ALLOW,
    'moodle/course:update'            => CAP_ALLOW,
    'moodle/course:useremail'         => CAP_ALLOW,
    'moodle/course:view'              => CAP_ALLOW,
    'moodle/course:viewhiddencourses' => CAP_ALLOW,
    'moodle/course:visibility'        => CAP_ALLOW,

    // User access / editing
    'moodle/user:create'             => CAP_ALLOW,
    'moodle/user:update'             => CAP_ALLOW,
    'moodle/user:viewdetails'        => CAP_ALLOW,
    'moodle/user:viewhiddendetails'  => CAP_ALLOW,

    // Role / enrollment
    'enrol/manual:enrol' => CAP_ALLOW,
    'moodle/role:assign' => CAP_ALLOW,

    // Grade report
    'gradereport/user:view' => CAP_ALLOW,
];

// ---------------- HELPERS ----------------
$ctx = context_system::instance();
function step($m) { cli_writeln("==> $m"); }

// ---------------- 1. ROLE ----------------
step("Role '$ROLE_SHORTNAME'");
$roleid = $DB->get_field('role', 'id', ['shortname' => $ROLE_SHORTNAME]);
if (!$roleid) {
    $roleid = create_role($ROLE_NAME, $ROLE_SHORTNAME, 'API client role');
    cli_writeln("    created (id=$roleid)");
} else {
    cli_writeln("    exists (id=$roleid)");
}

// ---------------- 2. CAPABILITIES ----------------
step("Capabilities on role");
foreach ($CAPABILITIES as $cap => $perm) {
    assign_capability($cap, $perm, $roleid, $ctx->id, true);
    cli_writeln("    $cap");
}

// ---------------- 3. USER ----------------
step("User '{$API_USER['username']}'");
$user = $DB->get_record('user', ['username' => $API_USER['username']]);
if (!$user) {
    $u = (object)$API_USER;
    $u->auth        = 'manual';
    $u->confirmed   = 1;
    $u->mnethostid  = $CFG->mnet_localhost_id;
    $u->password    = hash_internal_user_password($API_USER['password']);
    $u->id          = user_create_user($u, false, false);
    $user           = $DB->get_record('user', ['id' => $u->id]);
    cli_writeln("    created (id={$user->id})");
} else {
    cli_writeln("    exists (id={$user->id})");
}

// ---------------- 4. ASSIGN ROLE ----------------
step("Assign role to user");
if (!$DB->record_exists('role_assignments',
        ['roleid' => $roleid, 'userid' => $user->id, 'contextid' => $ctx->id])) {
    role_assign($roleid, $user->id, $ctx->id);
    cli_writeln("    assigned");
} else {
    cli_writeln("    already assigned");
}

// ---------------- 5. SERVICE ----------------
step("Service '$SERVICE_SHORT'");
$service = $DB->get_record('external_services', ['shortname' => $SERVICE_SHORT]);
if (!$service) {
    $s = (object)[
        'name'            => $SERVICE_NAME,
        'shortname'       => $SERVICE_SHORT,
        'enabled'         => 1,
        'restrictedusers' => 1,
        'timecreated'     => time(),
    ];
    $s->id   = $DB->insert_record('external_services', $s);
    $service = $DB->get_record('external_services', ['id' => $s->id]);
    cli_writeln("    created (id={$service->id})");
} else {
    cli_writeln("    exists (id={$service->id})");
    if ((int)$service->enabled !== 1) {
        $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);
        cli_writeln("    enabled=1");
    }
    if ((int)$service->restrictedusers !== 1) {
        $DB->set_field('external_services', 'restrictedusers', 1, ['id' => $service->id]);
        cli_writeln("    restrictedusers=1");
    }
}

// ---------------- 6. FUNCTIONS ----------------
step("Functions on service");
foreach ($FUNCTIONS as $fn) {
    if (!$DB->record_exists('external_functions', ['name' => $fn])) {
        cli_writeln("    ! $fn  (NOT FOUND — typo or plugin missing, skipped)");
        continue;
    }
    if ($DB->record_exists('external_services_functions',
            ['externalserviceid' => $service->id, 'functionname' => $fn])) {
        cli_writeln("    = $fn");
    } else {
        $DB->insert_record('external_services_functions', (object)[
            'externalserviceid' => $service->id,
            'functionname'      => $fn,
        ]);
        cli_writeln("    + $fn");
    }
}

// ---------------- 7. AUTHORIZE USER ----------------
step("Authorize user for service");
if (!$DB->record_exists('external_services_users',
        ['externalserviceid' => $service->id, 'userid' => $user->id])) {
    $DB->insert_record('external_services_users', (object)[
        'externalserviceid' => $service->id,
        'userid'            => $user->id,
        'iprestriction'     => null,
        'validuntil'        => null,
        'timecreated'       => time(),
    ]);
    cli_writeln("    authorized");
} else {
    cli_writeln("    already authorized");
}

// ---------------- 8. TOKEN ----------------
step("Token");
$token = $DB->get_record('external_tokens',
    ['userid' => $user->id, 'externalserviceid' => $service->id]);
if (!$token) {
    $t = (object)[
        'token'             => random_string(32),
        'userid'            => $user->id,
        'externalserviceid' => $service->id,
        'tokentype'         => 0, // EXTERNAL_TOKEN_PERMANENT
        'contextid'         => $ctx->id,
        'creatorid'         => $user->id,
        'timecreated'       => time(),
        'validuntil'        => 0,
        'lastaccess'        => 0,
    ];
    $t->id = $DB->insert_record('external_tokens', $t);
    $token = $DB->get_record('external_tokens', ['id' => $t->id]);
    cli_writeln("    created");
} else {
    cli_writeln("    exists");
}

// ---------------- DONE ----------------
cli_writeln("");
cli_writeln("================== TOKEN ==================");
cli_writeln("  " . $token->token);
cli_writeln("===========================================");
cli_writeln("");
cli_writeln("Smoke test:");
cli_writeln("  curl -s http://localhost:8080/webservice/rest/server.php \\");
cli_writeln("    --data-urlencode 'wstoken={$token->token}' \\");
cli_writeln("    --data-urlencode 'wsfunction=core_webservice_get_site_info' \\");
cli_writeln("    --data-urlencode 'moodlewsrestformat=json'");

