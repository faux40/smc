<?php

use Carbon\Carbon;

/*
 * Custom TinyButStrong `onformat` callbacks, autoloaded via composer
 * `files` (TBS resolves them as global functions). Ported from
 * bg_hazards_demo. Used by the ${key:MDDATE} template modifier.
 */
if (! function_exists('tbs_ordinal_date')) {
    function tbs_ordinal_date($fullName, &$currVal, $prmList, $tbs): void
    {
        if (is_string($currVal) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $currVal)) {
            $currVal = Carbon::createFromFormat('Y-m-d', $currVal)->format('F jS');
        }
    }
}
