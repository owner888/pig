# Run pig in an isolated environment

Use an isolated environment to limit the files, credentials, processes, and network services that generated commands can access or affect.

Isolate the complete pig process. pig has no tool-only isolation extension (upstream pi's Gondolin extension has no PHP port), but an extension can route its own shell commands elsewhere; see [Route shell commands](#route-shell-commands-into-a-container). For several people on one `pig web`, each account's tools can run in a Docker container of its own instead; see [Several Accounts, Docker & SSH](/docs/latest/web).

## Choose an isolation method

| Method | Where pig runs | What is isolated | Credential handling | Best for |
|---|---|---|---|---|
| Plain Docker | Container | pig, built-in tools, `!` commands, and extensions | Credentials passed into the container | A straightforward local container boundary |
| Docker Sandboxes | Managed sandbox | pig, built-in tools, `!` commands, and extensions | Provider credentials remain on the host and are substituted by the proxy | Managed local isolation without exposing the real provider key |
| OpenShell | Local or remote sandbox | pig, built-in tools, `!` commands, and extensions | Policy-controlled credentials and inference routing | Filesystem, process, network, and credential policies |

When the complete pig process runs inside an isolated environment, its extensions run there too.

## Decide what pig can access

An isolated process can still affect resources you expose to it:

- A read-write host mount lets pig modify those host files.
- Mounting `~/.pig/agent` exposes your pig credentials, settings, extensions, and sessions.
- Environment variables passed into a container are available to processes inside it.
- Network access may allow code or tool output to leave the environment.
- Tool-only isolation does not constrain the host pig process or extension tools that do not use the isolated backend.

Expose only the working folder, credentials, and network destinations needed for the task. Use read-only mounts or copy files into and out of the environment when you do not want writes to affect the host.

## Run pig in plain Docker

Plain Docker provides the simplest whole-process container boundary.

### Build the image

Create `Dockerfile.pig`:

```dockerfile
FROM php:8.3-cli-bookworm

RUN apt-get update \
  && apt-get install -y --no-install-recommends bash ca-certificates git ripgrep curl unzip libzip-dev \
  && docker-php-ext-install pcntl \
  && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
  && composer global require pigagent/pig

ENV PATH="/root/.composer/vendor/bin:${PATH}"

WORKDIR /workspace
ENTRYPOINT ["pig"]
```

Build it from the directory containing the file:

```bash
docker build -t pig-isolated -f Dockerfile.pig .
```

### Start pig

From the working folder you want pig to access, run:

```bash
docker run --rm -it \
  -e ANTHROPIC_API_KEY \
  -v "$PWD:/workspace" \
  -v pig-agent-home:/root/.pig/agent \
  pig-isolated
```

Replace `ANTHROPIC_API_KEY` with the credential required by your provider. The named `pig-agent-home` volume keeps container-local settings, credentials, and sessions between runs.

Do not mount the host's `~/.pig/agent` unless the container should have access to your host pig configuration and credentials.

### Verify the workspace

Inside pig, run:

```text
!pwd
```

The command should report `/workspace`. Changes under `/workspace` write through to the mounted host folder. Remove the bind mount or use a read-only mount when that is not acceptable.

## Run pig with Docker Sandboxes

[Docker Sandboxes](https://docs.docker.com/ai/sandboxes/) runs the complete pig process inside a managed sandbox. Its proxy can keep the real provider credential on the host and substitute it when requests leave the sandbox.

Configure credentials before creating the sandbox. Do not run `/login` inside the sandbox because that writes a real credential into it.

There is no published Docker Sandboxes kit for pig: the `sbx/pi-kit` kit runs upstream pi, not pig. To use Docker Sandboxes, build a kit or template from an image like the [plain Docker](#run-pig-in-plain-docker) one above, following Docker's kit documentation, and configure provider secrets with `sbx secret` so the proxy substitutes them.

pig reads `ANTHROPIC_OAUTH_TOKEN` (a Claude Pro or Max token from `claude setup-token`) and `ANTHROPIC_API_KEY` like any other provider variable, so a placeholder the proxy replaces works the same way.

## Run pig with OpenShell

[NVIDIA OpenShell](https://docs.nvidia.com/openshell/about/overview) provides local or remote sandboxes with filesystem, process, network, credential, and inference policies.

### Select a gateway

Every sandbox requires an active gateway:

```bash
openshell gateway add <gateway-url> --name <name>
openshell gateway select <name>
```

### Create the sandbox

Create the sandbox from an image that contains pig, such as the [plain Docker](#run-pig-in-plain-docker) image above, and start `pig` inside it. See OpenShell's documentation for the `openshell sandbox create` options.

pig, its built-in tools, `!` commands, and extension tools run inside the OpenShell boundary.

### Transfer files to a remote sandbox

A remote gateway does not bind-mount your host working folder. Clone the repository inside the sandbox or transfer files explicitly:

```bash
openshell sandbox upload pig-isolated ./working-folder /workspace
openshell sandbox download pig-isolated /workspace/working-folder ./working-folder-out
```

OpenShell inference routing can keep raw model credentials outside the sandbox. When configured, point pig at the corresponding OpenAI-compatible or Anthropic-compatible endpoint exposed by the gateway.

## Route shell commands into a container

pig ships no extension that moves built-in tools into a container or VM. An extension can register its own shell tool whose `spawnHook` rewrites each command, for example to run it through `docker exec`:

```php
use Pig\CodingAgent\Tools\BashTool;
use Pig\CodingAgent\Tools\SpawnContext;

$sandboxed = new BashTool(
    $cwd,
    spawnHook: fn (SpawnContext $spawn): SpawnContext => new SpawnContext(
        'docker exec -i -w /workspace pig-tools bash -c ' . escapeshellarg($spawn->command),
        $spawn->cwd,
        $spawn->env,
    ),
);
```

This isolates only that tool's commands. pig itself, its other tools, `!` commands, and other extensions still run on the host, so prefer whole-process isolation for untrusted work.
