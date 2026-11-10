"""Starts Airlock the way a client does, with the command given after --, and runs one command through it.

The first call must return the form, sandboxed (bubblewrap or sandbox-exec); the retry with the signed state and "once" must run
the command and return its output marked as data, saying --expect's text when given. --command and --output replace the
command run and the output it must print.
"""

import json
import subprocess
import sys

META = {
    "io.modelcontextprotocol/protocolVersion": "2026-07-28",
    "io.modelcontextprotocol/clientCapabilities": {"elicitation": {"form": {}}},
}
COMMAND = "echo airlock-smoke > smoke.txt && cat smoke.txt"


def call(server, request_id, command, extra):
    params = {"name": "run_command", "arguments": {"command": command}, "_meta": META, **extra}
    server.stdin.write(json.dumps({"jsonrpc": "2.0", "id": request_id, "method": "tools/call", "params": params}) + "\n")
    server.stdin.flush()
    line = server.stdout.readline()
    if not line:
        sys.exit("The server closed its output:\n" + server.stderr.read())
    return json.loads(line)["result"]


def main():
    separator = sys.argv.index("--")
    options = sys.argv[1:separator]
    expected = options[options.index("--expect") + 1] if "--expect" in options else None
    command = options[options.index("--command") + 1] if "--command" in options else COMMAND
    output = options[options.index("--output") + 1] if "--output" in options else "airlock-smoke"
    server = subprocess.Popen(sys.argv[separator + 1:], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    try:
        asked = call(server, 1, command, {})
        assert asked.get("resultType") == "input_required", asked
        message = asked["inputRequests"]["approval"]["params"]["message"]
        assert "Sandbox (bubblewrap)" in message or "Sandbox (sandbox-exec)" in message, message

        ran = call(server, 2, command, {"requestState": asked["requestState"], "inputResponses": {"approval": {"action": "accept", "content": {"decision": "once"}}}})
        text = ran["content"][0]["text"]
        assert not ran.get("isError"), text
        assert '<command-output exit-code="0">\n' + output + '\n</command-output>' in text, text
        assert expected is None or expected in text, text
        print(text)
    finally:
        server.stdin.close()
        server.wait(timeout=30)


main()
