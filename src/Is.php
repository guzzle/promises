<?php

declare(strict_types=1);

(function (): void {
    $original = error_reporting();
    error_reporting($original & ~E_DEPRECATED);

    require __DIR__.'/../is_php86.php';

    error_reporting($original);
})();
