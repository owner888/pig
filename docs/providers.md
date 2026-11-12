# Provider Authentication

Most hosted providers support one or both of these authentication methods:

- Sign in through a browser or device flow backed by OAuth.
- Provide an API key.

Use `/login [provider]` to see the methods supported by a provider. Amazon Bedrock and Google Vertex AI can also use ambient cloud credentials.

## Authenticate interactively

Run `/login` and select a provider. pig guides you through its OAuth or API-key flow and saves the resulting credential in [`auth.json`](/docs/latest/configuration#agent-directory): pi's `~/.pi/agent/auth.json` when pi already has one, so the two tools share rotating tokens, and `~/.pig/agent/auth.json` otherwise.

On a remote or headless machine, an OAuth callback may not reach the local process. When prompted, paste the final redirect URL or authorization code back into pig.

Run `/logout` and select a provider to remove its stored credential. This does not unset environment variables, remove authentication from `models.json`, or revoke the credential at the provider.

`auth.json` can contain API keys and OAuth tokens. Keep it private and do not commit it.

### Sign in with an account or subscription

`/login` first asks which sign-in flow you want: **Sign in with an account** or **Sign in with an API key** (pi's third, Radius, is not in pig: pig uses no pi service). The account sign-in flows align with upstream pi:

| Provider | How | Notes |
|---|---|---|
| **Anthropic (Claude Pro/Max)** | `/login` asks which way in first. **Browser** (default): pig listens on `http://localhost:53692/callback`, opens `claude.ai`, and the code comes back by itself; a paste box stays open beside it — if the browser is on another machine, paste the final redirect URL there. **Copy code** (headless): the browser lands on Anthropic's own page showing `code#state`; paste that. | The token goes out as Claude Code's (`claude-cli` user agent, both betas, tool names spelled `Read`/`Bash`/`Edit`/`Write`) because that is the identity Anthropic issued it to. Port 53692 is registered with Anthropic and cannot be changed. |
| **GitHub Copilot** | Device flow: pig shows a code, you type it at `github.com/login/device`, pig polls until it is accepted. Blank at the Enterprise prompt means `github.com`. | Claude's and Grok's models are switched on for the account after signing in. |
| **OpenAI (ChatGPT Plus/Pro)** | **Browser** (default): pig listens on `http://localhost:1455/auth/callback`, opens `auth.openai.com`, and the code comes back by itself; a paste box stays open beside it for redirect URL or code. **Device code** (headless): pig shows a code to type at `auth.openai.com/codex/device` and polls until accepted. | For `openai-codex` models — ChatGPT's Codex backend, with account id read from the token. |
| **Kimi Code (subscription)** | Device flow: pig shows a code, you approve it on kimi.com, pig polls until it is accepted. `KIMI_CODE_OAUTH_HOST` (or `KIMI_OAUTH_HOST`) overrides the authorization host. | Signs in the `kimi-coding` provider; tokens are renewed automatically. |
| **Antigravity** | Browser callback on `localhost:51121/oauth-callback`. | Comes with the `pig-antigravity` package (`pig install git:github.com/owner888/pig-antigravity`); without it the provider is not in the list. Needs `ANTIGRAVITY_CLIENT_ID` / `ANTIGRAVITY_CLIENT_SECRET` in the environment or `antigravity.clientId` / `antigravity.clientSecret` in `settings.json`; neither pig nor the package ships them. |

Tokens are renewed automatically when they expire. On a machine with no terminal UI use `pig-ai login <provider>`; on a pipe the browser callback cannot be raced against a paste, so pick "copy code" for Anthropic.

Selecting **Sign in with an API key** displays a searchable list of every built-in provider (Anthropic, OpenAI, Gemini, DeepSeek, OpenRouter, Groq, Moonshot, Z.AI, …) and extension providers, showing configuration status and origin (`✓ env: KEY`, `✓ configured`). Stored keys are kept in `auth.json`. Use `/logout` to remove stored accounts or API keys.

### Connect a custom OpenAI-compatible provider

Any custom or third-party OpenAI-compatible endpoint can be configured without hand-writing JSON:
1. Run `/login <provider_name>` (e.g. `/login my-gateway`) or select `+ Add custom provider...` from the `/login` menu;
2. Enter the endpoint's **Base URL** and **API key** when prompted;
3. pig automatically queries `$baseUrl/models` to discover available models, writes the catalog cleanly to `~/.pig/agent/models.json`, saves the key in `auth.json`, and switches to the new model immediately.

## Use an API key from the environment

Environment variables are useful in CI and anywhere pig should not store the key. Set the variable before starting pig:

```bash
export ANTHROPIC_API_KEY=sk-ant-...
pig
```

This table covers providers with a single primary API-key variable. Providers that need additional configuration or support ambient credentials are covered under [Cloud providers](#cloud-providers).

| Provider | Environment variable |
|---|---|
| Anthropic | `ANTHROPIC_API_KEY` |
| Ant Ling | `ANT_LING_API_KEY` |
| OpenAI | `OPENAI_API_KEY` |
| DeepSeek | `DEEPSEEK_API_KEY` |
| NVIDIA NIM | `NVIDIA_API_KEY` |
| Google Gemini | `GEMINI_API_KEY` |
| GitHub Copilot | `COPILOT_GITHUB_TOKEN`, then `GH_TOKEN`, then `GITHUB_TOKEN` |
| Mistral | `MISTRAL_API_KEY` |
| Groq | `GROQ_API_KEY` |
| Cerebras | `CEREBRAS_API_KEY` |
| xAI | `XAI_API_KEY` |
| OpenRouter | `OPENROUTER_API_KEY` |
| Vercel AI Gateway | `AI_GATEWAY_API_KEY` |
| ZAI Coding Plan (Global) | `ZAI_API_KEY` |
| ZAI Coding Plan (China) | `ZAI_CODING_CN_API_KEY` |
| OpenCode Zen and Go | `OPENCODE_API_KEY` |
| TypeSafe ([classifier models](/docs/latest/models#use-classifier-models)) | `TYPESAFE_API_KEY` |
| Hugging Face | `HF_TOKEN` |
| Fireworks | `FIREWORKS_API_KEY` |
| Together AI | `TOGETHER_API_KEY` |
| Baseten | `BASETEN_API_KEY` |
| Kimi For Coding | `KIMI_API_KEY` |
| Meta | `META_API_KEY` |
| MiniMax | `MINIMAX_API_KEY` |
| MiniMax (China) | `MINIMAX_CN_API_KEY` |
| Moonshot AI (Global and China) | `MOONSHOT_API_KEY` |
| Qwen Token Plan and Individual | `QWEN_TOKEN_PLAN_API_KEY` |
| Qwen Token Plan (China) and Individual (China) | `QWEN_TOKEN_PLAN_CN_API_KEY` |
| Xiaomi MiMo | `XIAOMI_API_KEY` |
| Xiaomi MiMo Token Plan (China) | `XIAOMI_TOKEN_PLAN_CN_API_KEY` |
| Xiaomi MiMo Token Plan (Amsterdam) | `XIAOMI_TOKEN_PLAN_AMS_API_KEY` |
| Xiaomi MiMo Token Plan (Singapore) | `XIAOMI_TOKEN_PLAN_SGP_API_KEY` |

Anthropic also recognizes `ANTHROPIC_AUTH_TOKEN`, sent as `Authorization: Bearer <token>` and checked first, and `ANTHROPIC_OAUTH_TOKEN`, which is checked before `ANTHROPIC_API_KEY`.

## Load an API key from a command

To use a secret manager without writing the resolved key to disk, set a provider's `key` in `auth.json` to a command prefixed with `!`:

```json
{
  "anthropic": {
    "type": "api_key",
    "key": "!security find-generic-password -ws 'anthropic'"
  }
}
```

`$NAME` and `${NAME}` are read from the entry's `env` and then the process environment; a bare name such as `MY_KEY` is a literal key. pig runs the command when the key is first needed and caches its standard output for the process lifetime. Empty output, a timeout, or a nonzero exit leaves the key unresolved until pig restarts.

## Cloud Providers

The providers below need additional settings or can use credentials supplied by their cloud platform.

A stored API-key credential can include an `env` object. Its values take priority over the process environment for that provider:

```json
{
  "cloudflare-workers-ai": {
    "type": "api_key",
    "key": "...",
    "env": {
      "CLOUDFLARE_ACCOUNT_ID": "account-id"
    }
  }
}
```

### Azure OpenAI

Set an API key plus either a base URL or resource name:

```bash
export AZURE_OPENAI_API_KEY=...
export AZURE_OPENAI_BASE_URL=https://your-resource.ai.azure.com
# Or:
export AZURE_OPENAI_RESOURCE_NAME=your-resource
```

Resource root URLs under `ai.azure.com`, `cognitiveservices.azure.com`, and `openai.azure.com` are normalized to the OpenAI API path.

### Amazon Bedrock

Bedrock can use a bearer token or an ambient AWS credential source:

```bash
# Named profile
export AWS_PROFILE=your-profile

# IAM keys
export AWS_ACCESS_KEY_ID=AKIA...
export AWS_SECRET_ACCESS_KEY=...
# Required for temporary credentials
export AWS_SESSION_TOKEN=...

# Bedrock bearer token
export AWS_BEARER_TOKEN_BEDROCK=...

# Region, when not supplied by the profile or AWS SDK configuration
export AWS_REGION=us-west-2
# AWS_DEFAULT_REGION is also supported
```

pig also supports ECS task credentials and IRSA through the standard `AWS_CONTAINER_CREDENTIALS_*` and `AWS_WEB_IDENTITY_TOKEN_FILE` variables.

### Cloudflare AI Gateway

The gateway requires a token, account ID, and gateway ID:

```bash
export CLOUDFLARE_API_KEY=...
export CLOUDFLARE_ACCOUNT_ID=...
export CLOUDFLARE_GATEWAY_ID=...
```

The account and gateway IDs can come from the process environment or the credential's `env` object in `auth.json`.

`CLOUDFLARE_API_KEY` authenticates pig to the gateway. Upstream access can use Cloudflare unified billing, credentials stored in the gateway, or an `Authorization` header configured for the provider in `models.json`.

### Cloudflare Workers AI

Workers AI requires a token and account ID:

```bash
export CLOUDFLARE_API_KEY=...
export CLOUDFLARE_ACCOUNT_ID=...
```

The account ID can also be stored in the credential's `env` object.

### Google Vertex AI

Use a Google Cloud API key:

```bash
export GOOGLE_CLOUD_API_KEY=...
```

To use Application Default Credentials, configure a project and location:

```bash
export GOOGLE_CLOUD_PROJECT=your-project
# GCLOUD_PROJECT is also supported
export GOOGLE_CLOUD_LOCATION=us-central1
```

Then authenticate:

```bash
gcloud auth application-default login
```

To use a service-account key file instead, set `GOOGLE_APPLICATION_CREDENTIALS` along with the project and location.
