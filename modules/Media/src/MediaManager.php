<?php

declare(strict_types=1);

namespace Modules\Media\src;

use NovaNuke\Core\Media\MediaUsageChecking;

use NovaNuke\Core\Events\EventDispatcher;
use RuntimeException;
use Throwable;

final class MediaManager
{
    public function __construct(private readonly MediaRepository $repository,private readonly MediaUploadValidator $validator,private readonly MediaStorage $storage,private readonly EventDispatcher $events){}
    public function upload(?array $file,int $user,mixed $title,mixed $alt):int
    {
        [$title,$alt]=$this->metadata($title,$alt);$media=$this->validator->validate($file);$path=$this->storage->store($media);
        try{return $this->repository->create($user,$media,$path,$title,$alt);}catch(Throwable $e){try{$this->storage->remove($path);}catch(Throwable){}throw$e;}
    }
    public function update(int $id,mixed $title,mixed $alt):void{[$title,$alt]=$this->metadata($title,$alt);$this->repository->update($id,$title,$alt);}
    public function delete(int $id):void
    {
        $media=$this->repository->find($id);if($media===null)throw new RuntimeException('Media item not found.');
        $usage=new MediaUsageChecking((string)$media['public_path']);$this->events->dispatch(\NovaNuke\Core\Events\EventName::MEDIA_USAGE_CHECKING,$usage);
        if($usage->total()>0)throw new RuntimeException('This image is still used by site content and cannot be deleted.');
        $this->repository->delete($id);$this->storage->remove((string)$media['public_path']);
    }
    /** @return array{?string,?string} */ private function metadata(mixed $title,mixed $alt):array
    {
        $title=trim((string)$title);$alt=trim((string)$alt);
        if(mb_strlen($title)>160||mb_strlen($alt)>255)throw new RuntimeException('Title or alternative text is too long.');
        return[$title===''?null:$title,$alt===''?null:$alt];
    }
}
