moodle-local_lticompletionsync
==============================

[![Moodle Plugin CI](https://github.com/ralferlebach/moodle-local_lticompletionsync/actions/workflows/moodle-plugin-ci-main.yml/badge.svg?branch=main)](https://github.com/ralferlebach/moodle-local_lticompletionsync/actions?query=branch%3Amain)

Transfers the completion of a resource published with *Publish as LTI tool* back to the platform as **native activity
completion of the same external tool (mod_lti) activity** – including revocation, versioned state, signed end-to-end
acknowledgement and reconciliation – and **never creates, changes or deletes a grade**. One plugin, installed on both
Moodle sites; each LTI 1.3 connection decides whether this site is sender (tool), receiver (platform) or both.

Status: alpha (0.1.0). See `docs/ACCEPTANCE.md` for what is proven and what is not, and `docs/DOCUMENTATION.md` for the
no-fork gate CS-G4: native completion works for activities with **manual** completion tracking (learners are locked out
server side); automatic completion tracking cannot be driven without a core change and is refused explicitly.


Requirements
------------

This plugin requires Moodle 4.5+ (tested on 4.5 and 5.3) and PHP 8.1+. It uses the core plugins enrol_lti and auth_lti
(tool side) and mod_lti (platform side); no other plugin is needed.


Motivation for this plugin
--------------------------

LTI Advantage has no channel for "completed" that a Moodle platform turns into activity completion: AGS can carry
`activityProgress`, but mod_lti does not map it to completion, and a score without `scoreGiven` may clear an existing
grade on the same line item. Moodle-to-Moodle setups (e.g. a central content site serving several course sites) need the
completion of the tool's resource to count in the platform course – for course completion, restrictions and reports –
without touching the gradebook.


Installation
------------

Install the plugin like any other plugin to folder

    local/lticompletionsync

on **both** sites. See http://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins.


Usage & Settings
----------------

1. Platform site: *Site administration > Plugins > Local plugins > LTI completion sync > Completion sync connections* –
   add a **receiver** connection for the LTI 1.3 tool, confirm it, choose automatic management, learner lockdown and the
   conflict policy. External tool activities must use **manual** completion tracking.
2. Tool site: same page – add a **sender** connection for the platform's enrol_lti deployment with the platform endpoint
   `https://<platform>/local/lticompletionsync/endpoint.php`, confirm it, "Check partner".
3. Learners launch the external tool activity; when they complete the published activity (or course) on the tool site,
   the activity on the platform becomes complete; a revocation on the tool site makes it incomplete again.
4. Teachers: *External tool activity > More > Completion sync* (opt in, lockdown state, received states);
   *Course > More > Completion sync status* (outgoing and incoming states, journal).

Settings: allow private targets (test labs only), delivery attempts, retry delays, message life time, clock skew,
journal retention.


Capabilities
------------

* **local/lticompletionsync:manageconnections** – manage the connection register, reconcile, requeue (manager; RISK_CONFIG).
* **local/lticompletionsync:manageactivity** – switch provider-managed completion and its learner lockdown for one
  external tool activity (editing teacher, manager; RISK_CONFIG).
* **local/lticompletionsync:viewstatus** – see the transfer states of a course (teachers, manager; RISK_PERSONAL).


Scheduled Tasks
---------------

* **\local_lticompletionsync\task\sweep_task** – every 5 minutes: delta sweep (revocations without an event, missed
  events) and due retries.
* **\local_lticompletionsync\task\full_reconcile_task** – daily: full reconcile of all sender connections.
* **\local_lticompletionsync\task\cleanup_task** – daily: expired replay entries, old journal rows.

Ad hoc tasks: delivery after each change, re-assert after a local change (provider policy), full reconcile on request,
lockdown after restore/duplication. CLI: `php local/lticompletionsync/cli/sync.php --help`.


How this plugin works / Pitfalls
--------------------------------

* Native completion is set with core's `completion_info::update_state()` on activities with manual completion tracking;
  learners are prevented from toggling it by a capability prohibition in the activity (core still shows the button; the
  click fails). No core change, no direct table writes, no grade operation.
* The channel is a documented LTI extension (not an AGS endpoint) authenticated with the LTI 1.3 keys both sites already
  trust (enrol_lti tool key, mod_lti platform key); replay protection, audience/deployment checks and SSRF guard included.
* Without the plugin on the platform, the sender declares "No partner plugin"; an optional tool-only level posts AGS
  progress to a dedicated line item (no guarantee of native completion).
* With ltisource_ltipseudonym the pseudonymous `sub` is translated through that plugin's read-only service; rotations
  fail closed.


Theme support
-------------

This plugin acts behind the scenes (administration and status pages), therefore it should work with all Moodle themes.
It is developed and tested on Moodle Core's Boost theme.


The LTI plugin family
---------------------

This plugin belongs to a family of LTI plugins. **Each plugin is independently installable and does not depend on
another one.** They talk to each other, if at all, only through Moodle events and read-only services.

| Plugin | Purpose |
|--------|---------|
| local_ltiidentity | Identity profile for LTI users |
| ltisource_ltipseudonym | Scoped (pseudonymised) identity in the launch |
| local_ltiaccountmerge | Reconciliation of accounts created by LTI launches |
| local_ltixsembed | Cross-site embedding of LTI content |
| tool_ltidiagnostics | Diagnostics of LTI connections (reads this plugin's events) |
| local_lticompletionsync (this plugin) | Native completion transfer from LTI tool to platform |
| tool_ltigroupautoenrol (existing) | Automatic enrolment of LTI users into groups |

The name is provisional, see `docs/NAMING.md`.


Plugin repositories
-------------------

This plugin is not published in the Moodle plugins repository.

The latest development version can be found on Github:
https://github.com/ralferlebach/moodle-local_lticompletionsync


Bug and problem reports / Support requests
------------------------------------------

Please report bugs and problems on Github:
https://github.com/ralferlebach/moodle-local_lticompletionsync/issues

Please note that due to limited resources we can't always provide per-case support.


Feature proposals
-----------------

Please issue feature proposals on Github:
https://github.com/ralferlebach/moodle-local_lticompletionsync/issues

Pull requests are welcome: https://github.com/ralferlebach/moodle-local_lticompletionsync/pulls


Moodle release support
----------------------

Supported: Moodle 4.5 (LTS) to 5.3 (`$plugin->supported = [405, 503]`). Support means a passing test on that version,
see `docs/ACCEPTANCE.md`.


Translating this plugin
-----------------------

The plugin ships English and German language packs. Other languages should be contributed through AMOS (https://lang.moodle.org).


Right-to-left support
---------------------

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.


Maintainers
-----------

Ralf Erlebach


Copyright
---------

The copyright of this plugin is held by Ralf Erlebach.

Individual copyrights of individual developers are tracked in PHPDoc comments and Git commits.
