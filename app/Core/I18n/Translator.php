<?php

declare(strict_types=1);

namespace NovaNuke\Core\I18n;

use JsonException;
use RuntimeException;

final class Translator
{
    private string $locale;
    private readonly string $fallbackLocale;

    /** @var array<string,string> */
    private array $directories = [];

    /** @var array<string,array<string,string>> */
    private array $catalogues = [];
    private ?string $owner=null;/** @var array<string,array<string,array<string,mixed>>> */private array$changes=[];
    public function beginOwner(string$o):void{$this->owner=$o;}public function endOwner():void{$this->owner=null;}public function commitOwner(string$o):void{unset($this->changes[$o]);}
    public function removeOwner(string$o):void{foreach($this->changes[$o]??[]as$n=>$x){if(($this->directories[$n]??null)!==$x['after'])continue;if($x['exists'])$this->directories[$n]=$x['directory'];else unset($this->directories[$n]);if($x['catalogue_exists'])$this->catalogues[$n]=$x['catalogue'];else unset($this->catalogues[$n]);}unset($this->changes[$o]);}

    public function __construct(
        string $locale,
        string $fallbackLocale,
        string $coreDirectory,
    ) {
        $this->locale = $this->validLocale($locale) ? $locale : 'en';
        $this->fallbackLocale = $this->validLocale($fallbackLocale) ? $fallbackLocale : 'en';
        $this->addNamespace('core', $coreDirectory);
    }

    public function setLocale(string $locale): void
    {
        $this->assertLocale($locale);
        $this->locale = $locale;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function addNamespace(string $namespace, string $directory): void
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,99}$/', $namespace)) {
            throw new RuntimeException('Translation namespaces must use lowercase letters, numbers and hyphens.');
        }
        $real = realpath($directory);
        if ($real === false || ! is_dir($real)) {
            return;
        }
        if($this->owner!==null&&!isset($this->changes[$this->owner][$namespace]))$this->changes[$this->owner][$namespace]=['exists'=>isset($this->directories[$namespace]),'directory'=>$this->directories[$namespace]??null,'catalogue_exists'=>isset($this->catalogues[$namespace]),'catalogue'=>$this->catalogues[$namespace]??null,'after'=>$real];
        $this->directories[$namespace] = $real;
        unset($this->catalogues[$namespace]);
    }

    /** @param array<string,scalar|null> $parameters */
    public function translate(string $key, array $parameters = []): string
    {
        [$namespace, $messageKey] = str_contains($key, '::') ? explode('::', $key, 2) : ['core', $key];
        if (! isset($this->directories[$namespace]) || ! preg_match('/^[a-z0-9][a-z0-9_.-]{0,190}$/', $messageKey)) {
            return $key;
        }

        $catalogue = $this->catalogue($namespace);
        $message = $catalogue[$this->locale][$messageKey]
            ?? $catalogue[$this->fallbackLocale][$messageKey]
            ?? $key;
        $replacements = [];
        foreach ($parameters as $name => $value) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name) && ($value === null || is_scalar($value))) {
                $replacements['{' . $name . '}'] = $value === null ? '' : (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /** @return array<string,array<string,string>> */
    private function catalogue(string $namespace): array
    {
        if (isset($this->catalogues[$namespace])) {
            return $this->catalogues[$namespace];
        }
        $catalogue = [];
        foreach (array_unique([$this->fallbackLocale, $this->locale]) as $locale) {
            $path = $this->directories[$namespace] . '/' . $locale . '.json';
            if (! is_file($path)) {
                $catalogue[$locale] = [];
                continue;
            }
            try {
                $decoded = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException("Invalid {$namespace} translation catalogue: {$locale}.", 0, $error);
            }
            if (! is_array($decoded)) {
                throw new RuntimeException("Translation catalogue must contain an object: {$namespace}/{$locale}.");
            }
            $catalogue[$locale] = [];
            foreach ($decoded as $key => $message) {
                if (is_string($key) && is_string($message)) {
                    $catalogue[$locale][$key] = $message;
                }
            }
        }

        return $this->catalogues[$namespace] = $catalogue;
    }

    private function assertLocale(string $locale): void
    {
        if (! $this->validLocale($locale)) {
            throw new RuntimeException('Invalid translation locale.');
        }
    }

    private function validLocale(string $locale): bool
    {
        return preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $locale) === 1;
    }
}
