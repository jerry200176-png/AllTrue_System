"""Query the existing AllTrue data API through an approved credential-injecting relay."""

import argparse
import ipaddress
import json
import os
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener

IDENTIFIER = re.compile(r"[A-Za-z][A-Za-z0-9_]{0,63}")
READ_PATH = "/api/v1/triage/db"


class NoRedirects(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def origin(value):
    parts = urlsplit(value)
    if (not parts.hostname or parts.username is not None or parts.password is not None
            or parts.path not in {"", "/"} or parts.query or parts.fragment):
        raise ValueError("Configure an approved relay origin without a path or credentials.")
    if parts.scheme == "http":
        try:
            local = ipaddress.ip_address(parts.hostname).is_loopback
        except ValueError:
            local = False
        if not local:
            raise ValueError("A remote relay must use HTTPS.")
    elif parts.scheme != "https":
        raise ValueError("Use HTTPS or an HTTP loopback relay.")
    parts.port  # Validate the configured port before using the origin.
    if parts.hostname.lower().rstrip(".") == "daan.lifenet.com.tw":
        raise ValueError("Use the approved credential-injecting relay for this WSL client.")
    return value.rstrip("/")


def identifier(value):
    if not IDENTIFIER.fullmatch(value):
        raise argparse.ArgumentTypeError("Expected a table or column identifier.")
    return value


def filter_argument(value):
    column, separator, term = value.partition("=")
    if not separator or not term:
        raise argparse.ArgumentTypeError("Expected COLUMN=VALUE.")
    return identifier(column), term


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--gateway", default=os.environ.get("ALLTRUE_READ_GATEWAY_URL"))
    parser.add_argument("table", nargs="?", type=identifier, help="Omit to list readable tables.")
    parser.add_argument("--eq", type=filter_argument, action="append", default=[])
    parser.add_argument("--gte", type=filter_argument, action="append", default=[])
    parser.add_argument("--lte", type=filter_argument, action="append", default=[])
    parser.add_argument("--in", dest="in_values", type=filter_argument, action="append", default=[])
    parser.add_argument("--order", type=identifier)
    parser.add_argument("--limit", type=int, default=50)
    args = parser.parse_args(argv)
    if not args.gateway:
        parser.exit(2, "No approved relay configured. Set ALLTRUE_READ_GATEWAY_URL.\n")
    if not 1 <= args.limit <= 200:
        parser.error("--limit must be between 1 and 200.")

    try:
        target = origin(args.gateway) + READ_PATH
        if args.table:
            target += "/" + args.table
            filters = {"eq": args.eq, "gte": args.gte, "lte": args.lte, "in": args.in_values}
            params = [(f"{op}[{column}]", term) for op, entries in filters.items() for column, term in entries]
            params.append(("limit", args.limit))
            if args.order:
                params.append(("order", args.order))
            target += "?" + urlencode(params)
        elif args.eq or args.gte or args.lte or args.in_values or args.order:
            parser.error("Specify a table before filtering.")

        # Credentials are added at the approved relay. Ambient proxies and redirects are disabled.
        opener = build_opener(ProxyHandler({}), NoRedirects())
        with opener.open(Request(target, headers={"Accept": "application/json"}), timeout=15) as response:
            data = json.load(response)
        print(json.dumps(data, ensure_ascii=False, indent=2))
    except HTTPError as error:
        parser.exit(1, f"AllTrue read request failed (HTTP {error.code}).\n")
    except (URLError, TimeoutError, OSError, ValueError):
        parser.exit(1, "AllTrue read failed. Verify the approved relay address and connection.\n")


if __name__ == "__main__":
    main()
