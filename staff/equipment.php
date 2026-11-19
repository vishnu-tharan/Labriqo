<?php

require_once __DIR__ . '/../includes/auth.php';

function findActiveEquipment(PDO $database, int $id, bool $lock = false): array|false
{
    $sql = 'SELECT * FROM equipment WHERE id = ? AND is_active = 1';
    $statement = $database->prepare($lock ? $sql . ' FOR UPDATE' : $sql);
    $statement->execute([$id]);
    return $statement->fetch();
}

function saveEquipment(PDO $database, array $fields, ?int $id): void
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
        return;
    }

    $item = findActiveEquipment($database, $id, true);

    if ($item === false) {
        throw new DomainException('This equipment record is no longer active.');
    }

    // Read the current stock under the lock so an edit preserves units already issued.
    $checkedOut = $item['total_quantity'] - $item['available_quantity'];
    if ($fields['total_quantity'] < $checkedOut) {
        throw new DomainException("Total quantity must cover the {$checkedOut} units already checked out.");
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
}

function removeEquipment(PDO $database, int $id): string
{
    $item = findActiveEquipment($database, $id, true);

    if ($item === false) {
        throw new DomainException('This equipment record is no longer active.');
    }

    $activeRequest = $database->prepare(
        "SELECT id FROM borrow_requests
         WHERE equipment_id = ? AND status IN ('pending', 'borrowed')
         LIMIT 1 FOR UPDATE"
    );
    $activeRequest->execute([$id]);

    if ($activeRequest->fetchColumn() !== false || $item['available_quantity'] < $item['total_quantity']) {
        throw new DomainException('Resolve pending requests and active loans before removing this equipment.');
    }

    $history = $database->prepare('SELECT id FROM borrow_requests WHERE equipment_id = ? LIMIT 1');
    $history->execute([$id]);

    if ($history->fetchColumn() !== false) {
        $database->prepare('UPDATE equipment SET is_active = 0 WHERE id = ?')->execute([$id]);
        return 'Equipment archived. Its borrowing history is still available.';
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
if (!in_array($mode, ['add', 'edit', 'remove', 'categories'], true)) {
    $mode = 'list';
}

$id = null;
$item = false;
if ($mode === 'edit' || $mode === 'remove') {
    $id = filter_var(queryValue('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $item = $id === false ? false : findActiveEquipment($database, $id);

    if ($item === false) {
        http_response_code(404);
        exit('This equipment record is unavailable.');
    }
}

$categoryName = '';
$categoryEditId = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'categories') {
    checkCsrf();
    $categoryName = trim(formValue('category_name'));
    $action = formValue('action');
    $categoryEditId = filter_var(formValue('category_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!validText($categoryName, 80)) {
        $errors[] = 'Enter a category name of up to 80 characters.';
    } elseif (!in_array($action, ['add', 'rename'], true) || ($action === 'rename' && $categoryEditId === false)) {
        $errors[] = 'Choose a valid category action.';
    } else {
        try {
            if ($action === 'add') {
                $database->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$categoryName]);
                $notice = 'Category added.';
            } else {
                $rename = $database->prepare('UPDATE categories SET name = ? WHERE id = ?');
                $rename->execute([$categoryName, $categoryEditId]);

                if ($rename->rowCount() === 0) {
                    $exists = $database->prepare('SELECT id FROM categories WHERE id = ?');
                    $exists->execute([$categoryEditId]);
                    if ($exists->fetchColumn() === false) {
                        throw new DomainException('This category is unavailable.');
                    }
                }
                $notice = 'Category updated.';
            }

            $_SESSION['notice'] = $notice;
            redirect('equipment.php?mode=categories');
        } catch (DomainException $exception) {
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }
            $errors[] = 'That category name is already in use.';
        }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode !== 'categories') {
    if ($mode === 'list') {
        header('Allow: GET');
        http_response_code(405);
        exit('Open the equipment form before submitting changes.');
    }

    checkCsrf();

    if ($mode !== 'remove') {
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
        if ($imageUrl !== '' && (strlen($imageUrl) > 2048
            || filter_var($imageUrl, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($imageUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $errors[] = 'Enter a valid HTTP or HTTPS image URL of up to 2,048 characters.';
        }
    }

    if ($errors === []) {
        $database->beginTransaction();

        try {
            if ($mode === 'remove') {
                $notice = removeEquipment($database, $id);
            } else {
                $equipmentFields = $fields;
                $equipmentFields['category_id'] = $categoryId;
                $equipmentFields['total_quantity'] = $quantity;
                $equipmentFields['image_url'] = $imageUrl === '' ? null : $imageUrl;
                saveEquipment($database, $equipmentFields, $id);
                $notice = $mode === 'add' ? 'Equipment added.' : 'Equipment updated.';
            }

            $database->commit();
            $_SESSION['notice'] = $notice;
            redirect('equipment.php');
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }

            if ($exception instanceof DomainException) {
                $errors[] = $exception->getMessage();
            } elseif ($exception instanceof PDOException && ($exception->errorInfo[1] ?? null) === 1062) {
                $errors[] = 'That equipment code is already in use.';
            } else {
                throw $exception;
            }
        }
    }
}

if ($mode === 'list') {
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
             WHERE (LOCATE(?, e.name) > 0 OR LOCATE(?, e.equipment_code) > 0)
               AND (? IS NULL OR e.category_id = ?)
             ORDER BY e.is_active DESC, e.name, e.equipment_code'
        );
        $pagination = paginate($database, $statement, [$search, $search, $categoryFilter, $categoryFilter], $_SERVER['REQUEST_METHOD'] === 'GET' && queryValue('export') === 'csv');
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
        'location' => 'Storage location',
        'image_url' => 'Image URL',
        'is_under_maintenance' => 'Under maintenance (1=yes, 0=no)',
        'is_active' => 'Active (1=yes, 0=archived)',
    ], $equipment);
}

require __DIR__ . '/../includes/header.php';
?>
<?php if ($mode === 'list'): ?>
    <div class="page-heading page-actions">
        <h1>Equipment inventory</h1>
        <div class="form-actions">
            <a href="equipment.php?mode=categories">Manage categories</a>
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
        <button class="button" type="submit">Search</button>
        <button class="button" type="submit" name="export" value="csv">Download CSV</button>
        <?php if ($search !== '' || $categoryId !== false): ?>
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
                            <td><?= (int) $row['available_quantity'] ?> / <?= (int) $row['total_quantity'] ?></td>
                            <td>
                                <?php if ($row['is_active']): ?>
                                    <div class="row-actions">
                                        <a href="equipment.php?mode=edit&amp;id=<?= (int) $row['id'] ?>"
                                           aria-label="<?= escape('Edit ' . $row['name']) ?>">Edit</a>
                                        <a class="danger-link" href="equipment.php?mode=remove&amp;id=<?= (int) $row['id'] ?>"
                                           aria-label="<?= escape('Remove ' . $row['name']) ?>">Remove</a>
                                    </div>
                                <?php else: ?>
                                    <span class="status status-archived">Archived</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php elseif ($mode === 'categories'): ?>
    <div class="page-heading page-actions">
        <h1>Equipment categories</h1>
        <a href="equipment.php">Back to inventory</a>
    </div>

    <section class="panel form-panel" aria-labelledby="add-category-title">
        <h2 id="add-category-title">Add category</h2>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
            <input type="hidden" name="action" value="add">
            <div class="field">
                <label for="new-category">Category name</label>
                <input id="new-category" name="category_name" maxlength="80"
                       value="<?= $categoryEditId === false ? escape($categoryName) : '' ?>" required>
            </div>
            <button class="button" type="submit">Add category</button>
        </form>
    </section>

    <section class="panel form-panel request-panel" aria-labelledby="rename-category-title">
        <h2 id="rename-category-title">Rename categories</h2>
        <p class="intro">A renamed category updates everywhere its equipment appears.</p>
        <?php if ($categoryNames === []): ?>
            <p>No categories have been added yet.</p>
        <?php endif; ?>
        <?php foreach ($categoryNames as $categoryKey => $existingName): ?>
            <form method="post" class="filter-form">
                <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="category_id" value="<?= (int) $categoryKey ?>">
                <div class="field">
                    <label for="category-<?= (int) $categoryKey ?>">Category name</label>
                    <input id="category-<?= (int) $categoryKey ?>" name="category_name" maxlength="80"
                           value="<?= escape($categoryEditId === (int) $categoryKey ? $categoryName : $existingName) ?>" required>
                </div>
                <button class="button" type="submit">Save name</button>
            </form>
        <?php endforeach; ?>
    </section>
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
                <label for="image-url">Image URL (optional)</label>
                <input id="image-url" name="image_url" type="url" maxlength="2048"
                       value="<?= escape($fields['image_url']) ?>">
            </div>
            <div class="form-actions">
                <button class="button" type="submit"><?= $mode === 'add' ? 'Add equipment' : 'Save changes' ?></button>
                <a href="equipment.php">Cancel</a>
            </div>
        </form>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
