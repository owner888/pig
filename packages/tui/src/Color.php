<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * A concrete color — upstream's `Color` union in `colors.ts` (`IndexedColor | RgbColorValue |
 * OklchColorValue`). Every color can be converted to sRGB, so color math never fails.
 *
 * Each implementation carries upstream's `kind` discriminant as a property, so a check reads the
 * same as upstream's (`$color->kind === 'indexed'`); `instanceof` works as well.
 *
 * @property-read 'indexed'|'rgb'|'oklch' $kind
 */
interface Color
{
}
