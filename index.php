<?php declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/vendor/autoload.php';

use DeepstakStats\Bot;

const ROOT = __DIR__;
const CACHE_DIR = ROOT . '/cache/';

Dotenv\Dotenv::createImmutable(ROOT)->safeLoad();

(new Bot())->start();