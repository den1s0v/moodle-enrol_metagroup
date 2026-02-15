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
 * Meta enrolment plugin settings and presets.
 *
 * @package    enrol_metagroup
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    //--- general settings -----------------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_metagroup_settings', '', get_string('pluginname_desc', 'enrol_metagroup')));

    if (!during_initial_install()) {
        $allroles = role_fix_names(get_all_roles(), null, ROLENAME_ORIGINALANDSHORT, true);
        $settings->add(new admin_setting_configmultiselect('enrol_metagroup/nosyncroleids', get_string('nosyncroleids', 'enrol_metagroup'), get_string('nosyncroleids_desc', 'enrol_metagroup'), array(), $allroles));

        $settings->add(new admin_setting_configcheckbox('enrol_metagroup/syncall', get_string('syncall', 'enrol_metagroup'), get_string('syncall_desc', 'enrol_metagroup'), 1));

        $options = array(
            ENROL_EXT_REMOVED_UNENROL        => get_string('extremovedunenrol', 'core_enrol'),
            ENROL_EXT_REMOVED_SUSPEND        => get_string('extremovedsuspend', 'core_enrol'),
            ENROL_EXT_REMOVED_SUSPENDNOROLES => get_string('extremovedsuspendnoroles', 'core_enrol'),
        );
        $settings->add(new admin_setting_configselect('enrol_metagroup/unenrolaction', get_string('extremovedaction', 'enrol'), get_string('extremovedaction_help', 'enrol'), ENROL_EXT_REMOVED_SUSPENDNOROLES, $options));

        $settings->add(new admin_setting_configselect(
            'enrol_metagroup/lostlinkaction',
            get_string('lostlinkaction', 'enrol_metagroup'),
            get_string('lostlinkaction_desc', 'enrol_metagroup'),
            ENROL_EXT_REMOVED_SUSPENDNOROLES, // По умолчанию: оставить студентов, но заблокировать.
            array(
                ENROL_EXT_REMOVED_KEEP => get_string('lostlinkaction_keep', 'enrol_metagroup'), // Оставить активными.
                ENROL_EXT_REMOVED_SUSPENDNOROLES => get_string('lostlinkaction_suspend', 'enrol_metagroup'), // Заблокировать.
                ENROL_EXT_REMOVED_UNENROL => get_string('lostlinkaction_unenrol', 'enrol_metagroup'), // Удалить.
            )
        ));

        $settings->add(new admin_setting_configcheckbox(
            'enrol_metagroup/deleteemptygroups',
            get_string('deleteemptygroups', 'enrol_metagroup'),
            get_string('deleteemptygroups_desc', 'enrol_metagroup'),
            0 // По умолчанию: не удалять пустые группы
        ));

        $settings->add(new admin_setting_configcheckbox(
            'enrol_metagroup/addgroupsuffix',
            get_string('addgroupsuffix', 'enrol_metagroup'),
            get_string('addgroupsuffix_desc', 'enrol_metagroup'),
            1
        ));

        $sortoptions = array(
            'sortorder' => new lang_string('sort_sortorder', 'admin'),
            'fullname' => new lang_string('sort_fullname', 'admin'),
            'shortname' => new lang_string('sort_shortname', 'admin'),
            'idnumber' => new lang_string('sort_idnumber', 'admin'),
        );
        $settings->add(new admin_setting_configselect(
            'enrol_metagroup/coursesort',
            new lang_string('coursesort', 'enrol_metagroup'),
            new lang_string('coursesort_help', 'enrol_metagroup'),
            'sortorder',
            $sortoptions
        ));

        $settings->add($setting = new admin_setting_configcheckbox('enrol_metagroup/limittoenrolled',
        get_string('limittoenrolled', 'enrol_metagroup'), get_string('limittoenrolled_help', 'enrol_metagroup'), 0));

        $url = new moodle_url('/enrol/metagroup/cleanup_empty_groups.php');
        $settings->add(new admin_setting_description('enrol_metagroup_cleanup_link',
            get_string('cleanup_empty_groups', 'enrol_metagroup'),
            html_writer::link($url, get_string('cleanup_empty_groups_run', 'enrol_metagroup'))));

    }
}
