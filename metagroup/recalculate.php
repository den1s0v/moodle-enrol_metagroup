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
 * Recalculate source_courses (customtext1) for a metagroup enrol instance.
 *
 * @package    enrol_metagroup
 * @copyright  2026 Denisov Mikhail (VSTU)
 * @copyright  based on work by 2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once("$CFG->dirroot/enrol/metagroup/locallib.php");

global $DB;

$id = required_param('id', PARAM_INT); // Enrol instance id.
$courseid = required_param('courseid', PARAM_INT);

require_sesskey();

$course = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('enrol/metagroup:config', $context);

$instance = $DB->get_record('enrol', ['id' => $id, 'courseid' => $courseid, 'enrol' => 'metagroup'], '*', MUST_EXIST);

$source_courses = enrol_metagroup_compute_source_courses($instance->customint1, $instance->customint3);
$DB->set_field('enrol', 'customtext1', json_encode(['source_courses' => $source_courses]), ['id' => $instance->id]);

$url = new moodle_url('/enrol/editinstance.php', [
    'courseid' => $courseid,
    'id' => $id,
    'type' => 'metagroup',
]);
redirect($url, get_string('recalculate_links', 'enrol_metagroup') . ': ' . get_string('success'), null, \core\output\notification::NOTIFY_SUCCESS);
