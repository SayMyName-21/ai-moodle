<?php
// Moodle configuration. Copied into the image by the Dockerfile.
//
// Nothing secret lives here: every value is read from the environment that
// docker-compose.yaml passes in, and the passwords come from .env.

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('MOODLE_DB_HOST');
$CFG->dbname    = getenv('MOODLE_DB_NAME');
$CFG->dbuser    = getenv('MOODLE_DB_USER');
$CFG->dbpass    = getenv('MOODLE_DB_PASSWORD');
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array(
    'dbpersist' => false,
    'dbsocket'  => false,
    'dbport'    => getenv('MOODLE_DB_PORT'),
);

$CFG->wwwroot   = getenv('MOODLE_URL');
$CFG->dataroot  = '/var/moodledata';
$CFG->directorypermissions = 02777;
$CFG->admin = 'admin';

if (filter_var(getenv('MOODLE_REVERSE_LB'), FILTER_VALIDATE_BOOLEAN)) {
    $CFG->reverseproxy = true;
}
if (filter_var(getenv('MOODLE_SSL'), FILTER_VALIDATE_BOOLEAN)) {
    $CFG->sslproxy = true;
}

require_once(__DIR__ . '/lib/setup.php');
