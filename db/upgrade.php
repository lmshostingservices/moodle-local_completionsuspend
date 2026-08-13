<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_completionsuspend_upgrade($oldversion) {
    if ($oldversion < 2026073100) {
        upgrade_plugin_savepoint(true, 2026073100, 'local', 'completionsuspend');
    }
    return true;
}
