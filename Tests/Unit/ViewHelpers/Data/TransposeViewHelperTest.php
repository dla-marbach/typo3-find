<?php

namespace Subugoe\Find\Tests\Unit\ViewHelpers\Data;

use PHPUnit\Framework\Attributes\Test;
use Subugoe\Find\ViewHelpers\Data\TransposeViewHelper;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class TransposeViewHelperTest extends UnitTestCase
{
    public TransposeViewHelper $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new TransposeViewHelper();
    }

    #[Test]
    public function arrayIsTransposed(): void
    {
        // Set up the mock object to return the expected value for getVariableProvider()
        $arguments = [
            'arrays' => [
                'horus' => ['b:ehedeti', 'h:rdr'],
                'behedeti' => ['h:orus', 'h:rdr'],
            ],
            'name' => 'hrdr',
        ];
        $expected = [
            ['horus' => 'b:ehedeti', 'behedeti' => 'h:orus'],
            ['horus' => 'h:rdr', 'behedeti' => 'h:rdr'],
        ];

        $this->fixture->initialize();
        $this->fixture->setArguments(['arrays' => $arguments['arrays'], 'name' => $arguments['name']]);
    }
}
