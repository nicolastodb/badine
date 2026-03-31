<?php
/**
 * ffbad.php — Proxy FFBaD pour Badine
 * Compatible PHP 7.2+
 *
 * CONFIGURATION : renseignez vos identifiants API FFBaD ci-dessous.
 * Pour obtenir des accès : contacter votre ligue régionale ou api@ffbad.org
 *
 * Test : ouvrir https://badine.toisier.fr/ffbad.php?action=test
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
error_reporting(0); // ne pas fuiter les erreurs PHP en JSON

// ── IDENTIFIANTS API ─────────────────────────────────────────────
$FFBAD_LOGIN    = '07346745';   // ← votre login API FFBaD
$FFBAD_PASSWORD = 'zuxnic-pIfmyp-6nanci';   // ← votre mot de passe API FFBaD
$FFBAD_WS_URL   = 'https://api.ffbad.org/FFBAD-WS.php';

// ── APPEL API ────────────────────────────────────────────────────
function ffbad_call($function, $params, $login, $password, $url) {
    if (!$login || !$password) {
        return array('_error' => 'Identifiants manquants — configurez ffbad.php');
    }

    $auth    = json_encode(array('Login' => $login, 'Password' => $password));
    $body    = json_encode(array_merge(array('Function' => $function), $params));
    $payload = json_encode(array('Auth' => $auth, 'Params' => $body));

    $opts = array(
        'http' => array(
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 10,
            'ignore_errors' => true,
        )
    );

    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $ctx);

    if ($raw === false) {
        return array('_error' => 'Impossible de joindre api.ffbad.org — vérifiez que OVH autorise les requêtes sortantes');
    }

    // Vérifier le code HTTP
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('/HTTP\/\d\.?\d? (\d+)/', $h, $m)) {
                $code = (int)$m[1];
                if ($code === 401 || $code === 403) {
                    return array('_error' => 'Identifiants invalides (HTTP ' . $code . ')');
                }
                break;
            }
        }
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array('_error' => 'Réponse invalide', 'raw' => substr($raw, 0, 300));
    }
    return $decoded;
}

// Normalise en tableau de lignes
function to_list($val) {
    if (empty($val)) return array();
    if (isset($val[0]) && is_array($val[0])) return $val; // déjà un tableau
    if (isset($val['Licence'])) return array($val);        // objet unique
    if (is_array($val)) return array_values($val);
    return array();
}

// ── ROUTING ──────────────────────────────────────────────────────
$action = isset($_GET['action']) ? $_GET['action'] : 'test';

// ── TEST ─────────────────────────────────────────────────────────
if ($action === 'test') {
    if (!$FFBAD_LOGIN) {
        echo json_encode(array(
            'ok'    => false,
            'error' => 'Identifiants non configurés. Ouvrez ffbad.php et renseignez $FFBAD_LOGIN et $FFBAD_PASSWORD'
        ));
        exit;
    }
    $r = ffbad_call('ws_test', array(), $FFBAD_LOGIN, $FFBAD_PASSWORD, $FFBAD_WS_URL);
    if (isset($r['_error'])) {
        echo json_encode(array('ok' => false, 'error' => $r['_error']));
    } else {
        echo json_encode(array('ok' => true, 'message' => 'Connexion FFBaD OK', 'response' => $r));
    }
    exit;
}

// ── SEARCH ───────────────────────────────────────────────────────
if ($action === 'search') {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 2) {
        echo json_encode(array('results' => array()));
        exit;
    }

    $res = ffbad_call('ws_getlicenceinfobystartnom',
        array('Nom' => strtoupper($q), 'NotLastSeasonOnly' => '0'),
        $FFBAD_LOGIN, $FFBAD_PASSWORD, $FFBAD_WS_URL
    );

    if (isset($res['_error'])) {
        echo json_encode(array('error' => $res['_error'], 'results' => array()));
        exit;
    }

    // L'API peut retourner différentes clés selon la version
    $raw = isset($res['licenceinfo'])
        ? $res['licenceinfo']
        : (isset($res['Licence']) ? $res['Licence'] : array());

    $list    = to_list($raw);
    $players = array();

    foreach (array_slice($list, 0, 12) as $p) {
        if (!isset($p['Licence'])) continue;
        $players[] = array(
            'licence' => (string)$p['Licence'],
            'nom'     => isset($p['Nom'])     ? (string)$p['Nom']     : '',
            'prenom'  => isset($p['Prenom'])  ? (string)$p['Prenom']  : '',
            'club'    => isset($p['NomClub']) ? (string)$p['NomClub'] : (isset($p['Club']) ? (string)$p['Club'] : ''),
            'sexe'    => isset($p['Sexe'])    ? (string)$p['Sexe']    : '',
        );
    }

    echo json_encode(array('results' => $players, 'count' => count($players)));
    exit;
}

// ── INFO ─────────────────────────────────────────────────────────
if ($action === 'info') {
    $licence = isset($_GET['licence']) ? trim($_GET['licence']) : '';
    if (!$licence) {
        echo json_encode(array('error' => 'Licence manquante'));
        exit;
    }

    $info = ffbad_call('ws_getlicenceinfobylicence',
        array('Licence' => $licence, 'NotLastSeasonOnly' => '0'),
        $FFBAD_LOGIN, $FFBAD_PASSWORD, $FFBAD_WS_URL
    );
    if (isset($info['_error'])) {
        echo json_encode(array('error' => $info['_error']));
        exit;
    }

    $rank = ffbad_call('ws_getrankingallbyarrayoflicence',
        array('ArrayOfLicence' => $licence),
        $FFBAD_LOGIN, $FFBAD_PASSWORD, $FFBAD_WS_URL
    );

    $pRaw   = isset($info['licenceinfo']) ? $info['licenceinfo'] : (isset($info['Licence']) ? $info['Licence'] : array());
    $pList  = to_list($pRaw);
    $player = isset($pList[0]) ? $pList[0] : array();

    $rRaw     = isset($rank['ranking']) ? $rank['ranking'] : (isset($rank['Ranking']) ? $rank['Ranking'] : array());
    $rList    = to_list($rRaw);
    $rankings = array();

    foreach ($rList as $r) {
        $pts = isset($r['Cote']) ? (int)$r['Cote'] : (isset($r['Points']) ? (int)$r['Points'] : 0);
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

    echo json_encode(array(
        'licence'  => $licence,
        'nom'      => isset($player['Nom'])    ? (string)$player['Nom']    : '',
        'prenom'   => isset($player['Prenom']) ? (string)$player['Prenom'] : '',
        'club'     => isset($player['NomClub']) ? (string)$player['NomClub'] : (isset($player['Club']) ? (string)$player['Club'] : ''),
        'sexe'     => isset($player['Sexe'])   ? (string)$player['Sexe']   : '',
        'rankings' => $rankings,
    ));
    exit;
}

// Ne devrait pas arriver
echo json_encode(array('error' => 'Action inconnue. Essayez: ?action=test'));
