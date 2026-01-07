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


## TRANSITIVE (CHAIN) LINKS

Starting from version 2.0, the plugin supports transitive (chain) links between courses. This allows creating metagroup links from child courses to third courses, even if you don't have direct access to the root course.

### How it works

**Example scenario:**
- Course A (root) → Course B (via metagroup link)
- Course B → Course C (new transitive link)

When creating a link from Course B to Course C, the plugin automatically:
1. Detects that Course B is a child course (has incoming metagroup links)
2. Finds the root course (Course A) through the chain
3. Creates a direct link from Course A to Course C "under the hood"
4. Stores both logical (what you see: B → C) and root (what's used for sync: A → C) information

**Benefits:**
- Teachers with access only to Course B can create links to Course C
- All synchronization still happens from the root course (star topology)
- No circular dependencies are created
- Only direct self-loops are prevented (course linking to itself)

### Technical details

- **Logical source** (`customint1`, `customint3`): The course and group you see and select in the UI
- **Root source** (`customint4`, `customint5`): The actual root course and group used for synchronization
- The plugin automatically maintains both sets of information for proper display and synchronization

### Special cases

**Aggregated groups:** If a group in the source course has members from multiple metagroup links, all root sources are automatically detected and separate links are created for each root source.

**Manually extended groups:** If students were manually added to a group that was created via a metagroup link, both root and logical sources are considered during synchronization to ensure no students are lost.

### What happens when an intermediate link is deleted?

When a intermediate metagroup link is deleted (e.g., A → B), dependent links (B → C) are automatically detected and marked as "lost links". They are then processed according to the `lostlinkaction` plugin setting:
- **KEEP**: Links remain but may not function correctly
- **SUSPENDNOROLES**: Students are suspended and roles are removed
- **UNENROL**: Students are unenrolled from the course

(На русском:)

## ТРАНЗИТИВНЫЕ (ЦЕПОЧЕЧНЫЕ) СВЯЗИ

Начиная с версии 2.0, плагин поддерживает транзитивные (цепочечные) связи между курсами. Это позволяет создавать метагрупповые связи из дочерних курсов на третий курс, даже если у вас нет прямого доступа к корневому курсу.

### Как это работает

**Пример сценария:**
- Курс A (корневой) → Курс B (через метагрупповую связь)
- Курс B → Курс C (новая транзитивная связь)

При создании связи из Курса B в Курс C, плагин автоматически:
1. Определяет, что Курс B является дочерним (имеет входящие метагрупповые связи)
2. Находит корневой курс (Курс A) через цепочку
3. Создаёт прямую связь от Курса A к Курсу C "под капотом"
4. Сохраняет как логическую (то, что вы видите: B → C), так и корневую (то, что используется для синхронизации: A → C) информацию

**Преимущества:**
- Преподаватели с доступом только к Курсу B могут создавать связи на Курс C
- Вся синхронизация всё равно происходит от корневого курса (топология "звезды")
- Циклические зависимости не создаются
- Запрещены только непосредственные самоссылки (курс на сам себя)

### Технические детали

- **Логический источник** (`customint1`, `customint3`): Курс и группа, которые вы видите и выбираете в интерфейсе
- **Корневой источник** (`customint4`, `customint5`): Фактический корневой курс и группа, используемые для синхронизации
- Плагин автоматически поддерживает оба набора информации для правильного отображения и синхронизации

### Особые случаи

**Сводные группы:** Если группа в исходном курсе имеет членов из нескольких метагрупповых связей, все корневые источники автоматически определяются и создаются отдельные связи для каждого корневого источника.

**Вручную расширенные группы:** Если студенты были добавлены вручную в группу, которая была создана через метагрупповую связь, при синхронизации учитываются как корневой, так и логический источники, чтобы студенты не терялись.

### Что происходит при удалении промежуточной связи?

При удалении промежуточной метагрупповой связи (например, A → B), зависимые связи (B → C) автоматически обнаруживаются и помечаются как "потерянные связи". Затем они обрабатываются согласно настройке плагина `lostlinkaction`:
- **KEEP**: Связи остаются, но могут работать некорректно
- **SUSPENDNOROLES**: Студенты блокируются, роли отзываются
- **UNENROL**: Студенты отчисляются из курса


## THANKS

This plugin is based on well-known `enrol_meta` built-in plugin (forked from Moodle 3.9.16 STABLE, version 2020061516).


