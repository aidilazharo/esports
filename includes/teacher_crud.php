<?php
declare(strict_types=1);

function teacherCrudScalar(PDO $pdo, string $sql, array $params = [], int|float|string $fallback = 0): int|float|string
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $fallback : $value;
    } catch (Throwable $e) {
        error_log('Teacher CRUD stat query failed: ' . $e->getMessage());
        return $fallback;
    }
}

function teacherCrudOptions(PDO $pdo, string $sql, array $params = [], string $valueKey = 'id', string $labelKey = 'label'): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row[$valueKey]] = (string)$row[$labelKey];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('Teacher CRUD options query failed: ' . $e->getMessage());
        return [];
    }
}

function teacherCrudDbDateTimeToInput(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $ts = strtotime($value);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

function teacherCrudInputDateTimeToDb(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $ts = strtotime($value);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

function teacherCrudNormalizeValue(array $field, mixed $raw): mixed
{
    $type = (string)($field['type'] ?? 'text');

    if ($type === 'checkbox') return $raw ? 1 : 0;
    if (is_string($raw)) $raw = trim($raw);

    if ($raw === '' || $raw === null) {
        if (!empty($field['required'])) return $field['default'] ?? '';
        return null;
    }

    if ($type === 'number') return !empty($field['integer']) ? (int)$raw : (float)$raw;
    if ($type === 'datetime-local') return teacherCrudInputDateTimeToDb((string)$raw);
    if ($type === 'date') {
        $ts = strtotime((string)$raw);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    return $raw;
}

function teacherCrudReadPostData(array $fields): array
{
    $data = [];
    foreach ($fields as $name => $field) {
        $raw = (($field['type'] ?? '') === 'checkbox')
            ? (isset($_POST[$name]) ? 1 : 0)
            : ($_POST[$name] ?? null);

        $value = teacherCrudNormalizeValue($field, $raw);
        if (!empty($field['required']) && ($value === null || $value === '')) {
            throw new RuntimeException(($field['label'] ?? $name) . ' is required.');
        }
        $data[$name] = $value;
    }
    return $data;
}

function teacherCrudRenderField(string $name, array $field, mixed $value): string
{
    $label = (string)($field['label'] ?? $name);
    $type = (string)($field['type'] ?? 'text');
    $required = !empty($field['required']) ? ' required' : '';
    $col = (string)($field['col'] ?? 'col-md-6');
    $help = (string)($field['help'] ?? '');
    $placeholder = (string)($field['placeholder'] ?? '');
    $min = isset($field['min']) ? ' min="' . e((string)$field['min']) . '"' : '';
    $max = isset($field['max']) ? ' max="' . e((string)$field['max']) . '"' : '';
    $step = isset($field['step']) ? ' step="' . e((string)$field['step']) . '"' : '';

    ob_start(); ?>
    <div class="<?= e($col) ?>">
        <?php if ($type === 'checkbox'): ?>
            <div class="form-check mt-4 pt-2">
                <input class="form-check-input" type="checkbox" value="1" id="<?= e($name) ?>" name="<?= e($name) ?>" <?= (int)$value === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="<?= e($name) ?>"><?= e($label) ?></label>
            </div>
        <?php else: ?>
            <label class="form-label" for="<?= e($name) ?>"><?= e($label) ?><?= !empty($field['required']) ? ' *' : '' ?></label>

            <?php if ($type === 'textarea'): ?>
                <textarea class="form-control" id="<?= e($name) ?>" name="<?= e($name) ?>" rows="<?= (int)($field['rows'] ?? 3) ?>" placeholder="<?= e($placeholder) ?>"<?= $required ?>><?= e((string)($value ?? '')) ?></textarea>

            <?php elseif ($type === 'select'): ?>
                <select class="form-select" id="<?= e($name) ?>" name="<?= e($name) ?>"<?= $required ?>>
                    <?php if (empty($field['required'])): ?><option value="">— None —</option><?php endif; ?>
                    <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                        <option value="<?= e((string)$optionValue) ?>" <?= (string)$value === (string)$optionValue ? 'selected' : '' ?>><?= e((string)$optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>

            <?php else:
                $inputValue = $type === 'datetime-local'
                    ? teacherCrudDbDateTimeToInput($value === null ? null : (string)$value)
                    : $value;
            ?>
                <input class="form-control" type="<?= e($type) ?>" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e((string)($inputValue ?? '')) ?>" placeholder="<?= e($placeholder) ?>"<?= $required ?><?= $min ?><?= $max ?><?= $step ?>>
            <?php endif; ?>

            <?php if ($help !== ''): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function teacherCrudHandle(PDO $pdo, array $config, int $teacherId, int $schoolId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    verifyCsrf();
    $action = (string)($_POST['crud_action'] ?? '');

    if (!empty($config['custom_handle']) && is_callable($config['custom_handle'])) {
        ($config['custom_handle'])($pdo, $action, $teacherId, $schoolId, $config);
        return;
    }

    $table = (string)$config['table'];
    $pk = (string)($config['pk'] ?? 'id');
    $fields = (array)$config['fields'];

    if ($action === 'create') {
        $data = teacherCrudReadPostData($fields);

        if (!empty($config['create_defaults']) && is_callable($config['create_defaults'])) {
            $data = ($config['create_defaults'])($data, $teacherId, $schoolId);
        }

        if (!empty($config['before_save']) && is_callable($config['before_save'])) {
            $data = ($config['before_save'])($data, false, null, $teacherId, $schoolId);
        }

        $cols = array_keys($data);
        $quoted = array_map(static function (string $name): string {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new InvalidArgumentException('Invalid field.');
            return '`' . $name . '`';
        }, $cols);

        $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
        setFlash('success', ($config['singular'] ?? 'Record') . ' created successfully.');
        redirect((string)$config['page']);
    }

    $id = (int)($_POST[$pk] ?? 0);
    if ($id <= 0) throw new RuntimeException('Invalid record ID.');

    if (empty($config['owns']) || !is_callable($config['owns']) || !($config['owns'])($pdo, $id, $teacherId, $schoolId)) {
        throw new RuntimeException('You do not have access to this record.');
    }

    if ($action === 'update') {
        $data = teacherCrudReadPostData($fields);
        if (!empty($config['before_save']) && is_callable($config['before_save'])) {
            $data = ($config['before_save'])($data, true, $id, $teacherId, $schoolId);
        }

        $set = [];
        foreach (array_keys($data) as $column) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) throw new InvalidArgumentException('Invalid field.');
            $set[] = '`' . $column . '`=?';
        }
        $stmt = $pdo->prepare('UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE `' . $pk . '`=?');
        $stmt->execute(array_merge(array_values($data), [$id]));
        setFlash('success', ($config['singular'] ?? 'Record') . ' updated successfully.');
        redirect((string)$config['page']);
    }

    if ($action === 'delete') {
        try {
            $stmt = $pdo->prepare('DELETE FROM `' . $table . '` WHERE `' . $pk . '`=?');
            $stmt->execute([$id]);
            setFlash('success', ($config['singular'] ?? 'Record') . ' deleted successfully.');
        } catch (PDOException $e) {
            error_log('Teacher CRUD delete failed: ' . $e->getMessage());
            setFlash('danger', 'This record cannot be deleted because other records are linked to it.');
        }
        redirect((string)$config['page']);
    }
}

function teacherCrudFetchEditRow(PDO $pdo, array $config, int $teacherId, int $schoolId): ?array
{
    $id = (int)($_GET['edit'] ?? 0);
    if ($id <= 0) return null;

    if (empty($config['owns']) || !is_callable($config['owns']) || !($config['owns'])($pdo, $id, $teacherId, $schoolId)) {
        return null;
    }

    $pk = (string)($config['pk'] ?? 'id');
    $stmt = $pdo->prepare('SELECT * FROM `' . (string)$config['table'] . '` WHERE `' . $pk . '`=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function teacherCrudPage(PDO $pdo, array $config, int $teacherId, int $schoolId): void
{
    $editRow = teacherCrudFetchEditRow($pdo, $config, $teacherId, $schoolId);
    $isEditing = $editRow !== null;

    portalPageStart('teacher', (string)$config['key'], (string)$config['title']); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">

    <div class="row g-3 mb-4">
        <?php foreach (($config['stats'] ?? []) as $stat):
            $params = array_map(
                static fn($p) => $p === '__TEACHER_ID__' ? $teacherId : ($p === '__SCHOOL_ID__' ? $schoolId : $p),
                (array)($stat['params'] ?? [])
            );
            $value = teacherCrudScalar($pdo, (string)$stat['sql'], $params, 0);
            if (!empty($stat['format']) && is_callable($stat['format'])) $value = ($stat['format'])($value);
            elseif (is_numeric($value)) $value = number_format((float)$value, (float)$value == (int)$value ? 0 : 2);
        ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card h-100">
                <div class="card-body d-flex justify-content-between align-items-center gap-3">
                    <div><div class="small text-secondary"><?= e((string)$stat['label']) ?></div><div class="h3 mb-0 fw-semibold"><?= e((string)$value) ?></div></div>
                    <i class="bi <?= e((string)($stat['icon'] ?? 'bi-bar-chart')) ?> fs-2 text-primary"></i>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card stat-card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 py-3">
            <div>
                <h2 class="h5 mb-1"><?= $isEditing ? 'Edit ' . e((string)$config['singular']) : 'Add ' . e((string)$config['singular']) ?></h2>
                <div class="small text-secondary"><?= e((string)($config['intro'] ?? 'Create and maintain records.')) ?></div>
            </div>
            <?php if ($isEditing): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e((string)$config['page']) ?>"><i class="bi bi-plus-circle me-1"></i>New Record</a><?php endif; ?>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="crud_action" value="<?= $isEditing ? 'update' : 'create' ?>">
                <?php if ($isEditing): ?><input type="hidden" name="<?= e((string)($config['pk'] ?? 'id')) ?>" value="<?= (int)$editRow[$config['pk'] ?? 'id'] ?>"><?php endif; ?>

                <?php foreach ((array)$config['fields'] as $name => $field):
                    $value = $isEditing ? ($editRow[$name] ?? null) : ($field['default'] ?? null);
                    echo teacherCrudRenderField((string)$name, (array)$field, $value);
                endforeach; ?>

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit"><i class="bi <?= $isEditing ? 'bi-save' : 'bi-plus-circle' ?> me-1"></i><?= $isEditing ? 'Save Changes' : 'Create ' . e((string)$config['singular']) ?></button>
                    <?php if ($isEditing): ?><a class="btn btn-outline-secondary" href="<?= e((string)$config['page']) ?>">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card stat-card">
        <div class="card-header bg-white py-3"><h2 class="h5 mb-0"><?= e((string)$config['title']) ?> Records</h2></div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="<?= e((string)$config['key']) ?>Table" class="table table-hover align-middle w-100">
                    <thead><tr>
                        <?php foreach ((array)$config['columns'] as $column): ?><th><?= e((string)$column['label']) ?></th><?php endforeach; ?>
                        <th class="text-end">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $rows = [];
                    try {
                        $stmt = $pdo->prepare((string)$config['list_sql']);
                        $params = array_map(
                            static fn($p) => $p === '__TEACHER_ID__' ? $teacherId : ($p === '__SCHOOL_ID__' ? $schoolId : $p),
                            (array)($config['list_params'] ?? [])
                        );
                        $stmt->execute($params);
                        $rows = $stmt->fetchAll();
                    } catch (Throwable $e) {
                        error_log('Teacher CRUD list query failed: ' . $e->getMessage());
                    }

                    foreach ($rows as $row): ?>
                        <tr>
                        <?php foreach ((array)$config['columns'] as $column):
                            $key = (string)$column['key'];
                            $value = $row[$key] ?? null;
                            if (!empty($column['format']) && is_callable($column['format'])) {
                                $rendered = ($column['format'])($value, $row);
                                $isHtml = !empty($column['html']);
                            } else {
                                $rendered = $value;
                                $isHtml = false;
                            }
                        ?>
                            <td><?= $isHtml ? (string)$rendered : e((string)($rendered ?? '')) ?></td>
                        <?php endforeach; ?>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e((string)$config['page']) ?>?edit=<?= (int)$row[$config['pk'] ?? 'id'] ?>" title="Edit"><i class="bi bi-pencil-square"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this record? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="crud_action" value="delete">
                                    <input type="hidden" name="<?= e((string)($config['pk'] ?? 'id')) ?>" value="<?= (int)$row[$config['pk'] ?? 'id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const table = document.getElementById(<?= json_encode((string)$config['key'] . 'Table') ?>);
        if (table && window.jQuery && jQuery.fn.DataTable) {
            jQuery(table).DataTable({
                pageLength: 10,
                lengthChange: false,
                searching: true,
                ordering: true,
                info: true,
                autoWidth: false,
                order: <?= json_encode($config['order'] ?? [[0,'asc']]) ?>,
                columnDefs: [{orderable:false,searchable:false,targets:-1}],
                language:{search:'Search:',emptyTable:'No records found.'}
            });
        }
    });
    </script>
    <?php portalPageEnd();
}
