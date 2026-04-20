<?php
/**
 * ffbad.php — Lookup joueurs FFBaD pour Badine
 * ?action=test              connectivité API + myffbad login
 * ?action=debug&q=NOM      diagnostic complet
 * ?action=search&q=NOM     autocomplétion joueurs
 * ?action=info&licence=XXX détail + classement
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
error_reporting(0);

// ── Credentials ──────────────────────────────────────────────────
$API_LOGIN    = '07346745';
$API_PASSWORD = 'zuxnic-pIfmyp-6nanci';
$API_URL      = 'https://api.ffbad.org/FFBAD-WS.php';

$MYF_LOGIN    = '07346745';
$MYF_PASS     = 'zuxnic-pIfmyp-6nanci';
$MYF_BASE     = 'https://www.myffbad.fr';
$MYF_SESSION  = __DIR__ . '/.myffbad_sess.txt'; // cookie jar mis en cache

// ── cURL brut (API JSON) ──────────────────────────────────────────
function do_curl($url, $post_body = null, $extra_headers = array()) {
    if (!function_exists('curl_init')) return array('error' => 'cURL absent');
    $ch = curl_init($url);
    $headers = array_merge(array('Accept: application/json, */*'), $extra_headers);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Badine/1.0',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ));
    if ($post_body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $body === '') return array('error' => $err ?: 'empty', 'code' => $code);
    return array('body' => $body, 'code' => $code);
}

// ── cURL navigateur (scraping avec session) ───────────────────────
function curl_browser($url, $post_data = null, $referer = null, $cookie_file = null) {
    if (!function_exists('curl_init')) return array('error' => 'cURL absent', 'code' => 0);
    $ch = curl_init($url);
    $headers = array(
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8',
        'Accept-Encoding: gzip, deflate, br',
        'Sec-Ch-Ua: "Chromium";v="124","Google Chrome";v="124","Not-A.Brand";v="99"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "Windows"',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: ' . ($referer ? 'same-origin' : 'none'),
        'Sec-Fetch-User: ?1',
        'Upgrade-Insecure-Requests: 1',
        'Dnt: 1',
    );
    if ($referer) $headers[] = 'Referer: ' . $referer;
    if ($post_data !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';

    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_TIMEOUT        => 18,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLINFO_HEADER_OUT    => true,
    );
    if ($cookie_file) {
        $opts[CURLOPT_COOKIEFILE] = $cookie_file;
        $opts[CURLOPT_COOKIEJAR]  = $cookie_file;
    }
    if ($post_data !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $post_data;
    }
    curl_setopt_array($ch, $opts);
    $body     = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final    = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err      = curl_error($ch);
    curl_close($ch);
    return array('body' => $body ?: '', 'code' => $code, 'url' => $final, 'error' => $err);
}

// ── API FFBaD officielle ──────────────────────────────────────────
function api_call($function, $params, $login, $password, $url) {
    $payload = json_encode(array(
        'Auth'   => json_encode(array('Login' => $login, 'Password' => $password)),
        'Params' => json_encode(array_merge(array('Function' => $function), $params)),
    ));
    $r = do_curl($url, $payload, array('Content-Type: application/json'));
    if (isset($r['error'])) return array('_curl_error' => $r['error'], '_code' => $r['code'] ?? 0);
    if ($r['code'] >= 400)  return array('_http_error' => $r['code'], '_body' => substr($r['body'], 0, 300));
    $d = json_decode($r['body'], true);
    return is_array($d) ? $d : array('_parse_error' => substr($r['body'], 0, 300));
}

function to_list($val) {
    if (empty($val)) return array();
    if (isset($val[0]) && is_array($val[0])) return $val;
    if (isset($val['Licence'])) return array($val);
    if (is_array($val)) return array_values($val);
    return array();
}

// ── MyFFBaD : connexion et cache de session ───────────────────────
function myffbad_get_session() {
    global $MYF_LOGIN, $MYF_PASS, $MYF_BASE, $MYF_SESSION;

    // Réutiliser la session si elle a moins de 4h
    if (file_exists($MYF_SESSION) && (time() - filemtime($MYF_SESSION)) < 14400) {
        $meta = json_decode(file_get_contents($MYF_SESSION), true);
        if (!empty($meta['jar']) && file_exists($meta['jar'])) {
            return $meta['jar'];
        }
    }

    $jar = tempnam(sys_get_temp_dir(), 'myffbad_');

    // 1. Charger la page login pour récupérer le token CSRF
    $r1 = curl_browser($MYF_BASE . '/connexion', null, null, $jar);
    if ($r1['code'] !== 200) return array('_login_error' => 'page_load_' . $r1['code']);

    // Extraire le token CSRF (patterns Symfony courants)
    $csrf = '';
    foreach (array(
        '/name="_csrf_token"\s+value="([^"]+)"/',
        '/name="token"\s+value="([^"]+)"/',
        '/name="_token"\s+value="([^"]+)"/',
        '/"csrf_token"\s*:\s*"([^"]+)"/',
        '/id="login__token"\s+value="([^"]+)"/',
    ) as $pat) {
        if (preg_match($pat, $r1['body'], $m)) { $csrf = $m[1]; break; }
    }

    // Construire les données POST (essayer les noms de champs courants)
    $post = http_build_query(array(
        '_username'    => $MYF_LOGIN,
        '_password'    => $MYF_PASS,
        '_csrf_token'  => $csrf,
        'identifiant'  => $MYF_LOGIN,
        'mot_de_passe' => $MYF_PASS,
    ));

    // 2. Poster les credentials
    $r2 = curl_browser($MYF_BASE . '/connexion', $post, $MYF_BASE . '/connexion', $jar);

    // Succès = redirigé hors de /connexion
    $ok = ($r2['code'] === 200 || $r2['code'] === 302)
       && !empty($r2['url'])
       && strpos($r2['url'], '/connexion') === false;

    if (!$ok) {
        @unlink($jar);
        return array('_login_error' => 'auth_failed', '_code' => $r2['code'], '_url' => $r2['url']);
    }

    // Sauvegarder le chemin du jar (cookie valide)
    file_put_contents($MYF_SESSION, json_encode(array('jar' => $jar, 'ts' => time())));
    return $jar;
}

// ── MyFFBaD : recherche joueur ────────────────────────────────────
function myffbad_search($nom, $prenom = '') {
    global $MYF_BASE;

    $jar = myffbad_get_session();
    if (is_array($jar)) return $jar; // erreur de login

    // Essayer search JSON d'abord, puis HTML
    $search_url = $MYF_BASE . '/recherche/joueur?' . http_build_query(array(
        'nom'    => $nom,
        'prenom' => $prenom,
    ));

    // Tentative JSON (si endpoint AJAX disponible)
    $rj = curl_browser(
        $MYF_BASE . '/api/joueur/search?' . http_build_query(array('nom' => $nom, 'prenom' => $prenom)),
        null, $MYF_BASE . '/recherche/joueur', $jar
    );
    if ($rj['code'] === 200 && !empty($rj['body'])) {
        $json = json_decode($rj['body'], true);
        if (is_array($json)) return parse_myffbad_json($json);
    }

    // Fallback HTML
    $r = curl_browser($search_url, null, $MYF_BASE . '/recherche/joueur', $jar);
    if ($r['code'] !== 200 || empty($r['body'])) {
        return array('_error' => 'search_' . $r['code']);
    }
    return parse_myffbad_html($r['body']);
}

// ── MyFFBaD : info joueur par licence ─────────────────────────────
function myffbad_info($licence) {
    global $MYF_BASE;

    $jar = myffbad_get_session();
    if (is_array($jar)) return array();

    // Essayer la fiche joueur directe
    foreach (array('/joueur/', '/licencie/', '/profil/') as $path) {
        $r = curl_browser($MYF_BASE . $path . urlencode($licence), null, $MYF_BASE . '/', $jar);
        if ($r['code'] === 200 && strlen($r['body']) > 500) {
            return parse_myffbad_player_page($r['body'], $licence);
        }
    }

    // Recherche par licence
    $r = curl_browser(
        $MYF_BASE . '/recherche/joueur?licence=' . urlencode($licence),
        null, $MYF_BASE . '/recherche/joueur', $jar
    );
    if ($r['code'] === 200 && !empty($r['body'])) {
        $players = parse_myffbad_html($r['body']);
        if (is_array($players) && count($players) > 0) return $players[0];
    }

    return array();
}

// ── Parsers HTML myffbad.fr ───────────────────────────────────────
function parse_myffbad_json($json) {
    $players = array();
    $items = $json['data'] ?? $json['joueurs'] ?? $json['results'] ?? $json;
    if (!is_array($items)) return array();
    foreach (array_slice($items, 0, 15) as $p) {
        $lic = (string)($p['licence'] ?? $p['Licence'] ?? $p['id'] ?? '');
        if (!$lic) continue;
        $players[] = array(
            'licence' => $lic,
            'nom'     => (string)($p['nom']    ?? $p['Nom']    ?? ''),
            'prenom'  => (string)($p['prenom'] ?? $p['Prenom'] ?? ''),
            'club'    => (string)($p['club']   ?? $p['Club']   ?? $p['nomClub'] ?? ''),
            'sexe'    => (string)($p['sexe']   ?? $p['Sexe']   ?? ''),
        );
    }
    return $players;
}

function parse_myffbad_html($html) {
    if (!$html) return array();
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);

    $players = array(); $seen = array();

    // Essayer les tableaux d'abord
    foreach ($xp->query('//table//tr') as $row) {
        $cells = $xp->query('td', $row);
        $vals = array();
        foreach ($cells as $c) $vals[] = trim(preg_replace('/\s+/', ' ', $c->textContent));
        if (count($vals) < 3) continue;
        $licence = '';
        foreach ($vals as $v) { if (preg_match('/^\d{7,8}$/', $v)) { $licence = $v; break; } }
        if (!$licence || isset($seen[$licence])) continue;
        $seen[$licence] = true;
        $idx = array_search($licence, $vals);
        $players[] = array(
            'licence' => $licence,
            'nom'     => $idx === 0 ? ($vals[1] ?? '') : ($vals[0] ?? ''),
            'prenom'  => $idx === 0 ? ($vals[2] ?? '') : ($vals[1] ?? ''),
            'club'    => $vals[3] ?? '',
            'sexe'    => '',
        );
    }

    // Si aucun tableau, chercher des éléments avec data-licence
    if (!$players) {
        foreach ($xp->query('//*[@data-licence]') as $el) {
            $lic = trim($el->getAttribute('data-licence'));
            if (!$lic || isset($seen[$lic])) continue;
            $seen[$lic] = true;
            $text = trim(preg_replace('/\s+/', ' ', $el->textContent));
            $parts = preg_split('/\s+/', $text, 3);
            $players[] = array(
                'licence' => $lic,
                'nom'     => $parts[0] ?? '',
                'prenom'  => $parts[1] ?? '',
                'club'    => $parts[2] ?? '',
                'sexe'    => '',
            );
        }
    }

    return $players;
}

function parse_myffbad_player_page($html, $licence) {
    // Extraire nom/prenom/club/classements depuis une page de profil
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);

    $nom = ''; $prenom = ''; $club = ''; $sexe = '';
    $rankings = array();

    // Nom / prénom : chercher dans h1, h2, .player-name, etc.
    foreach ($xp->query('//*[contains(@class,"player") or contains(@class,"joueur") or contains(@class,"profil")]//h1 | //h1 | //h2') as $el) {
        $t = trim(preg_replace('/\s+/', ' ', $el->textContent));
        if (strlen($t) > 3 && strlen($t) < 60) {
            $parts = explode(' ', $t, 2);
            $prenom = $parts[0];
            $nom = $parts[1] ?? '';
            break;
        }
    }

    // Club : chercher "club" ou patterns similaires
    foreach ($xp->query('//*[contains(@class,"club") or contains(text(),"Club")]') as $el) {
        $t = trim($el->textContent);
        if (strlen($t) > 3 && strlen($t) < 80) { $club = $t; break; }
    }

    // Classements : chercher des patterns "pts", "points", "série"
    foreach ($xp->query('//*[contains(@class,"ranking") or contains(@class,"classement") or contains(@class,"cote")]') as $el) {
        $text = trim(preg_replace('/\s+/', ' ', $el->textContent));
        if (preg_match('/(\d+)\s*pts?/i', $text, $m)) {
            $rankings[] = array(
                'discipline' => 'SH',
                'points'     => (int)$m[1],
                'rang'       => 0,
                'niveau'     => '',
                'sexe'       => '',
            );
        }
    }

    return array(
        'licence'  => $licence,
        'nom'      => $nom,
        'prenom'   => $prenom,
        'club'     => $club,
        'sexe'     => $sexe,
        'rankings' => $rankings,
    );
}

// ── Scraping ffbadminton.fr (fallback) ────────────────────────────
function scrape_ffbadminton($nom, $prenom = '') {
    $jar = tempnam(sys_get_temp_dir(), 'ffbad_');
    curl_browser('https://www.ffbadminton.fr/', null, null, $jar);
    curl_browser('https://www.ffbadminton.fr/joueurs/', null, 'https://www.ffbadminton.fr/', $jar);
    $r = curl_browser(
        'https://www.ffbadminton.fr/joueurs/?' . http_build_query(array(
            'nom' => $nom, 'prenom' => $prenom, 'submit' => '1',
        )),
        null, 'https://www.ffbadminton.fr/joueurs/', $jar
    );
    @unlink($jar);
    if ($r['code'] !== 200 || empty($r['body'])) return array('_code' => $r['code']);
    return parse_myffbad_html($r['body']); // même parser tableau
}

// ── Routing ──────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'test';

// ── TEST ─────────────────────────────────────────────────────────
if ($action === 'test') {
    $api = api_call('ws_test', array(), $API_LOGIN, $API_PASSWORD, $API_URL);
    $api_ok = !isset($api['_curl_error']) && !isset($api['_http_error']) && !isset($api['_parse_error']);

    // Test login myffbad
    $myf_jar = myffbad_get_session();
    $myf_ok  = is_string($myf_jar);

    echo json_encode(array(
        'api_ok'    => $api_ok,
        'myffbad_ok'=> $myf_ok,
        'api_resp'  => $api,
        'myffbad'   => $myf_ok ? 'logged_in' : $myf_jar,
    ));
    exit;
}

// ── DEBUG ────────────────────────────────────────────────────────
if ($action === 'debug') {
    $q   = trim($_GET['q'] ?? '');
    $nom = strtoupper(preg_split('/\s+/', $q)[0] ?? $q);

    $api_raw  = api_call('ws_getlicenceinfobystartnom',
        array('Nom' => $nom, 'NotLastSeasonOnly' => '0'),
        $API_LOGIN, $API_PASSWORD, $API_URL);

    $myf = myffbad_search($nom);

    echo json_encode(array(
        'server_ip'    => gethostbyname(gethostname()),
        'api_raw'      => $api_raw,
        'myffbad_res'  => $myf,
        'myffbad_count'=> is_array($myf) && !isset($myf['_login_error']) && !isset($myf['_error']) ? count($myf) : 0,
    ), JSON_PRETTY_PRINT);
    exit;
}

// ── SEARCH ───────────────────────────────────────────────────────
if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode(array('results' => array())); exit; }

    $parts  = preg_split('/\s+/', $q, 2);
    $nom    = strtoupper($parts[0]);
    $prenom = $parts[1] ?? '';

    $players = array();

    // 1. API officielle FFBaD
    $res = api_call('ws_getlicenceinfobystartnom',
        array('Nom' => $nom, 'NotLastSeasonOnly' => '0'),
        $API_LOGIN, $API_PASSWORD, $API_URL);

    if (!isset($res['_curl_error']) && !isset($res['_http_error'])) {
        foreach (array_slice(to_list($res['licenceinfo'] ?? $res['Licence'] ?? array()), 0, 15) as $p) {
            if (!isset($p['Licence'])) continue;
            $players[] = array(
                'licence' => (string)$p['Licence'],
                'nom'     => (string)($p['Nom']     ?? ''),
                'prenom'  => (string)($p['Prenom']  ?? ''),
                'club'    => (string)($p['NomClub'] ?? $p['Club'] ?? ''),
                'sexe'    => (string)($p['Sexe']    ?? ''),
            );
        }
    }

    // 2. MyFFBaD (session licencié)
    if (empty($players)) {
        $res2 = myffbad_search($nom, $prenom);
        if (is_array($res2) && !isset($res2['_login_error']) && !isset($res2['_error'])) {
            $players = $res2;
        }
    }

    // 3. Scraping ffbadminton.fr
    if (empty($players)) {
        $res3 = scrape_ffbadminton($nom, $prenom);
        if (is_array($res3) && !isset($res3['_code'])) {
            $players = $res3;
        }
    }

    echo json_encode(array('results' => array_values(array_slice($players, 0, 12)), 'count' => count($players)));
    exit;
}

// ── INFO ─────────────────────────────────────────────────────────
if ($action === 'info') {
    $licence = trim($_GET['licence'] ?? '');
    if (!$licence) { echo json_encode(array('error' => 'Licence manquante')); exit; }

    $nom = ''; $prenom = ''; $club = ''; $sexe = '';
    $rankings = array();

    // 1. API officielle
    $info = api_call('ws_getlicenceinfobylicence',
        array('Licence' => $licence, 'NotLastSeasonOnly' => '0'),
        $API_LOGIN, $API_PASSWORD, $API_URL);

    if (!isset($info['_curl_error']) && !isset($info['_http_error'])) {
        $pList = to_list($info['licenceinfo'] ?? $info['Licence'] ?? array());
        if ($pList) {
            $p = $pList[0];
            $nom    = (string)($p['Nom']     ?? '');
            $prenom = (string)($p['Prenom']  ?? '');
            $club   = (string)($p['NomClub'] ?? $p['Club'] ?? '');
            $sexe   = (string)($p['Sexe']    ?? '');
        }
    }

    $rank = api_call('ws_getrankingallbyarrayoflicence',
        array('ArrayOfLicence' => $licence),
        $API_LOGIN, $API_PASSWORD, $API_URL);

    if (!isset($rank['_curl_error']) && !isset($rank['_http_error'])) {
        foreach (to_list($rank['ranking'] ?? $rank['Ranking'] ?? array()) as $r) {
            $pts = (int)($r['Cote'] ?? $r['Points'] ?? 0);
            if (!$pts) continue;
            $rankings[] = array(
                'discipline' => (string)($r['Discipline'] ?? ''),
                'sexe'       => (string)($r['Sexe']       ?? ''),
                'points'     => $pts,
                'rang'       => (int)($r['Rang']           ?? 0),
                'niveau'     => (string)($r['Niveau']      ?? ''),
            );
        }
        usort($rankings, function($a,$b){return $b['points']-$a['points'];});
    }

    // 2. Fallback MyFFBaD si API bloquée
    if (!$nom) {
        $minfo = myffbad_info($licence);
        if (!empty($minfo['nom'])) {
            $nom      = $minfo['nom'];
            $prenom   = $minfo['prenom'];
            $club     = $minfo['club'];
            $sexe     = $minfo['sexe'];
            $rankings = $minfo['rankings'] ?? array();
        }
    }

    echo json_encode(array(
        'licence'  => $licence,
        'nom'      => $nom,
        'prenom'   => $prenom,
        'club'     => $club,
        'sexe'     => $sexe,
        'rankings' => $rankings,
    ));
    exit;
}

echo json_encode(array('error' => 'Action inconnue'));
