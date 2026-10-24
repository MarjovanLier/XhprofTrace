<?php

declare(strict_types=1);

namespace tests\Unit;

use MarjovanLier\XhprofTrace\Trace;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

/**
 * @internal
 *
 * @covers \MarjovanLier\XhprofTrace\Trace::processDataForReport
 */
final class ProcessDataForReportTest extends TestCase
{
    /**
     * A profile as xhprof stores it: one entry per caller and callee pair, plus the root "main()".
     *
     * The order matters: some functions are seen as a caller before they are seen as a callee.
     *
     * @var array<string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}>
     */
    private const PROFILE = [
        'a==>shared' => ['ct' => 2, 'wt' => 30, 'cpu' => 20, 'mu' => 300, 'pmu' => 0],
        'main()==>a' => ['ct' => 1, 'wt' => 100, 'cpu' => 60, 'mu' => 1000, 'pmu' => 0],
        'main()' => ['ct' => 1, 'wt' => 200, 'cpu' => 120, 'mu' => 2000, 'pmu' => 0],
        'b==>shared' => ['ct' => 3, 'wt' => 20, 'cpu' => 10, 'mu' => 200, 'pmu' => 0],
        'b==>Zend_Db_Adapter::query' => ['ct' => 1, 'wt' => 5, 'cpu' => 4, 'mu' => 50, 'pmu' => 0],
        'main()==>b' => ['ct' => 1, 'wt' => 60, 'cpu' => 40, 'mu' => 600, 'pmu' => 0],
    ];


    /**
     * @throws ReflectionException
     */
    public function testSumsEveryCallerAndSubtractsChildrenForExclusiveTotals(): void
    {
        self::assertSame(
            [
                'shared' => ['ct' => 5, 'wt' => 50, 'excl_wt' => 50, 'excl_cpu' => 30, 'excl_mu' => 500],
                'a' => ['ct' => 1, 'wt' => 100, 'excl_wt' => 70, 'excl_cpu' => 40, 'excl_mu' => 700],
                'main()' => ['ct' => 1, 'wt' => 200, 'excl_wt' => 40, 'excl_cpu' => 20, 'excl_mu' => 400],
                // The excluded Zend_ call is not listed, but its time is still not counted as b's own.
                'b' => ['ct' => 1, 'wt' => 60, 'excl_wt' => 35, 'excl_cpu' => 26, 'excl_mu' => 350],
            ],
            $this->callProcessDataForReport(self::PROFILE),
        );
    }


    /**
     * @param array<string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}> $data
     *
     * @throws ReflectionException
     */
    private function callProcessDataForReport(array $data): mixed
    {
        $reflectionMethod = (new ReflectionClass(Trace::class))->getMethod('processDataForReport');

        return $reflectionMethod->invoke(null, $data);
    }
}
