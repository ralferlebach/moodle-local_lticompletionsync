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

namespace local_lticompletionsync\integration;

use local_lticompletionsync\local\protocol;
use local_lticompletionsync\local\receiver\identity_resolver;
use local_lticompletionsync\local\receiver\processor;
use local_lticompletionsync\local\receiver\rejection;
use local_lticompletionsync\tests\sync_env;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/sync_env.php');

/**
 * Optional integration with ltisource_ltipseudonym: the translator API version is checked with defined() and must be
 * at least 2 (ltisource_ltipseudonym 0.3.0). An older installed plugin fails closed with a declared reason; an absent
 * plugin degrades to plain local user ids; nothing is guessed.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\receiver\identity_resolver
 * @covers     \local_lticompletionsync\local\receiver\processor
 */
final class identity_api_test extends \advanced_testcase {
    use sync_env;

    /**
     * A resolver that reports a given translator API version (0 = plugin absent, 1 = before 0.3.0).
     *
     * @param int $version
     * @return identity_resolver
     */
    private function resolver(int $version): identity_resolver {
        return new class ($version) extends identity_resolver {
            /** @var int */
            private $simulated;

            /**
             * Constructor.
             *
             * @param int $version
             */
            public function __construct(int $version) {
                $this->simulated = $version;
            }

            /**
             * Simulated API version.
             *
             * @return int
             */
            protected function api_version(): int {
                return $this->simulated;
            }
        };
    }

    /**
     * The detection reads the real constant with defined(): 0 without the plugin, its API_VERSION otherwise.
     */
    public function test_detection(): void {
        $class = '\\ltisource_ltipseudonym\\local\\proxy\\translator';
        $expected = !class_exists($class) ? 0 : (defined($class . '::API_VERSION') ? (int) constant($class . '::API_VERSION') : 1);
        $this->assertSame($expected, identity_resolver::translator_api_version());
        $this->assertSame(
            $expected >= identity_resolver::MIN_TRANSLATOR_API && method_exists($class, 'user_of'),
            identity_resolver::pseudonym_service_available()
        );
    }

    /**
     * An installed plugin older than API 2 fails closed for every subject (pseudonym or digits), with a declared reason
     * that the sender treats as permanent (dead letter, requeue after the platform upgraded the plugin).
     */
    public function test_older_plugin_fails_closed(): void {
        $this->resetAfterTest();
        $this->build_env();
        $resolver = $this->resolver(1);
        foreach ([(string) $this->plearner->id, 'pseudonym-abc'] as $sub) {
            try {
                $resolver->resolve($this->ptype, (int) $this->pcourse->id, (int) $this->pcm->id, $sub);
                $this->fail('An old translator must not be used and no local id must be guessed: ' . $sub);
            } catch (rejection $e) {
                $this->assertSame(identity_resolver::REASON_API, $e->reason);
            }
        }
        $this->assertContains(identity_resolver::REASON_API, protocol::PERMANENT_REASONS);

        // Through the receiver: rejected with the reason, native completion unchanged.
        $result = (new processor($resolver))->completion($this->receiver, ['resource_link_id' => (string) $this->pcm->instance,
            'user' => (string) $this->plearner->id, 'state' => 'completed', 'version' => 1, 'timestamp' => time(),
            'correlation_id' => \core\uuid::generate()]);
        $this->assertSame(protocol::ACK_REJECTED, $result['status']);
        $this->assertSame(identity_resolver::REASON_API, $result['reason']);
        $this->assertSame(0, $this->platform_state());
    }

    /**
     * Without the plugin, subjects are plain local user ids; anything else is unresolved.
     */
    public function test_absent_plugin_degrades_to_local_ids(): void {
        $this->resetAfterTest();
        $this->build_env();
        $resolver = $this->resolver(0);
        $this->assertSame(
            (int) $this->plearner->id,
            $resolver->resolve($this->ptype, (int) $this->pcourse->id, (int) $this->pcm->id, (string) $this->plearner->id)
        );
        $this->assertSame('local_id', $resolver->method);
        $this->expectException(rejection::class);
        $resolver->resolve($this->ptype, (int) $this->pcourse->id, (int) $this->pcm->id, 'pseudonym-abc');
    }
}
