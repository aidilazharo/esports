<?php
declare(strict_types=1);

function adminCrudIdentifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('Invalid SQL identifier.');
    }
    return '`' . $name . '`';
}

function adminCrudTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function adminCrudScalar(PDO $pdo, string $sql, array $params = [], int|float|string $fallback = 0): int|float|string
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $fallback : $value;
    } catch (Throwable $e) {
        error_log('Admin CRUD stat query failed: ' . $e->getMessage());
        return $fallback;
    }
}

function adminCrudOptions(PDO $pdo, string $sql, string $valueKey = 'id', string $labelKey = 'label'): array
{
    try {
        $rows = $pdo->query($sql)->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row[$valueKey]] = (string)$row[$labelKey];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('Admin CRUD options query failed: ' . $e->getMessage());
        return [];
    }
}

function adminCrudDbDateTimeToInput(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $ts = strtotime($value);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

function adminCrudInputDateTimeToDb(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $ts = strtotime($value);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

function adminCrudMoney(int|float|string|null $minor, string $currency = 'MYR'): string
{
    $amount = ((float)($minor ?? 0)) / 100;
    return strtoupper($currency) . ' ' . number_format($amount, 2);
}

function adminCrudStatusBadge(string $value, array $map = []): string
{
    $key = strtolower(trim($value));
    $class = $map[$key] ?? match ($key) {
        'active', 'paid', 'completed', 'open', 'approved', 'published' => 'success',
        'draft', 'pending_payment', 'pending_activation', 'applied', 'unpaid' => 'warning',
        'inactive', 'closed', 'cancelled', 'rejected', 'revoked', 'expired' => 'danger',
        default => 'secondary',
    };
    return '<span class="badge text-bg-' . e($class) . '">' . e($value === '' ? '-' : ucwords(str_replace('_', ' ', $value))) . '</span>';
}

function adminCrudNormalizeValue(array $field, mixed $raw): mixed
{
    $type = (string)($field['type'] ?? 'text');

    if ($type === 'checkbox') {
        return $raw ? 1 : 0;
    }

    if (is_string($raw)) {
        $raw = trim($raw);
    }

    if ($raw === '' || $raw === null) {
        if (!empty($field['required'])) {
            return $field['default'] ?? '';
        }
        return null;
    }

    if ($type === 'number') {
        return !empty($field['integer']) ? (int)$raw : (float)$raw;
    }

    if ($type === 'datetime-local') {
        return adminCrudInputDateTimeToDb((string)$raw);
    }

    if ($type === 'date') {
        $ts = strtotime((string)$raw);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    return $raw;
}

function adminCrudReadPostData(array $fields): array
{
    $data = [];
    foreach ($fields as $name => $field) {
        if (!empty($field['readonly_db'])) {
            continue;
        }

        if (($field['type'] ?? '') === 'checkbox') {
            $raw = isset($_POST[$name]) ? 1 : 0;
        } else {
            $raw = $_POST[$name] ?? null;
        }

        $value = adminCrudNormalizeValue($field, $raw);

        if (!empty($field['required']) && ($value === null || $value === '')) {
            throw new RuntimeException(($field['label'] ?? $name) . ' is required.');
        }

        $data[$name] = $value;
    }
    return $data;
}

function adminCrudFixedWhere(array $config): array
{
    $parts = [];
    $params = [];
    foreach (($config['fixed_values'] ?? []) as $column => $value) {
        $parts[] = adminCrudIdentifier((string)$column) . ' = ?';
        $params[] = $value;
    }
    return [$parts, $params];
}

function adminCrudHandle(PDO $pdo, array $config): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    verifyCsrf();

    $table = (string)$config['table'];
    $pk = (string)($config['pk'] ?? 'id');
    $page = (string)$config['page'];
    $fields = (array)$config['fields'];
    $action = (string)($_POST['crud_action'] ?? '');

    if (!adminCrudTableExists($pdo, $table)) {
        throw new RuntimeException('Required table "' . $table . '" does not exist. Run the admin portal SQL upgrade.');
    }

    if ($action === 'create') {
        $data = adminCrudReadPostData($fields);

        foreach (($config['fixed_values'] ?? []) as $column => $value) {
            $data[$column] = $value;
        }

        if (!empty($config['create_defaults']) && is_callable($config['create_defaults'])) {
            $data = ($config['create_defaults'])($data);
        }

        if (!empty($config['before_save']) && is_callable($config['before_save'])) {
            $data = ($config['before_save'])($data, false, null);
        }

        $columns = array_keys($data);
        $sql = 'INSERT INTO ' . adminCrudIdentifier($table)
            . ' (' . implode(', ', array_map('adminCrudIdentifier', $columns)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));

        setFlash('success', ($config['singular'] ?? 'Record') . ' created successfully.');
        redirect($page);
    }

    if ($action === 'update') {
        $id = (int)($_POST[$pk] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid record ID.');
        }

        $data = adminCrudReadPostData($fields);

        foreach (($config['fixed_values'] ?? []) as $column => $value) {
            $data[$column] = $value;
        }

        if (!empty($config['before_save']) && is_callable($config['before_save'])) {
            $data = ($config['before_save'])($data, true, $id);
        }

        $set = [];
        foreach (array_keys($data) as $column) {
            $set[] = adminCrudIdentifier($column) . ' = ?';
        }

        [$fixedParts, $fixedParams] = adminCrudFixedWhere($config);
        $where = [adminCrudIdentifier($pk) . ' = ?'];
        $whereParams = [$id];

        if ($fixedParts) {
            $where = array_merge($where, $fixedParts);
            $whereParams = array_merge($whereParams, $fixedParams);
        }

        $sql = 'UPDATE ' . adminCrudIdentifier($table)
            . ' SET ' . implode(', ', $set)
            . ' WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge(array_values($data), $whereParams));

        setFlash('success', ($config['singular'] ?? 'Record') . ' updated successfully.');
        redirect($page);
    }

    if ($action === 'delete') {
        $id = (int)($_POST[$pk] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid record ID.');
        }

        [$fixedParts, $fixedParams] = adminCrudFixedWhere($config);
        $where = [adminCrudIdentifier($pk) . ' = ?'];
        $params = [$id];

        if ($fixedParts) {
            $where = array_merge($where, $fixedParts);
            $params = array_merge($params, $fixedParams);
        }

        $sql = 'DELETE FROM ' . adminCrudIdentifier($table)
            . ' WHERE ' . implode(' AND ', $where);

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            setFlash('success', ($config['singular'] ?? 'Record') . ' deleted successfully.');
        } catch (PDOException $e) {
            error_log('Admin CRUD delete failed: ' . $e->getMessage());
            setFlash('danger', 'This record cannot be deleted because other records are linked to it.');
        }

        redirect($page);
    }
}

function adminCrudFetchEditRow(PDO $pdo, array $config): ?array
{
    $id = (int)($_GET['edit'] ?? 0);
    if ($id <= 0 || !adminCrudTableExists($pdo, (string)$config['table'])) {
        return null;
    }

    [$fixedParts, $fixedParams] = adminCrudFixedWhere($config);
    $where = [adminCrudIdentifier((string)($config['pk'] ?? 'id')) . ' = ?'];
    $params = [$id];

    if ($fixedParts) {
        $where = array_merge($where, $fixedParts);
        $params = array_merge($params, $fixedParams);
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM ' . adminCrudIdentifier((string)$config['table'])
        . ' WHERE ' . implode(' AND ', $where)
        . ' LIMIT 1'
    );
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function adminCrudRenderField(string $name, array $field, mixed $value): string
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

    ob_start();
    ?>
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
                    <?php if (empty($field['required'])): ?>
                        <option value="">— None —</option>
                    <?php endif; ?>
                    <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                        <option value="<?= e((string)$optionValue) ?>" <?= (string)$value === (string)$optionValue ? 'selected' : '' ?>><?= e((string)$optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>

            <?php else:
                $inputValue = $value;
                if ($type === 'datetime-local') {
                    $inputValue = adminCrudDbDateTimeToInput($value === null ? null : (string)$value);
                }
            ?>
                <input
                    class="form-control"
                    type="<?= e($type) ?>"
                    id="<?= e($name) ?>"
                    name="<?= e($name) ?>"
                    value="<?= e((string)($inputValue ?? '')) ?>"
                    placeholder="<?= e($placeholder) ?>"
                    <?= $required ?><?= $min ?><?= $max ?><?= $step ?>
                >
            <?php endif; ?>

            <?php if ($help !== ''): ?>
                <div class="form-text"><?= e($help) ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function adminCrudPage(PDO $pdo, array $config): void
{
    $table = (string)$config['table'];
    $tableReady = adminCrudTableExists($pdo, $table);
    $editRow = $tableReady ? adminCrudFetchEditRow($pdo, $config) : null;
    $isEditing = $editRow !== null;

    portalPageStart('admin', (string)$config['key'], (string)$config['title']);
    ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">

    <?php if (!$tableReady): ?>
        <div class="alert alert-warning">
            <strong>Database upgrade required.</strong>
            Table <code><?= e($table) ?></code> is not available. Run <code>sql/admin_portal_upgrade.sql</code>.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <?php foreach (($config['stats'] ?? []) as $stat):
            $value = $tableReady
                ? adminCrudScalar($pdo, (string)$stat['sql'], (array)($stat['params'] ?? []), 0)
                : 0;
            if (!empty($stat['format']) && is_callable($stat['format'])) {
                $value = ($stat['format'])($value);
            } else {
                $value = is_numeric($value) ? number_format((float)$value, (float)$value == (int)$value ? 0 : 2) : $value;
            }
        ?>
            <div class="col-sm-6 col-xl-3">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <div class="small text-secondary"><?= e((string)$stat['label']) ?></div>
                            <div class="h3 mb-0 fw-semibold"><?= e((string)$value) ?></div>
                        </div>
                        <div class="fs-2 text-primary"><i class="bi <?= e((string)($stat['icon'] ?? 'bi-bar-chart')) ?>"></i></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card stat-card mb-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
            <div>
                <h2 class="h5 mb-1"><?= $isEditing ? 'Edit ' . e((string)$config['singular']) : 'Add ' . e((string)$config['singular']) ?></h2>
                <div class="small text-secondary"><?= e((string)($config['intro'] ?? 'Create and maintain records.')) ?></div>
            </div>
            <?php if ($isEditing): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e((string)$config['page']) ?>"><i class="bi bi-plus-circle me-1"></i>New Record</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($tableReady): ?>
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="crud_action" value="<?= $isEditing ? 'update' : 'create' ?>">
                    <?php if ($isEditing): ?>
                        <input type="hidden" name="<?= e((string)($config['pk'] ?? 'id')) ?>" value="<?= (int)$editRow[$config['pk'] ?? 'id'] ?>">
                    <?php endif; ?>

                    <?php foreach ((array)$config['fields'] as $name => $field):
                        $value = $isEditing
                            ? ($editRow[$name] ?? null)
                            : ($field['default'] ?? null);
                        echo adminCrudRenderField((string)$name, (array)$field, $value);
                    endforeach; ?>

                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi <?= $isEditing ? 'bi-save' : 'bi-plus-circle' ?> me-1"></i>
                            <?= $isEditing ? 'Save Changes' : 'Create ' . e((string)$config['singular']) ?>
                        </button>
                        <?php if ($isEditing): ?>
                            <a class="btn btn-outline-secondary" href="<?= e((string)$config['page']) ?>">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card stat-card">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0"><?= e((string)$config['title']) ?> Records</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="<?= e((string)$config['key']) ?>Table" class="table table-hover align-middle w-100">
                    <thead>
                        <tr>
                            <?php foreach ((array)$config['columns'] as $column): ?>
                                <th><?= e((string)$column['label']) ?></th>
                            <?php endforeach; ?>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $rows = [];
                    if ($tableReady) {
                        try {
                            $stmt = $pdo->prepare((string)$config['list_sql']);
                            $stmt->execute((array)($config['list_params'] ?? []));
                            $rows = $stmt->fetchAll();
                        } catch (Throwable $e) {
                            error_log('Admin CRUD list query failed: ' . $e->getMessage());
                        }
                    }
                    foreach ($rows as $row):
                    ?>
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
                                <a class="btn btn-sm btn-outline-primary" href="<?= e((string)$config['page']) ?>?edit=<?= (int)$row[$config['pk'] ?? 'id'] ?>" title="Edit">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this <?= e(strtolower((string)$config['singular'])) ?>? This cannot be undone.');">
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
                order: <?= json_encode($config['order'] ?? [[0, 'asc']]) ?>,
                columnDefs: [
                    {orderable: false, searchable: false, targets: -1}
                ],
                language: {
                    search: 'Search:',
                    emptyTable: 'No records found.'
                }
            });
        }
    });
    </script>
    <?php
    portalPageEnd();
}
