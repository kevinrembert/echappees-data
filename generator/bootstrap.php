<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
foreach (['Support', 'Catalog', 'Budget', 'Sources', 'Writer', 'Generator'] as $module) require_once __DIR__ . '/src/' . $module . '.php';
