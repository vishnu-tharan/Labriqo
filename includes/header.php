<?php
$basePath = $basePath ?? '';
$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape($pageTitle) ?> | Labriqo</title>
    <link rel="stylesheet" href="<?= $basePath ?>assets/css/style.css">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <div class="container header-row">
        <a class="brand" href="<?= $basePath ?>index.php">Labriqo</a>
        <nav aria-label="Main navigation">
            <?php if ($user === null): ?>
                <a href="login.php" <?= $pageTitle === 'Sign in' ? 'aria-current="page"' : '' ?>>Sign in</a>
                <a href="register.php" <?= $pageTitle === 'Student registration' ? 'aria-current="page"' : '' ?>>Register</a>
            <?php else: ?>
                <a href="<?= $basePath . dashboardPath($user) ?>" <?= $pageTitle === 'Dashboard' ? 'aria-current="page"' : '' ?>>Dashboard</a>
                <a href="<?= $basePath . $user['role'] ?>/equipment.php" <?= $pageTitle === 'Equipment' ? 'aria-current="page"' : '' ?>>Equipment</a>
                <?php if ($user['role'] === 'student'): ?>
                    <a href="<?= $basePath ?>student/requests.php" <?= $pageTitle === 'My requests' ? 'aria-current="page"' : '' ?>>My requests</a>
                    <a href="<?= $basePath ?>student/profile.php" <?= $pageTitle === 'My profile' ? 'aria-current="page"' : '' ?>>My profile</a>
                <?php else: ?>
                    <a href="<?= $basePath ?>staff/requests.php" <?= $pageTitle === 'Borrowing requests' ? 'aria-current="page"' : '' ?>>Requests</a>
                    <a href="<?= $basePath ?>staff/returns.php" <?= $pageTitle === 'Returns' ? 'aria-current="page"' : '' ?>>Returns</a>
                <?php endif; ?>
                <form action="<?= $basePath ?>logout.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                    <button class="nav-button" type="submit">Sign out</button>
                </form>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main id="main" class="container">
    <?php if ($notice !== ''): ?>
        <p class="notice" role="status"><?= escape($notice) ?></p>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="notice notice-error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
