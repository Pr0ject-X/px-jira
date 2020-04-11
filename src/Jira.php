<?php

declare(strict_types=1);

namespace Pr0jectX\PxJira;

/**
 * Define the command Jira command.
 */
class Jira
{
    /**
     * Print the display Jira banner.
     */
    public static function printDisplayBanner()
    {
        print file_get_contents(
            dirname(__DIR__) . '/banner.txt'
        );
    }
}
