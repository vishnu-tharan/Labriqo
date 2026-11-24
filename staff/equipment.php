<?php

require_once __DIR__ . '/../includes/auth.php';

function findActiveEquipment(PDO $database, int $id, bool $lock = false): array|false
{
    $sql = 'SELECT * FROM equipment WHERE id = ? AND is_active = 1';
    $statement = $database->prepare($lock ? $sql . ' FOR UPDATE' : $sql);
    $statement->execute([$id]);
    return $statement->fetch();
}

function saveEquipment(PDO $database, array $fields, ?int $id, int $staffId, string $reason): void
{
    $fields['available_quantity'] = $fields['total_quantity'];

    if ($id === null) {
        $statement = $database->prepare(
            'INSERT INTO equipment
                (equipment_code, name, category_id, description, total_quantity, available_quantity, location, image_url, is_under_maintenance)
             VALUES
                (:equipment_code, :name, :category_id, :description, :total_quantity, :available_quantity, :location, :image_url, :is_under_maintenance)'
        );
        $statement->execute($fields);
        if ($fields['total_quantity'] > 0) {
            recordStockAdjustment($database, (int) $database->lastInsertId(), $staffId, 0, $fields['total_quantity'], 'Initial inventory');
        }
        return;
    }

    $item = findActiveEquipment($database, $id, true);

    if ($item === false) {
        throw new DomainException('This equipment record is no longer active.');
    }

    if ($item['tracks_assets'] && $fields['total_quantity'] !== (int) $item['total_quantity']) {
        throw new DomainException('Add or retire tagged assets to change the total quantity.');
    }

    // Read the current stock under the lock so an edit preserves units already issued.
    $checkedOut = $item['total_quantity'] - $item['available_quantity'];
    if ($fields['total_quantity'] < $checkedOut) {
        throw new DomainException("Total quantity must cover the {$checkedOut} units already issued, reserved, or held for repair.");
    }

    $waiting = $database->prepare("SELECT MAX(quantity) FROM borrow_requests WHERE equipment_id = ? AND status = 'waitlisted'");
    $waiting->execute([$id]);
    if ($fields['total_quantity'] < (int) $waiting->fetchColumn()) {
        throw new DomainException('Resolve waitlisted requests for more units before reducing the total stock.');
    }

    $fields['available_quantity'] -= $checkedOut;
    $fields['id'] = $id;
    $statement = $database->prepare(
        'UPDATE equipment
         SET equipment_code = :equipment_code, name = :name, category_id = :category_id,
             description = :description, total_quantity = :total_quantity, available_quantity = :available_quantity,
             location = :location, image_url = :image_url, is_under_maintenance = :is_under_maintenance
         WHERE id = :id'
    );
    $statement->execute($fields);
    if ($fields['total_quantity'] !== (int) $item['total_quantity']) {
        if (!validText($reason, 500)) {
            throw new DomainException('Enter a stock adjustment reason of up to 500 characters.');
        }
        recordStockAdjustment($database, $id, $staffId, (int) $item['total_quantity'], $fields['total_quantity'], $reason);
    }
}

function changeAssets(PDO $database, int $equipmentId, int $staffId, string $action): string
{
    $item = findActiveEquipment($database, $equipmentId, true);
    if ($item === false) {
        throw new DomainException('This equipment is no longer active.');
    }

    $rawTags = trim(formValue('asset_tags'));
    if (!mb_check_encoding($rawTags, 'UTF-8')) {
        throw new DomainException('Enter valid asset tags.');
    }
    $tags = array_values(array_filter(array_map('trim', preg_split('/\R/u', $rawTags) ?: []), static fn ($tag) => $tag !== ''));
    $add = $database->prepare('INSERT INTO equipment_assets (equipment_id, asset_tag) VALUES (?, ?)');

    if ($action === 'enable_assets') {
        if ($item['tracks_assets'] || $item['available_quantity'] !== $item['total_quantity']) {
            throw new DomainException('Start asset tracking when all units are in stock and no pickups are reserved.');
        }
        if (count($tags) !== (int) $item['total_quantity']) {
            throw new DomainException('Enter one distinct asset tag for each unit in stock.');
        }
        foreach ($tags as $tag) {
            if (!validText($tag, 80) || str_contains($tag, ',')) {
                throw new DomainException('Each tag must contain 1 to 80 characters and no commas.');
            }
            $add->execute([$equipmentId, $tag]);
        }
        $database->prepare('UPDATE equipment SET tracks_assets = 1 WHERE id = ?')->execute([$equipmentId]);
        return 'Asset tracking enabled.';
    }

    $reason = trim(formValue('stock_reason'));
    if (!$item['tracks_assets'] || !validText($reason, 500)) {
        throw new DomainException('Enter a reason of up to 500 characters for this stock adjustment.');
    }

    if ($action === 'add_asset') {
        if (count($tags) !== 1 || !validText($tags[0], 80) || str_contains($tags[0], ',')) {
            throw new DomainException('Enter one asset tag of up to 80 characters with no commas.');
        }
        if ((int) $item['total_quantity'] === 2147483647) {
            throw new DomainException('This equipment is already at the maximum quantity.');
        }
        $add->execute([$equipmentId, $tags[0]]);
        $change = 1;
    } elseif ($action === 'retire_asset') {
        $assetId = filter_var(formValue('asset_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($assetId === false || $item['available_quantity'] < 1) {
            throw new DomainException('Only available units can be retired.');
        }
        $waiting = $database->prepare("SELECT MAX(quantity) FROM borrow_requests WHERE equipment_id = ? AND status = 'waitlisted'");
        $waiting->execute([$equipmentId]);
        if ((int) $item['total_quantity'] - 1 < (int) $waiting->fetchColumn()) {
            throw new DomainException('Resolve larger waitlisted requests before retiring this asset.');
        }
        $retire = $database->prepare(
            'UPDATE equipment_assets SET retired_at = CURRENT_TIMESTAMP() WHERE id = ? AND equipment_id = ?
             AND retired_at IS NULL AND current_request_id IS NULL AND is_damaged = 0'
        );
        $retire->execute([$assetId, $equipmentId]);
        if ($retire->rowCount() !== 1) {
            throw new DomainException('This asset is no longer available to retire.');
        }
        $change = -1;
    } else {
        throw new DomainException('Choose a valid asset action.');
    }

    $adjust = $database->prepare(
        'UPDATE equipment SET total_quantity = total_quantity + ?, available_quantity = available_quantity + ? WHERE id = ?'
    );
    $adjust->execute([$change, $change, $equipmentId]);
    recordStockAdjustment($database, $equipmentId, $staffId, (int) $item['total_quantity'], (int) $item['total_quantity'] + $change, $reason);
    return $change > 0 ? 'Asset added.' : 'Asset retired.';
}

function resolveDamage(PDO $database, int $equipmentId, int $staffId): string
{
    $item = findActiveEquipment($database, $equipmentId, true);
    $quantity = filter_var(formValue('quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $action = formValue('action');
    $reason = trim(formValue('stock_reason'));

    if ($item === false || $quantity === false || $quantity > $item['damaged_quantity']
        || !in_array($action, ['repair', 'discard'], true) || !validText($reason, 500)) {
        throw new DomainException('Choose a valid quantity held for repair, an outcome, and a reason of up to 500 characters.');
    }

    $expected = filter_var(formValue('expected_damaged'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($expected !== (int) $item['damaged_quantity']) {
        throw new DomainException('Units held for repair changed since the form was opened. Refresh and try again.');
    }

    $discarding = $action === 'discard';
    if ($item['tracks_assets']) {
        $tags = array_map('trim', explode(',', formValue('asset_tags')));
        if (count($tags) !== $quantity) {
            throw new DomainException('Enter one asset tag per unit.');
        }

        $resolve = $database->prepare(
            'UPDATE equipment_assets SET is_damaged = 0,
             retired_at = CASE WHEN ? THEN CURRENT_TIMESTAMP() ELSE retired_at END
             WHERE equipment_id = ? AND asset_tag = ? AND is_damaged = 1
               AND current_request_id IS NULL AND retired_at IS NULL'
        );
        foreach ($tags as $tag) {
            $resolve->execute([(int) $discarding, $equipmentId, $tag]);
            if ($resolve->rowCount() !== 1) {
                throw new DomainException('Use distinct tags for units held for repair.');
            }
        }
    }

    $adjust = $database->prepare(
        'UPDATE equipment SET damaged_quantity = damaged_quantity - ?,
         available_quantity = available_quantity + ?, total_quantity = total_quantity - ? WHERE id = ?'
    );
    $adjust->execute([$quantity, $discarding ? 0 : $quantity, $discarding ? $quantity : 0, $equipmentId]);
    if ($discarding) {
        $before = (int) $item['total_quantity'];
        recordStockAdjustment($database, $equipmentId, $staffId, $before, $before - $quantity, $reason);
    }

    recordStaffActivity($database, $staffId, $discarding ? 'Damaged units discarded' : 'Units marked repaired', 'Equipment', $equipmentId, "{$quantity} units");
    return $discarding ? 'Damaged units discarded.' : 'Repaired units are available again.';
}

function removeEquipment(PDO $database, int $id): string
{
    $item = findActiveEquipment($database, $id, true);

    if ($item === false) {
        throw new DomainException('This equipment record is no longer active.');
    }

    $activeRequest = $database->prepare(
        "SELECT id FROM borrow_requests
         WHERE equipment_id = ? AND status IN ('pending', 'borrowed', 'waitlisted', 'reserved')
         LIMIT 1 FOR UPDATE"
    );
    $activeRequest->execute([$id]);

    if ($activeRequest->fetchColumn() !== false || $item['available_quantity'] < $item['total_quantity']) {
        throw new DomainException('Resolve pending requests and active loans before removing this equipment.');
    }

    $history = $database->prepare('SELECT id FROM borrow_requests WHERE equipment_id = ? LIMIT 1');
    $history->execute([$id]);

    $adjustments = $database->prepare('SELECT id FROM stock_adjustments WHERE equipment_id = ? LIMIT 1');
    $adjustments->execute([$id]);

    if ($item['tracks_assets'] || $history->fetchColumn() !== false || $adjustments->fetchColumn() !== false) {
        $database->prepare('UPDATE equipment SET is_active = 0 WHERE id = ?')->execute([$id]);
        return 'Equipment archived. Its history is still available.';
    }

    $database->prepare('DELETE FROM equipment WHERE id = ?')->execute([$id]);
    return 'Equipment removed.';
}

$user = requireRole('staff');
$basePath = '../';
$pageTitle = 'Equipment';
$database = db();
$errors = [];

$mode = queryValue('mode');
if ($mode === 'categories') {
    redirect('categories.php');
}
if (!in_array($mode, ['add', 'edit', 'remove', 'stock_history', 'assets', 'damage'], true)) {
    $mode = 'list';
}

$id = null;
$item = false;
if (in_array($mode, ['edit', 'remove', 'assets', 'damage'], true)) {
    $id = filter_var(queryValue('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($mode === 'assets' && $id !== false) {
        $assetEquipment = $database->prepare('SELECT * FROM equipment WHERE id = ?');
        $assetEquipment->execute([$id]);
        $item = $assetEquipment->fetch();
    } else {
        $item = $id === false ? false : findActiveEquipment($database, $id);
    }

    if ($item === false) {
        http_response_code(404);
        exit('This equipment record is unavailable.');
    }
}

$categoryNames = $database->query('SELECT id, name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$fields = [
    'equipment_code' => '',
    'name' => '',
    'category_id' => '',
    'description' => '',
    'total_quantity' => '',
    'location' => '',
    'image_url' => '',
    'is_under_maintenance' => '0',
];

if ($mode === 'edit') {
    foreach ($fields as $field => $value) {
        $fields[$field] = (string) $item[$field];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (in_array($mode, ['list', 'stock_history'], true)) {
        header('Allow: GET');
        http_response_code(405);
        exit('Open the equipment form before submitting changes.');
    }

    checkCsrf();

    if (!in_array($mode, ['remove', 'assets', 'damage'], true)) {
        foreach ($fields as $field => $value) {
            $fields[$field] = trim(formValue($field));
        }

        if ($fields['is_under_maintenance'] === '') {
            $fields['is_under_maintenance'] = '0';
        }
        if (!in_array($fields['is_under_maintenance'], ['0', '1'], true)) {
            $errors[] = 'Choose a valid maintenance status.';
        }

        $categoryId = filter_var($fields['category_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quantity = filter_var(
            $fields['total_quantity'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 2147483647]]
        );

        if (!validText($fields['equipment_code'], 30)) {
            $errors[] = 'Enter an equipment code of up to 30 characters.';
        }
        if (!validText($fields['name'], 120)) {
            $errors[] = 'Enter an equipment name of up to 120 characters.';
        }
        if ($categoryId === false || !isset($categoryNames[$categoryId])) {
            $errors[] = 'Choose an existing category.';
        }
        if (!validText($fields['description'], 2000)) {
            $errors[] = 'Enter a description of up to 2,000 characters.';
        }
        if ($quantity === false) {
            $errors[] = 'Enter a whole total quantity from 0 to 2,147,483,647.';
        }
        if (!validText($fields['location'], 120)) {
            $errors[] = 'Enter a storage location of up to 120 characters.';
        }

        $imageUrl = $fields['image_url'];
        if ($imageUrl !== '' && !safeEquipmentImage($imageUrl)) {
            $errors[] = 'Use a local image path under assets/, such as assets/multimeter.png.';
        }
    }

    if ($errors === []) {
        $database->beginTransaction();

        try {
            if ($mode === 'remove') {
                $notice = removeEquipment($database, $id);
            } elseif ($mode === 'damage') {
                $notice = resolveDamage($database, $id, $user['id']);
            } elseif ($mode === 'assets') {
                $notice = changeAssets($database, $id, $user['id'], formValue('action'));
            } else {
                $equipmentFields = $fields;
                $equipmentFields['category_id'] = $categoryId;
                $equipmentFields['total_quantity'] = $quantity;
                $equipmentFields['image_url'] = $imageUrl === '' ? null : $imageUrl;
                saveEquipment($database, $equipmentFields, $id, $user['id'], trim(formValue('stock_reason')));
                $notice = $mode === 'add' ? 'Equipment added.' : 'Equipment updated.';
            }

            $database->commit();
            $_SESSION['notice'] = $notice;
            redirect(in_array($mode, ['assets', 'damage'], true) ? 'equipment.php?mode=' . $mode . '&id=' . $id : 'equipment.php');
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }

            if ($exception instanceof DomainException) {
                $errors[] = $exception->getMessage();
            } elseif ($exception instanceof PDOException && ($exception->errorInfo[1] ?? null) === 1062) {
                $errors[] = $mode === 'assets' ? 'An asset tag is already in use.' : 'That equipment code is already in use.';
            } else {
                throw $exception;
            }
        }
    }
}

if ($mode === 'list') {
    $search = trim(queryValue('q'));
    $categoryId = filter_var(queryValue('category'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $activeOnly = queryValue('active') === '1';
    $equipment = [];

    if ($search !== '' && !validText($search, 120)) {
        $errors[] = 'Search using up to 120 characters.';
    } else {
        $categoryFilter = $categoryId === false ? null : $categoryId;
        $statement = $database->prepare(
            'SELECT e.*, c.name AS category_name
             FROM equipment e JOIN categories c ON e.category_id = c.id
             WHERE (LOCATE(?, e.name) > 0 OR LOCATE(?, e.equipment_code) > 0)
               AND (? IS NULL OR e.category_id = ?)
               AND (? = 0 OR e.is_active = 1)
             ORDER BY e.is_active DESC, e.name, e.equipment_code'
        );
        $pagination = paginate($database, $statement, [$search, $search, $categoryFilter, $categoryFilter, (int) $activeOnly], $_SERVER['REQUEST_METHOD'] === 'GET' && queryValue('export') === 'csv');
        $equipment = $pagination['records'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $mode === 'list'
    && queryValue('export') === 'csv' && $errors === []) {
    downloadCsv('labriqo-inventory.csv', [
        'id' => 'Equipment ID',
        'equipment_code' => 'Equipment code',
        'name' => 'Equipment name',
        'category_name' => 'Category',
        'description' => 'Description',
        'total_quantity' => 'Total quantity',
        'available_quantity' => 'Available quantity',
        'damaged_quantity' => 'Held for repair',
        'location' => 'Storage location',
        'image_url' => 'Image URL',
        'is_under_maintenance' => 'Under maintenance (1=yes, 0=no)',
        'is_active' => 'Active (1=yes, 0=archived)',
    ], $equipment);
}

if ($mode === 'assets') {
    $statement = $database->prepare(
        'SELECT id, asset_tag, current_request_id, retired_at, is_damaged FROM equipment_assets
         WHERE equipment_id = ? ORDER BY retired_at IS NOT NULL, asset_tag'
    );
    $pagination = paginate($database, $statement, [$id]);
    $assets = $pagination['records'];
    $selectedAsset = filter_var(queryValue('asset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $assetHistory = [];
    if ($selectedAsset !== false) {
        $history = $database->prepare(
            'SELECT r.id, r.issue_date, r.return_date, r.issue_condition, r.return_condition, a.asset_tag
             FROM loan_assets x JOIN equipment_assets a ON a.id = x.asset_id
             JOIN borrow_requests r ON r.id = x.request_id
             WHERE a.id = ? AND a.equipment_id = ? ORDER BY r.id DESC'
        );
        $history->execute([$selectedAsset, $id]);
        $assetHistory = $history->fetchAll();
    }
}

if ($mode === 'stock_history') {
    $statement = $database->prepare(
        'SELECT a.id, a.previous_total, a.new_total, a.reason, a.adjusted_at, e.name AS equipment_name, u.name AS staff_name
         FROM stock_adjustments a JOIN equipment e ON e.id = a.equipment_id JOIN users u ON u.id = a.staff_id
         ORDER BY a.id DESC'
    );
    $pagination = paginate($database, $statement, []);
    $adjustments = $pagination['records'];
}

require __DIR__ . '/../includes/header.php';
?>
<?php if ($mode === 'list'): ?>
    <div class="page-heading page-actions">
        <h1>Equipment inventory</h1>
        <div class="form-actions">
            <a href="categories.php">Manage categories</a>
            <a href="equipment.php?mode=stock_history">Stock history</a>
            <a class="button" href="equipment.php?mode=add">Add equipment</a>
        </div>
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
                <?php foreach ($categoryNames as $categoryKey => $categoryName): ?>
                    <option value="<?= (int) $categoryKey ?>" <?= $categoryId === (int) $categoryKey ? 'selected' : '' ?>>
                        <?= escape($categoryName) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <label class="filter-checkbox">
            <input type="checkbox" name="active" value="1" <?= $activeOnly ? 'checked' : '' ?>>
            Active only
        </label>
        <button class="button" type="submit">Search</button>
        <button class="button" type="submit" name="export" value="csv">Download CSV</button>
        <?php if ($search !== '' || $categoryId !== false || $activeOnly): ?>
            <a class="filter-reset" href="equipment.php">Clear filters</a>
        <?php endif; ?>
    </form>

    <?php if ($errors === [] && $equipment === []): ?>
        <p class="panel">No equipment matches your search.</p>
    <?php elseif ($equipment !== []): ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Equipment inventory">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Equipment</th>
                        <th scope="col">Category</th>
                        <th scope="col">Location</th>
                        <th scope="col">Available / total</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($equipment as $row): ?>
                        <tr>
                            <th scope="row">
                                <?= escape($row['name']) ?>
                                <small class="table-note"><?= escape($row['equipment_code']) ?></small>
                                <?php if ($row['is_under_maintenance']): ?>
                                    <span class="status status-maintenance">Under maintenance</span>
                                <?php endif; ?>
                            </th>
                            <td><?= escape($row['category_name']) ?></td>
                            <td><?= escape($row['location']) ?></td>
                            <td>
                                <?= (int) $row['available_quantity'] ?> / <?= (int) $row['total_quantity'] ?>
                                <?php if ($row['damaged_quantity'] > 0): ?>
                                    <small class="table-note">
                                        <a href="equipment.php?mode=damage&amp;id=<?= (int) $row['id'] ?>"><?= (int) $row['damaged_quantity'] ?> held for inspection or repair</a>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['is_active']): ?>
                                    <div class="row-actions">
                                        <a href="equipment.php?mode=edit&amp;id=<?= (int) $row['id'] ?>"
                                           aria-label="<?= escape('Edit ' . $row['name']) ?>">Edit</a>
                                        <a href="equipment.php?mode=assets&amp;id=<?= (int) $row['id'] ?>">Assets</a>
                                        <a class="danger-link" href="equipment.php?mode=remove&amp;id=<?= (int) $row['id'] ?>"
                                           aria-label="<?= escape('Remove ' . $row['name']) ?>">Remove</a>
                                    </div>
                                <?php else: ?>
                                    <span class="status status-archived">Archived</span>
                                    <?php if ($row['tracks_assets']): ?>
                                        <a href="equipment.php?mode=assets&amp;id=<?= (int) $row['id'] ?>">Asset history</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php elseif ($mode === 'damage'): ?>
    <div class="page-heading page-actions">
        <h1>Units held for inspection or repair</h1>
        <a href="equipment.php">Back to inventory</a>
    </div>
    <section class="panel form-panel" aria-labelledby="damage-heading">
        <h2 id="damage-heading"><?= escape($item['name']) ?></h2>
        <p><?= (int) $item['damaged_quantity'] ?> units on hold</p>
        <?php if ($item['damaged_quantity'] > 0): ?>
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                <input type="hidden" name="expected_damaged" value="<?= (int) $item['damaged_quantity'] ?>">
                <div class="field">
                    <label for="damage-quantity">Units to resolve</label>
                    <input id="damage-quantity" name="quantity" type="number" min="1" max="<?= (int) $item['damaged_quantity'] ?>" required>
                </div>
                <?php if ($item['tracks_assets']): ?>
                    <div class="field">
                        <a href="equipment.php?mode=assets&amp;id=<?= (int) $id ?>">View asset tags and statuses</a>
                        <label for="damage-tags">Asset tags, separated by commas</label>
                        <input id="damage-tags" name="asset_tags" required>
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label for="damage-reason">Reason</label>
                    <input id="damage-reason" name="stock_reason" maxlength="500" required>
                </div>
                <div class="form-actions">
                    <button class="button" type="submit" name="action" value="repair">Clear hold and restock</button>
                    <button class="button button-danger" type="submit" name="action" value="discard">Discard damaged units</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php elseif ($mode === 'assets'): ?>
    <div class="page-heading page-actions">
        <h1><?= escape($item['name']) ?> assets</h1>
        <a href="equipment.php">Back to inventory</a>
    </div>
    <?php if ($item['is_active']): ?>
        <form method="post" class="panel form-panel form-stack">
            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
            <input type="hidden" name="action" value="<?= $item['tracks_assets'] ? 'add_asset' : 'enable_assets' ?>">
            <div class="field">
                <label for="asset-tags"><?= $item['tracks_assets'] ? 'New asset tag' : 'Asset tags (one per line)' ?></label>
                <textarea id="asset-tags" name="asset_tags" rows="3"><?= escape(formValue('asset_tags')) ?></textarea>
                <?php if (!$item['tracks_assets']): ?>
                    <small>When all units are in stock, enter one tag for each of the <?= (int) $item['total_quantity'] ?> units.</small>
                <?php endif; ?>
            </div>
            <?php if ($item['tracks_assets']): ?>
                <div class="field">
                    <label for="asset-stock-reason">Reason for adding this asset</label>
                    <input id="asset-stock-reason" name="stock_reason" maxlength="500" required>
                </div>
            <?php endif; ?>
            <button class="button" type="submit"><?= $item['tracks_assets'] ? 'Add asset' : 'Enable asset tracking' ?></button>
        </form>
    <?php endif; ?>
    <?php if ($assets !== []): ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Tagged equipment units">
            <table>
                <thead><tr><th>Asset tag</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($assets as $asset): ?>
                        <tr>
                            <th scope="row"><?= escape($asset['asset_tag']) ?></th>
                            <td><?= $asset['retired_at'] !== null ? 'Retired' : ($asset['current_request_id'] !== null ? 'Issued' : ($asset['is_damaged'] ? 'Held for repair' : 'In storage')) ?></td>
                            <td>
                                <a href="equipment.php?mode=assets&amp;id=<?= (int) $id ?>&amp;asset=<?= (int) $asset['id'] ?>#asset-loans">Loan history</a>
                                <?php if ($item['is_active'] && $asset['retired_at'] === null && $asset['current_request_id'] === null && !$asset['is_damaged'] && $item['available_quantity'] > 0): ?>
                                    <form method="post" class="loan-action">
                                        <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="retire_asset">
                                        <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
                                        <label for="retire-reason-<?= (int) $asset['id'] ?>">Reason for retirement</label>
                                        <input id="retire-reason-<?= (int) $asset['id'] ?>" name="stock_reason" maxlength="500" required>
                                        <button class="button button-danger" type="submit">Retire asset</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($selectedAsset !== false): ?>
        <section id="asset-loans" class="panel">
            <h2>Asset loan history</h2>
            <?php if ($assetHistory === []): ?>
                <p>No loans are recorded for this asset.</p>
            <?php else: ?>
                <?php foreach ($assetHistory as $loan): ?>
                    <p><?= escape($loan['asset_tag']) ?> ·
                        <a href="requests.php?slip=<?= (int) $loan['id'] ?>">Borrowing slip #<?= (int) $loan['id'] ?></a> ·
                        Issued <?= escape($loan['issue_date'] ?? 'Not recorded') ?> ·
                        Returned <?= escape($loan['return_date'] ?? 'Awaiting return') ?>
                    </p>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php elseif ($mode === 'stock_history'): ?>
    <div class="page-heading page-actions">
        <h1>Stock adjustment history</h1>
        <a href="equipment.php">Back to inventory</a>
    </div>
    <?php if ($adjustments === []): ?>
        <p class="panel">No stock adjustments have been recorded.</p>
    <?php else: ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Stock adjustment history">
            <table>
                <thead><tr><th>Equipment</th><th>Previous total</th><th>New total</th><th>Reason</th><th>Staff</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ($adjustments as $entry): ?>
                        <tr>
                            <th scope="row"><?= escape($entry['equipment_name']) ?></th>
                            <td><?= (int) $entry['previous_total'] ?></td>
                            <td><?= (int) $entry['new_total'] ?></td>
                            <td><?= escape($entry['reason']) ?></td>
                            <td><?= escape($entry['staff_name']) ?></td>
                            <td><?= escape($entry['adjusted_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php elseif ($mode === 'remove'): ?>
    <section class="panel form-panel" aria-labelledby="page-title">
        <h1 id="page-title">Remove equipment</h1>
        <p>Remove <strong><?= escape($item['name']) ?></strong> (<?= escape($item['equipment_code']) ?>) from the catalog?</p>
        <p class="intro">Equipment with borrowing history will be archived so past records stay accessible.</p>
        <form method="post" class="form-actions">
            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
            <button class="button button-danger" type="submit">Confirm removal</button>
            <a href="equipment.php">Cancel</a>
        </form>
    </section>
<?php else: ?>
    <section class="panel form-panel" aria-labelledby="page-title">
        <h1 id="page-title"><?= $mode === 'add' ? 'Add equipment' : 'Edit equipment' ?></h1>
        <p class="intro">Set the total number of units. Available stock accounts for any units already checked out.</p>

        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="equipment-code">Equipment code</label>
                    <input id="equipment-code" name="equipment_code" maxlength="30"
                           value="<?= escape($fields['equipment_code']) ?>" required>
                </div>
                <div class="field">
                    <label for="name">Equipment name</label>
                    <input id="name" name="name" maxlength="120" value="<?= escape($fields['name']) ?>" required>
                </div>
                <div class="field">
                    <label for="category">Category</label>
                    <select id="category" name="category_id" required>
                        <option value="">Choose a category</option>
                        <?php foreach ($categoryNames as $categoryKey => $categoryName): ?>
                            <option value="<?= (int) $categoryKey ?>" <?= $fields['category_id'] === (string) $categoryKey ? 'selected' : '' ?>>
                                <?= escape($categoryName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="total-quantity">Total quantity</label>
                    <input id="total-quantity" name="total_quantity" type="number" min="0" max="2147483647" step="1"
                           value="<?= escape($fields['total_quantity']) ?>" required>
                    <?php if ($mode === 'edit'): ?>
                        <small><?= (int) $item['available_quantity'] ?> units currently available</small>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($mode === 'edit'): ?>
                <div class="field">
                    <label for="stock-reason">Reason for changing total stock</label>
                    <textarea id="stock-reason" name="stock_reason" rows="2" maxlength="500"><?= escape(formValue('stock_reason')) ?></textarea>
                    <small>Required when the total quantity changes.</small>
                </div>
            <?php endif; ?>
            <div class="field">
                <label for="location">Storage location</label>
                <input id="location" name="location" maxlength="120" value="<?= escape($fields['location']) ?>" required>
            </div>
            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4" maxlength="2000" required><?= escape($fields['description']) ?></textarea>
            </div>
            <div class="field">
                <label for="maintenance">Equipment status</label>
                <select id="maintenance" name="is_under_maintenance" required>
                    <option value="0" <?= $fields['is_under_maintenance'] === '0' ? 'selected' : '' ?>>Ready for lending</option>
                    <option value="1" <?= $fields['is_under_maintenance'] === '1' ? 'selected' : '' ?>>Under maintenance</option>
                </select>
                <small>Maintenance pauses new requests and issues for this equipment type.</small>
            </div>
            <div class="field">
                <label for="image-url">Local image path (optional)</label>
                <input id="image-url" name="image_url" maxlength="255"
                       value="<?= safeEquipmentImage($fields['image_url']) ? escape($fields['image_url']) : '' ?>">
                <small>Choose an image already stored under assets/, such as assets/multimeter.png.</small>
            </div>
            <div class="form-actions">
                <button class="button" type="submit"><?= $mode === 'add' ? 'Add equipment' : 'Save changes' ?></button>
                <a href="equipment.php">Cancel</a>
            </div>
        </form>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
