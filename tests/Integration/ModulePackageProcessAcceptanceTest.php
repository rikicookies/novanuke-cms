<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use NovaNuke\Core\Modules\ModuleCompatibilityChecker;
use NovaNuke\Core\Modules\ModulePackageInstaller;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ModulePackageProcessAcceptanceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/novanuke-module-process-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/modules/Fixture/src', 0770, true);
        mkdir($this->root . '/storage/private', 0770, true);
        file_put_contents($this->root . '/modules/Fixture/module.json', $this->manifest('1.0.0'));
        file_put_contents($this->root . '/modules/Fixture/src/FixtureModule.php', '<?php namespace Modules\\Fixture\\src;');
        file_put_contents($this->root . '/modules/Fixture/old.txt', 'old');
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->root);
        }
    }

    public function testThreeProcessesRespectStableLockAcrossHandoff(): void
    {
        $archive = $this->archive();
        $firstReady = $this->root . '/first-ready';
        $firstHold = $this->root . '/first-hold';
        $secondContended = $this->root . '/second-contended';
        $secondReady = $this->root . '/second-ready';
        $secondHold = $this->root . '/second-hold';
        file_put_contents($firstHold, 'hold');
        file_put_contents($secondHold, 'hold');
        $script = $this->script();
        $first = $this->process($script, [$this->root, $archive, $firstReady, $firstReady, $firstHold, 'hold']);
        $this->waitFor($firstReady, $first['output']);
        $second = $this->process($script, [$this->root, $archive, $secondContended, $secondReady, $secondHold, 'retry']);
        $this->waitFor($secondContended, $second['output']);
        self::assertFileExists($this->root . '/storage/private/module-updates/fixture.lock');

        file_put_contents($firstHold, 'release');
        $this->waitFor($secondReady, $second['output']);

        $third = $this->process($script, [$this->root, $archive, $this->root . '/third-attempt', $this->root . '/unused-ready', $this->root . '/unused-hold', 'once']);
        $thirdResult = $this->finish($third, 10);
        self::assertNotSame(0, $thirdResult['code']);
        self::assertStringContainsString('already in progress', $thirdResult['output']);

        file_put_contents($secondHold, 'release');
        $firstResult = $this->finish($first, 10);
        $secondResult = $this->finish($second, 10);
        self::assertSame(0, $firstResult['code'], $firstResult['output']);
        self::assertSame(0, $secondResult['code'], $secondResult['output']);
        self::assertSame('new', file_get_contents($this->root . '/modules/Fixture/new.txt'));
        self::assertFileDoesNotExist($this->root . '/modules/Fixture/old.txt');
        self::assertSame([], glob($this->root . '/storage/private/module-updates/fixture/*') ?: []);
        self::assertFileExists($this->root . '/storage/private/module-updates/fixture.lock');
    }

    public function testInterruptedChildLeavesDiscoverableRecoveryState(): void
    {
        $archive = $this->archive();
        $script = $this->script();
        $process = $this->process($script, [$this->root, $archive, $this->root . '/unused-signal', $this->root . '/unused-ready', $this->root . '/unused-hold', 'interrupt']);
        $result = $this->finish($process, 10);

        self::assertSame(23, $result['code'], $result['output']);
        self::assertFileExists($this->root . '/modules/Fixture/new.txt');
        self::assertFileDoesNotExist($this->root . '/modules/Fixture/old.txt');
        $states = glob($this->root . '/storage/private/module-updates/fixture/*/operation.json') ?: [];
        self::assertCount(1, $states);
        $state = json_decode((string) file_get_contents($states[0]), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('source-published', $state['state']);
        self::assertFileExists(dirname($states[0]) . '/previous/old.txt');
    }

    private function script(): string
    {
        $script = $this->root . '/child.php';
        file_put_contents($script, <<<'PHP'
<?php
declare(strict_types=1);
require $argv[8] . '/autoload.php';
use NovaNuke\Core\Modules\ModuleCompatibilityChecker;
use NovaNuke\Core\Modules\ModulePackageInstaller;
$root=$argv[1];$archive=$argv[2];$signal=$argv[3];$ready=$argv[4];$hold=$argv[5];$mode=$argv[6];$done=$argv[7];
$installer=new ModulePackageInstaller($root . '/modules',new ModuleCompatibilityChecker('0.4.0-beta.1'));
for($attempt=0;;$attempt++) {
 try {
  $installer->upgrade($archive,['fixture'=>['installed_version'=>'1.0.0']],static function()use($ready,$hold,$mode):void{
   if($mode==='interrupt')exit(23);
   file_put_contents($ready,'ready');
   if($mode==='hold'||$mode==='retry'){while((string)@file_get_contents($hold)==='hold')usleep(10000);}
  });
  file_put_contents($done,'success');
  exit(0);
 } catch (Throwable $error) {
  if($mode==='retry' && str_contains($error->getMessage(),'already in progress') && $attempt<100){file_put_contents($signal,'contended');usleep(20000);continue;}
  file_put_contents($done,$error->getMessage());
  exit(17);
 }
}
PHP);
        return $script;
    }

    /** @return array{process:resource, output:string} */
    private function process(string $script, array $args): mixed
    {
        $autoload = dirname(__DIR__, 2) . '/vendor';
        $output = $this->root . '/child-' . bin2hex(random_bytes(3)) . '.log';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' ' . implode(' ', array_map('escapeshellarg', [...$args, $output, $autoload]));
        $process = proc_open($command, [['file', $output, 'ab'], ['file', $output, 'ab'], ['file', $output, 'ab']], $unused);
        self::assertIsResource($process);
        return ['process' => $process, 'output' => $output];
    }

    /** @param array{process:resource,output:string} $job */
    private function finish(array $job, int $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        $completed = false;
        do {
            $status = proc_get_status($job['process']);
            $output = (string) @file_get_contents($job['output']);
            if (! $status['running'] || str_contains($output, 'success') || str_contains($output, 'already in progress')) {
                $completed = true;
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! $completed) {
            proc_terminate($job['process']);
            proc_close($job['process']);
            self::fail('Child process exceeded the bounded timeout: ' . (string) @file_get_contents($job['output']));
        }
        $code = proc_close($job['process']);
        return ['code' => $code, 'output' => (string) @file_get_contents($job['output'])];
    }

    private function waitFor(string $path, ?string $output = null): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path) && microtime(true) < $deadline) usleep(10000);
        self::assertFileExists($path, $output === null ? '' : (string) @file_get_contents($output));
    }

    private function archive(): string
    {
        $source = $this->root . '/package/Fixture';
        mkdir($source . '/src', 0770, true);
        file_put_contents($source . '/module.json', $this->manifest('2.0.0'));
        file_put_contents($source . '/src/FixtureModule.php', '<?php namespace Modules\\Fixture\\src;');
        file_put_contents($source . '/new.txt', 'new');
        $archive = $this->root . '/upgrade.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname($source), \FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile()) $zip->addFile($file->getPathname(), str_replace('\\', '/', substr($file->getPathname(), strlen(dirname($source)) + 1)));
        $zip->close();
        return $archive;
    }

    private function manifest(string $version): string
    {
        return json_encode(['name'=>'Fixture','slug'=>'fixture','version'=>$version,'provider'=>'Modules\\Fixture\\src\\FixtureModule','cms_min_version'=>'0.4.0-beta.1','php_min_version'=>'8.3.0','permissions'=>[]], JSON_THROW_ON_ERROR);
    }
}
