# Security

## Trust model

* A completion message is accepted only from an LTI 1.3 tool type for which the platform administrator **enabled a
  receiver connection**, and only for **activities of that tool type** that are managed (per-activity opt-in or
  "automatic management" of the connection) and use **manual** completion tracking, and only for users who are
  **tracked for completion in that course**. A trusted tool can therefore set or revoke completion of its own activities
  for enrolled learners – the same trust the tool already has for grades through AGS – and nothing else.
* A tool sends only for an **enabled sender connection** (administrative confirmation on the tool side) to the endpoint
  URL registered there, which must be on the host of the registered platform issuer.

## Authentication and integrity

| Threat | Measure | Evidence |
|---|---|---|
| Forged request | JWS RS256 verified by mod_lti's `lti_verify_jwt_signature()` with the public key / key set configured for the tool type; `alg` must be RS256 | `loopback_flow_test::test_authentication_negatives` (tampered payload, foreign key) |
| Token for another receiver / misrouting | `aud` must equal this endpoint URL; `iss = sub = client id`; `deployment_id` must be the type's deployment id | same test (wrong audience, wrong deployment, unknown client); mutation "audience not checked" killed |
| Replay | unique `jti` (16–128 chars) stored as sha256(connection, jti) with a unique index until `exp` + skew; bounded life time (`exp - iat ≤ TTL + skew`, `iat` not in the future) | `test_replay_is_refused`, expired / long-lived cases; mutation "every jti accepted" killed |
| Forged acknowledgement | answer is a JWS of the platform key (mod_lti), verified with the platform key set registered in enrol_lti; `iss`, `aud`, `in_reply_to = request jti` checked; only `applied` with `appliedversion ≥ version` counts | integration tests via the real endpoint; `keys_test` |
| Late / duplicated delivery | monotonic versions per target; older versions refused, same version answered from storage | `test_out_of_order_old_version_is_rejected`, `test_lost_answer_retry_is_idempotent`, E2E; mutation killed |
| Identity confusion | `sub` resolved as local id only without pseudonym policy; with ltisource_ltipseudonym only through its translator; any miss fails closed | `test_unresolvable_identity_fails_closed`, `test_pseudonym_integration_and_rotation`; mutation killed |
| Learner self-marking | CAP_PROHIBIT of `moodle/course:togglecompletion` for the authenticated-user role in the module context (server-side core check, not CSS) | gate tests step 2, E2E click test; mutation killed |

No new key material and no shared secrets: the plugin reuses the keys core already maintains (enrol_lti tool key,
mod_lti platform key) and the trust the administrators already configured.

## SSRF (outgoing requests of the sender: completion endpoint and platform key set)

* `url_guard`: https only, no credentials, no control characters/backslashes, bounded length, literal and named
  private/loopback/link-local/reserved/multicast/CGNAT/documentation ranges refused (IPv4, IPv6, mapped, NAT64, 6to4,
  Teredo, decimal/hex spellings), plus core's `curl_security_helper`.
* DNS pinning: the host is resolved once, every answer must be public, the connection is pinned with `CURLOPT_RESOLVE`,
  and the peer address curl really used is compared with the validated set (`pin_mismatch`).
* No redirects are followed; TLS verification is never switched off; response size and time are bounded.
* The endpoint URL must be on the host of the registered issuer (validated when saving the connection).
* `allowprivatetargets` (off by default) disables the private-range rule and pinning for **test labs only** (the E2E stack
  resolves its hosts to 127.0.0.1).
* Evidence: `url_guard_test` (15 refused URLs, pinning with a rebinding resolver), `state_test::test_connection_validation`.

## Web pages

* Every state-changing action requires `require_sesskey()` and the capability in the right context:
  `manageconnections` (system), `manageactivity` (module), `viewstatus` (course, read only). All parameters through
  `required_param`/`optional_param` with types; forms through the Form API.
* The endpoint has no session (`NO_MOODLE_COOKIES`), accepts POST only, limits the body to 16 KiB, answers with
  `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`, and reports authentication failures with unsigned
  generic codes only.

## Logging

The journal and the Moodle events contain event names, ids, versions, correlation ids and short codes. They never
contain a JWT, an access token, a key, a client assertion or a message payload. Error codes stored in the outbox are
bounded to 64 characters and come from a fixed vocabulary (plus `PARAM_ALPHANUMEXT`-cleaned remote codes).

## Capabilities and risks

| Capability | Context | Risk | Default |
|---|---|---|---|
| `local/lticompletionsync:manageconnections` | system | RISK_CONFIG | manager |
| `local/lticompletionsync:manageactivity` | module | RISK_CONFIG (writes a role override in the module) | editingteacher, manager |
| `local/lticompletionsync:viewstatus` | course | RISK_PERSONAL (names and states) | teacher, editingteacher, manager |
