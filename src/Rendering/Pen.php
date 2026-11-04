<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\ColorProfile;

/**
 * @internal
 */
final class Pen
{
    /**
     * Animated colours can grow these caches indefinitely, so clear them at this limit.
     */
    private const COLOR_CACHE_LIMIT = 4096;

    private int $currentForeground = 0;
    private int $currentBackground = 0;
    private int $currentAttributes = 0;
    private int $currentLink = 0;

    /**
     * @var array<int, int>
     */
    private array $downsampleCache = [];
    /**
     * @var array<int, string>
     */
    private array $foregroundSgrCache = [];
    /**
     * @var array<int, string>
     */
    private array $backgroundSgrCache = [];
    /**
     * @var array<int, string>
     */
    private array $attributeSgrCache = [];

    public function __construct(private readonly ColorProfile $colorProfile)
    {
    }

    /**
     * Terminals disagree on complex emoji width, so the cursor is put back after one by the move handed in.
     * tmux can drop a VS16 emoji if its second cell still holds part of an old wide character, so both cells are cleared first.
     */
    public static function cellText(string $character, int $width, string $moveToCell, string $moveAfterCell): string
    {
        if (!self::isCluster($character, $width)) {
            return $character;
        }

        $text = str_contains($character, "\u{FE0F}") ? str_repeat(' ', $width) . $moveToCell . $character : $character;

        return $text . $moveAfterCell;
    }

    public static function isCluster(string $character, int $width): bool
    {
        return $width > 0 && strlen($character) > 4;
    }

    public function switchTo(int $foreground, int $background, int $attributes): string
    {
        return $this->sgrDelta($foreground, $background, $attributes & Hyperlinks::SGR_ATTRIBUTES)
            . $this->linkDelta($attributes & ~Hyperlinks::SGR_ATTRIBUTES);
    }

    /**
     * A link left open would carry on to whatever the terminal prints next.
     */
    public function closeLink(): string
    {
        if ($this->currentLink === 0) {
            return '';
        }

        $this->currentLink = 0;

        return Ansi::HYPERLINK_END;
    }

    public function forgetStyle(): void
    {
        $this->currentForeground = 0;
        $this->currentBackground = 0;
        $this->currentAttributes = 0;
    }

    public function lift(): string
    {
        $styled = $this->currentForeground !== 0 || $this->currentBackground !== 0 || $this->currentAttributes !== 0;
        $text = $this->closeLink() . ($styled ? Ansi::RESET_STYLE : '');
        $this->forgetStyle();

        return $text;
    }

    private function linkDelta(int $link): string
    {
        if ($link === $this->currentLink) {
            return '';
        }

        $change = $this->closeLink();
        $this->currentLink = $link;

        return $link === 0 ? $change : $change . Ansi::hyperlink(Hyperlinks::urlIn($link) ?? '');
    }

    private function sgrDelta(int $foreground, int $background, int $attributes): string
    {
        $foreground = $this->downsample($foreground);
        $background = $this->downsample($background);

        if ($foreground === $this->currentForeground && $background === $this->currentBackground && $attributes === $this->currentAttributes) {
            return '';
        }

        $removedAttributes = $this->currentAttributes & ~$attributes;
        $sgrParts = [];

        if ($removedAttributes !== 0) {
            $sgrParts[] = '0';
            if ($attributes !== 0) {
                $sgrParts[] = $this->attributesToSgr($attributes);
            }
            if ($foreground !== 0) {
                $sgrParts[] = $this->colorToSgr($foreground, background: false);
            }
            if ($background !== 0) {
                $sgrParts[] = $this->colorToSgr($background, background: true);
            }
        } else {
            $addedAttributes = $attributes & ~$this->currentAttributes;
            if ($addedAttributes !== 0) {
                $sgrParts[] = $this->attributesToSgr($addedAttributes);
            }
            if ($foreground !== $this->currentForeground) {
                $sgrParts[] = $this->colorToSgr($foreground, background: false);
            }
            if ($background !== $this->currentBackground) {
                $sgrParts[] = $this->colorToSgr($background, background: true);
            }
        }

        $this->currentForeground = $foreground;
        $this->currentBackground = $background;
        $this->currentAttributes = $attributes;

        return $sgrParts !== [] ? Ansi::sgr(implode(';', $sgrParts)) : '';
    }

    /**
     * @infection-ignore-all a bounded memo over Color::shownIn(): a miss or a reset computes the same value again
     */
    private function downsample(int $color): int
    {
        if ($this->colorProfile === ColorProfile::TrueColor) {
            return $color;
        }

        if (!isset($this->downsampleCache[$color]) && count($this->downsampleCache) >= self::COLOR_CACHE_LIMIT) {
            $this->downsampleCache = [];
        }

        return $this->downsampleCache[$color] ??= Color::fromValue($color)->shownIn($this->colorProfile)->value;
    }

    /**
     * @infection-ignore-all a bounded memo over Color's SGR codes: a miss or a reset computes the same value again
     */
    private function colorToSgr(int $color, bool $background): string
    {
        if ($background) {
            if (!isset($this->backgroundSgrCache[$color]) && count($this->backgroundSgrCache) >= self::COLOR_CACHE_LIMIT) {
                $this->backgroundSgrCache = [];
            }

            return $this->backgroundSgrCache[$color]
                ??= Color::fromValue($color)->toBackgroundSgr();
        }

        if (!isset($this->foregroundSgrCache[$color]) && count($this->foregroundSgrCache) >= self::COLOR_CACHE_LIMIT) {
            $this->foregroundSgrCache = [];
        }

        return $this->foregroundSgrCache[$color]
            ??= Color::fromValue($color)->toForegroundSgr();
    }

    private function attributesToSgr(int $attributes): string
    {
        if (isset($this->attributeSgrCache[$attributes])) {
            return $this->attributeSgrCache[$attributes];
        }

        $codes = [];
        foreach (Attr::BY_SGR_CODE as $code => $attribute) {
            if (($attributes & $attribute) !== 0) {
                $codes[] = $code;
            }
        }

        return $this->attributeSgrCache[$attributes] = implode(';', $codes);
    }
}
