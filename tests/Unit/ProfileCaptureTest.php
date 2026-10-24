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
 * Runs the real xhprof extension, so it needs ext-xhprof loaded.
 *
 * @internal
 *
 * @covers \MarjovanLier\XhprofTrace\Trace::createProfilesDir
 * @covers \MarjovanLier\XhprofTrace\Trace::disableXhprof
 * @covers \MarjovanLier\XhprofTrace\Trace::enableXhprof
 * @covers \MarjovanLier\XhprofTrace\Trace::setProfilesDir
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

        $this->removeDirectory($this->profilesDir);

        // A mutant that drops the closing slash saves beside the folder instead of in it.
        $besideFolder = glob(rtrim($this->profilesDir, '/') . '*.application.json');

        foreach (is_array($besideFolder) ? $besideFolder : [] as $file) {
            unlink($file);
        }

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

        $files = $this->profileFiles($this->profilesDir);

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

        [$file] = $this->profileFiles($this->profilesDir);

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
     * @throws JsonException
     */
    public function testSavesIntoAFolderThatDoesNotExistYet(): void
    {
        $folder = $this->profilesDir . 'new/deeper/';
        Trace::setProfilesDir($folder);

        Trace::enableXhprof();
        Trace::disableXhprof();

        self::assertCount(1, $this->profileFiles($folder));
    }


    /**
     * @throws JsonException
     */
    public function testSavesInsideAFolderGivenWithoutTrailingSlash(): void
    {
        Trace::setProfilesDir(rtrim($this->profilesDir, '/'));

        Trace::enableXhprof();
        Trace::disableXhprof();

        self::assertCount(1, $this->profileFiles($this->profilesDir));
    }


    /**
     * @throws JsonException
     */
    public function testEmptyFolderMeansTheCurrentFolder(): void
    {
        Trace::setProfilesDir('');

        Trace::enableXhprof();
        Trace::disableXhprof();

        self::assertCount(1, $this->profileFiles($this->profilesDir . 'cwd/'));
    }


    /**
     * @throws ReflectionException
     */
    public function testCreatingAFolderThatAnotherProcessJustMadeRaisesNoWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = [$level, $message];

            return true;
        });

        try {
            // The folder already exists, as when a second process created it a moment earlier.
            (new ReflectionClass(Trace::class))->getMethod('createProfilesDir')->invoke(null);

            // This one must still reach the handler above, so the method put back the handler it replaced.
            trigger_error('after', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }

        self::assertSame([[E_USER_WARNING, 'after']], $warnings);
    }


    /**
     * @throws JsonException
     */
    public function testDisableWithoutEnableSavesNothing(): void
    {
        Trace::disableXhprof();

        self::assertSame([], $this->profileFiles($this->profilesDir));
    }


    /**
     * @return array<int, string>
     */
    private function profileFiles(string $folder): array
    {
        $files = glob($folder . '*.json');

        return is_array($files) ? $files : [];
    }


    /**
     * Removes everything in the directory, then the directory.
     *
     * A glob would miss the names a mutant can write (no ".json" ending, or a leading dot).
     */
    private function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);

        foreach (is_array($entries) ? array_diff($entries, ['.', '..']) : [] as $entry) {
            $path = $directory . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path . '/');

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
