<?php
declare(strict_types=1);

// Apply before LIMIT so incomplete products do not crowd out usable cards.
function pcf_home_item_image_where(string $alias = 'items'): string
{
    if (!in_array($alias, ['items', 'i'], true)) {
        throw new InvalidArgumentException('Invalid home item alias');
    }
    return 'COALESCE(NULLIF(TRIM(' . $alias . '.image_small), ""),'
        . 'NULLIF(TRIM(' . $alias . '.image_large), ""),'
        . 'NULLIF(TRIM(' . $alias . '.image_list), "")) IS NOT NULL';
}
