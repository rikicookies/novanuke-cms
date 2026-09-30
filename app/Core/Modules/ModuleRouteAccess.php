<?php
declare(strict_types=1);
namespace NovaNuke\Core\Modules;
use NovaNuke\Auth\AuthManager;use NovaNuke\Core\Access\AccessAudience;use NovaNuke\Core\Http\Request;use NovaNuke\Core\Http\Response;use NovaNuke\Core\Http\Routing\Route;use NovaNuke\Core\I18n\Translator;
final class ModuleRouteAccess
{
 public function __construct(private readonly ModuleRepository $modules,private readonly AccessAudience $audience,private readonly AuthManager $auth,private readonly ?Translator $translator=null){}
 public function guard(Route $route,Request $request):?Response
 {
  if($route->owner===null||$request->path()==='/admin'||str_starts_with($request->path(),'/admin/'))return null;
  $record=$this->modules->all()[$route->owner]??null;if($record===null)return null;
  $user=$this->auth->user();if($this->audience->allows((string)($record['audience']??'public'),$user))return null;
  return $user===null?Response::redirect('/login'):Response::html($this->translator?->translate('error.vip_required')??'This area requires active VIP access.',403);
 }
 /** @param array<string,mixed>|null $user */
 public function allows(string $slug,?array $user):bool
 {
  $record=$this->modules->all()[$slug]??null;return $record!==null&&(bool)$record['enabled']&&$this->audience->allows((string)($record['audience']??'public'),$user);
 }
}
