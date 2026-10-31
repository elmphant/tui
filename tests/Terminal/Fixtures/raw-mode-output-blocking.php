<?php

declare(strict_types=1);

use Elmphant\Tui\Terminal\SystemTerminal;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$output = fopen('/dev/tty', 'w');
$terminal = new SystemTerminal(STDIN, $output);

$terminal->enableRawMode();
$inRawMode = stream_get_meta_data($output)['blocked'];
$terminal->disableRawMode();

echo json_encode(['inRawMode' => $inRawMode, 'afterwards' => stream_get_meta_data($output)['blocked']]);
