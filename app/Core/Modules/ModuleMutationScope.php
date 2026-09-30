<?php
declare(strict_types=1);
namespace NovaNuke\Core\Modules;
use Closure;
final class ModuleMutationScope
{
    private static ?string $owner=null;
    /** @var array<string,list<Closure():void>> */private static array$rollbacks=[];
    public static function begin(string$owner):void{self::$owner=$owner;}
    public static function end():void{self::$owner=null;}
    public static function owner():?string{return self::$owner;}
    public static function onRollback(Closure$rollback):void{if(self::$owner!==null)self::$rollbacks[self::$owner][]=$rollback;}
    public static function commit(string$owner):void{unset(self::$rollbacks[$owner]);}
    public static function rollback(string$owner):void{foreach(array_reverse(self::$rollbacks[$owner]??[])as$rollback)$rollback();unset(self::$rollbacks[$owner]);}
}
