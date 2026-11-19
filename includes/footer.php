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
