"""Import shim for the hyphenated Phase 1 receipt CLI."""
from importlib.util import module_from_spec, spec_from_file_location
from pathlib import Path

_spec = spec_from_file_location("agent_factory_receipt_impl", Path(__file__).parent / "agent-factory" / "receipt.py")
_module = module_from_spec(_spec)
assert _spec.loader
_spec.loader.exec_module(_module)
globals().update({name: getattr(_module, name) for name in dir(_module) if not name.startswith("_")})
