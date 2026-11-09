<?php

if (! function_exists('dinero')) {
    /** $1,234.50 en pesos, con centavos. */
    function dinero(float|int|null $v): string
    {
        $v = (float) $v;

        return ($v < 0 ? '−$' : '$').number_format(abs($v), 2);
    }
}

if (! function_exists('pct')) {
    function pct(float|int|null $v): string
    {
        return round(((float) $v) * 100).'%';
    }
}
