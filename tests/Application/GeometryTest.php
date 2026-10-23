<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Geometry;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeometryTest extends TestCase
{
    #[Test]
    public function aComponentIsWhereTheLayoutPutIt(): void
    {
        $geometry = Geometry::of(self::headerAndList(), Rect::fromSize(20, 10));

        self::assertEquals(new Rect(0, 2, 20, 8), $geometry->rectOf('list'));
    }

    #[Test]
    public function aPlainWidgetIsFoundByTheInstanceTheTreeHolds(): void
    {
        $body = Text::new('body');
        $root = Layout::vertical()->add(Text::new('head'), Constraint::exact(2))->add($body, Constraint::flex());

        self::assertEquals(new Rect(0, 2, 20, 8), Geometry::of($root, Rect::fromSize(20, 10))->rectOf($body));
        self::assertNull(Geometry::of($root, Rect::fromSize(20, 10))->rectOf(Text::new('body')), 'an equal widget is not the one drawn');
    }

    #[Test]
    public function aComponentNotInTheTreeIsNowhere(): void
    {
        self::assertNull(Geometry::of(self::headerAndList(), Rect::fromSize(20, 10))->rectOf('details'));
    }

    #[Test]
    public function aChildAPaneGraftsIsFoundByItsOwnIdInsideThePane(): void
    {
        $geometry = Geometry::of(self::form(), Rect::fromSize(20, 10));

        $pane = $geometry->rectOf('form');
        $field = $geometry->rectOf('form/1');

        self::assertNotNull($pane);
        self::assertNotNull($field);
        self::assertEquals($field, $pane->intersect($field), 'the field lies inside the pane');
    }

    #[Test]
    public function thePointerFindsTheInnermostComponentUnderIt(): void
    {
        $geometry = Geometry::of(self::form(), Rect::fromSize(20, 10));
        $field = $geometry->rectOf('form/1');
        self::assertNotNull($field);

        self::assertSame('form/1', $geometry->componentAt($field->x, $field->y)?->value);
        self::assertSame('form', $geometry->componentAt(0, 0)?->value, 'the border belongs to the pane');
    }

    #[Test]
    public function thePointerOverNoComponentFindsNothing(): void
    {
        self::assertNull(Geometry::of(self::headerAndList(), Rect::fromSize(20, 10))->componentAt(3, 0));
    }

    #[Test]
    public function anAppAsksTheGeometryOfWhatItDraws(): void
    {
        $app = new GeometryAskingApp(self::headerAndList());

        self::assertEquals(new Rect(0, 2, 20, 8), $app->whereIs('list', Rect::fromSize(20, 10)));
    }

    private static function headerAndList(): Widget
    {
        return Layout::vertical()
            ->add(Text::new('head'), Constraint::exact(2))
            ->add(Component::named('list', Text::new('rows')), Constraint::flex());
    }

    private static function form(): Widget
    {
        return Component::bind(
            'form',
            Panel::new('Form')->withChildren([TextInput::new()->withPlaceholder('a'), TextInput::new()->withPlaceholder('b')]),
            static fn (GeometryAskingApp $app, Panel $panel): GeometryAskingApp => $app,
        );
    }
}

final readonly class GeometryAskingApp extends App
{
    public function __construct(private Widget $tree)
    {
    }

    public function view(): Widget
    {
        return $this->tree;
    }

    public function whereIs(string $id, Rect $screen): ?Rect
    {
        return $this->geometry($screen)->rectOf($id);
    }
}
