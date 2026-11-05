<?php
    require_once __DIR__ . '/../includes/functions.php';
    require_login(['admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
        csrf_check();
        $cid = (int)post('customer_id');
        $st = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE customer_id = ?');
        $st->execute([$cid]);
        if ((int)$st->fetchColumn() > 0) {
            flash('error', 'This customer has appointment history and cannot be deleted. Deactivate the account instead (Edit).');
        } else {
            // deleting the user cascades to the customer row
            $pdo->prepare('DELETE FROM users WHERE user_id = (SELECT user_id FROM customers WHERE customer_id = ?)')->execute([$cid]);
            flash('success', 'Customer deleted.');
        }
        redirect('admin/customers.php');
    }

    $customers = $pdo->query(
        "SELECT c.customer_id, c.first_name, c.last_name, c.phone, u.email, u.is_active,
                (SELECT COUNT(*) FROM appointments a WHERE a.customer_id = c.customer_id) AS bookings
        FROM customers c JOIN users u ON u.user_id = c.user_id
        ORDER BY c.first_name, c.last_name"
    )->fetchAll();

    $page_title = 'Customers';
    require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Customers</h1>
    <a class="btn" href="<?= e(url('admin/customer_form.php')) ?>">+ Add customer</a>
</div>
<div class="form-group"><input type="text" placeholder="Search customers by name, email or phone..." data-filter-table="customerTable" aria-label="Search customers"></div>
<div class="table-wrap"><table id="customerTable">
    <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Bookings</th><th>Account</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$customers): ?><tr><td colspan="6" class="muted">No customers yet.</td></tr><?php endif; ?>
    <?php foreach ($customers as $c): ?>
        <tr>
            <td><?= e($c['first_name'] . ' ' . $c['last_name']) ?></td>
            <td><?= e($c['email']) ?></td>
            <td><?= e($c['phone']) ?></td>
            <td><?= (int)$c['bookings'] ?></td>
            <td><span class="badge <?= $c['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="actions">
                <a class="btn btn-small btn-outline" href="<?= e(url('admin/customer_form.php?id=' . (int)$c['customer_id'])) ?>">Edit</a>
                <form method="post" class="inline-form" data-confirm="Delete this customer?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="customer_id" value="<?= (int)$c['customer_id'] ?>">
                    <button class="btn btn-small btn-danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
