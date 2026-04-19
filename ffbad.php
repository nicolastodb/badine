<?php
/**
 * ffbad.php — Lookup joueurs FFBaD pour Badine
 * Stratégie : API officielle api.ffbad.org → fallback scraping ffbadminton.fr
 * Compatible PHP 7.2+
 *
 * ?action=test              vérifier la connectivité
 * ?action=search&q=NOM      autocomplétion par nom
 * ?action=info&licence=XXX  détail + classement d'un joueur
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
error_reporting(0);

// ── Identifiants API officielle FFBaD ────────────────────────────
// Obtenus via votre ligue régionale ou api@ffbad.org
$API_LOGIN    = '07346745';
$API_PASSWORD = 'zuxnic-pIfmyp-6nanci';
$API_URL      = 'https://api.ffbad.org/FFBAD-WS.php';

// ── Appel API officielle ─────────────────────────────────────────
function api_call($function, $params, $login, $password, $url) {
    $payload = json_encode(array(
        'Auth'   => json_encode(array('Login' => $login, 'Password' => $password)),
        'Params' => json_encode(array_merge(array('Function' => $function), $params)),
    ));

    $ctx = stream_context_create(array('http' => array(
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => $payload,
        'timeout'       => 10,
        'ignore_errors' => true,
    )));

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;

    // Vérifier code HTTP
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('/HTTP\/\S+ (\d+)/', $h, $m) && (int)$m[1] >= 400) return null;
        }
    }

    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

// Normalise une valeur brute de l'API en tableau de lignes
function to_list($val) {
    if (empty($val)) return array();
    if (isset($val[0]) && is_array($val[0])) return $val;
    if (isset($val['Licence'])) return array($val);
    if (is_array($val)) return array_values($val);
    return array();
}

// ── Scraping fallback (ffbadminton.fr) ───────────────────────────
function scrape_get($url, $params = array()) {
    if (!function_exists('curl_init')) return null;
    if ($params) $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15',
        CURLOPT_HTTPHEADER     => array(
            'Accept: text/html,*/*;q=0.8',
            'Accept-Language: fr-FR,fr;q=0.9',
            'Referer: https://www.ffbadminton.fr/',
        ),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIEJAR      => sys_get_temp_dir() . '/ffbad_s.txt',
        CURLOPT_COOKIEFILE     => sys_get_temp_dir() . '/ffbad_s.txt',
    ));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$body || $code >= 400) return null;
    return $body;
}

function scrape_players($html) {
    if (!$html) return array();
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);

    $players = array();
    $rows = $xp->query('//table//tr');
    foreach ($rows as $row) {
        $cells = $xp->query('td', $row);
        $vals  = array();
        foreach ($cells as $c) $vals[] = trim(preg_replace('/\s+/', ' ', $c->textContent));
        if (count($vals) < 3) continue;

        $licence = '';
        foreach ($vals as $v) {
            if (preg_match('/^\d{7,8}$/', $v)) { $licence = $v; break; }
        }
        if (!$licence) continue;
        $idx = array_search($licence, $vals);

        if ($idx === 0) {
            $players[] = array('licence'=>$licence,'nom'=>$vals[1]??'','prenom'=>$vals[2]??'','club'=>$vals[3]??'','sexe'=>'');
        } else {
            $players[] = array('licence'=>$licence,'nom'=>$vals[0]??'','prenom'=>$vals[1]??'','club'=>$vals[3]??'','sexe'=>'');
        }
    }

    // Dédupliquer
    $seen = array(); $out = array();
    foreach ($players as $p) {
        if ($p['nom'] && !isset($seen[$p['licence']])) { $seen[$p['licence']]=1; $out[]=$p; }
    }
    return $out;
}

// ── Routing ──────────────────────────────────────────────────────
$action = isset($_GET['action']) ? $_GET['action'] : 'test';

// ── TEST ─────────────────────────────────────────────────────────
if ($action === 'test') {
    $r = api_call('ws_test', array(), $API_LOGIN, $API_PASSWORD, $API_URL);
    if ($r !== null) {
        echo json_encode(array('ok'=>true, 'source'=>'api', 'message'=>'API FFBaD OK'));
    } else {
        $html = scrape_get('https://www.ffbadminton.fr/joueurs/');
        echo json_encode(array(
            'ok'      => (bool)$html,
            'source'  => 'scraping',
            'message' => $html ? 'ffbadminton.fr accessible (API indisponible)' : 'Aucune source accessible',
        ));
    }
    exit;
}

// ── SEARCH ───────────────────────────────────────────────────────
if ($action === 'search') {
    $q = trim(isset($_GET['q']) ? $_GET['q'] : '');
    if (strlen($q) < 2) { echo json_encode(array('results'=>array())); exit; }

    $players = array();

    // 1. API officielle
    if ($API_LOGIN) {
        $parts = preg_split('/\s+/', $q, 2);
        $nom   = strtoupper($parts[0]);

        $res = api_call('ws_getlicenceinfobystartnom',
            array('Nom' => $nom, 'NotLastSeasonOnly' => '0'),
            $API_LOGIN, $API_PASSWORD, $API_URL);

        if ($res !== null) {
            $raw  = isset($res['licenceinfo']) ? $res['licenceinfo']
                  : (isset($res['Licence'])    ? $res['Licence'] : array());
            $list = to_list($raw);
            foreach (array_slice($list, 0, 15) as $p) {
                if (!isset($p['Licence'])) continue;
                $players[] = array(
                    'licence' => (string)$p['Licence'],
                    'nom'     => isset($p['Nom'])     ? (string)$p['Nom']     : '',
                    'prenom'  => isset($p['Prenom'])  ? (string)$p['Prenom']  : '',
                    'club'    => isset($p['NomClub']) ? (string)$p['NomClub'] : (isset($p['Club']) ? (string)$p['Club'] : ''),
                    'sexe'    => isset($p['Sexe'])    ? (string)$p['Sexe']    : '',
                );
            }
        }
    }

    // 2. Fallback scraping si API vide
    if (empty($players)) {
        $parts  = preg_split('/\s+/', $q, 2);
        $nom    = strtoupper($parts[0]);
        $prenom = count($parts) > 1 ? $parts[1] : '';

        $html = scrape_get('https://www.ffbadminton.fr/joueurs/', array(
            'nom'    => $nom,
            'prenom' => $prenom,
            'submit' => '1',
        ));
        if (!$html) {
            $html = scrape_get('https://www.ffbadminton.fr/joueurs/', array(
                'search_nom' => $nom, 'search_prenom' => $prenom,
            ));
        }
        $players = scrape_players($html);
    }

    echo json_encode(array('results' => array_values(array_slice($players, 0, 12)), 'count' => count($players)));
    exit;
}

// ── INFO ─────────────────────────────────────────────────────────
if ($action === 'info') {
    $licence = trim(isset($_GET['licence']) ? $_GET['licence'] : '');
    if (!$licence) { echo json_encode(array('error'=>'Licence manquante')); exit; }

    $nom = ''; $prenom = ''; $club = ''; $sexe = '';
    $rankings = array();

    // 1. API officielle
    if ($API_LOGIN) {
        $info = api_call('ws_getlicenceinfobylicence',
            array('Licence' => $licence, 'NotLastSeasonOnly' => '0'),
            $API_LOGIN, $API_PASSWORD, $API_URL);

        $rank = api_call('ws_getrankingallbyarrayoflicence',
            array('ArrayOfLicence' => $licence),
            $API_LOGIN, $API_PASSWORD, $API_URL);

        if ($info !== null) {
            $pRaw  = isset($info['licenceinfo']) ? $info['licenceinfo']
                   : (isset($info['Licence'])    ? $info['Licence'] : array());
            $pList = to_list($pRaw);
            $p     = isset($pList[0]) ? $pList[0] : array();
            $nom    = isset($p['Nom'])     ? (string)$p['Nom']     : '';
            $prenom = isset($p['Prenom'])  ? (string)$p['Prenom']  : '';
            $club   = isset($p['NomClub']) ? (string)$p['NomClub'] : (isset($p['Club']) ? (string)$p['Club'] : '');
            $sexe   = isset($p['Sexe'])    ? (string)$p['Sexe']    : '';
        }

        if ($rank !== null) {
            $rRaw  = isset($rank['ranking']) ? $rank['ranking']
                   : (isset($rank['Ranking']) ? $rank['Ranking'] : array());
            $rList = to_list($rRaw);
            foreach ($rList as $r) {
                $pts = isset($r['Cote']) ? (int)$r['Cote']
                     : (isset($r['Points']) ? (int)$r['Points'] : 0);
                if (!$pts) continue;
                $rankings[] = array(
                    'discipline' => isset($r['Discipline']) ? (string)$r['Discipline'] : '',
                    'sexe'       => isset($r['Sexe'])       ? (string)$r['Sexe']       : '',
                    'points'     => $pts,
                    'rang'       => isset($r['Rang'])        ? (int)$r['Rang']          : 0,
                    'niveau'     => isset($r['Niveau'])      ? (string)$r['Niveau']     : '',
                );
            }
            usort($rankings, function($a, $b) { return $b['points'] - $a['points']; });
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
