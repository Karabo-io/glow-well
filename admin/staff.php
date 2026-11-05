<?php
    require_once __DIR__ . '/../includes/functions.php';
    require_login(['admin']);
    
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
        csrf_check();
        $sid = (int)post('staff_id');
        $st = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE staff_id = ?');
        $st->execute([$sid]);
        if ((int)$st->fetchColumn() > 0) {
            flash('error', 'This staff member has appointments on record and cannot be deleted. Deactivate the account instead (Edit).');
        } else {
            $pdo->prepare('DELETE FROM users WHERE user_id = (SELECT user_id FROM staff WHERE staff_id = ?)')->execute([$sid]);
            flash('success', 'Staff member deleted.');
        }
        redirect('admin/staff.php');
    }

    $staff = $pdo->query(
        "SELECT s.staff_id, s.first_name, s.last_name, s.job_title, s.phone, u.email, u.is_active,
                (SELECT COUNT(*) FROM staff_services ss WHERE ss.staff_id = s.staff_id) AS service_count,
                (SELECT COUNT(*) FROM staff_schedules sc WHERE sc.staff_id = s.staff_id) AS days_worked
        FROM staff s JOIN users u ON u.user_id = s.user_id
        ORDER BY s.first_name, s.last_name"
    )->fetchAll();

    $page_title = 'Staff';
    require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Staff</h1>
    <a class="btn" href="<?= e(url('admin/staff_form.php')) ?>">+ Add staff member</a>
</div>
<div class="table-wrap"><table>
    <thead><tr><th>Name</th><th>Role</th><th>Email</th><th>Phone</th><th>Services</th><th>Days / week</th><th>Account</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$staff): ?><tr><td colspan="8" class="muted">No staff members yet.</td></tr><?php endif; ?>
    <?php foreach ($staff as $s): ?>
        <tr>
            <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
            <td><?= e($s['job_title']) ?></td>
            <td><?= e($s['email']) ?></td>
            <td><?= e($s['phone']) ?></td>
            <td><?= (int)$s['service_count'] ?></td>
            <td><?= (int)$s['days_worked'] ?></td>
            <td><span class="badge <?= $s['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="actions">
                <a class="btn btn-small btn-outline" href="<?= e(url('admin/staff_form.php?id=' . (int)$s['staff_id'])) ?>">Edit / schedule</a>
                <form method="post" class="inline-form" data-confirm="Delete this staff member?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="staff_id" value="<?= (int)$s['staff_id'] ?>">
                    <button class="btn btn-small btn-danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
