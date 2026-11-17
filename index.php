<?php

require_once __DIR__ . '/includes/auth.php';

$user = currentUser();
redirect($user === null ? 'login.php' : dashboardPath($user));
