<?php

declare(strict_types=1);

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Separator;
use Elmphant\Tui\Widget\Widget;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

final readonly class InlineRuledE2eApp extends App
{
    public function view(): Widget
    {
        return Layout::vertical(
            Separator::new(),
            Paragraph::fromText('Ruled lines run the full width'),
            Separator::new(),
            Paragraph::fromText('Press Enter when you are done'),
            Separator::new(),
        );
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return $message->key === Key::Enter ? new UpdateResult($this, Cmd::quit()) : UpdateResult::ignored($this);
    }
}

echo "Ruled heading\n";

Program::new(new InlineRuledE2eApp())->withInline()->start();

echo "Ruled done\n";
