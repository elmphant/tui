<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Message\Chosen;
use Elmphant\Tui\Message\Submitted;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ScreenMode;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Widget;

final readonly class ProjectQuestions extends App
{
    /**
     * @param array<string, TextInput|Select> $fields
     * @param array<string, string>           $answers
     */
    public function __construct(public array $fields, public array $answers = [])
    {
    }

    public function view(): Widget
    {
        $question = $this->question();

        return Layout::column(
            Paragraph::fromText("? {$question}"),
            $this->bind('fields', at: $question)
                ->on(Submitted::class, static fn (self $app): self|UpdateResult => $app->answered())
                ->on(Chosen::class, static fn (self $app): self|UpdateResult => $app->answered()),
            Paragraph::fromText($this->fields[$question] instanceof Select ? '↑/↓ to move · Enter to choose' : 'Enter to confirm · Ctrl+C to cancel'),
        );
    }

    public function isDone(): bool
    {
        return count($this->answers) === count($this->fields);
    }

    private function question(): string
    {
        return array_key_first(array_diff_key($this->fields, $this->answers)) ?? (string)array_key_last($this->fields);
    }

    /**
     * @return self|UpdateResult<self>
     */
    private function answered(): self|UpdateResult
    {
        $question = $this->question();
        $field = $this->fields[$question];
        $answer = trim(($field instanceof Select ? $field->selectedValue() : $field->value()) ?? '');
        if ($answer === '') {
            return $this;
        }

        $next = $this->cloneWith(answers: [...$this->answers, $question => $answer]);
        $printed = Cmd::println("✓ {$question}  {$answer}");

        return new UpdateResult($next, $next->isDone() ? Cmd::batch($printed, Cmd::quit()) : $printed);
    }
}

echo "Setting up a new project\n\n";

$result = Program::new(new ProjectQuestions([
    'Project name' => TextInput::new()->withPlaceholder('my-project')->withSubmit(),
    'Database' => Select::new(['MySQL', 'PostgreSQL', 'SQLite']),
    'Database host' => TextInput::new('localhost')->withSubmit(),
]))->withScreenMode(ScreenMode::inline())->start();

echo $result->isDone() ? "\nDone.\n" : "\nCancelled.\n";
