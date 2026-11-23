<?php
declare(strict_types=1);
namespace App\Workflow;

use App\Tickets\IntakeSchema;
use App\Tickets\TicketReader;
use InvalidArgumentException;

final class Graph
{
    public array $nodes=[];
    public array $edges=[];
    public string $start;
    private array $reachCache=[];

    public function __construct(public readonly array $definition)
    {
        if (($definition['engine']??'')!=='workflow-v1' || !TicketReader::uuid($definition['department_id']??'')
            || !is_array($definition['form_schema']??null) || !is_array($definition['nodes']??null) || !is_array($definition['edges']??null)
            || count($definition['nodes'])<2 || count($definition['nodes'])>100 || count($definition['edges'])>200) { throw new InvalidArgumentException('Invalid bounded workflow definition.'); }
        IntakeSchema::validateDefinition($definition['form_schema']);
        $starts=[];$ends=[];
        foreach($definition['nodes'] as $node) {
            if (!is_array($node) || !is_string($node['id']??null) || !preg_match('/^[a-z][a-z0-9_]{0,49}$/D',$node['id']) || isset($this->nodes[$node['id']])
                || !is_string($node['label']??null) || strlen($node['label'])<1 || strlen($node['label'])>120
                || !in_array($node['type']??'', ['start','intake','task','claim_pool','approval','route','decision','parallel_fork','parallel_join','request_info','timer','escalation','notification','resolve','close','end'],true)) { throw new InvalidArgumentException('Invalid, duplicate or unsupported workflow node.'); }
            $config=$node['config']??[];
            if (!is_array($config)) { throw new InvalidArgumentException('Invalid node configuration.'); }
            if (in_array($node['type'],['task','claim_pool','approval','route'],true)) {
                if (!TicketReader::uuid($config['department_id']??'') || (in_array($node['type'],['task','claim_pool','approval'],true) && !TicketReader::uuid($config['position_id']??''))) { throw new InvalidArgumentException('Routing/task nodes require directory UUIDs.'); }
                if (!in_array($config['scope']??'station',['station','province','department','national'],true)) { throw new InvalidArgumentException('Invalid task geography.'); }
            }
            foreach(['sla_minutes','delay_minutes'] as $field) { if(isset($config[$field]) && (!is_int($config[$field]) || $config[$field]<1 || $config[$field]>525600)) { throw new InvalidArgumentException('Invalid timer/SLA duration.'); } }
            if($node['type']==='timer' && !isset($config['delay_minutes'])) { throw new InvalidArgumentException('Timer duration required.'); }
            $this->nodes[$node['id']]=$node;
            if($node['type']==='start') {$starts[]=$node['id'];}
            if(in_array($node['type'],['close','end'],true)) {$ends[]=$node['id'];}
        }
        if(count($starts)!==1 || !$ends) { throw new InvalidArgumentException('Exactly one start and at least one terminal are required.'); }
        $this->start=$starts[0];
        foreach($definition['edges'] as $edge) {
            if(!is_array($edge) || !isset($this->nodes[$edge['from']??''],$this->nodes[$edge['to']??'']) || $edge['to']===$this->start
                || (isset($edge['otherwise']) && !is_bool($edge['otherwise'])) || (isset($edge['when']) && !empty($edge['otherwise']))) { throw new InvalidArgumentException('Invalid workflow edge.'); }
            if(isset($edge['when'])) { Condition::validate($edge['when'],array_keys($definition['form_schema']['properties'])); }
            $this->edges[$edge['from']][]=$edge;
        }
        foreach($this->nodes as $id=>$node) {
            $edges=$this->edges[$id]??[];
            if($node['type']==='approval'&&isset($node['config']['on_reject'])&&(!in_array($node['config']['on_reject'],['terminate','route'],true)||$node['config']['on_reject']==='route'&&count($edges)<2)){throw new InvalidArgumentException('Routed rejection requires explicit conditional outcome paths.');}
            if(in_array($node['type'],['close','end'],true)) { if($edges) {throw new InvalidArgumentException('Terminal nodes cannot have outgoing edges.');} continue; }
            if(!$edges) {throw new InvalidArgumentException('Every nonterminal must have an exit.');}
            if($node['type']==='parallel_fork') {
                $join=$node['config']['join_id']??'';
                $joinNode=$this->nodes[$join]??[];
                $policy=$joinNode['config']['mode']??'all';
                if(count($edges)<2 || count($edges)>10 || ($joinNode['type']??'')!=='parallel_join' || !in_array($policy,['all','any','quorum'],true)) { throw new InvalidArgumentException('Fork requires 2..10 branches and a matching join policy.'); }
                if($policy==='quorum' && (!is_int($joinNode['config']['quorum']??null) || $joinNode['config']['quorum']<1 || $joinNode['config']['quorum']>count($edges))) { throw new InvalidArgumentException('Invalid approval quorum.'); }
                if($policy!=='all' && ($joinNode['config']['cancel_remaining']??false)!==true) {throw new InvalidArgumentException('ANY/quorum joins require explicit cancellation of remaining branches.');}
                $targets=array_column($edges,'to');
                if(count($targets)!==count(array_unique($targets))) {throw new InvalidArgumentException('Fork branches must be distinct.');}
                foreach($targets as $target) {if(!$this->allPathsReach($target,$join,[])) {throw new InvalidArgumentException('Every fork branch must reach its matching join before terminating.');}}
            } elseif(count($edges)>1 || $node['type']==='decision') {
                if(count(array_filter($edges,static fn($edge)=>($edge['otherwise']??false)===true))!==1
                    || array_filter($edges,static fn($edge)=>!isset($edge['when']) && !($edge['otherwise']??false))) {throw new InvalidArgumentException('Conditional branches require exactly one fallback and explicit conditions.');}
            } elseif(isset($edges[0]['when']) || !empty($edges[0]['otherwise'])) {throw new InvalidArgumentException('A sole unconditional exit must not have a condition.');}
        }
        $visiting=[];$visited=[];
        $walk=function(string $id) use (&$walk,&$visiting,&$visited):void {
            if(isset($visiting[$id])) {throw new InvalidArgumentException('Cycles are not supported; use explicit bounded new requests.');}
            if(isset($visited[$id])) {return;}
            $visiting[$id]=true;foreach($this->edges[$id]??[] as $edge){$walk($edge['to']);}unset($visiting[$id]);$visited[$id]=true;
        };
        $walk($this->start);
        if(count($visited)!==count($this->nodes)) {throw new InvalidArgumentException('Unreachable nodes are forbidden.');}
        foreach($this->nodes as $id=>$node) {
            if($node['type']==='parallel_join' && count(array_filter($this->nodes,static fn($fork)=>$fork['type']==='parallel_fork' && ($fork['config']['join_id']??'')===$id))!==1) {throw new InvalidArgumentException('Every join requires exactly one matching fork.');}
        }
        $contexts=[];$states=[];
        $structure=function(string$id,array$stack)use(&$structure,&$contexts,&$states):void{
            $node=$this->nodes[$id];$key=$id.'|'.json_encode($stack,JSON_THROW_ON_ERROR);
            if(isset($states[$key]))return;$states[$key]=true;
            if(count($states)>500){throw new InvalidArgumentException('Workflow expansion exceeds its execution bound.');}
            if($node['type']==='parallel_join'){
                $frame=array_pop($stack);if(!$frame||$frame['join']!==$id){throw new InvalidArgumentException('A join must close the current matching fork.');}
            }else{
                $serialized=json_encode($stack,JSON_THROW_ON_ERROR);
                if(isset($contexts[$id])&&$contexts[$id]!==$serialized){throw new InvalidArgumentException('Independent branches must meet at their declared join.');}
                $contexts[$id]=$serialized;
            }
            foreach($this->edges[$id]??[]as$edge){$nextStack=$stack;if($node['type']==='parallel_fork'){$nextStack[]=['join'=>$node['config']['join_id'],'branch'=>$edge['to']];}$structure($edge['to'],$nextStack);}
        };
        $structure($this->start,[]);
    }

    private function allPathsReach(string $node,string $target,array $seen):bool
    {
        if($node===$target){return true;}
        $key=$target.'|'.$node;if(array_key_exists($key,$this->reachCache)){return$this->reachCache[$key];}
        if(isset($seen[$node]) || !($this->edges[$node]??[])) {return false;}
        $seen[$node]=true;foreach($this->edges[$node] as $edge){if(!$this->allPathsReach($edge['to'],$target,$seen)){return$this->reachCache[$key]=false;}}return$this->reachCache[$key]=true;
    }

    public function next(string $node,array $values):array
    {
        $edges=$this->edges[$node]??[];
        if(($this->nodes[$node]['type']??'')==='parallel_fork'){return array_column($edges,'to');}
        if(count($edges)===1 && !isset($edges[0]['when'])){return [$edges[0]['to']];}
        $fallback=null;
        foreach($edges as $edge){if(isset($edge['when']) && Condition::evaluate($edge['when'],$values)){return [$edge['to']];}if($edge['otherwise']??false){$fallback=$edge['to'];}}
        return $fallback===null?[]:[$fallback];
    }
}
