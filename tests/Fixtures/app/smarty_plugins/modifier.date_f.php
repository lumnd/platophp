<?php
/**
 * Test only: same name as the framework modifier, proves the application wins.
 */
function smarty_modifier_date_f($t, $f)
{
    return 'app:' . date($f, (int) $t);
}
