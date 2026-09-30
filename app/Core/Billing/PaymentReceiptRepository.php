<?php

declare(strict_types=1);

namespace NovaNuke\Core\Billing;

use PDO;
use PDOException;

final class PaymentReceiptRepository
{
    public function __construct(private readonly PDO $database) {}

    /** @return array<string,mixed>|null */
    public function find(string $provider,string $externalReference,bool $forUpdate=false): ?array
    {
        $sql='SELECT * FROM payment_receipts WHERE provider=:provider AND external_reference=:reference LIMIT 1';
        if($forUpdate) $sql.=' FOR UPDATE';
        $statement=$this->database->prepare($sql);
        $statement->execute(['provider'=>$provider,'reference'=>$externalReference]);
        $row=$statement->fetch();
        return is_array($row)?$row:null;
    }

    public function record(VerifiedPayment $payment): void
    {
        $statement=$this->database->prepare(
            'INSERT INTO payment_receipts '
            . '(provider,external_reference,user_id,plan_key,amount_minor,currency,processed_at,created_at) '
            . 'VALUES (:provider,:reference,:user,:plan,:amount,:currency,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        );
        try{
            $statement->execute([
                'provider'=>$payment->provider,
                'reference'=>$payment->externalReference,
                'user'=>$payment->userId,
                'plan'=>$payment->planKey,
                'amount'=>$payment->amountMinor,
                'currency'=>$payment->currency,
            ]);
        }catch(PDOException $error){
            $driverCode=(int)($error->errorInfo[1]??0);
            if($driverCode===1062) throw new DuplicatePaymentReceipt('Payment receipt already exists.',0,$error);
            throw $error;
        }
    }
}
