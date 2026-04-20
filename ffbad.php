<?php
/**
 * ffbad.php — Lookup joueurs FFBaD pour Badine
 * ?action=test              connectivité
 * ?action=debug&q=NOM      réponse brute (diagnostic)
 * ?action=search&q=NOM     autocomplétion
 * ?action=info&licence=XXX détail + classement
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
error_reporting(0);

$API_LOGIN    = '07346745';
$API_PASSWORD = 'zuxnic-pIfmyp-6nanci';
$API_URL      = 'https://api.ffbad.org/FFBAD-WS.php';

// ── cURL helper (API JSON calls) ──────────────────────────────────
function do_curl($url, $post_body = null, $extra_headers = array()) {
    if (!function_exists('curl_init')) return array('error' => 'cURL absent');
    $ch = curl_init($url);
    $headers = array_merge(array('Accept: application/json, text/html, */*'), $extra_headers);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Badine/1.0 (+https://badminton)',
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
    if ($body === false || $body === '') return array('error' => $err ?: 'empty response', 'code' => $code);
    return array('body' => $body, 'code' => $code);
}

// ── cURL helper (browser scraping avec session cookies) ───────────
function do_curl_browser($url, $referer = null, $cookie_file = null) {
    if (!function_exists('curl_init')) return array('error' => 'cURL absent', 'code' => 0);
    $ch = curl_init($url);
    $headers = array(
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.217 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
        'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept-Encoding: gzip, deflate, br',
        'Cache-Control: max-age=0',
        'Sec-Ch-Ua: "Not_A Brand";v="8","Chromium";v="120","Google Chrome";v="120"',
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
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    );
    if ($cookie_file) {
        $opts[CURLOPT_COOKIEFILE] = $cookie_file;
        $opts[CURLOPT_COOKIEJAR]  = $cookie_file;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array('body' => $body ?: '', 'code' => $code, 'error' => $err);
}

// ── Appel API officielle FFBaD ─────────────────────────────────────
function api_call($function, $params, $login, $password, $url) {
    $payload = json_encode(array(
        'Auth'   => json_encode(array('Login' => $login, 'Password' => $password)),
        'Params' => json_encode(array_merge(array('Function' => $function), $params)),
    ));
    $r = do_curl($url, $payload, array('Content-Type: application/json'));
    if (isset($r['error'])) return array('_curl_error' => $r['error'], '_code' => $r['code'] ?? 0);
    if ($r['code'] >= 400)  return array('_http_error' => $r['code'], '_body' => substr($r['body'], 0, 200));
    $d = json_decode($r['body'], true);
    return is_array($d) ? $d : array('_parse_error' => substr($r['body'], 0, 200));
}

function to_list($val) {
    if (empty($val)) return array();
    if (isset($val[0]) && is_array($val[0])) return $val;
    if (isset($val['Licence'])) return array($val);
    if (is_array($val)) return array_values($val);
    return array();
}

// ── Scraping ffbadminton.fr avec session ─────────────────────────
function scrape_search($nom, $prenom = '') {
    $tmp = tempnam(sys_get_temp_dir(), 'ffbad_');

    // 1. Établir la session : charger la homepage
    do_curl_browser('https://www.ffbadminton.fr/', null, $tmp);

    // 2. Visiter /joueurs/ pour le referer
    do_curl_browser('https://www.ffbadminton.fr/joueurs/', 'https://www.ffbadminton.fr/', $tmp);

    // 3. Lancer la recherche avec session + referer
    $r = do_curl_browser(
        'https://www.ffbadminton.fr/joueurs/?' . http_build_query(array(
            'nom' => $nom, 'prenom' => $prenom, 'submit' => '1',
        )),
        'https://www.ffbadminton.fr/joueurs/',
        $tmp
    );
    @unlink($tmp);

    if ($r['code'] !== 200 || empty($r['body'])) {
        return array('_scrape_error' => $r['code'], '_curl_error' => $r['error']);
    }
    return parse_players_html($r['body']);
}

function parse_players_html($html) {
    if (!$html) return array();
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $players = array(); $seen = array();
    foreach ($xp->query('//table//tr') as $row) {
        $cells = $xp->query('td', $row);
        $vals  = array();
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
    return $players;
}

// ── Routing ──────────────────────────────────────────────────────
$action = isset($_GET['action']) ? $_GET['action'] : 'test';

// ── TEST ──────────────────────────────────────────────────────────
if ($action === 'test') {
    $r = api_call('ws_test', array(), $API_LOGIN, $API_PASSWORD, $API_URL);
    $api_ok = !isset($r['_curl_error']) && !isset($r['_http_error']) && !isset($r['_parse_error']);
    echo json_encode(array('ok' => $api_ok, 'api_response' => $r));
    exit;
}

// ── DEBUG ─────────────────────────────────────────────────────────
if ($action === 'debug') {
    $q   = trim($_GET['q'] ?? '');
    $nom = strtoupper(preg_split('/\s+/', $q)[0]);

    $api_raw = api_call('ws_getlicenceinfobystartnom',
        array('Nom' => $nom, 'NotLastSeasonOnly' => '0'),
        $API_LOGIN, $API_PASSWORD, $API_URL);

    $scrape = scrape_search($nom);
    $scrape_players = is_array($scrape) && !isset($scrape['_scrape_error']) ? $scrape : array();
    $scrape_error   = isset($scrape['_scrape_error']) ? $scrape : null;

    echo json_encode(array(
        'server_ip'     => gethostbyname(gethostname()),
        'api_raw'       => $api_raw,
        'scrape_error'  => $scrape_error,
        'scrape_count'  => count($scrape_players),
        'scrape_first3' => array_slice($scrape_players, 0, 3),
    ), JSON_PRETTY_PRINT);
    exit;
}

// ── SEARCH ───────────────────────────────────────────────────────
if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode(array('results' => array())); exit; }

    $parts  = preg_split('/\s+/', $q, 2);
    $nom    = strtoupper($parts[0]);
    $prenom = count($parts) > 1 ? $parts[1] : '';

    $players = array();

    // 1. API officielle
    $res = api_call('ws_getlicenceinfobystartnom',
        array('Nom' => $nom, 'NotLastSeasonOnly' => '0'),
        $API_LOGIN, $API_PASSWORD, $API_URL);

    if (!isset($res['_curl_error']) && !isset($res['_http_error'])) {
        $raw  = $res['licenceinfo'] ?? $res['Licence'] ?? array();
        $list = to_list($raw);
        foreach (array_slice($list, 0, 15) as $p) {
            if (!isset($p['Licence'])) continue;
            $players[] = array(
                'licence' => (string)($p['Licence']),
                'nom'     => (string)($p['Nom']     ?? ''),
                'prenom'  => (string)($p['Prenom']  ?? ''),
                'club'    => (string)($p['NomClub'] ?? $p['Club'] ?? ''),
                'sexe'    => (string)($p['Sexe']    ?? ''),
            );
        }
    }

    // 2. Fallback scraping
    if (empty($players)) {
        $scraped = scrape_search($nom, $prenom);
        if (is_array($scraped) && !isset($scraped['_scrape_error'])) {
            $players = $scraped;
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

    $info = api_call('ws_getlicenceinfobylicence',
        array('Licence' => $licence, 'NotLastSeasonOnly' => '0'),
        $API_LOGIN, $API_PASSWORD, $API_URL);

    if (!isset($info['_curl_error']) && !isset($info['_http_error'])) {
        $pRaw  = $info['licenceinfo'] ?? $info['Licence'] ?? array();
        $pList = to_list($pRaw);
        if ($pList) {
            $p      = $pList[0];
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
        $rRaw  = $rank['ranking'] ?? $rank['Ranking'] ?? array();
        $rList = to_list($rRaw);
        foreach ($rList as $r) {
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
        usort($rankings, function($a, $b) { return $b['points'] - $a['points']; });
    }

    // Si l'API est bloquée mais qu'on a juste la licence, retourner une erreur claire
    if (!$nom && isset($info['_http_error'])) {
        echo json_encode(array('error' => 'API non accessible depuis ce serveur', 'licence' => $licence));
        exit;
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
