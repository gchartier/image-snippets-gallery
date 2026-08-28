#!/usr/bin/env python3
"""Check the block-editor's server-side render response.

Reads the block-renderer JSON on stdin. This is the path the editor preview
uses, so it covers the server half of the Refresh button.
"""
import json
import sys

GREEN = "\033[32mPASS\033[0m"
RED = "\033[31mFAIL\033[0m"

failures = 0


def check(desc, cond, detail=""):
    global failures
    if not cond:
        failures += 1
    print(f"  {GREEN if cond else RED}  {desc}" + (f" — {detail}" if detail else ""))


raw = sys.stdin.read()
try:
    html = json.loads(raw)["rendered"]
except Exception as exc:
    check("editor preview route responds", False, f"{exc}: {raw[:160]}")
    sys.exit(1)

figures = html.count('<figure class="isgal-item"')
check("editor preview rendered figures", figures > 0, f"{figures} figures")
check("editor preview carries JSON-LD", "application/ld+json" in html)

sys.exit(1 if failures else 0)
