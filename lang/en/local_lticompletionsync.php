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
 * English strings for local_lticompletionsync.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['ackstatus'] = 'Receiver answer';
$string['action_delete'] = 'Delete';
$string['action_disable'] = 'Disable';
$string['action_enable'] = 'Enable';
$string['action_probe'] = 'Check partner';
$string['action_reconcile'] = 'Full reconcile';
$string['action_refreshkeys'] = 'Refresh platform keys';
$string['action_requeue'] = 'Requeue dead letters';
$string['action_resync'] = 'Force resync';
$string['action_retrynow'] = 'Retry now';
$string['activity_disable'] = 'Stop provider-managed completion';
$string['activity_enable'] = 'Let the provider manage completion';
$string['activitypage'] = 'Completion sync';
$string['addreceiver'] = 'Add receiver connection (this site is the platform)';
$string['addsender'] = 'Add sender connection (this site is the tool)';
$string['agscapability'] = 'AGS offered by the platform: {$a->progress} of {$a->links} resource links grant line items and scores.';
$string['agslineitemlabel'] = 'Completion (progress only)';
$string['agsprogress'] = 'Tool-only AGS progress without partner plugin';
$string['agsprogress_help'] = 'Only used when the platform has no completion sync plugin. The progress is posted as a standard AGS score (activityProgress, gradingProgress NotReady, no score) to a dedicated progress line item that this plugin creates; the graded line item is never used. The platform creates its own grade item for it, and there is no guarantee that the platform marks its activity as complete. Off by default.';
$string['allowprivatetargets'] = 'Allow private targets (test labs only)';
$string['allowprivatetargets_desc'] = 'Outgoing requests (completion messages, platform key sets) may go to private, loopback or link-local addresses and to plain http, and DNS pinning is skipped. Never enable this on a production site.';
$string['appliedversion'] = 'Applied version';
$string['automanage'] = 'Manage all activities of this tool automatically';
$string['automanage_help'] = 'If enabled, every external tool activity of this tool type with manual completion tracking is managed as soon as the first completion message arrives (including the learner lockdown). If disabled, a teacher must switch on provider-managed completion per activity.';
$string['backoffbase'] = 'First retry delay';
$string['backoffbase_desc'] = 'Delay before the first retry of a failed delivery. Each further attempt doubles the delay (exponential backoff with jitter).';
$string['backoffmax'] = 'Maximum retry delay';
$string['backoffmax_desc'] = 'Upper limit of the retry delay.';
$string['batchlimit'] = 'Rows per delivery run';
$string['batchlimit_desc'] = 'Maximum number of outbox rows one delivery run (drain task, sweep) processes. The rest waits for the next run.';
$string['cachedef_platformkeys'] = 'Public key sets of LTI platforms (to verify signed acknowledgements)';
$string['clockskew'] = 'Accepted clock skew';
$string['clockskew_desc'] = 'Tolerance for the time claims of signed messages.';
$string['conflictpolicy'] = 'Local changes';
$string['conflictpolicy_help'] = 'What happens when the completion of a managed activity is changed locally (for example a teacher override): "Provider is authoritative" restores the provider state; "Keep local change" keeps it and reports a conflict to the provider.';
$string['conflictpolicy_local'] = 'Keep local change and report a conflict';
$string['conflictpolicy_provider'] = 'Provider is authoritative (restore provider state)';
$string['connection'] = 'Connection';
$string['connections'] = 'Completion sync connections';
$string['connections_intro'] = 'Each LTI 1.3 connection has its own role. As sender (tool side) this site reports the completion of its published resources to the platform. As receiver (platform side) it accepts completion for its external tool activities. A connection is only active after an administrator has confirmed it on both sites.';
$string['correlationid'] = 'Correlation id';
$string['deleteconfirm'] = 'Delete the connection "{$a}" with all its transfer states? Native completion on the platform is not changed.';
$string['deleted'] = 'The connection was deleted.';
$string['deployment'] = 'LTI deployment (published resources)';
$string['deployment_help'] = 'The platform registration and deployment of the "Publish as LTI tool" configuration whose resource links report completion.';
$string['detail'] = 'Detail';
$string['editconnection'] = 'Edit connection';
$string['enabled'] = 'Enabled';
$string['enabledconfirm'] = 'Confirmed and enabled';
$string['enabledconfirm_help'] = 'Administrative confirmation of this connection. Completion is only transferred when the connection is enabled on the sending and on the receiving site.';
$string['endpointurl'] = 'Completion endpoint of the platform';
$string['endpointurl_help'] = 'Normally <platform address>/local/lticompletionsync/endpoint.php. The address must use https and the host of the registered platform issuer. Private addresses are refused unless the test-lab setting allows them.';
$string['env_completionhook'] = 'Core completion hook (5.0+, informational)';
$string['env_diagnostics'] = 'tool_ltidiagnostics installed';
$string['env_endpoint'] = 'Endpoint of this site';
$string['env_moodle'] = 'Moodle release';
$string['env_platformkey'] = 'Platform key (mod_lti) present';
$string['env_privatetargets'] = 'Private targets allowed';
$string['env_pseudonyms'] = 'Pseudonym translation (ltisource_ltipseudonym) available';
$string['env_toolkey'] = 'Tool key (enrol_lti) present';
$string['environment'] = 'Environment';
$string['error_badkeyset'] = 'The key set of the platform could not be read.';
$string['error_badmessage'] = 'The message is malformed.';
$string['error_connection'] = 'Unknown connection.';
$string['error_deployment'] = 'Choose an existing LTI deployment.';
$string['error_nokey'] = 'This site has no LTI tool key.';
$string['error_noreceiver'] = 'There is no receiver connection for the tool of this activity.';
$string['error_policy'] = 'Unknown conflict policy.';
$string['error_role'] = 'Unknown role.';
$string['error_type'] = 'Choose an LTI 1.3 tool type with a client id.';
$string['error_url_blocked'] = 'The address points to a private, local or blocked network.';
$string['error_url_credentials'] = 'The address must not contain credentials.';
$string['error_url_empty'] = 'Enter the address.';
$string['error_url_host'] = 'The host name is invalid.';
$string['error_url_issuerhost'] = 'The endpoint must be on the host of the registered platform issuer.';
$string['error_url_malformed'] = 'The address is malformed.';
$string['error_url_scheme'] = 'The address must use https.';
$string['error_url_toolong'] = 'The address is too long.';
$string['event_acknowledged'] = 'Completion sync: acknowledged';
$string['event_completion_applied'] = 'Completion sync: completion applied';
$string['event_completion_revoked'] = 'Completion sync: completion revoked';
$string['event_dead_letter'] = 'Completion sync: dead letter';
$string['event_http_received'] = 'Completion sync: message received';
$string['event_identity_resolved'] = 'Completion sync: identity resolved';
$string['event_name'] = 'Event';
$string['event_queued'] = 'Completion sync: queued';
$string['event_reconciled'] = 'Completion sync: reconciled';
$string['event_retry_scheduled'] = 'Completion sync: retry scheduled';
$string['event_sent'] = 'Completion sync: sent';
$string['event_source_detected'] = 'Completion sync: source change detected';
$string['filteredby'] = 'Filtered by correlation id {$a}.';
$string['incoming'] = 'Incoming (this site is the platform)';
$string['journal'] = 'Transfer journal';
$string['journalretention'] = 'Journal retention (days)';
$string['journalretention_desc'] = 'Journal entries older than this are deleted by the cleanup task.';
$string['keysrefreshed'] = 'The cached platform key set was discarded.';
$string['label_receiver'] = 'Receiver for tool "{$a}"';
$string['label_sender'] = 'Sender to platform "{$a}"';
$string['level'] = 'Capability level';
$string['level_cooperative'] = 'Cooperative';
$string['level_cooperative_desc'] = 'The platform runs this plugin: manual completion tracking, server-controlled - the external tool sets and revokes the native completion of the external tool activity with signed acknowledgement and reconciliation; learners cannot mark it themselves. An automatic completion condition is not available (open core gate).';
$string['level_nopartner'] = 'No partner plugin';
$string['level_nopartner_desc'] = 'The platform has no completion endpoint. Nothing is transferred; completion is not available on the platform.';
$string['level_nopartnernoags'] = 'No partner plugin, no AGS';
$string['level_nopartnernoags_desc'] = 'The platform has no completion endpoint and did not grant the AGS line item and score scopes. Nothing can be transferred.';
$string['level_partnerdisabled'] = 'Partner not enabled';
$string['level_partnerdisabled_desc'] = 'The platform runs this plugin, but its administrator has not enabled the receiver connection for this tool yet.';
$string['level_partnererror'] = 'Partner not reachable';
$string['level_partnererror_desc'] = 'The last capability check failed. See the journal and the endpoint address.';
$string['level_receiver'] = 'Receiver';
$string['level_receiver_desc'] = 'Manual completion tracking, server-controlled: accepts signed completion messages of this tool for its external tool activities with manual completion tracking and locks the learners\' own toggle. An automatic completion condition is not available (open core gate).';
$string['level_toolonly'] = 'Tool-only (AGS progress)';
$string['level_toolonly_desc'] = 'No partner plugin: progress is posted as a standard AGS score on a dedicated progress line item. No guarantee of native completion and no acknowledgement.';
$string['level_unknown'] = 'Not checked yet';
$string['level_unknown_desc'] = 'The partner has not been checked yet. Use "Check partner".';
$string['lockdown'] = 'Prevent learners from marking the activity themselves';
$string['lockdown_help'] = 'Writes a prohibition of the capability moodle/course:togglecompletion for the authenticated user role in the context of each managed activity, so that learners cannot set or revoke the completion themselves (server-side check of core). An explicit override that existed for that role in the activity before is remembered and written back exactly when the management ends; if an administrator changes the prohibition meanwhile, that decision is kept. Teachers with the override capability can still override; such changes are handled by the conflict policy.';
$string['lockdown_open'] = 'Tracked users who can still mark it themselves';
$string['lockdown_override'] = 'Current override of the authenticated user role';
$string['lockdown_prior'] = 'Override before the lockdown (restored on release)';
$string['lockdown_sideeffect'] = 'Learners see "Completion: set by the external tool" instead of the "Mark as done" button on the activity page. Moodle core offers no hook for the course page and the navigation footer: there the button stays visible, marked as unavailable with an explanatory note; the server refuses the toggle either way.';
$string['lticompletionsync:manageactivity'] = 'Manage provider-managed completion of an external tool activity';
$string['lticompletionsync:manageconnections'] = 'Manage completion sync connections';
$string['lticompletionsync:viewstatus'] = 'View the completion sync status of a course';
$string['ltitype'] = 'LTI 1.3 tool';
$string['ltitype_help'] = 'The external tool (LTI 1.3) whose signed completion messages this site accepts. The tool is authenticated with the public key or key set configured for this tool.';
$string['managed'] = 'Completion managed by the provider';
$string['managednotice_aria'] = 'The completion of "{$a}" is set by the external tool. You cannot mark it as done yourself.';
$string['managednotice_done'] = 'Done (confirmed by the external tool).';
$string['managednotice_explain'] = 'The completion of "{$a}" is set by the external tool when you complete the activity there. You cannot mark it as done yourself here.';
$string['managednotice_inline'] = 'Set by the external tool';
$string['managednotice_title'] = 'Completion:';
$string['managednotice_todo'] = 'Not done yet.';
$string['maxattempts'] = 'Delivery attempts';
$string['maxattempts_desc'] = 'After this many failed attempts a message goes to the dead letter state.';
$string['messagettl'] = 'Message life time';
$string['messagettl_desc'] = 'Validity of a signed message.';
$string['noconnections'] = 'No connections yet.';
$string['outgoing'] = 'Outgoing (this site is the tool)';
$string['partner_disabled'] = 'The partner plugin is present, but the connection is not enabled there.';
$string['partner_error'] = 'The partner could not be checked.';
$string['partner_noplugin'] = 'The platform has no completion sync plugin.';
$string['partner_plugin'] = 'The partner plugin answered.';
$string['partner_unknown'] = 'Not checked.';
$string['pluginname'] = 'LTI completion sync';
$string['privacy:metadata:log'] = 'Transfer journal (event names and codes, no message content).';
$string['privacy:metadata:log:correlationid'] = 'Correlation id of the transfer.';
$string['privacy:metadata:log:eventname'] = 'Name of the transfer event.';
$string['privacy:metadata:log:timecreated'] = 'Time of the event.';
$string['privacy:metadata:log:userid'] = 'The user the transfer was about.';
$string['privacy:metadata:ltiplatform'] = 'Completion states of users of published resources are sent to the LTI platform that launched them.';
$string['privacy:metadata:ltiplatform:state'] = 'Whether the user completed the published resource.';
$string['privacy:metadata:ltiplatform:sub'] = 'The LTI user identifier that the platform itself issued at launch (its user id or a pseudonym).';
$string['privacy:metadata:ltiplatform:version'] = 'Version and time of the completion state.';
$string['privacy:metadata:out'] = 'Completion states of published resources to be reported to the platform.';
$string['privacy:metadata:out:desiredstate'] = 'Completion state reported to the platform.';
$string['privacy:metadata:out:status'] = 'Delivery status.';
$string['privacy:metadata:out:userid'] = 'The user who completed the published resource.';
$string['privacy:metadata:out:version'] = 'Version of the reported state.';
$string['privacy:metadata:rcv'] = 'Completion states received from the tool for external tool activities.';
$string['privacy:metadata:rcv:remotestate'] = 'Completion state reported by the tool.';
$string['privacy:metadata:rcv:remoteversion'] = 'Version of the reported state.';
$string['privacy:metadata:rcv:status'] = 'Processing status.';
$string['privacy:metadata:rcv:subjecthash'] = 'Hash of the LTI user identifier the tool used for the state.';
$string['privacy:metadata:rcv:userid'] = 'The user the state belongs to.';
$string['privacy:metadata:timemodified'] = 'Time of the last change.';
$string['providerstate'] = 'Provider state';
$string['receiver_intro'] = 'This site is the LTI platform. Signed completion messages of the chosen tool are accepted at {$a} and applied to the native completion of its external tool activities (manual completion tracking only).';
$string['receiverdisabled'] = 'The receiver connection for this tool is not enabled.';
$string['reconcilequeued'] = 'A full reconcile was queued.';
$string['report'] = 'Outcome report (last 24 hours)';
$string['report_failed'] = 'Failed (cause)';
$string['report_intro'] = 'Transfers per connection from the journal: confirmed, revoked, failed and retried with their causes. The same report is available as JSON with "cli/sync.php --report".';
$string['report_retried'] = 'Retried (cause)';
$string['report_revoked'] = 'Revoked';
$string['report_success'] = 'Successful';
$string['requeued'] = '{$a} messages were queued again.';
$string['resyncqueued'] = 'A force resync was queued: every target is sent again with a new version.';
$string['retrynowdone'] = '{$a} messages waiting for a retry are due now.';
$string['role'] = 'Role';
$string['role_receiver'] = 'Receiver (platform)';
$string['role_sender'] = 'Sender (tool)';
$string['saved'] = 'Saved.';
$string['sender_intro'] = 'This site is the LTI tool. The completion of resources published with "Publish as LTI tool" is reported to the platform of the chosen deployment, signed with the tool key of this site.';
$string['settings'] = 'Settings';
$string['showall'] = 'Show all';
$string['source'] = 'Source';
$string['state_completed'] = 'Completed';
$string['state_incomplete'] = 'Not completed';
$string['states'] = 'Transfer states';
$string['statuspage'] = 'Completion sync status';
$string['task_cleanup'] = 'Completion sync: cleanup';
$string['task_fullreconcile'] = 'Completion sync: full reconcile';
$string['task_sweep'] = 'Completion sync: delta sweep and retries';
$string['timebudget'] = 'Time per delivery run';
$string['timebudget_desc'] = 'Maximum time one delivery run spends before leaving the remaining rows to the next run.';
$string['unsupported_completion_disabled'] = 'Completion tracking is disabled in the course.';
$string['unsupported_not_lti'] = 'The activity is not an external tool (mod_lti) activity.';
$string['unsupported_tracking_automatic'] = 'The activity uses automatic completion tracking. Only manual completion tracking can be managed by the provider without a core change.';
$string['unsupported_tracking_none'] = 'The activity has no completion tracking.';
$string['unsupported_user_not_tracked'] = 'The user is not tracked for completion in this course.';
$string['version'] = 'Version';
