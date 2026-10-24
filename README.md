# XhprofTrace

XhprofTrace is a PHP library that provides an interface for the XHProf profiling tool. It allows you to enable and
disable profiling, and generate and display a report of the profiling data.

## Requirements

- PHP 8.3 or higher
- XHProf PHP extension

## Installation

Before installing the XhprofTrace library, you need to ensure that the XHProf PHP extension is installed and enabled. If
you are using Docker, you can add the following lines to your Dockerfile:

```dockerfile
RUN pecl install xhprof && docker-php-ext-enable xhprof
``` 

```bash
composer require marjovanlier/xhprof-trace
```

## Usage

Here is a basic example of how to use the library:

```php
use MarjovanLier\XhprofTrace\Trace;

// Enable XHProf profiling
Trace::enableXhprof();

// Your application code here...

// Disable XHProf profiling
Trace::disableXhprof();

// Display the profiling report in the console
Trace::displayReportCLI();
```

## Documentation

The library provides the following static methods:

- `setProfilesDir(string $path)`: Sets the folder for the profile files. The default is `/var/www/html/profiles/`.
- `enableXhprof()`: Enables XHProf profiling.
- `disableXhprof()`: Disables XHProf profiling and saves the profiling data to a file in the profiles folder. It
  creates the folder when it does not exist, and saves nothing when profiling was not enabled.
- `displayReportCLI()`: Displays a table of every profiled function, summed over all saved runs: the number of
  calls, the inclusive and exclusive wall time and the exclusive CPU time in milliseconds, the exclusive memory change
  in kilobytes, and the number of runs. "Exclusive" is the function's own share, without the functions it called. The
  memory change is negative when a function frees more memory than it takes. The function with the highest exclusive
  wall time is the last row. A recursive function keeps the names xhprof gives each depth (`fib`, `fib@1`, `fib@2`),
  so it has one row per depth.

The report adds up every profile file in the folder, also those from earlier sessions. Empty the folder to compare a
new set of runs on its own. Files that hold no profile data are skipped.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.