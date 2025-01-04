# Meta-group link  (enrol_metagroup plugin for Moodle 3+)
---

This enrollment plugin links specific groups between courses so that group members (e.g. students) can access a different course.  
As opposed to `Linked meta-course` enrol method, this plugin deals with separate groups, not with all course participants as a whole.

(In Russian:)

Этот плагин для зачисления связывает определенные группы между курсами, чтобы члены группы (например, студенты) могли получить доступ к другому курсу.  
В отличие от метода `Связанный мета-курс`, этот плагин работает с отдельными группами, а не со всеми участниками курса в целом.


## INSTALLATION USING GIT

1) Clone this repository.

2*) Copy `metagroup` directory to `<Moodle root>/enrol/` directory.

3*) Visit `/admin/index.php` as site administrator and follow plugin installation instructions.

4) Visit Plugins → Enrol methods → Enable `Metagroup link` (`Связь с метагруппой`) method (provided by this plugin). 


## INSTALLATION VIA ARCHIVE UPLOAD

(The same as above but steps 2 and 3.)

\*2) Put `metagroup` directory into a zip archive, so that it includes e.g. `<your.zip>/metagroup/version.php`.

\*3) Visit `/admin/tool/installaddon/index.php`, upload `<your.zip>` file, conirm and follow plugin installation instructions.


## USAGE

1) Navigate to one of your courses → Participants → Enrolment methods → Add → Choose `Metagroup link`

2) Setup new link:

 - Select a course and confirm
 - Choose group(s) to link
 - Optionally select traget group (a new groups is created by default for each linked group)
 - Confirm method creation

3) In editing the new enrol entry, only target group may be changed. If one needs to change source group to link, new enrol instance should be created.

4) The enrol entry (as well as all enrolled paricipants) can be suspended or removed as usual — in Participants → Enrolment methods.


## THANKS

This plugin is based on well-known `enrol_meta` built-in plugin (forked from Moodle 3.9.16 STABLE, version 2020061516).


