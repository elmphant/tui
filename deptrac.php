<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

/*
 * One layer per namespace. A layer may use only the layers listed for it; a
 * namespace with subnamespaces of its own (Rendering, Widget) covers its own
 * files only, and each subnamespace is a layer of its own.
 */

$layer = static fn (string $name, string $pattern): Layer => Layer::withName($name)
    ->collectors(DirectoryConfig::create('src/' . $pattern));

$support = $layer('Support', 'Support/.*');
$text = $layer('Text', 'Text/.*');
$search = $layer('Search', 'Search/.*');
$geometry = $layer('Layout', 'Layout/.*');
$core = $layer('Core', 'Core/.*');
$message = $layer('Message', 'Message/.*');
$terminal = $layer('Terminal', 'Terminal/.*');
$input = $layer('Input', 'Input/.*');
$rendering = $layer('Rendering', 'Rendering/[^/]+[.]php$');
$drawingContext = $layer('Rendering\Context', 'Rendering/Context/.*');
$widget = $layer('Widget', 'Widget/[^/]+[.]php$');
$focus = $layer('Widget\Focus', 'Widget/Focus/.*');
$plan = $layer('Widget\Plan', 'Widget/Plan/.*');
$dispatch = $layer('Widget\Dispatch', 'Widget/Dispatch/.*');
$stackers = $layer('Widget\Layout', 'Widget/Layout/.*');
$prose = $layer('Widget\Prose', 'Widget/Prose/.*');
$feedback = $layer('Widget\Feedback', 'Widget/Feedback/.*');
$bar = $layer('Widget\Bar', 'Widget/Bar/.*');
$field = $layer('Widget\Field', 'Widget/Field/.*');
$popup = $layer('Widget\Popup', 'Widget/Popup/.*');
$tree = $layer('Widget\Tree', 'Widget/Tree/.*');
$choice = $layer('Widget\Choice', 'Widget/Choice/.*');
$listing = $layer('Widget\Listing', 'Widget/Listing/.*');
$table = $layer('Widget\Table', 'Widget/Table/.*');
$structure = $layer('Widget\Structure', 'Widget/Structure/.*');
$filter = $layer('Widget\Filter', 'Widget/Filter/.*');
$runtime = $layer('Runtime', 'Runtime/.*');
$application = $layer('Application', 'Application/.*');
$testing = $layer('Testing', 'Testing/.*');
$stan = $layer('Stan', 'Stan/.*');

$foundation = [$support, $text, $search, $geometry];
$drawing = [...$foundation, $core, $message, $terminal, $input, $rendering, $drawingContext];
$contract = [...$drawing, $widget, $focus, $dispatch];

$rules = [
    [$support],
    [$text, $support],
    [$search, $support],
    [$geometry, $support],
    [$core, $support, $geometry],
    [$message, $support, $geometry, $core],
    [$terminal, $support, $text],
    [$input, $support, $text, $geometry, $core, $message],
    [$rendering, $support, $text, $geometry, $terminal],
    [$drawingContext, $support, $text, $geometry, $terminal, $core, $message, $rendering],
    [$widget, ...$drawing],
    [$focus, ...$drawing, $widget],
    [$plan, ...$drawing, $widget, $focus],
    [$dispatch, ...$drawing, $widget, $focus],
    [$stackers, ...$contract],
    [$prose, ...$contract],
    [$feedback, ...$contract],
    [$bar, ...$contract],
    [$field, ...$contract],
    [$popup, ...$contract],
    [$tree, ...$contract],
    [$choice, ...$contract, $popup],
    [$listing, ...$contract, $bar],
    [$table, ...$contract, $bar],
    [$structure, ...$contract, $bar, $stackers],
    [$filter, ...$contract, $field, $listing, $table, $prose, $choice, $popup],
    [$runtime, ...$drawing],
    [$application, ...$contract, $plan, $runtime],
];

$layers = [
    $support, $text, $search, $geometry, $core, $message, $terminal, $input, $rendering, $drawingContext,
    $widget, $focus, $plan, $dispatch, $stackers, $prose, $feedback, $bar, $field, $popup, $tree, $choice,
    $listing, $table, $structure, $filter, $runtime, $application, $testing, $stan,
];
$rules[] = [$testing, ...array_filter($layers, static fn (Layer $candidate): bool => $candidate !== $testing)];
$rules[] = [$stan, ...array_filter($layers, static fn (Layer $candidate): bool => $candidate !== $stan)];

return static function (DeptracConfig $config) use ($layers, $rules): void {
    $config
        ->paths('src')
        ->cacheFile('var/deptrac.cache')
        ->layers(...$layers)
        ->rulesets(...array_map(
            static fn (array $rule): Ruleset => Ruleset::forLayer(array_shift($rule))->accesses(...$rule),
            $rules,
        ));
};
