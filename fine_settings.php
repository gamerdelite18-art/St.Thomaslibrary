<?php
/**
 * fine_settings.php - Admin Settings -> Fine Settings
 * Configure the daily rate, on/off switch, grace period and maximum cap.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$errors = [];
$cfg    = fine_settings();

$form = [
    'per_day'    => $cfg['per_day'],
    'enabled'    => $cfg['enabled'],
    'grace_days' => $cfg['grace_days'],
    'max_amount' => $cfg['max_amount'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $form['per_day']    = float_param($_POST['per_day'] ?? 0);
    $form['enabled']    = isset($_POST['enabled']);
    $form['grace_days'] = int_param($_POST['grace_days'] ?? 0);
    $form['max_amount'] = float_param($_POST['max_amount'] ?? 0);

    // ---- Validation ----
    if ($form['per_day'] < 0) {
        $errors['per_day'] = 'The daily fine cannot be negative.';
    } elseif ($form['per_day'] > 1000) {
        $errors['per_day'] = 'That looks too high - keep the rate below 1000.';
    }

    if ($form['grace_days'] < 0) {
        $errors['grace_days'] = 'The grace period cannot be negative.';
    } elseif ($form['grace_days'] > 90) {
        $errors['grace_days'] = 'Use a grace period of 90 days or less.';
    }

    if ($form['max_amount'] < 0) {
        $errors['max_amount'] = 'The maximum fine cannot be negative.';
    }

    if (!$errors) {
        save_fine_settings($form['per_day'], $form['enabled'], $form['grace_days'], $form['max_amount']);
        flash('success', 'Fine settings saved.');
        redirect('fine_settings.php');
    }
}

// Live example using the numbers currently typed in the form
$example_due     = '2026-10-10';
$example_preview = calculate_fine($example_due, '2026-10-14');

$current_page  = 'fine_settings.php';
$page_title    = 'Fine Settings';
$page_subtitle = 'Admin settings - late return fine configuration';
$page_actions  = '<a href="fines.php" class="btn btn-outline-secondary btn-sm">'
               . '<i class="bi bi-arrow-left me-1"></i> Back to Fines</a>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3">
    <!-- ===================== SETTINGS FORM ===================== -->
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-gear me-2"></i>Late Return Fine Configuration</div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>

                    <!-- Fines on/off -->
                    <div class="form-check form-switch mb-4 p-3 border rounded bg-light">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="enabled" name="enabled" value="1"
                               <?= $form['enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enabled">
                            <strong>Charge late return fines</strong>
                            <div class="small text-muted">
                                When off, no new fine is charged. Fines already assessed are kept as they are.
                            </div>
                        </label>
                    </div>

                    <!-- Fine per day -->
                    <div class="mb-3">
                        <label class="form-label" for="per_day">Fine per day <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" step="0.50" min="0"
                                   class="form-control <?= isset($errors['per_day']) ? 'is-invalid' : '' ?>"
                                   id="per_day" name="per_day" value="<?= e($form['per_day']) ?>" required>
                            <span class="input-group-text">per overdue day</span>
                            <div class="invalid-feedback"><?= e($errors['per_day'] ?? '') ?></div>
                        </div>
                        <div class="form-text">The amount charged for each day a book is late.</div>
                    </div>

                    <!-- Grace period -->
                    <div class="mb-3">
                        <label class="form-label" for="grace_days">Grace period (days)</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-hourglass"></i></span>
                            <input type="number" step="1" min="0"
                                   class="form-control <?= isset($errors['grace_days']) ? 'is-invalid' : '' ?>"
                                   id="grace_days" name="grace_days" value="<?= e($form['grace_days']) ?>">
                            <span class="input-group-text">free days</span>
                            <div class="invalid-feedback"><?= e($errors['grace_days'] ?? '') ?></div>
                        </div>
                        <div class="form-text">
                            Days after the due date that are not charged. Leave at 0 to charge from the very next day.
                        </div>
                    </div>

                    <!-- Maximum fine -->
                    <div class="mb-4">
                        <label class="form-label" for="max_amount">Maximum fine limit</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" step="0.50" min="0"
                                   class="form-control <?= isset($errors['max_amount']) ? 'is-invalid' : '' ?>"
                                   id="max_amount" name="max_amount" value="<?= e($form['max_amount']) ?>">
                            <span class="input-group-text">cap</span>
                            <div class="invalid-feedback"><?= e($errors['max_amount'] ?? '') ?></div>
                        </div>
                        <div class="form-text">
                            Leave at <strong>0</strong> for no limit. Any other number caps the total fine per book.
                        </div>
                    </div>

                    <hr>
                    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end">
                        <a href="fine_settings.php" class="btn btn-outline-secondary">Reset</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===================== SIDE PANEL ===================== -->
    <div class="col-12 col-lg-5">
        <!-- Live worked example -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-calculator me-2"></i>How the Fine Works</div>
            <div class="card-body">
                <div class="alert alert-light border mb-3">
                    <div class="small text-muted mb-1">Example from the specification</div>
                    <table class="table table-sm mb-0 small">
                        <tr><td>Due date</td><td class="text-end fw-semibold"><?= fmt_date($example_due) ?></td></tr>
                        <tr><td>Returned on</td><td class="text-end fw-semibold"><?= fmt_date('2026-10-14') ?></td></tr>
                        <tr><td>Overdue days</td><td class="text-end fw-semibold"><?= $example_preview['overdue_days'] ?></td></tr>
                        <tr class="table-light">
                            <td class="fw-bold">Fine</td>
                            <td class="text-end fw-bold text-danger">
                                <?= rupees($example_preview['fine']) ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="small text-muted mb-2">The rules being applied right now:</div>
                <ul class="small mb-0">
                    <li>Fines are <strong><?= $cfg['enabled'] ? 'ON' : 'OFF' ?></strong>.</li>
                    <li><strong><?= rupees($cfg['per_day']) ?></strong> per overdue day.</li>
                    <li>Grace period: <strong><?= $cfg['grace_days'] ?> day(s)</strong><?= $cfg['grace_days'] > 0 ? ' before charges start' : ' (charges start the day after the due date)' ?>.</li>
                    <li>Maximum: <strong><?= $cfg['max_amount'] > 0 ? rupees($cfg['max_amount']) : 'no limit' ?></strong>.</li>
                </ul>
            </div>
        </div>

        <!-- Current position -->
        <?php $sum = fines_summary(); ?>
        <div class="card">
            <div class="card-header"><i class="bi bi-graph-up me-2"></i>Current Fine Position</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between">
                    <span>Total assessed</span>
                    <strong><?= rupees($sum['assessed']) ?></strong>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-success">Collected</span>
                    <strong class="text-success"><?= rupees($sum['collected']) ?></strong>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-danger">Pending</span>
                    <strong class="text-danger"><?= rupees($sum['pending']) ?></strong>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span>Books overdue now</span>
                    <strong><?= $sum['overdue'] ?></strong>
                </li>
            </ul>
            <div class="card-footer bg-white small text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Changing the rate affects fines calculated from now on. Fines already
                charged on returned books stay exactly as they were.
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
