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


## PUBLIC API FOR PROGRAMMATIC ACCESS

Starting from version 2.1, the plugin provides a public API for programmatic creation, deletion, and retrieval of metagroup links. This API is designed for bulk synchronization from external sources (e.g., database sync plugins).

### Available Functions

#### `enrol_metagroup_create_link()`

Creates a metagroup link between source and target groups programmatically.

**Signature:**
```php
function enrol_metagroup_create_link(
    int $target_courseid,
    int $source_courseid,
    int $source_groupid,
    int|null $target_groupid = null,
    array $options = []
): stdClass|false
```

**Parameters:**
- `$target_courseid` - ID of the target course (where students will be enrolled)
- `$source_courseid` - ID of the source course (where source group is located)
- `$source_groupid` - ID of the source group
- `$target_groupid` - ID of the target group (if `null`, will be created automatically)
- `$options` - Optional array with additional options:
  - `'target_group_name'` => string|null - Optional explicit name for target group (avoids automatic suffix)
  - `'status'` => ENROL_INSTANCE_ENABLED|ENROL_INSTANCE_DISABLED (default: ENABLED)
  - `'roleid'` => int - Assigned role ID (default: student role)
  - `'sync_on_create'` => bool - Whether to sync immediately after creation (default: true)

**Returns:**
- Full enrolment instance object (`stdClass`) on success
- `false` on failure

**Examples:**
```php
// Create link with automatic target group creation
$instance = enrol_metagroup_create_link(
    $target_courseid = 5,
    $source_courseid = 3,
    $source_groupid = 10,
    $target_groupid = null  // will be created automatically
);
// $instance->id contains the created instance ID

// Create link with explicit target group name (no automatic suffix)
$instance = enrol_metagroup_create_link(
    $target_courseid = 5,
    $source_courseid = 3,
    $source_groupid = 10,
    $target_groupid = null,
    ['target_group_name' => 'My Custom Group Name']
);

// Create link with existing target group
$instance = enrol_metagroup_create_link(
    $target_courseid = 5,
    $source_courseid = 3,
    $source_groupid = 10,
    $target_groupid = 15  // use existing group
);
```

#### `enrol_metagroup_delete_link()`

Deletes a metagroup link by its parameters.

**Signature:**
```php
function enrol_metagroup_delete_link(
    int $target_courseid,
    int $source_courseid,
    int $source_groupid,
    int|null $target_groupid = null
): bool
```

**Parameters:**
- `$target_courseid` - ID of the target course
- `$source_courseid` - ID of the source course
- `$source_groupid` - ID of the source group
- `$target_groupid` - Optional: ID of the target group (for more precise matching)

**Returns:**
- `true` on success
- `false` on failure or if link not found

**Example:**
```php
$deleted = enrol_metagroup_delete_link(
    $target_courseid = 5,
    $source_courseid = 3,
    $source_groupid = 10
);
```

#### `enrol_metagroup_find_link()`

Finds an existing metagroup link by its parameters.

**Signature:**
```php
function enrol_metagroup_find_link(
    int $target_courseid,
    int $source_courseid,
    int $source_groupid,
    int|null $target_groupid = null
): stdClass|false
```

**Parameters:**
- Same as `enrol_metagroup_delete_link()`

**Returns:**
- Enrolment instance object (`stdClass`) on success
- `false` if not found

**Example:**
```php
$link = enrol_metagroup_find_link(5, 3, 10);
if ($link) {
    // Link exists, $link contains full enrolment instance
}
```

#### `enrol_metagroup_get_all_links()`

Gets all metagroup links in a unified format. Returns data in a format that doesn't require decoding custom fields, making it ideal for bulk synchronization.

**Signature:**
```php
function enrol_metagroup_get_all_links(
    int|null $target_courseid = null,
    int|null $source_courseid = null
): array
```

**Parameters:**
- `$target_courseid` - Optional: filter by target course ID
- `$source_courseid` - Optional: filter by source course ID

**Returns:**
Array of link objects, each containing:
- `id` => int - Enrolment instance ID
- `target_courseid` => int - Target course ID (courseid)
- `source_courseid` => int - Logical source course ID (customint1)
- `source_groupid` => int - Logical source group ID (customint3)
- `target_groupid` => int - Target group ID (customint2)
- `root_courseid` => int|null - Root source course ID (customint4, if exists)
- `root_groupid` => int|null - Root source group ID (customint5, if exists)
- `status` => int - ENROL_INSTANCE_ENABLED or DISABLED
- `roleid` => int - Assigned role ID
- `source_group_name` => string - Cached source group name (customchar2)
- `root_course_name` => string|null - Root course name (customchar1, if exists)
- `root_group_name` => string|null - Root group name (customchar3, if exists)
- `source_courses` => array - IDs of all source courses (from customtext1 JSON)

**Example:**
```php
// Get all links
$all_links = enrol_metagroup_get_all_links();
foreach ($all_links as $link) {
    echo "Link {$link->id}: Course {$link->source_courseid} → {$link->target_courseid}\n";
}

// Get links for specific target course
$course_links = enrol_metagroup_get_all_links($target_courseid = 5);

// Get links from specific source course
$source_links = enrol_metagroup_get_all_links(null, $source_courseid = 3);
```

### Bulk Synchronization Example

```php
// Get all existing links from database
$existing_links = enrol_metagroup_get_all_links();

// Get desired links from external source (e.g., database)
$desired_links = get_desired_links_from_external_source();

// Create missing links
foreach ($desired_links as $desired) {
    $found = false;
    foreach ($existing_links as $existing) {
        if ($existing->target_courseid == $desired->target_courseid &&
            $existing->source_courseid == $desired->source_courseid &&
            $existing->source_groupid == $desired->source_groupid) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        enrol_metagroup_create_link(
            $desired->target_courseid,
            $desired->source_courseid,
            $desired->source_groupid,
            $desired->target_groupid,
            ['target_group_name' => $desired->target_group_name]
        );
    }
}

// Delete links that should not exist
foreach ($existing_links as $existing) {
    $should_exist = false;
    foreach ($desired_links as $desired) {
        if ($existing->target_courseid == $desired->target_courseid &&
            $existing->source_courseid == $desired->source_courseid &&
            $existing->source_groupid == $desired->source_groupid) {
            $should_exist = true;
            break;
        }
    }
    if (!$should_exist) {
        enrol_metagroup_delete_link(
            $existing->target_courseid,
            $existing->source_courseid,
            $existing->source_groupid
        );
    }
}
```

(На русском:)

## ПУБЛИЧНЫЙ API ДЛЯ ПРОГРАММНОГО ДОСТУПА

Начиная с версии 2.1, плагин предоставляет публичный API для программного создания, удаления и получения метагрупповых связей. Этот API предназначен для массовой синхронизации из внешних источников (например, плагинов синхронизации с базой данных).

### Доступные функции

#### `enrol_metagroup_create_link()`

Создает метагрупповую связь между группами-источниками и группами-назначениями программно.

**Сигнатура:**
```php
function enrol_metagroup_create_link(
    int $target_courseid,
    int $source_courseid,
    int $source_groupid,
    int|null $target_groupid = null,
    array $options = []
): stdClass|false
```

**Параметры:**
- `$target_courseid` - ID целевого курса (куда будут зачислены студенты)
- `$source_courseid` - ID курса-источника (где находится группа-источник)
- `$source_groupid` - ID группы-источника
- `$target_groupid` - ID целевой группы (если `null`, будет создана автоматически)
- `$options` - Опциональный массив с дополнительными опциями:
  - `'target_group_name'` => string|null - Опциональное явное имя целевой группы (избегает автоматического суффикса)
  - `'status'` => ENROL_INSTANCE_ENABLED|ENROL_INSTANCE_DISABLED (по умолчанию: ENABLED)
  - `'roleid'` => int - ID назначаемой роли (по умолчанию: роль студента)
  - `'sync_on_create'` => bool - Синхронизировать ли сразу после создания (по умолчанию: true)

**Возвращает:**
- Полный объект экземпляра enrolment (`stdClass`) при успехе
- `false` при ошибке

#### `enrol_metagroup_delete_link()`

Удаляет метагрупповую связь по её параметрам.

**Сигнатура:**
```php
function enrol_metagroup_delete_link(
    int $target_courseid,
    int $source_courseid,
    int $source_groupid,
    int|null $target_groupid = null
): bool
```

**Возвращает:**
- `true` при успехе
- `false` при ошибке или если связь не найдена

#### `enrol_metagroup_find_link()`

Находит существующую метагрупповую связь по её параметрам.

**Сигнатура:**
```php
function enrol_metagroup_find_link(
    int $target_courseid,
    int $source_courseid,
    int $source_groupid,
    int|null $target_groupid = null
): stdClass|false
```

**Возвращает:**
- Объект экземпляра enrolment (`stdClass`) при успехе
- `false` если не найдена

#### `enrol_metagroup_get_all_links()`

Получает все метагрупповые связи в унифицированном формате. Возвращает данные в формате, который не требует расшифровки кастомных полей, что идеально для массовой синхронизации.

**Сигнатура:**
```php
function enrol_metagroup_get_all_links(
    int|null $target_courseid = null,
    int|null $source_courseid = null
): array
```

**Возвращает:**
Массив объектов связей, каждый содержит:
- `id` => int - ID экземпляра enrolment
- `target_courseid` => int - ID целевого курса
- `source_courseid` => int - ID логического курса-источника
- `source_groupid` => int - ID логической группы-источника
- `target_groupid` => int - ID целевой группы
- `root_courseid` => int|null - ID корневого курса-источника (если существует)
- `root_groupid` => int|null - ID корневой группы-источника (если существует)
- `status` => int - ENROL_INSTANCE_ENABLED или DISABLED
- `roleid` => int - ID назначаемой роли
- `source_group_name` => string - Кэшированное имя группы-источника
- `root_course_name` => string|null - Имя корневого курса (если существует)
- `root_group_name` => string|null - Имя корневой группы (если существует)
- `source_courses` => array - ID всех курсов-источников (из customtext1 JSON)


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


## SOURCE COURSES (customtext1)

Starting from a recent version, the plugin stores an extended list of source courses in `enrol.customtext1` as JSON. This list is used for recursive link validation and for displaying the full chain of courses on the edit form.

### Purpose

The field `customtext1` contains JSON:

```json
{"source_courses": [2, 5, 7, 12]}
```

The array lists all course IDs that supply students to the linked group, in order: root courses (deepest) first, intermediate courses, then the logical source course last. Unique IDs only.

### Computation algorithm

The list is derived from the enrolment methods of all members of the source group:

- **metagroup**: Use `customint4`/`customint1` of the parent link first; fallback to full recursive traversal via `enrol_metagroup_find_root_course`
- **meta**: 2 levels (current course + parent course from `customint1`)
- **other methods**: 1 level (current course only)

### When it is recalculated

- On instance creation
- When the source course or group is changed in the edit form
- Via the "Recalculate links" button on the edit form
- During sync for instances with empty `customtext1` (up to 50 per run)

### Recursive link validation

A link may not be created if the target course appears in the source courses of the chosen source. This avoids recursive links (e.g. from a child course back to a parent). Validation is performed in PHP only, without JSON functions in SQL, for compatibility with different databases.

### Edit form display

On the enrolment instance edit form, an informational block shows the full chain of courses and groups with active links, indicating how the target group was assembled from all source paths.

(На русском:)

## SOURCE COURSES (customtext1)

Начиная с одной из последних версий, плагин сохраняет расширенный список курсов-источников в `enrol.customtext1` в формате JSON. Список используется для проверки рекурсивных связей и для отображения полной цепочки курсов на форме редактирования.

### Назначение

Поле `customtext1` содержит JSON:

```json
{"source_courses": [2, 5, 7, 12]}
```

Массив перечисляет ID всех курсов, поставляющих студентов в связанную группу, в порядке: сначала корневые курсы (максимальная глубина), затем промежуточные, в конце — логический курс-источник. Только уникальные ID.

### Алгоритм вычисления

Список строится на основе способов зачисления участников группы-источника:

- **metagroup**: сначала используются `customint4`/`customint1` родительской связи; при отсутствии — полный рекурсивный обход через `enrol_metagroup_find_root_course`
- **meta**: 2 уровня (текущий курс + родительский курс из `customint1`)
- **остальные способы**: 1 уровень (только текущий курс)

### Когда пересчитывается

- При создании экземпляра
- При изменении курса-источника или группы в форме редактирования
- По кнопке «Пересчитать связи» на форме редактирования
- При sync для экземпляров с пустым `customtext1` (до 50 за запуск)

### Проверка рекурсивных связей

Связь не может быть создана, если целевой курс входит в список курсов-источников выбранного источника. Это предотвращает рекурсивные связи (например, из дочернего курса обратно в родительский). Проверка выполняется только в PHP, без JSON-функций в SQL, для совместимости с разными СУБД.

### Отображение на форме редактирования

На форме редактирования экземпляра способа зачисления выводится информационный блок с полной цепочкой курсов и групп с активными ссылками, показывающий, как целевая группа была собрана из всех путей-источников.


## THANKS

This plugin is based on well-known `enrol_meta` built-in plugin (forked from Moodle 3.9.16 STABLE, version 2020061516).


