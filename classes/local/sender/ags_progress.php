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

namespace local_lticompletionsync\local\sender;

use enrol_lti\local\ltiadvantage\lib\issuer_database;
use enrol_lti\local\ltiadvantage\lib\launch_cache_session;
use enrol_lti\local\ltiadvantage\repository\application_registration_repository;
use enrol_lti\local\ltiadvantage\repository\deployment_repository;
use enrol_lti\local\ltiadvantage\repository\resource_link_repository;
use Packback\Lti1p3\LtiAssignmentsGradesService;
use Packback\Lti1p3\LtiGrade;
use Packback\Lti1p3\LtiLineitem;
use Packback\Lti1p3\LtiRegistration;
use Packback\Lti1p3\LtiServiceConnector;

/**
 * Optional tool-only level (spec section 2 and 4.2): standard AGS progress on a DEDICATED progress line item.
 *
 * A score with activityProgress and gradingProgress = NotReady and WITHOUT scoreGiven is posted to a line item that
 * this plugin creates for the purpose (tag "lticompletionsync-progress"). The coupled, graded line item of the
 * resource link is never used, because AGS lets a platform clear an existing score when scoreGiven is missing.
 * This level gives no guarantee that the platform updates native activity completion, and the platform creates its
 * own gradebook column for the progress line item (documented limitation; off by default).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ags_progress {
    /** @var string Line item tag. */
    public const TAG = 'lticompletionsync-progress';
    /** @var string Scope needed to find or create the progress line item. */
    public const SCOPE_LINEITEM = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';
    /** @var string Scope needed to post a score. */
    public const SCOPE_SCORE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';

    /** @var string Result: posted. */
    public const RESULT_SENT = 'sent';
    /** @var string Result: the platform did not grant the needed AGS capability. */
    public const RESULT_UNSUPPORTED = 'unsupported';
    /** @var string Result: failure (retry). */
    public const RESULT_ERROR = 'error';

    /**
     * The AGS capability set the platform actually granted for the resource links of a connection (what it put into
     * the launches), so that a platform without the partner plugin is judged by what it offers, not by a promise.
     *
     * @param \stdClass $con sender connection
     * @return array links (int), progress (int: links granting the line items service with the lineitem and score
     *               scopes, i.e. where a dedicated progress line item can be created and scored)
     */
    public static function capability_set(\stdClass $con): array {
        global $DB;
        // Few distinct combinations per deployment: group in SQL, judge the (JSON) line item scopes in PHP.
        $rs = $DB->get_recordset_sql(
            "SELECT s.lineitemscope, s.scorescope, s.hasurl, COUNT(1) AS c
               FROM (SELECT lineitemscope, scorescope,
                            CASE WHEN lineitemsservice IS NULL OR lineitemsservice = '' THEN 0 ELSE 1 END AS hasurl
                       FROM {enrol_lti_resource_link}
                      WHERE ltideploymentid = :dep) s
           GROUP BY s.lineitemscope, s.scorescope, s.hasurl",
            ['dep' => (int) $con->deploymentid]
        );
        $result = ['links' => 0, 'progress' => 0];
        foreach ($rs as $row) {
            $result['links'] += (int) $row->c;
            $lineitemscopes = json_decode((string) $row->lineitemscope, true);
            if (
                (int) $row->hasurl === 1 && $row->scorescope === self::SCOPE_SCORE
                && is_array($lineitemscopes) && in_array(self::SCOPE_LINEITEM, $lineitemscopes, true)
            ) {
                $result['progress'] += (int) $row->c;
            }
        }
        $rs->close();
        return $result;
    }

    /**
     * Post the progress of an outbox row.
     *
     * @param \stdClass $row outbox row
     * @return string RESULT_* constant
     */
    public function send(\stdClass $row): string {
        global $CFG, $DB;
        require_once($CFG->libdir . '/filelib.php');
        $rl = (new resource_link_repository())->find((int) $row->resourcelinkid);
        if (!$rl) {
            return self::RESULT_UNSUPPORTED;
        }
        $service = $rl->get_grade_service();
        if (!$service || !$service->get_lineitemsurl()) {
            return self::RESULT_UNSUPPORTED;
        }
        $scopes = $service->get_scopes();
        if (!in_array(self::SCOPE_LINEITEM, $scopes, true) || !in_array(self::SCOPE_SCORE, $scopes, true)) {
            return self::RESULT_UNSUPPORTED;
        }
        $sub = source::subject((int) $row->userid, (int) $row->toolid);
        if ($sub === null) {
            return self::RESULT_UNSUPPORTED;
        }
        try {
            $appregistration = (new application_registration_repository())->find_by_deployment($rl->get_deploymentid());
            if (!$appregistration) {
                return self::RESULT_UNSUPPORTED;
            }
            $issuerdb = new issuer_database(new application_registration_repository(), new deployment_repository());
            $registration = $issuerdb->findRegistrationByIssuer(
                $appregistration->get_platformid()->out(false),
                $appregistration->get_clientid()
            );
            $ags = $this->get_ags($registration, [
                'lineitems' => $service->get_lineitemsurl()->out(false),
                // Deliberately no coupled line item: the graded item of the resource link is never touched.
                'lineitem' => null,
                'scope' => $scopes,
            ]);
            $lineitem = $ags->findOrCreateLineitem(new LtiLineitem([
                'label' => get_string('agslineitemlabel', 'local_lticompletionsync'),
                'scoreMaximum' => 1,
                'tag' => self::TAG,
                'resourceId' => self::TAG . '-' . (int) $row->toolid,
                'resourceLinkId' => $rl->get_resourcelinkid(),
            ]));
            if ($lineitem->getTag() !== self::TAG) {
                return self::RESULT_ERROR;
            }
            $grade = LtiGrade::new()
                ->setUserId($sub)
                ->setActivityProgress($row->desiredstate ? 'Completed' : 'Initialized')
                ->setGradingProgress('NotReady')
                ->setTimestamp(date('Y-m-d\TH:i:sP', (int) $row->statetime));
            $response = $ags->putGrade($grade, $lineitem);
        } catch (\Throwable $e) {
            return self::RESULT_ERROR;
        }
        $status = (int) ($response['status'] ?? 0);
        return in_array($status, [200, 201, 202, 204], true) ? self::RESULT_SENT : self::RESULT_ERROR;
    }

    /**
     * Create the AGS service (tests override this).
     *
     * @param LtiRegistration $registration
     * @param array $servicedata
     * @return LtiAssignmentsGradesService
     */
    protected function get_ags(LtiRegistration $registration, array $servicedata): LtiAssignmentsGradesService {
        $connector = new LtiServiceConnector(new launch_cache_session(), new \core\http_client());
        return new LtiAssignmentsGradesService($connector, $registration, $servicedata);
    }
}
