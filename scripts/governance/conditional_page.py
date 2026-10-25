"""Unactivated #4385 conditional-page integration; no production wiring or storage.

The existing policy still validates every inventory and explicit attempt page.
An upstream trusted artifact/identity boundary is REQUIRED; a local hash does not
prove cache origin. This primitive supplies no approval, expiry or provenance.
"""
import copy
import hashlib
import json
import re


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()).hexdigest()


def valid_etag(value):
    return isinstance(value, str) and re.fullmatch(r'(?:W/)?"[a-fA-F0-9]{32,128}"', value) is not None


def weak_etag_equal(left, right):
    """If-None-Match uses weak comparison (RFC 9110); opaque tag is case-sensitive."""
    return valid_etag(left) and valid_etag(right) and left.removeprefix('W/') == right.removeprefix('W/')


def read_page(*, request_identity, fetch, cached=None, trusted_cache_digest=None):
    """fetch(if_none_match) returns (status, selected_etag, decoded_json).

    Always call the same authenticated GET, even on a warm cache. A bare cache hit
    is never data freshness. The caller must verify request_identity against its
    trusted repo/path/API-version/authz-scope/policy configuration and verify the
    artifact providing trusted_cache_digest. Neither can come from PR input.
    """
    # Validate and consume the SAME private snapshots throughout the operation.
    request_identity = copy.deepcopy(request_identity)
    cached = copy.deepcopy(cached)
    if not isinstance(request_identity, dict) or set(request_identity) != {'repo', 'path', 'api_version', 'authz_scope', 'policy_sha'}:
        raise ValueError('request identity incomplete')
    if (not all(isinstance(v, str) and v for v in request_identity.values())
            or not re.fullmatch('[a-f0-9]{40}', request_identity['policy_sha'])):
        raise ValueError('request identity malformed')
    # fetch may mutate caller-owned inputs. Validate and consume the SAME private
    # snapshot, so a server304 cannot bless a body changed during revalidation.
    validator = None
    if cached is not None:
        if (not isinstance(cached, dict) or set(cached) != {'identity', 'etag', 'data'}
                or trusted_cache_digest is None or digest(cached) != trusted_cache_digest
                or cached['identity'] != request_identity or not valid_etag(cached['etag'])):
            raise ValueError('trusted complete cache/validator unavailable')
        validator = cached['etag']
    status, etag, data = fetch(validator)
    if type(status) is not int:
        raise ValueError('response status unknown')
    if status == 304:
        if cached is None or not weak_etag_equal(etag, validator) or data is not None:
            raise ValueError('304 cannot establish a current page')
        return {'data': copy.deepcopy(cached['data']), 'cache': copy.deepcopy(cached),
                'freshness': 'same-request authenticated server304', 'http_calls': 1}
    if status != 200 or not isinstance(data, dict):
        raise ValueError('metadata GET failed or body incomplete; Hold')
    updated = {'identity': copy.deepcopy(request_identity), 'etag': etag, 'data': copy.deepcopy(data)} if valid_etag(etag) else None
    return {'data': copy.deepcopy(data), 'cache': updated, 'freshness': 'same-request authenticated server200', 'http_calls': 1}
