<?php

declare(strict_types=1);

namespace tests\Unit;

use MarjovanLier\XhprofTrace\Trace;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

/**
 * @internal
 *
 * @covers \MarjovanLier\XhprofTrace\Trace::isExcludedClass
 */
final class IsExcludedClassTest extends TestCase
{
    /**
     * @var string[]
     */
    private const EXCLUDED_PREFIXES = [
        'Zend_',
        'Composer\\',
        'PHPStan\\',
    ];

    /**
     * @var string[]
     */
    private const NON_EXCLUDED_FUNCTION_NAMES = [
        'Allowed_SomeFunctionName',
        'Permitted_AnotherFunctionName',
    ];

    /**
     * @var ReflectionClass<Trace>
     */
    private ReflectionClass $reflectionClass;


    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Create a reflection of the Trace class
        $this->reflectionClass = new ReflectionClass(Trace::class);
    }


    public function testIsExcludedClassReturnsTrueForExcludedPrefixes(): void
    {
        foreach (self::EXCLUDED_PREFIXES as $excludedPrefix) {
            $functionName = $excludedPrefix . 'SomeFunctionName';
            self::assertTrue($this->callIsExcludedClass($functionName));
        }
    }


    public function testIsExcludedClassReturnsFalseForNonExcludedPrefixes(): void
    {
        foreach (self::NON_EXCLUDED_FUNCTION_NAMES as $functionName) {
            self::assertFalse($this->callIsExcludedClass($functionName));
        }
    }


    /**
     * @throws ReflectionException
     */
    private function callIsExcludedClass(string $functionName): bool
    {
        // Get the 'isExcludedClass' method from the reflection
        $reflectionMethod = $this->reflectionClass->getMethod('isExcludedClass');

        // Invoke the method on a Trace object and pass the function name
        /**
         * @var bool $result
         */
        $result = $reflectionMethod->invoke(null, $functionName);

        return $result;
    }
}
