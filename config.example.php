<?php
/**
 * Copiez ce fichier vers config.php et renseignez vos valeurs.
 * Ne committez jamais config.php.
 */
return [
    'SPOTIFY_CLIENT_ID'     => 'votre_client_id',
    'SPOTIFY_CLIENT_SECRET' => 'votre_client_secret',

    // Doit correspondre exactement à une Redirect URI de l'app Spotify
    // Ex. aaPanel : https://votre-domaine.tld/auth.php
    // Ex. local   : http://127.0.0.1:8888/callback
    'SPOTIFY_REDIRECT_URI'  => 'https://votre-domaine.tld/auth.php',

    // ID de la playlist (URL Spotify : .../playlist/XXXXXXXX)
    'SPOTIFY_PLAYLIST_ID'   => 'votre_playlist_id',

    // Webradio NRJ : 158 = NRJ FM, 1 = NRJ HITS
    'NRJ_WEBRADIO_ID'       => '158',

    // Secret pour protéger l'accès web à run.php (?key=...)
    'CRON_SECRET'           => 'changez_moi_par_un_secret_long',
];
