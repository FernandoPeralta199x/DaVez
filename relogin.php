<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Security/Bootstrap.php';

davez_install_safe_exception_handler();
davez_require_http_method('POST');

davez_send_error(
    'legacy_relogin_disabled',
    'A recuperação antiga foi desativada. Solicite um código individual ao administrador.',
    410
);
