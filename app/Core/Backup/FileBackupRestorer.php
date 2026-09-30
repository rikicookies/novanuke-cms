<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use RuntimeException;
use Throwable;

final class FileBackupRestorer
{
    public function __construct(private readonly BackupVerifier $verifier)
    {
    }

    /** @return array{files:int,bytes:int} */
    public function restore(string $archive, string $destination): array
    {
        $verified=$this->verifier->verifyFileArchive($archive);
        $this->prepareDestination($destination);

        $stream=fopen($archive,'rb');
        if($stream===false) throw new RuntimeException('File backup is not readable.');
        $written=[];
        $files=0;
        $bytes=0;

        try{
            while(($header=fread($stream,512))!==false&&$header!==''){
                if(strlen($header)!==512) throw new RuntimeException('File backup has a truncated TAR header.');
                if($header===str_repeat("\0",512)){
                    $second=fread($stream,512);
                    if(!is_string($second)||$second!==str_repeat("\0",512)) throw new RuntimeException('File backup has an invalid TAR terminator.');
                    break;
                }

                $name=rtrim(substr($header,0,100),"\0");
                $prefix=rtrim(substr($header,345,155),"\0");
                $archivePath=$prefix===''?$name:$prefix.'/'.$name;
                $sizeField=trim(substr($header,124,12),"\0 ");
                if($sizeField===''||preg_match('/^[0-7]+$/',$sizeField)!==1) throw new RuntimeException("Invalid TAR size for: {$archivePath}");
                $size=octdec($sizeField);

                if($archivePath==='NOVANUKE-BACKUP.json'){
                    $this->discard($stream,$size);
                }else{
                    $target=$this->targetPath($destination,$archivePath);
                    $directory=dirname($target);
                    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)){
                        throw new RuntimeException("Unable to create restore directory: {$archivePath}");
                    }
                    if(is_link($directory)||is_link($target)||file_exists($target)){
                        throw new RuntimeException("Restore target already exists or is unsafe: {$archivePath}");
                    }
                    $output=fopen($target,'xb');
                    if($output===false) throw new RuntimeException("Unable to create restored file: {$archivePath}");
                    try{
                        $remaining=$size;
                        while($remaining>0){
                            $chunk=fread($stream,min(1048576,$remaining));
                            if(!is_string($chunk)||$chunk==='') throw new RuntimeException("Truncated backup entry: {$archivePath}");
                            if(fwrite($output,$chunk)!==strlen($chunk)) throw new RuntimeException("Unable to write restored file: {$archivePath}");
                            $remaining-=strlen($chunk);
                        }
                        if(!fflush($output)) throw new RuntimeException("Unable to flush restored file: {$archivePath}");
                    }finally{
                        fclose($output);
                    }
                    @chmod($target,0600);
                    $written[]=$target;
                    $files++;
                    $bytes+=$size;
                }

                $padding=(512-($size%512))%512;
                if($padding>0) $this->discard($stream,$padding);
            }

            if($files!==$verified['files']||$bytes!==$verified['bytes']){
                throw new RuntimeException('Restored file totals do not match the verified backup manifest.');
            }

            return ['files'=>$files,'bytes'=>$bytes];
        }catch(Throwable $error){
            foreach(array_reverse($written) as $file) if(is_file($file)) @unlink($file);
            throw $error;
        }finally{
            fclose($stream);
        }
    }

    private function prepareDestination(string $destination): void
    {
        if($destination===''||is_link($destination)) throw new RuntimeException('Restore destination is unsafe.');
        if(!is_dir($destination)&&!mkdir($destination,0700,true)&&!is_dir($destination)){
            throw new RuntimeException('Unable to create restore destination.');
        }
        $entries=array_values(array_diff(scandir($destination)?:[],['.','..']));
        if($entries!==[]) throw new RuntimeException('Restore destination must be empty.');
        if(!is_writable($destination)) throw new RuntimeException('Restore destination is not writable.');
    }

    private function targetPath(string $destination,string $archivePath): string
    {
        if($archivePath===''||str_contains($archivePath,'\\')||str_starts_with($archivePath,'/')
            ||preg_match('#(^|/)\.\.(/|$)#',$archivePath)===1||preg_match('/^[A-Za-z]:/',$archivePath)===1){
            throw new RuntimeException('Backup archive path is unsafe.');
        }
        return rtrim($destination,'/\\').DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$archivePath);
    }

    /** @param resource $stream */
    private function discard($stream,int $bytes): void
    {
        $remaining=$bytes;
        while($remaining>0){
            $chunk=fread($stream,min(1048576,$remaining));
            if(!is_string($chunk)||$chunk==='') throw new RuntimeException('Backup archive is truncated.');
            $remaining-=strlen($chunk);
        }
    }
}
