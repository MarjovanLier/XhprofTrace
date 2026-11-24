<?php

declare(strict_types=1);

namespace MarjovanLier\XhprofTrace\Tests\Unit;

use JsonException;
use MarjovanLier\XhprofTrace\Trace;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests the report through displayReportCLI(), the way a user runs it: profile files in a folder, a table out.
 *
 * @internal
 */
#[CoversClass(Trace::class)]
final class DisplayReportCliTest extends TestCase
{
    private const HEADER = [
        'Function',
        'Calls',
        'Wall incl. (ms)',
        'Wall excl. (ms)',
        'CPU excl. (ms)',
        'Memory change excl. (KB)',
        'Runs',
    ];

    /**
     * One run as xhprof stores it: one entry per caller and callee pair, plus the root "main()".
     *
     * "helper" has two callers and frees memory. The Composer, Zend and PHPStan calls are left out of the
     * report, but their time still comes off their callers. Times are in microseconds, memory in bytes.
     * The class names are made up, so Rector does not turn them into ::class references.
     *
     * @var array<string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}>
     */
    private const FIRST_RUN = [
        'main()' => ['ct' => 1, 'wt' => 10000, 'cpu' => 8000, 'mu' => 110592, 'pmu' => 0],
        'main()==>load' => ['ct' => 1, 'wt' => 3000, 'cpu' => 2000, 'mu' => 102400, 'pmu' => 0],
        'load==>Composer\Fixture\Loader::loadClass' => ['ct' => 4, 'wt' => 1000, 'cpu' => 1000, 'mu' => 1024, 'pmu' => 0],
        'load==>helper' => ['ct' => 2, 'wt' => 400, 'cpu' => 300, 'mu' => -2048, 'pmu' => 0],
        'main()==>query' => ['ct' => 1, 'wt' => 5000, 'cpu' => 1000, 'mu' => 1024, 'pmu' => 0],
        'query==>Zend_Db_Adapter::query' => ['ct' => 1, 'wt' => 4000, 'cpu' => 500, 'mu' => 512, 'pmu' => 0],
        'query==>helper' => ['ct' => 1, 'wt' => 100, 'cpu' => 100, 'mu' => 0, 'pmu' => 0],
        'main()==>PHPStan\Fixture\Analyser::analyse' => ['ct' => 1, 'wt' => 500, 'cpu' => 500, 'mu' => 0, 'pmu' => 0],
    ];

    /**
     * @var array<string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}>
     */
    private const SECOND_RUN = [
        'main()' => ['ct' => 1, 'wt' => 15000, 'cpu' => 3000, 'mu' => 2048, 'pmu' => 0],
        'main()==>query' => ['ct' => 2, 'wt' => 12000, 'cpu' => 1500, 'mu' => 1024, 'pmu' => 0],
        'query==>Zend_Db_Adapter::query' => ['ct' => 2, 'wt' => 3000, 'cpu' => 1000, 'mu' => 0, 'pmu' => 0],
    ];

    private string $profilesDir;


    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->profilesDir = sys_get_temp_dir() . '/xhprof-trace-test-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->profilesDir);
        Trace::setProfilesDir($this->profilesDir);
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
     */
    public function testSumsEveryRunWithTheHighestExclusiveWallTimeLast(): void
    {
        $this->writeProfile('1792832673.aaaaaaaaaaaaaaaa.application.json', self::FIRST_RUN);
        $this->writeProfile('1792832674.bbbbbbbbbbbbbbbb.application.json', self::SECOND_RUN);

        self::assertSame(
            [
                self::HEADER,
                ['helper', '3', '0.50', '0.50', '0.40', '-2.0', '1'],
                ['load', '1', '3.00', '1.60', '0.70', '101.0', '1'],
                ['main()', '2', '25.00', '4.50', '6.00', '8.0', '2'],
                ['query', '3', '17.00', '9.90', '0.90', '1.5', '2'],
            ],
            $this->renderReport(),
        );
    }


    /**
     * @throws JsonException
     */
    public function testReadsOnlyProfileFilesThatHoldData(): void
    {
        $this->writeProfile('1792832673.aaaaaaaaaaaaaaaa.application.json', self::SECOND_RUN);
        // Saved by an older version for a disable without an enable.
        $this->writeFile('1792832674.bbbbbbbbbbbbbbbb.application.json', 'null');
        // Cut off mid-write.
        $this->writeFile('1792832675.cccccccccccccccc.application.json', '{"main()": {"ct": 1,');
        // Valid profile data, but not a file disableXhprof() saves.
        $this->writeProfile('composer.json', self::FIRST_RUN);

        self::assertSame(
            [
                self::HEADER,
                ['main()', '1', '15.00', '3.00', '1.50', '1.0', '1'],
                ['query', '2', '12.00', '9.00', '0.50', '1.0', '1'],
            ],
            $this->renderReport(),
        );
    }


    public function testShowsOnlyTheHeaderWithoutProfiles(): void
    {
        self::assertSame([self::HEADER], $this->renderReport());
    }


    /**
     * Renders the report and returns the cells of each table row, the header first.
     *
     * @return array<int, array<int, string>>
     *
     * @throws JsonException
     */
    private function renderReport(): array
    {
        $bufferedOutput = new BufferedOutput();
        Trace::displayReportCLI($bufferedOutput);

        $rows = [];

        foreach (explode(PHP_EOL, $bufferedOutput->fetch()) as $line) {
            // Border lines start with "+"; header and data lines start with "|".
            if (!str_starts_with($line, '|')) {
                continue;
            }

            $rows[] = array_map(trim(...), explode('|', trim($line, '|')));
        }

        return $rows;
    }


    /**
     * @param array<string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}> $profile
     *
     * @throws JsonException
     */
    private function writeProfile(string $name, array $profile): void
    {
        $this->writeFile($name, json_encode($profile, JSON_THROW_ON_ERROR));
    }


    private function writeFile(string $name, string $contents): void
    {
        file_put_contents($this->profilesDir . $name, $contents);
    }
}
