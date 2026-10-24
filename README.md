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
composer require marjovanlier/xhproftrace
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

- `enableXhprof()`: Enables XHProf profiling.
- `disableXhprof()`: Disables XHProf profiling and saves the profiling data to a file.
- `displayReportCLI()`: Generates a report from the profiling data and displays it in the console.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.