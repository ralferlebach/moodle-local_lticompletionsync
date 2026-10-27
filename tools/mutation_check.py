#!/usr/bin/env python3
"""Mutation checks for the key guarantees of local_lticompletionsync.

Each mutation breaks one guarantee in the plugin source, runs the PHPUnit tests that must detect it (through the
shared runner `ltirun`), and restores the file. A mutation is KILLED when the tests fail, SURVIVED when they pass.
Every mutation must be killed.

    python3 tools/mutation_check.py [--tree 53] [--db pgsql]

Never run this while another process mirrors the plugin: the mutated file is mirrored into the Moodle tree.
"""

import argparse
import os
import subprocess
import sys

PLUGIN = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

MUTATIONS = [
    {
        'name': 'grade untouched (CS-G3): the adapter writes a grade',
        'file': 'classes/local/completion_adapter.php',
        'old': "        $completion->update_state($cm, $completed ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE, $userid);",
        'new': "        $completion->update_state($cm, $completed ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE, $userid);\n"
               "        require_once($GLOBALS['CFG']->libdir . '/gradelib.php');\n"
               "        grade_update('mod/lti', $cm->course, 'mod', 'lti', $cm->instance, 0,\n"
               "            [$userid => (object) ['userid' => $userid, 'rawgrade' => $completed ? 100 : 0]]);",
        'filter': 'cs_g4_gate_test|loopback_flow_test::test_completion_arrives',
    },
    {
        'name': 'revocation (CS-G5): a provider state 0 is never recorded',
        'file': 'classes/local/sender/outbox.php',
        'old': "                if ((int) $row->desiredstate === $state) {",
        'new': "                if ((int) $row->desiredstate === $state || $state === 0) {",
        'filter': 'loopback_flow_test::test_revocation|state_test::test_outbox_versions',
    },
    {
        'name': 'replay protection (CS-G5): every jti is accepted',
        'file': 'classes/local/receiver/endpoint.php',
        'old': "        if ($DB->record_exists(self::JTI_TABLE, ['jtihash' => $hash])) {\n            return false;\n        }",
        'new': "        if ($DB->record_exists(self::JTI_TABLE, ['jtihash' => $hash])) {\n            return true;\n        }",
        'filter': 'loopback_flow_test::test_replay',
    },
    {
        'name': 'out-of-order (CS-G5): an older version is applied',
        'file': 'classes/local/receiver/processor.php',
        'old': "        if ($msg['version'] < (int) $row->remoteversion) {",
        'new': "        if (false) {",
        'filter': 'loopback_flow_test::test_out_of_order',
    },
    {
        'name': 'fail closed (pseudonyms): the local id is accepted under a pseudonymising policy',
        'file': 'classes/local/receiver/identity_resolver.php',
        'old': "                if ($ctx && $ctx->pseudonymising()) {",
        'new': "                if ($ctx && $ctx->pseudonymising() && !ctype_digit($sub)) {",
        'filter': 'loopback_flow_test::test_pseudonym',
    },
    {
        'name': 'learner lockdown (CS-G4 step 2): CAP_PREVENT instead of CAP_PROHIBIT',
        'file': 'classes/local/completion_adapter.php',
        'old': "        assign_capability(self::TOGGLE_CAPABILITY, CAP_PROHIBIT, $roleid, $context->id, true);",
        'new': "        assign_capability(self::TOGGLE_CAPABILITY, CAP_PREVENT, $roleid, $context->id, true);\n"
               "        unassign_capability(self::TOGGLE_CAPABILITY, $roleid, $context->id);",
        'filter': 'cs_g4_gate_test::test_step2_lockdown|loopback_flow_test::test_completion_arrives',
    },
    {
        'name': 'authentication: the audience is not checked',
        'file': 'classes/local/receiver/endpoint.php',
        'old': "            !$audok || ($claims['sub'] ?? null) !== $clientid",
        'new': "            ($claims['sub'] ?? null) !== $clientid",
        'filter': 'loopback_flow_test::test_authentication_negatives',
    },
]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--tree', default='53')
    parser.add_argument('--db', default='pgsql')
    args = parser.parse_args()
    results = []
    for m in MUTATIONS:
        path = os.path.join(PLUGIN, m['file'])
        with open(path, encoding='utf-8') as handle:
            original = handle.read()
        if original.count(m['old']) != 1:
            results.append((m['name'], 'ERROR: anchor not found exactly once'))
            continue
        try:
            with open(path, 'w', encoding='utf-8') as handle:
                handle.write(original.replace(m['old'], m['new']))
            run = subprocess.run(['/home/claude/bin/ltirun', args.tree, args.db, 'local_lticompletionsync', 'phpunit',
                                  '--filter', m['filter']], capture_output=True, text=True)
            verdict = 'KILLED' if run.returncode != 0 else 'SURVIVED'
        finally:
            with open(path, 'w', encoding='utf-8') as handle:
                handle.write(original)
        results.append((m['name'], verdict))
        print(f"{verdict:9s} {m['name']}", flush=True)
    # Mirror the restored sources back into the tree.
    subprocess.run(['/home/claude/bin/ltirun', args.tree, args.db, 'local_lticompletionsync', 'mirror'],
                   capture_output=True)
    survived = [r for r in results if r[1] != 'KILLED']
    print(f"\n{len(results) - len(survived)}/{len(results)} mutations killed")
    sys.exit(1 if survived else 0)


if __name__ == '__main__':
    main()
