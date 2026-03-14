<?php

namespace Subugoe\Find\Tests\Unit\ViewHelpers\Data;

/* * *************************************************************
 *  Copyright notice
 *
 *  (c) 2015 Ingo Pfennigstorf <pfennigstorf@sub-goettingen.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 * ************************************************************* */

use PHPUnit\Framework\Attributes\Test;
use Subugoe\Find\ViewHelpers\Data\SplitViewHelper;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class SplitViewHelperTest extends UnitTestCase
{
    /**
     * @var SplitViewHelper
     */
    public $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new SplitViewHelper();
    }

    #[Test]
    public function stringIsExplodedCorrectlyWithoutPassedSeparator(): void
    {
        $string = 'hrdr, behedeti, horus';
        $expected = ['hrdr', 'behedeti', 'horus'];
        $this->fixture->setArguments(
            [
                'string' => $string,
            ]
        );

        self::assertSame($expected, $this->fixture->initializeArgumentsAndRender());
    }

    #[Test]
    public function stringIsExplodedCorrectlyWithPassedSeparator(): void
    {
        $string = 'hrdr, behedeti, horus';
        $separator = ', ';
        $expected = ['hrdr', 'behedeti', 'horus'];

        $this->fixture->setArguments([
            'string' => $string,
            'separator' => $separator,
        ]);

        self::assertSame($expected, $this->fixture->initializeArgumentsAndRender());
    }

    #[Test]
    public function stringIsExplodedCorrectlyWithNonDefaultSeparator(): void
    {
        $string = 'hrdrhorus behedetihorus horus';
        $separator = 'horus ';
        $expected = ['hrdr', 'behedeti', 'horus'];
        $this->fixture->setArguments([
            'string' => $string,
            'separator' => $separator,
        ]);

        self::assertSame($expected, $this->fixture->initializeArgumentsAndRender());
    }

    #[Test]
    public function emptyArrayIsReturnedWhenPassingIt(): void
    {
        $string = '';
        $expected = [''];
        $this->fixture->setArguments([
            'string' => $string,
        ]);

        $actual = $this->fixture->initializeArgumentsAndRender();

        self::assertSame($expected, $actual);
    }
}
