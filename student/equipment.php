<?php

require_once __DIR__ . '/../includes/auth.php';

$user = requireRole('student');
$basePath = '../';
$pageTitle = 'Equipment';
$database = db();

$detailView = isset($_GET['id']);
$item = false;
$errors = [];
$quantityInput = '1';
$reason = '';
$preferredDate = '';
$currentBorrowedUnits = borrowedUnits($database, $user['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$detailView) {
        header('Allow: GET');
        http_response_code(405);
        exit('Open an equipment item to submit a request.');
    }

    checkCsrf();
}

if ($detailView) {
    $id = filter_var(queryValue('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($id !== false) {
        $statement = $database->prepare(
            'SELECT e.*, c.name AS category_name, CURRENT_DATE() AS today
             FROM equipment e JOIN categories c ON e.category_id = c.id
             WHERE e.id = ? AND e.is_active = 1'
        );
        $statement->execute([$id]);
        $item = $statement->fetch();
    }

    if ($item === false) {
        http_response_code(404);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $joinWaitlist = formValue('action') === 'waitlist';
        $quantityInput = trim(formValue('quantity'));
        $reason = trim(formValue('reason'));
        $preferredDate = formValue('preferred_date');
        $quantity = filter_var($quantityInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($quantity === false) {
            $errors[] = 'Enter a whole quantity of at least 1.';
        }
        if (!validText($reason, 1000)) {
            $errors[] = 'Enter a borrowing reason of up to 1,000 characters.';
        }
        if (!validDate($preferredDate) || $preferredDate < $item['today']) {
            $errors[] = 'Choose a preferred borrowing date of today or later.';
        }

        if ($errors === []) {
            $database->beginTransaction();

            try {
                // Lock the same equipment row used by staff edits and removal.
                $stock = $database->prepare(
                    'SELECT total_quantity, available_quantity, is_under_maintenance, CURRENT_DATE() AS today
                     FROM equipment WHERE id = ? AND is_active = 1 FOR UPDATE'
                );
                $stock->execute([$id]);
                $current = $stock->fetch();

                if ($current === false) {
                    $errors[] = 'This equipment is no longer in the catalog.';
                    $item = false;
                    http_response_code(404);
                } else {
                    $item['total_quantity'] = $current['total_quantity'];
                    $item['available_quantity'] = $current['available_quantity'];
                    $item['today'] = $current['today'];

                    $item['is_under_maintenance'] = $current['is_under_maintenance'];
                    $queued = $database->prepare(
                        "SELECT COUNT(*) AS waiting, COUNT(CASE WHEN user_id = ? THEN 1 END) AS already_waiting
                         FROM borrow_requests WHERE equipment_id = ? AND status = 'waitlisted'"
                    );
                    $queued->execute([$user['id'], $id]);
                    $waitlist = $queued->fetch();

                    if ($current['is_under_maintenance']) {
                        $errors[] = 'This equipment is under maintenance.';
                    } elseif ($quantity + borrowedUnits($database, $user['id']) > MAX_BORROWED_UNITS) {
                        $errors[] = 'This request exceeds the student borrowing limit of ' . MAX_BORROWED_UNITS . ' units.';
                    } elseif ($joinWaitlist && $quantity > $current['total_quantity']) {
                        $errors[] = 'The requested quantity exceeds the total stock.';
                    } elseif ($joinWaitlist && $waitlist['already_waiting'] > 0) {
                        $errors[] = 'You already have a request on the waitlist for this equipment.';
                    } elseif ($joinWaitlist && $waitlist['waiting'] == 0 && $quantity <= $current['available_quantity']) {
                        $errors[] = 'This quantity is available. Submit a regular request.';
                    } elseif (!$joinWaitlist && ($quantity > $current['available_quantity'] || $waitlist['waiting'] > 0)) {
                        $errors[] = 'The quantity is unavailable for a regular request. You can join the waitlist.';
                    } elseif ($preferredDate < $current['today']) {
                        $errors[] = 'Choose a preferred borrowing date of today or later.';
                    }
                }

                if ($errors === []) {
                    $request = $database->prepare(
                        'INSERT INTO borrow_requests (user_id, equipment_id, quantity, reason, preferred_date, status)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $request->execute([$user['id'], $id, $quantity, $reason, $preferredDate, $joinWaitlist ? 'waitlisted' : 'pending']);
                    $database->commit();

                    $_SESSION['notice'] = $joinWaitlist ? 'You joined the waitlist.' : 'Request submitted. It is pending staff review.';
                    redirect('requests.php');
                }

                $database->rollBack();
            } catch (Throwable $exception) {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                throw $exception;
            }
        }
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
        $pagination = paginate($database, $statement, [$search, $search, $categoryFilter, $categoryFilter]);
        $equipment = $pagination['records'];
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
                <?php if ($item['is_under_maintenance']): ?>
                    <span class="status status-maintenance">Under maintenance</span>
                <?php elseif ($item['available_quantity'] > 0): ?>
                    <span class="status status-available">Available</span>
                <?php else: ?>
                    <span class="status status-unavailable">Unavailable</span>
                <?php endif; ?>
            </div>
        </article>

        <?php if (!$item['is_under_maintenance'] && $item['total_quantity'] > 0): ?>
            <section class="panel request-panel" aria-labelledby="request-title">
                <h2 id="request-title">Request equipment</h2>
                <p>You have <?= (int) $currentBorrowedUnits ?> of your <?= MAX_BORROWED_UNITS ?> units borrowed or reserved.</p>
                <form method="post" class="form-stack">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">

                    <div class="form-grid">
                        <div class="field">
                            <label for="quantity">Quantity</label>
                            <input id="quantity" name="quantity" type="number" min="1"
                                   max="<?= (int) $item['total_quantity'] ?>"
                                   value="<?= escape($quantityInput) ?>" required>
                        </div>
                        <div class="field">
                            <label for="preferred-date">Preferred borrowing date</label>
                            <input id="preferred-date" name="preferred_date" type="date"
                                   min="<?= escape($item['today']) ?>"
                                   value="<?= escape($preferredDate) ?>" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="reason">Borrowing reason</label>
                        <textarea id="reason" name="reason" rows="3" maxlength="1000" required><?= escape($reason) ?></textarea>
                    </div>
                    <div class="form-actions">
                        <button class="button" type="submit">Submit request</button>
                        <button class="button" type="submit" name="action" value="waitlist">Join waitlist</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
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
                        <?php if ($item['is_under_maintenance']): ?>
                            <span class="status status-maintenance">Under maintenance</span>
                        <?php elseif ($item['available_quantity'] > 0): ?>
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
