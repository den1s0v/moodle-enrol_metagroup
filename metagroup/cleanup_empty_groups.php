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
 * Admin page to cleanup empty orphaned groups and collapse numbered copies.
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
$action = optional_param('action', '', PARAM_ALPHA);

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

// --- Form 1: orphan empty groups cleanup ---
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
        'checked' => ($dryrun && $action === 'cleanup') ? 'checked' : null,
    ]) .
    html_writer::label(get_string('cleanup_empty_groups_dryrun', 'enrol_metagroup'), 'id_dryrun'),
    'form-group form-check'
);

$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'cleanup']);
$form .= html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('cleanup_empty_groups_run', 'enrol_metagroup'),
    'class' => 'btn btn-primary',
]);
$form .= html_writer::end_tag('fieldset');
$form .= html_writer::end_tag('form');
echo $form;

// --- Form 2: collapse numbered copies (exactly one course) ---
echo $OUTPUT->heading(get_string('collapse_numbered_copies', 'enrol_metagroup'), 3);
echo html_writer::tag('p', get_string('collapse_numbered_copies_description', 'enrol_metagroup'), ['class' => 'mb-3']);

$form2 = html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'mform']);
$form2 .= html_writer::start_tag('fieldset', ['class' => 'clearfix']);
$form2 .= html_writer::tag('legend', get_string('collapse_numbered_copies', 'enrol_metagroup'), ['class' => 'sr-only']);

$form2 .= html_writer::div(
    html_writer::label(get_string('cleanup_empty_groups_courseids', 'enrol_metagroup'), 'id_collapse_courseid') .
    html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'courseids',
        'id' => 'id_collapse_courseid',
        'value' => s($courseids),
        'class' => 'form-control',
        'size' => 20,
        'placeholder' => 'e.g. 5',
    ]) .
    html_writer::span(get_string('collapse_numbered_copies_courseid_required', 'enrol_metagroup'), 'form-text text-muted'),
    'form-group'
);

$form2 .= html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'dryrun',
        'id' => 'id_collapse_dryrun',
        'value' => '1',
        'checked' => ($dryrun && $action === 'collapse') ? 'checked' : null,
    ]) .
    html_writer::label(get_string('cleanup_empty_groups_dryrun', 'enrol_metagroup'), 'id_collapse_dryrun'),
    'form-group form-check'
);

$form2 .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$form2 .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'collapse']);
$form2 .= html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('collapse_numbered_copies_run', 'enrol_metagroup'),
    'class' => 'btn btn-secondary',
]);
$form2 .= html_writer::end_tag('fieldset');
$form2 .= html_writer::end_tag('form');
echo $form2;

if ($action === 'cleanup') {
    require_sesskey();
    $ids = [];
    if (trim($courseids) !== '') {
        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $courseids))));
    }

    $result = enrol_metagroup_cleanup_empty_groups($ids, true, (bool)$dryrun);

    echo $OUTPUT->heading(
        $dryrun
            ? get_string('cleanup_empty_groups_preview_result', 'enrol_metagroup')
            : get_string('cleanup_empty_groups_result', 'enrol_metagroup'),
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
} else if ($action === 'collapse') {
    require_sesskey();

    $parts = array_values(array_filter(array_map('trim', explode(',', $courseids)), 'strlen'));
    $ids = array_map('intval', $parts);
    $ids = array_values(array_filter($ids));

    if (count($ids) !== 1) {
        echo html_writer::div(
            get_string('collapse_numbered_copies_courseid_required', 'enrol_metagroup'),
            'alert alert-danger'
        );
    } else {
        $cid = $ids[0];
        // Forced: runs even when reuseemptygroup setting is off.
        $result = enrol_metagroup_collapse_course_groups($cid, (bool)$dryrun);

        echo $OUTPUT->heading(
            $dryrun
                ? get_string('collapse_numbered_copies_preview_result', 'enrol_metagroup')
                : get_string('collapse_numbered_copies_result', 'enrol_metagroup'),
            3
        );

        if (empty($result['actions'])) {
            echo html_writer::div(get_string('collapse_numbered_copies_none', 'enrol_metagroup'), 'alert alert-info');
        } else {
            $table = new html_table();
            $table->head = [
                get_string('course', 'core'),
                get_string('group', 'core'),
                get_string('status', 'core'),
            ];
            $table->data = [];
            $table->attributes['class'] = 'generaltable';

            $coursename = $DB->get_field('course', 'shortname', ['id' => $cid]);
            $courselabel = $coursename ? s($coursename) : $cid;

            foreach ($result['actions'] as $row) {
                $label = s($row->name);
                if ($row->action === 'rename' && !empty($row->newname)) {
                    $label .= ' → ' . s($row->newname) . ' (id ' . (int)$row->groupid . ')';
                    $status = $dryrun
                        ? get_string('collapse_numbered_copies_would_rename', 'enrol_metagroup')
                        : get_string('collapse_numbered_copies_renamed', 'enrol_metagroup');
                } else if ($row->action === 'delete') {
                    $status = $dryrun
                        ? get_string('collapse_numbered_copies_would_delete', 'enrol_metagroup')
                        : get_string('collapse_numbered_copies_deleted', 'enrol_metagroup');
                } else if ($row->action === 'retarget') {
                    $label .= ' → id ' . (int)$row->toid;
                    $status = get_string('collapse_numbered_copies_retargeted', 'enrol_metagroup');
                } else if ($row->action === 'skipped') {
                    $status = !empty($row->error)
                        ? s($row->error)
                        : get_string('cleanup_empty_groups_skipped', 'enrol_metagroup');
                } else {
                    $status = s($row->action);
                }
                $table->data[] = [$courselabel, $label, $status];
            }

            echo html_writer::table($table);
            echo html_writer::div(
                get_string('collapse_numbered_copies_total', 'enrol_metagroup', $result['total']),
                'alert alert-info'
            );
        }
    }

    echo html_writer::link($returnurl, get_string('back', 'core'));
}

echo $OUTPUT->footer();
