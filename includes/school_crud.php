<?php
declare(strict_types=1);

function schoolCrudIdentifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('Invalid SQL identifier.');
    }
    return '`' . $name . '`';
}

function schoolCrudTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function schoolCrudScalar(PDO $pdo, string $sql, array $params = [], int|float|string $fallback = 0): int|float|string
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $fallback : $value;
    } catch (Throwable $e) {
        error_log('School CRUD stat query failed: ' . $e->getMessage());
        return $fallback;
    }
}

function schoolCrudOptions(PDO $pdo, string $sql, array $params = [], string $valueKey = 'id', string $labelKey = 'label'): array
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
        error_log('School CRUD options query failed: ' . $e->getMessage());
        return [];
    }
}

function schoolCrudDbDateTimeToInput(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $ts = strtotime($value);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

function schoolCrudInputDateTimeToDb(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $ts = strtotime($value);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

function schoolCrudNormalizeValue(array $field, mixed $raw): mixed
{
    $type = (string)($field['type'] ?? 'text');

    if ($type === 'checkbox') return $raw ? 1 : 0;
    if (is_string($raw)) $raw = trim($raw);

    if ($raw === '' || $raw === null) {
        if (!empty($field['required'])) return $field['default'] ?? '';
        return null;
    }

    if ($type === 'number') return !empty($field['integer']) ? (int)$raw : (float)$raw;
    if ($type === 'datetime-local') return schoolCrudInputDateTimeToDb((string)$raw);
    if ($type === 'date') {
        $ts = strtotime((string)$raw);
        return $ts === false ? null : date('Y-m-d', $ts);
    }
    return $raw;
}

function schoolCrudReadPostData(array $fields): array
{
    $data = [];
    foreach ($fields as $name => $field) {
        $raw = (($field['type'] ?? '') === 'checkbox')
            ? (isset($_POST[$name]) ? 1 : 0)
            : ($_POST[$name] ?? null);

        $value = schoolCrudNormalizeValue($field, $raw);
        if (!empty($field['required']) && ($value === null || $value === '')) {
            throw new RuntimeException(($field['label'] ?? $name) . ' is required.');
        }
        $data[$name] = $value;
    }
    return $data;
}

function schoolCrudRenderField(string $name, array $field, mixed $value): string
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
                    ? schoolCrudDbDateTimeToInput($value === null ? null : (string)$value)
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

function schoolCrudHandle(PDO $pdo, array $config, int $schoolId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    verifyCsrf();

    $table = (string)$config['table'];
    $pk = (string)($config['pk'] ?? 'id');
    $page = (string)$config['page'];
    $fields = (array)$config['fields'];
    $scopeColumn = (string)($config['scope_column'] ?? 'school_id');
    $action = (string)($_POST['crud_action'] ?? '');

    if (!schoolCrudTableExists($pdo, $table)) {
        throw new RuntimeException('Required table "' . $table . '" does not exist. Run sql/school_portal_upgrade.sql.');
    }

    if ($action === 'create') {
        $data = schoolCrudReadPostData($fields);
        $data[$scopeColumn] = $schoolId;

        foreach (($config['fixed_values'] ?? []) as $col => $value) {
            $data[$col] = $value;
        }

        if (!empty($config['create_defaults']) && is_callable($config['create_defaults'])) {
            $data = ($config['create_defaults'])($data, $schoolId);
        }

        if (!empty($config['before_save']) && is_callable($config['before_save'])) {
            $data = ($config['before_save'])($data, false, null, $schoolId);
        }

        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . schoolCrudIdentifier($table)
            . ' (' . implode(', ', array_map('schoolCrudIdentifier', $cols)) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));

        setFlash('success', ($config['singular'] ?? 'Record') . ' created successfully.');
        redirect($page);
    }

    if ($action === 'update') {
        $id = (int)($_POST[$pk] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid record ID.');

        $ownership = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . schoolCrudIdentifier($table)
            . ' WHERE ' . schoolCrudIdentifier($pk) . ' = ? AND ' . schoolCrudIdentifier($scopeColumn) . ' = ?'
        );
        $ownership->execute([$id, $schoolId]);
        if ((int)$ownership->fetchColumn() !== 1) {
            throw new RuntimeException('You do not have access to this record.');
        }

        $data = schoolCrudReadPostData($fields);
        foreach (($config['fixed_values'] ?? []) as $col => $value) {
            $data[$col] = $value;
        }

        if (!empty($config['before_save']) && is_callable($config['before_save'])) {
            $data = ($config['before_save'])($data, true, $id, $schoolId);
        }

        $set = [];
        foreach (array_keys($data) as $col) $set[] = schoolCrudIdentifier($col) . ' = ?';

        $sql = 'UPDATE ' . schoolCrudIdentifier($table)
            . ' SET ' . implode(', ', $set)
            . ' WHERE ' . schoolCrudIdentifier($pk) . ' = ? AND ' . schoolCrudIdentifier($scopeColumn) . ' = ?';

        $values = array_values($data);
        if (isset($config['reset_verification_on_email_change']) && array_key_exists('email', $data)) {
            // Compare against the old email within the same UPDATE, before assigning email.
            $verificationFields = [(string)$config['reset_verification_on_email_change'], 'email_verification_token_hash', 'email_verification_expires_at'];
            $verificationSet = [];
            foreach ($verificationFields as $field) {
                $column = schoolCrudIdentifier($field);
                $verificationSet[] = $column . ' = CASE WHEN BINARY email <=> BINARY ? THEN ' . $column . ' ELSE NULL END';
            }
            $sql = 'UPDATE ' . schoolCrudIdentifier($table)
                . ' SET ' . implode(', ', array_merge($verificationSet, $set))
                . ' WHERE ' . schoolCrudIdentifier($pk) . ' = ? AND ' . schoolCrudIdentifier($scopeColumn) . ' = ?';
            $values = array_merge(array_fill(0, count($verificationSet), $data['email']), $values);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($values, [$id, $schoolId]));

        setFlash('success', ($config['singular'] ?? 'Record') . ' updated successfully.');
        redirect($page);
    }

    if ($action === 'delete') {
        $id = (int)($_POST[$pk] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid record ID.');

        try {
            $stmt = $pdo->prepare(
                'DELETE FROM ' . schoolCrudIdentifier($table)
                . ' WHERE ' . schoolCrudIdentifier($pk) . ' = ? AND ' . schoolCrudIdentifier($scopeColumn) . ' = ?'
            );
            $stmt->execute([$id, $schoolId]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Record not found or not owned by this school.');
            }
            setFlash('success', ($config['singular'] ?? 'Record') . ' deleted successfully.');
        } catch (PDOException $e) {
            error_log('School CRUD delete failed: ' . $e->getMessage());
            setFlash('danger', 'This record cannot be deleted because other records are linked to it.');
        }
        redirect($page);
    }
}

function schoolCrudFetchEditRow(PDO $pdo, array $config, int $schoolId): ?array
{
    $id = (int)($_GET['edit'] ?? 0);
    if ($id <= 0 || !schoolCrudTableExists($pdo, (string)$config['table'])) return null;

    $pk = (string)($config['pk'] ?? 'id');
    $scopeColumn = (string)($config['scope_column'] ?? 'school_id');
    $stmt = $pdo->prepare(
        'SELECT * FROM ' . schoolCrudIdentifier((string)$config['table'])
        . ' WHERE ' . schoolCrudIdentifier($pk) . ' = ? AND ' . schoolCrudIdentifier($scopeColumn) . ' = ? LIMIT 1'
    );
    $stmt->execute([$id, $schoolId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function schoolCrudPage(PDO $pdo, array $config, int $schoolId): void
{
    $table = (string)$config['table'];
    $tableReady = schoolCrudTableExists($pdo, $table);
    $editRow = $tableReady ? schoolCrudFetchEditRow($pdo, $config, $schoolId) : null;
    $isEditing = $editRow !== null;
    $showForm = empty($config['list_first']) || $isEditing || isset($_GET['add']);

    portalPageStart('school', (string)$config['key'], (string)$config['title']); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">

    <?php if (!$tableReady): ?>
        <div class="alert alert-warning">Records are temporarily unavailable. Please contact support.</div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <?php foreach (($config['stats'] ?? []) as $stat):
            $value = $tableReady
                ? schoolCrudScalar($pdo, (string)$stat['sql'], array_merge((array)($stat['params'] ?? []), array_fill(0, (int)($stat['school_param_count'] ?? 0), $schoolId)), 0)
                : 0;
            if (!empty($stat['format']) && is_callable($stat['format'])) $value = ($stat['format'])($value);
            elseif (is_numeric($value)) $value = number_format((float)$value, (float)$value == (int)$value ? 0 : 2);
        ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card h-100"><div class="card-body d-flex justify-content-between align-items-center gap-3">
                <div><div class="small text-secondary"><?= e((string)$stat['label']) ?></div><div class="h3 mb-0 fw-semibold"><?= e((string)$value) ?></div></div>
                <i class="bi <?= e((string)($stat['icon'] ?? 'bi-bar-chart')) ?> fs-2 text-primary"></i>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($showForm): ?>
    <div class="card stat-card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 py-3">
            <div><h2 class="h5 mb-1"><?= $isEditing ? 'Edit ' . e((string)$config['singular']) : 'Add ' . e((string)$config['singular']) ?></h2>
            <div class="small text-secondary"><?= e((string)($config['intro'] ?? 'Create and maintain school records.')) ?></div></div>
            <?php if ($isEditing): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e((string)$config['page']) ?>"><i class="bi bi-plus-circle me-1"></i>New Record</a><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($tableReady): ?>
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="crud_action" value="<?= $isEditing ? 'update' : 'create' ?>">
                <?php if ($isEditing): ?><input type="hidden" name="<?= e((string)($config['pk'] ?? 'id')) ?>" value="<?= (int)$editRow[$config['pk'] ?? 'id'] ?>"><?php endif; ?>

                <?php foreach ((array)$config['fields'] as $name => $field):
                    $value = $isEditing ? ($editRow[$name] ?? null) : ($field['default'] ?? null);
                    echo schoolCrudRenderField((string)$name, (array)$field, $value);
                endforeach; ?>

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit"><i class="bi <?= $isEditing ? 'bi-save' : 'bi-plus-circle' ?> me-1"></i><?= $isEditing ? 'Save Changes' : 'Create ' . e((string)$config['singular']) ?></button>
                    <?php if ($isEditing || !empty($config['list_first'])): ?><a class="btn btn-outline-secondary" href="<?= e((string)$config['page']) ?>">Cancel</a><?php endif; ?>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
    <div class="card stat-card">
        <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between gap-2"><h2 class="h5 mb-0"><?= e((string)$config['title']) ?> Records</h2>
            <?php if (!$showForm && $tableReady): ?><a class="btn btn-primary btn-sm" data-mobile-icon-links="off" href="<?= e((string)$config['page']) ?>?add=1"><i class="bi bi-plus-circle" aria-hidden="true"></i> Add <?=e((string)$config['singular'])?></a><?php endif; ?>
        </div>
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
                    if ($tableReady) {
                        try {
                            $stmt = $pdo->prepare((string)$config['list_sql']);
                            $params = (array)($config['list_params'] ?? []);
                            $params = array_map(static fn($p) => $p === '__SCHOOL_ID__' ? $schoolId : $p, $params);
                            $stmt->execute($params);
                            $rows = $stmt->fetchAll();
                        } catch (Throwable $e) {
                            error_log('School CRUD list query failed: ' . $e->getMessage());
                        }
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
