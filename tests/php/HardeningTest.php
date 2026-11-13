<?php

declare(strict_types=1);

use PyHP\Console\InfoCommand;
use PyHP\Exception\PythonException;
use PyHP\Setup\CommandRunner;
use PyHP\Setup\Installer;
use PyHP\Setup\ProcessRunner;
use PyHP\Setup\Redact;
use PyHP\Setup\SetupConfig;
use PyHP\Setup\VirtualEnv;

/*
 * Fixes from the 1.0.0-rc1 review (docs/tasks/v1-rc1-review.md): `pyhp info` must
 * not run Python files from the project, output must not end a session, and
 * credentials in requirement URLs must not be printed.
 */

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-hardening-'.bin2hex(random_bytes(4));
    mkdir($this->root);
    $this->previousEnv = getenv('PYHP_PYTHON');
    putenv('PYHP_PYTHON');
    // Runs `pyhp info`; returns [exit code, output] with "\n" line endings.
    $this->info = function (CommandRunner $runner, bool $json = false): array {
        $output = new ArrayObject;
        $code = (new InfoCommand($runner, function (string $text) use ($output): void {
            $output->append($text);
        }, fn (string $python): ?string => null))->run($this->root, $json);

        return [$code, str_replace("\r\n", "\n", implode('', $output->getArrayCopy()))];
    };
});

afterEach(function (): void {
    putenv($this->previousEnv === false ? 'PYHP_PYTHON' : 'PYHP_PYTHON='.$this->previousEnv);
    removeDirectory($this->root);
});

// -- finding 2: pyhp info ran Python files from the project -------------------- //

test('pyhp info does not import Python files lying in the project', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['six']]]]));
    $marker = $this->root.DIRECTORY_SEPARATOR.'ran.txt';
    foreach (['json', 'platform', 'os'] as $module) {
        file_put_contents($this->root."/{$module}.py", 'open('.var_export($marker, true).', "a").write("'.$module.'")'."\n");
    }
    putenv('PYHP_PYTHON='.testPython());

    [, $text] = ($this->info)(new ProcessRunner);

    expect(file_exists($marker) ? file_get_contents($marker) : 'nothing ran')->toBe('nothing ran')
        ->and($text)->toMatch('/\n  Version +3\.\d+\.\d+ \(/')
        ->and($text)->not->toContain('could not be started');
});

// -- finding 3: output could end a healthy session ------------------------------ //

test('output larger than max_message_bytes is cut, and the session goes on', function (): void {
    $py = testSession(['max_message_bytes' => 1024]);

    $py->exec("print('A' * 1100)");
    $output = $py->takeOutput();

    expect($output)->toStartWith('[pyhp: the first ')
        ->and($output)->toContain(' characters of output were cut to fit max_message_bytes]')
        ->and($output)->toEndWith(str_repeat('A', 100)."\n")
        ->and($py->isOpen())->toBeTrue()
        ->and($py->eval('6 * 7'))->toBe(42);
});

test('printing text that is not valid Unicode does not stop the worker', function (): void {
    $py = testSession();

    $py->exec("print('a\\ud800b')");

    expect($py->takeOutput())->toBe("a?b\n")
        ->and(fn () => $py->exec("raise ValueError('\\udfff')"))->toThrow(PythonException::class, 'ValueError: ?')
        ->and($py->eval('1 + 1'))->toBe(2);
});

// -- finding 5: credentials in requirement URLs were printed --------------------- //

test('credentials in URLs are hidden', function (string $text, string $shown): void {
    expect(Redact::urls($text))->toBe($shown);
})->with([
    'user and password' => ['pkg @ https://user:s3cret@example.com/pkg.whl', 'pkg @ https://***@example.com/pkg.whl'],
    'token only' => ['--extra-index-url https://ghp_token@pypi.example.com/simple', '--extra-index-url https://***@pypi.example.com/simple'],
    'git URL' => ['pkg @ git+https://oauth2:tok@gitlab.com/a/b.git@v1.0', 'pkg @ git+https://***@gitlab.com/a/b.git@v1.0'],
    'two URLs' => ['https://a:b@one.example https://c@two.example', 'https://***@one.example https://***@two.example'],
    'inside JSON' => ['"pkg @ https://u:p@h.example/x"', '"pkg @ https://***@h.example/x"'],
    'no credentials' => ['pkg @ https://example.com/pkg.whl and git@github.com:a/b', 'pkg @ https://example.com/pkg.whl and git@github.com:a/b'],
    'plain requirement' => ['pandas>=2', 'pandas>=2'],
]);

test('pyhp info hides credentials in requirement URLs, in text and JSON', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['pkg @ https://user:s3cret@example.com/pkg.whl']]]]));

    [, $text] = ($this->info)(new FakeCommandRunner);
    [, $json] = ($this->info)(new FakeCommandRunner, json: true);

    expect($text)->toContain('pkg @ https://***@example.com/pkg.whl')
        ->and($text)->not->toContain('s3cret')
        ->and($json)->toContain('pkg @ https://***@example.com/pkg.whl')
        ->and($json)->not->toContain('s3cret');
});

test('pyhp setup shows commands without credentials but runs them with the real URL', function (): void {
    $lines = new ArrayObject;
    $python = VirtualEnv::python($this->root.DIRECTORY_SEPARATOR.'.venv', 'Linux');
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: function (array $command) use ($python): void {
        if (in_array('venv', $command, true)) {
            mkdir(dirname($python), 0777, true);
            touch($python);
        }
    });

    (new Installer($runner, function (string $line) use ($lines): void {
        $lines->append($line);
    }, 'Linux'))->install(new SetupConfig(requirements: ['pkg @ https://user:s3cret@example.com/pkg.whl']), $this->root);

    $shown = implode("\n", $lines->getArrayCopy());
    expect($shown)->toContain('https://***@example.com/pkg.whl')
        ->and($shown)->not->toContain('s3cret')
        ->and(implode("\n", $runner->commandLines()))->toContain('https://user:s3cret@example.com/pkg.whl');
});
