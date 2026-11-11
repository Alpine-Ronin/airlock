# Airlock: file formats

Status: describes 0.6.0. Not yet a promise: 1.0 is when these formats are kept stable (see
[the roadmap](design.md#status-and-roadmap)), and the open questions at the end come first.

Airlock keeps everything it reads or writes in a few files: the policy and the network a user writes, the grants it
writes for them, and the audit log. This page says what each holds, which values are accepted, and what Airlock does
with a file it cannot read.

## Where they are

Airlock's directory is `$XDG_CONFIG_HOME/airlock`, or `~/.config/airlock` when that variable is not set. It is open to
its owner only, and out of every command's reach.

| File | Written by | Read by |
|---|---|---|
| `policy.yaml` | the user | the policy, for every project |
| `projects/<name>-<hash>/policy.yaml` | the user | the policy, for one project |
| `<project>/.airlock.yaml` | the project, committed | the policy, for that project |
| `network.yaml` | the user | the sandbox, for every project |
| `projects/<name>-<hash>/network.yaml` | the user | the sandbox, for one project |
| `projects/<name>-<hash>/grants.yaml` | Airlock (`always`, `airlock grants`) | the policy, for one project |
| `audit.jsonl`, `audit.1.jsonl` to `audit.5.jsonl` | Airlock | `airlock log`, `airlock doctor`, `airlock://audit` |
| `models.yaml` | `airlock configure` | the model checks |

`<name>-<hash>` is the project directory's base name, with every run of characters other than `A-Z a-z 0-9 . _ -`
replaced by `-`, then `-` and the first 12 hexadecimal characters of the SHA-256 of the project's absolute path:
`projects/shop-1a2b3c4d5e6f`. Moving or renaming a project gives it another directory, and so new grants.

`originals/` holds the client files as they were before `airlock connect` first changed them, for `--remove`. Its
names and content are internal.

## Policy: `policy.yaml`, `.airlock.yaml`

A policy can only refuse. Every policy file that applies is read, and what they refuse adds up.

```yaml
refuse:
  - 'terraform destroy'
  - 'make deploy'
refuse_hosts:
  - api.github.com
```

| Key | Type | Meaning |
|---|---|---|
| `refuse` | list of non-empty strings | Command lines that never run, and are not asked about |
| `refuse_hosts` | list of non-empty strings | Host names a sandboxed command never reaches, on any port, registries included |

- A `refuse` entry is read as a shell reads it, into words. A part of a line is refused when its words start with the
  entry's words; the program is compared by its base name (`/usr/bin/rm` is `rm`), also behind a wrapper such as
  `timeout` or `env`.
- A `refuse_hosts` entry is a host name, compared exactly; no wildcard.
- Both keys are optional; an empty file refuses nothing.

## Network: `network.yaml`

Only the user's own files: a project's `.airlock.yaml` can remove hosts (`refuse_hosts`), never add one.

```yaml
allow:
  - example.com          # port 443
  - internal.example:8443
local:
  - 5432
```

| Key | Type | Meaning |
|---|---|---|
| `allow` | list of non-empty strings | Hosts a command may reach besides the registries: `host` (port 443) or `host:port`. Exact names, no wildcard, no URL |
| `local` | list of integers, 1 to 65535 | Ports on `127.0.0.1` a command may reach, such as a database its tests use. Not the relay's own port, 3128 |

## Grants: `grants.yaml`

What *Always allow in this project* allowed, written by Airlock, readable by its owner only. A user can edit it, or
take a grant back with `airlock grants --revoke <number>`.

```yaml
grants:
    -
        command: [make, test]
        prefix: true
        sources: [{ path: Makefile, sha256: 89520b0db4d2c5a317665f520eacf8250b743087cb82cecfd357542e72be8bee }]
```

| Key | Type | Meaning |
|---|---|---|
| `grants` | list of entries | |
| `command` | non-empty list of strings | The command's words, as the shell reads them |
| `prefix` | boolean | `true`: covers the command and longer ones starting with its words (`make test …`). `false`: covers exactly these words |
| `sources` | list of `{path, sha256}`, optional | Files deciding what the command does, relative to the project, and the SHA-256 of their content when it was allowed. The grant holds only while each is unchanged. An empty `sha256` is a file that could not be read: it never matches |

The program in `command` is compared as written: a grant for `make` does not cover `/usr/bin/make`.

## Audit log: `audit.jsonl`

One JSON object per line, appended for every decision, in every project. At 10 MiB the file moves to `audit.1.jsonl`,
the one before to `audit.2.jsonl`, and so on up to `audit.5.jsonl`; the oldest goes.

```json
{"at":"2026-11-11T13:09:52+00:00","project":"/home/you/shop","command":"cat README.md","verdict":"plain","outcome":"ran_and_granted_for_session","reasons":[],"exit_code":0,"timed_out":false,"detail":null,"output_check":"injection","output_check_reason":null,"masked_secrets":0,"blocked_connections":[],"blocked_count":0}
```

Every key is written on every line, `null` when it does not apply.

| Key | Type | Meaning |
|---|---|---|
| `at` | string | When, ISO 8601 with its offset |
| `project` | string | The project's absolute path |
| `command` | string | The command line as the agent sent it |
| `verdict` | `plain`, `ask_every_time`, `refused` | What the policy found: nothing dangerous; asked each time, no grant covers it; never runs |
| `outcome` | see below | What happened |
| `reasons` | list of strings | Why the policy did not find it plain, in words for the user |
| `exit_code` | integer or `null` | `null` when it did not run, or was stopped |
| `timed_out` | boolean | Stopped at `AIRLOCK_TIMEOUT` |
| `detail` | string or `null` | Why it failed, for `failed` |
| `output_check` | `clean`, `injection`, `not_checked`, or `null` | The model's reading of the output; `null` when nothing ran |
| `output_check_reason` | `not_configured`, `timeout`, `rate_limited`, `error`, or `null` | Why the output was not checked |
| `masked_secrets` | integer or `null` | Secrets masked in the output; `null` when it did not run |
| `blocked_connections` | list of strings | The first hosts the sandbox kept it from, as `host:port`, at most 20 |
| `blocked_count` | integer, 0 or more | How many connections it kept it from in all |

`outcome` is one of: `refused_by_policy`, `refused_by_user`, `ran_once`, `ran_and_granted_for_session`,
`ran_and_granted_for_project`, `ran_under_grant`, `failed`, `read_from_github`, `opened_on_github`.

## Models: `models.yaml`

Written by `airlock configure`, readable by its owner only, since it holds the API key. Keys: `base_url`, `api_key`,
`output_check_model`, `risk_model`, each a string. The variables `AIRLOCK_AI_BASE_URL`, `AIRLOCK_AI_API_KEY`,
`AIRLOCK_OUTPUT_CHECK_MODEL` and `AIRLOCK_RISK_MODEL` win over it.

## A file Airlock cannot read

| File | Not valid YAML, a wrong type, or an unknown key | Unknown key |
|---|---|---|
| a policy file | Airlock runs no command until it is fixed; `airlock doctor` names the file and the key | refused the same way |
| `network.yaml` | the command stops, with the reason | refused the same way |
| `grants.yaml` | Airlock stops and names the file; it never guesses | ignored when read, and dropped the next time Airlock writes the file |
| `audit.jsonl` | a line it cannot read is skipped by `airlock log` | ignored |

A policy fails closed on purpose: a refusal Airlock cannot read must not become a command it runs.

## Open questions before 1.0

1. **A version field.** No file says which format it follows. A `version: 1` key in each YAML file, and in each audit
   line, would let a later Airlock read an older file, and an older one say "written by a newer Airlock" instead of
   "invalid".
2. **Unknown keys in a policy.** Today an unknown key stops every command. That keeps a misspelled `refuse` from being
   silently ignored, but a key added in 1.1 would stop 1.0 entirely. With a version field, an older Airlock could
   refuse a newer file with a clear message instead.
3. **Unknown keys in `grants.yaml`** are dropped on the next write. Keep, or keep them as they were?
4. **What 1.0 promises for the audit log**: keys only added, never renamed or removed, and values of `verdict` and
   `outcome` only added?
5. **`models.yaml`**: part of the promise, or internal to `airlock configure`?
