<?php
require dirname(__DIR__).'/app/bootstrap.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
App\Services\Migrations::run();
echo "Incremental migrations applied. Existing data preserved.\n";
