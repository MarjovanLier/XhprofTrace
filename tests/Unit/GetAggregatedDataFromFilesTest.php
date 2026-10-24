<?php

declare(strict_types=1);

namespace tests\Unit;

use JsonException;
use MarjovanLier\XhprofTrace\Trace;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

/**
 * @internal
 *
 * @covers \MarjovanLier\XhprofTrace\Trace::generateReport
 * @covers \MarjovanLier\XhprofTrace\Trace::getAggregatedDataFromFiles
 */
final class GetAggregatedDataFromFilesTest extends TestCase
{
    /**
     * @var array<string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}>
     */
    private const FIRST_RUN = [
        'main()==>a' => ['ct' => 1, 'wt' => 100, 'cpu' => 60, 'mu' => 1000, 'pmu' => 0],
        'main()' => ['ct' => 1, 'wt' => 150, 'cpu' => 90, 'mu' => 1500, 'pmu' => 0],
    ];

    /**
     * @var array<string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}>
     */
    private const SECOND_RUN = [
        'main()==>a' => ['ct' => 2, 'wt' => 10, 'cpu' => 8, 'mu' => 100, 'pmu' => 0],
        'main()==>b' => ['ct' => 1, 'wt' => 300, 'cpu' => 200, 'mu' => 50, 'pmu' => 0],
        'main()' => ['ct' => 1, 'wt' => 320, 'cpu' => 210, 'mu' => 160, 'pmu' => 0],
    ];

    private string $profilesDir;


    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->profilesDir = sys_get_temp_dir() . '/xhprof-trace-test-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->profilesDir);
    }


    #[Override]
    protected function tearDown(): void
    {
        $files = glob($this->profilesDir . '*');

        foreach (is_array($files) ? $files : [] as $file) {
            unlink($file);
        }

        rmdir($this->profilesDir);

        parent::tearDown();
    }


    /**
     * @throws JsonException
     * @throws ReflectionException
     */
    public function testSumsAllRunsWithTheHighestExclusiveWallTimeLast(): void
    {
        $files = [
            $this->writeProfile('first.json', self::FIRST_RUN),
            $this->writeProfile('second.json', self::SECOND_RUN),
        ];

        self::assertSame(
            [
                'main()' => ['ct' => 2, 'wt' => 470, 'excl_wt' => 60, 'excl_cpu' => 32, 'excl_mu' => 510, 'runs' => 2],
                'a' => ['ct' => 3, 'wt' => 110, 'excl_wt' => 110, 'excl_cpu' => 68, 'excl_mu' => 1100, 'runs' => 2],
                'b' => ['ct' => 1, 'wt' => 300, 'excl_wt' => 300, 'excl_cpu' => 200, 'excl_mu' => 50, 'runs' => 1],
            ],
            $this->callGetAggregatedDataFromFiles($files),
        );
    }


    /**
     * @param array<string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}> $profile
     *
     * @throws JsonException
     */
    private function writeProfile(string $name, array $profile): string
    {
        $file = $this->profilesDir . $name;
        file_put_contents($file, json_encode($profile, JSON_THROW_ON_ERROR));

        return $file;
    }


    /**
     * @param array<int, string> $files
     *
     * @throws ReflectionException
     */
    private function callGetAggregatedDataFromFiles(array $files): mixed
    {
        $reflectionMethod = (new ReflectionClass(Trace::class))->getMethod('getAggregatedDataFromFiles');

        return $reflectionMethod->invoke(null, $files);
    }
}
