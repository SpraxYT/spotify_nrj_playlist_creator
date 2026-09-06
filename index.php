<?php

declare(strict_types=1);

/**
 * Accueil minimal : liens vers auth, run, historique.
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NRJ → Spotify</title>
<style>
:root { --bg:#f6f5f2; --fg:#1a1a1a; --muted:#5c5c5c; --line:#d8d5ce; }
*{box-sizing:border-box}
body{margin:0;font:16px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--fg)}
main{max-width:36rem;margin:0 auto;padding:2.5rem 1.25rem}
h1{font-size:1.5rem;margin:0 0 .4rem;font-weight:650}
p{color:var(--muted);margin:0 0 1.5rem}
ul{list-style:none;margin:0;padding:0}
li{border-top:1px solid var(--line);padding:.85rem 0}
li:last-child{border-bottom:1px solid var(--line)}
a{color:var(--fg);font-weight:600;text-decoration:none}
a:hover{text-decoration:underline}
small{display:block;color:var(--muted);font-weight:400;margin-top:.2rem}
</style>
</head>
<body>
<main>
<h1>NRJ → Spotify</h1>
<p>Ajoute les titres diffusés sur NRJ à votre playlist Spotify.</p>
<ul>
<li>
<a href="auth.php">Autorisation Spotify</a>
<small>À faire une seule fois (OAuth → data/token.json)</small>
</li>
<li>
<a href="history.php">Historique NRJ</a>
<small>Récupérer la liste récente et l’ajouter manuellement</small>
</li>
<li>
<a href="run.php">run.php</a>
<small>Cron / titre en cours — nécessite ?key=CRON_SECRET</small>
</li>
</ul>
</main>
</body>
</html>
