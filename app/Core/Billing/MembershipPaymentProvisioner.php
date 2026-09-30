<?php

declare(strict_types=1);

namespace NovaNuke\Core\Billing;

use NovaNuke\Core\Membership\MembershipProvisionerInterface;
use PDO;
use RuntimeException;
use Throwable;

final class MembershipPaymentProvisioner
{
    public function __construct(
        private readonly PDO $database,
        private readonly PaymentProviderRegistry $providers,
        private readonly PaymentReceiptRepository $receipts,
        private readonly MembershipProvisionerInterface $memberships,
    ) {}

    /** @param array<string,string> $headers
     *  @return array<string,mixed>
     */
    public function handle(string $providerKey,string $payload,array $headers=[]): array
    {
        $payment=$this->providers->get($providerKey)->verify($payload,$headers);
        if($payment->provider!==$providerKey){
            throw new RuntimeException('Verified payment provider does not match the selected provider.');
        }
        return $this->apply($payment);
    }

    /** @return array<string,mixed> */
    public function apply(VerifiedPayment $payment): array
    {
        $ownsTransaction=!$this->database->inTransaction();
        if($ownsTransaction) $this->database->beginTransaction();
        try{
            $existing=$this->receipts->find($payment->provider,$payment->externalReference,true);
            if($existing!==null){
                $this->assertSamePayment($existing,$payment);
                if($ownsTransaction) $this->database->commit();
                return ['duplicate'=>true,'payment'=>$existing];
            }

            try{
                $this->receipts->record($payment);
            }catch(DuplicatePaymentReceipt){
                $existing=$this->receipts->find($payment->provider,$payment->externalReference,true);
                if($existing===null) throw new RuntimeException('Duplicate payment receipt could not be reloaded.');
                $this->assertSamePayment($existing,$payment);
                if($ownsTransaction) $this->database->commit();
                return ['duplicate'=>true,'payment'=>$existing];
            }

            $membership=$this->memberships->provision(
                $payment->userId,
                $payment->planKey,
                'payment',
                $payment->provider.':'.$payment->externalReference,
            );

            if($ownsTransaction) $this->database->commit();
            return ['duplicate'=>false,'payment'=>[
                'provider'=>$payment->provider,
                'external_reference'=>$payment->externalReference,
                'user_id'=>$payment->userId,
                'plan_key'=>$payment->planKey,
                'amount_minor'=>$payment->amountMinor,
                'currency'=>$payment->currency,
            ],'membership'=>$membership];
        }catch(Throwable $error){
            if($ownsTransaction&&$this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $existing */
    private function assertSamePayment(array $existing,VerifiedPayment $payment): void
    {
        $same=(int)$existing['user_id']===$payment->userId
            &&(string)$existing['plan_key']===$payment->planKey
            &&(int)$existing['amount_minor']===$payment->amountMinor
            &&strtoupper((string)$existing['currency'])===$payment->currency;
        if(!$same) throw new RuntimeException('Payment reference was already used with different payment data.');
    }
}
