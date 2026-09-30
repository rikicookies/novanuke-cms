<?php

declare(strict_types=1);

namespace NovaNuke\Core\Developer;

use JsonException;
use NovaNuke\Core\ModuleApi;
use NovaNuke\Core\Modules\ModuleCompatibilityChecker;
use NovaNuke\Core\Modules\ModuleManifest;
use NovaNuke\Core\Version;

final class ModuleDiagnostic
{
    /** @return list<array{level:string,passed:bool,label:string,detail:string}> */
    public function inspect(string $modulePath, string $modulesPath): array
    {
        $checks = [];
        $modulePath = rtrim($modulePath, '/\\');
        $modulesPath = rtrim($modulesPath, '/\\');

        if (! is_dir($modulePath)) {
            return [$this->fail('Module directory', "Directory not found: {$modulePath}")];
        }

        $manifestFile = $modulePath . '/module.json';
        if (! is_file($manifestFile)) {
            return [$this->fail('Manifest', 'module.json is missing.')];
        }

        try {
            $data = json_decode((string) file_get_contents($manifestFile), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            return [$this->fail('Manifest JSON', $error->getMessage())];
        }
        if (! is_array($data)) {
            return [$this->fail('Manifest JSON', 'module.json must contain an object.')];
        }

        try {
            $manifest = ModuleManifest::fromArray($data, $modulePath);
            $checks[] = $this->pass('Manifest contract', "{$manifest->slug} {$manifest->version}; API {$manifest->apiVersion}.");
        } catch (\Throwable $error) {
            return [$this->fail('Manifest contract', $error->getMessage())];
        }

        $checks[] = $this->compatibility($manifest, $modulesPath);
        $checks[] = $this->provider($manifest);
        $checks[] = $this->migrations($manifest);
        foreach ($this->catalogues($manifest) as $check) $checks[] = $check;
        $checks[] = $this->readme($manifest);
        $checks[] = $this->routeNames($manifest);

        return $checks;
    }

    /** @return array{level:string,passed:bool,label:string,detail:string} */
    private function compatibility(ModuleManifest $manifest, string $modulesPath): array
    {
        $available = [];
        foreach ($manifest->dependencies as $slug => $minimumVersion) {
            $found = null;
            foreach (glob($modulesPath . '/*/module.json') ?: [] as $file) {
                try {
                    $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
                    if (! is_array($data)) continue;
                    $candidate = ModuleManifest::fromArray($data, dirname($file));
                } catch (\Throwable) {
                    continue;
                }
                if ($candidate->slug === $slug) {
                    $found = $candidate;
                    break;
                }
            }
            if ($found !== null) {
                $available[$slug] = ['installed_version' => $found->version];
            }
        }

        $result = (new ModuleCompatibilityChecker(Version::CURRENT, PHP_VERSION, ModuleApi::VERSION))->check($manifest, $available);
        return $result->compatible
            ? $this->pass('Declared compatibility', 'CMS, PHP, API and on-disk dependency versions satisfy the manifest.')
            : $this->fail('Declared compatibility', (string) $result->reason);
    }

    /** @return array{level:string,passed:bool,label:string,detail:string} */
    private function provider(ModuleManifest $manifest): array
    {
        $prefix = 'Modules\\' . basename($manifest->path) . '\\';
        $relative = str_starts_with($manifest->provider, $prefix)
            ? substr($manifest->provider, strlen($prefix))
            : '';
        $file = $manifest->path . '/' . str_replace('\\', '/', $relative) . '.php';
        if ($relative === '' || ! is_file($file)) {
            return $this->fail('Provider', 'Provider file was not found at the PSR-4 path: ' . $file);
        }
        $source = (string) file_get_contents($file);
        $short = basename(str_replace('\\', '/', $manifest->provider));
        if (! preg_match('/\bclass\s+' . preg_quote($short, '/') . '\b/', $source)) {
            return $this->fail('Provider', "Provider class {$short} was not found in its declared file.");
        }
        if (! str_contains($source, 'ModuleInterface')) {
            return $this->fail('Provider', 'Provider does not statically reference ModuleInterface.');
        }
        return $this->pass('Provider', 'Provider file/class and ModuleInterface reference are present without executing module code.');
    }

    /** @return array{level:string,passed:bool,label:string,detail:string} */
    private function migrations(ModuleManifest $manifest): array
    {
        $directory = $manifest->path . '/database/migrations';
        if (! is_dir($directory)) {
            return $this->warn('Migrations', 'No database/migrations directory; valid for modules without schema.');
        }
        $files = glob($directory . '/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            if (! preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/', basename($file))) {
                return $this->fail('Migrations', 'Invalid migration filename: ' . basename($file));
            }
            $source = (string) file_get_contents($file);
            if (! str_contains($source, 'Migration')) {
                return $this->fail('Migrations', 'Migration does not statically reference the Migration contract: ' . basename($file));
            }
        }
        return $files === []
            ? $this->pass('Migrations', 'Migration directory is present and empty.')
            : $this->pass('Migrations', count($files) . ' migration file(s) use the expected naming/contract pattern.');
    }

    /** @return list<array{level:string,passed:bool,label:string,detail:string}> */
    private function catalogues(ModuleManifest $manifest): array
    {
        $directory = $manifest->path . '/language';
        if (! is_dir($directory)) {
            return [$this->warn('Catalogues', 'No language directory. Add en.json/es.json if the module exposes user-facing strings.')];
        }
        $files = glob($directory . '/*.json') ?: [];
        if ($files === []) {
            return [$this->warn('Catalogues', 'Language directory is empty.')];
        }
        $decoded = [];
        foreach ($files as $file) {
            try {
                $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                return [$this->fail('Catalogues', basename($file) . ': ' . $error->getMessage())];
            }
            if (! is_array($data)) return [$this->fail('Catalogues', basename($file) . ' must contain a JSON object.')];
            $decoded[basename($file)] = $data;
        }
        if (isset($decoded['en.json'], $decoded['es.json'])) {
            $en = array_keys($decoded['en.json']); sort($en);
            $es = array_keys($decoded['es.json']); sort($es);
            if ($en !== $es) return [$this->fail('Catalogues', 'en.json and es.json top-level keys are not in parity.')];
        }
        return [$this->pass('Catalogues', count($files) . ' JSON catalogue(s) parsed successfully' . (isset($decoded['en.json'], $decoded['es.json']) ? ' with EN/ES key parity.' : '.'))];
    }

    /** @return array{level:string,passed:bool,label:string,detail:string} */
    private function readme(ModuleManifest $manifest): array
    {
        return is_file($manifest->path . '/README.md')
            ? $this->pass('README', 'Module documentation is present.')
            : $this->warn('README', 'README.md is missing; recommended for distributable modules.');
    }

    /** @return array{level:string,passed:bool,label:string,detail:string} */
    private function routeNames(ModuleManifest $manifest): array
    {
        $bad = [];
        $count = 0;
        foreach (glob($manifest->path . '/src/*.php') ?: [] as $file) {
            foreach ($this->staticallyDeclaredRouteNames((string) file_get_contents($file)) as $name) {
                $count++;
                if (! str_starts_with($name, $manifest->slug . '.')) $bad[] = $name;
            }
        }
        if ($bad !== []) {
            return $this->fail('Named routes', 'Route names must start with ' . $manifest->slug . '.: ' . implode(', ', array_unique($bad)));
        }
        return $this->pass('Named routes', $count === 0
            ? 'No literal named routes were statically detected.'
            : "{$count} literal named route(s) remain inside the {$manifest->slug}. namespace.");
    }

    /** @return list<string> */
    private function staticallyDeclaredRouteNames(string $source): array
    {
        $tokens = token_get_all($source);
        $names = [];
        $count = count($tokens);
        for ($i = 0; $i < $count - 3; $i++) {
            if ($tokens[$i] !== '->' && ! (is_array($tokens[$i]) && $tokens[$i][0] === T_OBJECT_OPERATOR)) continue;
            $previous = $i - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) $previous--;
            $receiver = $previous >= 0 ? $tokens[$previous] : null;
            $isRouterReceiver = (is_array($receiver) && $receiver[0] === T_STRING && strtolower($receiver[1]) === 'router')
                || (is_array($receiver) && $receiver[0] === T_VARIABLE && strtolower($receiver[1]) === '$router');
            if (! $isRouterReceiver) continue;
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if ($j >= $count || ! is_array($tokens[$j]) || ! in_array(strtolower($tokens[$j][1]), ['get', 'post', 'put', 'patch', 'delete'], true)) continue;
            $j++;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if (($tokens[$j] ?? null) !== '(') continue;

            $depth = 1;
            $lastTopLevelString = null;
            for ($j++; $j < $count && $depth > 0; $j++) {
                $token = $tokens[$j];
                if ($token === '(' || $token === '[' || $token === '{') { $depth++; continue; }
                if ($token === ')' || $token === ']' || $token === '}') { $depth--; continue; }
                if ($depth === 1 && is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $value = substr($token[1], 1, -1);
                    $quote = $token[1][0];
                    $lastTopLevelString = $quote === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $value) : stripcslashes($value);
                }
            }
            if ($lastTopLevelString !== null && preg_match('/^[a-z][a-z0-9.-]*$/', $lastTopLevelString)) {
                $names[] = $lastTopLevelString;
            }
            $i = max($i, $j - 1);
        }
        return $names;
    }

    /** @return array{level:string,passed:bool,label:string,detail:string} */
    private function pass(string $label, string $detail): array { return ['level' => 'PASS', 'passed' => true, 'label' => $label, 'detail' => $detail]; }
    /** @return array{level:string,passed:bool,label:string,detail:string} */
    private function warn(string $label, string $detail): array { return ['level' => 'WARN', 'passed' => true, 'label' => $label, 'detail' => $detail]; }
    /** @return array{level:string,passed:bool,label:string,detail:string} */
    private function fail(string $label, string $detail): array { return ['level' => 'FAIL', 'passed' => false, 'label' => $label, 'detail' => $detail]; }
}
