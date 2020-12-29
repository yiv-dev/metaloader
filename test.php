<?php
use YIVDEV\METALOADER\Handler;
require 'src/Handler.php';

$testHandler = new Handler('https://nr8.newradio.it:19574/stream');
$content = $testHandler->read_stream();
print_r("----------------\n");
print_r($content);


print_r("\n");

