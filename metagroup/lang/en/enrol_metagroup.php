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
 * Strings for component 'enrol_metagroup', language 'en'.
 *
 * @package    enrol_metagroup
 * @copyright  2010 onwards Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['addgroup'] = 'Add to group';
$string['addgroupsuffix'] = 'Add "(linked)" suffix to group names';
$string['addgroupsuffix_desc'] = 'If enabled, automatically created target groups will be named with a suffix (e.g. "Group (linked)"). Numbering (2), (3) for duplicate names still applies when disabled.';
$string['cleanup_empty_groups'] = 'Clean empty groups';
$string['cleanup_empty_groups_courseids'] = 'Course IDs';
$string['cleanup_empty_groups_courseids_help'] = 'Comma-separated course IDs. Leave empty to process all courses that have metagroup instances.';
$string['cleanup_empty_groups_deleted'] = 'Deleted';
$string['cleanup_empty_groups_description'] = 'Remove empty groups that are no longer used as targets by any metagroup enrolment. Groups still referenced as customint2 are not deleted.';
$string['cleanup_empty_groups_dryrun'] = 'Preview only (no deletion)';
$string['cleanup_empty_groups_none'] = 'No empty orphaned groups found.';
$string['cleanup_empty_groups_preview_result'] = 'Preview: groups that would be deleted';
$string['cleanup_empty_groups_result'] = 'Cleanup result';
$string['cleanup_empty_groups_run'] = 'Run cleanup';
$string['cleanup_empty_groups_skipped'] = 'Skipped (still in use)';
$string['cleanup_empty_groups_total'] = 'Total deleted: {$a}';
$string['cleanup_empty_groups_would_delete'] = 'Would delete';
$string['coursesort'] = 'Sort course list';
$string['coursesort_help'] = 'This determines whether the list of courses that can be linked are sorted by sort order (i.e. the order set in Site administration > Courses > Manage courses and categories) or alphabetically by course setting.';
$string['changecourseselection'] = '← Select another course';
$string['creategroup_one'] = 'Create common group with course name';
$string['creategroup_many'] = 'Create copies of selected groups';
// $string['creategroup_universal'] = 'Create new group(s)';
$string['defaultgroupnametext'] = '{$a->name} (linked) {$a->increment}';
$string['defaultenrolnametext'] = '{$a->method} (group «{$a->target_group}» linked with «{$a->source_group}» from «{$a->source_course}»)';
$string['deleteemptygroups'] = 'Delete empty groups';
$string['deleteemptygroups_desc'] = 'If enabled, empty groups will be deleted when the link to the parent course is lost.';
$string['enrolmetasynctask'] = 'Meta-group enrolment sync task';
$string['linkedcourse'] = 'Link course';
$string['linkedcourse_help'] = 'The other course in which the source group is located, i.e. the group that will be linked to the current course.';
$string['limittoenrolled'] = 'Personal courses only';
$string['limittoenrolled_help'] = 'In selecting a course to create a link, only suggest those courses that the user is explicitly enrolled in. If enabled, users with extended rights at the course category level will not be able to use these rights when choosing courses to link.';
$string['linkedgroup'] = 'Link group';
$string['linkedgroup_help'] = 'The linked group from the selected course. Chain links are supported — you may use a group from an intermediate course that receives members via metagroup (e.g. A→B→C). Recursive links are prevented during validation.';
$string['lostlinkaction'] = 'Behavior when the link to the parent course is lost';
$string['lostlinkaction_desc'] = 'What to do when the parent course or group is deleted. The deletion of empty groups is controlled by the setting below.';
$string['lostlinkaction_keep'] = 'Keep students active';
$string['lostlinkaction_suspend'] = 'Suspend students';
$string['lostlinkaction_unenrol'] = 'Unenrol students';
$string['metagroup:config'] = 'Configure metagroup enrol instances';
$string['metagroup:selectaslinked'] = 'Select group of a course as linked';
$string['metagroup:unenrol'] = 'Unenrol suspended users';
$string['nosourcegroups'] = 'Couldn\'t find any groups in the selected course (or these are already linked to the current course)';
$string['nosyncroleids'] = 'Roles that are not synchronised';
$string['nosyncroleids_desc'] = 'By default all course level role assignments are synchronised from parent to child courses. Roles that are selected here will not be included in the synchronisation process. The roles available for synchronisation will be updated in the next cron execution.';
$string['pluginname'] = 'Metagroup link';
$string['pluginname_desc'] = 'Metagroup link enrolment plugin synchronises enrolments and roles from a group in one course to a group in another course.';
$string['searchgroup'] = 'Select a group…';
$string['sourcegroup'] = 'Source group';
$string['syncall'] = 'Synchronise all group members';
$string['syncall_desc'] = 'If enabled all group members are synchronised even if they have no role in parent course, if disabled only users that have at least one synchronised role are enrolled in child course.';
$string['syncmode'] = 'Sync kind';
$string['syncmode_updatable'] = 'Updatable group (mirroring)';
$string['syncmode_snapshot'] = 'Non-updatable group (snapshot)';
$string['sourcecourseschain'] = 'Source courses chain';
$string['recalculate_links'] = 'Recalculate links';
$string['recursive_link_error'] = 'Cannot create link: the target course is in the source chain (recursive link).';
$string['privacy:metadata:core_group'] = 'Enrol metagroup plugin can create a new group or use an existing group to add all the participants of the other course\'s group linked.';
