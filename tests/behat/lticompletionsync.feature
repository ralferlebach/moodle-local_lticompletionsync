@local @local_lticompletionsync
Feature: Completion sync connections and provider-managed completion of external tool activities
  In order to receive the completion of an LTI tool natively in an external tool activity
  As an administrator and as a teacher
  I need to confirm the connection, opt an activity in and see the transfer state, while learners cannot self-mark it

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Stella    | Student  | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "mod_lti > tool types" exist:
      | name       | baseurl                               | coursevisible | state | lti_ltiversion | lti_keytype | lti_publickeyset                    |
      | Peer tool  | https://tool.example.org/enrol/lti/launch.php | 2     | 1     | 1.3.0          | JWK_KEYSET  | https://tool.example.org/enrol/lti/jwks.php |

  Scenario: The administrator sees the empty register, the environment and both roles
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > LTI completion sync > Completion sync connections" in site administration
    Then I should see "No connections yet."
    And I should see "Add sender connection (this site is the tool)"
    And I should see "Add receiver connection (this site is the platform)"
    And I should see "Endpoint of this site"
    And I should see "Transfer journal"

  Scenario: The administrator confirms a receiver connection and can disable it again
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > LTI completion sync > Completion sync connections" in site administration
    When I press "Add receiver connection (this site is the platform)"
    And I set the following fields to these values:
      | LTI 1.3 tool              | Peer tool |
      | Confirmed and enabled     | 1         |
    And I press "Save changes"
    Then I should see "Receiver for tool \"Peer tool\""
    And I should see "Manual completion tracking, server-controlled"
    And I should see "An automatic completion condition is not available (open core gate)."
    And I press "Disable"
    And I should see "Saved."

  Scenario: A sender connection needs a deployment and a safe https endpoint
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > LTI completion sync > Completion sync connections" in site administration
    When I press "Add sender connection (this site is the tool)"
    And I set the following fields to these values:
      | Completion endpoint of the platform | http://127.0.0.1/local/lticompletionsync/endpoint.php |
    And I press "Save changes"
    Then I should see "Choose an existing LTI deployment."
    And I should see "The address must use https."

  Scenario: A teacher lets the provider manage a manual-completion activity and learners are locked out server side
    Given the following "local_lticompletionsync > receiver connections" exist:
      | tool      | automanage |
      | Peer tool | 0          |
    And the following "mod_lti > tool instances" exist:
      | name         | course | tool      | completion |
      | Peer content | C1     | Peer tool | 1          |
    And I am on the "Peer content" "lti activity" page logged in as "teacher1"
    When I navigate to "Completion sync" in current page administration
    Then I should see "Completion managed by the provider"
    And I should see "instead of the \"Mark as done\" button on the activity page"
    And I press "Let the provider manage completion"
    And I should see "Saved."
    And the following should exist in the "generaltable" table:
      | -1-                                            | -2- |
      | Completion managed by the provider             | Yes |
      | Prevent learners from marking the activity themselves | Yes |
      | Tracked users who can still mark it themselves | 0   |
    And I press "Stop provider-managed completion"
    And the following should exist in the "generaltable" table:
      | -1-                                            | -2- |
      | Completion managed by the provider             | No  |

  Scenario: A locked-down learner sees the completion state set by the tool instead of a dead-end button
    Given the following "local_lticompletionsync > receiver connections" exist:
      | tool      | automanage |
      | Peer tool | 0          |
    And the following "mod_lti > tool instances" exist:
      | name         | course | tool      | completion |
      | Peer content | C1     | Peer tool | 1          |
      | Free content | C1     | Peer tool | 1          |
    And I am on the "Peer content" "lti activity" page logged in as "teacher1"
    And I navigate to "Completion sync" in current page administration
    And I press "Let the provider manage completion"
    And I log out
    When I am on the "Peer content" "lti activity" page logged in as "student1"
    Then I should see "Not done yet." in the "[data-region=local-lticompletionsync-managed]" "css_element"
    And I should see "is set by the external tool when you complete the activity there. You cannot mark it as done yourself here."
    # No control in the activity header (4.5) or the page header actions (5.x). The 5.3 linear navigation footer renders
    # its own control without a hook (documented boundary; annotated by JavaScript, covered by Playwright).
    And "Mark as done" "button" should not exist in the ".activity-header" "css_element"
    And "Mark as done" "button" should not exist in the "#page-header" "css_element"
    And I am on the "Free content" "lti activity" page
    And "Mark as done" "button" should exist
    And I should not see "You cannot mark it as done yourself here."

  Scenario: An activity with automatic completion tracking is refused with the reason
    Given the following "local_lticompletionsync > receiver connections" exist:
      | tool      |
      | Peer tool |
    And the following "mod_lti > tool instances" exist:
      | name      | course | tool      | completion | completionview |
      | Auto peer | C1     | Peer tool | 2          | 1              |
    And I am on the "Auto peer" "lti activity" page logged in as "teacher1"
    When I navigate to "Completion sync" in current page administration
    Then I should see "Only manual completion tracking can be managed by the provider without a core change."
    And "Let the provider manage completion" "button" should not exist

  Scenario: Learners do not get the management page, teachers get the course status page
    Given the following "mod_lti > tool instances" exist:
      | name         | course | tool      | completion |
      | Peer content | C1     | Peer tool | 1          |
    When I am on the "Peer content" "lti activity" page logged in as "student1"
    Then I should not see "Completion sync"
    And I log out
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I navigate to "Completion sync status" in current page administration
    And I should see "Outgoing (this site is the tool)"
    And I should see "Incoming (this site is the platform)"
