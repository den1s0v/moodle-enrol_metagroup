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
 * ENROL_METAGROUP_CREATE_GROUP constant for automatically creating a group for a metagroup course.
 */
define('ENROL_METAGROUP_CREATE_GROUP', -1);


define('ENROL_METAGROUP_UPDATABLE', 1);
define('ENROL_METAGROUP_NON_UPDATABLE', 0);
/** Course enrol instance status: disabled but not initialized yet. (used in enrol->status)*/
define('ENROL_METAGROUP_NON_UPDATABLE_INIT', 2);

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
            $source_coursename = format_string(get_course_display_name_for_list(get_course($instance->customchar1)));
            
            // Fallback if the group was deleted (ex. manually) but will be restored on next sync.
            $source_groupname = $instance->customchar2;
            // Get actual group name (since it could be renamed after this enrol method added).
            $source_groupid = $instance->customint2;
            $source_groupname = groups_get_group($source_groupid, 'name')->name ?: $source_groupname;
            
            $target_groupid = $instance->customint2;
            $target_groupname = groups_get_group($target_groupid, 'name', MUST_EXIST)->name;

            $course = $DB->get_record('course', array('id'=>$instance->customint1));
            if ($course) {
                $coursename = format_string(get_course_display_name_for_list($course));
            } else {
                // Use course id, if course is deleted.
                $coursename = $instance->customint1;
            }
            return get_string('defaultenrolnametext', 'enrol_' . $enrol, [
                'method' => get_string('pluginname', 'enrol_' . $enrol),
                'target_group' => $target_groupname,
                'source_group' => $source_groupname,
                'source_course' => $coursename,
            ]);
        }
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
        global $CFG;

        require_once("$CFG->dirroot/enrol/metagroup/locallib.php");

        if (isset($fields['customint4']) && $fields['customint4'] == ENROL_METAGROUP_NON_UPDATABLE) {
            // On creation of disabled instance, sync should be performed once.
            $fields['customint4'] = ENROL_METAGROUP_NON_UPDATABLE_INIT;
        }

        // if (isset($fields['customint1'])) {
        //     // Fetch & cache source course's fullname.
        //     $fields['customchar1'] = format_string(get_course_display_name_for_list(get_course($fields['customint1'])));
        // }

        // Support creating multiple at once (for several source groups from the source course).
        if (isset($fields['customint3']) && is_array($fields['customint3'])) {
            $source_groups = array_unique($fields['customint3']);
        } else if (isset($fields['customint3'])) {
            $source_groups = array($fields['customint3']);
        } else {
            $source_groups = array(null); // Strange? Yes, but that's how it's working or instance is not created ever.
        }
        foreach ($source_groups as $source_groupid) {
            if (!empty($fields['customint2']) && $fields['customint2'] == ENROL_METAGROUP_CREATE_GROUP) {
                $context = context_course::instance($course->id);
                require_capability('moodle/course:managegroups', $context);

                // Add a new group for each synced group-to-group pair.
                $groupid = enrol_metagroup_create_new_group($course->id, $source_groupid);

                // Update group ids before each save.
                $fields['customint2'] = $groupid;
                $fields['customint3'] = $source_groupid;

                // Fetch & cache source group's name.
                $fields['customchar2'] = groups_get_group($source_groupid, 'name', MUST_EXIST)->name;
            }

            $result = parent::add_instance($course, $fields);
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
        global $CFG;

        require_once("$CFG->dirroot/enrol/metagroup/locallib.php");

        if (!empty($data->customint2) && $data->customint2 == ENROL_METAGROUP_CREATE_GROUP) {
            $context = context_course::instance($instance->courseid);
            require_capability('moodle/course:managegroups', $context);
            
            $groupid = enrol_metagroup_create_new_group($instance->courseid, $data->customint3);
            $data->customint2 = $groupid;
        }
        
        // Keep (frozen) "cache" attributes.
        // $data->customchar1 = $instance->customchar1;
        $data->customchar2 = $instance->customchar2;

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
    public function update_status($instance, $newstatus) {
        global $CFG;

        parent::update_status($instance, $newstatus);

        require_once("$CFG->dirroot/enrol/metagroup/locallib.php");
        enrol_metagroup_sync($instance->courseid);
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
            $existing = array();
        } else {
            $where = '';
            $params = array();
            $instanceparams = array('enrol' => 'metagroup', 'courseid' => $instance->courseid);
            $existing = $DB->get_records('enrol', $instanceparams, '', 'customint1, id');
        }

        // TODO: this has to be done via ajax or else it will fail very badly on large sites!
        $courses = array();
        $select = ', ' . context_helper::get_preload_record_columns_sql('ctx');
        $join = "LEFT JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel)";

        $sortorder = 'c.' . $this->get_config('coursesort', 'sortorder') . ' ASC';

        $sql = "SELECT c.id, c.fullname, c.shortname, c.visible $select FROM {course} c $join $where ORDER BY $sortorder";
        $rs = $DB->get_recordset_sql($sql, array('contextlevel' => CONTEXT_COURSE) + $params);
        foreach ($rs as $c) {
            if ($c->id == SITEID or $c->id == $instance->courseid or isset($existing[$c->id])) {
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
     * @return array
     */
    protected function get_target_group_options($coursecontext) {
        // $groups = array(0 => get_string('none'));
        $groups = [];
        $courseid = $coursecontext->instanceid;
        if (has_capability('moodle/course:managegroups', $coursecontext)) {
            $groups[ENROL_METAGROUP_CREATE_GROUP] = get_string('creategroup', 'enrol_metagroup');
        }
        foreach (groups_get_all_groups($courseid) as $group) {
            $groups[$group->id] = format_string($group->name, true, array('context' => $coursecontext));
        }
        return $groups;
    }

    /**
     * Return an array of valid options for the groups (in source course).
     *
     * @param context $coursecontext
     * @return array
     */
    protected function get_source_group_options($courseid, $nonempty_only = true, $include_groupsize = true) {

        $groups_with_members = groups_get_all_groups($courseid, 0, 0, 'g.*', true);
        
        $group_options = [];

        foreach ($groups_with_members as $group) {
            $size = count($group->members);
            if ($nonempty_only && $size <= 1) {
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
        global $DB;

        ///
        // throw new coding_exception('edit_instance_form');

        // $mform->addElement('html', '<pre>'.var_export($_POST, true).'</pre>');

        // $mform->addElement('html', '<pre>'.var_export($instance, true).'</pre>');
        // $mform->addElement('html', '<pre>'.var_export($mform, true).'</pre>');
        // $mform->addElement('html', '<pre>'.var_export($coursecontext, true).'</pre>');
        ///

        $creation_mode = empty($instance->id);
        $edit_mode = !$creation_mode;
        
        if ($creation_mode) {
            $creation_stage = 2;
            
            if ( ! $_POST['customint1'] /* || $_POST['reselect_course'] */) {
                $creation_stage = 1;
            }

        } else {
            $creation_stage = 0;
        }

        // $groups = $this->get_target_group_options($coursecontext);

        // Do not allow to link the same course as external.
        $excludelist = array($coursecontext->instanceid);

        $options = array(
            'requiredcapabilities' => array('enrol/metagroup:selectaslinked'),
            // 'multiple' => empty($instance->id),  // We only accept multiple values on creation.
            'exclude' => $excludelist
        );
        $mform->addElement('course', 'customint1', get_string('linkedcourse', 'enrol_metagroup'), $options);
        $mform->addRule('customint1', get_string('required'), 'required', null, 'client');
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

            $source_courseid = $instance->customint1 ?: $_POST['customint1'];
            // $source_courseid = $instance->customint1 ?: (array_key_exists('customint1', $_POST)? $_POST['customint1'] : 0);
            

            // • customint3 (source_groupid) — id группы-источника
            {
                // Show 'autocomplete' element (search & select UI).

                // Get all non-empty groups in source course, minus groups already used by this plugin.

                // Exclude groups that have already linked to this course.
                $existing = $DB->get_records('enrol', array('enrol' => 'metagroup', 'courseid' => $coursecontext->instanceid), '', 'id, customint3');

                $excludelist = [];
                foreach ($existing as $existinginstance) {
                    $excludelist[] = $existinginstance->customint3;
                }

                $options = array(

                    'multiple' => true, 
                    'placeholder' => /* 'Группа-источник' */ get_string('sourcegroup', 'enrol_metagroup'),
                    'noselectionstring' => /* 'Выберите группу…' */ get_string('searchgroup', 'enrol_metagroup'), 
                    'exclude' => $excludelist
                );         

                if (empty($instance->customint3)) {
                    // Get all possible options to choose from.
                    $groupnames = self::get_source_group_options($source_courseid);

                } else {
                    // Just one group chosen before.
                    $group = groups_get_group($instance->customint3);
                    $groupnames = [$group->id => $group->name];
                }

                $mform->addElement('autocomplete', 'customint3', /* 'Группа-источник' */ get_string('linkedgroup', 'enrol_metagroup'), $groupnames, $options);
                $mform->addRule('customint3', get_string('required'), 'required', null, 'client');
                $mform->addHelpButton('customint3', 'linkedgroup', 'enrol_metagroup');

                if (!empty($instance->customint3)) {
                    // Record was loaded from DB, thus the user is editing the form.
                    $mform->freeze('customint3');
                }
            }

            // • customint2 (target_groupid) — id группы-назначения

            $groups = $this->get_target_group_options($coursecontext);

            $mform->addElement('select', 'customint2', get_string('addgroup', 'enrol_metagroup'), $groups);
            $mform->addRule('customint2', get_string('required'), 'required', null, 'client');


            // • customint4 (updatable) — 1 - обновлять, 0 - не обновлять

            $options = [
                // 'Обновляемое зеркало группы'.
                ENROL_METAGROUP_UPDATABLE => get_string('syncmode_updatable', 'enrol_metagroup'),
                // 'Необновляемая копия группы'.
                ENROL_METAGROUP_NON_UPDATABLE => get_string('syncmode_snapshot', 'enrol_metagroup'),
            ];

            $select = $mform->addElement('select', 'customint4', /* 'Тип синхронизации' */ get_string('syncmode', 'enrol_metagroup'), $options);
            
            $select->setSelected(ENROL_METAGROUP_UPDATABLE);  // Default when no data is set to the form element.

            if (0) {
                // TODO: add setting to hide this option. Отключаение синхронизации может привести к накоплению мусора в курсах.
                $select->hide();  // ~~
            }
        }
        // Else: do not show group option until course is selected.
            
        /* 
        customint1 (source_courseid) — id курса-источника
        customint2 (target_groupid) — id группы-назначения
        customint3 (source_groupid) — id группы-источника
        customint4 (updatable) — 1 - обновлять, 0 - не обновлять
        customchar2 (source_groupname) — имя группы-источника (для констстентности, чтобы избежать накладок при переименовании группы-источника)    */
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
     * @return void
     */
    public function edit_instance_validation($data, $files, $instance, $context) {
        global $DB;

        $errors = array();

        ///

        // $instance->customint2 = 999;
        // $result = parent::update_instance($instance, $instance);
            // throw new coding_exception(123);

        // echo('<br><br><br>');
        // echo('<pre>'.var_export($data, true).'</pre>');

        // if ($data['customint1'] && ! $data['srcgroup']) {
        //     $errors['customint2x'] = 'Нужно задать группы';
        // }

        // if ($data['reselect_course']) {
        //     $errors['customint1'] = 'Вы можете выбрать другой курс';
        // }
        ///

        $thiscourseid = $context->instanceid;

        if (!empty($data['customint1'])) {
            $coursesidarr = is_array($data['customint1']) ? $data['customint1'] : [$data['customint1']];
            list($coursesinsql, $coursesinparams) = $DB->get_in_or_equal($coursesidarr, SQL_PARAMS_NAMED, 'metacourseid');
            $coursesrecords = $DB->get_records_select('course', "id {$coursesinsql}",
                $coursesinparams, '', 'id,visible');

            if ($coursesrecords) {
                // Cast NULL to 0 to avoid possible mess with the SQL.
                $instanceid = $instance->id ?? 0;

                $existssql = "enrol = :metagroup AND courseid = :currentcourseid AND id != :id AND customint1 {$coursesinsql}";
                $existsparams = [
                    'metagroup' => 'metagroup',
                    'currentcourseid' => $thiscourseid,
                    'id' => $instanceid
                ];
                $existsparams += $coursesinparams;
                if ($DB->record_exists_select('enrol', $existssql, $existsparams)) {
                    // // We may leave right here as further checks do not make sense in case we have existing enrol records
                    // // with the parameters from above.
                    // $errors['customint1'] = get_string('invalidcourseid1', 'error');
                    // ???
                } else {
                    foreach ($coursesrecords as $coursesrecord) {
                        $coursecontext = context_course::instance($coursesrecord->id);
                        if (!$coursesrecord->visible and !has_capability('moodle/course:viewhiddencourses', $coursecontext)) {
                            $errors['customint1'] = get_string('nopermissions', 'error',
                                'moodle/course:viewhiddencourses');
                        } else if (!has_capability('enrol/metagroup:selectaslinked', $coursecontext)) {
                            $errors['customint1'] = get_string('nopermissions', 'error',
                                'enrol/metagroup:selectaslinked');
                        } else if ($coursesrecord->id == SITEID or $coursesrecord->id == $thiscourseid) {
                            $errors['customint1'] = get_string('invalidcourseid2', 'error');
                        }
                    }
                }
            } else {
                $errors['customint1'] = get_string('invalidcourseid3', 'error');
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
