<?php

declare(strict_types=1);

namespace NovaNuke\Core\Content;

enum ContentProfile: string
{
    case FullContent = 'full_content';
    case Description = 'description';
    case Comment = 'comment';
    case Profile = 'profile';
    case Message = 'message';
}
