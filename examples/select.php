#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Bar\Header;
use Elmphant\Tui\Widget\Choice\CheckboxGroup;
use Elmphant\Tui\Widget\Choice\Radio;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Separator;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;

final readonly class PizzaOrder extends App
{
    /**
     * @param Dropdown<Select>         $size
     * @param Dropdown<Radio>          $crust
     * @param Dropdown<CheckboxGroup>  $toppings
     * @param Dropdown<FilterableList> $address
     */
    public function __construct(
        private Dropdown $size,
        private Dropdown $crust,
        private Dropdown $toppings,
        private Dropdown $address,
        private Panel $order,
    ) {
    }

    public static function new(): self
    {
        $places = ['Moonbase Alpha', 'Crater Street 7', 'Lighthouse Point', 'Harbour Lane 12', 'Maple Grove 3',
            'Cloud Nine Tower', 'Pebble Beach Hut', 'Old Mill Road 9', 'Sunny Side 42', 'Fox Den Alley 5'];

        return new self(
            Dropdown::new(Select::new(['Small', 'Medium', 'Large', 'Moon-sized'])->withEmptyCaption('choose a size')),
            Dropdown::new(Radio::new(['Thin', 'Classic', 'Stuffed', 'Cloud'])),
            Dropdown::new(CheckboxGroup::new(['Mushrooms', 'Olives', 'Pineapple', 'Basil', 'Extra cheese', 'Jalapeños'])),
            Dropdown::new(FilterableList::new(array_map(
                static fn (string $place): SimpleListItem => new SimpleListItem($place),
                $places,
            ))->withEmptyCaption('type to search')),
            Panel::new('Order a pizza')->withPadding(Spacing::symmetric(2, 1)),
        );
    }

    public function view(): Widget
    {
        return Layout::column(
            Header::new()->withCenter(Text::token('Pizza Planet', StyleToken::Accent)),
            Component::bind('order', $this->order->withChildren([
                Text::token('Size', StyleToken::Title),
                $this->bind('size'),
                Separator::new(),
                Text::token('Crust', StyleToken::Title),
                $this->bind('crust'),
                Separator::new(),
                Text::token('Toppings', StyleToken::Title),
                $this->bind('toppings'),
                Separator::new(),
                Text::token('Deliver to', StyleToken::Title),
                $this->bind('address'),
            ]), static fn (self $app, Panel $order): self => $app->cloneWith(order: $order)),
            Paragraph::fromLines([new Line([Span::token($this->summary(), StyleToken::Accent)])]),
            Footer::new()->withLeft(FocusHints::new()),
        );
    }

    private function summary(): string
    {
        $size = $this->size->content()->selectedValue();
        $crust = $this->crust->content()->selectedValue();
        $address = $this->address->content()->selectedItem()?->title();
        if ($size === null || $crust === null || $address === null) {
            return 'Pick a size and an address to order.';
        }

        $toppings = $this->toppings->content()->checkedValues();

        return sprintf(
            'Your order: a %s %s pizza%s, to %s.',
            strtolower($size),
            strtolower($crust),
            $toppings === [] ? '' : ' with ' . strtolower(implode(', ', $toppings)),
            $address,
        );
    }
}

Program::run(PizzaOrder::new());
