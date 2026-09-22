#!/usr/bin/env bash
#
# Sets the four deploy secrets on the GitHub repository, so
# .github/workflows/deploy-staging.yml can reach the server.
#
#   ./deploy/configure-github.sh <host> <user> [key-path]
#   ./deploy/configure-github.sh 13.234.x.x ubuntu
#
# ⚠ REPOSITORY secrets, not ENVIRONMENT secrets, and that is deliberate.
# deploy-staging.yml resolves `${{ secrets.SSH_HOST }}` in the CALLER job, which
# has no `environment:` of its own — it passes them down to the reusable
# workflow as inputs. Environment secrets would not resolve there. It also keeps
# this working on a free personal account, where environment secrets on a
# PRIVATE repo are a paid feature.
#
# ⚠ Nothing here is printed. Secrets go to GitHub over stdin; the private key is
# never echoed, never passed as an argument (argv is readable by any process on
# the box), and never leaves this machine except to GitHub.
set -euo pipefail

HOST="${1:?usage: configure-github.sh <host> <user> [key-path]}"
USER_NAME="${2:?usage: configure-github.sh <host> <user> [key-path]}"
KEY="${3:-storage/app/deploy/zippi_school_deploy}"

cd "$(dirname "$0")/.."

if [[ ! -f "$KEY" ]]; then
  echo "No private key at $KEY" >&2
  echo "Generate one:  ssh-keygen -t ed25519 -f $KEY -N '' -C github-actions@zippi-school" >&2
  exit 1
fi

# A key the server has never been told about produces a deploy that fails at the
# last step, after building and uploading. Cheaper to find out now.
echo "→ checking the server accepts this key"
if ! ssh -i "$KEY" -o BatchMode=yes -o ConnectTimeout=8 \
        -o StrictHostKeyChecking=accept-new \
        "${USER_NAME}@${HOST}" true 2>/dev/null; then
  echo >&2
  echo "Could not log in as ${USER_NAME}@${HOST} with $KEY." >&2
  echo "Add the public half to that server's ~/.ssh/authorized_keys:" >&2
  echo >&2
  cat "${KEY}.pub" >&2
  echo >&2
  exit 1
fi
echo "  ok"

echo "→ setting secrets"
printf '%s' "$HOST"      | gh secret set SSH_HOST
printf '%s' "$USER_NAME" | gh secret set SSH_USER
gh secret set SSH_PRIVATE_KEY < "$KEY"

# ⚠ Pins the host key. The alternative — StrictHostKeyChecking=no — accepts
# whatever answers on that address, which is the whole attack this prevents.
ssh-keyscan -H "$HOST" 2>/dev/null | gh secret set SSH_KNOWN_HOSTS

echo
echo "Set:"
gh secret list

cat <<'NEXT'

Next, on the SERVER (as a sudoer), if it has not been done:

    ./deploy/bootstrap-server.sh staging          # dry run, prints the plan
    ./deploy/bootstrap-server.sh staging --apply

Then deploy:

    gh workflow run deploy-staging.yml --ref develop
    gh run watch

NEXT
