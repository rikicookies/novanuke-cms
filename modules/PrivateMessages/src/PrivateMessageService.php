<?php

declare(strict_types=1);

namespace Modules\PrivateMessages\src;

use NovaNuke\Core\Content\ContentFormat;
use NovaNuke\Core\Content\ContentProfile;
use NovaNuke\Core\Content\ContentRendererInterface;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Messaging\PrivateMessageComposerInterface;
use NovaNuke\Core\Messaging\PrivateMessageSent;
use NovaNuke\Core\Security\DatabaseRateLimiter;
use RuntimeException;
use Twig\Markup;

final class PrivateMessageService implements PrivateMessageComposerInterface
{
    public function __construct(private readonly PrivateMessageRepository $repository,private readonly PrivateMessageInput $input,private readonly DatabaseRateLimiter $sendLimiter,private readonly DatabaseRateLimiter $reportLimiter,private readonly EventDispatcher $events,private readonly ContentRendererInterface $contentRenderer) {}

    public function composeUrlFor(string $username): string
    {
        return '/messages/compose?to=' . rawurlencode($username);
    }

    public function compose(int $sender,string $username,mixed $subject,mixed $body,mixed $bodyFormat=null): int
    {
        $username=$this->input->recipient($username);$subject=$this->input->subject($subject);[$body,$format]=$this->content($body,$bodyFormat);
        $recipient=$this->repository->userByUsername($username); if($recipient===null)throw new RuntimeException('Recipient was not found.');
        $recipientId=(int)$recipient['id']; if($recipientId===$sender)throw new RuntimeException('You cannot message yourself.');
        $this->assertCanSend($sender,$recipientId); $created=$this->repository->createWithMessage($sender,$recipientId,$subject,$body,$format->value);
        $this->events->dispatch(\NovaNuke\Core\Events\EventName::PRIVATE_MESSAGE_SENT,new PrivateMessageSent($recipientId,$created['conversation_id'],(string)$created['message_id']));
        return $created['conversation_id'];
    }

    public function reply(int $conversation,int $sender,mixed $body,mixed $bodyFormat=null): int
    {
        [$body,$format]=$this->content($body,$bodyFormat);
        $thread=$this->repository->conversation($conversation,$sender); if($thread===null)throw new RuntimeException('Conversation not found.');
        $recipient=(int)$thread['other']['id'];$this->assertCanSend($sender,$recipient);$message=$this->repository->reply($conversation,$sender,$body,$format->value);
        $this->events->dispatch(\NovaNuke\Core\Events\EventName::PRIVATE_MESSAGE_SENT,new PrivateMessageSent($recipient,$conversation,(string)$message));
        return $message;
    }

    public function report(int $message,int $user,mixed $reason): void
    {
        $key='user:'.$user; if($this->reportLimiter->tooManyAttempts($key))throw new RuntimeException('Too many reports. Please wait before trying again.');
        $reason=$this->input->reason($reason);
        $this->repository->report($message,$user,$reason);$this->reportLimiter->hit($key);
    }

    public function renderMessages(array $messages): array
    {
        foreach($messages as &$message){$html=$this->contentRenderer->render((string)$message['body'],ContentFormat::fromInput($message['body_format']??null,ContentFormat::Markdown),ContentProfile::Message);$message['body_html']=new Markup($html,'UTF-8');$message['body_text']=trim(strip_tags($html));}unset($message);return $messages;
    }

    /** @return array{0:string,1:ContentFormat} */
    private function content(mixed $body,mixed $bodyFormat):array
    {
        $body=$this->input->body($body);$format=$this->input->format($bodyFormat);$visible=trim(strip_tags($this->contentRenderer->render($body,$format,ContentProfile::Message)));if($visible==='')throw new RuntimeException('Message must contain visible text.');return[$body,$format];
    }

    private function assertCanSend(int $sender,int $recipient): void
    {
        if($this->repository->blocked($sender,$recipient))throw new RuntimeException('Messaging is unavailable between these users.');
        $key='user:'.$sender;if($this->sendLimiter->tooManyAttempts($key))throw new RuntimeException('Too many messages. Please wait before trying again.');$this->sendLimiter->hit($key);
    }
}
