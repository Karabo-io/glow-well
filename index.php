<?php
    require_once __DIR__ . '/includes/functions.php';
    
    $pdo = db();

    $rows = $pdo->query(
        "SELECT s.service_id, s.service_name, s.description, s.duration_minutes, s.price, c.category_name
        FROM services s
        JOIN service_categories c ON  c.category_id = s.category_id
        WHERE s.is_active = 1
        ORDER BY c.category_name, s.service_name"
    )->fetchAll();

    $grouped = [];
    foreach($rows as $r) {
        $grouped[$r['category_name']][] = $r;
    }

    $page_title = "Welcome";
    require __DIR__ . '/includes/hearder.php';
?>
<section class="hero">
    <h1>Relax. Refresh. Glow</h1>
    <p>Book facials, massasges, hair treatments and wellness sessions online in under a minute.</p>
    <?php if (($_SESSION['role'] ?? "") === 'customer'): ?>
        <a class="btn" href="<?= e(url('book.php')) ?>">Book an appointment</a>
    <?php elseif(!is_logged_in()): ?>
        <a class="btn" href="<? e(url('register.php')) ?>">Create an account to book</a>
        <a class="btn btn-outline" href="<? e(url('login.php'))?>">Login</a>
    <?php else: ?>
        <a class="btn" href="<? e(url(home_for_role())) ?>">Go to my dashboard</a>
    <?php endif; ?>
<section>

<h2 id="services">Our services</h2>
<?php if(!$grouped): ?>
    <p class="muted">No services are available at the moment. Please check back soon.</p>
<?php endif; ?>
<?php foreach($grouped as $category => $services): ?>
    <h3 class="category-title"><?= e($category)?></h3>
    <div class="grid grid-3">
        <?php foreach($services as $s): ?>
            <article class="card service-card">
                <div>
                    <h3><?= e($s['service_name']) ?></h3>
                    <p class="muted"><?= e($s['description'])?></p>
                </div>
                <div class="service-meta">
                    <span class="price"><?= e(money($s['price'])) ?></span>
                    <span class="muted"><?= (int)$s['duration_minutes'] ?>min</span>
                </div>
                <?php if(($_SESSION['role'] ?? "") === 'customer'): ?>
                    <div class="btn-row"><a class="btn btn-small" href="<? e(url('book.php?service_id=' . (int)$s['service_id'])) ?>">Book this</a></div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php' ?>