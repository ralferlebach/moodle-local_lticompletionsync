# Naming (provisional)

## Rules checked

* Frankenstyle: type prefix + `_` + name; the name has lowercase letters and digits only, starts with a letter, no
  underscore (`lticompletionsync` ok).
* DB tables start with the full component name plus `_`. An XMLDB table name (without the site prefix) has at most
  `xmldb_table::NAME_MAX_LENGTH = 63 - PREFIX_MAX_LENGTH(10) = 53` characters in 4.5 and 5.3 (the old limit of 28 no
  longer applies); with `local_lticompletionsync_` (24 characters) the free part is 29 characters. The short suffixes
  below were chosen to stay within the historical 28 as well, which keeps the names readable in SQL tools.
  The tables are `con`, `act`, `out`, `rcv`, `jti`, `log` (27 characters each; asserted by
  `plugin_contract_test::test_install_and_upgrade`). Further tables have ample room.
* Type fit: the plugin is cross-cutting (an endpoint, observers of core events, tasks, an admin register, a teacher page
  in the activity navigation, a course page). `local` is the type for such site-wide additions; the spec (section 1)
  fixes "genau ein installierbares Moodle-Plugin ... `local_lticompletionsync`" and forbids requiring an `ltiservice`
  subplugin.
* Language file name = component (`lang/en/local_lticompletionsync.php`), capability names `local/lticompletionsync:*`.

## Alternatives

| # | Component | Table prefix (free chars) | Pros | Cons |
|---|-----------|---------------------------|------|------|
| 1 | `local_lticompletionsync` | 24 (4) | Name of the spec; says exactly what it does (completion, sync, LTI); family style `lti…`; `local` fits a two-role cross-cutting plugin | Long; 4-character table suffixes only |
| 2 | `local_lticompletion` | 20 (8) | Shorter, more room for tables | "sync" lost: reads like a completion *rule* for mod_lti, which Moodle does not support (CS-G4 step 3) – misleading |
| 3 | `local_ltiprogress` | 18 (10) | Short; matches AGS "activityProgress" | Suggests partial progress / AGS only; the plugin's core level is native completion, not AGS |
| 4 | `tool_lticompletionsync` | 23 (5) | `tool` lists it under *Admin tools*; one more table character | Admin tools are expected to be administrator utilities; this plugin also has a teacher page, an endpoint for machines and event observers |
| 5 | `ltiservice_completionsync` | 26 (2) | Would live next to mod_lti's AGS/NRPS services | Explicitly ruled out by the spec as a required subplugin; mod_lti subplugin cannot carry the tool-side (enrol_lti) role; only 2 table characters |
| 6 | `local_ltisync` | 14 (14) | Very short | Too generic: enrol_lti already has member and grade "sync" |

## Recommendation

Keep **`local_lticompletionsync`** (1): it is the name the spec fixes for the one-package, two-role design, it is
precise, and the table names are far below the 53-character limit. If the owner wants a shorter name for table
reasons, the best alternative is `local_lticompletion` (2), with the drawback that it no longer says "sync".
A rename before the first release is a fresh install (no upgrade path from this 0.1.0 alpha needed).
