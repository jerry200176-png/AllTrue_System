import sys,unittest,tempfile,json
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[2]))
from scripts.harness.evidence_observer import *
class Fake:
 def __init__(self,checks=None,rules=None,statuses=None):self.checks=checks or [];self.rules=rules or [];self.statuses=statuses or [];self.calls=[]
 def get(self,path,params):
  self.calls.append((path,params))
  if 'check-runs' in path:return {'total_count':len(self.checks),'check_runs':self.checks if params['page']==1 else []}
  if path.endswith('/status'):return {'total_count':len(self.statuses),'statuses':self.statuses if params['page']==1 else []}
  return self.rules
GOOD='''# Plan\n## Acceptance Criteria\n- [x] AC-001: check UI\n- [x] AC-002: check CI\n## Acceptance Evidence Map\n```json\n{"AC-001":["ui-smoke"],"AC-002":["check:gate:2"]}\n```'''
UI=lambda status='PASSED',**x:dict(schema='ui-smoke-evidence-v1',status=status,event='pull_request',pr_head_sha='a'*40,tested_sha='b'*40,github_sha='b'*40,base_sha='c'*40,relevant=True,missing_credential_names=[],relevant_playwright_skipped=status=='SKIPPED',acceptance_relevant_set='frontend/e2e/smoke.spec.js',test_counts={'passed':1 if status=='PASSED' else 0,'skipped':1 if status=='SKIPPED' else 0,'failed':1 if status=='FAILED' else 0},passed=status=='PASSED',skipped=status=='SKIPPED',failed=status=='FAILED',**x)
class T(unittest.TestCase):
 def test_report_emitter_four_states(self):
  creds={x:'x' for x in KNOWN_CREDS}
  report={'config':{'argv':['/usr/bin/node','node_modules/.bin/playwright','test'],'workers':1,'quiet':False},'suites':[{'file':'frontend/e2e/smoke.spec.js','title':'smoke','specs':[{'title':'acceptance','tests':[{'title':'case','results':[{'status':'skipped'} for _ in range(6)]}]}]}]}
  with tempfile.NamedTemporaryFile(mode='w',delete=False) as f:json.dump(report,f);path=f.name
  self.assertEqual(emit_ui_evidence(path,event='pull_request',job_status='success',run_e2e=True,credentials=creds)['status'],'SKIPPED')
  self.assertEqual(emit_ui_evidence(path,event='pull_request',job_status='success',run_e2e=True,credentials=creds)['test_counts']['skipped'],6)
  self.assertEqual(emit_ui_evidence('/no/report',event='pull_request',job_status='success',run_e2e=True,credentials=creds)['status'],'FAILED')
  self.assertEqual(emit_ui_evidence(path,event='pull_request',job_status='success',run_e2e=False,credentials={})['status'],'NOT_APPLICABLE')
  report={'suites':[{'file':'frontend/e2e/smoke.spec.js','specs':[{'tests':[{'results':[{'status':'skipped'} for _ in range(6)]}]}]}]}
  with open(path,'w') as f:json.dump(report,f)
  skipped=emit_ui_evidence(path,event='pull_request',job_status='success',run_e2e=True,credentials=creds)
  self.assertEqual(skipped['status'],'SKIPPED');self.assertEqual(skipped['test_counts']['skipped'],6)
 def test_acceptance_strict(self):
  self.assertTrue(bind_acceptance(GOOD,{'ui-smoke','check:gate:2'})['valid'])
  changed=GOOD.replace('"AC-002":["check:gate:2"]','"AC-003":["check:gate:2"]')
  self.assertNotEqual(changed,GOOD)
  self.assertFalse(bind_acceptance(changed,{'ui-smoke','check:gate:2'})['valid'])
  with self.assertRaises(ObservationError):parse_evidence_map(GOOD.replace('"AC-002":["check:gate:2"]','"AC-002":["check:gate:2"],"AC-002":["check:gate:2"]'))
 def test_real_rules_list_and_latest_apps(self):
  rules=[{'type':'required_status_checks','parameters':{'required_status_checks':[{'context':'gate','integration_id':2}]}},{'type':'other','parameters':{}}]
  checks=[{'id':1,'name':'gate','app':{'id':2},'status':'completed','conclusion':'failure','completed_at':'2020'},{'id':2,'name':'gate','app':{'id':2},'status':'completed','conclusion':'success','completed_at':'2021'}]
  out=observe(Fake(checks,rules), 'o/r','a'*40,criteria_text=GOOD,ui=UI())
  self.assertEqual(out['outcome'],'OBSERVED_COMPLETE');self.assertEqual(out['required_statuses'],[('gate',2)])
 def test_legacy_failure_pending_and_ui(self):
  rules=[{'type':'required_status_checks','parameters':{'required_status_checks':[{'context':'gate'}]}}]
  self.assertEqual(observe(Fake([],rules,[{'context':'gate','state':'error'}]),'o/r','a'*40,criteria_text=GOOD,ui=UI())['outcome'],'VERIFICATION_FAILED')
  self.assertEqual(observe(Fake([],rules,[{'context':'gate','state':'pending'}]),'o/r','a'*40,criteria_text=GOOD,ui=UI())['outcome'],'EVIDENCE_REPAIR')
  self.assertEqual(classify_ui(None),'NOT_APPLICABLE');self.assertEqual(classify_ui(UI('SKIPPED')),'SKIPPED');self.assertEqual(classify_ui(UI('FAILED')),'FAILED')
 def test_metadata_plain_and_pagination(self):
  out=observe(Fake(), 'o/r','a'*40,ui=None,repo_run_id='7',run_attempt='2',event='pull_request',collector_sha='c',base_sha='b')
  self.assertFalse(out['authoritative']);self.assertNotIn('envelope_id',out);self.assertEqual(out['subject_sha'],'a'*40)
  with self.assertRaises(ObservationError):paginate(lambda p:[{'same':1}],per_page=1)
 def test_ui_allowlist_and_incomplete_diagnostic(self):
  with self.assertRaises(ObservationError): classify_ui(dict(UI(),secret='never'))
  out=observe(Fake(),'o/r','a'*40)
  self.assertEqual(out['outcome'],'OBSERVED_INCOMPLETE')
  for key in ('event','acceptance_relevant_set'):
   bad=UI();bad.pop(key)
   with self.assertRaises(ObservationError):classify_ui(bad)
  na=UI();na.update(relevant=False,status='NOT_APPLICABLE',passed=False,skipped=False,failed=False,test_counts={'passed':0,'skipped':0,'failed':0},missing_credential_names=[])
  self.assertEqual(classify_ui(na),'NOT_APPLICABLE')
  with self.assertRaises(ObservationError):
   bad=UI();bad['tested_sha']='d'*40;sanitize_ui(bad)
 def test_failure_map_and_unchecked_criteria_fail_closed(self):
  unchecked=GOOD.replace('[x] AC-001','[ ] AC-001')
  self.assertTrue(parse_acceptance_criteria(unchecked)['AC-001'])
  rules=[{'type':'required_status_checks','parameters':{'required_status_checks':[]}}]
  out=observe(Fake([],rules), 'o/r','a'*40, criteria_text=unchecked, ui=UI())
  self.assertNotEqual(out['outcome'],'OBSERVED_COMPLETE')
 def test_envelope_shape_is_not_accepted(self):
  from scripts.harness.contracts import EvidenceEnvelope
  with self.assertRaises((ValueError,TypeError)): EvidenceEnvelope.from_dict({'schema':'github-observer-observation-v1','subject_sha':'x'})
 def test_report_unknown_and_malformed_are_failed(self):
  creds={x:'x' for x in KNOWN_CREDS}
  for report in ({'suites':[{'file':'smoke.spec.js','specs':[{'tests':[{'results':[{'status':'mystery'}]}]}]}]}, {'suites':[{'file':'smoke.spec.js','specs':'bad'}]}):
   with tempfile.NamedTemporaryFile(mode='w',delete=False) as f:json.dump(report,f);path=f.name
   self.assertEqual(emit_ui_evidence(path,event='pull_request',job_status='success',run_e2e=True,credentials=creds)['status'],'FAILED')
 def test_artifact_binding_rejects_wrong_subject_and_event(self):
  artifact=UI(); artifact['pr_head_sha']='d'*40
  with self.assertRaises(ObservationError):observe(Fake(),'o/r','a'*40,event='pull_request',ui=artifact,artifact=artifact)
  event_artifact=UI(); event_artifact['event']='workflow_dispatch'
  with self.assertRaises(ObservationError):observe(Fake(),'o/r','a'*40,event='pull_request',ui=event_artifact,artifact=event_artifact)
  with self.assertRaises(ObservationError):observe(Fake(),'o/r','a'*40,event='pull_request',ui=UI(),artifact=dict(UI()))
 def test_latest_in_progress_beats_old_success(self):
  rules=[{'type':'required_status_checks','parameters':{'required_status_checks':[{'context':'gate','integration_id':2}]}}]
  checks=[{'id':1,'name':'gate','app':{'id':2},'status':'completed','conclusion':'success','started_at':'2020'}, {'id':2,'name':'gate','app':{'id':2},'status':'in_progress','conclusion':None,'started_at':'2021'}]
  self.assertEqual(observe(Fake(checks,rules),'o/r','a'*40)['outcome'],'EVIDENCE_REPAIR')
 def test_recency_ties_fail_closed(self):
  item={'id':1,'name':'gate','app':{'id':2},'status':'completed','conclusion':'success','started_at':'2020'}
  with self.assertRaises(ObservationError):latest_checks([item,dict(item)])
  legacy={'context':'gate','state':'success','updated_at':'2020','id':1}
  with self.assertRaises(ObservationError):latest_legacy([legacy,dict(legacy)])
 def test_draft_workflow_guard_text(self):
  workflow=Path(__file__).resolve().parents[2].joinpath('.github/workflows/agent-evidence-observer.yml').read_text()
  self.assertIn("conclusion != 'cancelled'",workflow);self.assertIn("conclusion != 'skipped'",workflow)
if __name__=='__main__':unittest.main()
