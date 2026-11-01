<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';
$bootstrap = getenv('WIZHIPPO_TEST_BOOTSTRAP');
if ($bootstrap !== false && $bootstrap !== '') {
    require $bootstrap;
}
