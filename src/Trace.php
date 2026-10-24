<?php

declare(strict_types=1);

namespace MarjovanLier\XhprofTrace;

use JsonException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * This class provides methods for enabling and disabling XHProf, a hierarchical profiler for PHP.
 * It also provides methods for generating and displaying a report of the profiling data.
 */
final class Trace
{
    /**
     * Prefixes of classes to be excluded from the report.
     *
     * @var string[]
     */
    private const EXCLUDED_PREFIXES = [
        'Zend_',
        'Composer\\',
        'PHPStan\\',
    ];

    /**
     * Totals of a function before any of its calls are counted.
     */
    private const EMPTY_ROW = [
        'ct' => 0,
        'wt' => 0,
        'excl_wt' => 0,
        'excl_cpu' => 0,
        'excl_mu' => 0,
    ];

    /**
     * Directory where profile data files are stored.
     */
    private static string $profilesDir = '/var/www/html/profiles/';


    /**
     * Sets the directory where profile data files are stored.
     *
     * @param string $path The path to the directory.
     *
     * @api
     *
     * @noinspection PhpUnused
     */
    public static function setProfilesDir(string $path): void
    {
        // The file name is appended to this path, so without a closing slash it would land beside the folder.
        self::$profilesDir = match (true) {
            $path === '' => './',
            str_ends_with($path, '/') => $path,
            default => $path . '/',
        };
    }


    /**
     * Enables XHProf profiling.
     *
     * @api
     *
     * @noinspection PhpUnused
     */
    public static function enableXhprof(): void
    {
        // Built-in functions stay in the profile, so time spent in a database or HTTP call is visible.
        xhprof_enable(XHPROF_FLAGS_MEMORY + XHPROF_FLAGS_CPU);
    }


    /**
     * Disables XHProf profiling and saves the profiling data to a file.
     *
     * @throws JsonException If an error occurs during JSON encoding.
     *
     * @api
     *
     * @noinspection PhpUnused
     */
    public static function disableXhprof(): void
    {
        // Stop first, so the calls that build the file name are not in the profile.
        $profile = xhprof_disable();

        // xhprof returns null when profiling was never enabled, although its stubs say array;
        // a file for that call would only hold "null".
        /** @psalm-suppress TypeDoesNotContainNull */
        if ($profile === null) { // @phpstan-ignore identical.alwaysFalse
            return;
        }

        if (!is_dir(self::$profilesDir)) {
            self::createProfilesDir();
        }

        // The random part keeps two runs in the same second from writing to the same file.
        $filename = self::$profilesDir . time() . '.' . bin2hex(random_bytes(8)) . '.application.json';
        file_put_contents($filename, json_encode($profile, JSON_THROW_ON_ERROR));
    }


    /**
     * Creates the profiles directory.
     *
     * Two processes can find the directory missing at the same moment. The one that loses the race gets a
     * "File exists" warning from mkdir(), which frameworks often turn into an exception; that warning alone
     * is ignored. Any other failure, such as a denied permission, still reaches PHP's own error handler.
     */
    private static function createProfilesDir(): void
    {
        set_error_handler(static fn(): bool => is_dir(self::$profilesDir));
        mkdir(self::$profilesDir, recursive: true);
        restore_error_handler();
    }


    /**
     * Generates a report from the profiling data and displays it in the console.
     *
     * Times are in milliseconds and memory changes in kilobytes, summed over all runs. "Excl." is the
     * function's own share, without the functions it called. The highest exclusive wall time is last.
     *
     * @api
     *
     * @noinspection PhpUnused
     */
    public static function displayReportCLI(): void
    {
        $files = self::getAllProfileFiles();

        $aggregatedData = self::getAggregatedDataFromFiles($files);

        $consoleOutput = new ConsoleOutput();
        $table = new Table($consoleOutput);
        $table->setHeaders([
            'Function',
            'Calls',
            'Wall incl. (ms)',
            'Wall excl. (ms)',
            'CPU excl. (ms)',
            // xhprof records the change in memory, which is negative when a function frees more than it takes.
            'Memory change excl. (KB)',
            'Runs',
        ]);

        foreach ($aggregatedData as $name => $data) {
            $table->addRow([
                $name,
                $data['ct'],
                number_format($data['wt'] / 1000, 2),
                number_format($data['excl_wt'] / 1000, 2),
                number_format($data['excl_cpu'] / 1000, 2),
                number_format($data['excl_mu'] / 1024, 1),
                $data['runs'],
            ]);
        }

        $table->render();
    }


    /**
     * Retrieves the paths of the profile files disableXhprof() saved in the profiles directory.
     *
     * @return array<int, string> An array of absolute file paths.
     *
     * @psalm-return list<string>
     */
    private static function getAllProfileFiles(): array
    {
        // Other JSON files in the same folder (a composer.json, say) are not profiles.
        $files = glob(self::$profilesDir . '*.application.json', GLOB_NOSORT);

        return is_array($files) ? $files : [];
    }


    /**
     * Sums the per-function totals of every profile and sorts them by exclusive wall time, highest last.
     *
     * @param array<int, string> $files
     *
     * @return array<string, array{ct: int, wt: int, excl_wt: int, excl_cpu: int, excl_mu: int, runs: int}>
     */
    private static function getAggregatedDataFromFiles(array $files): array
    {
        $totals = [];

        foreach ($files as $file) {
            foreach (self::generateReport($file) as $name => $row) {
                $total = $totals[$name] ?? (self::EMPTY_ROW + ['runs' => 0]);
                $totals[$name] = [
                    'ct' => $total['ct'] + $row['ct'],
                    'wt' => $total['wt'] + $row['wt'],
                    'excl_wt' => $total['excl_wt'] + $row['excl_wt'],
                    'excl_cpu' => $total['excl_cpu'] + $row['excl_cpu'],
                    'excl_mu' => $total['excl_mu'] + $row['excl_mu'],
                    'runs' => $total['runs'] + 1,
                ];
            }
        }

        // The hottest functions end up last, just above the prompt.
        uasort(
            $totals,
            static fn(array $first, array $second): int => $first['excl_wt'] <=> $second['excl_wt'],
        );

        return $totals;
    }


    /**
     * Generates a report from a single profiling data file.
     *
     * @return array<string, array{ct: int, wt: int, excl_wt: int, excl_cpu: int, excl_mu: int}>
     */
    private static function generateReport(string $filename): array
    {
        if (!is_file($filename)) {
            return [];
        }

        $fileContents = file_get_contents($filename);
        if ($fileContents === false) {
            return [];
        }

        $data = json_decode($fileContents, true);

        // A file holding "null" (saved by an older version) or cut off mid-write has nothing to report.
        if (!is_array($data)) {
            return [];
        }

        /**
         * @var array<string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}> $data
         */
        return self::processDataForReport($data);
    }


    /**
     * Turns one profile into totals per function.
     *
     * xhprof stores one entry per caller and callee pair, so a function called from several places
     * is summed over all of them. Its exclusive ("excl_") figures are its inclusive figures minus
     * those of every function it called.
     *
     * @param array<string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}> $data
     *
     * @return array<string, array{ct: int, wt: int, excl_wt: int, excl_cpu: int, excl_mu: int}>
     *
     * @psalm-pure
     */
    private static function processDataForReport(array $data): array
    {
        $report = [];

        foreach ($data as $key => $values) {
            // Every key is "caller==>callee" except the root, "main()", which has no caller.
            $parts = explode('==>', $key);
            $callee = array_pop($parts);
            $caller = array_pop($parts);

            $report[$callee] ??= self::EMPTY_ROW;
            $report[$callee]['ct'] += $values['ct'];
            $report[$callee]['wt'] += $values['wt'];
            $report[$callee]['excl_wt'] += $values['wt'];
            $report[$callee]['excl_cpu'] += $values['cpu'];
            $report[$callee]['excl_mu'] += $values['mu'];

            if ($caller === null) {
                continue;
            }

            $report[$caller] ??= self::EMPTY_ROW;
            $report[$caller]['excl_wt'] -= $values['wt'];
            $report[$caller]['excl_cpu'] -= $values['cpu'];
            $report[$caller]['excl_mu'] -= $values['mu'];
        }

        // Excluded functions are dropped only now, so their time is still taken off their callers.
        return array_filter(
            $report,
            static fn(string $name): bool => !self::isExcludedClass($name),
            ARRAY_FILTER_USE_KEY,
        );
    }


    /**
     * Checks if a class should be excluded from the report based on its prefix.
     *
     * @param string $functionName Name of the function.
     *
     * @return bool Whether the class should be excluded.
     *
     * @psalm-pure
     */
    private static function isExcludedClass(string $functionName): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $excludedPrefix) {
            if (str_starts_with($functionName, $excludedPrefix)) {
                return true;
            }
        }

        return false;
    }
}
