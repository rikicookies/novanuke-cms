<?php

declare(strict_types=1);
namespace NovaNuke\Core\Forms;
use NovaNuke\Core\Http\Request; use NovaNuke\Core\Http\Response; use NovaNuke\Core\Mail\Mailer; use NovaNuke\Core\Security\CsrfTokenManager; use NovaNuke\Core\Security\DatabaseRateLimiter; use NovaNuke\Core\Settings\SettingsRepository; use PDO;
final class ContactFormController {
 public function __construct(private readonly CsrfTokenManager $csrf, private readonly Mailer $mailer, private readonly SettingsRepository $settings, private readonly PDO $database) {}
 public function submit(Request $request): Response {
  $returnTo=$this->returnTo($request->input('return_to','/'));
  if(!$this->csrf->validate($request->input('_token'))) return Response::redirect($this->resultUrl($returnTo,'invalid'),303);
  if(trim((string)$request->input('website',''))!=='') return Response::redirect($this->resultUrl($returnTo,'sent'),303);
  $limiter=new DatabaseRateLimiter($this->database,5,900,'public-contact-form'); $key=$request->ip();
  if($limiter->tooManyAttempts($key)) return Response::redirect($this->resultUrl($returnTo,'limited'),303); $limiter->hit($key);
  $fields=$this->fields($request); if($fields===null) return Response::redirect($this->resultUrl($returnTo,'invalid'),303);
  $recipient=strtolower(trim($this->settings->string('site.admin_email',''))); if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)) return Response::redirect($this->resultUrl($returnTo,'unavailable'),303);
  $siteName=trim($this->settings->string('site.name','NovaNuke'))?:'NovaNuke'; $subject='Website inquiry — '.$siteName; if($fields['subject']!=='') $subject.=': '.$fields['subject'];
  $lines=['A new website inquiry was submitted.','']; foreach(['name'=>'Name','email'=>'Email','phone'=>'Phone','address'=>'Address','subject'=>'Subject','message'=>'Message'] as $k=>$label) if($fields[$k]!=='') $lines[]=$label.': '.$fields[$k]; $lines[]=''; $lines[]='IP: '.$request->ip();
  $this->mailer->sendMessage($recipient,$subject,implode("\n",$lines),$fields['email']!==''?$fields['email']:null); return Response::redirect($this->resultUrl($returnTo,'sent'),303);
 }
 private function fields(Request $request): ?array { $field=static fn(mixed $v,int $max):string=>mb_substr(trim(is_scalar($v)?(string)$v:''),0,$max); $d=['name'=>$field($request->input('name',''),120),'email'=>strtolower($field($request->input('email',''),254)),'phone'=>$field($request->input('phone',''),60),'address'=>$field($request->input('address',''),300),'subject'=>$field($request->input('subject',''),160),'message'=>$field($request->input('message',''),5000)]; if($d['name']===''||$d['message']==='') return null; if($d['email']!==''&&!filter_var($d['email'],FILTER_VALIDATE_EMAIL)) return null; return $d; }
 private function returnTo(mixed $v):string { $v=is_string($v)?trim($v):'/'; if($v===''||!str_starts_with($v,'/')||str_starts_with($v,'//')||str_contains($v,'\\')||preg_match('/[\x00-\x20\x7F?#]/',$v)===1) return '/'; return $v; }
 private function resultUrl(string $returnTo,string $result):string { return $returnTo.'?form='.rawurlencode($result); }
}
