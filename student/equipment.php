<?php

require_once __DIR__ . '/../includes/auth.php';

$user = requireRole('student');
$basePath = '../';
$pageTitle = 'Equipment';
$database = db();

$detailView = isset($_GET['id']);
$item = false;
$errors = [];

if ($detailView) {
    $id = filter_var(queryValue('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($id !== false) {
        $statement = $database->prepare(
            'SELECT e.*, c.name AS category_name
             FROM equipment e JOIN categories c ON e.category_id = c.id
             WHERE e.id = ? AND e.is_active = 1'
        );
        $statement->execute([$id]);
        $item = $statement->fetch();
    }

    if ($item === false) {
        http_response_code(404);
    }
} else {
    $categories = $database->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
    $search = trim(queryValue('q'));
    $categoryId = filter_var(queryValue('category'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $equipment = [];

    if ($search !== '' && !validText($search, 120)) {
        $errors[] = 'Search using up to 120 characters.';
    } else {
        $categoryFilter = $categoryId === false ? null : $categoryId;
        $statement = $database->prepare(
            'SELECT e.*, c.name AS category_name
             FROM equipment e JOIN categories c ON e.category_id = c.id
             WHERE e.is_active = 1
               AND (LOCATE(?, e.name) > 0 OR LOCATE(?, e.equipment_code) > 0)
               AND (? IS NULL OR e.category_id = ?)
             ORDER BY e.name, e.equipment_code'
        );
        $statement->execute([$search, $search, $categoryFilter, $categoryFilter]);
        $equipment = $statement->fetchAll();
    }
}

require __DIR__ . '/../includes/header.php';
?>
<?php if ($detailView): ?>
    <p class="back-link"><a href="equipment.php">Back to equipment</a></p>

    <?php if ($item === false): ?>
        <section class="panel">
            <h1>Equipment unavailable</h1>
            <p>This equipment is no longer in the catalog or the link is invalid.</p>
        </section>
    <?php else: ?>
        <article class="panel equipment-detail">
            <?php if ($item['image_url']): ?>
                <img class="equipment-image" src="<?= escape($item['image_url']) ?>"
                     alt="<?= escape($item['name']) ?>" referrerpolicy="no-referrer">
            <?php else: ?>
                <div class="equipment-image image-placeholder">No image available</div>
            <?php endif; ?>
            <div>
                <p class="item-meta"><?= escape($item['category_name']) ?> · <?= escape($item['equipment_code']) ?></p>
                <h1><?= escape($item['name']) ?></h1>
                <p class="equipment-description"><?= escape($item['description']) ?></p>
                <dl class="details">
                    <div>
                        <dt>Available quantity</dt>
                        <dd><?= (int) $item['available_quantity'] ?> of <?= (int) $item['total_quantity'] ?></dd>
                    </div>
                    <div>
                        <dt>Storage location</dt>
                        <dd><?= escape($item['location']) ?></dd>
                    </div>
                </dl>
                <?php if ($item['available_quantity'] > 0): ?>
                    <span class="status status-available">Available</span>
                <?php else: ?>
                    <span class="status status-unavailable">Unavailable</span>
                <?php endif; ?>
            </div>
        </article>
    <?php endif; ?>
<?php else: ?>
    <div class="page-heading">
        <h1>Equipment catalog</h1>
        <p class="intro">Find laboratory equipment and check what is available.</p>
    </div>

    <form method="get" class="panel filter-form">
        <div class="field">
            <label for="search">Search by name or code</label>
            <input id="search" name="q" type="search" maxlength="120" value="<?= escape($search) ?>">
        </div>
        <div class="field">
            <label for="category">Category</label>
            <select id="category" name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>>
                        <?= escape($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="button" type="submit">Search</button>
        <?php if ($search !== '' || $categoryId !== false): ?>
            <a class="filter-reset" href="equipment.php">Clear filters</a>
        <?php endif; ?>
    </form>

    <?php if ($errors === [] && $equipment === []): ?>
        <p class="panel">No equipment matches your search.</p>
    <?php endif; ?>

    <div class="equipment-grid">
        <?php foreach ($equipment as $item): ?>
            <article class="panel equipment-card">
                <?php if ($item['image_url']): ?>
                    <img class="equipment-image" src="<?= escape($item['image_url']) ?>"
                         alt="<?= escape($item['name']) ?>" loading="lazy" referrerpolicy="no-referrer">
                <?php else: ?>
                    <div class="equipment-image image-placeholder">No image available</div>
                <?php endif; ?>
                <div class="equipment-card-body">
                    <p class="item-meta"><?= escape($item['category_name']) ?> · <?= escape($item['equipment_code']) ?></p>
                    <h2><a href="equipment.php?id=<?= (int) $item['id'] ?>"><?= escape($item['name']) ?></a></h2>
                    <p class="equipment-summary"><?= escape($item['description']) ?></p>
                    <div class="item-availability">
                        <?php if ($item['available_quantity'] > 0): ?>
                            <span class="status status-available">Available</span>
                        <?php else: ?>
                            <span class="status status-unavailable">Unavailable</span>
                        <?php endif; ?>
                        <span><?= (int) $item['available_quantity'] ?> of <?= (int) $item['total_quantity'] ?> units</span>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
