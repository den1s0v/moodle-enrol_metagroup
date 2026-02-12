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
 * Admin page to cleanup empty orphaned groups in courses.
 *
 * @package    enrol_metagroup
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/enrol/metagroup/locallib.php');

global $DB;

$courseids = optional_param('courseids', '', PARAM_RAW);
$dryrun = optional_param('dryrun', 0, PARAM_BOOL);
$submit = optional_param('submit', 0, PARAM_BOOL);

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');

$PAGE->set_url(new moodle_url('/enrol/metagroup/cleanup_empty_groups.php'));
$PAGE->set_title(get_string('cleanup_empty_groups', 'enrol_metagroup'));
$PAGE->set_heading(get_string('cleanup_empty_groups', 'enrol_metagroup'));

$returnurl = new moodle_url('/admin/settings.php');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('cleanup_empty_groups', 'enrol_metagroup'));

echo html_writer::tag('p', get_string('cleanup_empty_groups_description', 'enrol_metagroup'), ['class' => 'mb-3']);

$form = html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'mform']);
$form .= html_writer::start_tag('fieldset', ['class' => 'clearfix']);
$form .= html_writer::tag('legend', get_string('cleanup_empty_groups', 'enrol_metagroup'), ['class' => 'sr-only']);

$form .= html_writer::div(
    html_writer::label(get_string('cleanup_empty_groups_courseids', 'enrol_metagroup'), 'id_courseids') .
    html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'courseids',
        'id' => 'id_courseids',
        'value' => s($courseids),
        'class' => 'form-control',
        'size' => 60,
        'placeholder' => 'e.g. 5, 10, 15',
    ]) .
    html_writer::span(get_string('cleanup_empty_groups_courseids_help', 'enrol_metagroup'), 'form-text text-muted'),
    'form-group'
);

$form .= html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'dryrun',
        'id' => 'id_dryrun',
        'value' => '1',
        'checked' => $dryrun ? 'checked' : null,
    ]) .
    html_writer::label(get_string('cleanup_empty_groups_dryrun', 'enrol_metagroup'), 'id_dryrun'),
    'form-group form-check'
);

$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'submit', 'value' => '1']);
$form .= html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('cleanup_empty_groups_run', 'enrol_metagroup'), 'class' => 'btn btn-primary']);
$form .= html_writer::end_tag('fieldset');
$form .= html_writer::end_tag('form');

echo $form;

if ($submit) {
    require_sesskey();
    $ids = [];
    if (trim($courseids) !== '') {
        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $courseids))));
    }

    $result = enrol_metagroup_cleanup_empty_groups($ids, true, (bool)$dryrun);

    echo $OUTPUT->heading(
        $dryrun ? get_string('cleanup_empty_groups_preview_result', 'enrol_metagroup') : get_string('cleanup_empty_groups_result', 'enrol_metagroup'),
        3
    );

    if (empty($result['deleted']) && empty($result['skipped'])) {
        echo html_writer::div(get_string('cleanup_empty_groups_none', 'enrol_metagroup'), 'alert alert-info');
    } else {
        $table = new html_table();
        $table->head = [
            get_string('course', 'core'),
            get_string('group', 'core'),
            get_string('status', 'core'),
        ];
        $table->data = [];
        $table->attributes['class'] = 'generaltable';

        foreach ($result['deleted'] as $g) {
            $coursename = $DB->get_field('course', 'shortname', ['id' => $g->courseid]);
            $table->data[] = [
                $coursename ? s($coursename) : $g->courseid,
                s($g->name),
                $dryrun ? get_string('yes', 'core') : get_string('cleanup_empty_groups_deleted', 'enrol_metagroup'),
            ];
        }
        foreach ($result['skipped'] as $g) {
            $obj = (object)(array)$g;
            $coursename = isset($obj->courseid) ? $DB->get_field('course', 'shortname', ['id' => $obj->courseid]) : '';
            $table->data[] = [
                $coursename ? s($coursename) : (isset($obj->courseid) ? $obj->courseid : '-'),
                isset($obj->name) ? s($obj->name) : '-',
                isset($obj->error) ? s($obj->error) : get_string('cleanup_empty_groups_skipped', 'enrol_metagroup'),
            ];
        }

        echo html_writer::table($table);
        echo html_writer::div(
            get_string('cleanup_empty_groups_total', 'enrol_metagroup', $result['total_deleted']),
            'alert alert-info'
        );
    }

    echo html_writer::link($returnurl, get_string('back', 'core'));
}

echo $OUTPUT->footer();
