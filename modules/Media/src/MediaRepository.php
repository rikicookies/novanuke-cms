<?php

declare(strict_types=1);

namespace Modules\Media\src;

use NovaNuke\Core\Media\MediaLibraryInterface;
use PDO;
use RuntimeException;

final class MediaRepository implements MediaLibraryInterface
{
    public function __construct(private readonly PDO $database){}
    public function all():array{return $this->database->query('SELECT m.*,u.username FROM media_files m INNER JOIN users u ON u.id=m.uploaded_by ORDER BY m.id DESC LIMIT 200')->fetchAll();}
    public function find(int $id):?array{$s=$this->database->prepare('SELECT * FROM media_files WHERE id=:id');$s->execute(['id'=>$id]);$row=$s->fetch();return is_array($row)?$row:null;}
    public function create(int $user,ValidatedMedia $media,string $path,?string $title,?string $alt):int
    {
        $s=$this->database->prepare('INSERT INTO media_files (uploaded_by,public_path,original_name,mime_type,file_size,width,height,title,alt_text,created_at,updated_at) VALUES (:user,:path,:name,:mime,:size,:width,:height,:title,:alt,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        $s->execute(['user'=>$user,'path'=>$path,'name'=>$media->originalName,'mime'=>$media->mimeType,'size'=>$media->size,'width'=>$media->width,'height'=>$media->height,'title'=>$title,'alt'=>$alt]);return(int)$this->database->lastInsertId();
    }
    public function update(int $id,?string $title,?string $alt):void{$s=$this->database->prepare('UPDATE media_files SET title=:title,alt_text=:alt,updated_at=UTC_TIMESTAMP() WHERE id=:id');$s->execute(compact('title','alt','id'));if($s->rowCount()!==1&&$this->find($id)===null)throw new RuntimeException('Media item not found.');}
    public function delete(int $id):void{$s=$this->database->prepare('DELETE FROM media_files WHERE id=:id');$s->execute(['id'=>$id]);if($s->rowCount()!==1)throw new RuntimeException('Media item not found.');}
}
