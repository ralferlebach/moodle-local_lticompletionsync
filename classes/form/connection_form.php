<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_lticompletionsync\form;

use local_lticompletionsync\local\connection;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form of a connection (sender on the tool side or receiver on the platform side).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection_form extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        global $DB;
        $mform = $this->_form;
        $role = $this->_customdata['role'];
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'role', $role);
        $mform->setType('role', PARAM_ALPHA);

        if ($role === connection::ROLE_RECEIVER) {
            $types = [];
            foreach ($DB->get_records('lti_types', ['ltiversion' => '1.3.0'], 'name ASC', 'id, name, clientid') as $type) {
                $types[$type->id] = format_string($type->name) . ' (' . s($type->clientid) . ')';
            }
            $mform->addElement('select', 'ltitypeid', get_string('ltitype', 'local_lticompletionsync'), $types);
            $mform->addHelpButton('ltitypeid', 'ltitype', 'local_lticompletionsync');
            $mform->addElement('advcheckbox', 'automanage', get_string('automanage', 'local_lticompletionsync'));
            $mform->addHelpButton('automanage', 'automanage', 'local_lticompletionsync');
            $mform->addElement('advcheckbox', 'lockdown', get_string('lockdown', 'local_lticompletionsync'));
            $mform->addHelpButton('lockdown', 'lockdown', 'local_lticompletionsync');
            $mform->setDefault('lockdown', 1);
            $mform->addElement('select', 'conflictpolicy', get_string('conflictpolicy', 'local_lticompletionsync'), [
                connection::POLICY_PROVIDER => get_string('conflictpolicy_provider', 'local_lticompletionsync'),
                connection::POLICY_LOCAL => get_string('conflictpolicy_local', 'local_lticompletionsync'),
            ]);
            $mform->addHelpButton('conflictpolicy', 'conflictpolicy', 'local_lticompletionsync');
        } else {
            $sql = "SELECT d.id, d.name, d.deploymentid, r.name AS regname, r.platformid
                      FROM {enrol_lti_deployment} d
                      JOIN {enrol_lti_app_registration} r ON r.id = d.platformid
                  ORDER BY r.name, d.name";
            $deployments = [];
            foreach ($DB->get_records_sql($sql) as $dep) {
                $deployments[$dep->id] = format_string($dep->regname) . ' / ' . format_string($dep->name) .
                    ' (' . s($dep->platformid) . ')';
            }
            $mform->addElement('select', 'deploymentid', get_string('deployment', 'local_lticompletionsync'), $deployments);
            $mform->addHelpButton('deploymentid', 'deployment', 'local_lticompletionsync');
            $mform->addElement('text', 'endpointurl', get_string('endpointurl', 'local_lticompletionsync'), ['size' => 80]);
            $mform->setType('endpointurl', PARAM_URL);
            $mform->addRule('endpointurl', null, 'required', null, 'client');
            $mform->addHelpButton('endpointurl', 'endpointurl', 'local_lticompletionsync');
            $mform->addElement('advcheckbox', 'agsprogress', get_string('agsprogress', 'local_lticompletionsync'));
            $mform->addHelpButton('agsprogress', 'agsprogress', 'local_lticompletionsync');
        }
        $mform->addElement('advcheckbox', 'enabled', get_string('enabledconfirm', 'local_lticompletionsync'));
        $mform->addHelpButton('enabled', 'enabledconfirm', 'local_lticompletionsync');
        $this->add_action_buttons();
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        foreach (connection::validate((object) $data) as $field => $code) {
            $errors[$field] = get_string($code, 'local_lticompletionsync');
        }
        return $errors;
    }
}
