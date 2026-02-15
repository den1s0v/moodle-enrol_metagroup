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
 * Event observer for metagroup enrolment plugin.
 *
 * @package    enrol_metagroup
 * @copyright  2013 Rajesh Taneja <rajesh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/enrol/metagroup/locallib.php');

/**
 * Event observer for enrol_metagroup.
 *
 * @package    enrol_metagroup
 * @copyright  2013 Rajesh Taneja <rajesh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_metagroup_observer extends enrol_metagroup_handler {

    /**
     * Triggered via user_enrolment_created event.
     *
     * @param \core\event\user_enrolment_created $event
     * @return bool true on success.
     */
    public static function user_enrolment_created(\core\event\user_enrolment_created $event) {
        if (!enrol_is_enabled('metagroup')) {
            // No more enrolments for disabled plugins.
            return true;
        }

        self::sync_course_instances($event->courseid, $event->relateduserid);
        return true;
    }

    /**
     * Triggered via user_enrolment_deleted event.
     *
     * @param \core\event\user_enrolment_deleted $event
     * @return bool true on success.
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event) {
        if (!enrol_is_enabled('metagroup')) {
            // This is slow, let enrol_metagroup_sync() deal with disabled plugin.
            return true;
        }

        self::sync_course_instances($event->courseid, $event->relateduserid);

        return true;
    }

    /**
     * Triggered via user_enrolment_updated event.
     *
     * @param \core\event\user_enrolment_updated $event
     * @return bool true on success
     */
    public static function user_enrolment_updated(\core\event\user_enrolment_updated $event) {
        if (!enrol_is_enabled('metagroup')) {
            // No modifications if plugin disabled.
            return true;
        }

        self::sync_course_instances($event->courseid, $event->relateduserid);

        return true;
    }

    /**
     * Triggered via role_assigned event.
     *
     * @param \core\event\role_assigned $event
     * @return bool true on success.
     */
    public static function role_assigned(\core\event\role_assigned $event) {
        if (!enrol_is_enabled('metagroup')) {
            return true;
        }

        // Only course level roles are interesting.
        if (!$parentcontext = context::instance_by_id($event->contextid, IGNORE_MISSING)) {
            return true;
        }
        if ($parentcontext->contextlevel != CONTEXT_COURSE) {
            return true;
        }

        self::sync_course_instances($parentcontext->instanceid, $event->relateduserid);

        return true;
    }

    /**
     * Triggered via role_unassigned event.
     *
     * @param \core\event\role_unassigned $event
     * @return bool true on success
     */
    public static function role_unassigned(\core\event\role_unassigned $event) {
        if (!enrol_is_enabled('metagroup')) {
            // All roles are removed via cron automatically.
            return true;
        }

        // Only course level roles are interesting.
        if (!$parentcontext = context::instance_by_id($event->contextid, IGNORE_MISSING)) {
            return true;
        }
        if ($parentcontext->contextlevel != CONTEXT_COURSE) {
            return true;
        }

        self::sync_course_instances($parentcontext->instanceid, $event->relateduserid);

        return true;
    }

    /**
     * Triggered via course_deleted event.
     *
     * @param \core\event\course_deleted $event
     * @return bool true on success
     */
    public static function course_deleted(\core\event\course_deleted $event) {
        global $DB;

        if (!enrol_is_enabled('metagroup')) {
            // This is slow, let enrol_metagroup_sync() deal with disabled plugin.
            return true;
        }

        // Does anything want to sync with this parent?
        // Проверяем как логические (customint1), так и корневые (customint4) курсы.
        // При удалении курса нужно обработать все связи, которые на него ссылаются.
        if (!$enrols = $DB->get_records_sql(
            "SELECT * FROM {enrol}
             WHERE enrol = 'metagroup'
             AND (customint1 = ? OR customint4 = ?)
             ORDER BY courseid ASC, id ASC",
            array($event->objectid, $event->objectid))) {
            return true;
        }

        $plugin = enrol_get_plugin('metagroup');
        $unenrolaction = $plugin->get_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);

        if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
            // Simple, just delete this instance which purges all enrolments,
            // admins were warned that this is risky setting!
            foreach ($enrols as $enrol) {
                $plugin->delete_instance($enrol);
            }
            return true;
        }

        foreach ($enrols as $enrol) {
            if ($unenrolaction == ENROL_EXT_REMOVED_SUSPEND or $unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
                // This makes all enrolments suspended very quickly.
                $plugin->update_status($enrol, ENROL_INSTANCE_DISABLED);
            }
            if ($unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
                $context = context_course::instance($enrol->courseid);
                role_unassign_all(array('contextid'=>$context->id, 'component'=>'enrol_metagroup', 'itemid'=>$enrol->id));
            }
        }

        return true;
    }

    /**
     * Triggered via enrol_instance_updated event.
     *
     * @param \core\event\enrol_instance_updated $event
     * @return boolean
     */
    public static function enrol_instance_updated(\core\event\enrol_instance_updated $event) {
        global $DB;

        if (!enrol_is_enabled('metagroup')) {
            // This is slow, let enrol_metagroup_sync() deal with disabled plugin.
            return true;
        }

        // Does anything want to sync with this parent?
        // Проверяем как логические (customint1), так и корневые (customint4) курсы.
        $affectedcourses = $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {enrol} '.
                'WHERE (customint1 = ? OR customint4 = ?) AND enrol = ?',
                array($event->courseid, $event->courseid, 'metagroup'));

        foreach ($affectedcourses as $courseid) {
            enrol_metagroup_sync($courseid);
        }

        return true;
    }

    /**
     * Triggered via enrol_instance_deleted event.
     * Обрабатывает удаление метагрупповой связи и пересчитывает корневые курсы для зависимых связей.
     *
     * @param \core\event\enrol_instance_deleted $event
     * @return boolean
     */
    public static function enrol_instance_deleted(\core\event\enrol_instance_deleted $event) {
        global $DB, $CFG;

        if (!enrol_is_enabled('metagroup')) {
            return true;
        }

        // Если удалена метагрупповая связь, нужно обработать зависимые связи.
        if ($event->other['enrol'] !== 'metagroup') {
            return true;
        }

        $deleted_courseid = $event->courseid;
        require_once($CFG->dirroot.'/enrol/metagroup/locallib.php');

        // Находим все связи, которые используют удалённый курс как логический (customint1) или корневой (customint4).
        // Это могут быть как прямые зависимости, так и транзитивные.
        $dependent_enrols = $DB->get_records_sql(
            "SELECT e.* FROM {enrol} e
             WHERE e.enrol = 'metagroup'
             AND (e.customint1 = ? OR e.customint4 = ?)",
            array($deleted_courseid, $deleted_courseid)
        );

        foreach ($dependent_enrols as $enrol) {
            // Если удалённый курс был корневым (customint4), связь становится невалидной.
            if ($enrol->customint4 == $deleted_courseid) {
                // Корневой курс удалён - обрабатываем как потерянную связь.
                enrol_metagroup_deal_with_lost_link($enrol);
                continue;
            }

            // Если удалённый курс был логическим (customint1), но не корневым,
            // это означает, что промежуточная связь была удалена.
            // Например: была цепочка A → B → C, где B → C использует A как корневой (customint4) и B как логический (customint1).
            // При удалении связи A → B, связь B → C теряет логический курс, но корневой (A) остаётся.
            // В этом случае помечаем связь как потерянную, так как логический курс недоступен.
            if ($enrol->customint1 == $deleted_courseid) {
                enrol_metagroup_deal_with_lost_link($enrol);
            }
        }

        return true;
    }

    public static function group_member_added(\core\event\group_member_added $event)
    {
        global $DB;

        if (!enrol_is_enabled('metagroup')) {
            // This is slow, let enrol_metagroup_sync() deal with disabled plugin.
            return true;
        }

        // Проверяем, связана ли группа с метагруппой.
        $enrol = $DB->get_record('enrol', array('customint3' => $event->objectid, 'enrol' => 'metagroup'));
        if ($enrol) {
            // Синхронизируем зачисления.
            enrol_metagroup_sync($enrol->courseid);
        }
    }

    public static function group_member_removed(\core\event\group_member_removed $event)
    {
        global $DB;

        if (!enrol_is_enabled('metagroup')) {
            // This is slow, let enrol_metagroup_sync() deal with disabled plugin.
            return true;
        }

        // Проверяем, связана ли группа с метагруппой.
        // Проверяем как логические (customint3), так и корневые (customint5) группы.
        $enrols = $DB->get_records_sql(
            "SELECT * FROM {enrol}
             WHERE enrol = 'metagroup'
             AND (customint3 = ? OR customint5 = ?)",
            array($event->objectid, $event->objectid)
        );
        foreach ($enrols as $enrol) {
            // Синхронизируем зачисления.
            enrol_metagroup_sync($enrol->courseid);
        }
    }

    public static function group_deleted(\core\event\group_deleted $event)
    {
        global $DB;

        if (!enrol_is_enabled('metagroup')) {
            // This is slow, let enrol_metagroup_sync() deal with disabled plugin.
            return true;
        }

        // Проверяем, связана ли удалённая группа с метагруппой.
        // Проверяем как логические (customint3), так и корневые (customint5) группы.
        $enrols = $DB->get_records_sql(
            "SELECT * FROM {enrol}
             WHERE enrol = 'metagroup'
             AND (customint3 = ? OR customint5 = ?)",
            array($event->objectid, $event->objectid)
        );
        foreach ($enrols as $enrol) {
            enrol_metagroup_deal_with_lost_link($enrol);
        }
    }
}
