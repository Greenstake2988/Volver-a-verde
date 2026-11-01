<?php

if (! function_exists('dinero')) {
    /** $1,234 en pesos, sin centavos. */
    function dinero(float|int|null $v): string
    {
        $v = (float) $v;

        return ($v < 0 ? '−$' : '$').number_format(abs($v), 0);
    }
}

if (! function_exists('pct')) {
    function pct(float|int|null $v): string
    {
        return round(((float) $v) * 100).'%';
    }
}
