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
 * @covers \MarjovanLier\XhprofTrace\Trace::getAllProfileFiles
 * @covers \MarjovanLier\XhprofTrace\Trace::setProfilesDir
 */
final class GetAllProfileFilesTest extends TestCase
{
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
     * @throws ReflectionException
     */
    public function testListsOnlyTheFilesTraceSaves(): void
    {
        $profile = $this->profilesDir . '1792832673.fa60c82397190a01.application.json';
        touch($profile);
        touch($this->profilesDir . 'composer.json');

        self::assertSame([$profile], $this->callGetAllProfileFiles());
    }


    /**
     * @throws ReflectionException
     */
    private function callGetAllProfileFiles(): mixed
    {
        $reflectionMethod = (new ReflectionClass(Trace::class))->getMethod('getAllProfileFiles');

        return $reflectionMethod->invoke(null);
    }
}
