<?php

declare(strict_types=1);

require_once 'config.php';
require_once __DIR__ . '/programme_helpers.php';
require_once __DIR__ . '/includes/admin_auth.php';
requireAdminAccess('Programme Manager');

if (!programmeSchemaReady($pdo)) {
    $pageTitle = 'Programme Manager';
    require 'includes/header.php';
    ?>
    <div class="alert alert-warning">
        <h1 class="h4">Programme database upgrade required</h1>
        <p class="mb-2">Run <code>upgrade_programmes_certificates.sql</code> once, then reload this page.</p>
        <a class="btn btn-outline-secondary" href="admin.php">Back to Admin</a>
    </div>
    <?php
    require 'includes/footer.php';
    exit;
}

$errors = [];
$editId = (int) ($_GET['edit'] ?? $_POST['programme_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if (in_array($action, ['create', 'update'], true)) {
        $id = (int) ($_POST['programme_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $rules = trim((string) ($_POST['rules_text'] ?? ''));
        $faq = trim((string) ($_POST['faq_text'] ?? ''));
        $registrationFee = programmeAmountToMinor((string) ($_POST['registration_fee_rm'] ?? '0'));
        $kitFee = programmeAmountToMinor((string) ($_POST['kit_fee_rm'] ?? '0'));
        $membershipFee = programmeAmountToMinor((string) ($_POST['membership_fee_rm'] ?? '0'));
        $periodMonths = max(1, min(120, (int) ($_POST['membership_period_months'] ?? 12)));
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'MYR')));
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        $status = (string) ($_POST['status'] ?? 'active');
        $moduleIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['module_ids'] ?? [])))));
        $eventIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['event_ids'] ?? [])))));

        if ($name === '') $errors[] = 'Programme name is required.';
        if (!preg_match('/^[A-Z]{3}$/', $currency)) $errors[] = 'Currency must be a 3-letter code.';
        if (!in_array($status, ['active', 'inactive'], true)) $errors[] = 'Invalid programme status.';

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($isDefault) {
                    $pdo->exec('UPDATE programmes SET is_default = 0');
                }

                if ($action === 'create') {
                    $code = programmeUniqueCode($pdo, $name);
                    $stmt = $pdo->prepare(
                        'INSERT INTO programmes
                         (code,name,description,rules_text,faq_text,registration_fee_minor,kit_fee_minor,membership_fee_minor,membership_period_months,currency,is_default,status)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                    );
                    $stmt->execute([$code,$name,$description ?: null,$rules ?: null,$faq ?: null,$registrationFee,$kitFee,$membershipFee,$periodMonths,$currency,$isDefault,$status]);
                    $id = (int) $pdo->lastInsertId();
                } else {
                    $stmt = $pdo->prepare('SELECT id,is_default FROM programmes WHERE id = ? LIMIT 1');
                    $stmt->execute([$id]);
                    $existing = $stmt->fetch();
                    if (!$existing) throw new RuntimeException('Programme not found.');
                    if ((int) $existing['is_default'] === 1 && !$isDefault) {
                        $otherDefault = (int) $pdo->query('SELECT COUNT(*) FROM programmes WHERE is_default = 1 AND id <> ' . $id)->fetchColumn();
                        if ($otherDefault === 0) {
                            $isDefault = 1;
                        }
                    }
                    $code = programmeUniqueCode($pdo, $name, $id);
                    $stmt = $pdo->prepare(
                        'UPDATE programmes SET code=?,name=?,description=?,rules_text=?,faq_text=?,registration_fee_minor=?,kit_fee_minor=?,membership_fee_minor=?,membership_period_months=?,currency=?,is_default=?,status=? WHERE id=?'
                    );
                    $stmt->execute([$code,$name,$description ?: null,$rules ?: null,$faq ?: null,$registrationFee,$kitFee,$membershipFee,$periodMonths,$currency,$isDefault,$status,$id]);
                }

                $pdo->prepare('DELETE FROM programme_learning_modules WHERE programme_id = ?')->execute([$id]);
                if ($moduleIds) {
                    $ins = $pdo->prepare('INSERT INTO programme_learning_modules (programme_id,module_id,is_required) VALUES (?,?,1)');
                    foreach ($moduleIds as $moduleId) $ins->execute([$id,$moduleId]);
                }

                $pdo->prepare('DELETE FROM programme_event_links WHERE programme_id = ?')->execute([$id]);
                if ($eventIds) {
                    $ins = $pdo->prepare('INSERT INTO programme_event_links (programme_id,event_id) VALUES (?,?)');
                    foreach ($eventIds as $eventId) $ins->execute([$id,$eventId]);
                }

                if ((int) $pdo->query('SELECT COUNT(*) FROM programmes WHERE is_default = 1')->fetchColumn() === 0) {
                    $pdo->prepare('UPDATE programmes SET is_default = 1 WHERE id = ?')->execute([$id]);
                }

                $pdo->commit();
                setFlash('success', $action === 'create' ? 'Programme created.' : 'Programme updated.');
                redirect('programmes.php?edit=' . $id);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Unable to save programme. ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['programme_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT is_default FROM programmes WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            $errors[] = 'Programme not found.';
        } elseif ((int) $row['is_default'] === 1) {
            $errors[] = 'The default programme cannot be deleted. Make another programme default first.';
        } else {
            try {
                $pdo->prepare('DELETE FROM programmes WHERE id = ?')->execute([$id]);
                setFlash('success', 'Programme deleted.');
                redirect('programmes.php');
            } catch (Throwable $e) {
                $errors[] = 'Unable to delete this programme because it may still be used by certificates.';
            }
        }
    }
}

$programmes = programmeAll($pdo);
$modules = $pdo->query('SELECT id,module_code,module_name,week_number FROM learning_modules ORDER BY week_number,sequence_no')->fetchAll();
$events = $pdo->query("SELECT id,event_code,event_type,title,status FROM programme_events ORDER BY start_at DESC,id DESC")->fetchAll();

$edit = null;
$selectedModules = [];
$selectedEvents = [];
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM programmes WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
    if ($edit) {
        $stmt = $pdo->prepare('SELECT module_id FROM programme_learning_modules WHERE programme_id = ?');
        $stmt->execute([$editId]);
        $selectedModules = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt = $pdo->prepare('SELECT event_id FROM programme_event_links WHERE programme_id = ?');
        $stmt->execute([$editId]);
        $selectedEvents = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

$form = $edit ?: [
    'id'=>0,'name'=>'','description'=>'','rules_text'=>'','faq_text'=>'',
    'registration_fee_minor'=>0,'kit_fee_minor'=>0,'membership_fee_minor'=>0,
    'membership_period_months'=>12,'currency'=>'MYR','is_default'=>0,'status'=>'active'
];

$pageTitle = 'Programme Manager';
require 'includes/header.php';
?>
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
    <div>
        <h1 class="h2 mb-1">Programme Manager</h1>
        <p class="text-secondary mb-0">Create multiple programmes with independent rules, FAQ, payment values, learning modules and events.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="certificates.php" class="btn btn-outline-primary"><i class="bi bi-award me-2"></i>Certificates</a>
        <a href="events-competitions-report.php" class="btn btn-outline-secondary">Events Manager</a>
    </div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="row g-4">
    <div class="col-xl-5">
        <div class="card content-card">
            <div class="card-header bg-light border-0 p-4"><h2 class="h5 mb-0"><?= $edit ? 'Edit Programme' : 'Create Programme' ?></h2></div>
            <div class="card-body p-4">
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
                    <input type="hidden" name="programme_id" value="<?= (int) $form['id'] ?>">
                    <div class="col-12"><label class="form-label required">Programme Name</label><input class="form-control" name="name" value="<?= e($form['name']) ?>" required></div>
                    <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?= e($form['description']) ?></textarea></div>
                    <div class="col-12"><label class="form-label">Rules</label><textarea class="form-control" name="rules_text" rows="5" placeholder="Programme-specific rules..."><?= e($form['rules_text']) ?></textarea></div>
                    <div class="col-12"><label class="form-label">FAQ</label><textarea class="form-control" name="faq_text" rows="5" placeholder="Programme-specific FAQ..."><?= e($form['faq_text']) ?></textarea></div>

                    <div class="col-6 col-md-3"><label class="form-label">Registration (RM)</label><input type="number" min="0" step="0.01" class="form-control" name="registration_fee_rm" value="<?= e(number_format(((int)$form['registration_fee_minor'])/100,2,'.','')) ?>"></div>
                    <div class="col-6 col-md-3"><label class="form-label">Kit (RM)</label><input type="number" min="0" step="0.01" class="form-control" name="kit_fee_rm" value="<?= e(number_format(((int)$form['kit_fee_minor'])/100,2,'.','')) ?>"></div>
                    <div class="col-6 col-md-3"><label class="form-label">Membership (RM)</label><input type="number" min="0" step="0.01" class="form-control" name="membership_fee_rm" value="<?= e(number_format(((int)$form['membership_fee_minor'])/100,2,'.','')) ?>"></div>
                    <div class="col-6 col-md-3"><label class="form-label">Months</label><input type="number" min="1" max="120" class="form-control" name="membership_period_months" value="<?= (int)$form['membership_period_months'] ?>"></div>
                    <div class="col-md-4"><label class="form-label">Currency</label><input class="form-control text-uppercase" maxlength="3" name="currency" value="<?= e($form['currency']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><option value="active" <?= $form['status']==='active'?'selected':'' ?>>Active</option><option value="inactive" <?= $form['status']==='inactive'?'selected':'' ?>>Inactive</option></select></div>
                    <div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_default" id="isDefault" <?= (int)$form['is_default']===1?'checked':'' ?>><label class="form-check-label" for="isDefault">Default programme</label></div></div>

                    <div class="col-12"><hr><label class="form-label fw-semibold">Learning Modules</label><div class="border rounded p-3" style="max-height:260px;overflow:auto">
                        <?php foreach ($modules as $module): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="module_ids[]" value="<?= (int)$module['id'] ?>" id="m<?= (int)$module['id'] ?>" <?= in_array((int)$module['id'],$selectedModules,true)?'checked':'' ?>><label class="form-check-label" for="m<?= (int)$module['id'] ?>"><code><?= e($module['module_code']) ?></code> <?= e($module['module_name']) ?></label></div><?php endforeach; ?>
                    </div></div>

                    <div class="col-12"><label class="form-label fw-semibold">Events / Competitions</label><div class="border rounded p-3" style="max-height:260px;overflow:auto">
                        <?php if (!$events): ?><div class="text-secondary small">No events available.</div><?php endif; ?>
                        <?php foreach ($events as $event): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="event_ids[]" value="<?= (int)$event['id'] ?>" id="e<?= (int)$event['id'] ?>" <?= in_array((int)$event['id'],$selectedEvents,true)?'checked':'' ?>><label class="form-check-label" for="e<?= (int)$event['id'] ?>"><span class="badge text-bg-<?= $event['event_type']==='tournament'?'warning':'primary' ?>"><?= e($event['event_type']==='tournament'?'Competition':'Event') ?></span> <?= e($event['title']) ?></label></div><?php endforeach; ?>
                    </div></div>

                    <div class="col-12 d-flex gap-2"><button class="btn btn-primary flex-grow-1"><i class="bi bi-save me-2"></i><?= $edit?'Update':'Create' ?> Programme</button><?php if ($edit): ?><a href="programmes.php" class="btn btn-outline-secondary">New</a><?php endif; ?></div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card content-card">
            <div class="card-header bg-light border-0 p-4"><h2 class="h5 mb-0">Programmes</h2></div>
            <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Programme</th><th>Payments</th><th>Bindings</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
                <?php foreach ($programmes as $p):
                    $mc=$pdo->prepare('SELECT COUNT(*) FROM programme_learning_modules WHERE programme_id=?');$mc->execute([(int)$p['id']]);
                    $ec=$pdo->prepare('SELECT COUNT(*) FROM programme_event_links WHERE programme_id=?');$ec->execute([(int)$p['id']]);
                ?>
                <tr><td><div class="fw-semibold"><?= e($p['name']) ?></div><code><?= e($p['code']) ?></code><?php if ((int)$p['is_default']===1): ?> <span class="badge text-bg-success">Default</span><?php endif; ?></td>
                <td class="small">Reg <?= e(programmeMoney((int)$p['registration_fee_minor'])) ?><br>Kit <?= e(programmeMoney((int)$p['kit_fee_minor'])) ?><br>Membership <?= e(programmeMoney((int)$p['membership_fee_minor'])) ?>/<?= (int)$p['membership_period_months'] ?> mo</td>
                <td><span class="badge text-bg-light border text-dark"><?= (int)$mc->fetchColumn() ?> modules</span> <span class="badge text-bg-light border text-dark"><?= (int)$ec->fetchColumn() ?> events</span></td>
                <td><span class="badge text-bg-<?= $p['status']==='active'?'success':'secondary' ?>"><?= e(ucfirst($p['status'])) ?></span></td>
                <td class="text-end"><div class="btn-group btn-group-sm"><a class="btn btn-outline-success" target="_blank" href="programme.php?id=<?= (int)$p['id'] ?>">View</a><a class="btn btn-outline-primary" href="programmes.php?edit=<?= (int)$p['id'] ?>">Edit</a><?php if ((int)$p['is_default']!==1): ?><form method="post" onsubmit="return confirm('Delete this programme?')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="programme_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-outline-danger">Delete</button></form><?php endif; ?></div></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
