<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

redirect(currentUser() === null ? 'views/login.php' : 'views/dashboard.php');
