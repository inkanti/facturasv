<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

// El registro público está deshabilitado: las empresas y sus usuarios los
// administra el superadministrador.
redirect('views/login.php?registro=deshabilitado');
