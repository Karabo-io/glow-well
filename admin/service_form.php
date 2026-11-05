<?php
    require_once __DIR__ . '/../includes/functions.php';
    require_login(['admin']);
    
    $pdo = db();
    $id = (int)($_GET['id'] ?? $_POST['service_id'] ?? 0);
    $editing = $id > 0;
    $errors = [];
    $form = ['category_id' => '', 'service_name' => '', 'description' => '', 'duration_minutes' => '60', 'price' => '', 'is_active' => '1'];

    if ($editing) {
        $st = $pdo->prepare('SELECT * FROM services WHERE service_id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            flash('error', 'That service does not exist.');
            redirect('admin/services.php');
        }
        foreach ($form as $k => $_) { $form[$k] = (string)($row[$k] ?? ''); }
    }

    $categories = $pdo->query('SELECT category_id, category_name FROM service_categories ORDER BY category_name')->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        foreach (['category_id', 'service_name', 'description', 'duration_minutes', 'price'] as $k) { $form[$k] = post($k); }
        $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

        $validCats = array_map('strval', array_column($categories, 'category_id'));
        if (!in_array($form['category_id'], $validCats, true)) { $errors[] = 'Please choose a category.'; }
        if ($form['service_name'] === '' || mb_strlen($form['service_name']) > 100) { $errors[] = 'Please enter a service name (up to 100 characters).'; }
        if (mb_strlen($form['description']) > 255) { $errors[] = 'The description may not be longer than 255 characters.'; }
        if (!ctype_digit($form['duration_minutes']) || (int)$form['duration_minutes'] < 15 || (int)$form['duration_minutes'] > 480) {
            $errors[] = 'Duration must be a whole number of minutes between 15 and 480.';
        }
        if (!is_numeric($form['price']) || (float)$form['price'] < 0 || (float)$form['price'] > 99999) {
            $errors[] = 'Price must be a number between 0 and 99999.';
        }

        if (!$errors) {
            try {
                $desc = $form['description'] === '' ? null : $form['description'];
                if ($editing) {
                    $pdo->prepare('UPDATE services SET category_id = ?, service_name = ?, description = ?, duration_minutes = ?, price = ?, is_active = ? WHERE service_id = ?')
                        ->execute([(int)$form['category_id'], $form['service_name'], $desc, (int)$form['duration_minutes'], round((float)$form['price'], 2), (int)$form['is_active'], $id]);
                } else {
                    $pdo->prepare('INSERT INTO services (category_id, service_name, description, duration_minutes, price, is_active) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([(int)$form['category_id'], $form['service_name'], $desc, (int)$form['duration_minutes'], round((float)$form['price'], 2), (int)$form['is_active']]);
                }
                flash('success', $editing ? 'Service updated.' : 'Service added.');
                redirect('admin/services.php');
            } catch (PDOException $ex) {
                $errors[] = $ex->getCode() === '23000' ? 'A service with that name already exists.' : 'Could not save the service. Please try again.';
                error_log('service_form: ' . $ex->getMessage());
            }
        }
    }

    $page_title = $editing ? 'Edit service' : 'Add service';
    require __DIR__ . '/../includes/header.php';
?>
<div class="card form-card">
    <h2><?= $editing ? 'Edit service' : 'Add service' ?></h2>
    <?php if ($errors): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="service_id" value="<?= (int)$id ?>">
        <div class="form-group"><label for="service_name">Service name</label><input type="text" id="service_name" name="service_name" required maxlength="100" value="<?= e($form['service_name']) ?>"></div>
        <div class="form-group"><label for="category_id">Category</label>
            <select id="category_id" name="category_id" required data-label="a category"><option value="">Choose a category</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int)$c['category_id'] ?>" <?= $form['category_id'] === (string)$c['category_id'] ? 'selected' : '' ?>><?= e($c['category_name']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="form-group"><label for="description">Description</label><input type="text" id="description" name="description" maxlength="255" value="<?= e($form['description']) ?>"></div>
        <div class="form-row">
            <div class="form-group"><label for="duration_minutes">Duration (minutes)</label><input type="number" id="duration_minutes" name="duration_minutes" required min="15" step="5" value="<?= e($form['duration_minutes']) ?>"></div>
            <div class="form-group"><label for="price">Price (Rand)</label><input type="number" id="price" name="price" required min="0" step="0.01" value="<?= e($form['price']) ?>"></div>
        </div>
        <div class="form-group checkbox-row"><input type="checkbox" id="is_active" name="is_active" value="1" <?= $form['is_active'] === '1' ? 'checked' : '' ?>><label for="is_active">Available for booking</label></div>
        <div class="btn-row">
            <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Add service' ?></button>
            <a class="btn btn-outline" href="<?= e(url('admin/services.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
