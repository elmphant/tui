<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Message\Dismissed;
use Elmphant\Tui\Message\Pressed;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ScreenMode;
use Elmphant\Tui\Widget\Form\Field;
use Elmphant\Tui\Widget\Form\Form;
use Elmphant\Tui\Widget\Widget;

final readonly class DatabaseForm extends App
{
    public function __construct(public Form $form, public bool $saved = false)
    {
    }

    public static function new(): self
    {
        $timeZones = timezone_identifiers_list(DateTimeZone::EUROPE);

        return new self(Form::new('Database')
            ->withField(Field::text('host', 'Host')->withRequired())
            ->withField(Field::text('name', 'Database name'))
            ->withField(Field::choice('driver', 'Driver', ['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite']))
            ->withField(Field::choice('timezone', 'Time zone', array_combine($timeZones, $timeZones)))
            ->withSubmit('Save')
            ->withCancel());
    }

    public function view(): Widget
    {
        return $this->bind('form')
            ->on(Pressed::class, static fn (self $app): UpdateResult => new UpdateResult($app->cloneWith(saved: true), Cmd::quit()))
            ->on(Dismissed::class, static fn (self $app): UpdateResult => new UpdateResult($app, Cmd::quit()));
    }
}

echo "Setting up the project.\n";

$result = Program::new(DatabaseForm::new())->withScreenMode(ScreenMode::inline())->start();

echo $result->saved ? 'Saved: ' . json_encode($result->form->values()) . "\n" : "Cancelled.\n";
