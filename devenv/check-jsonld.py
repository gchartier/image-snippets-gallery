#!/usr/bin/env python3
"""Check the JSON-LD embedded in a rendered gallery page.

Reads the page HTML on stdin. Prints one PASS/FAIL line per check and exits
non-zero if any failed, so verify.sh can fold the results into its totals.
"""
import json
import re
import sys

GREEN = "\033[32mPASS\033[0m"
RED = "\033[31mFAIL\033[0m"

failures = 0


def check(desc, cond, detail=""):
    global failures
    if not cond:
        failures += 1
    print(f"  {GREEN if cond else RED}  {desc}" + (f" — {detail}" if detail else ""))


html = sys.stdin.read()
blocks = re.findall(r'<script type="application/ld\+json">(.*?)</script>', html, re.S)

check("exactly one ld+json block", len(blocks) == 1, f"{len(blocks)} found")
if not blocks:
    sys.exit(1)

try:
    doc = json.loads(blocks[0])
    check("payload is valid JSON", True, f"{len(blocks[0])} bytes")
except Exception as exc:
    check("payload is valid JSON", False, str(exc))
    sys.exit(1)

graph = doc.get("@graph", [])
images = [n for n in graph if n.get("@type") == "ImageObject"]
named = [n for n in graph if "@graph" in n]
figures = html.count('<figure class="isgal-item"')

check(
    "schema.org is the default vocabulary",
    doc.get("@context", {}).get("@vocab") == "http://schema.org/",
)
check(
    "one ImageGallery node",
    sum(1 for n in graph if n.get("@type") == "ImageGallery") == 1,
)
check(
    "an ImageObject per rendered figure",
    len(images) == figures and figures > 0,
    f"{len(images)} nodes, {figures} figures",
)
check("a named graph per image", len(named) == len(images), f"{len(named)} graphs")

# Bare terms are what structured-data validators expect to see.
check(
    "schema.org terms are bare, not CURIEs",
    bool(images) and "contentUrl" in images[0] and "schema:contentUrl" not in images[0],
)
check(
    "images carry a resolved name",
    bool(images) and isinstance(images[0].get("name"), str) and bool(images[0]["name"]),
)

# The whole point of the exercise: statements schema.org cannot express,
# still present and still attributed to the graph that asserted them.
preds = set()
for ng in named:
    for node in ng["@graph"]:
        preds.update(k for k in node if not k.startswith("@"))
lio = sorted(p for p in preds if p.startswith("lio:"))
check("LIO statements survive into the payload", bool(lio), ", ".join(lio) or "none")
check(
    "named graphs keep their attribution",
    bool(named)
    and all(
        n.get("@id", "").startswith("https://imagesnippets.com/imgtag/rdf/")
        for n in named
    ),
)

# Nothing should reach a crawler as a bare identifier.
opaque = [a for n in images for a in n.get("about", []) if "name" not in a]
check("no unlabelled entities in about", not opaque, f"{len(opaque)} unlabelled")

sys.exit(1 if failures else 0)
