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

/**
 * CLI: probe partners, run a delta sweep or a full reconcile, deliver due rows, show counts.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\sender\channel;
use local_lticompletionsync\local\sender\dispatcher;
use local_lticompletionsync\local\sender\reconciler;
use local_lticompletionsync\local\status_report;

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'probe' => false, 'sweep' => false, 'full' => false, 'deliver' => false, 'status' => false,
        'connection' => 0],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}
$anyaction = $options['probe'] || $options['sweep'] || $options['full'] || $options['deliver'] || $options['status'];
if ($options['help'] || !$anyaction) {
    echo "LTI completion sync maintenance.

Options:
  --probe            Capability handshake with the partner of every enabled sender connection
  --sweep            Delta sweep (changes without events, revocations)
  --full             Full reconcile
  --deliver          Deliver all due outbox rows now
  --status           Print connections with level and state counts
  --connection=ID    Restrict --probe/--full to one connection
  -h, --help         This help
";
    exit(0);
}
\core\cron::setup_user();
$only = (int) $options['connection'];
if ($options['probe']) {
    foreach (connection::all() as $con) {
        if ($con->role === connection::ROLE_SENDER && $con->enabled && (!$only || (int) $con->id === $only)) {
            cli_writeln('probe ' . $con->id . ': ' . (new channel())->probe($con));
        }
    }
}
if ($options['sweep']) {
    cli_writeln('sweep: ' . reconciler::sweep(0) . ' new versions');
}
if ($options['full']) {
    cli_writeln('full: ' . reconciler::full($only) . ' new versions');
}
if ($options['deliver']) {
    cli_writeln('deliver: ' . (new dispatcher())->deliver_due() . ' rows');
}
if ($options['status']) {
    foreach (connection::all() as $con) {
        $level = status_report::level($con);
        $counts = [];
        foreach (status_report::counts($con) as $status => $count) {
            $counts[] = $status . '=' . $count;
        }
        cli_writeln(implode("\t", [$con->id, $con->role, $con->enabled ? 'enabled' : 'disabled', $level['key'],
            implode(',', $counts)]));
    }
}
