<?php
declare(strict_types=1);
namespace NovaNuke\Core\I18n;
final class CatalogueAuditor
{
 public function __construct(private readonly string $root){}
 /** @return list<array{passed:bool,label:string,detail:string}> */ public function run():array
 {
  $directories=[$this->root.'/language'];foreach(['modules','themes']as$type)foreach(glob($this->root.'/'.$type.'/*/language',GLOB_ONLYDIR)?:[]as$dir)$directories[]=$dir;$checks=[];
  foreach($directories as$dir){$name=$dir===$this->root.'/language'?'core':str_replace($this->root.'/','',$dir);$files=glob($dir.'/*.json')?:[];$catalogues=[];$valid=true;$detail=[];foreach($files as$file){$locale=pathinfo($file,PATHINFO_FILENAME);try{$data=json_decode((string)file_get_contents($file),true,128,JSON_THROW_ON_ERROR);if(!is_array($data))throw new \RuntimeException('root is not an object');foreach($data as$key=>$value)if(!is_string($key)||!preg_match('/^[a-z0-9][a-z0-9_.-]{0,190}$/',$key)||!is_string($value))throw new \RuntimeException('invalid key or value');$catalogues[$locale]=array_keys($data);}catch(\Throwable$e){$valid=false;$detail[]=$locale.': '.$e->getMessage();}}
   if(!isset($catalogues['en'],$catalogues['es'])){$valid=false;$detail[]='English and Spanish catalogues are required.';}elseif(array_diff($catalogues['en'],$catalogues['es'])||array_diff($catalogues['es'],$catalogues['en'])){$valid=false;$detail[]='English and Spanish keys differ.';}$checks[]=['passed'=>$valid,'label'=>$name,'detail'=>$detail?implode(' ',$detail):count($catalogues['en']??[]).' matched keys'];
  }return$checks;
 }
}
