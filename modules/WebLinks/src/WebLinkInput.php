<?php

declare(strict_types=1);

namespace Modules\WebLinks\src;

use NovaNuke\Core\Content\ContentFormat;use RuntimeException;

final class WebLinkInput
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function link(array $input,bool $administrative):array
    {
        $title=trim(strip_tags((string)($input['title']??'')));if(mb_strlen($title)<2||mb_strlen($title)>200)throw new RuntimeException('Title must contain 2-200 characters.');
        $slug=strtolower(trim((string)($input['slug']??'')));if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)||mb_strlen($slug)>200)throw new RuntimeException('Slug must use lowercase words separated by hyphens.');
        $url=$this->url($input['url']??null);$description=(string)($input['description']??'');if(trim($description)==='')throw new RuntimeException('Description is required.');if(mb_strlen($description)>100000)throw new RuntimeException('Description must not exceed 100,000 characters.');$descriptionFormat=ContentFormat::fromInput($input['description_format']??null);
        $status=$administrative?(string)($input['status']??'pending'):'pending';if(!in_array($status,['pending','published','rejected'],true))throw new RuntimeException('Invalid link status.');
        $audience=$administrative?(string)($input['audience']??'public'):'public';if(!in_array($audience,['public','member','vip'],true))throw new RuntimeException('Invalid link audience.');
        return ['category_id'=>$this->id($input['category_id']??null),'title'=>$title,'slug'=>$slug,'url'=>$url,'description'=>$description,'description_format'=>$descriptionFormat->value,'image_path'=>$this->image($input['image_path']??null),'status'=>$status,'audience'=>$audience,'is_featured'=>$administrative&&($input['is_featured']??null)==='1'?1:0];
    }
    public function category(array $input):array{$name=trim(strip_tags((string)($input['name']??'')));$slug=strtolower(trim((string)($input['slug']??'')));if(mb_strlen($name)<2||mb_strlen($name)>120||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug))throw new RuntimeException('Enter a valid category name and slug.');$description=trim(strip_tags((string)($input['description']??'')));if(mb_strlen($description)>500)throw new RuntimeException('Category description cannot exceed 500 characters.');return compact('name','slug','description');}
    public function url(mixed $value):string{$url=trim((string)$value);$scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));if(strlen($url)>2048||!filter_var($url,FILTER_VALIDATE_URL)||!in_array($scheme,['http','https'],true)||parse_url($url,PHP_URL_USER)!==null||preg_match('/[\x00-\x1F\x7F]/',$url))throw new RuntimeException('URL must use safe HTTP or HTTPS without embedded credentials.');return$url;}
    private function id(mixed $value):?int{if($value===null||$value==='')return null;$id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)throw new RuntimeException('Invalid category.');return(int)$id;}
    private function image(mixed $value):?string{$value=trim((string)$value);if($value==='')return null;if(strlen($value)>255||!preg_match('#^/uploads/[a-zA-Z0-9/_-]+\.(?:png|jpe?g|gif|webp)$#',$value)||str_contains($value,'..'))throw new RuntimeException('Image must be a safe path below /uploads/.');return$value;}
}
