<?php if (isset($borrowingSlip)): ?>
    <div class="slip-tools">
        <p><a href="requests.php">Back to borrowing records</a></p>
        <p>Use your browser's Print menu or press Ctrl+P to print this slip.</p>
    </div>
    <article class="panel borrowing-slip" aria-labelledby="slip-title">
        <h1 id="slip-title">Labriqo borrowing slip</h1>
        <p>Request #<?= (int) $borrowingSlip['id'] ?> · <?= escape(ucfirst($borrowingSlip['status'])) ?></p>
        <h2>Student</h2>
        <dl class="details">
            <div><dt>Full name</dt><dd><?= escape($borrowingSlip['student_name']) ?></dd></div>
            <div><dt>Student ID</dt><dd><?= escape($borrowingSlip['student_id']) ?></dd></div>
            <div><dt>Department</dt><dd><?= escape($borrowingSlip['department']) ?></dd></div>
            <div><dt>Email</dt><dd><?= escape($borrowingSlip['email']) ?></dd></div>
        </dl>
        <h2>Equipment and dates</h2>
        <dl class="details">
            <div><dt>Equipment</dt><dd><?= escape($borrowingSlip['equipment_name']) ?></dd></div>
            <div><dt>Equipment code</dt><dd><?= escape($borrowingSlip['equipment_code']) ?></dd></div>
            <div><dt>Quantity issued</dt><dd><?= (int) $borrowingSlip['quantity'] ?></dd></div>
            <div><dt>Issued on</dt><dd><?= escape($borrowingSlip['issue_date'] ?? 'Not recorded') ?></dd></div>
            <div><dt>Return due date</dt><dd><?= escape($borrowingSlip['due_date'] ?? 'Not recorded') ?></dd></div>
            <div><dt>Returned on</dt><dd><?= escape($borrowingSlip['return_date'] ?? 'Awaiting return') ?></dd></div>
        </dl>
        <h2>Borrowing reason</h2>
        <p class="request-reason"><?= escape($borrowingSlip['reason']) ?></p>
    </article>
<?php endif; ?>
<?php if (isset($pagination) && $pagination['pages'] > 1): ?>
    <nav class="pagination" aria-label="List pages">
        <?php if ($pagination['page'] > 1): ?>
            <a class="button" rel="prev"
               href="?<?= escape(http_build_query(array_merge($_GET, ['page' => $pagination['page'] - 1]))) ?>">Previous</a>
        <?php endif; ?>
        <span>Page <?= (int) $pagination['page'] ?> of <?= (int) $pagination['pages'] ?></span>
        <?php if ($pagination['page'] < $pagination['pages']): ?>
            <a class="button" rel="next"
               href="?<?= escape(http_build_query(array_merge($_GET, ['page' => $pagination['page'] + 1]))) ?>">Next</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
<?php if (!empty($showPasswordForm)): ?>
    <section class="panel form-panel request-panel" aria-labelledby="password-title">
        <h2 id="password-title">Change password</h2>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="field">
                <label for="current-password">Current password</label>
                <input id="current-password" name="current_password" type="password"
                       autocomplete="current-password" required>
            </div>
            <div class="field">
                <label for="new-password">New password</label>
                <input id="new-password" name="new_password" type="password"
                       autocomplete="new-password" minlength="8" aria-describedby="new-password-hint" required>
                <small id="new-password-hint">Use at least 8 characters.</small>
            </div>
            <div class="field">
                <label for="confirm-password">Confirm new password</label>
                <input id="confirm-password" name="confirm_password" type="password"
                       autocomplete="new-password" required>
            </div>
            <button class="button" type="submit">Change password</button>
        </form>
    </section>
<?php endif; ?>
</main>
<footer class="site-footer">
    <div class="container">Labriqo · Borrow Smart. Return on Time.</div>
</footer>
</body>
</html>
