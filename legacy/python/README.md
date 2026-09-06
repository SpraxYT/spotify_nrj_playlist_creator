# Ancienne version Python

Conservée pour référence. Le projet principal est désormais en PHP à la racine du dépôt.

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
# depuis la racine du dépôt, avec chemins adaptés
python legacy/python/bot.py --once
```
