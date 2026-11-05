<?php
    require_once __DIR__ . '/../includes/functions.php';
    require_login(['admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = post('action');

        if ($action === 'delete_service') {
            $sid = (int)post('service_id');
            $st = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE service_id = ?');
            $st->execute([$sid]);
            if ((int)$st->fetchColumn() > 0) {
                flash('error', 'This service is used by existing appointments and cannot be deleted. Mark it inactive instead (Edit).');
            } else {
                $pdo->prepare('DELETE FROM services WHERE service_id = ?')->execute([$sid]);
                flash('success', 'Service deleted.');
            }
        } elseif ($action === 'add_category') {
            $name = post('category_name');
            $desc = post('description');
            if ($name === '' || mb_strlen($name) > 80) {
                flash('error', 'Please enter a category name (up to 80 characters).');
            } elseif (mb_strlen($desc) > 255) {
                flash('error', 'The description may not be longer than 255 characters.');
            } else {
                try {
                    $pdo->prepare('INSERT INTO service_categories (category_name, description) VALUES (?, ?)')
                        ->execute([$name, $desc === '' ? null : $desc]);
                    flash('success', 'Category added.');
                } catch (PDOException $ex) {
                    flash('error', $ex->getCode() === '23000' ? 'A category with that name already exists.' : 'Could not add the category.');
                }
            }
        } elseif ($action === 'delete_category') {
            $cid = (int)post('category_id');
            $st = $pdo->prepare('SELECT COUNT(*) FROM services WHERE category_id = ?');
            $st->execute([$cid]);
            if ((int)$st->fetchColumn() > 0) {
                flash('error', 'This category still has services. Move or delete them first.');
            } else {
                $pdo->prepare('DELETE FROM service_categories WHERE category_id = ?')->execute([$cid]);
                flash('success', 'Category deleted.');
            }
        }
        redirect('admin/services.php');
    }

    $services = $pdo->query(
        'SELECT s.*, c.category_name FROM services s JOIN service_categories c ON c.category_id = s.category_id
        ORDER BY c.category_name, s.service_name'
    )->fetchAll();
    $categories = $pdo->query(
        'SELECT c.*, (SELECT COUNT(*) FROM services s WHERE s.category_id = c.category_id) AS service_count
        FROM service_categories c ORDER BY c.category_name'
    )->fetchAll();

    $page_title = 'Services';
    require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Services</h1>
    <a class="btn" href="<?= e(url('admin/service_form.php')) ?>">+ Add service</a>
</div>
<div class="table-wrap"><table>
    <thead><tr><th>Service</th><th>Category</th><th>Duration</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($services as $s): ?>
        <tr>
            <td><?= e($s['service_name']) ?></td>
            <td><?= e($s['category_name']) ?></td>
            <td><?= (int)$s['duration_minutes'] ?> min</td>
            <td><?= e(money($s['price'])) ?></td>
            <td><span class="badge <?= $s['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="actions">
                <a class="btn btn-small btn-outline" href="<?= e(url('admin/service_form.php?id=' . (int)$s['service_id'])) ?>">Edit</a>
                <form method="post" class="inline-form" data-confirm="Delete this service?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_service">
                    <input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>">
                    <button class="btn btn-small btn-danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<h2 style="margin-top:2rem">Categories</h2>
<div class="grid grid-3">
    <div class="card">
        <h3>Add a category</h3>
        <form method="post" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_category">
            <div class="form-group"><label for="category_name">Name</label><input type="text" id="category_name" name="category_name" required maxlength="80" data-label="the category name"></div>
            <div class="form-group"><label for="description">Description (optional)</label><input type="text" id="description" name="description" maxlength="255"></div>
            <button class="btn btn-small" type="submit">Add category</button>
        </form>
    </div>
    <div class="card" style="grid-column: span 2">
        <h3>Existing categories</h3>
        <table>
            <thead><tr><th>Category</th><th>Services</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td><?= e($c['category_name']) ?></td>
                    <td><?= (int)$c['service_count'] ?></td>
                    <td class="right">
                        <form method="post" class="inline-form" data-confirm="Delete this category?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                            <button class="btn btn-small btn-danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
