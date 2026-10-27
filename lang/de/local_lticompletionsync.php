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

/**
 * German strings for local_lticompletionsync.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['ackstatus'] = 'Antwort des Empfängers';
$string['action_delete'] = 'Löschen';
$string['action_disable'] = 'Deaktivieren';
$string['action_enable'] = 'Aktivieren';
$string['action_probe'] = 'Gegenstelle prüfen';
$string['action_reconcile'] = 'Vollständiger Abgleich';
$string['action_requeue'] = 'Unzustellbare erneut einreihen';
$string['activity_disable'] = 'Anbietergesteuerten Abschluss beenden';
$string['activity_enable'] = 'Abschluss durch den Anbieter steuern lassen';
$string['activitypage'] = 'Abschluss-Synchronisation';
$string['addreceiver'] = 'Empfängerverbindung hinzufügen (diese Site ist die Plattform)';
$string['addsender'] = 'Senderverbindung hinzufügen (diese Site ist das Tool)';
$string['agslineitemlabel'] = 'Abschluss (nur Fortschritt)';
$string['agsprogress'] = 'Tool-only-AGS-Fortschritt ohne Partner-Plugin';
$string['agsprogress_help'] = 'Wird nur verwendet, wenn die Plattform kein Completion-Sync-Plugin hat. Der Fortschritt wird als Standard-AGS-Score (activityProgress, gradingProgress NotReady, ohne Punktwert) an ein eigenes Fortschritts-Line-Item gesendet, das dieses Plugin anlegt; das bewertete Line Item wird nie verwendet. Die Plattform legt dafür eine eigene Bewertungsspalte an, und es ist nicht garantiert, dass sie ihre Aktivität als abgeschlossen markiert. Standardmäßig aus.';
$string['allowprivatetargets'] = 'Private Ziele erlauben (nur Testumgebungen)';
$string['allowprivatetargets_desc'] = 'Ausgehende Anfragen (Abschlussnachrichten, Schlüsselsätze der Plattform) dürfen an private, Loopback- oder Link-Local-Adressen und per http gehen; das DNS-Pinning entfällt. Niemals auf einer Produktivsite aktivieren.';
$string['appliedversion'] = 'Angewendete Version';
$string['automanage'] = 'Alle Aktivitäten dieses Tools automatisch steuern';
$string['automanage_help'] = 'Wenn aktiviert, wird jede Aktivität „Externes Tool“ dieses Tooltyps mit manueller Abschlussverfolgung gesteuert, sobald die erste Abschlussnachricht eintrifft (einschließlich der Sperre für Teilnehmende). Wenn deaktiviert, muss eine Lehrperson den anbietergesteuerten Abschluss je Aktivität einschalten.';
$string['backoffbase'] = 'Erste Wartezeit vor Wiederholung';
$string['backoffbase_desc'] = 'Wartezeit vor der ersten Wiederholung einer fehlgeschlagenen Zustellung. Jeder weitere Versuch verdoppelt die Wartezeit (exponentielles Backoff mit Zufallsanteil).';
$string['backoffmax'] = 'Maximale Wartezeit vor Wiederholung';
$string['backoffmax_desc'] = 'Obergrenze der Wartezeit vor einer Wiederholung.';
$string['cachedef_platformkeys'] = 'Öffentliche Schlüsselsätze von LTI-Plattformen (zur Prüfung signierter Bestätigungen)';
$string['clockskew'] = 'Zulässige Uhrabweichung';
$string['clockskew_desc'] = 'Toleranz für die Zeitangaben signierter Nachrichten.';
$string['conflictpolicy'] = 'Lokale Änderungen';
$string['conflictpolicy_help'] = 'Was geschieht, wenn der Abschluss einer gesteuerten Aktivität lokal geändert wird (z. B. Überschreibung durch eine Lehrperson): „Anbieter ist maßgeblich“ stellt den Zustand des Anbieters wieder her; „Lokale Änderung behalten“ behält sie und meldet dem Anbieter einen Konflikt.';
$string['conflictpolicy_local'] = 'Lokale Änderung behalten und Konflikt melden';
$string['conflictpolicy_provider'] = 'Anbieter ist maßgeblich (Anbieterzustand wiederherstellen)';
$string['connection'] = 'Verbindung';
$string['connections'] = 'Verbindungen der Abschluss-Synchronisation';
$string['connections_intro'] = 'Jede LTI-1.3-Verbindung hat ihre eigene Rolle. Als Sender (Tool-Seite) meldet diese Site den Abschluss ihrer veröffentlichten Ressourcen an die Plattform. Als Empfänger (Plattform-Seite) nimmt sie den Abschluss für ihre Aktivitäten „Externes Tool“ entgegen. Eine Verbindung ist erst aktiv, wenn sie auf beiden Sites administrativ bestätigt wurde.';
$string['correlationid'] = 'Korrelations-ID';
$string['deleteconfirm'] = 'Die Verbindung „{$a}“ mit allen Übertragungszuständen löschen? Der native Abschluss auf der Plattform wird nicht verändert.';
$string['deleted'] = 'Die Verbindung wurde gelöscht.';
$string['deployment'] = 'LTI-Deployment (veröffentlichte Ressourcen)';
$string['deployment_help'] = 'Die Plattformregistrierung und das Deployment der Konfiguration „Als LTI-Tool veröffentlichen“, deren Ressourcenlinks den Abschluss melden.';
$string['detail'] = 'Detail';
$string['editconnection'] = 'Verbindung bearbeiten';
$string['enabled'] = 'Aktiviert';
$string['enabledconfirm'] = 'Bestätigt und aktiviert';
$string['enabledconfirm_help'] = 'Administrative Bestätigung dieser Verbindung. Der Abschluss wird nur übertragen, wenn die Verbindung auf der sendenden und auf der empfangenden Site aktiviert ist.';
$string['endpointurl'] = 'Abschluss-Endpunkt der Plattform';
$string['endpointurl_help'] = 'Normalerweise <Plattformadresse>/local/lticompletionsync/endpoint.php. Die Adresse muss https und den Host des registrierten Plattform-Issuers verwenden. Private Adressen werden abgelehnt, sofern die Testumgebungs-Einstellung sie nicht erlaubt.';
$string['env_completionhook'] = 'Core-Abschluss-Hook (ab 5.0, nur informativ)';
$string['env_diagnostics'] = 'tool_ltidiagnostics installiert';
$string['env_endpoint'] = 'Endpunkt dieser Site';
$string['env_moodle'] = 'Moodle-Version';
$string['env_platformkey'] = 'Plattformschlüssel (mod_lti) vorhanden';
$string['env_privatetargets'] = 'Private Ziele erlaubt';
$string['env_pseudonyms'] = 'Pseudonym-Übersetzung (ltisource_ltipseudonym) verfügbar';
$string['env_toolkey'] = 'Toolschlüssel (enrol_lti) vorhanden';
$string['environment'] = 'Umgebung';
$string['error_badkeyset'] = 'Der Schlüsselsatz der Plattform konnte nicht gelesen werden.';
$string['error_badmessage'] = 'Die Nachricht ist fehlerhaft.';
$string['error_connection'] = 'Unbekannte Verbindung.';
$string['error_deployment'] = 'Wählen Sie ein vorhandenes LTI-Deployment.';
$string['error_nokey'] = 'Diese Site hat keinen LTI-Toolschlüssel.';
$string['error_noreceiver'] = 'Für das Tool dieser Aktivität gibt es keine Empfängerverbindung.';
$string['error_policy'] = 'Unbekannte Konfliktregel.';
$string['error_role'] = 'Unbekannte Rolle.';
$string['error_type'] = 'Wählen Sie einen LTI-1.3-Tooltyp mit Client-ID.';
$string['error_url_blocked'] = 'Die Adresse zeigt in ein privates, lokales oder gesperrtes Netz.';
$string['error_url_credentials'] = 'Die Adresse darf keine Zugangsdaten enthalten.';
$string['error_url_empty'] = 'Geben Sie die Adresse ein.';
$string['error_url_host'] = 'Der Hostname ist ungültig.';
$string['error_url_issuerhost'] = 'Der Endpunkt muss auf dem Host des registrierten Plattform-Issuers liegen.';
$string['error_url_malformed'] = 'Die Adresse ist fehlerhaft.';
$string['error_url_scheme'] = 'Die Adresse muss https verwenden.';
$string['error_url_toolong'] = 'Die Adresse ist zu lang.';
$string['event_acknowledged'] = 'Abschluss-Synchronisation: bestätigt';
$string['event_completion_applied'] = 'Abschluss-Synchronisation: Abschluss gesetzt';
$string['event_completion_revoked'] = 'Abschluss-Synchronisation: Abschluss zurückgenommen';
$string['event_dead_letter'] = 'Abschluss-Synchronisation: unzustellbar';
$string['event_http_received'] = 'Abschluss-Synchronisation: Nachricht empfangen';
$string['event_identity_resolved'] = 'Abschluss-Synchronisation: Identität aufgelöst';
$string['event_name'] = 'Ereignis';
$string['event_queued'] = 'Abschluss-Synchronisation: eingereiht';
$string['event_reconciled'] = 'Abschluss-Synchronisation: abgeglichen';
$string['event_retry_scheduled'] = 'Abschluss-Synchronisation: Wiederholung geplant';
$string['event_sent'] = 'Abschluss-Synchronisation: gesendet';
$string['event_source_detected'] = 'Abschluss-Synchronisation: Änderung der Quelle erkannt';
$string['filteredby'] = 'Gefiltert nach Korrelations-ID {$a}.';
$string['incoming'] = 'Eingehend (diese Site ist die Plattform)';
$string['journal'] = 'Übertragungsprotokoll';
$string['journalretention'] = 'Aufbewahrung des Protokolls (Tage)';
$string['journalretention_desc'] = 'Ältere Protokolleinträge werden von der Bereinigungsaufgabe gelöscht.';
$string['label_receiver'] = 'Empfänger für das Tool „{$a}“';
$string['label_sender'] = 'Sender an die Plattform „{$a}“';
$string['level'] = 'Fähigkeitsstufe';
$string['level_cooperative'] = 'Kooperativ';
$string['level_cooperative_desc'] = 'Die Plattform betreibt dieses Plugin: nativer Abschluss der Aktivität „Externes Tool“ mit signierter Bestätigung, Rücknahme und Abgleich.';
$string['level_nopartner'] = 'Kein Partner-Plugin';
$string['level_nopartner_desc'] = 'Die Plattform hat keinen Abschluss-Endpunkt. Es wird nichts übertragen; der Abschluss ist auf der Plattform nicht verfügbar.';
$string['level_partnerdisabled'] = 'Gegenstelle nicht aktiviert';
$string['level_partnerdisabled_desc'] = 'Die Plattform betreibt dieses Plugin, aber ihre Administration hat die Empfängerverbindung für dieses Tool noch nicht aktiviert.';
$string['level_partnererror'] = 'Gegenstelle nicht erreichbar';
$string['level_partnererror_desc'] = 'Die letzte Fähigkeitsprüfung ist fehlgeschlagen. Siehe Protokoll und Endpunktadresse.';
$string['level_receiver'] = 'Empfänger';
$string['level_receiver_desc'] = 'Nimmt signierte Abschlussnachrichten dieses Tools für seine Aktivitäten „Externes Tool“ mit manueller Abschlussverfolgung an.';
$string['level_toolonly'] = 'Tool-only (AGS-Fortschritt)';
$string['level_toolonly_desc'] = 'Kein Partner-Plugin: Der Fortschritt wird als Standard-AGS-Score an ein eigenes Fortschritts-Line-Item gesendet. Keine Garantie für nativen Abschluss und keine Bestätigung.';
$string['level_unknown'] = 'Noch nicht geprüft';
$string['level_unknown_desc'] = 'Die Gegenstelle wurde noch nicht geprüft. Verwenden Sie „Gegenstelle prüfen“.';
$string['lockdown'] = 'Teilnehmende daran hindern, die Aktivität selbst abzuhaken';
$string['lockdown_help'] = 'Setzt im Kontext jeder gesteuerten Aktivität ein Verbot der Fähigkeit moodle/course:togglecompletion für die Rolle „Authentifizierte/r Nutzer/in“, sodass Teilnehmende den Abschluss nicht selbst setzen oder zurücknehmen können (serverseitige Prüfung des Cores). Lehrpersonen mit der Überschreibungsfähigkeit können weiterhin überschreiben; solche Änderungen behandelt die Konfliktregel.';
$string['lockdown_open'] = 'Verfolgte Nutzer/innen, die noch selbst abhaken können';
$string['lockdown_sideeffect'] = 'Bekannte Einschränkung des Moodle-Cores: Die Kursseite zeigt Teilnehmenden einer gesteuerten Aktivität weiterhin die Schaltfläche „Als erledigt markieren“. Ein Klick scheitert mit einem Berechtigungsfehler; der Abschlussstatus wird nicht verändert.';
$string['lticompletionsync:manageactivity'] = 'Anbietergesteuerten Abschluss einer Aktivität „Externes Tool“ verwalten';
$string['lticompletionsync:manageconnections'] = 'Verbindungen der Abschluss-Synchronisation verwalten';
$string['lticompletionsync:viewstatus'] = 'Status der Abschluss-Synchronisation eines Kurses ansehen';
$string['ltitype'] = 'LTI-1.3-Tool';
$string['ltitype_help'] = 'Das externe Tool (LTI 1.3), dessen signierte Abschlussnachrichten diese Site annimmt. Das Tool wird mit dem für dieses Tool konfigurierten öffentlichen Schlüssel bzw. Schlüsselsatz authentifiziert.';
$string['managed'] = 'Abschluss durch den Anbieter gesteuert';
$string['maxattempts'] = 'Zustellversuche';
$string['maxattempts_desc'] = 'Nach so vielen fehlgeschlagenen Versuchen gilt eine Nachricht als unzustellbar.';
$string['messagettl'] = 'Lebensdauer einer Nachricht';
$string['messagettl_desc'] = 'Gültigkeit einer signierten Nachricht.';
$string['noconnections'] = 'Noch keine Verbindungen.';
$string['outgoing'] = 'Ausgehend (diese Site ist das Tool)';
$string['partner_disabled'] = 'Das Partner-Plugin ist vorhanden, die Verbindung ist dort aber nicht aktiviert.';
$string['partner_error'] = 'Die Gegenstelle konnte nicht geprüft werden.';
$string['partner_noplugin'] = 'Die Plattform hat kein Completion-Sync-Plugin.';
$string['partner_plugin'] = 'Das Partner-Plugin hat geantwortet.';
$string['partner_unknown'] = 'Nicht geprüft.';
$string['pluginname'] = 'LTI-Abschluss-Synchronisation';
$string['privacy:metadata:log'] = 'Übertragungsprotokoll (Ereignisnamen und Codes, keine Nachrichteninhalte).';
$string['privacy:metadata:log:correlationid'] = 'Korrelations-ID der Übertragung.';
$string['privacy:metadata:log:eventname'] = 'Name des Übertragungsereignisses.';
$string['privacy:metadata:log:timecreated'] = 'Zeitpunkt des Ereignisses.';
$string['privacy:metadata:log:userid'] = 'Die Person, die die Übertragung betraf.';
$string['privacy:metadata:ltiplatform'] = 'Abschlusszustände von Nutzer/innen veröffentlichter Ressourcen werden an die LTI-Plattform gesendet, von der aus sie gestartet wurden.';
$string['privacy:metadata:ltiplatform:state'] = 'Ob die Person die veröffentlichte Ressource abgeschlossen hat.';
$string['privacy:metadata:ltiplatform:sub'] = 'Die LTI-Nutzerkennung, die die Plattform selbst beim Start übermittelt hat (ihre Nutzer-ID oder ein Pseudonym).';
$string['privacy:metadata:ltiplatform:version'] = 'Version und Zeitpunkt des Abschlusszustands.';
$string['privacy:metadata:out'] = 'Abschlusszustände veröffentlichter Ressourcen, die an die Plattform gemeldet werden.';
$string['privacy:metadata:out:desiredstate'] = 'An die Plattform gemeldeter Abschlusszustand.';
$string['privacy:metadata:out:status'] = 'Zustellstatus.';
$string['privacy:metadata:out:userid'] = 'Die Person, die die veröffentlichte Ressource abgeschlossen hat.';
$string['privacy:metadata:out:version'] = 'Version des gemeldeten Zustands.';
$string['privacy:metadata:rcv'] = 'Vom Tool empfangene Abschlusszustände für Aktivitäten „Externes Tool“.';
$string['privacy:metadata:rcv:remotestate'] = 'Vom Tool gemeldeter Abschlusszustand.';
$string['privacy:metadata:rcv:remoteversion'] = 'Version des gemeldeten Zustands.';
$string['privacy:metadata:rcv:status'] = 'Verarbeitungsstatus.';
$string['privacy:metadata:rcv:userid'] = 'Die Person, zu der der Zustand gehört.';
$string['privacy:metadata:timemodified'] = 'Zeitpunkt der letzten Änderung.';
$string['providerstate'] = 'Zustand beim Anbieter';
$string['receiver_intro'] = 'Diese Site ist die LTI-Plattform. Signierte Abschlussnachrichten des gewählten Tools werden unter {$a} angenommen und auf den nativen Abschluss seiner Aktivitäten „Externes Tool“ angewendet (nur manuelle Abschlussverfolgung).';
$string['receiverdisabled'] = 'Die Empfängerverbindung für dieses Tool ist nicht aktiviert.';
$string['reconcilequeued'] = 'Ein vollständiger Abgleich wurde eingereiht.';
$string['requeued'] = '{$a} Nachrichten wurden erneut eingereiht.';
$string['role'] = 'Rolle';
$string['role_receiver'] = 'Empfänger (Plattform)';
$string['role_sender'] = 'Sender (Tool)';
$string['saved'] = 'Gespeichert.';
$string['sender_intro'] = 'Diese Site ist das LTI-Tool. Der Abschluss von Ressourcen, die mit „Als LTI-Tool veröffentlichen“ bereitgestellt werden, wird an die Plattform des gewählten Deployments gemeldet, signiert mit dem Toolschlüssel dieser Site.';
$string['settings'] = 'Einstellungen';
$string['showall'] = 'Alle anzeigen';
$string['source'] = 'Quelle';
$string['state_completed'] = 'Abgeschlossen';
$string['state_incomplete'] = 'Nicht abgeschlossen';
$string['states'] = 'Übertragungszustände';
$string['statuspage'] = 'Status der Abschluss-Synchronisation';
$string['task_cleanup'] = 'Abschluss-Synchronisation: Bereinigung';
$string['task_fullreconcile'] = 'Abschluss-Synchronisation: vollständiger Abgleich';
$string['task_sweep'] = 'Abschluss-Synchronisation: Delta-Abgleich und Wiederholungen';
$string['unsupported_completion_disabled'] = 'Die Abschlussverfolgung ist im Kurs deaktiviert.';
$string['unsupported_not_lti'] = 'Die Aktivität ist keine Aktivität „Externes Tool“ (mod_lti).';
$string['unsupported_tracking_automatic'] = 'Die Aktivität verwendet automatische Abschlussverfolgung. Ohne Core-Änderung kann nur die manuelle Abschlussverfolgung vom Anbieter gesteuert werden.';
$string['unsupported_tracking_none'] = 'Die Aktivität hat keine Abschlussverfolgung.';
$string['unsupported_user_not_tracked'] = 'Die Person wird in diesem Kurs nicht für den Abschluss verfolgt.';
$string['version'] = 'Version';
