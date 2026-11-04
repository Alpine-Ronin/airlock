# Airlock: design

Airlock is an MCP server that lets any agent run shell commands on your machine
without trusting the agent with your machine. Claude Code, Zed, VS Code or Cursor
connect to it like to any MCP server; every command they send passes through it.

## The problem

An agent runs commands it decided to run, from text it read. A README, a log, a
web page or a dependency's error message can tell it to run something else, and
it often does. Each client answers this its own way — a list of allowed command
prefixes here, an "allow for this session" there — and none of it travels: the
same user, in another client, starts from nothing.

Airlock moves the answer out of the client and into one server every client can
use: what runs, where it runs, what the user is asked, and what the agent is
allowed to read back.

## What happens to a command

1. **Read.** The command line is parsed the way a shell reads it: simple
   commands, pipes, `&&`, `;`, redirections, substitutions. A grant for
   `make test` covers `make test`, never `make test; rm -rf ~`.
2. **Weighed.** Each part is one of:
   - *plain*: covered by a grant, or harmless to run;
   - *asked every time*: `sudo`, a recursive delete, `git push`, a download piped
     into a shell, one that names a secret file (`.env`, `id_rsa`, `*.pem`), or
     one that cannot be read whole (`$(...)`, a here-document). No grant covers it;
   - *refused*: what the project's policy forbids outright.
3. **Asked.** Anything not covered goes to the user as a form, in their own
   client: what will run, why it is asked, and a choice of *once*, *this
   session*, *always for this project* or *refuse*. A command asked every time
   only offers *once* or *refuse*.
4. **Run.** In a sandbox: bubblewrap on Linux, `sandbox-exec` on macOS. The
   command can write only to the project, `/tmp` and package caches, and cannot
   read Airlock's own files or the user's keys; with `strict`, it has no network.
   Where no sandbox can run, Airlock says so, and asks about every command.
5. **Checked.** Its output goes back to the agent marked as data, not
   instructions, between tags it cannot close, with API keys masked. A model
   reads it first: output that looks like an injection is flagged, so the agent
   and the user both see that it tried, and the audit log keeps it.

Every decision is appended to an audit log, readable as the MCP resource
`airlock://audit`.

## The protocol

Airlock is written for MCP 2026-07-28. A form is not a request the server sends
mid-call any more: the tool returns an `input_required` result naming the form,
and the client calls again with the answer (*Multi Round-Trip Requests*). Claude
Code speaks this already. The PHP SDK serves the same tool to clients that only
know 2025-11-25, by turning the result into a classic `elicitation/create`
request and running the tool again with the answer; the tool cannot tell which
happened. Both paths are tested.

The state that rides along with the retry comes back from the client, so it is
attacker-controlled: Airlock signs it, and it names the exact command it was
minted for, so an answer for `make test` cannot be replayed to run anything else.

Airlock sees only the commands that come through `run_command`: an agent that
keeps its client's own shell tool can run anything without it, and no MCP
message can take that tool away. `airlock connect` does it from the client's
files where the client allows it (Claude Code's deny rules, Codex's
`shell_tool` feature, Goose's tool filter), and says what to do by hand where
no file can (Cursor, VS Code). It edits those files in place and keeps what
else they hold: a JSON file with comments, or a TOML table written as dotted
keys, is left to the user rather than rewritten. It keeps each file as it was
before it first changed it, in its own directory, and `--remove` reads that
to put back a setting the user had rather than delete it.

Sampling, which once let a server borrow the client's model, is deprecated since
2026-07-28 in favour of calling a provider directly — which is what Airlock does
through Symfony AI.

## What Symfony AI does

Both calls go through `symfony/ai-platform` and its generic bridge to any API
that speaks OpenAI's chat completions, with Symfony AI's structured output: the
answer is a field, not prose to parse. The API, its key and the models are
never in the code and have no default. `airlock configure` sets them up in a
terminal, so the key never passes through the agent (an MCP form must not
carry secrets): it lists the API's models and calibrates them with the very
classifier and explainer the server uses (flag a known injection, pass a
harmless output, sum up `rm -rf build` in one line), then writes
`models.yaml`, 0600, in the configuration directory no command can see. An
`AIRLOCK_AI_*` environment variable, when set, wins over the file. Airlock was
tested with NVIDIA's API catalog, which has a free tier, and the two models
below.

- **Output check.** `minimaxai/minimax-m3.1-flash` reads the output, between
  tags, and answers `clean` or `injection`. A long output is read in
  overlapping chunks; one chunk flagged flags it all. NVIDIA's dedicated
  safety models (`nemoguard-jailbreak-detect`, Llama Guard, the Nemotron
  safety guards) were tried first and missed an injected README every time:
  they moderate content, they do not look for instructions aimed at an agent.
  Hugging Face's `protectai/deberta-v3-base-prompt-injection-v2` would fit,
  but serving it through Inference Providers takes paid credits.
- **Risk in plain words.** For a command asked every time,
  `nvidia/nemotron-3.5-bolt-e4b` writes one line saying what it would do
  ("deletes build/ and everything in it"), shown in the form under the rule,
  never instead of it, and labelled as a model's reading. It sees the command
  line only, and an answer that is not one short line is dropped.

Both read attacker-controlled text, so neither decides anything: a model fooled
into "clean" leaves the output marked as data with its secrets masked, and the
plain-words line never changes what the user may answer.

Both are optional. Until the API and a model are set, or when the model is slow, rate
limited or down, the output is marked as not checked, saying why, and the form
shows the rule that triggered; nothing about running a command depends on a
model being reachable.

## Built like it should be

Symfony 8.1, the MCP bundle, PHP 8.5. Every class `final`, value objects
`readonly`, `declare(strict_types=1)` everywhere, PHPStan at its highest level
with strict rules and no baseline, PHP CS Fixer on `@Symfony` and its risky
set, Rector, and one `composer check` that CI runs. PHPUnit: the parser and the
policy by their public API; the server through both protocol eras over stdio;
the model calls against recorded responses, so the tests never reach the
network. And tested by hand in a real
client before each change is merged.

Grants and the audit log are files under the user's configuration directory,
written under a `symfony/lock` lock: two agents can share one Airlock.

## Not in scope

- Being a security boundary against a malicious *user*: Airlock protects a user
  from what their agent read, not a machine from its owner.
- Windows: the sandboxes are Linux's and macOS's.
- Replacing the client's own approvals: Airlock adds a layer every client
  shares; a client may still ask before calling it.

## Status and roadmap

Done: the parser, the policy, both sandboxes, the form and its grants, the
audit log, the model checks, `airlock connect` for five clients, and a static
binary per platform.

Next, in this order:

- Every client `connect` writes for, run end to end, and a table saying what
  works in each.
- Grants and the audit log managed from the terminal: list and revoke grants,
  read recent decisions; and the log rotated past a size.
- `airlock doctor`: the sandbox, the connected clients, the models and the
  file permissions checked in one command, each problem with its fix.
- Releases that prove where they come from (build provenance, an SBOM, a
  signed and notarized macOS binary) and a parser fuzzed against grants that
  cover more than they say.
- 1.0: the policy, grants and audit formats documented and kept stable.
