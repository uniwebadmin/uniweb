# UniWeb — Cloud / Mobile Agents

Primary control surface: **https://cursor.com/agents** (phone or any browser).

Repo: https://github.com/6396601005/uniweb

## Start a cloud agent from mobile

1. Open https://cursor.com/agents and sign in.
2. Select repo `6396601005/uniweb` (branch `main` unless continuing an open PR branch).
3. Paste a task, for example:

```text
UniWeb live launch continue. Priority: broken pages/links; cron auto-audit green; KYC verify on admin_kyc.php; gateway keys UI; smoke homepage/signup/demo/checkout/admin_website.php. English UI only. Do not delete production pages. Do not invent credentials. Commit on a cloud branch and open a PR.
```

4. Close the laptop anytime — the agent runs in Cursor cloud.

## Secrets

- `config.php` and SFTP credentials are **not** in git. Do not recreate secrets in PRs.
- Partner gateway keys are pasted by the owner in the live admin UI when received.

## Deploy note

Live Hostinger deploy may need the laptop/FTP once for a release, or an allowlisted `scripts/release_deploy.ps1` when credentials are available in the environment. Cloud agents should still ship code via PR even if live FTP is unavailable.
