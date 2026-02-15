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
 * Meta-group enrolment plugin BASED ON meta-course enrolment plugin (enrol_meta).
 *
 * @package    enrol_metagroup
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * ENROL_METAGROUP_CREATE_GROUP constant for automatically creating a group for a metagroup.
 */
define('ENROL_METAGROUP_CREATE_GROUP', -1);

/**
 * ENROL_METAGROUP_CREATE_SEPARATE_GROUPS constant for automatically creating a separate group for each of linked groups (on creation with several groups at once).
 */
define('ENROL_METAGROUP_CREATE_SEPARATE_GROUPS', -2);



/**
 * Meta course enrolment plugin.
 * @author Petr Skoda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_metagroup_plugin extends enrol_plugin {

    /**
     * Returns localised name of enrol instance
     *
     * @param stdClass $instance (null is accepted too)
     * @return string
     */
    public function get_instance_name($instance) {
        global $DB;

        if (empty($instance)) {
            $enrol = $this->get_name();
            return get_string('pluginname', 'enrol_'.$enrol);
        } else if (!empty($instance->name)) {
            return format_string($instance->name);
        } else {
            $enrol = $this->get_name();

            // Get actual group name (since it could be renamed after this enrol method added).
            $source_groupid = $instance->customint3;
            $source_group = groups_get_group($source_groupid, 'name');
            $source_groupname = $source_group ? $source_group->name : (
                // Fallback to using saved groupname if the group was deleted (ex. manually).
                $instance->customchar2 . ' [' . get_string('deleted') . ']'
            );

            $target_groupid = $instance->customint2;
            $target_group = groups_get_group($target_groupid, 'name' /*, IGNORE_MISSING */);
            $target_groupname = $target_group ? $target_group->name : (
                // Fallback if the group was deleted (ex. manually) but will be restored on next sync.
                '[' . get_string('deleted') . ']'
            );

            // Используем логический курс для отображения (то, что видит пользователь).
            $logical_courseid = $instance->customint1;
            $root_courseid = !empty($instance->customint4) ? $instance->customint4 : null;
            $root_coursename = !empty($instance->customchar1) ? $instance->customchar1 : null;
            $root_groupname = !empty($instance->customchar3) ? $instance->customchar3 : null;
            
            $course = get_course($logical_courseid);
            if ($course) {
                $coursename = format_string(get_course_display_name_for_list($course));
            } else {
                // Use course id, if the course has been deleted.
                $coursename = $logical_courseid;
            }
            
            // Если корневой курс отличается от логического, показываем информацию о корневом источнике.
            if ($root_courseid && $root_courseid != $logical_courseid && $root_coursename) {
                $coursename .= ' (через ' . self::shorten_long_name($root_coursename) . ')';
                if ($root_groupname && $root_groupname != $source_groupname) {
                    $source_groupname .= ' (из ' . self::shorten_long_name($root_groupname) . ')';
                }
            }
            
            return get_string('defaultenrolnametext', 'enrol_' . $enrol, [
                'method' => get_string('pluginname', 'enrol_' . $enrol),
                'target_group' => self::shorten_long_name($target_groupname),
                'source_group' => self::shorten_long_name($source_groupname),
                'source_course' => self::shorten_long_name($coursename),
            ]);
        }
    }

    public static function shorten_long_name($s, $length_limit=40) {
        $len = mb_strlen($s);
        if ($len <= $length_limit) {
            return $s;
        }

        $part_len = (int)(($length_limit - 1) / 2);
        return mb_substr($s, 0, $part_len) . '…' . mb_substr($s, -$part_len);
    }

    /**
     * Returns true if we can add a new instance to this course.
     *
     * @param int $courseid
     * @return boolean
     */
    public function can_add_instance($courseid) {
        $context = context_course::instance($courseid, MUST_EXIST);
        if (!has_capability('moodle/course:enrolconfig', $context) || !has_capability('enrol/metagroup:config', $context)) {
            return false;
        }
        // Multiple instances supported - multiple parent courses linked.
        return true;
    }

    /**
     * Does this plugin allow manual unenrolment of a specific user?
     * Yes, but only if user suspended...
     *
     * @param stdClass $instance course enrol instance
     * @param stdClass $ue record from user_enrolments table
     *
     * @return bool - true means user with 'enrol/xxx:unenrol' may unenrol this user, false means nobody may touch this user enrolment
     */
    public function allow_unenrol_user(stdClass $instance, stdClass $ue) {
        if ($ue->status == ENROL_USER_SUSPENDED) {
            return true;
        }

        return false;
    }

    /**
     * Called after updating/inserting course.
     *
     * @param bool $inserted true if course just inserted
     * @param stdClass $course
     * @param stdClass $data form data
     * @return void
     */
    public function course_updated($inserted, $course, $data) {
        // Meta sync updates are slow, if enrolments get out of sync teacher will have to wait till next cron.
        // We should probably add some sync button to the course enrol methods overview page.
    }

    /**
     * Add new instance of enrol plugin.
     * @param object $course
     * @param array $fields instance fields
     * @return int id of last instance, null if can not be created
     */
    public function add_instance($course, array $fields = null) {
        global $CFG, $DB;

        require_once("$CFG->dirroot/enrol/metagroup/locallib.php");

        // Support creating multiple at once (for several source groups from the source course).
        if (isset($fields['customint3']) && is_array($fields['customint3'])) {
            $source_groups = array_unique($fields['customint3']);
        } elseif (isset($fields['customint3'])) {
            $source_groups = array($fields['customint3']);
        } else {
            $source_groups = [];
        }

        // Check if there are enrol duplicates.
        $already_linked_groups = $DB->get_fieldset_select('enrol', 'customint3', 'enrol = "metagroup" AND courseid = ? AND customint1 = ?', [$fields['courseid'], $fields['customint1'], ]);

        if ($already_linked_groups) {
            // Do not create enrols for alredy linked groups.
            $source_groups = array_diff($source_groups, $already_linked_groups);
        }

        $data = (array)$fields;  // Make updatable copy to keep $fields intact.
        $result = null;

        $target_groupid = $fields['customint2'];

        if (!empty($fields['customint2']) && $fields['customint2'] == ENROL_METAGROUP_CREATE_GROUP) {
            // Create one dedicated group for all source groups as requested.
            $context = context_course::instance($course->id);
            require_capability('moodle/course:managegroups', $context);

            // Add a new group with the name of linked course.
            $target_groupid = enrol_metagroup_create_new_group($course->id, null, get_course($fields['customint1'])->shortname);
        }

        // Определяем корневой курс для транзитивных связей.
        $logical_courseid = $fields['customint1'];
        $root_info = null;

        foreach ($source_groups as $source_groupid) {
            if (!empty($fields['customint2']) && $fields['customint2'] == ENROL_METAGROUP_CREATE_SEPARATE_GROUPS) {
                // Create saparate groups for each source group as requested.
                $context = context_course::instance($course->id);
                require_capability('moodle/course:managegroups', $context);

                // Add a new group for each synced group-to-group pair.
                $target_groupid = enrol_metagroup_create_new_group($course->id, $source_groupid);
            }

            // Проверяем, является ли группа сводной (имеет членов из нескольких метагрупповых связей).
            $aggregated_enrols = enrol_metagroup_detect_aggregated_group($logical_courseid, $source_groupid);
            
            if (!empty($aggregated_enrols)) {
                // Группа сводная - нужно обработать все корневые источники.
                // Для каждой метагрупповой связи находим её корневой источник.
                $root_sources = [];
                foreach ($aggregated_enrols as $enrol) {
                    $parent_root_courseid = !empty($enrol->customint4) ? $enrol->customint4 : $enrol->customint1;
                    $parent_root_groupid = !empty($enrol->customint5) ? $enrol->customint5 : $enrol->customint3;
                    
                    $root = enrol_metagroup_find_root_course($parent_root_courseid, $parent_root_groupid);
                    if ($root && !empty($root['root_courseid'])) {
                        $key = $root['root_courseid'] . '_' . ($root['root_groupid'] ?? 0);
                        if (!isset($root_sources[$key])) {
                            $root_sources[$key] = $root;
                        }
                    }
                }
                
                // Создаём отдельную метагрупповую связь для каждого корневого источника.
                foreach ($root_sources as $root) {
                    $data['customint2'] = $target_groupid;
                    $data['customint3'] = $source_groupid;
                    $data['customchar2'] = groups_get_group($source_groupid, 'name', MUST_EXIST)->name;
                    
                    // Сохраняем логические значения (то, что видит пользователь).
                    $data['customint1'] = $logical_courseid;
                    
                    // Сохраняем корневые значения (используются для синхронизации).
                    $data['customint4'] = $root['root_courseid'];
                    $data['customint5'] = $root['root_groupid'];
                    $data['customchar1'] = $root['root_coursename'];
                    $data['customchar3'] = $root['root_groupname'];
                    
                    $result = parent::add_instance($course, $data);
                    if ($result) {
                        $source_courses = enrol_metagroup_compute_source_courses($logical_courseid, $source_groupid);
                        $DB->set_field('enrol', 'customtext1', json_encode(['source_courses' => $source_courses]), ['id' => $result]);
                    }
                }
            } else {
                // Обычная группа - находим корневой курс.
                $root = enrol_metagroup_find_root_course($logical_courseid, $source_groupid);
                
                // Update group id before each save.
                $data['customint2'] = $target_groupid;
                $data['customint3'] = $source_groupid;

                // Fetch & cache source group's name.
                $data['customchar2'] = groups_get_group($source_groupid, 'name', MUST_EXIST)->name;

                // Сохраняем логические значения (то, что видит пользователь).
                $data['customint1'] = $logical_courseid;
                
                // Сохраняем корневые значения (используются для синхронизации).
                if ($root && !empty($root['root_courseid'])) {
                    $data['customint4'] = $root['root_courseid'];
                    $data['customint5'] = $root['root_groupid'];
                    $data['customchar1'] = $root['root_coursename'];
                    $data['customchar3'] = $root['root_groupname'];
                } else {
                    // Если корневой курс не найден (возможно, цикл), используем логический курс как корневой.
                    $data['customint4'] = $logical_courseid;
                    $data['customint5'] = $source_groupid;
                    $course_obj = get_course($logical_courseid);
                    $data['customchar1'] = $course_obj->shortname ?: $course_obj->fullname;
                    $group_obj = groups_get_group($source_groupid, 'name', MUST_EXIST);
                    $data['customchar3'] = $group_obj->name;
                }

                // Add enrolling method to the course: one for each linked group.
                $result = parent::add_instance($course, $data);
                if ($result) {
                    $source_courses = enrol_metagroup_compute_source_courses($logical_courseid, $source_groupid);
                    $DB->set_field('enrol', 'customtext1', json_encode(['source_courses' => $source_courses]), ['id' => $result]);
                }
            }
        }

        enrol_metagroup_sync($course->id, false);

        return $result;
    }

    /**
     * Update instance of enrol plugin.
     * @param stdClass $instance
     * @param stdClass $data modified instance fields
     * @return boolean
     */
    public function update_instance($instance, $data) {
        global $CFG, $DB;

        require_once("$CFG->dirroot/enrol/metagroup/locallib.php");

        // Convert array with single value to plain value.
        if (isset($data->customint3) && is_array($data->customint3)) {
            $data->customint3 = reset($data->customint3);
        }

        if (!empty($data->customint2) && $data->customint2 == ENROL_METAGROUP_CREATE_GROUP) {
            $context = context_course::instance($instance->courseid);
            require_capability('moodle/course:managegroups', $context);

            $groupid = enrol_metagroup_create_new_group($instance->courseid, $data->customint3);
            if (!$groupid || $groupid <= 0) {
                throw new moodle_exception('error', 'enrol_metagroup', '', 'Failed to create target group');
            }
            $data->customint2 = $groupid;
        } else if (!empty($data->customint2) && $data->customint2 == ENROL_METAGROUP_CREATE_SEPARATE_GROUPS) {
            // When editing, CREATE_SEPARATE_GROUPS should not be allowed as it's only for creation with multiple groups.
            // If this value is set during update, it means user wants to create a new group for the single source group.
            // Convert it to CREATE_GROUP behavior.
            $context = context_course::instance($instance->courseid);
            require_capability('moodle/course:managegroups', $context);

            $groupid = enrol_metagroup_create_new_group($instance->courseid, $data->customint3);
            if (!$groupid || $groupid <= 0) {
                throw new moodle_exception('error', 'enrol_metagroup', '', 'Failed to create target group');
            }
            $data->customint2 = $groupid;
        }

        // Keep (frozen) "cache" attributes.
        $data->customchar2 = $instance->customchar2;

        // Если изменён логический курс или группа, пересчитываем корневой курс.
        if (isset($data->customint1) && $data->customint1 != $instance->customint1) {
            // Запрещаем изменение, если новый логический курс создаст непосредственный цикл.
            if ($data->customint1 == $instance->courseid) {
                throw new moodle_exception('invalidcourseid', 'error');
            }
            
            $logical_courseid = $data->customint1;
            $logical_groupid = isset($data->customint3) ? $data->customint3 : $instance->customint3;
            
            // Находим корневой курс.
            $root = enrol_metagroup_find_root_course($logical_courseid, $logical_groupid);
            
            if ($root && !empty($root['root_courseid'])) {
                $data->customint4 = $root['root_courseid'];
                $data->customint5 = $root['root_groupid'];
                $data->customchar1 = $root['root_coursename'];
                $data->customchar3 = $root['root_groupname'];
            } else {
                // Если корневой курс не найден, используем логический как корневой.
                $data->customint4 = $logical_courseid;
                $data->customint5 = $logical_groupid;
                $course_obj = get_course($logical_courseid);
                $data->customchar1 = $course_obj->shortname ?: $course_obj->fullname;
                $group_obj = groups_get_group($logical_groupid, 'name', MUST_EXIST);
                $data->customchar3 = $group_obj->name;
            }
        } else if (isset($data->customint3) && $data->customint3 != $instance->customint3) {
            // Если изменена только группа, пересчитываем корневую группу.
            $logical_courseid = isset($data->customint1) ? $data->customint1 : $instance->customint1;
            $logical_groupid = $data->customint3;
            
            $root = enrol_metagroup_find_root_course($logical_courseid, $logical_groupid);
            
            if ($root && !empty($root['root_courseid'])) {
                $data->customint4 = $root['root_courseid'];
                $data->customint5 = $root['root_groupid'];
                $data->customchar1 = $root['root_coursename'];
                $data->customchar3 = $root['root_groupname'];
            }
        }

        // Пересчёт customtext1 при изменении источника (кнопка «Пересчитать связи» — отдельная страница).
        $source_changed = (isset($data->customint1) && $data->customint1 != $instance->customint1) ||
            (isset($data->customint3) && $data->customint3 != $instance->customint3);
        if ($source_changed) {
            $src_courseid = isset($data->customint1) ? $data->customint1 : $instance->customint1;
            $src_groupid = isset($data->customint3) ? $data->customint3 : $instance->customint3;
            $source_courses = enrol_metagroup_compute_source_courses($src_courseid, $src_groupid);
            $DB->set_field('enrol', 'customtext1', json_encode(['source_courses' => $source_courses]), ['id' => $instance->id]);
        }

        $result = parent::update_instance($instance, $data);

        enrol_metagroup_sync($instance->courseid);

        return $result;
    }

    /**
     * Update instance status
     *
     * @param stdClass $instance
     * @param int $newstatus ENROL_INSTANCE_ENABLED, ENROL_INSTANCE_DISABLED
     * @return void
     */
    public function update_status($instance, $newstatus, $invoke_sync = true) {
        global $CFG;

        parent::update_status($instance, $newstatus);

        if ($invoke_sync) {
            require_once("$CFG->dirroot/enrol/metagroup/locallib.php");
            enrol_metagroup_sync($instance->courseid);
        }
    }

    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_delete_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/metagroup:config', $context);
    }

    /**
     * Is it possible to hide/show enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_hide_show_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/metagroup:config', $context);
    }

    /**
     * We are a good plugin and don't invent our own UI/validation code path.
     *
     * @return boolean
     */
    public function use_standard_editing_ui() {
        return true;
    }

    /**
     * (Method is not used.)
     * Return an array of valid options for the courses.
     *
     * @param stdClass $instance
     * @param context $coursecontext
     * @return array
     */
    protected function get_course_options($instance, $coursecontext) {
        global $DB;

        if ($instance->id) {
            $where = 'WHERE c.id = :courseid';
            $params = array('courseid' => $instance->customint1);
        } else {
            $where = '';
            $params = array();
        }

        // TODO: this has to be done via ajax or else it will fail very badly on large sites!
        $courses = array();
        $select = ', ' . context_helper::get_preload_record_columns_sql('ctx');
        $join = "LEFT JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel)";
        // Add check: course has at least one non-empty group.
        $join .= " JOIN {groups} g ON (g.courseid = c.id) ";
        $join .= " JOIN {groups_members} gm ON (gm.groupid = g.id) ";

        $sortorder = 'c.' . $this->get_config('coursesort', 'sortorder') . ' ASC';

        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.visible $select FROM {course} c $join $where ORDER BY $sortorder";
        $rs = $DB->get_recordset_sql($sql, array('contextlevel' => CONTEXT_COURSE) + $params);
        foreach ($rs as $c) {
            if ($c->id == SITEID or $c->id == $instance->courseid /* or isset($existing[$c->id]) */) {
                continue;
            }
            context_helper::preload_from_record($c);
            $coursecontext = context_course::instance($c->id);
            if (!$c->visible and !has_capability('moodle/course:viewhiddencourses', $coursecontext)) {
                continue;
            }
            if (!has_capability('enrol/metagroup:selectaslinked', $coursecontext)) {
                continue;
            }
            $courses[$c->id] = $coursecontext->get_context_name(false);
        }
        $rs->close();
        return $courses;
    }

    /**
     * Return an array of valid options for the groups (to whose members may be added).
     *
     * @param context $coursecontext
     * @param string $source_groups_count 'many' to add extra menu option "Create new group for each one" (default), or 'one' for just one option "Create new group".
     * @return array
     */
    protected function get_target_group_options($coursecontext, $source_groups_count = 'many') {
        // $groups = array(0 => get_string('none'));
        $groups = [];
        $courseid = $coursecontext->instanceid;

        if (has_capability('moodle/course:managegroups', $coursecontext)) {
            $groups[ENROL_METAGROUP_CREATE_SEPARATE_GROUPS] = get_string('creategroup_many', 'enrol_metagroup');

            $groups[ENROL_METAGROUP_CREATE_GROUP] = get_string('creategroup_one', 'enrol_metagroup');
        }

        foreach (groups_get_all_groups($courseid) as $group) {
            $groups[$group->id] = format_string($group->name, true, array('context' => $coursecontext));
        }
        return $groups;
    }

    /**
     * Return an array of valid options for the groups (in source course).
     *
     * Returns all (optionally non-empty) groups in source course, minus groups already used by this plugin.
     *
     * @param context $coursecontext
     * @param int $courseid source course
     * @param bool $nonempty_only
     * @param bool $include_groupsize
     * @return array
     */
    protected function get_source_group_options($coursecontext, $courseid, $nonempty_only = true, $include_groupsize = true) {
        global $DB;

        $groups_with_members = groups_get_all_groups($courseid, 0, 0, 'g.*', true);

        // Exclude groups that are already used in any metagroup enrolment instance on this course.
        // Check both customint3 (logical source group) and customint5 (root group for transitive links).
        // Get all non-zero customint3 and all non-zero customint5 values without priorities.
        $sql = "SELECT DISTINCT groupid
                FROM (
                    SELECT customint3 AS groupid
                    FROM {enrol}
                    WHERE enrol = 'metagroup' AND courseid = ? AND customint3 > 0
                    UNION
                    SELECT customint5 AS groupid
                    FROM {enrol}
                    WHERE enrol = 'metagroup' AND courseid = ? AND customint5 > 0
                ) AS combined";
        $used_groups = $DB->get_fieldset_sql($sql, [$coursecontext->instanceid, $coursecontext->instanceid]);
        $already_linked_groups = array_filter($used_groups); // Remove null/zero values

        $group_options = [];

        foreach ($groups_with_members as $group) {
            if (in_array($group->id, $already_linked_groups)) {
                continue;
            }

            $size = count($group->members);
            if ($nonempty_only && $size < 1) {
                continue;
            }

            $name = format_string($group->name, $courseid);
            // $name = $group->name;
            if ($include_groupsize) {
                $name = "$name ($size)";
            }
            $group_options[$group->id]  = $name;
        }
        return $group_options;
    }

    /**
     * Add elements to the edit instance form.
     *
     * @param stdClass $instance
     * @param MoodleQuickForm $mform
     * @param context $coursecontext
     * @return bool
     */
    public function edit_instance_form($instance, MoodleQuickForm $mform, $coursecontext) {
        global $DB, $CFG;

        $creation_mode = empty($instance->id);
        $edit_mode = !$creation_mode;

        // Note the two-step creation scheme via form invalidation and re-rendering with more fields shown.
        if ($creation_mode) {
            $creation_stage = 2;

            if (!isset($_POST['customint1'])) {
                $creation_stage = 1;
            }

        } else {
            $creation_stage = 0;
        }

        // Do not allow to link the same course as external.
        $excludelist = array($coursecontext->instanceid);

        // Variant 1, — course-specific selector UI, reliable enough.
        // See: "$CFG->libdir/form/course.php", class MoodleQuickForm_course.
        $options = array(
            'requiredcapabilities' => array('enrol/metagroup:selectaslinked'),
            // 'multiple' => false,  // Allow selecting only one course on creation.
            'limittoenrolled' => get_config('enrol_metagroup', 'limittoenrolled'),  // If true: only "my" ($USER's) courses.
            'exclude' => $excludelist,
        );
        $mform->addElement('course', 'customint1', get_string('linkedcourse', 'enrol_metagroup'), $options);

        // Variant 2: 'autocomplete' — very slow UI. Removed from here.

        //// $mform->addRule('customint1', get_string('required'), 'required', null, 'client');  // OFF: disable form locking after select+edit.
        $mform->addHelpButton('customint1', 'linkedcourse', 'enrol_metagroup');

        if ($edit_mode || $creation_stage == 2) {
            // Sourse course is know and should not be changed.
            $mform->freeze('customint1');
        }

        if ($creation_stage == 2) {
            // Add option to roll back to course selection.
            $text = get_string('changecourseselection', 'enrol_metagroup') /* '← Выбрать другой курс' */;
            $url = new moodle_url('/enrol/editinstance.php', ['type' => $this->get_name(), 'courseid' => $coursecontext->instanceid]);
            $html_a = html_writer::link($url, $text);
            $mform->addElement('static', '', '', $html_a);
        }


        if ($edit_mode || $creation_stage == 2) {
            // We are within of the process of two-step creation process, thus the user is editing.

            $source_courseid = (isset($instance->customint1) && $instance->customint1) ?: (array_key_exists('customint1', $_POST)? $_POST['customint1'] : 0);


            // • customint3 (source_groupid) — id группы-источника
            {
                // Show 'autocomplete' element (search & select UI).

                if (empty($instance->customint3)) {
                    // Get all possible options to choose from.
                    $source_groupnames = self::get_source_group_options($coursecontext, $source_courseid);

                } else {
                    // Just one group chosen before.
                    $group = groups_get_group($instance->customint3);
                    if ($group) {
                        $source_groupnames = [$group->id => $group->name];
                    } else {
                        // Fallback to using saved groupname if the group was deleted (ex. manually).
                        $source_groupnames = [$instance->customint3 => $instance->customchar2 . ' [' . get_string('deleted') . ']'];
                    }
                }

                $options = array(
                    'multiple' => true,
                    'placeholder' => /* 'Группа-источник' */ get_string('sourcegroup', 'enrol_metagroup'),
                    'noselectionstring' => $source_groupnames ? /* 'Выберите группу…' */ get_string('searchgroup', 'enrol_metagroup') : /* Группа не найдена */  get_string('nosourcegroups', 'enrol_metagroup'),
                );

                $mform->addElement('autocomplete', 'customint3', /* 'Группа-источник' */ get_string('linkedgroup', 'enrol_metagroup'), $source_groupnames, $options);
                $mform->addRule('customint3', get_string('required'), 'required', null, 'client');
                $mform->addHelpButton('customint3', 'linkedgroup', 'enrol_metagroup');


                if (!empty($instance->customint3)) {
                    // Record was loaded from DB, thus the user is editing the form.
                    $mform->freeze('customint3');
                }
            }

            // • customint2 (target_groupid) — id группы-назначения (эта группа всегда есть, не может быть пустым или 0).

            $groups = $this->get_target_group_options($coursecontext, (count($source_groupnames) == 1 ? 'one' : 'many'));

            $mform->addElement('select', 'customint2', get_string('addgroup', 'enrol_metagroup'), $groups);

            if ($edit_mode && !empty($instance->customint3)) {
                require_once("$CFG->dirroot/enrol/metagroup/locallib.php");
                $paths = enrol_metagroup_get_chain_for_display($instance);
                $chain_html = '';
                if (!empty($paths)) {
                    $chain_html .= html_writer::tag('h4', get_string('sourcecourseschain', 'enrol_metagroup'));
                    foreach ($paths as $path) {
                        $parts = [];
                        foreach ($path as $step) {
                            $link = html_writer::link($step['courseurl'], s($step['coursename']));
                            $groupinfo = $step['groupname'] !== null ? ' — ' . s($step['groupname']) : '';
                            $parts[] = $link . $groupinfo;
                        }
                        $chain_html .= html_writer::tag('div', implode(' → ', $parts), ['class' => 'mb-2']);
                    }
                    $chain_html .= html_writer::empty_tag('br');
                    $mform->addElement('static', 'sourcechain', '', $chain_html);
                }
                $recalc_url = new moodle_url('/enrol/metagroup/recalculate.php', [
                    'id' => $instance->id,
                    'courseid' => $coursecontext->instanceid,
                    'sesskey' => sesskey(),
                ]);
                $recalc_link = html_writer::link($recalc_url, get_string('recalculate_links', 'enrol_metagroup'), ['class' => 'btn btn-secondary']);
                $mform->addElement('static', 'recalculate_links', '', $recalc_link);
            }

        } else {
            // Do not show group options until course is selected,
            // but show "Next" button to semantically link/explain the next form appearance.
            $mform->addElement('submit', 'submitbutton_next', get_string('next'));
        }
        /*
        Dev tip: 'enrol' table fields usage:
        customint1 (source_courseid) — id курса-источника
        customint2 (target_groupid) — id группы-назначения (эта группа всегда есть, не может быть пустым или 0)
        customint3 (source_groupid) — id группы-источника
        customchar2 (source_groupname) — имя группы-источника (для консистентности, чтобы избежать накладок при переименовании группы-источника)    */
    }

    /**
     * Perform custom validation of the data used to edit the instance.
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @param object $instance The instance loaded from the DB
     * @param context $context The context of the instance we are editing
     * @return array of "element_name"=>"error_description" if there are errors,
     *         or an empty array if everything is OK.
     */
    public function edit_instance_validation($data, $files, $instance, $context) {
        global $DB, $CFG;

        require_once("$CFG->dirroot/enrol/metagroup/locallib.php");

        $errors = array();

        $thiscourseid = $context->instanceid;

        if (!empty($data['customint1'])) {

            // Get courses selected via form.
            $coursesidarr = is_array($data['customint1']) ? $data['customint1'] : [$data['customint1']];
            list($coursesinsql, $coursesinparams) = $DB->get_in_or_equal($coursesidarr, SQL_PARAMS_NAMED, 'metacourseid');

            // Fetch picked courses & check if these have non-empty groups.
            $sql = "SELECT DISTINCT c.id, c.visible
                        FROM {course} c
                        JOIN {groups} g ON (g.courseid = c.id)
                        JOIN {groups_members} gm ON (gm.groupid = g.id)
                        WHERE c.id {$coursesinsql}";

            $existsparams = [] + $coursesinparams;
            $courseswithgroups = $DB->get_records_sql($sql, $existsparams);

            if (count($courseswithgroups) < count($coursesidarr)) {
                // Some courses filtered out due to absence of groups.
                $errors['customint1'] = get_string('cannotfindgroup', 'error');
                return $errors;
            }

            $coursesrecords = $courseswithgroups;

            if ($coursesrecords) {
                foreach ($coursesrecords as $coursesrecord) {
                    $coursecontext = context_course::instance($coursesrecord->id);
                    if (!$coursesrecord->visible and !has_capability('moodle/course:viewhiddencourses', $coursecontext)) {
                        $errors['customint1'] = get_string('nopermissions', 'error',
                            'moodle/course:viewhiddencourses');
                    } else if (!has_capability('enrol/metagroup:selectaslinked', $coursecontext)) {
                        $errors['customint1'] = get_string('nopermissions', 'error',
                            'enrol/metagroup:selectaslinked');
                    } else if ($coursesrecord->id == SITEID) {
                        $errors['customint1'] = get_string('invalidcourseid', 'error');
                    } else if ($coursesrecord->id == $thiscourseid) {
                        // Запрещаем только непосредственный цикл (курс на сам себя).
                        $errors['customint1'] = get_string('invalidcourseid', 'error');
                    } else {
                        // Расширенная проверка: target не должен входить в source_courses.
                        $source_groupid = isset($data['customint3']) ? $data['customint3'] : 0;
                        if (is_array($source_groupid)) {
                            $source_groupid = reset($source_groupid) ?: 0;
                        }
                        if ($source_groupid) {
                            $source_courses = enrol_metagroup_compute_source_courses($coursesrecord->id, $source_groupid);
                            if (in_array((int)$thiscourseid, array_map('intval', $source_courses))) {
                                $errors['customint1'] = get_string('recursive_link_error', 'enrol_metagroup');
                            }
                        }
                    }
                }
            } else {
                $errors['customint1'] = get_string('invalidcourseid', 'error');
            }
        } else {
            $errors['customint1'] = get_string('required');
        }

        if (array_key_exists('customint2', $data)) {
            $validgroups = array_keys($this->get_target_group_options($context));

            $tovalidate = array(
                'customint2' => $validgroups
            );
            $typeerrors = $this->validate_param_types($data, $tovalidate);
            $errors = array_merge($errors, $typeerrors);
        }

        return $errors;
    }


    /**
     * Restore instance and map settings.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $course
     * @param int $oldid
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid) {
        global $DB, $CFG;

        if (!$step->get_task()->is_samesite()) {
            // No metagroup restore from other sites.
            $step->set_mapping('enrol', $oldid, 0);
            return;
        }

        if (!empty($data->customint2)) {
            $data->customint2 = $step->get_mappingid('group', $data->customint2);
        }

        if ($DB->record_exists('course', array('id' => $data->customint1))) {
            $instance = $DB->get_record('enrol', array('roleid' => $data->roleid, 'customint1' => $data->customint1,
                'courseid' => $course->id, 'enrol' => $this->get_name()));
            if ($instance) {
                $instanceid = $instance->id;
            } else {
                $instanceid = $this->add_instance($course, (array)$data);
            }
            $step->set_mapping('enrol', $oldid, $instanceid);

            require_once("$CFG->dirroot/enrol/metagroup/locallib.php");
            enrol_metagroup_sync($data->customint1);

        } else {
            $step->set_mapping('enrol', $oldid, 0);
        }
    }

    /**
     * Restore user enrolment.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $instance
     * @param int $userid
     * @param int $oldinstancestatus
     */
    public function restore_user_enrolment(restore_enrolments_structure_step $step, $data, $instance, $userid, $oldinstancestatus) {
        global $DB;

        if ($this->get_config('unenrolaction') != ENROL_EXT_REMOVED_SUSPENDNOROLES) {
            // Enrolments were already synchronised in restore_instance(), we do not want any suspended leftovers.
            return;
        }

        // ENROL_EXT_REMOVED_SUSPENDNOROLES means all previous enrolments are restored
        // but without roles and suspended.

        if (!$DB->record_exists('user_enrolments', array('enrolid' => $instance->id, 'userid' => $userid))) {
            $this->enrol_user($instance, $userid, null, $data->timestart, $data->timeend, ENROL_USER_SUSPENDED);
            if ($instance->customint2) {
                groups_add_member($instance->customint2, $userid, 'enrol_metagroup', $instance->id);
            }
        }
    }

    /**
     * Restore user group membership.
     * @param stdClass $instance
     * @param int $groupid
     * @param int $userid
     */
    public function restore_group_member($instance, $groupid, $userid) {
        // Nothing to do here, the group members are added in $this->restore_group_restored().
        return;
    }

}
