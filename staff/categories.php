<?php

require_once __DIR__ . '/../includes/auth.php';

$user = requireRole('staff');
$basePath = '../';
$pageTitle = 'Equipment categories';
$database = db();
$errors = [];

$categoryName = '';
$categoryEditId = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            redirect('categories.php');
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

require __DIR__ . '/../includes/header.php';
?>
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
