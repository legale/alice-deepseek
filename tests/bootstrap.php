<?php

require_once __DIR__ . '/../vendor/autoload.php';

$_ENV['OPENROUTER_API_KEY'] = 'test_key';
$_ENV['MODEL_ID'] = 'test/model';
$_ENV['GOOGLE_API_KEY'] = 'test_google_key';
$_ENV['GOOGLE_CX'] = 'test_cx';

if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
        require_once __DIR__ . '/../vendor/phpunit/phpunit/src/Framework/TestCase.php';
}

