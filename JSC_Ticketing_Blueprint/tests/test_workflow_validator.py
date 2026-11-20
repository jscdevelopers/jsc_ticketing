import unittest, json, copy, sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
from workflow_validator import validate, simulate_route, check_condition, InvalidWorkflow

class WorkflowChecks(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.wf=json.loads((ROOT/'specs/workflow-example-category-A.json').read_text())
 def wfcopy(self):return copy.deepcopy(self.wf)
 def test_valid(self): self.assertEqual(13,len(validate(self.wf)))
 def test_category_A(self): self.assertIn('route_x', '\n'.join(simulate_route(self.wf,{'ticket.category':'A'})))
 def test_category_B(self): self.assertIn('route_default', '\n'.join(simulate_route(self.wf,{'ticket.category':'B'})))
 def test_duplicate(self):
  wf=self.wfcopy();wf['nodes'].append(wf['nodes'][0]);self.assertRaises(InvalidWorkflow,validate,wf)
 def test_missing_start(self):
  wf=self.wfcopy();wf['nodes'][0]['type']='task';self.assertRaises(InvalidWorkflow,validate,wf)
 def test_unreachable(self):
  wf=self.wfcopy();wf['nodes'].append({'id':'lonely','type':'task','label':'Lost'});self.assertRaises(InvalidWorkflow,validate,wf)
 def test_cycle(self):
  wf=self.wfcopy();wf['edges'].append({'from':'end','to':'start'});self.assertRaises(InvalidWorkflow,validate,wf)
 def test_missing_fallback(self):
  wf=self.wfcopy();wf['edges']=[e for e in wf['edges'] if not e.get('otherwise')];self.assertRaises(InvalidWorkflow,validate,wf)
 def test_script_injection(self):self.assertRaises(InvalidWorkflow,check_condition,{'eval':['alert(1)']})
 def test_malicious_var(self):self.assertRaises(InvalidWorkflow,check_condition,{'eq':[{'var':'actor.is_admin'},True]})
 def test_missing_fork_join(self):
  wf=self.wfcopy();wf['nodes'][6]['config']['join_id']='not_an_id';self.assertRaises(InvalidWorkflow,validate,wf)
 def test_secretariat_not_national(self):
  staff=json.loads((ROOT/'specs/sample-scope-actors.json').read_text())['actors']
  self.assertFalse(staff[0]['can_view_national'])
  self.assertEqual('Secretariat',staff[0]['station'])
  self.assertTrue(staff[1]['can_view_national'])
  self.assertNotEqual('Secretariat',staff[1]['station'])

if __name__=='__main__':unittest.main()
