# Privacy

Implemented in `classes/privacy/provider.php` (metadata, plugin provider, core_userlist_provider); tested by
`tests/privacy/provider_test.php` on real data of both roles.

| Data | Where | Purpose | Retention |
|---|---|---|---|
| Desired completion state, version, delivery status per (connection, resource link, user) | tool side, `local_lticompletionsync_out` | report the state to the platform, retry, reconcile | while the connection and the published tool exist; deleted with the user, the connection, the tool course, the published tool, the tool's deployment or by a privacy request |
| Received state, versions, processing status, hash of the LTI subject of the version sequence per (connection, activity, user) | platform side, `local_lticompletionsync_rcv` | versioning, idempotency, conflict handling | while the activity / connection exists; deleted with the user, the activity, the course, the connection, the LTI tool type or by a privacy request |
| Transfer journal (event name, correlation id, ids, version, short code) | both, `local_lticompletionsync_log` | diagnosis | `journalretention` days (default 90), cleanup task; deleted with the user, the course or by a privacy request |
| Sent to the platform (external location `ltiplatform`) | message | native completion on the platform | – |

* **No profile data** is copied. The identifier sent to the platform is the LTI `sub` the platform itself issued at launch
  (its own user id or a pseudonym of ltisource_ltipseudonym); the platform resolves it locally.
* Native completion on the platform is core data (`course_modules_completion`), handled by core's privacy provider.
* Contexts: outbox rows belong to the published activity or course, received rows to the LTI activity, journal rows to
  the activity, the course or the system context.
* Export: per context, all fields except internal ids, times as dates.
* Deletion: per user and context, per user list, per context; the `user_deleted` observer removes all rows of a deleted
  user.
* Account merges (tool_mergeusers): the rows follow the kept user, no duplicates (`merge_integration_test`).
* Logs contain codes only (audit redaction, see SECURITY.md); the outcome report shows counts and codes, no names.
