"""Checks a release's SBOM against the lock file: it must list each package the PHAR holds, at its locked version, and
no other, such as a development tool.

Usage: check_sbom.py composer.lock airlock.cdx.json
"""

import json
import sys


def locked_packages(lock_path):
    with open(lock_path) as lock_file:
        lock = json.load(lock_file)
    return {f"pkg:composer/{package['name']}@{package['version']}" for package in lock["packages"]}


def listed_packages(sbom_path):
    with open(sbom_path) as sbom_file:
        sbom = json.load(sbom_file)
    if sbom.get("bomFormat") != "CycloneDX":
        sys.exit(f"{sbom_path} is not a CycloneDX SBOM.")
    return {component["purl"] for component in sbom.get("components", [])}


locked = locked_packages(sys.argv[1])
listed = listed_packages(sys.argv[2])
missing = sorted(locked - listed)
extra = sorted(listed - locked)
if missing or extra:
    sys.exit(f"The SBOM does not match the lock file.\nMissing: {missing}\nNot in the PHAR: {extra}")
print(f"The SBOM lists the {len(locked)} packages the PHAR holds, and no other.")
