"""Minimal deterministic blueprint validator/simulator; NOT the production PHP engine.

Usage:
  python scripts/workflow_validator.py specs/workflow-example-category-A.json A
  python scripts/workflow_validator.py specs/workflow-example-category-A.json B
"""
from __future__ import annotations
import json, sys
from pathlib import Path
from collections import defaultdict, deque

ALLOWED_OPS = {'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'exists'}
ALLOWED_VARS = {'ticket.category', 'ticket.priority', 'requester.province_id', 'requester.station_id'}

class InvalidWorkflow(ValueError):
    pass


def check_condition(node: dict) -> None:
    if not isinstance(node, dict) or len(node) != 1:
        raise InvalidWorkflow('Condition must be a one-operator object')
    op, value = next(iter(node.items()))
    if op not in ALLOWED_OPS:
        raise InvalidWorkflow(f'Operator not allowed: {op}')
    if not isinstance(value, list) or not value or len(value) > 2:
        raise InvalidWorkflow('Invalid comparison operands')
    for item in value:
        if isinstance(item, dict):
            if set(item) != {'var'} or item['var'] not in ALLOWED_VARS:
                raise InvalidWorkflow('Unapproved variable expression')


def validate(wf: dict) -> dict[str, dict]:
    nodes = wf.get('nodes', [])
    edges = wf.get('edges', [])
    ids = [n.get('id') for n in nodes]
    if not ids or len(ids) != len(set(ids)):
        raise InvalidWorkflow('Missing or duplicate node IDs')
    m = {n['id']:n for n in nodes}
    starts = [n for n in nodes if n.get('type') == 'start']
    ends = [n for n in nodes if n.get('type') in ('end', 'close')]
    if len(starts) != 1 or not ends:
        raise InvalidWorkflow('Exactly one start and at least one terminal required')
    graph = defaultdict(list)
    for edge in edges:
        a,b=edge.get('from'),edge.get('to')
        if a not in m or b not in m:
            raise InvalidWorkflow(f'Edge targets invalid node {a} -> {b}')
        if 'when' in edge:
            check_condition(edge['when'])
        graph[a].append(edge)
    for n in nodes:
        if n.get('type') == 'decision':
            branches=graph[n['id']]
            if not branches or sum(bool(e.get('otherwise')) for e in branches) != 1:
                raise InvalidWorkflow(f'Decision {n["id"]} must have exactly one fallback')
            if any('when' not in e and not e.get('otherwise') for e in branches):
                raise InvalidWorkflow('Decision must label every explicit branch')
    visiting, visited = set(), set()
    def walk(nid: str) -> None:
        if nid in visiting:
            if not wf.get('published_policy', {}).get('allow_loops', False):
                raise InvalidWorkflow('Cycles not allowed')
            return
        if nid in visited: return
        visiting.add(nid)
        for edge in graph[nid]: walk(edge['to'])
        visiting.remove(nid);visited.add(nid)
    walk(starts[0]['id'])
    if set(m) != visited:
        raise InvalidWorkflow('Unreachable nodes: ' + ', '.join(sorted(set(m)-visited)))
    for n in nodes:
        if n['type'] not in ('end','close') and not graph[n['id']]:
            raise InvalidWorkflow(f'Non-terminal dead end: {n["id"]}')
    for n in nodes:
        if n['type']=='parallel_fork':
            join=n.get('config',{}).get('join_id')
            if not join or join not in m or m[join]['type']!='parallel_join':
                raise InvalidWorkflow('Parallel fork missing valid matching join')
    return m


def evaluate_condition(cond: dict, fields: dict) -> bool:
    check_condition(cond)
    op,args=next(iter(cond.items()))
    values=[fields.get(x['var']) if isinstance(x,dict) else x for x in args]
    if op=='eq':return len(values)==2 and values[0]==values[1]
    if op=='neq':return len(values)==2 and values[0]!=values[1]
    if op=='gt':return len(values)==2 and values[0]>values[1]
    if op=='gte':return len(values)==2 and values[0]>=values[1]
    if op=='lt':return len(values)==2 and values[0]<values[1]
    if op=='lte':return len(values)==2 and values[0]<=values[1]
    if op=='in':return len(values)==2 and isinstance(values[1],list) and values[0] in values[1]
    if op=='exists':return len(values)==1 and values[0] is not None
    raise InvalidWorkflow('Unexpected operator')


def simulate_route(wf:dict, fields:dict) -> list[str]:
    m=validate(wf)
    edges=defaultdict(list)
    for e in wf['edges']:edges[e['from']].append(e)
    node=next(k for k,v in m.items() if v['type']=='start')
    trace=[]
    for _ in range(len(m)+1):
        trace.append(f'{node} ({m[node]["label"]})')
        if m[node]['type'] in ('route','claim_pool','end'):
            return trace
        choices=edges[node]
        if m[node]['type']=='decision':
            branch=next((e for e in choices if 'when' in e and evaluate_condition(e['when'],fields)),None)
            branch=branch or next((e for e in choices if e.get('otherwise')),None)
            if branch is None:raise InvalidWorkflow('No branch selected')
            node=branch['to']
        else:
            if len(choices)!=1:
                raise InvalidWorkflow(f'Cannot linear-simulate node {node}; use branch-aware engine')
            node=choices[0]['to']
    raise InvalidWorkflow('Path exceeded max length')

if __name__=='__main__':
    if len(sys.argv) not in (2,3):
        raise SystemExit('Usage: python workflow_validator.py WORKFLOW_JSON [CATEGORY]')
    wf=json.loads(Path(sys.argv[1]).read_text())
    print('VALID:',len(validate(wf)),'nodes')
    print('\n'.join(simulate_route(wf,{'ticket.category':sys.argv[2] if len(sys.argv)>2 else 'A'})))
