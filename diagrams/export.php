<?php

declare(strict_types=1);

// GitHub cannot run the container query in a source page, so a README picks the
// variant with <picture media> and each one has to be a file of its own.
// Run after every edit: php diagrams/export.php

$variants = ['default' => '', 'narrow' => '-narrow'];

foreach (glob(__DIR__ . '/*.html') ?: [] as $source) {
    $html = (string)file_get_contents($source);

    foreach ($variants as $class => $suffix) {
        if (preg_match('/<svg class="' . $class . '".*?<\/svg>/s', $html, $match) !== 1) {
            throw new RuntimeException(basename($source) . " has no <svg class=\"{$class}\">");
        }

        // Without width and height GitHub draws the image at a size of its own.
        preg_match('/viewBox="0 0 (\d+) (\d+)"/', $match[0], $size);
        $svg = preg_replace('/^<svg class="' . $class . '"/', sprintf('<svg width="%d" height="%d"', $size[1], $size[2]), $match[0]);

        $target = substr($source, 0, -5) . $suffix . '.svg';
        file_put_contents($target, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n{$svg}\n");
        echo basename($target), "\n";
    }
}
