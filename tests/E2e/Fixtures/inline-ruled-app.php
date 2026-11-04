<?php

declare(strict_types=1);

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ScreenMode;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Separator;
use Elmphant\Tui\Widget\Widget;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

final readonly class InlineRuledE2eApp extends App
{
    public function view(): Widget
    {
        return Layout::column(
            Separator::new(),
            Paragraph::fromText('Ruled lines run the full width'),
            Separator::new(),
            Paragraph::fromText('Press Enter when you are done'),
            Separator::new(),
        );
    }

    protected function onKey(KeyMsg $message, Elmphant\Tui\Application\InputContext $input): UpdateResult
    {
        return $message->key === Key::Enter ? new UpdateResult($this, Cmd::quit()) : new UpdateResult($this);
    }
}

echo "Ruled heading\n";

Program::new(new InlineRuledE2eApp())->withScreenMode(ScreenMode::inline())->start();

echo "Ruled done\n";
