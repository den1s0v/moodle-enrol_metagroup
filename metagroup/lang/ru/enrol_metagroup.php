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
 * Strings for component 'enrol_metagroup', language 'ru'.
 *
 * @package    enrol_metagroup
 * @copyright  2010 onwards Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['addgroup'] = 'Добавить в группу';
$string['coursesort'] = 'Сортировка списка курсов';
$string['coursesort_help'] = 'Это определяет, будет ли список курсов, которые можно связать, отсортирован по порядку сортировки (т.е. по порядку, установленному в Администрирование сайта > Курсы > Управление курсами и категориями) или в алфавитном порядке по настройкам курса.';
$string['changecourseselection'] = '← Выбрать другой курс';
$string['creategroup_one'] = 'Создать новую группу';
$string['creategroup_many'] = 'Создать новую группу для каждой группы';
// $string['creategroup_universal'] = 'Создать новую(-ые) группу(-ы)';
$string['defaultgroupnametext'] = '{$a->name} (связ.) {$a->increment}';
$string['defaultenrolnametext'] = '{$a->method} (группа «{$a->target_group}» связана с «{$a->source_group}» в «{$a->source_course}»)';
$string['deleteemptygroups'] = 'Удалять опустевшие группы';
$string['deleteemptygroups_desc'] = 'Если включено, автоматически созданые, но впоследствии опустевшие группы будут удалены при потере ссылки на родительский курс и отчислении всех студентов (см. соответствующую настройку выше).';
$string['enrolmetasynctask'] = 'Задача синхронизации зачислений метагрупп';
$string['linkedcourse'] = 'Курс c группой';
$string['linkedcourse_help'] = 'Другой курс, в котором находится группа-источник, т.е. группа, которая будет связана с текущим курсом.';
$string['limittoenrolled'] = 'Только личные курсы';
$string['limittoenrolled_help'] = 'При выборе курса для создания связи предлагать только те курсы, на которые пользователь зачислен явно. Если включено, то пользователи с расширенными правами на уровне категории курса не смогут пользоваться этими правами при выборе связываемых курсов.';
$string['linkedgroup'] = 'Связать группу';
$string['linkedgroup_help'] = 'Связываемая группа из выбранного курса. • Обратите внимание: Пользователи, зачисленные в другой курс этим же способом связи («Связь с метагруппой») НЕ могут быть перенесены в текущий курс во избежание циклических связей. Если Вы собирались сделать "цепочку" из метагрупп, то используйте вместо этого группу из самого первого в "цепочке" курса.';
$string['lostlinkaction'] = 'При потере связи c родительским курсом';
$string['lostlinkaction_desc'] = 'Что делать, когда родительский курс или группа удалены. Удаление опустевших групп контролируется настройкой ниже.';
$string['lostlinkaction_keep'] = 'Оставить студентов активными';
$string['lostlinkaction_suspend'] = 'Заблокировать студентов';
$string['lostlinkaction_unenrol'] = 'Исключить студентов';
$string['metagroup:config'] = 'Настроить экземпляры метагруппы';
$string['metagroup:selectaslinked'] = 'Выбрать группу курса для связывания';
$string['metagroup:unenrol'] = 'Отчислить заблокированных пользователей';
$string['nosourcegroups'] = 'Не удалось найти группы в выбранном курсе (либо они уже связаны с текущим курсом)';
$string['nosyncroleids'] = 'Роли, которые не синхронизируются';
$string['nosyncroleids_desc'] = 'По умолчанию, все назначения ролей на уровне курса синхронизируются от родительских к дочерним курсам. Роли, выбранные здесь, не будут включены в процесс синхронизации. Роли, доступные для синхронизации, будут обновлены при следующем выполнении cron.';
$string['pluginname'] = 'Связь с метагруппой';
$string['pluginname_desc'] = 'Плагин записи метагруппой курса синхронизирует зачисления и роли из группы одного курса в группу другого курсов.';
$string['searchgroup'] = 'Выберите группу…';
$string['sourcegroup'] = 'Группа-источник';
$string['syncall'] = 'Синхронизировать всех участников группы';
$string['syncall_desc'] = 'Если включено, то синхронизируются все участники группы, даже если у них нет роли в родительском курсе; если отключено, то только пользователи, имеющие хотя бы одну синхронизируемую роль, будут зачислены в дочерний курс.';
$string['syncmode'] = 'Тип синхронизации';
$string['syncmode_updatable'] = 'Обновляемое зеркало группы';
$string['syncmode_snapshot'] = 'Необновляемая копия группы';
$string['privacy:metadata:core_group'] = 'Плагин метагруппы может создать новую группу или использовать существующую группу для добавления всех участников связанной группы другого курса.';
