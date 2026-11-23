<?php
declare(strict_types=1);
namespace App\Workflow;

use InvalidArgumentException;

final class Condition
{
    public static function validate(mixed $condition,array $fields=[],int $depth=0): void
    {
        if ($depth>8 || !is_array($condition) || count($condition)!==1) { throw new InvalidArgumentException('Condition must be a bounded one-operator object.'); }
        $op=array_key_first($condition); $args=$condition[$op];
        if (in_array($op,['and','or','not'],true)) {
            if (!is_array($args) || !array_is_list($args) || count($args)<1 || count($args)>10 || ($op==='not' && count($args)!==1)) { throw new InvalidArgumentException('Invalid logical operands.'); }
            foreach ($args as $arg) { self::validate($arg,$fields,$depth+1); }
            return;
        }
        if (!in_array($op,['eq','neq','gt','gte','lt','lte','in','exists'],true) || !is_array($args) || !array_is_list($args) || count($args)!==($op==='exists'?1:2)) { throw new InvalidArgumentException('Unsupported condition operator or arity.'); }
        foreach ($args as $value) {
            if (is_array($value) && !array_is_list($value)) {
                $path=$value['var']??null;
                if (array_keys($value)!==['var'] || !is_string($path) || (!in_array($path,['ticket.category','ticket.priority','requester.station_id','requester.province_id','requester.department_id','step.outcome'],true)
                    && !(str_starts_with($path,'fields.') && in_array(substr($path,7),$fields,true)))) { throw new InvalidArgumentException('Unapproved condition variable.'); }
            } elseif (is_array($value)) {
                if ($op!=='in' || count($value)>50 || array_filter($value,static fn($item)=>!is_scalar($item) && $item!==null)) { throw new InvalidArgumentException('Invalid membership values.'); }
            } elseif (!is_scalar($value) && $value!==null) { throw new InvalidArgumentException('Invalid comparison value.'); }
        }
    }

    public static function evaluate(array $condition,array $values): bool
    {
        $op=array_key_first($condition);$args=$condition[$op];
        if ($op==='and') { foreach($args as $arg) { if(!self::evaluate($arg,$values)) { return false; } } return true; }
        if ($op==='or') { foreach($args as $arg) { if(self::evaluate($arg,$values)) { return true; } } return false; }
        if ($op==='not') { return !self::evaluate($args[0],$values); }
        $a=is_array($args[0]) && isset($args[0]['var']) ? ($values[$args[0]['var']]??null):$args[0];
        $b=isset($args[1]) && is_array($args[1]) && isset($args[1]['var']) ? ($values[$args[1]['var']]??null):($args[1]??null);
        return match($op) {
            'exists'=>$a!==null,'eq'=>$a===$b,'neq'=>$a!==$b,'in'=>is_array($b)&&in_array($a,$b,true),
            'gt','gte','lt','lte'=>(is_int($a)||is_float($a))&&(is_int($b)||is_float($b)) && match($op){'gt'=>$a>$b,'gte'=>$a>=$b,'lt'=>$a<$b,'lte'=>$a<=$b},
            default=>false,
        };
    }
}
