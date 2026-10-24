<?php

declare(strict_types=1);

namespace tests\Unit;

use JsonException;
use MarjovanLier\XhprofTrace\Trace;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real xhprof extension, so it needs ext-xhprof loaded.
 *
 * @internal
 *
 * @covers \MarjovanLier\XhprofTrace\Trace::disableXhprof
 * @covers \MarjovanLier\XhprofTrace\Trace::enableXhprof
 */
final class ProfileCaptureTest extends TestCase
{
    private string $profilesDir;

    private string $previousDir;


    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->profilesDir = sys_get_temp_dir() . '/xhprof-trace-test-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->profilesDir . 'cwd/', recursive: true);
        Trace::setProfilesDir($this->profilesDir);

        // A profile saved without the directory (a mutant under Infection) lands in cwd/, not in the repository.
        $this->previousDir = (string) getcwd();
        chdir($this->profilesDir . 'cwd/');
    }


    #[Override]
    protected function tearDown(): void
    {
        chdir($this->previousDir);

        $this->removeDirectory($this->profilesDir . 'cwd/');
        $this->removeDirectory($this->profilesDir);

        parent::tearDown();
    }


    /**
     * @throws JsonException
     */
    public function testTwoRunsInOneSecondKeepBothProfiles(): void
    {
        Trace::enableXhprof();
        Trace::disableXhprof();
        Trace::enableXhprof();
        Trace::disableXhprof();

        $files = $this->profileFiles();

        self::assertCount(2, $files);

        foreach ($files as $file) {
            self::assertMatchesRegularExpression('/^\d+\.[0-9a-f]{16}\.application\.json$/', basename($file));
        }
    }


    /**
     * @throws JsonException
     */
    public function testProfileRecordsBuiltInFunctionsWithCpuAndMemory(): void
    {
        Trace::enableXhprof();
        usleep(1);
        Trace::disableXhprof();

        [$file] = $this->profileFiles();

        /**
         * @var array<string, array<string, int>> $profile
         */
        $profile = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        $builtInCalls = array_filter(
            $profile,
            static fn(string $key): bool => str_ends_with($key, '==>usleep'),
            ARRAY_FILTER_USE_KEY,
        );

        self::assertCount(1, $builtInCalls);

        foreach ($builtInCalls as $builtInCall) {
            self::assertArrayHasKey('cpu', $builtInCall);
            self::assertArrayHasKey('mu', $builtInCall);
        }
    }


    /**
     * @return array<int, string>
     */
    private function profileFiles(): array
    {
        $files = glob($this->profilesDir . '*.json');

        return is_array($files) ? $files : [];
    }


    /**
     * Removes every file in the directory, then the directory.
     *
     * A glob would miss the names a mutant can write (no ".json" ending, or a leading dot).
     */
    private function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);

        foreach (is_array($entries) ? array_diff($entries, ['.', '..']) : [] as $entry) {
            unlink($directory . $entry);
        }

        rmdir($directory);
    }
}
