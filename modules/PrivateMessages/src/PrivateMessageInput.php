<?php

declare(strict_types=1);

namespace Modules\PrivateMessages\src;

use NovaNuke\Core\Content\ContentFormat;
use RuntimeException;

final class PrivateMessageInput
{
    public function recipient(mixed $value):string{$v=trim((string)$value);if(!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/',$v))throw new RuntimeException('Enter a valid recipient username.');return$v;}
    public function subject(mixed $value):string{$v=trim(strip_tags((string)$value));if(mb_strlen($v)<2||mb_strlen($v)>200)throw new RuntimeException('Subject must contain 2-200 characters.');return$v;}
    public function body(mixed $value):string{$v=trim((string)$value);if(mb_strlen($v)<2||mb_strlen($v)>5000)throw new RuntimeException('Message must contain 2-5000 characters.');return$v;}
    public function format(mixed $value):ContentFormat{return ContentFormat::fromInput($value,ContentFormat::Markdown);}
    public function reason(mixed $value):string{$v=trim(strip_tags((string)$value));if(mb_strlen($v)<5||mb_strlen($v)>500)throw new RuntimeException('Report reason must contain 5-500 characters.');return$v;}
}
