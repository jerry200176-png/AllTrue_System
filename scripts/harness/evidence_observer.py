"""Fail-closed, read-only GitHub evidence observation (never canonical evidence)."""
from __future__ import annotations
import argparse, hashlib, json, os, re
from typing import Any, Callable
from urllib.parse import urlencode
from urllib.request import Request, urlopen

MAX_PAGES=100; TERMINAL={"failure","cancelled","timed_out","action_required"}; LEGACY_FAIL={"failure","error"}; PENDING={"pending","in_progress","queued","requested","waiting","blocked"}; SKIP={"skipped","neutral"}
class ObservationError(ValueError): pass
class Provenance:
 def __init__(self,provider,collection_method,agent_cli=None): self.provider,self.collection_method,self.agent_cli=provider,collection_method,agent_cli
 def to_dict(self): return {"provider":self.provider,"collection_method":self.collection_method,"agent_cli":self.agent_cli}
class GitHubClient:
 def __init__(self,token=None,api_root="https://api.github.com"): self.root,self.token=api_root.rstrip("/"),token
 def get(self,path,params=None):
  q=urlencode({k:v for k,v in (params or {}).items() if v is not None}); h={"Accept":"application/vnd.github+json"};
  if self.token: h["Authorization"]="Bearer "+self.token
  with urlopen(Request(self.root+path+("?"+q if q else ""),headers=h,method="GET"),timeout=30) as r:return json.load(r)
def paginate(fetch:Callable[[int],Any],*,per_page=100,max_pages=MAX_PAGES):
 out=[]; seen=set(); total=None
 for page in range(1,max_pages+1):
  raw=fetch(page)
  if isinstance(raw,list): items=raw; count=None
  elif isinstance(raw,dict):
   keys=[k for k in ("items","check_runs","statuses","workflow_runs") if k in raw]
   if len(keys)!=1 or not isinstance(raw[keys[0]],list): raise ObservationError("malformed_page")
   items,count=raw[keys[0]],raw.get("total_count")
  else: raise ObservationError("malformed_page")
  if not all(isinstance(x,dict) for x in items): raise ObservationError("malformed_item")
  digest=hashlib.sha256(json.dumps(items,sort_keys=True).encode()).hexdigest()
  if digest in seen: raise ObservationError("duplicate_page")
  seen.add(digest); out.extend(items)
  if count is not None:
   if not isinstance(count,int) or count<0: raise ObservationError("malformed_total")
   total=count
  if total is not None and len(out)>=total:return out[:total],len(out)==total
  if (total is None and len(items)<per_page) or (total is not None and not items):return out,total is None or len(out)==total
 raise ObservationError("pagination_limit")
def _criteria(text):
 m=re.search(r"^(#{1,6})\s*Acceptance Criteria\s*$",text,re.I|re.M)
 if not m:return "",0
 level=len(m.group(1)); rest=text[m.end():]; end=re.search(r"^(#{1,%d})\s+\S"%level,rest,re.M); return (rest[:end.start()] if end else rest),level
def parse_acceptance_criteria(text):
 section,_=_criteria(text); out={}; fenced=False
 for n,line in enumerate(section.splitlines(),1):
  if "```" in line:fenced=not fenced;continue
  if fenced:continue
  for m in re.finditer(r"\bAC-(\d{3})\b",line,re.I):out.setdefault("AC-"+m.group(1),[]).append(f"line:{n}:{line.strip()}")
 return dict(sorted(out.items()))
def parse_evidence_map(text):
 m=re.findall(r"(?is)^##\s+Acceptance Evidence Map\s*$.*?^```json\s*$\n(.*?)^```\s*$",text,re.M)
 if len(m)!=1:raise ObservationError("evidence_map_count")
 try: value=json.loads(m[0],object_pairs_hook=lambda pairs: _unique(pairs))
 except (json.JSONDecodeError,ObservationError) as e:raise ObservationError("evidence_map_json") from e
 if not isinstance(value,dict) or not value:raise ObservationError("evidence_map_shape")
 for k,v in value.items():
  if not re.fullmatch(r"AC-\d{3}",k,re.I) or not isinstance(v,list) or not v or not all(isinstance(x,str) and x for x in v):raise ObservationError("evidence_map_entry")
 return {k.upper():v for k,v in value.items()}
def _unique(pairs):
 d={}
 for k,v in pairs:
  if k in d:raise ObservationError("duplicate_json_key")
  d[k]=v
 return d
def bind_acceptance(text,refs=()):
 criteria,_=_criteria(text)
 if not criteria.strip():raise ObservationError("criteria_missing")
 ids=parse_acceptance_criteria(text); mapped=parse_evidence_map(text); a,b=set(ids),set(mapped)
 duplicate=sorted(k for k,v in ids.items() if len(v)!=1); unknown=sorted(b-a); missing=sorted(a-b); badrefs=sorted({x for vals in mapped.values() for x in vals if x!="ui-smoke" and not re.fullmatch(r"check:[^:]+:(?:null|[0-9]+)",x)}); unresolved=sorted(k for k,vals in mapped.items() if any(x not in refs for x in vals)); unchecked=sorted(k for k in ids if re.search(r"\[\s*\]", " ".join(ids[k])) or not re.search(r"\[x\]", " ".join(ids[k]),re.I))
 return {"criteria":ids,"evidence_map":mapped,"duplicate":duplicate,"unknown":unknown,"missing":missing,"bad_refs":badrefs,"unresolved":unresolved,"unchecked":unchecked,"valid":not(duplicate or unknown or missing or badrefs or unresolved or unchecked)}
def required_status_tuples(rules):
 if not isinstance(rules,list):raise ObservationError("rules_list_required")
 out=[]; seen=set()
 for rule in rules:
  if not isinstance(rule,dict):raise ObservationError("rules_schema")
  p=rule.get("parameters",{}); checks=p.get("required_status_checks") if isinstance(p,dict) else None
  if rule.get("type")!="required_status_checks":continue
  if not isinstance(checks,list):raise ObservationError("rules_schema")
  for x in checks:
   if not isinstance(x,dict) or not isinstance(x.get("context"),str):raise ObservationError("rules_schema")
   i=x.get("integration_id");
   if i is not None and (not isinstance(i,int) or i<0):raise ObservationError("rules_schema")
   t=(x["context"],i)
   if t in seen:raise ObservationError("duplicate_required_status")
   seen.add(t);out.append(t)
 return out
def latest_checks(items):
 out={}
 for x in items:
  n=x.get("name"); app=x.get("app") or {}; i=app.get("id") if isinstance(app,dict) else None
  if not isinstance(n,str) or (i is not None and not isinstance(i,int)):raise ObservationError("check_schema")
  key=(n,i); stamp=(x.get("started_at") or x.get("created_at") or "",x.get("id") or -1)
  if key in out and stamp==out[key]["_stamp"]:raise ObservationError("ambiguous_check_recency")
  if key not in out or stamp>out[key]["_stamp"]:out[key]={"name":n,"app_id":i,"check_id":x.get("id"),"status":x.get("status"),"conclusion":x.get("conclusion"),"_stamp":stamp}
 return out
def validate_check(item, *, legacy=False):
 state=item.get("state") if legacy else item.get("status"); conclusion=item.get("state") if legacy else item.get("conclusion")
 if legacy:
  if conclusion in {"failure","error"}:return "failed"
  if conclusion=="success":return "success"
  if conclusion in PENDING:return "pending"
  raise ObservationError("unknown_legacy_state")
 if state!="completed":
  if state in {"queued","in_progress","requested","waiting"}:return "pending"
  raise ObservationError("unknown_check_status")
 if conclusion=="success":return "success"
 if conclusion in TERMINAL|{"failure","error"}:return "failed"
 if conclusion in SKIP|PENDING:return "pending"
 raise ObservationError("unknown_check_conclusion")
def latest_legacy(items):
 out={}
 for x in items:
  context=x.get("context")
  if not isinstance(context,str):raise ObservationError("legacy_schema")
  stamp=(x.get("updated_at") or "",x.get("id") or -1)
  if context in out and stamp==out[context]["_stamp"]:raise ObservationError("ambiguous_legacy_recency")
  if context not in out or stamp>out[context]["_stamp"]:out[context]={**x,"_stamp":stamp}
 return out
KNOWN_CREDS=("SMOKE_BASE_URL","SMOKE_DIRECTOR_USER","SMOKE_DIRECTOR_PASS","SMOKE_TEACHER_USER","SMOKE_TEACHER_PASS","SMOKE_PARENT_STUDENT_NAME","SMOKE_PARENT_PHONE")
UI_KEYS={"schema","status","event","pr_head_sha","tested_sha","github_sha","base_sha","relevant","missing_credential_names","relevant_playwright_skipped","passed","skipped","failed","acceptance_relevant_set","test_counts"}
def classify_ui(ui):
 if ui is None:return "NOT_APPLICABLE"
 if not isinstance(ui,dict) or set(ui)!=UI_KEYS:raise ObservationError("ui_keys")
 if ui.get("schema")!="ui-smoke-evidence-v1":raise ObservationError("ui_schema")
 if not isinstance(ui.get("relevant"),bool) or not isinstance(ui.get("missing_credential_names"),list) or tuple(ui["missing_credential_names"])!=tuple(x for x in KNOWN_CREDS if x in ui["missing_credential_names"]):raise ObservationError("ui_schema")
 if not all(type(ui.get(x)) is bool for x in ("passed","skipped","failed","relevant_playwright_skipped")):raise ObservationError("ui_schema")
 counts=ui.get("test_counts")
 if not isinstance(counts,dict) or set(counts)!={"passed","skipped","failed"} or not all(type(v) is int and 0<=v<=100000 for v in counts.values()):raise ObservationError("ui_schema")
 if ui["relevant_playwright_skipped"] != (counts["skipped"]>0):raise ObservationError("ui_contradiction")
 if ui.get("status") not in {"NOT_APPLICABLE","PASSED","SKIPPED","FAILED"}:raise ObservationError("ui_status")
 if ui.get("relevant") is False:
  if ui["status"]!="NOT_APPLICABLE" or any(ui[x] for x in ("passed","skipped","failed")) or any(counts.values()) or ui["missing_credential_names"]:raise ObservationError("ui_contradiction")
  return "NOT_APPLICABLE"
 required={"status","relevant","missing_credential_names","passed","skipped","failed"}
 if not required.issubset(ui):raise ObservationError("ui_schema")
 if ui["status"]=="FAILED" and ui["failed"] and not ui["passed"] and not ui["skipped"]:return "FAILED"
 if ui["status"]=="SKIPPED" and ui["skipped"] and not ui["failed"] and (ui["missing_credential_names"] or counts["skipped"]>0):return "SKIPPED"
 if ui["status"]=="PASSED" and ui["passed"] and not ui["skipped"] and not ui["failed"] and not ui["missing_credential_names"] and counts["passed"]>0 and not counts["skipped"] and not counts["failed"]:return "PASSED"
 raise ObservationError("ui_contradiction")
def sanitize_ui(ui):
 status=classify_ui(ui)
 if ui is None:return None
 if ui["event"] not in {"pull_request","workflow_dispatch","schedule"}:raise ObservationError("ui_event")
 for key in ("pr_head_sha","tested_sha","github_sha","base_sha"):
  if not isinstance(ui.get(key),str) or not re.fullmatch(r"[0-9a-fA-F]{40}",ui[key]):raise ObservationError("ui_provenance")
 if ui["tested_sha"].lower()==ui["pr_head_sha"].lower() or ui["tested_sha"].lower()!=ui["github_sha"].lower():raise ObservationError("ui_sha_contradiction")
 return {k:ui[k] for k in UI_KEYS if k in ui and k not in {"relevant_frontend"}}
def emit_ui_evidence(report_path, *, event, job_status, run_e2e, credentials, pr_head_sha=None, base_sha=None, tested_sha=None, github_sha=None):
 missing=[x for x in KNOWN_CREDS if not credentials.get(x)] if run_e2e else []
 counts={"passed":0,"skipped":0,"failed":0}; report_ok=False
 def visit(node,relevant=False):
  if not isinstance(node,(dict,list)):raise ObservationError("report_shape")
  if isinstance(node,dict):
   relevant=relevant or "smoke.spec.js" in str(node.get("file",""))
   if "specs" in node and not isinstance(node["specs"],list):raise ObservationError("report_specs")
   if relevant:
    for spec in node.get("specs",[]):
     if not isinstance(spec,dict) or not isinstance(spec.get("tests",[]),list):raise ObservationError("report_spec")
     for test in spec.get("tests",[]):
      if not isinstance(test,dict) or not isinstance(test.get("results",[]),list):raise ObservationError("report_test")
      for result in test.get("results",[]):
       if not isinstance(result,dict) or result.get("status") not in {"passed","skipped","failed","timedOut","interrupted"}:raise ObservationError("report_result")
       state=result.get("status")
       if state=="passed":counts["passed"]+=1
       elif state=="skipped":counts["skipped"]+=1
       elif state in {"failed","timedOut","interrupted"}:counts["failed"]+=1
   for value in node.values():
    if isinstance(value,(dict,list)):visit(value,relevant)
  elif isinstance(node,list):
   for value in node:
    if isinstance(value,(dict,list)):visit(value,relevant)
 try:
  with open(report_path,encoding="utf-8") as f:visit(json.load(f));report_ok=True
 except (OSError,json.JSONDecodeError,TypeError,ObservationError):pass
 if not run_e2e:status="NOT_APPLICABLE"
 elif job_status in {"failure","cancelled"} or not report_ok:status="FAILED"
 elif missing or counts["skipped"]:status="SKIPPED"
 elif counts["failed"] or counts["passed"]==0:status="FAILED"
 else:status="PASSED"
 return {"schema":"ui-smoke-evidence-v1","status":status,"event":event,"pr_head_sha":pr_head_sha,"tested_sha":tested_sha,"github_sha":github_sha,"base_sha":base_sha,"relevant":bool(run_e2e),"missing_credential_names":missing,"relevant_playwright_skipped":counts["skipped"]>0,"passed":status=="PASSED","skipped":status=="SKIPPED","failed":status=="FAILED","acceptance_relevant_set":"frontend/e2e/smoke.spec.js","test_counts":counts}
def observe(client,repo,subject_sha,*,base_ref="main",criteria_text="",ui=None,provenance=None,repo_run_id=None,run_attempt=None,event=None,collector_sha=None,base_sha=None,artifact=None):
 if event not in {None,"pull_request"}:raise ObservationError("event_not_pull_request")
 if artifact is not None and artifact is not ui:raise ObservationError("artifact_object_mismatch")
 if artifact is not None:
  if not isinstance(artifact,dict):raise ObservationError("artifact_schema")
  for key in ("event","pr_head_sha","base_sha","tested_sha","github_sha","acceptance_relevant_set"):
   if key not in artifact:raise ObservationError("artifact_missing_"+key)
  if artifact["event"]!="pull_request" or event not in {None,artifact["event"]} or artifact["pr_head_sha"]!=subject_sha or (base_sha is not None and artifact["base_sha"]!=base_sha) or artifact["acceptance_relevant_set"]!="frontend/e2e/smoke.spec.js":raise ObservationError("artifact_binding")
 checks,cok=paginate(lambda p:client.get(f"/repos/{repo}/commits/{subject_sha}/check-runs",{"page":p,"per_page":100,"filter":"latest"})); statuses,sok=paginate(lambda p:client.get(f"/repos/{repo}/commits/{subject_sha}/status",{"page":p,"per_page":100}))
 rules,rok=paginate(lambda p:client.get(f"/repos/{repo}/rules/branches/{base_ref}",{"page":p,"per_page":100})); required=required_status_tuples(rules); latest=latest_checks(checks); failed=pending=missing=False
 refs=set(); invalid_checks=False
 for item in latest.values():
  try: item["validated"]=validate_check(item)
  except ObservationError: invalid_checks=True
 for (name,app_id),item in latest.items():
  if item.get("validated")=="success": refs.add(f"check:{name}:{app_id if app_id is not None else 'null'}")
 for context,integration in required:
  found=[x for (n,i),x in latest.items() if n==context and (integration is None or i==integration)]
  if integration is None and found: found=[max(found,key=lambda x:(x.get("completed_at") or "",x.get("started_at") or "",x.get("check_id") or -1))]
  if not found and integration is None:
   legacy=latest_legacy(statuses); found=[legacy[context]] if context in legacy else []
  if not found:missing=True;continue
  try: state=validate_check(found[0],legacy=("state" in found[0] and "status" not in found[0]))
  except ObservationError: invalid_checks=True; state="pending"
  failed|=state=="failed"; pending|=state=="pending"
 ui_status=classify_ui(ui) if ui is not None else "NOT_APPLICABLE"
 sanitized_ui=sanitize_ui(ui)
 if ui_status=="PASSED" and ui is not None:
  refs.add("ui-smoke"); refs.add("ui:"+hashlib.sha256(json.dumps(ui,sort_keys=True,separators=(",",":")).encode()).hexdigest()+":PASSED")
 acceptance=None; invalid=False
 if criteria_text:
  try:acceptance=bind_acceptance(criteria_text,refs);invalid=not acceptance["valid"]
  except ObservationError as e:acceptance={"valid":False,"error":str(e)};invalid=True
 outcome="VERIFICATION_FAILED" if failed or ui_status=="FAILED" else ("EVIDENCE_REPAIR" if invalid_checks or missing or pending or invalid or ui_status=="SKIPPED" or not (cok and sok and rok) else ("OBSERVED_COMPLETE" if criteria_text and ui is not None else "OBSERVED_INCOMPLETE"))
 return {"schema":"github-observer-observation-v1","authoritative":False,"claimable":False,"model":None,"profile":None,"repo":repo,"subject_sha":subject_sha,"base_ref":base_ref,"base_sha":base_sha,"artifact":sanitized_ui,"run_id":repo_run_id,"run_attempt":run_attempt,"event":event,"collector_sha":collector_sha,"required_statuses":required,"checks":[{k:v for k,v in x.items() if k!="_stamp"} for x in latest.values()],"legacy_statuses":statuses,"ui_smoke":{"status":ui_status},"acceptance":acceptance,"pagination":{"checks":cok,"statuses":sok,"rules":rok,"complete":cok and sok and rok},"outcome":outcome,"provenance":(provenance or Provenance("github","rest")).to_dict()}
def main(argv=None):
 p=argparse.ArgumentParser();p.add_argument("--repo",required=True);p.add_argument("--sha",required=True);p.add_argument("--base-ref",default="main");p.add_argument("--criteria-file");p.add_argument("--ui-file");p.add_argument("--run-id");p.add_argument("--run-attempt");p.add_argument("--event");p.add_argument("--collector-sha");p.add_argument("--base-sha");p.add_argument("--output",required=True);a=p.parse_args(argv)
 criteria=open(a.criteria_file,encoding="utf-8").read() if a.criteria_file else "";ui=json.load(open(a.ui_file,encoding="utf-8")) if a.ui_file else None
 result=observe(GitHubClient(os.environ.get("GITHUB_TOKEN")),a.repo,a.sha,base_ref=a.base_ref,criteria_text=criteria,ui=ui,repo_run_id=a.run_id,run_attempt=a.run_attempt,event=a.event,collector_sha=a.collector_sha,base_sha=a.base_sha,artifact=ui)
 with open(a.output,"w",encoding="utf-8") as f:json.dump(result,f,indent=2,sort_keys=True);f.write("\n")
 return 0
if __name__=="__main__":raise SystemExit(main())
