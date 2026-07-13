# CI quality gates

The `API quality gates` workflow runs for every pull request and for pushes to `main` with read-only repository permissions and cancellation of superseded runs.

`PHP 8.3 quality` validates Composer metadata, installs the lock file, checks PHP syntax and Pint formatting, and runs the complete PHPUnit suite. PHPUnit writes a JUnit report that remains available as a workflow artifact for 14 days, while the normal test output and problem matcher expose failures directly in the job log.

`Locked dependency audit` runs `composer audit --locked` against production and development packages. Known vulnerabilities or malware fail the job; abandoned packages are reported without failing the baseline.

Repository branch rules for `main` must require both named jobs before merge. Local equivalents are `composer lint`, `composer test`, and `composer check`; the Windows environment may need Composer invoked by its resolved executable rather than relying on `PATH`.
