<?php

declare(strict_types=1);

namespace NovaNuke\Core\Billing;

use NovaNuke\Core\Membership\MembershipPlanCatalog;
use PDO;

final class PaymentHealthCheck
{
    public function __construct(
        private readonly PDO $database,
        private readonly MembershipPlanCatalog $plans,
        private readonly PaymentProviderRegistry $providers,
    ) {}

    /** @return list<array{name:string,passed:bool,detail:string}> */
    public function run(): array
    {
        $checks=[];
        $table=$this->tableExists('payment_receipts');
        $checks[]=[
            'name'=>'Payment receipts table',
            'passed'=>$table,
            'detail'=>$table?'payment_receipts is available.':'payment_receipts is missing. Run pending migrations.',
        ];
        if(!$table) return $checks;

        $columns=$this->columns('payment_receipts');
        foreach(['provider','external_reference','user_id','plan_key','amount_minor','currency','processed_at','created_at'] as $column){
            $present=in_array($column,$columns,true);
            $checks[]=[
                'name'=>"Receipt column {$column}",
                'passed'=>$present,
                'detail'=>$present?"payment_receipts.{$column} is available.":"Missing payment_receipts.{$column}.",
            ];
        }

        $orphans=$this->count(
            'SELECT COUNT(*) FROM payment_receipts pr LEFT JOIN users u ON u.id=pr.user_id WHERE u.id IS NULL'
        );
        $checks[]=[
            'name'=>'Receipt ownership',
            'passed'=>$orphans===0,
            'detail'=>$orphans===0?'Every payment receipt belongs to an existing user.':"{$orphans} orphan payment receipt(s) exist.",
        ];

        $known=array_keys($this->plans->all());
        $placeholders=implode(',',array_fill(0,count($known),'?'));
        $statement=$this->database->prepare(
            "SELECT COUNT(*) FROM payment_receipts WHERE plan_key NOT IN ({$placeholders})"
        );
        $statement->execute($known);
        $unknown=(int)$statement->fetchColumn();
        $checks[]=[
            'name'=>'Known receipt plan keys',
            'passed'=>$unknown===0,
            'detail'=>$unknown===0?'All payment receipt plan keys are recognized.':"{$unknown} payment receipt(s) use unknown plan keys.",
        ];

        $duplicate=$this->count(
            'SELECT COUNT(*) FROM (SELECT provider,external_reference FROM payment_receipts '
            . 'GROUP BY provider,external_reference HAVING COUNT(*)>1) x'
        );
        $checks[]=[
            'name'=>'Receipt idempotency',
            'passed'=>$duplicate===0,
            'detail'=>$duplicate===0?'Provider references are unique.':"{$duplicate} duplicate provider reference group(s) exist.",
        ];

        $keys=$this->providers->keys();
        $checks[]=[
            'name'=>'Registered payment providers',
            'passed'=>true,
            'detail'=>$keys===[]?'No payment provider is enabled.':implode(', ',$keys),
        ];

        return $checks;
    }

    private function tableExists(string $table): bool
    {
        $statement=$this->database->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table'
        );
        $statement->execute(['table'=>$table]);
        return (int)$statement->fetchColumn()===1;
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $statement=$this->database->prepare(
            'SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:table'
        );
        $statement->execute(['table'=>$table]);
        return array_values(array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function count(string $sql): int
    {
        return (int)$this->database->query($sql)->fetchColumn();
    }
}
