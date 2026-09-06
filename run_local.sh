#!/usr/bin/env bash
# Lance le bot Python local (racine du projet).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

if [[ ! -d .venv ]]; then
  echo "Création du venv…"
  python3 -m venv .venv --without-pip 2>/dev/null || python3 -m venv .venv
  # Certaines distros livrent un venv sans pip
  if [[ ! -f .venv/bin/pip ]]; then
    curl -sS https://bootstrap.pypa.io/get-pip.py -o /tmp/get-pip.py
    .venv/bin/python /tmp/get-pip.py
  fi
fi

# shellcheck disable=SC1091
source .venv/bin/activate

if ! python -c "import spotipy, requests, dotenv" 2>/dev/null; then
  pip install -r local_bot/requirements.txt
fi

exec python local_bot/bot.py "$@"
