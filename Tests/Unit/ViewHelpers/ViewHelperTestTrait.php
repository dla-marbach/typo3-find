<?php

namespace Dla\Find\Tests\Unit\ViewHelpers;

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

use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\Variables\StandardVariableProvider;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperInterface;

/**
 * Replacement for the removed Nimut\TestingFramework\TestCase\ViewHelperBaseTestcase::injectDependenciesIntoViewHelper()
 * and AbstractTestCase::inject(), scoped down to what this extension's ViewHelper tests actually need.
 */
trait ViewHelperTestTrait
{
    protected ?RenderingContextInterface $renderingContext = null;

    /** @var StandardVariableProvider|\PHPUnit\Framework\MockObject\MockObject */
    protected $variableProvider;

    protected function injectDependenciesIntoViewHelper(ViewHelperInterface $viewHelper): void
    {
        if ($this->renderingContext === null) {
            $this->variableProvider = $this->getMockBuilder(StandardVariableProvider::class)
                ->onlyMethods(['add', 'get', 'remove', 'exists'])
                ->getMock();
            $this->renderingContext = $this->getMockBuilder(RenderingContextInterface::class)->getMock();
            $this->renderingContext->method('getVariableProvider')->willReturn($this->variableProvider);
        }
        $viewHelper->setRenderingContext($this->renderingContext);
        $viewHelper->setArguments([]);
    }

    /** Reflection-based property/setter injection, replacing the removed AbstractTestCase::inject(). */
    protected function inject(object $target, string $name, mixed $dependency): void
    {
        $setter = 'set' . ucfirst($name);
        if (method_exists($target, $setter)) {
            $target->$setter($dependency);
            return;
        }
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $dependency);
    }
}
