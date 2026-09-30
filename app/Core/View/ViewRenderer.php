<?php

declare(strict_types=1);

namespace NovaNuke\Core\View;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use NovaNuke\Core\I18n\Translator;

final class ViewRenderer
{
    private readonly Environment $twig;
    private readonly FilesystemLoader $loader;
    private ?string $owner=null;/** @var array<string,array<string,array{before:list<string>,after:list<string>}>> */private array$paths=[];/** @var array<string,list<array{name:string,value:mixed}>> */private array$globals=[];
    public function beginOwner(string$o):void{$this->owner=$o;}public function endOwner():void{$this->owner=null;}
    public function removeOwner(string$o):void{foreach($this->paths[$o]??[]as$n=>$x)if($this->loader->getPaths($n)===$x['after'])$this->loader->setPaths($x['before'],$n);unset($this->paths[$o],$this->globals[$o]);}
    public function commitOwner(string$o):void{foreach($this->globals[$o]??[]as$g)$this->twig->addGlobal($g['name'],$g['value']);unset($this->paths[$o],$this->globals[$o]);}

    public function __construct(string $viewPath, string $cachePath, bool $debug, ?Translator $translator = null)
    {
        $this->loader = new FilesystemLoader($viewPath);
        $this->twig = new Environment($this->loader, [
            'cache' => $debug ? false : $cachePath,
            'debug' => $debug,
            // Release patches may update Twig templates while keeping the same
            // application version. Production must notice those source changes
            // instead of serving an older compiled sidebar indefinitely.
            'auto_reload' => true,
            'strict_variables' => $debug,
            'autoescape' => 'html',
        ]);
        if ($translator !== null) {
            $this->twig->addFunction(new TwigFunction(
                'trans',
                static fn (string $key, array $parameters = []): string => $translator->translate($key, $parameters),
            ));
        }
    }

    public function addNamespace(string $namespace, string $path): void
    {
        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $namespace)) {
            throw new \InvalidArgumentException('Invalid view namespace.');
        }

        $this->before($namespace);$this->loader->addPath($path,$namespace);$this->after($namespace);
    }

    public function prependPath(string $path): void
    {
        $this->before(FilesystemLoader::MAIN_NAMESPACE);$this->loader->prependPath($path);$this->after(FilesystemLoader::MAIN_NAMESPACE);
    }

    public function prependNamespace(string $namespace, string $path): void
    {
        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $namespace)) {
            throw new \InvalidArgumentException('Invalid view namespace.');
        }

        $this->before($namespace);$this->loader->prependPath($path,$namespace);$this->after($namespace);
    }

    public function addGlobal(string $name, mixed $value): void
    {
        if($this->owner!==null){$this->globals[$this->owner][]=['name'=>$name,'value'=>$value];return;}
        $this->twig->addGlobal($name,$value);
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }
    private function before(string$n):void{if($this->owner===null||isset($this->paths[$this->owner][$n]))return;$p=$this->loader->getPaths($n);$this->paths[$this->owner][$n]=['before'=>$p,'after'=>$p];}private function after(string$n):void{if($this->owner!==null)$this->paths[$this->owner][$n]['after']=$this->loader->getPaths($n);}
}
