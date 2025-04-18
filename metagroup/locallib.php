<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Local stuff for metagroup course enrolment plugin.
 *
 * @package    enrol_metagroup
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


/**
 * Event handler for metagroup enrolment plugin.
 *
 * We try to keep everything in sync via listening to events,
 * it may fail sometimes, so we always do a full sync in cron too.
 */
class enrol_metagroup_handler {

    /**
     * Synchronise metagroup enrolments of this user in this course
     * @static
     * @param int $courseid
     * @param int $userid
     * @return void
     */
    protected static function sync_course_instances($courseid, $userid) {
        global $DB;

        static $preventrecursion = false;

        // Does anything want to sync with this parent?
        if (!$enrols = $DB->get_records('enrol', array('customint1'=>$courseid, 'enrol'=>'metagroup'), 'id ASC')) {
            return;
        }

        if ($preventrecursion) {
            return;
        }

        $preventrecursion = true;

        try {
            foreach ($enrols as $enrol) {
                self::sync_with_parent_course($enrol, $userid);
            }
        } catch (Exception $e) {
            $preventrecursion = false;
            throw $e;
        }

        $preventrecursion = false;
    }

    /**
     * Synchronise enrolments of specific user in given instance as fast as possible.
     *
     * All roles are removed if the metagroup plugin disabled.
     *
     * Notes:
     * This methods ensures this user has required enrolment, roles and target-group membership.
     * If the user had to be removed from any other groups, this will not be handled here.
     *
     * @static
     * @param stdClass $instance
     * @param int $userid
     * @return void
     */
    protected static function sync_with_parent_course(stdClass $instance, $userid) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $plugin = enrol_get_plugin('metagroup');

        if ($instance->customint1 == $instance->courseid) {
            // Can not sync with self!!!
            return;
        }


        $context = context_course::instance($instance->courseid);

        // List of enrolments in parent course (we ignore metagroup enrols in parents completely).
        // Added: restrict `ue`s to members source group.
        list($enabled, $params) = $DB->get_in_or_equal(explode(',', $CFG->enrol_plugins_enabled), SQL_PARAMS_NAMED, 'e');
        $params['userid'] = $userid;
        $params['parentcourse'] = $instance->customint1;
        $params['groupid'] = $instance->customint3;
        $sql = "SELECT ue.*, e.status AS enrolstatus
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol <> 'metagroup' AND e.courseid = :parentcourse AND e.enrol $enabled)
                  JOIN {groups_members} gm ON (gm.userid = ue.userid)
                 WHERE ue.userid = :userid AND gm.groupid = :groupid";
        $parentues = $DB->get_records_sql($sql, $params);
        // Current enrolments for this instance.
        $ue = $DB->get_record('user_enrolments', array('enrolid'=>$instance->id, 'userid'=>$userid));

        // First deal with users that are not enrolled in parent.
        if (empty($parentues)) {
            self::user_not_supposed_to_be_here($instance, $ue, $context, $plugin);
            return;
        }

        if (!$parentcontext = context_course::instance($instance->customint1, IGNORE_MISSING)) {
            // Weird, we should not get here.
            // Maybe if parent course had been deleted.
            return;
        }

        $skiproles = $plugin->get_config('nosyncroleids', '');
        $skiproles = empty($skiproles) ? array() : explode(',', $skiproles);
        $syncall   = $plugin->get_config('syncall', 1);

        // Roles in parent course (metagroup enrols must be ignored!)
        $parentroles = array();
        list($ignoreroles, $params) = $DB->get_in_or_equal($skiproles, SQL_PARAMS_NAMED, 'ri', false, -1);
        $params['contextid'] = $parentcontext->id;
        $params['userid'] = $userid;
        $select = "contextid = :contextid AND userid = :userid AND component <> 'enrol_metagroup' AND roleid $ignoreroles";
        foreach($DB->get_records_select('role_assignments', $select, $params) as $ra) {
            $parentroles[$ra->roleid] = $ra->roleid;
        }

        // Do we want users without roles?
        if (!$syncall and empty($parentroles)) {
            self::user_not_supposed_to_be_here($instance, $ue, $context, $plugin);
            return;
        }

        // Roles from this instance.
        $roles = array();
        $ras = $DB->get_records('role_assignments', array('contextid'=>$context->id, 'userid'=>$userid, 'component'=>'enrol_metagroup', 'itemid'=>$instance->id));
        foreach($ras as $ra) {
            $roles[$ra->roleid] = $ra->roleid;
        }
        unset($ras);

        // Is parent enrol active? Find minimum timestart and maximum timeend of all active enrolments.
        $parentstatus = ENROL_USER_SUSPENDED;
        $parenttimeend = null;
        $parenttimestart = null;
        foreach ($parentues as $pue) {
            if ($pue->status == ENROL_USER_ACTIVE && $pue->enrolstatus == ENROL_INSTANCE_ENABLED) {
                $parentstatus = ENROL_USER_ACTIVE;
                if ($parenttimeend === null || $pue->timeend == 0 || ($parenttimeend && $parenttimeend < $pue->timeend)) {
                    $parenttimeend = $pue->timeend;
                }
                if ($parenttimestart === null || $parenttimestart > $pue->timestart) {
                    $parenttimestart = $pue->timestart;
                }
            }
        }

        // Enrol user if not enrolled yet or fix status/timestart/timeend. Use the minimum timestart and maximum timeend found above.
        if ($ue) {
            if ($parentstatus != $ue->status ||
                    ($parentstatus == ENROL_USER_ACTIVE && ($parenttimestart != $ue->timestart || $parenttimeend != $ue->timeend))) {
                $plugin->update_user_enrol($instance, $userid, $parentstatus, $parenttimestart, $parenttimeend);
                $ue->status = $parentstatus;
                $ue->timestart = $parenttimestart;
                $ue->timeend = $parenttimeend;

                // Ensure user is in target group.
                if ($instance->customint2 &&
                    !groups_is_member($instance->customint2, $userid)) {
                    // Note: if the group is absent, this will fail; new group will be created on full sync.
                    groups_add_member($instance->customint2, $userid, 'enrol_metagroup', $instance->id);
                }

            }
        } else {
            $plugin->enrol_user($instance, $userid, NULL, (int)$parenttimestart, (int)$parenttimeend, $parentstatus);
            $ue = new stdClass();
            $ue->userid = $userid;
            $ue->enrolid = $instance->id;
            $ue->status = $parentstatus;
            if ($instance->customint2) {
                // Note: if the group is absent, this will fail; new group will be created on full sync.
                groups_add_member($instance->customint2, $userid, 'enrol_metagroup', $instance->id);
            }
        }

        $unenrolaction = $plugin->get_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);

        // Only active users in enabled instances are supposed to have roles (we can reassign the roles any time later).
        if ($ue->status != ENROL_USER_ACTIVE or $instance->status != ENROL_INSTANCE_ENABLED or
                ($parenttimeend and $parenttimeend < time()) or ($parenttimestart > time())) {
            if ($unenrolaction == ENROL_EXT_REMOVED_SUSPEND) {
                // Always keep the roles.
            } else if ($roles) {
                // This will only unassign roles that were assigned in this enrolment method, leaving all manual role assignments intact.
                role_unassign_all(array('userid'=>$userid, 'contextid'=>$context->id, 'component'=>'enrol_metagroup', 'itemid'=>$instance->id));
            }
            return;
        }

        // Add new roles.
        foreach ($parentroles as $rid) {
            if (!isset($roles[$rid])) {
                role_assign($rid, $userid, $context->id, 'enrol_metagroup', $instance->id);
            }
        }

        if ($unenrolaction == ENROL_EXT_REMOVED_SUSPEND) {
            // Always keep the roles.
            return;
        }

        // Remove roles.
        foreach ($roles as $rid) {
            if (!isset($parentroles[$rid])) {
                role_unassign($rid, $userid, $context->id, 'enrol_metagroup', $instance->id);
            }
        }
    }

    /**
     * Deal with users that are not supposed to be enrolled via this instance (i.e. suspend / unenrol).
     * @static
     * @param stdClass $instance
     * @param stdClass $ue
     * @param context_course $context
     * @param enrol_plugin $plugin instance of enrol_metagroup class
     * @return void
     */
    protected static function user_not_supposed_to_be_here($instance, $ue, context_course $context, $plugin) {
        if (!$ue) {
            // Not enrolled yet - simple!
            return;
        }

        $userid = $ue->userid;
        $unenrolaction = $plugin->get_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);

        if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
            // Purges grades, group membership, preferences, etc. - admins were warned!
            $plugin->unenrol_user($instance, $userid);

        } else if ($unenrolaction == ENROL_EXT_REMOVED_SUSPEND) {
            if ($ue->status != ENROL_USER_SUSPENDED) {
                $plugin->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
            }

        } else if ($unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
            if ($ue->status != ENROL_USER_SUSPENDED) {
                $plugin->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
            }
            role_unassign_all(array('userid'=>$userid, 'contextid'=>$context->id, 'component'=>'enrol_metagroup', 'itemid'=>$instance->id));

        } else {
            debugging('Unknown unenrol action '.$unenrolaction);
        }
    }

    /**
     * Delete group if it is empty and the plugin is configured to do so.
     *
     * @param int $groupid id group to delete if it is empty
     */
    public static function delete_empty_group_as_configured($groupid, $verbose = false) {
        global $DB;

        // Удаляем пустую группу, если включена соответствующая настройка, и целевая группа присутствует.
        if ($groupid && get_config('enrol_metagroup', 'deleteemptygroups')) {
            $groupmembers = $DB->count_records('groups_members', array('groupid' => $groupid));
            if ($groupmembers == 0) {
                groups_delete_group($groupid);
                if ($verbose) {
                    mtrace("  removed empty group: $groupid .");
                }
            }
        }
    }
}

/**
 * Sync all metagroup links.
 *
 * @param int $courseid one course, empty mean all
 * @param bool $verbose verbose CLI output
 * @return int 0 means ok, 1 means error, 2 means plugin disabled
 */
function enrol_metagroup_sync($courseid = NULL, $verbose = false) {
    global $CFG, $DB;
    require_once("{$CFG->dirroot}/group/lib.php");

    // Purge all roles if metagroup sync disabled, those can be recreated later here in cron.
    if (!enrol_is_enabled('metagroup')) {
        if ($verbose) {
            mtrace('Metagroup plugin is disabled, unassigning all plugin roles and stopping.');
        }
        role_unassign_all(array('component'=>'enrol_metagroup'));
        return 2;
    }

    // Unfortunately this may take a long time, execution can be interrupted safely.
    core_php_time_limit::raise();
    raise_memory_limit(MEMORY_HUGE);

    if ($verbose) {
        mtrace('Starting user enrolment synchronisation (enrol_metagroup) ...');
    }

    $enrols_having_link_lost = [];


    // Получаем настройку поведения при потере связи с родительским курсом.
    $lostlinkaction = get_config('enrol_metagroup', 'lostlinkaction');

    if (true /* $lostlinkaction != ENROL_EXT_REMOVED_KEEP */) {

        // Обработка случаев, когда родительский курс или группа удалены.
        $sql = "SELECT distinct e.*
                FROM {enrol} e
                LEFT JOIN {course} c ON c.id = e.customint1
                LEFT JOIN {groups} g ON g.id = e.customint3
                WHERE e.enrol = 'metagroup'
                AND (c.id IS NULL OR g.id IS NULL)";

        $rs = $DB->get_records_sql($sql);
        $lost_links_count = 0;

        foreach ($rs as $record) {
            $enrols_having_link_lost[]= $record->id;

            if ($verbose) {
                mtrace('dealing with a lost link: enrolid=' . ($record->id));
            }
            $lost_links_count += 1;
            enrol_metagroup_deal_with_lost_link($record);

            if ($lostlinkaction == ENROL_EXT_REMOVED_UNENROL) {
                // Удаляем опустевшую группы, если включена соответствующая настройка плагина, и целевая группа присутствует.
                enrol_metagroup_handler::delete_empty_group_as_configured($record->customint2, true);
            }
        }
        // $rs->close();
        if ($verbose) {
            mtrace("Done dealing with $lost_links_count lost link(s).");
        }
    }

    // End of new fragment.

    $instances = array(); // Cache enrol instances.
    $local_groups = array(); // Cache groups instances.

    $metagroup = enrol_get_plugin('metagroup');

    $unenrolaction = $metagroup->get_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);
    $skiproles     = $metagroup->get_config('nosyncroleids', '');
    $skiproles     = empty($skiproles) ? array() : explode(',', $skiproles);
    $syncall       = $metagroup->get_config('syncall', 1);

    $allroles = get_all_roles();


    // Iterate through all not enrolled yet users (not in the course or/and not in target group).
    // For each active enrolment of each user find the minimum
    // enrolment startdate and maximum enrolment enddate.
    // This SQL relies on the fact that ENROL_USER_ACTIVE < ENROL_USER_SUSPENDED
    // and ENROL_INSTANCE_ENABLED < ENROL_INSTANCE_DISABLED. Condition "pue.status + pe.status = 0" means
    // that enrolment is active. When MIN(pue.status + pe.status)=0 it means there exists an active
    // enrolment.
    // Added: restrict `ue`s to members source group (and group membership should be originated in corresponding enrol instance).
    // A returned record should have: ue_id == NULL (means the need to enrol) and/or gm_id == NULL (means the need to add to target group).
    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
    list($enabled, $params) = $DB->get_in_or_equal(explode(',', $CFG->enrol_plugins_enabled), SQL_PARAMS_NAMED, 'e');
    $params['courseid'] = $courseid;
    $params['enrolstatus'] = ENROL_INSTANCE_ENABLED;
    $sql = "SELECT pue.userid, e.id AS enrolid,
                ue.id AS ue_id, gm.id AS gm_id,
                MIN(pue.status + pe.status) AS status,
                MIN(CASE WHEN (pue.status + pe.status = 0) THEN pue.timestart ELSE 9999999999 END) AS timestart,
                MAX(CASE WHEN (pue.status + pe.status = 0) THEN
                        (CASE WHEN pue.timeend = 0 THEN 9999999999 ELSE pue.timeend END)
                        ELSE 0 END) AS timeend
              FROM {user_enrolments} pue
              JOIN {enrol} pe ON (pe.id = pue.enrolid AND pe.enrol <> 'metagroup' AND pe.enrol $enabled)
              JOIN {groups_members} pgm ON (pgm.userid = pue.userid)
              JOIN {enrol} e ON (e.customint1 = pe.courseid AND e.customint3 = pgm.groupid AND e.enrol = 'metagroup' AND e.status = :enrolstatus $onecourse)
              JOIN {user} u ON (u.id = pue.userid AND u.deleted = 0)
         LEFT JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = pue.userid)
         LEFT JOIN {groups_members} gm ON (gm.userid = pue.userid AND e.customint2 = gm.groupid AND gm.itemid = e.id)
             WHERE ue.id IS NULL OR gm.id IS NULL
             GROUP BY pue.userid, e.id";

    // Create / restore user enrolments & group memberships.
    $rs = $DB->get_recordset_sql($sql, $params);
    $rs_counter = 0;
    foreach($rs as $ue) {

        if(in_array($ue->enrolid, $enrols_having_link_lost)) {
            continue;
        }

        if (!isset($instances[$ue->enrolid])) {
            // Add instance to cache.
            $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
        }
        $instance = $instances[$ue->enrolid];

        if (!$syncall) {
            // Check if current user has any of synced roles (in source course).
            // This may be slow if very many users are ignored in sync.
            $parentcontext = context_course::instance($instance->customint1);
            list($ignoreroles, $params) = $DB->get_in_or_equal($skiproles, SQL_PARAMS_NAMED, 'ri', false, -1);
            $params['contextid'] = $parentcontext->id;
            $params['userid'] = $ue->userid;
            $select = "contextid = :contextid AND userid = :userid AND component <> 'enrol_metagroup' AND roleid $ignoreroles";
            if (!$DB->record_exists_select('role_assignments', $select, $params)) {
                // Bad luck, this user does not have any role we want in parent course.
                if ($verbose) {
                    mtrace("  skipping enrolling: $ue->userid ==> $instance->courseid (user without role)");
                }
                continue;
            }
        }

        ++$rs_counter;

        // So now we have aggregated values that we will use for the metagroup enrolment status, timeend and timestart.
        // Again, we use the fact that active=0 and disabled/suspended=1. Only when MIN(pue.status + pe.status)=0 the enrolment is active:
        $ue->status = ($ue->status == ENROL_USER_ACTIVE + ENROL_INSTANCE_ENABLED) ? ENROL_USER_ACTIVE : ENROL_USER_SUSPENDED;
        // Timeend 9999999999 was used instead of 0 in the "MAX()" function:
        $ue->timeend = ($ue->timeend == 9999999999) ? 0 : (int)$ue->timeend;
        // Timestart 9999999999 is only possible when there are no active enrolments:
        $ue->timestart = ($ue->timestart == 9999999999) ? 0 : (int)$ue->timestart;

        if (!$ue->ue_id) {
            // Not enrolled yet.
            $metagroup->enrol_user($instance, $ue->userid, null, $ue->timestart, $ue->timeend, $ue->status);

            if ($verbose) {
                mtrace("  enrolled: $ue->userid ==> $instance->courseid");
            }
        }
        if (!$ue->gm_id && $instance->customint2) {
            // Not a group member yet.
            $gid = $instance->customint2;

            // First, ensure that target group exists.
            if (isset($local_groups[$gid])) {
                $group = $local_groups[$gid];
            } else {
                if (!$group = groups_get_group($gid)) {
                    // Add new group.
                    $new_groupid = enrol_metagroup_create_new_group($instance->courseid, $instance->customint3, false);

                    if ($new_groupid != $gid) {
                        // Update instance with new target group id!
                        $DB->update_record('enrol', (object)['id' => $instance->id, 'customint2' => $new_groupid]);
                    }

                    $group = groups_get_group($new_groupid);
                }
                $local_groups[$gid] = $group;

            }

            // Add member to group.
            $ok = groups_add_member($group, $ue->userid, 'enrol_metagroup', $instance->id);

            if ($verbose) {
                mtrace("  added to group: $ue->userid ==> $gid in course $instance->courseid (success: $ok).");
            }
        }
    }
    $rs->close();


    if ($verbose) {
        mtrace("Absent/incomplete enrolments processed: $rs_counter.");
    }


    // Unenrol / remove from group as necessary - ignore enabled flag, we want to get rid of existing enrols in any case.
    // Added: restrict `ue`s to members source group (and group membership should be originated in corresponding enrol instance).
    // A returned record should have: parent_enrolid == NULL (means the need to unenrol) and/or old_groupid != NULL (means the need to remove from that group).
    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
    list($enabled, $params) = $DB->get_in_or_equal(explode(',', $CFG->enrol_plugins_enabled), SQL_PARAMS_NAMED, 'e');
    $params['courseid'] = $courseid;
    $sql = "SELECT ue.*, xpe.id AS parent_enrolid, gm.groupid AS old_groupid
              FROM {user_enrolments} ue
              JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'metagroup' $onecourse)
         LEFT JOIN {groups_members} gm ON (gm.userid = ue.userid AND gm.itemid = e.id)
         LEFT JOIN ({user_enrolments} xpue
                      JOIN {enrol} xpe ON (xpe.id = xpue.enrolid AND xpe.enrol <> 'metagroup' AND xpe.enrol $enabled)
                      JOIN {groups_members} xpgm ON (xpgm.userid = xpue.userid)
                   ) ON (xpe.courseid = e.customint1 AND xpue.userid = ue.userid AND xpgm.groupid = e.customint3)
             WHERE xpue.userid IS NULL OR (gm.id IS NOT NULL AND e.customint2 <> gm.groupid)";

    // debugging($sql);
    // debugging(var_export($params, true));

    $rs = $DB->get_recordset_sql($sql, $params);
    $rs_counter = 0;
    foreach($rs as $ue) {

        if (in_array($ue->enrolid, $enrols_having_link_lost)) {
            continue;
        }

        ++$rs_counter;

        if (!isset($instances[$ue->enrolid])) {
            $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
        }
        $instance = $instances[$ue->enrolid];

        if ($ue->old_groupid && $ue->old_groupid != $instance->customint2) {
            // Move group member from old group to new one.
            /// echo (" <br><br><br><br><br><br> ");

            $ok = groups_add_member($instance->customint2, $ue->userid, 'enrol_metagroup', $instance->id);
            /// ↓
            if ($verbose /* || 1 */) {
                mtrace("  added user to group: $ue->userid ==> $instance->customint2 in course $instance->courseid (success: $ok).");
            }


            $ok = groups_remove_member($ue->old_groupid, $ue->userid);
            if ($verbose) {
                mtrace("  removed user from group: $ue->userid ==> $ue->old_groupid in course $instance->courseid (success: $ok).");
            }
            /// echo(" <br> removed user from group: $ue->userid ==> $ue->old_groupid in course $instance->courseid (success: $ok).");

            enrol_metagroup_handler::delete_empty_group_as_configured($ue->old_groupid, $verbose);
        }

        if (!$ue->parent_enrolid) {
            // Unenrol / suspend as configured.
            if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
                $metagroup->unenrol_user($instance, $ue->userid);
                if ($verbose) {
                    mtrace("  unenrolling: $ue->userid ==> $instance->courseid");
                }

            } else if ($unenrolaction == ENROL_EXT_REMOVED_SUSPEND) {
                if ($ue->status != ENROL_USER_SUSPENDED) {
                    $metagroup->update_user_enrol($instance, $ue->userid, ENROL_USER_SUSPENDED);
                    if ($verbose) {
                        mtrace("  suspending: $ue->userid ==> $instance->courseid");
                    }
                }

            } else if ($unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
                if ($ue->status != ENROL_USER_SUSPENDED) {
                    $metagroup->update_user_enrol($instance, $ue->userid, ENROL_USER_SUSPENDED);
                    $context = context_course::instance($instance->courseid);
                    role_unassign_all(array('userid'=>$ue->userid, 'contextid'=>$context->id, 'component'=>'enrol_metagroup', 'itemid'=>$instance->id));
                    if ($verbose) {
                        mtrace("  suspending and removing all roles: $ue->userid ==> $instance->courseid");
                    }
                }
            }
        }
    }
    $rs->close();


    if ($verbose) {
        mtrace("Extra enrolments processed: $rs_counter.");
    }


    //*
    // Update status - metagroup enrols are ignored to avoid recursion.
    // Note the trick here is that the active enrolment and instance constants have value 0.
    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
    list($enabled, $params) = $DB->get_in_or_equal(explode(',', $CFG->enrol_plugins_enabled), SQL_PARAMS_NAMED, 'e');
    $params['courseid'] = $courseid;
    // The query builds a list of all the non-metagroup enrolments that are on courses (the children) that are linked to by a metagroup
    // enrolment, it then groups them by the course that linked to them (the parents).
    //
    // It will only return results where the there is a difference between the status of the parent and the lowest status
    // of the children (remember that 0 is active, any other status is some form of inactive), or the time the earliest non-zero
    // start time of a child is different to the parent, or the longest effective end date has changed.
    //
    // The last two case statements in the HAVING clause are designed to ignore any inactive child records when calculating
    // the start and end time.
    // Added: restrict `ue`s to members source group (that is set in metagroup enrol instance).
    $sql = "SELECT ue.userid, ue.enrolid,
                   MIN(xpue.status + xpe.status) AS pstatus,
                   MIN(CASE WHEN (xpue.status + xpe.status = 0) THEN xpue.timestart ELSE 9999999999 END) AS ptimestart,
                   MAX(CASE WHEN (xpue.status + xpe.status = 0) THEN
                                 (CASE WHEN xpue.timeend = 0 THEN 9999999999 ELSE xpue.timeend END)
                            ELSE 0 END) AS ptimeend
              FROM {user_enrolments} ue
              JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'metagroup' $onecourse)
              JOIN {user_enrolments} xpue ON (xpue.userid = ue.userid)
              JOIN {enrol} xpe ON (xpe.id = xpue.enrolid AND xpe.enrol <> 'metagroup'
                   AND xpe.enrol $enabled AND xpe.courseid = e.customint1)
              JOIN {groups_members} pgm ON (pgm.userid = ue.userid AND e.customint3 = pgm.groupid)
          GROUP BY ue.userid, ue.enrolid
            HAVING (MIN(xpue.status + xpe.status) = 0 AND MIN(ue.status) > 0)
                   OR (MIN(xpue.status + xpe.status) > 0 AND MIN(ue.status) = 0)
                   OR ((CASE WHEN
                                  MIN(CASE WHEN (xpue.status + xpe.status = 0) THEN xpue.timestart ELSE 9999999999 END) = 9999999999
                             THEN 0
                             ELSE
                                  MIN(CASE WHEN (xpue.status + xpe.status = 0) THEN xpue.timestart ELSE 9999999999 END)
                              END) <> MIN(ue.timestart))
                   OR ((CASE
                         WHEN MAX(CASE WHEN (xpue.status + xpe.status = 0)
                                       THEN (CASE WHEN xpue.timeend = 0 THEN 9999999999 ELSE xpue.timeend END)
                                       ELSE 0 END) = 9999999999
                         THEN 0 ELSE MAX(CASE WHEN (xpue.status + xpe.status = 0)
                                              THEN (CASE WHEN xpue.timeend = 0 THEN 9999999999 ELSE xpue.timeend END)
                                              ELSE 0 END)
                          END) <> MAX(ue.timeend))";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ue) {

        if (in_array($ue->enrolid, $enrols_having_link_lost)) {
            continue;
        }

        if (!isset($instances[$ue->enrolid])) {
            $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
        }
        $instance = $instances[$ue->enrolid];
        $ue->pstatus = ($ue->pstatus == ENROL_USER_ACTIVE + ENROL_INSTANCE_ENABLED) ? ENROL_USER_ACTIVE : ENROL_USER_SUSPENDED;
        $ue->ptimeend = ($ue->ptimeend == 9999999999) ? 0 : (int)$ue->ptimeend;
        $ue->ptimestart = ($ue->ptimestart == 9999999999) ? 0 : (int)$ue->ptimestart;

        if ($ue->pstatus == ENROL_USER_ACTIVE and (!$ue->ptimeend || $ue->ptimeend > time())
                and !$syncall and $unenrolaction != ENROL_EXT_REMOVED_UNENROL) {
            // This may be slow if very many users are ignored in sync.
            $parentcontext = context_course::instance($instance->customint1);
            list($ignoreroles, $params) = $DB->get_in_or_equal($skiproles, SQL_PARAMS_NAMED, 'ri', false, -1);
            $params['contextid'] = $parentcontext->id;
            $params['userid'] = $ue->userid;
            $select = "contextid = :contextid AND userid = :userid AND component <> 'enrol_metagroup' AND roleid $ignoreroles";
            if (!$DB->record_exists_select('role_assignments', $select, $params)) {
                // Bad luck, this user does not have any role we want in parent course.
                if ($verbose) {
                    mtrace("  skipping unsuspending: $ue->userid ==> $instance->courseid (user without role)");
                }
                continue;
            }
        }

        $metagroup->update_user_enrol($instance, $ue->userid, $ue->pstatus, $ue->ptimestart, $ue->ptimeend);
        if ($verbose) {
            if ($ue->pstatus == ENROL_USER_ACTIVE) {
                mtrace("  unsuspending: $ue->userid ==> $instance->courseid");
            } else {
                mtrace("  suspending: $ue->userid ==> $instance->courseid");
            }
        }
    }
    $rs->close();
    // */


    // Now assign all necessary roles (currently absent).
    $enabled = explode(',', $CFG->enrol_plugins_enabled);
    foreach($enabled as $k=>$v) {
        if ($v === 'metagroup') {
            continue; // No metagroup sync of metagroup roles.
        }
        $enabled[$k] = 'enrol_'.$v;
    }
    $enabled[] = ''; // Manual assignments are replicated too.

    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
    list($enabled, $params) = $DB->get_in_or_equal($enabled, SQL_PARAMS_NAMED, 'e');
    $params['coursecontext'] = CONTEXT_COURSE;
    $params['courseid'] = $courseid;
    $params['activeuser'] = ENROL_USER_ACTIVE;
    $params['enabledinstance'] = ENROL_INSTANCE_ENABLED;
    $sql = "SELECT DISTINCT pra.roleid, pra.userid, c.id AS contextid, e.id AS enrolid, e.courseid
              FROM {role_assignments} pra
              JOIN {user} u ON (u.id = pra.userid AND u.deleted = 0)
              JOIN {context} pc ON (pc.id = pra.contextid AND pc.contextlevel = :coursecontext AND pra.component $enabled)
              JOIN {enrol} e ON (e.customint1 = pc.instanceid AND e.enrol = 'metagroup' $onecourse AND e.status = :enabledinstance)
              JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = u.id AND ue.status = :activeuser)
              JOIN {context} c ON (c.contextlevel = pc.contextlevel AND c.instanceid = e.courseid)
         LEFT JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.userid = pra.userid AND ra.roleid = pra.roleid AND ra.itemid = e.id AND ra.component = 'enrol_metagroup')
             WHERE ra.id IS NULL";

    if ($ignored = $metagroup->get_config('nosyncroleids')) {
        list($notignored, $xparams) = $DB->get_in_or_equal(explode(',', $ignored), SQL_PARAMS_NAMED, 'ig', false);
        $params = array_merge($params, $xparams);
        $sql = "$sql AND pra.roleid $notignored";
    }

    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ra) {
        role_assign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_metagroup', $ra->enrolid);
        if ($verbose) {
            mtrace("  assigning role: $ra->userid ==> $ra->courseid as ".$allroles[$ra->roleid]->shortname);
        }
    }
    $rs->close();


    // Remove unwanted roles - include ignored roles and disabled plugins too.
    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
    $params = array();
    $params['coursecontext'] = CONTEXT_COURSE;
    $params['courseid'] = $courseid;
    $params['activeuser'] = ENROL_USER_ACTIVE;
    $params['enabledinstance'] = ENROL_INSTANCE_ENABLED;
    if ($ignored = $metagroup->get_config('nosyncroleids')) {
        list($notignored, $xparams) = $DB->get_in_or_equal(explode(',', $ignored), SQL_PARAMS_NAMED, 'ig', false);
        $params = array_merge($params, $xparams);
        $notignored = "AND pra.roleid $notignored";
    } else {
        $notignored = "";
    }

    $sql = "SELECT ra.roleid, ra.userid, ra.contextid, ra.itemid, e.courseid
              FROM {role_assignments} ra
              JOIN {enrol} e ON (e.id = ra.itemid AND ra.component = 'enrol_metagroup' AND e.enrol = 'metagroup' $onecourse)
              JOIN {context} pc ON (pc.instanceid = e.customint1 AND pc.contextlevel = :coursecontext)
         LEFT JOIN {role_assignments} pra ON (pra.contextid = pc.id AND pra.userid = ra.userid AND pra.roleid = ra.roleid AND pra.component <> 'enrol_metagroup' $notignored)
         LEFT JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = ra.userid AND ue.status = :activeuser)
             WHERE pra.id IS NULL OR ue.id IS NULL OR e.status <> :enabledinstance";

    if ($unenrolaction != ENROL_EXT_REMOVED_SUSPEND) {
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach($rs as $ra) {
            role_unassign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_metagroup', $ra->itemid);
            if ($verbose) {
                mtrace("  unassigning role: $ra->userid ==> $ra->courseid as ".$allroles[$ra->roleid]->shortname);
            }
        }
        $rs->close();
    }


    // Kick out or suspend users without synced roles if syncall disabled.
    if (!$syncall) {
        if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
            // Unenrol.
            $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
            $params = array();
            $params['coursecontext'] = CONTEXT_COURSE;
            $params['courseid'] = $courseid;
            $sql = "SELECT ue.userid, ue.enrolid
                      FROM {user_enrolments} ue
                      JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'metagroup' $onecourse)
                      JOIN {context} c ON (e.courseid = c.instanceid AND c.contextlevel = :coursecontext)
                 LEFT JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.itemid = e.id AND ra.userid = ue.userid)
                     WHERE ra.id IS NULL";
            $ues = $DB->get_recordset_sql($sql, $params);
            foreach($ues as $ue) {
                if (!isset($instances[$ue->enrolid])) {
                    $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
                }
                $instance = $instances[$ue->enrolid];
                $metagroup->unenrol_user($instance, $ue->userid);
                if ($verbose) {
                    mtrace("  unenrolling: $ue->userid ==> $instance->courseid (user without role)");
                }
            }
            $ues->close();

        } else {
            // Just suspend the users.
            $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
            $params = array();
            $params['coursecontext'] = CONTEXT_COURSE;
            $params['courseid'] = $courseid;
            $params['active'] = ENROL_USER_ACTIVE;
            $sql = "SELECT ue.userid, ue.enrolid
                      FROM {user_enrolments} ue
                      JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'metagroup' $onecourse)
                      JOIN {context} c ON (e.courseid = c.instanceid AND c.contextlevel = :coursecontext)
                 LEFT JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.itemid = e.id AND ra.userid = ue.userid)
                     WHERE ra.id IS NULL AND ue.status = :active";
            $ues = $DB->get_recordset_sql($sql, $params);
            foreach($ues as $ue) {
                if (!isset($instances[$ue->enrolid])) {
                    $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
                }
                $instance = $instances[$ue->enrolid];
                $metagroup->update_user_enrol($instance, $ue->userid, ENROL_USER_SUSPENDED);
                if ($verbose) {
                    mtrace("  suspending: $ue->userid ==> $instance->courseid (user without role)");
                }
            }
            $ues->close();
        }
    }

    if ($verbose) {
        mtrace('...user enrolment synchronisation finished.');
    }

    return 0;
}

/**
 * Called for enrol instance when it is known that the group in parent course does not exist anymore.
 *
 * @param object $enrol metagroup enrol record.
 */
function enrol_metagroup_deal_with_lost_link($enrol) {
    global $DB;
    if ($enrol) {
        // Применяем настройку lostlinkaction.
        $lostlinkaction = get_config('enrol_metagroup', 'lostlinkaction');
        switch ($lostlinkaction) {
            case ENROL_EXT_REMOVED_KEEP:
                // Ничего не делаем ().
                break;

            case ENROL_EXT_REMOVED_SUSPENDNOROLES:
                // Заблокировать студентов и отозвать роли.
                $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, array('enrolid' => $enrol->id));
                role_unassign_all(array('component' => 'enrol_metagroup', 'itemid' => $enrol->id));
                break;

            case ENROL_EXT_REMOVED_UNENROL:
                $plugin = enrol_get_plugin('metagroup');

                // Удалить способ зачисления (и студентов из курса); в результате группа должна очиститься.
                // $plugin->delete_instance($enrol);

                // Заблокировать способ зачисления и удалить всех студентов из курса.
                $plugin->update_status($enrol, ENROL_INSTANCE_DISABLED, false);

                // $target_group = groups_get_group($enrol->customint2);
                $target_group_members = groups_get_members($enrol->customint2);
                if ($target_group_members) {
                    foreach($target_group_members as $student)
                    {
                        $plugin->unenrol_user($enrol, $student->id);
                    }
                }
                break;
        }
    }
}

/**
 * Create a new group with the other group's name (group name is fetched unless $explicit_name is given).
 * If such a group does already exist (with the same name) and `$always_create_new` is `false`, returns id of existing group.
 *
 * @param int $courseid this course to create group in.
 * @param int $linkedgroupid optional id of a group in another course.
 * @param string $explicit_name optional but required if $linkedgroupid is not provided.
 * @param bool $always_create_new if `false` and a group with the same name already exists, its ID will just be returned. If `true` (default), a new group is created and renamed in case of name clash.
 * @return int $groupid ID of fresh group.
 */
function enrol_metagroup_create_new_group($courseid, $linkedgroupid = null, $explicit_name = null, $always_create_new = true) {
    global $DB, $CFG;

    require_once($CFG->dirroot.'/group/lib.php');

    if ($explicit_name)
        $groupname = $explicit_name;
    else
        $groupname = groups_get_group($linkedgroupid, 'name', MUST_EXIST)->name;

    $a = new stdClass();
    $a->name = $groupname;
    $a->increment = '';
    $groupname = trim(get_string('defaultgroupnametext', 'enrol_metagroup', $a));

    // Check to see if the group name already exists in this course.
    // Add an incremented number if it does.
    $inc = 1;
    while ($DB->record_exists('groups', array('name' => $groupname, 'courseid' => $courseid))) {
        if ($always_create_new) {
            $a->increment = '(' . (++$inc) . ')';
            $groupname = trim(get_string('defaultgroupnametext', 'enrol_metagroup', $a));

        } else {
            // Return id.
            // TODO: refactor out extra DB query.
            $groupid = $DB->get_field('groups', 'id', array('name' => $groupname, 'courseid' => $courseid), MUST_EXIST);
            return $groupid;
        }
    }

    // Create a new group for the course metagroup sync.
    $groupdata = new stdClass();
    $groupdata->courseid = $courseid;
    $groupdata->name = $groupname;
    $groupid = groups_create_group($groupdata);

    return $groupid;
}
