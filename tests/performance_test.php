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

namespace local_lticompletionsync;

use local_lticompletionsync\local\completion_adapter;
use local_lticompletionsync\local\receiver\activity_manager;
use local_lticompletionsync\local\sender\outbox;
use local_lticompletionsync\local\sender\reconciler;
use local_lticompletionsync\local\sender\source;
use local_lticompletionsync\tests\sync_env;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/sync_env.php');

/**
 * CS-04: query counts of the reconciliation paths grow with the number of batches (and of changed targets), not with
 * the number of unchanged users; the lockdown verification is two queries for any course size.
 *
 * The measured large-scale run (10,000+ LTI accounts, 100+ resources, PostgreSQL and MariaDB, EXPLAIN, run time and
 * peak memory) is tools/load/run.sh (results in docs/ACCEPTANCE.md); this test pins the complexity in CI.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\sender\reconciler
 * @covers     \local_lticompletionsync\local\sender\source
 * @covers     \local_lticompletionsync\local\sender\outbox
 * @covers     \local_lticompletionsync\local\completion_adapter
 */
final class performance_test extends \advanced_testcase {
    use sync_env;

    /** @var \stdClass Resource link of the published page. */
    private $rl;

    /**
     * Add LTI accounts of the published page directly (as launches would have created them).
     *
     * @param int $n
     * @param int $completed how many of them completed the page on the tool side
     * @return int[] user ids
     */
    private function add_accounts(int $n, int $completed): array {
        global $DB;
        $ids = [];
        $now = time();
        for ($i = 0; $i < $n; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $luid = $DB->insert_record('enrol_lti_users', (object) [
                'userid' => $user->id, 'toolid' => $this->tresource->id, 'sourceid' => 'sub-' . $user->id,
                'ltideploymentid' => $this->tdeployment->id, 'lastaccess' => $now, 'timecreated' => $now,
            ]);
            $DB->insert_record('enrol_lti_user_resource_link', (object) ['ltiuserid' => $luid,
                'resourcelinkid' => $this->rl->id]);
            if ($i < $completed) {
                $DB->insert_record('course_modules_completion', (object) ['coursemoduleid' => $this->tpage->cmid,
                    'userid' => $user->id, 'completionstate' => COMPLETION_COMPLETE, 'timemodified' => $now]);
            }
            $ids[] = (int) $user->id;
        }
        return $ids;
    }

    /**
     * Reads and writes of a callable.
     *
     * @param callable $fn
     * @return int[] [reads, writes]
     */
    private function cost(callable $fn): array {
        global $DB;
        source::reset_caches();
        $r = $DB->perf_get_reads();
        $w = $DB->perf_get_writes();
        $fn();
        return [$DB->perf_get_reads() - $r, $DB->perf_get_writes() - $w];
    }

    /**
     * Prepare the environment with one real launch (creates the resource link).
     */
    private function prepare(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        $this->launch($this->plearner, $this->tresource);
        $this->rl = $DB->get_record('enrol_lti_resource_link', ['resourceid' => $this->tresource->id], '*', MUST_EXIST);
    }

    /**
     * Full reconcile: an unchanged connection costs the same number of reads for 20 and for 120 accounts (one batch),
     * and per batch a constant number when the batch is small; new versions cost writes per changed target only.
     */
    public function test_full_reconcile_query_count(): void {
        global $DB;
        $this->prepare();
        $this->add_accounts(20, 5);
        [, $writes] = $this->cost(fn() => reconciler::full());
        $this->assertSame(5, $DB->count_records(outbox::TABLE, ['status' => outbox::STATUS_PENDING]));
        $this->assertGreaterThan(0, $writes);
        [$reads20, $writes20] = $this->cost(fn() => reconciler::full());
        $this->assertSame(0, $writes20, 'nothing changed: no write');

        $this->add_accounts(100, 0);
        [$reads120, $writes120] = $this->cost(fn() => reconciler::full());
        $this->assertSame(0, $writes120);
        $this->assertLessThanOrEqual($reads20 + 2, $reads120, 'reads do not grow with unchanged accounts');

        // Small batches: reads grow with the number of batches, a bounded number per batch.
        $batches = (int) ceil(121 / 10);
        [$readssmall] = $this->cost(function () {
            foreach (\local_lticompletionsync\local\connection::all() as $con) {
                if ($con->role === 'sender') {
                    source::each_target_batch($con, fn($targets) => reconciler::check_targets($targets, 'full'), 10);
                }
            }
        });
        $this->assertLessThanOrEqual($batches * 6 + 5, $readssmall);
        // One drain task at most, not one ad hoc task per version.
        $this->assertLessThanOrEqual(1, $DB->count_records_select(
            'task_adhoc',
            'classname = :c',
            ['c' => '\\local_lticompletionsync\\task\\dispatch_task']
        ));
    }

    /**
     * Delta sweep: reads per run do not grow with the number of reported but unchanged targets.
     */
    public function test_sweep_query_count(): void {
        $this->prepare();
        $this->add_accounts(30, 30);
        reconciler::full();
        $since = time() + 10;
        [$reads30, $writes30] = $this->cost(fn() => reconciler::sweep($since));
        $this->assertSame(0, $writes30);
        $this->add_accounts(90, 90);
        reconciler::full();
        [$reads120, $writes120] = $this->cost(fn() => reconciler::sweep($since));
        $this->assertSame(0, $writes120);
        $this->assertLessThanOrEqual($reads30 + 2, $reads120);
    }

    /**
     * Lockdown verification: two queries for 1 or 60 tracked learners (no has_capability() per user).
     */
    public function test_lockdown_verification_is_constant(): void {
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        activity_manager::set_managed($this->pcm, true);
        activity_manager::set_managed($this->pcm, false);
        [$reads1] = $this->cost(fn() => completion_adapter::users_who_can_toggle($this->pcm));
        for ($i = 0; $i < 60; $i++) {
            $this->getDataGenerator()->create_and_enrol($this->pcourse, 'student');
        }
        [$reads61] = $this->cost(fn() => completion_adapter::users_who_can_toggle($this->pcm, 5));
        $this->assertLessThanOrEqual($reads1 + 1, $reads61, 'no query per user');
        $this->assertLessThanOrEqual(6, $reads61);
        $open = completion_adapter::users_who_can_toggle($this->pcm, 5);
        $this->assertSame(61, $open['count']);
        $this->assertCount(5, $open['sample']);
        // Locked down: nobody, and core's capability join answers without a user query.
        activity_manager::set_managed($this->pcm, true);
        [$readslocked] = $this->cost(fn() => completion_adapter::users_who_can_toggle($this->pcm));
        $this->assertSame(['count' => 0, 'sample' => []], completion_adapter::users_who_can_toggle($this->pcm));
        $this->assertLessThanOrEqual(6, $readslocked);
    }

    /**
     * Delivery runs are bounded by the batch limit; the rest waits (no endless task chain).
     */
    public function test_delivery_run_is_bounded(): void {
        global $DB;
        $this->prepare();
        $this->add_accounts(25, 25);
        reconciler::full();
        $this->assertSame(25, $DB->count_records(outbox::TABLE, ['status' => outbox::STATUS_PENDING]));
        $http = new class implements \local_lticompletionsync\local\net\http_client {
            /** @var int */
            public $posts = 0;

            /**
             * Down.
             *
             * @param string $url
             * @param string $body
             * @param string $contenttype
             * @return \local_lticompletionsync\local\net\http_response
             */
            public function post(string $url, string $body, string $contenttype): \local_lticompletionsync\local\net\http_response {
                $this->posts++;
                return new \local_lticompletionsync\local\net\http_response(0, '', '', 'transport_7');
            }

            /**
             * Down.
             *
             * @param string $url
             * @return \local_lticompletionsync\local\net\http_response
             */
            public function get(string $url): \local_lticompletionsync\local\net\http_response {
                return new \local_lticompletionsync\local\net\http_response(0, '', '', 'transport_7');
            }
        };
        $channel = new \local_lticompletionsync\local\sender\channel($http);
        $dispatcher = new \local_lticompletionsync\local\sender\dispatcher($channel);
        $this->assertSame(10, $dispatcher->deliver_due(10));
        // The partner is unreachable: one probe and one delivery attempt per run, the other rows wait for their retry
        // without a request (bounded load during an outage).
        $this->assertSame(2, $http->posts);
        $this->assertSame(10, $DB->count_records(outbox::TABLE, ['status' => outbox::STATUS_RETRY]));
        $this->assertSame(15, $DB->count_records(outbox::TABLE, ['status' => outbox::STATUS_PENDING]));
    }
}
