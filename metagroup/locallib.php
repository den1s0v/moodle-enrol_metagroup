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

        // List of enrolments in parent course (any enabled method including metagroup, e.g. when source is a summary course).
        // Added: restrict `ue`s to members source group.
        // Используем корневой курс и группу для синхронизации, если они указаны.
        $root_courseid = !empty($instance->customint4) ? $instance->customint4 : $instance->customint1;
        $root_groupid = !empty($instance->customint5) ? $instance->customint5 : $instance->customint3;
        $logical_courseid = $instance->customint1;
        $logical_groupid = $instance->customint3;
        
        list($enabled, $params) = $DB->get_in_or_equal(explode(',', $CFG->enrol_plugins_enabled), SQL_PARAMS_NAMED, 'e');
        $params['userid'] = $userid;
        $params['parentcourse'] = $root_courseid;
        $params['groupid'] = $root_groupid;
        $params['targetcourseid'] = $instance->courseid;
        $sql = "SELECT ue.*, e.status AS enrolstatus
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = :parentcourse AND e.enrol $enabled AND (e.enrol <> 'metagroup' OR e.customint1 <> :targetcourseid))
                  JOIN {groups_members} gm ON (gm.userid = ue.userid AND gm.groupid = :groupid)
                 WHERE ue.userid = :userid";
        $parentues = $DB->get_records_sql($sql, $params);
        
        // Проверяем также вручную добавленных студентов в логической группе (если она отличается от корневой).
        if ($logical_courseid != $root_courseid || $logical_groupid != $root_groupid) {
            // Ищем студентов, добавленных вручную в логическую группу.
            $manual_params = $params;
            $manual_params['logicalcourse'] = $logical_courseid;
            $manual_params['logicalgroup'] = $logical_groupid;
            $manual_params['targetcourseid'] = $instance->courseid;
            $manual_members = $DB->get_records_sql(
                "SELECT DISTINCT ue.*, e.status AS enrolstatus
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = :logicalcourse AND e.enrol $enabled AND (e.enrol <> 'metagroup' OR e.customint1 <> :targetcourseid))
                  JOIN {groups_members} gm ON (gm.userid = ue.userid AND gm.groupid = :logicalgroup AND (gm.itemid = 0 OR gm.component = ''))
                 WHERE ue.userid = :userid",
                $manual_params
            );
            // Объединяем результаты.
            foreach ($manual_members as $manual_ue) {
                $parentues[] = $manual_ue;
            }
        }
        
        // Current enrolments for this instance.
        $ue = $DB->get_record('user_enrolments', array('enrolid'=>$instance->id, 'userid'=>$userid));

        // First deal with users that are not enrolled in parent.
        if (empty($parentues)) {
            self::user_not_supposed_to_be_here($instance, $ue, $context, $plugin);
            return;
        }

        if (!$parentcontext = context_course::instance($root_courseid, IGNORE_MISSING)) {
            // Weird, we should not get here.
            // Maybe if parent course had been deleted.
            return;
        }

        $skiproles = $plugin->get_config('nosyncroleids', '');
        $skiproles = empty($skiproles) ? array() : explode(',', $skiproles);
        $syncall   = $plugin->get_config('syncall', 1);

        // Roles in parent course (including enrol_metagroup for chain support).
        $parentroles = array();
        list($ignoreroles, $params) = $DB->get_in_or_equal($skiproles, SQL_PARAMS_NAMED, 'ri', false, -1);
        $params['contextid'] = $parentcontext->id;
        $params['userid'] = $userid;
        $select = "contextid = :contextid AND userid = :userid AND roleid $ignoreroles";
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
                if ($instance->customint2 && $instance->customint2 > 0 &&
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
            if ($instance->customint2 && $instance->customint2 > 0) {
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

        // Проверяем, что группа существует
        if (!$groupid || $groupid <= 0) {
            return;
        }
        
        // Проверяем существование группы в БД
        if (!$DB->record_exists('groups', ['id' => $groupid])) {
            if ($verbose) {
                mtrace("  skipping empty group deletion: group $groupid does not exist");
            }
            return;
        }

        // Удаляем пустую группу, если включена соответствующая настройка, и целевая группа присутствует.
        if (get_config('enrol_metagroup', 'deleteemptygroups')) {
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
 * Clean up empty orphaned groups in given courses.
 *
 * Deletes only groups that are empty (0 members) AND not referenced by any
 * enrol_metagroup instance (customint2). Groups still used as targets are kept.
 *
 * @param array $courseids Course IDs to process (empty = all courses with metagroup)
 * @param bool $verbose Unused, kept for API compatibility
 * @param bool $dryrun If true, do not delete, only return what would be deleted
 * @return array ['deleted' => [...], 'skipped' => [...], 'total_deleted' => N]
 */
function enrol_metagroup_cleanup_empty_groups(array $courseids, $verbose = false, $dryrun = false) {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/group/lib.php');

    $result = [
        'deleted' => [],
        'skipped' => [],
        'total_deleted' => 0,
    ];

    if (empty($courseids)) {
        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid FROM {enrol} WHERE enrol = 'metagroup' AND courseid > 0"
        );
    }
    $courseids = array_filter(array_map('intval', $courseids));
    if (empty($courseids)) {
        return $result;
    }

    list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
    $params['enrol'] = 'metagroup';

    $emptygroups = $DB->get_records_sql(
        "SELECT g.id, g.courseid, g.name
           FROM {groups} g
           LEFT JOIN {groups_members} gm ON gm.groupid = g.id
          WHERE g.courseid $insql
          GROUP BY g.id, g.courseid, g.name
         HAVING COUNT(gm.id) = 0",
        $params
    );

    $protected = $DB->get_fieldset_sql(
        "SELECT DISTINCT customint2
           FROM {enrol}
          WHERE enrol = :enrol
            AND courseid $insql
            AND customint2 > 0",
        $params
    );
    $protected = array_flip($protected);

    foreach ($emptygroups as $group) {
        if (isset($protected[$group->id])) {
            $result['skipped'][] = $group;
            continue;
        }
        if ($dryrun) {
            $result['deleted'][] = $group;
        } else {
            try {
                groups_delete_group($group->id);
                $result['deleted'][] = $group;
                $result['total_deleted']++;
            } catch (Exception $e) {
                $result['skipped'][] = (object)array_merge((array)$group, ['error' => $e->getMessage()]);
            }
        }
    }

    if ($dryrun) {
        $result['total_deleted'] = count($result['deleted']);
    }

    return $result;
}

/**
 * Находит корневой курс через цепочку метагрупповых связей.
 *
 * Рекурсивно проходит по цепочке метагрупповых связей, чтобы найти корневой курс.
 * Если курс сам является корневым (не имеет входящих метагрупповых связей), возвращает его данные.
 *
 * @param int $courseid ID курса для поиска корневого
 * @param int|null $groupid ID группы в этом курсе (опционально, для точного определения цепочки)
 * @param array $visited массив посещённых курсов для защиты от циклов (внутренний параметр)
 * @return array|null массив с ключами: 'root_courseid', 'root_groupid', 'root_coursename', 'root_groupname'
 *                    или null, если курс не найден или обнаружен цикл
 */
function enrol_metagroup_find_root_course($courseid, $groupid = null, $visited = []) {
    global $DB;

    // Защита от циклов.
    if (in_array($courseid, $visited)) {
        // Обнаружен цикл, возвращаем null.
        return null;
    }
    $visited[] = $courseid;

    // Проверяем, является ли этот курс дочерним (имеет входящие метагрупповые связи).
    // Ищем метагрупповые связи, где этот курс является целевым (courseid).
    // Если указана группа, ищем связи, которые добавляют студентов в эту группу.
    $conditions = [
        'courseid' => $courseid,
        'enrol' => 'metagroup',
        'status' => ENROL_INSTANCE_ENABLED
    ];
    if ($groupid) {
        $conditions['customint2'] = $groupid;
    }
    
    $parent_enrols = $DB->get_records('enrol', $conditions);

    if (empty($parent_enrols)) {
        // Этот курс является корневым - не имеет входящих метагрупповых связей.
        $course = $DB->get_record('course', ['id' => $courseid], 'id,shortname,fullname');
        if (!$course) {
            return null;
        }
        
        $root_groupid = null;
        $root_groupname = null;
        if ($groupid) {
            $group = $DB->get_record('groups', ['id' => $groupid], 'id,name');
            if ($group) {
                $root_groupid = $groupid;
                $root_groupname = $group->name;
            }
        }
        
        return [
            'root_courseid' => $courseid,
            'root_groupid' => $root_groupid,
            'root_coursename' => $course->shortname ?: $course->fullname,
            'root_groupname' => $root_groupname
        ];
    }

    // Этот курс является дочерним. Берём первую активную метагрупповую связь.
    // Если указана группа, используем связь, которая добавляет в эту группу.
    $parent_enrol = reset($parent_enrols);
    
    // Определяем родительский курс. Используем корневой курс, если он указан, иначе логический.
    $parent_courseid = !empty($parent_enrol->customint4) ? $parent_enrol->customint4 : $parent_enrol->customint1;
    $parent_groupid = !empty($parent_enrol->customint5) ? $parent_enrol->customint5 : $parent_enrol->customint3;

    // Рекурсивно ищем корневой курс от родительского.
    $root = enrol_metagroup_find_root_course($parent_courseid, $parent_groupid, $visited);
    
    if ($root === null) {
        // Обнаружен цикл или ошибка, возвращаем null.
        return null;
    }

    // Если корневая группа ещё не определена, используем группу из родительской связи.
    if ($root['root_groupid'] === null && $parent_groupid) {
        $group = $DB->get_record('groups', ['id' => $parent_groupid], 'id,name');
        if ($group) {
            $root['root_groupid'] = $parent_groupid;
            $root['root_groupname'] = $group->name;
        }
    }

    return $root;
}

/**
 * Определяет, является ли группа сводной (имеет членов из нескольких метагрупповых связей).
 *
 * @param int $courseid ID курса, в котором находится группа
 * @param int $groupid ID группы для проверки
 * @return array массив всех метагрупповых связей (enrol records), которые добавляют студентов в эту группу
 */
function enrol_metagroup_detect_aggregated_group($courseid, $groupid) {
    global $DB;

    // Находим все метагрупповые связи, которые используют эту группу как целевую.
    $enrols = $DB->get_records('enrol', [
        'courseid' => $courseid,
        'customint2' => $groupid,
        'enrol' => 'metagroup',
        'status' => ENROL_INSTANCE_ENABLED
    ]);

    return $enrols;
}

/**
 * Вычисляет перечень курсов-источников для группы на основе способов зачисления участников.
 *
 * Учитывает metagroup (полная глубина, сначала customint4/customint1), meta (2 уровня), остальные (1 уровень).
 * Порядок: корневые курсы → промежуточные → source_courseid последним.
 *
 * @param int $source_courseid ID курса-источника (логический)
 * @param int $source_groupid ID группы-источника
 * @param array $visited защита от циклов (внутренний параметр)
 * @return array массив courseid в порядке: корни → промежуточные → source_courseid
 */
function enrol_metagroup_compute_source_courses($source_courseid, $source_groupid, $visited = []) {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/group/lib.php');

    if (in_array($source_courseid, $visited)) {
        return [];
    }
    $visited[] = $source_courseid;

    if (!$source_groupid) {
        return [$source_courseid];
    }

    $members = groups_get_members($source_groupid);
    if (empty($members)) {
        return [$source_courseid];
    }

    $collected = [];

    list($enabled, $enabled_params) = $DB->get_in_or_equal(
        explode(',', $CFG->enrol_plugins_enabled),
        SQL_PARAMS_NAMED,
        'ep'
    );

    // Один запрос: все уникальные способы зачисления, через которые участники группы попали в неё.
    $params = ['courseid' => $source_courseid, 'groupid' => $source_groupid] + $enabled_params;
    $sql = "SELECT DISTINCT e.*
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid AND e.enrol $enabled
              JOIN {groups_members} gm ON gm.userid = ue.userid AND gm.groupid = :groupid";
    $unique_enrols = $DB->get_records_sql($sql, $params);

    foreach ($unique_enrols as $enrol) {
        if ($enrol->enrol === 'metagroup') {
            $parent_courseid = !empty($enrol->customint4) ? $enrol->customint4 : $enrol->customint1;
            $parent_groupid = !empty($enrol->customint5) ? $enrol->customint5 : $enrol->customint3;
            if ($parent_courseid && $parent_courseid != $source_courseid) {
                $parent_courses = enrol_metagroup_compute_source_courses(
                    $parent_courseid,
                    $parent_groupid ?: 0,
                    $visited
                );
                $parent_courses = array_diff($parent_courses, [$source_courseid]);
                $collected = array_values(array_unique(array_merge($parent_courses, $collected)));
                $root = enrol_metagroup_find_root_course($parent_courseid, $parent_groupid);
                if ($root && !empty($root['root_courseid']) && !in_array($root['root_courseid'], $collected)) {
                    array_unshift($collected, $root['root_courseid']);
                }
            }
        } else if ($enrol->enrol === 'meta') {
            $parent_courseid = !empty($enrol->customint1) ? $enrol->customint1 : null;
            if ($parent_courseid && !in_array($parent_courseid, $collected)) {
                array_unshift($collected, $parent_courseid);
            }
        }
    }

    if (!in_array($source_courseid, $collected)) {
        $collected[] = $source_courseid;
    }
    return array_values(array_unique($collected));
}

/**
 * Строит все пути, как и откуда текущая целевая группа была собрана.
 *
 * Только участники целевой группы (customint2). Трассирует все пути от корневых курсов до группы-источника.
 *
 * @param stdClass $instance enrol record с customint1, customint2, customint3, courseid
 * @return array массив путей. Каждый путь — массив шагов [{courseid, groupid, groupname, courseurl}, ...]
 *               от корня к группе-источнику, плюс целевой курс/группа в конце
 */
function enrol_metagroup_get_chain_for_display($instance) {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/group/lib.php');

    $target_courseid = $instance->courseid;
    $target_groupid = $instance->customint2;
    $source_courseid = $instance->customint1;
    $source_groupid = $instance->customint3;

    if (!$source_groupid) {
        return [];
    }

    $members = groups_get_members($source_groupid);
    if (empty($members)) {
        $step = enrol_metagroup_chain_step($source_courseid, $source_groupid);
        $targetstep = enrol_metagroup_chain_step($target_courseid, $target_groupid);
        return [[$step, $targetstep]];
    }

    list($enabled, $enabled_params) = $DB->get_in_or_equal(
        explode(',', $CFG->enrol_plugins_enabled),
        SQL_PARAMS_NAMED,
        'ep'
    );

    // Один запрос: все уникальные способы зачисления, через которые участники попали в группу-источник.
    $params = ['courseid' => $source_courseid, 'groupid' => $source_groupid] + $enabled_params;
    $sql = "SELECT DISTINCT e.*
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid AND e.enrol $enabled
              JOIN {groups_members} gm ON gm.userid = ue.userid AND gm.groupid = :groupid";
    $unique_enrols = $DB->get_records_sql($sql, $params);

    $paths = [];
    foreach ($unique_enrols as $enrol) {
        $chain = [];
        if ($enrol->enrol === 'metagroup') {
            $parent_courseid = !empty($enrol->customint4) ? $enrol->customint4 : $enrol->customint1;
            $parent_groupid = !empty($enrol->customint5) ? $enrol->customint5 : $enrol->customint3;
            if ($parent_courseid && $parent_courseid != $source_courseid) {
                $chain = enrol_metagroup_build_path_recursive($parent_courseid, $parent_groupid, []);
            }
        } else if ($enrol->enrol === 'meta' && !empty($enrol->customint1)) {
            $chain = [enrol_metagroup_chain_step($enrol->customint1, 0)];
        }
        $chain[] = enrol_metagroup_chain_step($source_courseid, $source_groupid);
        $chain[] = enrol_metagroup_chain_step($target_courseid, $target_groupid);
        $paths[] = $chain;
    }

    if (empty($paths)) {
        $step = enrol_metagroup_chain_step($source_courseid, $source_groupid);
        $targetstep = enrol_metagroup_chain_step($target_courseid, $target_groupid);
        return [[$step, $targetstep]];
    }

    return $paths;
}

/**
 * Рекурсивно строит путь от (courseid, groupid) к корню.
 * @internal
 */
function enrol_metagroup_build_path_recursive($courseid, $groupid, $visited) {
    global $DB, $CFG;

    if (in_array($courseid, $visited)) {
        return [];
    }
    $visited[] = $courseid;

    $conditions = ['courseid' => $courseid, 'enrol' => 'metagroup', 'status' => ENROL_INSTANCE_ENABLED];
    if ($groupid > 0) {
        $conditions['customint2'] = $groupid;
    }
    $parent_enrols = $DB->get_records('enrol', $conditions);

    if (empty($parent_enrols)) {
        return [enrol_metagroup_chain_step($courseid, $groupid)];
    }

    $parent = reset($parent_enrols);
    $parent_courseid = !empty($parent->customint4) ? $parent->customint4 : $parent->customint1;
    $parent_groupid = !empty($parent->customint5) ? $parent->customint5 : $parent->customint3;

    $prev = enrol_metagroup_build_path_recursive($parent_courseid, $parent_groupid, $visited);
    $prev[] = enrol_metagroup_chain_step($courseid, $groupid);
    return $prev;
}

/**
 * Формирует шаг цепочки для отображения.
 * @internal
 */
function enrol_metagroup_chain_step($courseid, $groupid) {
    global $DB;

    $courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
    $course = $DB->get_record('course', ['id' => $courseid], 'id,shortname,fullname');
    $coursename = $course ? format_string(get_course_display_name_for_list($course)) : (string)$courseid;

    $groupname = null;
    if ($groupid) {
        $group = $DB->get_record('groups', ['id' => $groupid], 'id,name');
        $groupname = $group ? $group->name : null;
    }

    return [
        'courseid' => $courseid,
        'groupid' => $groupid,
        'groupname' => $groupname,
        'coursename' => $coursename,
        'courseurl' => $courseurl->out(false),
    ];
}

/**
 * Обработка потерянных связей (когда родительский курс или группа удалены).
 *
 * @param int|null $courseid ID курса для обработки, null означает все курсы
 * @param bool $verbose подробный вывод
 * @return array массив ID способов зачисления с потерянными связями
 */
function enrol_metagroup_sync_lost_links($courseid, $verbose) {
    global $DB;

    $enrols_having_link_lost = [];
    $lostlinkaction = get_config('enrol_metagroup', 'lostlinkaction');

        // Обрабатываем все потерянные связи, кроме случая ENROL_EXT_REMOVED_KEEP.
        // Проверяем логические связи (customint1, customint3), так как они используются для отображения.
        if ($lostlinkaction != ENROL_EXT_REMOVED_KEEP) {
        $sql = "SELECT distinct e.*
                FROM {enrol} e
                LEFT JOIN {course} c ON c.id = COALESCE(e.customint4, e.customint1)
                LEFT JOIN {groups} g ON g.id = COALESCE(e.customint5, e.customint3)
                WHERE e.enrol = 'metagroup'
                AND (c.id IS NULL OR g.id IS NULL)";

        $rs = $DB->get_records_sql($sql);
        $lost_links_count = 0;
        $error_count = 0;

        foreach ($rs as $record) {
            try {
                $enrols_having_link_lost[] = $record->id;

                if ($verbose) {
                    mtrace('dealing with a lost link: enrolid=' . ($record->id));
                }
                $lost_links_count += 1;
                enrol_metagroup_deal_with_lost_link($record);

                if ($lostlinkaction == ENROL_EXT_REMOVED_UNENROL) {
                    // Удаляем опустевшую группу, если включена соответствующая настройка плагина.
                    enrol_metagroup_handler::delete_empty_group_as_configured($record->customint2, $verbose);
                }
            } catch (Exception $e) {
                $error_count++;
                if ($verbose) {
                    mtrace("  ERROR processing lost link: " . $e->getMessage());
                    mtrace("    Details: enrolid=" . $record->id . ", courseid=" . $record->courseid . ", customint1=" . $record->customint1 . ", customint3=" . $record->customint3);
                }
                error_log("enrol_metagroup sync_lost_links error: " . $e->getMessage() . 
                          " (enrolid=" . $record->id . ", courseid=" . $record->courseid . ")");
                continue;
            }
        }
        if ($verbose) {
            mtrace("Done dealing with $lost_links_count lost link(s).");
            if ($error_count > 0) {
                mtrace("Lost links processing errors: $error_count");
            }
        }
    }

    return $enrols_having_link_lost;
}

/**
 * Создание/восстановление отсутствующих зачислений и членств в группах.
 *
 * @param int|null $courseid ID курса для обработки, null означает все курсы
 * @param bool $verbose подробный вывод
 * @param array $enrols_having_link_lost массив ID способов зачисления с потерянными связями (для пропуска)
 * @param array $instances кэш экземпляров способов зачисления (передаётся по ссылке)
 * @param array $local_groups кэш групп (передаётся по ссылке)
 * @param enrol_metagroup_plugin $metagroup экземпляр плагина
 * @param array $skiproles массив ID ролей для пропуска
 * @param bool $syncall синхронизировать всех пользователей независимо от ролей
 * @return int количество обработанных записей
 */
function enrol_metagroup_sync_missing_enrolments($courseid, $verbose, $enrols_having_link_lost, &$instances, &$local_groups, $metagroup, $skiproles, $syncall) {
    global $CFG, $DB;

    // Итерация по всем пользователям, которые ещё не зачислены (не в курсе или/и не в целевой группе).
    // Для каждого активного зачисления каждого пользователя находим минимальную
    // дату начала зачисления и максимальную дату окончания зачисления.
    // Этот SQL опирается на тот факт, что ENROL_USER_ACTIVE < ENROL_USER_SUSPENDED
    // и ENROL_INSTANCE_ENABLED < ENROL_INSTANCE_DISABLED. Условие "pue.status + pe.status = 0" означает
    // что зачисление активно. Когда MIN(pue.status + pe.status)=0 это означает, что существует активное
    // зачисление.
    // Добавлено: ограничение `ue`s до членов исходной группы (и членство в группе должно быть создано соответствующим экземпляром зачисления).
    // Возвращаемая запись должна иметь: ue_id == NULL (означает необходимость зачисления) и/или gm_id == NULL (означает необходимость добавления в целевую группу).
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
              JOIN {enrol} pe ON (pe.id = pue.enrolid AND pe.enrol $enabled)
              JOIN {groups_members} pgm ON (pgm.userid = pue.userid)
              JOIN {enrol} e ON (COALESCE(e.customint4, e.customint1) = pe.courseid AND COALESCE(e.customint5, e.customint3) = pgm.groupid AND e.enrol = 'metagroup' AND e.status = :enrolstatus $onecourse AND (pe.enrol <> 'metagroup' OR pe.customint1 <> e.courseid))
              JOIN {user} u ON (u.id = pue.userid AND u.deleted = 0)
         LEFT JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = pue.userid)
         LEFT JOIN {groups_members} gm ON (gm.userid = pue.userid AND e.customint2 = gm.groupid AND gm.itemid = e.id)
             WHERE ue.id IS NULL OR gm.id IS NULL
             GROUP BY pue.userid, e.id";

    // Создание / восстановление зачислений пользователей и членств в группах.
    $rs = $DB->get_recordset_sql($sql, $params);
    $rs_counter = 0;
    $error_count = 0;
    foreach($rs as $ue) {
        try {
            if(in_array($ue->enrolid, $enrols_having_link_lost)) {
                continue;
            }

            if (!isset($instances[$ue->enrolid])) {
                // Добавляем экземпляр в кэш.
                $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
            }
            $instance = $instances[$ue->enrolid];

            if (!$syncall) {
                // Проверяем, есть ли у текущего пользователя какие-либо синхронизируемые роли (в корневом курсе).
                // Это может быть медленно, если очень много пользователей игнорируется при синхронизации.
                $root_courseid = !empty($instance->customint4) ? $instance->customint4 : $instance->customint1;
                $parentcontext = context_course::instance($root_courseid);
                list($ignoreroles, $params) = $DB->get_in_or_equal($skiproles, SQL_PARAMS_NAMED, 'ri', false, -1);
                $params['contextid'] = $parentcontext->id;
                $params['userid'] = $ue->userid;
                $select = "contextid = :contextid AND userid = :userid AND roleid $ignoreroles";
                if (!$DB->record_exists_select('role_assignments', $select, $params)) {
                    // Не повезло, у этого пользователя нет ни одной роли, которую мы хотим в родительском курсе.
                    if ($verbose) {
                        mtrace("  skipping enrolling: $ue->userid ==> $instance->courseid (user without role)");
                    }
                    continue;
                }
            }

            ++$rs_counter;

        // Теперь у нас есть агрегированные значения, которые мы будем использовать для статуса зачисления метагруппы, timeend и timestart.
        // Снова используем тот факт, что active=0 и disabled/suspended=1. Только когда MIN(pue.status + pe.status)=0 зачисление активно:
        $ue->status = ($ue->status == ENROL_USER_ACTIVE + ENROL_INSTANCE_ENABLED) ? ENROL_USER_ACTIVE : ENROL_USER_SUSPENDED;
        // Timeend 9999999999 использовался вместо 0 в функции "MAX()":
        $ue->timeend = ($ue->timeend == 9999999999) ? 0 : (int)$ue->timeend;
        // Timestart 9999999999 возможен только когда нет активных зачислений:
        $ue->timestart = ($ue->timestart == 9999999999) ? 0 : (int)$ue->timestart;

        if (!$ue->ue_id) {
            // Ещё не зачислен.
            $metagroup->enrol_user($instance, $ue->userid, null, $ue->timestart, $ue->timeend, $ue->status);

            if ($verbose) {
                mtrace("  enrolled: $ue->userid ==> $instance->courseid");
            }
        }
        if (!$ue->gm_id && $instance->customint2 && $instance->customint2 > 0) {
            // Ещё не член группы.
            $gid = $instance->customint2;

            // Сначала убеждаемся, что целевая группа существует.
            if (isset($local_groups[$gid])) {
                $group = $local_groups[$gid];
            } else {
                if (!$group = groups_get_group($gid)) {
                    // Добавляем новую группу.
                    $new_groupid = enrol_metagroup_create_new_group($instance->courseid, $instance->customint3, false);

                    if ($new_groupid != $gid) {
                        // Обновляем экземпляр с новым ID целевой группы!
                        $DB->update_record('enrol', (object)['id' => $instance->id, 'customint2' => $new_groupid]);
                    }

                    $group = groups_get_group($new_groupid);
                }
                $local_groups[$gid] = $group;

            }

            // Добавляем члена в группу.
            $ok = groups_add_member($group, $ue->userid, 'enrol_metagroup', $instance->id);

            if ($verbose) {
                mtrace("  added to group: $ue->userid ==> $gid in course $instance->courseid (success: $ok).");
            }
        }
        } catch (Exception $e) {
            $error_count++;
            if ($verbose) {
                mtrace("  ERROR processing missing enrolment: " . $e->getMessage());
                mtrace("    Details: userid=$ue->userid, enrolid=$ue->enrolid, courseid=" . (isset($instance) ? $instance->courseid : 'unknown'));
            }
            error_log("enrol_metagroup sync_missing_enrolments error: " . $e->getMessage() . 
                      " (userid=$ue->userid, enrolid=$ue->enrolid)");
            continue;
        }
    }
    $rs->close();

    if ($verbose) {
        mtrace("Absent/incomplete enrolments processed: $rs_counter.");
        if ($error_count > 0) {
            mtrace("Missing enrolments processing errors: $error_count");
        }
    }

    return $rs_counter;
}

/**
 * Удаление лишних зачислений и перемещение между группами.
 *
 * @param int|null $courseid ID курса для обработки, null означает все курсы
 * @param bool $verbose подробный вывод
 * @param array $enrols_having_link_lost массив ID способов зачисления с потерянными связями (для пропуска)
 * @param array $instances кэш экземпляров способов зачисления (передаётся по ссылке)
 * @param enrol_metagroup_plugin $metagroup экземпляр плагина
 * @param int $unenrolaction действие при удалении зачисления
 * @return int количество обработанных записей
 */
function enrol_metagroup_sync_extra_enrolments($courseid, $verbose, $enrols_having_link_lost, &$instances, $metagroup, $unenrolaction) {
    global $CFG, $DB;

    // Cache for group existence checks to avoid duplicate DB queries
    $group_exists_cache = [];

    // Отчисление / удаление из группы по мере необходимости - игнорируем флаг enabled, мы хотим избавиться от существующих зачислений в любом случае.
    // Добавлено: ограничение `ue`s до членов исходной группы (и членство в группе должно быть создано соответствующим экземпляром зачисления).
    // Возвращаемая запись должна иметь: parent_enrolid == NULL (означает необходимость отчисления) и/или old_groupid != NULL (означает необходимость удаления из этой группы).
    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
    list($enabled, $params) = $DB->get_in_or_equal(explode(',', $CFG->enrol_plugins_enabled), SQL_PARAMS_NAMED, 'e');
    $params['courseid'] = $courseid;
    $sql = "SELECT ue.*, xpe.id AS parent_enrolid, gm.groupid AS old_groupid
              FROM {user_enrolments} ue
              JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'metagroup' $onecourse)
         LEFT JOIN {groups_members} gm ON (gm.userid = ue.userid AND gm.itemid = e.id)
         LEFT JOIN ({user_enrolments} xpue
                      JOIN {enrol} xpe ON (xpe.id = xpue.enrolid AND xpe.enrol $enabled)
                      JOIN {groups_members} xpgm ON (xpgm.userid = xpue.userid)
                   ) ON (xpe.courseid = COALESCE(e.customint4, e.customint1) AND xpue.userid = ue.userid AND xpgm.groupid = COALESCE(e.customint5, e.customint3) AND (xpe.enrol <> 'metagroup' OR xpe.customint1 <> e.courseid))
             WHERE xpue.userid IS NULL OR (gm.id IS NOT NULL AND e.customint2 <> gm.groupid)";

    $rs = $DB->get_recordset_sql($sql, $params);
    $rs_counter = 0;
    $error_count = 0;
    foreach($rs as $ue) {
        try {
            if (in_array($ue->enrolid, $enrols_having_link_lost)) {
                continue;
            }

            ++$rs_counter;

        if (!isset($instances[$ue->enrolid])) {
            $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
        }
        $instance = $instances[$ue->enrolid];

        if ($ue->old_groupid && $ue->old_groupid != $instance->customint2) {
            // Перемещаем пользователя из старой группы в новую.
            // Проверяем, что customint2 содержит валидный ID группы (не константу CREATE_GROUP или CREATE_SEPARATE_GROUPS)
            if ($instance->customint2 <= 0) {
                if ($verbose) {
                    mtrace("  skipping group move: invalid group ID ($instance->customint2) for instance $instance->id");
                }
                continue;
            }
            $ok = groups_add_member($instance->customint2, $ue->userid, 'enrol_metagroup', $instance->id);
            if ($verbose) {
                mtrace("  added user to group: $ue->userid ==> $instance->customint2 in course $instance->courseid (success: $ok).");
            }

            // Проверяем существование старой группы с использованием кэша
            $cache_key = $ue->old_groupid;
            if (!isset($group_exists_cache[$cache_key])) {
                $group_exists_cache[$cache_key] = ($ue->old_groupid > 0 && 
                    $DB->record_exists('groups', ['id' => $ue->old_groupid, 'courseid' => $instance->courseid]));
            }
            
            if ($group_exists_cache[$cache_key]) {
                $ok = groups_remove_member($ue->old_groupid, $ue->userid);
                if ($verbose) {
                    mtrace("  removed user from group: $ue->userid ==> $ue->old_groupid in course $instance->courseid (success: $ok).");
                }
                
                // Проверяем существование группы перед вызовом delete_empty_group_as_configured
                // (функция сама может проверять, но лучше убедиться)
                enrol_metagroup_handler::delete_empty_group_as_configured($ue->old_groupid, $verbose);
            } else {
                if ($verbose) {
                    mtrace("  skipping group removal: group $ue->old_groupid does not exist for user $ue->userid in course $instance->courseid");
                }
            }
        }

        if (!$ue->parent_enrolid) {
            // Отчисление / приостановка в соответствии с настройками.
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
        } catch (Exception $e) {
            $error_count++;
            if ($verbose) {
                mtrace("  ERROR processing extra enrolment: " . $e->getMessage());
                mtrace("    Details: userid=$ue->userid, enrolid=$ue->enrolid, courseid=" . (isset($instance) ? $instance->courseid : 'unknown') . ", old_groupid=" . (isset($ue->old_groupid) ? $ue->old_groupid : 'N/A'));
            }
            error_log("enrol_metagroup sync_extra_enrolments error: " . $e->getMessage() . 
                      " (userid=$ue->userid, enrolid=$ue->enrolid)");
            continue;
        }
    }
    $rs->close();

    if ($verbose) {
        mtrace("Extra enrolments processed: $rs_counter.");
        if ($error_count > 0) {
            mtrace("Extra enrolments processing errors: $error_count");
        }
    }

    return $rs_counter;
}

/**
 * Обновление статусов зачислений на основе родительских зачислений.
 *
 * @param int|null $courseid ID курса для обработки, null означает все курсы
 * @param bool $verbose подробный вывод
 * @param array $enrols_having_link_lost массив ID способов зачисления с потерянными связями (для пропуска)
 * @param array $instances кэш экземпляров способов зачисления (передаётся по ссылке)
 * @param enrol_metagroup_plugin $metagroup экземпляр плагина
 * @param array $skiproles массив ID ролей для пропуска
 * @param bool $syncall синхронизировать всех пользователей независимо от ролей
 * @param int $unenrolaction действие при удалении зачисления
 */
function enrol_metagroup_sync_status_updates($courseid, $verbose, $enrols_having_link_lost, &$instances, $metagroup, $skiproles, $syncall, $unenrolaction) {
    global $CFG, $DB;

    // Обновление статусов зачислений - метагрупповые зачисления игнорируются, чтобы избежать рекурсии.
    // Примечание: трюк здесь в том, что константы активного зачисления и экземпляра имеют значение 0.
    // Запрос строит список всех неметагрупповых зачислений, которые находятся на курсах (детях), связанных метагрупповым
    // зачислением, затем группирует их по курсу, который связан с ними (родителям).
    //
    // Он вернёт результаты только там, где есть разница между статусом родителя и наименьшим статусом
    // детей (помните, что 0 - это активный, любой другой статус - это какая-то форма неактивного), или время самого раннего ненулевого
    // времени начала ребёнка отличается от родителя, или самая длинная эффективная дата окончания изменилась.
    //
    // Последние два оператора case в предложении HAVING предназначены для игнорирования любых неактивных записей детей при вычислении
    // времени начала и окончания.
    // Добавлено: ограничение `ue`s до членов исходной группы (которая установлена в экземпляре зачисления метагруппы).
    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
    list($enabled, $params) = $DB->get_in_or_equal(explode(',', $CFG->enrol_plugins_enabled), SQL_PARAMS_NAMED, 'e');
    $params['courseid'] = $courseid;
    $sql = "SELECT ue.userid, ue.enrolid,
                   MIN(xpue.status + xpe.status) AS pstatus,
                   MIN(CASE WHEN (xpue.status + xpe.status = 0) THEN xpue.timestart ELSE 9999999999 END) AS ptimestart,
                   MAX(CASE WHEN (xpue.status + xpe.status = 0) THEN
                                 (CASE WHEN xpue.timeend = 0 THEN 9999999999 ELSE xpue.timeend END)
                            ELSE 0 END) AS ptimeend
              FROM {user_enrolments} ue
              JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'metagroup' $onecourse)
              JOIN {user_enrolments} xpue ON (xpue.userid = ue.userid)
              JOIN {enrol} xpe ON (xpe.id = xpue.enrolid AND xpe.enrol $enabled AND xpe.courseid = COALESCE(e.customint4, e.customint1) AND (xpe.enrol <> 'metagroup' OR xpe.customint1 <> e.courseid))
              JOIN {groups_members} pgm ON (pgm.userid = ue.userid AND COALESCE(e.customint5, e.customint3) = pgm.groupid)
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
    $error_count = 0;
    foreach($rs as $ue) {
        try {
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
            // Это может быть медленно, если очень много пользователей игнорируется при синхронизации.
            $root_courseid = !empty($instance->customint4) ? $instance->customint4 : $instance->customint1;
            $parentcontext = context_course::instance($root_courseid);
            list($ignoreroles, $params) = $DB->get_in_or_equal($skiproles, SQL_PARAMS_NAMED, 'ri', false, -1);
            $params['contextid'] = $parentcontext->id;
            $params['userid'] = $ue->userid;
            $select = "contextid = :contextid AND userid = :userid AND roleid $ignoreroles";
            if (!$DB->record_exists_select('role_assignments', $select, $params)) {
                // Не повезло, у этого пользователя нет ни одной роли, которую мы хотим в родительском курсе.
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
        } catch (Exception $e) {
            $error_count++;
            if ($verbose) {
                mtrace("  ERROR processing status update: " . $e->getMessage());
                mtrace("    Details: userid=$ue->userid, enrolid=$ue->enrolid, courseid=" . (isset($instance) ? $instance->courseid : 'unknown'));
            }
            error_log("enrol_metagroup sync_status_updates error: " . $e->getMessage() . 
                      " (userid=$ue->userid, enrolid=$ue->enrolid)");
            continue;
        }
    }
    $rs->close();
    
    if ($verbose && $error_count > 0) {
        mtrace("Status updates processing errors: $error_count");
    }
}

/**
 * Назначение всех необходимых ролей (в настоящее время отсутствующих).
 *
 * @param int|null $courseid ID курса для обработки, null означает все курсы
 * @param bool $verbose подробный вывод
 * @param enrol_metagroup_plugin $metagroup экземпляр плагина
 * @param array $allroles массив всех ролей
 */
function enrol_metagroup_sync_roles_assign($courseid, $verbose, $metagroup, $allroles) {
    global $CFG, $DB;

    $enabled = explode(',', $CFG->enrol_plugins_enabled);
    foreach($enabled as $k=>$v) {
        $enabled[$k] = 'enrol_'.$v;
    }
    $enabled[] = ''; // Ручные назначения также реплицируются.
    $enabled[] = 'enrol_metagroup'; // Chain support: copy roles from parent metagroup too.

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
              JOIN {enrol} e ON (COALESCE(e.customint4, e.customint1) = pc.instanceid AND e.enrol = 'metagroup' $onecourse AND e.status = :enabledinstance)
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
    $error_count = 0;
    foreach($rs as $ra) {
        try {
            role_assign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_metagroup', $ra->enrolid);
            if ($verbose) {
                mtrace("  assigning role: $ra->userid ==> $ra->courseid as ".$allroles[$ra->roleid]->shortname);
            }
        } catch (Exception $e) {
            $error_count++;
            if ($verbose) {
                mtrace("  ERROR processing role assignment: " . $e->getMessage());
                mtrace("    Details: userid=$ra->userid, roleid=$ra->roleid, enrolid=$ra->enrolid, courseid=$ra->courseid");
            }
            error_log("enrol_metagroup sync_roles_assign error: " . $e->getMessage() . 
                      " (userid=$ra->userid, roleid=$ra->roleid, enrolid=$ra->enrolid)");
            continue;
        }
    }
    $rs->close();
    
    if ($verbose && $error_count > 0) {
        mtrace("Role assignments processing errors: $error_count");
    }
}

/**
 * Удаление нежелательных ролей - включая игнорируемые роли и отключённые плагины тоже.
 *
 * @param int|null $courseid ID курса для обработки, null означает все курсы
 * @param bool $verbose подробный вывод
 * @param enrol_metagroup_plugin $metagroup экземпляр плагина
 * @param array $allroles массив всех ролей
 * @param int $unenrolaction действие при удалении зачисления
 */
function enrol_metagroup_sync_roles_unassign($courseid, $verbose, $metagroup, $allroles, $unenrolaction) {
    global $DB;

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
              JOIN {context} pc ON (pc.instanceid = COALESCE(e.customint4, e.customint1) AND pc.contextlevel = :coursecontext)
         LEFT JOIN {role_assignments} pra ON (pra.contextid = pc.id AND pra.userid = ra.userid AND pra.roleid = ra.roleid $notignored)
         LEFT JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = ra.userid AND ue.status = :activeuser)
             WHERE pra.id IS NULL OR ue.id IS NULL OR e.status <> :enabledinstance";

    if ($unenrolaction != ENROL_EXT_REMOVED_SUSPEND) {
        $rs = $DB->get_recordset_sql($sql, $params);
        $error_count = 0;
        foreach($rs as $ra) {
            try {
                role_unassign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_metagroup', $ra->itemid);
                if ($verbose) {
                    mtrace("  unassigning role: $ra->userid ==> $ra->courseid as ".$allroles[$ra->roleid]->shortname);
                }
            } catch (Exception $e) {
                $error_count++;
                if ($verbose) {
                    mtrace("  ERROR processing role unassignment: " . $e->getMessage());
                    mtrace("    Details: userid=$ra->userid, roleid=$ra->roleid, itemid=$ra->itemid, courseid=$ra->courseid");
                }
                error_log("enrol_metagroup sync_roles_unassign error: " . $e->getMessage() . 
                          " (userid=$ra->userid, roleid=$ra->roleid, itemid=$ra->itemid)");
                continue;
            }
        }
        $rs->close();
        
        if ($verbose && $error_count > 0) {
            mtrace("Role unassignments processing errors: $error_count");
        }
    }
}

/**
 * Отчисление или приостановка пользователей без синхронизируемых ролей, если syncall отключён.
 *
 * @param int|null $courseid ID курса для обработки, null означает все курсы
 * @param bool $verbose подробный вывод
 * @param array $instances кэш экземпляров способов зачисления (передаётся по ссылке)
 * @param enrol_metagroup_plugin $metagroup экземпляр плагина
 * @param int $unenrolaction действие при удалении зачисления
 */
function enrol_metagroup_cleanup_users_without_roles($courseid, $verbose, &$instances, $metagroup, $unenrolaction) {
    global $DB;

    $error_count = 0;

    if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
        // Отчисление.
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
            try {
                if (!isset($instances[$ue->enrolid])) {
                    $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
                }
                $instance = $instances[$ue->enrolid];
                $metagroup->unenrol_user($instance, $ue->userid);
                if ($verbose) {
                    mtrace("  unenrolling: $ue->userid ==> $instance->courseid (user without role)");
                }
            } catch (Exception $e) {
                $error_count++;
                if ($verbose) {
                    mtrace("  ERROR processing cleanup unenrol: " . $e->getMessage());
                    mtrace("    Details: userid=$ue->userid, enrolid=$ue->enrolid, courseid=" . (isset($instance) ? $instance->courseid : 'unknown'));
                }
                error_log("enrol_metagroup cleanup_users_without_roles (unenrol) error: " . $e->getMessage() . 
                          " (userid=$ue->userid, enrolid=$ue->enrolid)");
                continue;
            }
        }
        $ues->close();

    } else {
        // Просто приостанавливаем пользователей.
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
            try {
                if (!isset($instances[$ue->enrolid])) {
                    $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
                }
                $instance = $instances[$ue->enrolid];
                $metagroup->update_user_enrol($instance, $ue->userid, ENROL_USER_SUSPENDED);
                if ($verbose) {
                    mtrace("  suspending: $ue->userid ==> $instance->courseid (user without role)");
                }
            } catch (Exception $e) {
                $error_count++;
                if ($verbose) {
                    mtrace("  ERROR processing cleanup suspend: " . $e->getMessage());
                    mtrace("    Details: userid=$ue->userid, enrolid=$ue->enrolid, courseid=" . (isset($instance) ? $instance->courseid : 'unknown'));
                }
                error_log("enrol_metagroup cleanup_users_without_roles (suspend) error: " . $e->getMessage() . 
                          " (userid=$ue->userid, enrolid=$ue->enrolid)");
                continue;
            }
        }
        $ues->close();
    }
    
    if ($verbose && $error_count > 0) {
        mtrace("Cleanup users processing errors: $error_count");
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

    // Удаляем все роли, если синхронизация метагруппы отключена, они могут быть воссозданы позже здесь в cron.
    if (!enrol_is_enabled('metagroup')) {
        if ($verbose) {
            mtrace('Metagroup plugin is disabled, unassigning all plugin roles and stopping.');
        }
        role_unassign_all(array('component'=>'enrol_metagroup'));
        return 2;
    }

    // К сожалению, это может занять много времени, выполнение может быть безопасно прервано.
    core_php_time_limit::raise();
    raise_memory_limit(MEMORY_HUGE);

    if ($verbose) {
        mtrace('Starting user enrolment synchronisation (enrol_metagroup) ...');
    }

    // Шаг 1: Обработка потерянных связей (когда родительский курс или группа удалены).
    $enrols_having_link_lost = enrol_metagroup_sync_lost_links($courseid, $verbose);

    // Инициализация customtext1 (source_courses) для экземпляров с пустым полем (созданных до фичи).
    $onecourse = $courseid ? "AND courseid = :courseid" : "";
    $params = ['enrol' => 'metagroup'];
    if ($courseid) {
        $params['courseid'] = $courseid;
    }
    $empty_customtext = $DB->get_records_select(
        'enrol',
        "enrol = :enrol AND (customtext1 IS NULL OR customtext1 = '') $onecourse",
        $params,
        'id ASC',
        'id,courseid,customint1,customint2,customint3',
        0,
        50
    );
    foreach ($empty_customtext as $rec) {
        $source_courses = enrol_metagroup_compute_source_courses(
            $rec->customint1,
            $rec->customint3
        );
        $json = json_encode(['source_courses' => $source_courses]);
        $DB->set_field('enrol', 'customtext1', $json, ['id' => $rec->id]);
        if ($verbose) {
            mtrace("  Initialized customtext1 for enrol instance {$rec->id}");
        }
    }

    // Инициализация кэшей и получение настроек плагина.
    $instances = array(); // Кэш экземпляров способов зачисления.
    $local_groups = array(); // Кэш экземпляров групп.

    $metagroup = enrol_get_plugin('metagroup');
    $unenrolaction = $metagroup->get_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);
    $skiproles = $metagroup->get_config('nosyncroleids', '');
    $skiproles = empty($skiproles) ? array() : explode(',', $skiproles);
    $syncall = $metagroup->get_config('syncall', 1);
    $allroles = get_all_roles();

    // Шаг 2: Создание/восстановление отсутствующих зачислений и членств в группах.
    enrol_metagroup_sync_missing_enrolments($courseid, $verbose, $enrols_having_link_lost, $instances, $local_groups, $metagroup, $skiproles, $syncall);

    // Шаг 3: Удаление лишних зачислений и перемещение между группами.
    enrol_metagroup_sync_extra_enrolments($courseid, $verbose, $enrols_having_link_lost, $instances, $metagroup, $unenrolaction);

    // Шаг 4: Обновление статусов зачислений на основе родительских зачислений.
    enrol_metagroup_sync_status_updates($courseid, $verbose, $enrols_having_link_lost, $instances, $metagroup, $skiproles, $syncall, $unenrolaction);

    // Шаг 5: Назначение всех необходимых ролей (в настоящее время отсутствующих).
    enrol_metagroup_sync_roles_assign($courseid, $verbose, $metagroup, $allroles);

    // Шаг 6: Удаление нежелательных ролей.
    enrol_metagroup_sync_roles_unassign($courseid, $verbose, $metagroup, $allroles, $unenrolaction);

    // Шаг 7: Отчисление или приостановка пользователей без синхронизируемых ролей, если syncall отключён.
    if (!$syncall) {
        enrol_metagroup_cleanup_users_without_roles($courseid, $verbose, $instances, $metagroup, $unenrolaction);
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

                // Отключаем способ зачисления и удаляем всех студентов из курса.
                // Примечание: мы не удаляем сам способ зачисления (delete_instance), а только отключаем его
                // и удаляем всех пользователей, чтобы группа могла быть очищена.
                $plugin->update_status($enrol, ENROL_INSTANCE_DISABLED, false);

                // Проверяем, что customint2 содержит валидный ID группы
                if ($enrol->customint2 <= 0) {
                    break;
                }
                // Проверяем, что группа существует перед использованием
                if (!$DB->record_exists('groups', ['id' => $enrol->customint2, 'courseid' => $enrol->courseid])) {
                    break;
                }
                $target_group_members = groups_get_members($enrol->customint2);
                if ($target_group_members) {
                    foreach($target_group_members as $student) {
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

    // Шаг 1: Удаляем существующий инкремент вида " (N)" в конце имени
    $groupname = preg_replace('/ \(\d+\)$/', '', $groupname);

    $addsuffix = (bool) get_config('enrol_metagroup', 'addgroupsuffix');

    // Шаг 2: Проверяем, заканчивается ли имя на суффикс (с учетом возможного инкремента)
    $suffixes = [' (связ.)', ' (linked)'];
    $has_suffix = false;
    foreach ($suffixes as $suffix) {
        if (mb_substr($groupname, -mb_strlen($suffix)) === $suffix) {
            $has_suffix = true;
            break;
        }
        // Проверяем также с инкрементом вида " (связ.) (2)"
        if (preg_match('/' . preg_quote($suffix, '/') . ' \(\d+\)$/', $groupname)) {
            $has_suffix = true;
            break;
        }
    }

    // Шаг 3: Применяем шаблон только если настройка включена и суффикс отсутствует
    if ($addsuffix && !$has_suffix) {
        $a = new stdClass();
        $a->name = $groupname;
        $a->increment = '';
        $groupname = trim(get_string('defaultgroupnametext', 'enrol_metagroup', $a));
    }

    // Базовое имя для добавления (2), (3) при отключённом суффиксе.
    $basegroupname = $groupname;

    // Check to see if the group name already exists in this course.
    // Add an incremented number if it does.
    $inc = 1;
    while ($DB->record_exists('groups', array('name' => $groupname, 'courseid' => $courseid))) {
        if ($always_create_new) {
            if (!$addsuffix) {
                $groupname = $basegroupname . ' (' . (++$inc) . ')';
            } else if ($has_suffix) {
                // Если суффикс уже есть, добавляем инкремент напрямую
                $groupname = $groupname . ' (' . (++$inc) . ')';
            } else {
                // Если суффикса нет, используем шаблон с инкрементом
                $a->increment = '(' . (++$inc) . ')';
                $groupname = trim(get_string('defaultgroupnametext', 'enrol_metagroup', $a));
            }

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

/**
 * Create a metagroup link between source and target groups.
 * 
 * Public API function for creating metagroup enrolment instances programmatically.
 * Can be called from other plugins for bulk synchronization.
 * 
 * @param int $target_courseid ID of the target course (where students will be enrolled)
 * @param int $source_courseid ID of the source course (where source group is located)
 * @param int $source_groupid ID of the source group
 * @param int|null $target_groupid ID of the target group (if null, will be created automatically)
 * @param array $options Optional array with additional options:
 *   - 'target_group_name' => string|null (optional explicit name for target group, avoids automatic suffix)
 *   - 'status' => ENROL_INSTANCE_ENABLED|ENROL_INSTANCE_DISABLED (default: ENABLED)
 *   - 'roleid' => int (default: student role)
 *   - 'sync_on_create' => bool (default: true, whether to sync immediately after creation)
 * @return stdClass|false Full enrolment instance object on success, false on failure
 */
function enrol_metagroup_create_link($target_courseid, $source_courseid, $source_groupid, $target_groupid = null, $options = []) {
    global $DB, $CFG;

    require_once($CFG->dirroot.'/enrol/metagroup/lib.php');
    require_once($CFG->dirroot.'/group/lib.php');

    // Validate input parameters
    if (empty($target_courseid) || empty($source_courseid) || empty($source_groupid)) {
        return false;
    }

    // Check if courses exist
    if (!$DB->record_exists('course', ['id' => $target_courseid])) {
        return false;
    }
    if (!$DB->record_exists('course', ['id' => $source_courseid])) {
        return false;
    }

    // Check if source group exists
    if (!$DB->record_exists('groups', ['id' => $source_groupid, 'courseid' => $source_courseid])) {
        return false;
    }

    // Check for existing link (including suspended ones) - search without target_groupid filter first
    $conditions = [
        'courseid' => $target_courseid,
        'enrol' => 'metagroup',
        'customint1' => $source_courseid,
        'customint3' => $source_groupid,
    ];
    // Don't filter by target_groupid when searching - we want to find existing link regardless
    $existing = $DB->get_record('enrol', $conditions);

    if ($existing) {
        // Update existing link: activate and update parameters
        $update_data = new stdClass();
        $update_data->id = $existing->id;
        $update_data->status = $options['status'] ?? ENROL_INSTANCE_ENABLED;
        
        if (isset($options['roleid'])) {
            $update_data->roleid = $options['roleid'];
        }
        
        // Check if existing link has invalid target group and needs new one
        $needs_new_group = false;
        if ($existing->customint2 <= 0 || !$DB->record_exists('groups', ['id' => $existing->customint2, 'courseid' => $target_courseid])) {
            $needs_new_group = true;
        }
        
        // If target_groupid is null, create group automatically
        if ($target_groupid === null || $needs_new_group) {
            $target_group_name = $options['target_group_name'] ?? null;
            $target_groupid = enrol_metagroup_create_new_group(
                $target_courseid,
                $source_groupid,
                $target_group_name  // If specified, uses this name without suffixes
            );
            if (!$target_groupid) {
                return false;
            }
            $update_data->customint2 = $target_groupid;
        } else {
            // Validate that target group exists
            if (!$DB->record_exists('groups', ['id' => $target_groupid, 'courseid' => $target_courseid])) {
                return false;
            }
            // Update target group if it changed
            if ($existing->customint2 != $target_groupid) {
                $update_data->customint2 = $target_groupid;
            }
        }
        
        // Check if source parameters changed and recalculate root values if needed
        if ($existing->customint1 != $source_courseid || $existing->customint3 != $source_groupid) {
            $update_data->customint1 = $source_courseid;
            $update_data->customint3 = $source_groupid;
            
            // Get source group name for caching
            $group_obj = groups_get_group($source_groupid, 'name', MUST_EXIST);
            $update_data->customchar2 = $group_obj->name;
            
            // Recalculate root values
            $root = enrol_metagroup_find_root_course($source_courseid, $source_groupid);
            
            if ($root && !empty($root['root_courseid'])) {
                $update_data->customint4 = $root['root_courseid'];
                $update_data->customint5 = $root['root_groupid'];
                $update_data->customchar1 = $root['root_coursename'];
                $update_data->customchar3 = $root['root_groupname'];
            } else {
                // If root course not found, use logical as root
                $update_data->customint4 = $source_courseid;
                $update_data->customint5 = $source_groupid;
                $course_obj = get_course($source_courseid);
                $update_data->customchar1 = $course_obj->shortname ?: $course_obj->fullname;
                $update_data->customchar3 = $group_obj->name;
            }
        }

        $source_courses = enrol_metagroup_compute_source_courses($source_courseid, $source_groupid);
        $update_data->customtext1 = json_encode(['source_courses' => $source_courses]);
        
        $DB->update_record('enrol', $update_data);
        
        // Reload updated instance
        $existing = $DB->get_record('enrol', ['id' => $existing->id]);
        
        // Sync if requested
        if (($options['sync_on_create'] ?? true) && $update_data->status == ENROL_INSTANCE_ENABLED) {
            enrol_metagroup_sync($target_courseid, false);
        }
        
        return $existing;
    }

    // No existing link found - create new one
    // If target_groupid is null, create group automatically
    if ($target_groupid === null) {
        $target_group_name = $options['target_group_name'] ?? null;
        $target_groupid = enrol_metagroup_create_new_group(
            $target_courseid,
            $source_groupid,
            $target_group_name  // If specified, uses this name without suffixes
        );
        if (!$target_groupid) {
            return false;
        }
    } else {
        // Validate that target group exists
        if (!$DB->record_exists('groups', ['id' => $target_groupid, 'courseid' => $target_courseid])) {
            return false;
        }
    }

    // Prepare fields for add_instance
    $fields = [
        'courseid' => $target_courseid,
        'enrol' => 'metagroup',
        'status' => $options['status'] ?? ENROL_INSTANCE_ENABLED,
        'roleid' => $options['roleid'] ?? null,
        'customint1' => $source_courseid,  // source course
        'customint2' => $target_groupid,  // target group
        'customint3' => $source_groupid,  // source group
    ];

    // Get course object
    $course = get_course($target_courseid);
    if (!$course) {
        return false;
    }

    // Get plugin instance
    $plugin = enrol_get_plugin('metagroup');
    if (!$plugin) {
        return false;
    }

    // Create instance
    try {
        $instance_id = $plugin->add_instance($course, $fields);
        if (!$instance_id) {
            return false;
        }

        // Get full enrolment instance object
        $instance = $DB->get_record('enrol', ['id' => $instance_id]);
        if (!$instance) {
            return false;
        }

        return $instance;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Find existing metagroup link by its parameters.
 * 
 * @param int $target_courseid ID of the target course
 * @param int $source_courseid ID of the source course
 * @param int $source_groupid ID of the source group
 * @param int|null $target_groupid Optional: ID of the target group
 * @return stdClass|false Enrolment instance object on success, false if not found
 */
function enrol_metagroup_find_link($target_courseid, $source_courseid, $source_groupid, $target_groupid = null) {
    global $DB;

    $conditions = [
        'courseid' => $target_courseid,
        'enrol' => 'metagroup',
        'customint1' => $source_courseid,
        'customint3' => $source_groupid,
    ];
    if ($target_groupid !== null) {
        $conditions['customint2'] = $target_groupid;
    }

    return $DB->get_record('enrol', $conditions);
}

/**
 * Delete a metagroup link by its parameters.
 * 
 * Public API function for deleting metagroup enrolment instances programmatically.
 * Can be called from other plugins for bulk synchronization.
 * 
 * @param int $target_courseid ID of the target course
 * @param int $source_courseid ID of the source course
 * @param int $source_groupid ID of the source group
 * @param int|null $target_groupid Optional: ID of the target group (for more precise matching)
 * @return bool True on success, false on failure or if link not found
 */
function enrol_metagroup_delete_link($target_courseid, $source_courseid, $source_groupid, $target_groupid = null) {
    global $CFG;

    require_once($CFG->dirroot.'/enrol/metagroup/lib.php');

    // Find the link
    $instance = enrol_metagroup_find_link($target_courseid, $source_courseid, $source_groupid, $target_groupid);
    if (!$instance) {
        return false;
    }

    // Get plugin instance
    $plugin = enrol_get_plugin('metagroup');
    if (!$plugin) {
        return false;
    }

    // Delete instance
    try {
        $plugin->delete_instance($instance);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get all metagroup links in a unified format.
 * 
 * Public API function for retrieving all metagroup enrolment instances.
 * Returns data in a format that doesn't require decoding custom fields.
 * Useful for bulk synchronization from external sources.
 * 
 * @param int|null $target_courseid Optional: filter by target course ID
 * @param int|null $source_courseid Optional: filter by source course ID
 * @return array Array of link objects, each containing:
 *   - 'id' => int (enrolment instance ID)
 *   - 'target_courseid' => int (courseid)
 *   - 'source_courseid' => int (customint1 - logical source course)
 *   - 'source_groupid' => int (customint3 - logical source group)
 *   - 'target_groupid' => int (customint2)
 *   - 'root_courseid' => int|null (customint4 - root source course, if exists)
 *   - 'root_groupid' => int|null (customint5 - root source group, if exists)
 *   - 'status' => int (ENROL_INSTANCE_ENABLED or DISABLED)
 *   - 'roleid' => int (assigned role ID)
 *   - 'source_group_name' => string (customchar2 - cached source group name)
 *   - 'root_course_name' => string|null (customchar1 - root course name, if exists)
 *   - 'root_group_name' => string|null (customchar3 - root group name, if exists)
 *   - 'source_courses' => array (decoded from customtext1 JSON, empty array if not set)
 */
function enrol_metagroup_get_all_links($target_courseid = null, $source_courseid = null) {
    global $DB;

    // Build SQL query with optional filters
    $conditions = ['enrol' => 'metagroup'];
    $params = [];

    if ($target_courseid !== null) {
        $conditions['courseid'] = $target_courseid;
    }
    if ($source_courseid !== null) {
        $conditions['customint1'] = $source_courseid;
    }

    // Get all records
    $records = $DB->get_records('enrol', $conditions, 'id ASC');

    // Transform to unified format
    $links = [];
    foreach ($records as $record) {
        $link = new stdClass();
        $link->id = $record->id;
        $link->target_courseid = $record->courseid;
        $link->source_courseid = $record->customint1;
        $link->source_groupid = $record->customint3;
        $link->target_groupid = $record->customint2;
        $link->root_courseid = !empty($record->customint4) ? $record->customint4 : null;
        $link->root_groupid = !empty($record->customint5) ? $record->customint5 : null;
        $link->status = $record->status;
        $link->roleid = $record->roleid;
        $link->source_group_name = $record->customchar2 ?? '';
        $link->root_course_name = !empty($record->customchar1) ? $record->customchar1 : null;
        $link->root_group_name = !empty($record->customchar3) ? $record->customchar3 : null;
        $link->source_courses = [];
        if (!empty($record->customtext1)) {
            $decoded = json_decode($record->customtext1, true);
            if (is_array($decoded) && isset($decoded['source_courses'])) {
                $link->source_courses = $decoded['source_courses'];
            }
        }
        $links[] = $link;
    }

    return $links;
}
