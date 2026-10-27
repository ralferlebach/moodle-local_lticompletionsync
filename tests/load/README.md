# Load tests

Out of scope for the 0.1.0 MVP. The plugin adds no learner-facing page; its load is one small insert and one deduplicated
ad hoc task per completion change on the tool side, and one signed POST per state version to the platform endpoint
(no session, 16 KiB limit). Concurrency is covered functionally instead: per-row locks for deliveries
(`loopback_flow_test::test_parallel_delivery_is_locked`), unique indexes plus optimistic version checks for parallel
state changes (`state_test::test_outbox_superseded_result_is_dropped`), replay table with a unique index. A load test
would measure Moodle's completion API and web server, not this plugin.
