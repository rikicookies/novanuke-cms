<?php
declare(strict_types=1);
namespace NovaNuke\Core\I18n;
use JsonException;use RuntimeException;
final class LocaleRegistry
{
 /** @var array<string,string> */ private array $locales=[];
 public function __construct(private readonly string $directory)
 {
  foreach(glob(rtrim($directory,'/').'/*.json')?:[] as$file){$code=pathinfo($file,PATHINFO_FILENAME);if(!preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/',$code))continue;try{$data=json_decode((string)file_get_contents($file),true,128,JSON_THROW_ON_ERROR);}catch(JsonException$e){throw new RuntimeException("Invalid core translation catalogue: {$code}.",0,$e);}if(!is_array($data))throw new RuntimeException("Translation catalogue must contain an object: core/{$code}.");$label=$data['locale.native_name']??$code;if(!is_string($label)||trim($label)===''||mb_strlen($label)>80)throw new RuntimeException("Invalid native locale name: {$code}.");$this->locales[$code]=trim($label);}ksort($this->locales);if(!isset($this->locales['en']))throw new RuntimeException('The English core catalogue is required.');
 }
 /** @return array<string,string> */ public function all():array{return$this->locales;}
 /** @return list<string> */ public function codes():array{return array_keys($this->locales);}
 public function supports(string $locale):bool{return isset($this->locales[$locale]);}
 public function fallback(string $locale,string $default='en'):string{return$this->supports($locale)?$locale:($this->supports($default)?$default:'en');}
}
