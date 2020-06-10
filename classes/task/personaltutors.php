<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A scheduled task for scripted database integrations.
 *
 * @package    local_personaltutors - template
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_personaltutors\task;
use stdClass;
use context_user;

defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot.'/local/extdb/classes/task/extdb.php');

/**
 * A scheduled task for scripted external database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class personaltutors extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'local_personaltutors');
    }

    /**
     * Run sync.
     */
    public function execute() {

        global $CFG, $DB;

        $externaldb = new \local_extdb\extdb();
        $name = $externaldb->get_name();

        $externaldbtype = $externaldb->get_config('dbtype');
        $externaldbhost = $externaldb->get_config('dbhost');
        $externaldbname = $externaldb->get_config('dbname');
        $externaldbencoding = $externaldb->get_config('dbencoding');
        $externaldbsetupsql = $externaldb->get_config('dbsetupsql');
        $externaldbsybasequoting = $externaldb->get_config('dbsybasequoting');
        $externaldbdebugdb = $externaldb->get_config('dbdebugdb');
        $externaldbuser = $externaldb->get_config('dbuser');
        $externaldbpassword = $externaldb->get_config('dbpass');
        $tabletut = get_string('tutorstable', 'local_personaltutors');

        // Database connection and setup checks.
        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$externaldbtype) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $externaldbtype . '<br>';
        }
        // Check remote tutors table.
        if (!$tabletut) {
            echo 'Tutors Table not defined.<br>';
            return 0;
        } else {
            echo 'Tutors Table: ' . $tabletut . '<br>';
        }
        echo 'Starting connection...<br>';

        // Report connection error if occurs.
        if (!$extdb = $externaldb->db_init(
            $externaldbtype,
            $externaldbhost,
            $externaldbuser,
            $externaldbpassword,
            $externaldbname)) {
            echo 'Error while communicating with external database <br>';
            return 1;
        }

        /* WHAT NEEDS TO HAPPEN *
         * ******************** */

        // Get roles as array.
        $sqlroles = 'SELECT shortname,id FROM {role}';
        $roles = $DB->get_records_sql($sqlroles);
        // Get all students as array.
        $sqlusers = 'SELECT username,id FROM {user}';
        $users = $DB->get_records_sql($sqlusers);

        // Get tutors, tutees and roles.

        // Ensure array is empty.
        $perstutor = array();

        // Fetch from external database.
        $sql = $externaldb->db_get_sql($tabletut, array(), array(), true);
        // Read database results into usable array.
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $externaldb->db_decode($fields);
                    $perstutor[] = $fields;
                }
            }
            $rs->Close();
        } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external assessments table<br>';
            return 4;
        }

        foreach ($perstutor as $pt) {
            echo $pt['student_username']. ' : '.$pt['mentor_username']. ' : '. $pt['mentor_role']."\n";
            if (isset($users[$pt['student_username']]) && isset($users[$pt['mentor_username']])) {

                if ($users[$pt['student_username']]) {
                    $tuteeid = $users[$pt['student_username']]->id;
                }
                if ($users[$pt['mentor_username']]) {
                    $mentorid = $users[$pt['mentor_username']]->id;
                }
                if ($DB->record_exists('user', array('id' => $tuteeid, 'deleted' => 0)) &&
                    $DB->record_exists('user', array('id' => $mentorid, 'deleted' => 0))) {
                    $tuteecontextr = context_user::instance($tuteeid);
                    $tuteecontext = $tuteecontextr->id;
                    $tutorid = $users[$pt['mentor_username']]->id;
                    $role = $roles[$pt['mentor_role']]->id;
                    $modifierid = 0;
                    $component = 'local_personaltutors';
                echo $role.':'.$tuteecontext.':'.$tutorid."\n";

                if ($role && $tuteecontext && $tutorid) {
                    $roleassign = array();
                    // Check if exists.
                    // If not write it.

                    if (!$DB->record_exists('role_assignments',
                        array('roleid' => $role, 'contextid' => $tuteecontext, 'userid' => $tutorid))) {
                        $roleassign['roleid'] = $role;
                        $roleassign['contextid'] = $tuteecontext;
                        $roleassign['userid'] = $tutorid;
                        $roleassign['timemodified'] = time();
                        $roleassign['modifier'] = 0;
                        $roleassign['itemid'] = 0;
                        $roleassign['sortorder'] = 0;
                        $roleassign['component'] = 'local_personaltutors';

                        print_r($roleassign);

                        $DB->insert_record('role_assignments', $roleassign);
                        echo 'Written ? <br>';
                    }
                }
            }
            }
        }

        // Free memory.
        $extdb->Close();
    }

}
