<?php
/**
 * ffbad.php — Scraping public ffbadminton.fr (sans identifiants)
 * Compatible PHP 7.2+ · Répond au même format JSON qu'avant
 *
 * ?action=test              vérifier la connectivité
 * ?action=search&q=NOM      recherche par nom → résultats autocomplete
 * ?action=info&licence=XXX  détail + classement d'un joueur
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
error_reporting(0);

// ── Configuration ────────────────────────────────────────────────
// URL de recherche publique FFBaD (sans login)
// Si l'URL change un jour, modifier ici seulement.
define('FFBAD_SEARCH',  'https://www.ffbadminton.fr/joueurs/');
define('FFBAD_RANKING', 'https://www.ffbadminton.fr/classement/');
define('FFBAD_ORIGIN',  'https://www.ffbadminton.fr');
define('FFBAD_UA',      'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
define('FFBAD_TIMEOUT', 12);
define('FFBAD_COOKIE',  sys_get_temp_dir() . '/ffbad_sess.txt');

// ── HTTP ─────────────────────────────────────────────────────────
/**
 * Fetch une URL (GET) avec les bons headers navigateur.
 * Retourne ['html' => '...', 'code' => 200] ou ['error' => '...'].
 */
function ffbad_get($url, array $params = []) {
    if (!function_exists('curl_init')) {
        return ['error' => 'cURL non disponible sur ce serveur'];
    }
    if ($params) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => FFBAD_TIMEOUT,
        CURLOPT_ENCODING       => '',          // supporte gzip/deflate
        CURLOPT_USERAGENT      => FFBAD_UA,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
            'Accept-Language: fr-FR,fr;q=0.9,en;q=0.7',
            'Referer: ' . FFBAD_ORIGIN . '/',
            'Cache-Control: no-cache',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIEJAR      => FFBAD_COOKIE,
        CURLOPT_COOKIEFILE     => FFBAD_COOKIE,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $body === '') {
        return ['error' => $err ?: 'Réponse vide de ffbadminton.fr'];
    }
    if ($code >= 400) {
        return ['error' => "HTTP $code — ffbadminton.fr inaccessible"];
    }
    return ['html' => (string) $body, 'code' => $code];
}

// ── Parsing HTML ─────────────────────────────────────────────────
/**
 * Extrait les lignes <tr> d'un tableau HTML en tableau de tableaux de strings.
 */
function html_table_rows($html) {
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp   = new DOMXPath($dom);
    $rows = $xp->query('//table//tr');
    $out  = [];
    foreach ($rows as $row) {
        $cells = $xp->query('td|th', $row);
        $vals  = [];
        foreach ($cells as $c) {
            $vals[] = trim(preg_replace('/\s+/', ' ', $c->textContent));
        }
        if (count($vals) >= 2) $out[] = $vals;
    }
    return $out;
}

/**
 * Cherche un numéro de licence FFBaD (7–8 chiffres) dans un tableau de valeurs.
 */
function extract_licence(array $vals) {
    foreach ($vals as $v) {
        if (preg_match('/^\d{7,8}$/', trim($v))) return trim($v);
    }
    return '';
}

/**
 * Cherche un niveau de classement FFBaD (P, D8..D1, N3..N1, R6..R1)
 */
function extract_niveau($val) {
    $v = strtoupper(trim($val));
    if (preg_match('/^(P|D[1-9]|N[1-3]|R[1-6]|[1-9]\d*)$/', $v)) return $v;
    return '';
}

/**
 * Interprète une ligne du tableau joueurs et renvoie un tableau normalisé.
 * Essaie plusieurs ordres de colonnes connus sur ffbadminton.fr.
 */
function interpret_player_row(array $vals) {
    $licence = extract_licence($vals);
    if (!$licence) return null;

    $idx = array_search($licence, array_map('trim', $vals));

    // Format A — Licence | Nom | Prénom | Club | Département | Catégorie | Classement
    if ($idx === 0) {
        return [
            'licence'    => $licence,
            'nom'        => $vals[1] ?? '',
            'prenom'     => $vals[2] ?? '',
            'club'       => $vals[3] ?? '',
            'categorie'  => $vals[5] ?? '',
            'classement' => extract_niveau($vals[6] ?? ($vals[5] ?? '')),
        ];
    }

    // Format B — Nom | Prénom | Licence | Club | Catégorie | Classement
    if ($idx === 2) {
        return [
            'licence'    => $licence,
            'nom'        => $vals[0] ?? '',
            'prenom'     => $vals[1] ?? '',
            'club'       => $vals[3] ?? '',
            'categorie'  => $vals[4] ?? '',
            'classement' => extract_niveau($vals[5] ?? ''),
        ];
    }

    // Format générique — licence trouvée ailleurs
    $rest = array_values(array_filter($vals, fn($v) => trim($v) !== $licence));
    return [
        'licence'    => $licence,
        'nom'        => $rest[0] ?? '',
        'prenom'     => $rest[1] ?? '',
        'club'       => $rest[2] ?? '',
        'categorie'  => '',
        'classement' => '',
    ];
}

/**
 * Parse les joueurs depuis le HTML d'une page de résultats.
 */
function parse_players_html($html) {
    $rows    = html_table_rows($html);
    $players = [];
    foreach ($rows as $row) {
        $p = interpret_player_row($row);
        if ($p && !empty($p['nom'])) {
            $players[] = [
                'licence' => $p['licence'],
                'nom'     => $p['nom'],
                'prenom'  => $p['prenom'],
                'club'    => $p['club'],
                'sexe'    => '',
            ];
        }
    }
    // Dédupliquer par licence
    $seen = [];
    $out  = [];
    foreach ($players as $p) {
        if (!isset($seen[$p['licence']])) {
            $seen[$p['licence']] = true;
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * Parse les classements d'un joueur depuis le HTML de sa page détail.
 * Cherche une table contenant discipline + points/niveau.
 */
function parse_rankings_html($html) {
    $rows     = html_table_rows($html);
    $rankings = [];
    $disc_map = ['SH' => 'SH', 'SD' => 'SD', 'DH' => 'DH', 'DD' => 'DD', 'DX' => 'DX',
                 'SIMPLE H' => 'SH', 'SIMPLE D' => 'SD',
                 'DOUBLE H' => 'DH', 'DOUBLE D' => 'DD', 'DOUBLE X' => 'DX', 'MIXTE' => 'DX'];

    foreach ($rows as $row) {
        $discipline = '';
        $niveau     = '';
        $points     = 0;

        foreach ($row as $cell) {
            $up = strtoupper(trim($cell));
            if (isset($disc_map[$up])) { $discipline = $disc_map[$up]; continue; }
            if ($n = extract_niveau($cell)) { $niveau = $n; continue; }
            if (is_numeric($cell) && (int)$cell > 0) { $points = (int)$cell; }
        }
        if ($discipline && ($niveau || $points)) {
            $rankings[] = ['discipline' => $discipline, 'sexe' => '', 'points' => $points, 'rang' => 0, 'niveau' => $niveau];
        }
    }
    return $rankings;
}

/**
 * Essaie d'extraire un lien vers la page détail d'un joueur donné.
 */
function find_player_detail_url($html, $base, $licence) {
    preg_match_all('/<a[^>]+href=["\']([^"\']*)["\'][^>]*>/i', $html, $m);
    foreach ($m[1] as $href) {
        if (strpos($href, $licence) !== false || preg_match('/joueur|licence|fiche|profil/i', $href)) {
            if (strpos($href, 'http') === 0) return $href;
            return rtrim($base, '/') . '/' . ltrim($href, '/');
        }
    }
    return '';
}

// ── Routing ──────────────────────────────────────────────────────
$action = isset($_GET['action']) ? $_GET['action'] : 'test';

// ── TEST ─────────────────────────────────────────────────────────
if ($action === 'test') {
    $r = ffbad_get(FFBAD_SEARCH);
    if (isset($r['error'])) {
        echo json_encode(['ok' => false, 'error' => $r['error']]);
    } else {
        $ok = strlen($r['html']) > 200;
        echo json_encode(['ok' => $ok, 'http' => $r['code'], 'bytes' => strlen($r['html']),
                          'message' => $ok ? 'ffbadminton.fr accessible' : 'Réponse inattendue']);
    }
    exit;
}

// ── SEARCH ───────────────────────────────────────────────────────
if ($action === 'search') {
    $q = trim(isset($_GET['q']) ? $_GET['q'] : '');
    if (strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    // Séparer "Nom Prénom" → nom = première partie, prenom = reste
    $parts  = preg_split('/\s+/', $q, 2);
    $nom    = strtoupper($parts[0]);
    $prenom = count($parts) > 1 ? $parts[1] : '';

    // Essai 1 — recherche par nom
    $r = ffbad_get(FFBAD_SEARCH, [
        'nom'    => $nom,
        'prenom' => $prenom,
        'club'   => '',
        'submit' => '1',
    ]);

    if (isset($r['error'])) {
        // Essai 2 — noms de paramètres alternatifs
        $r = ffbad_get(FFBAD_SEARCH, [
            'search_nom'    => $nom,
            'search_prenom' => $prenom,
            'q'             => $q,
        ]);
    }

    if (isset($r['error'])) {
        echo json_encode(['error' => $r['error'], 'results' => []]);
        exit;
    }

    $players = parse_players_html($r['html']);

    // Si le site a retourné 0 résultats et que le HTML semble être
    // une page avec un formulaire (pas de tableau), on retourne vide proprement.
    echo json_encode(['results' => array_values(array_slice($players, 0, 12)), 'count' => count($players)]);
    exit;
}

// ── INFO ─────────────────────────────────────────────────────────
if ($action === 'info') {
    $licence = trim(isset($_GET['licence']) ? $_GET['licence'] : '');
    if (!$licence || !preg_match('/^\d{7,8}$/', $licence)) {
        echo json_encode(['error' => 'Licence invalide']);
        exit;
    }

    // Recherche le joueur par sa licence pour avoir ses infos de base
    $r = ffbad_get(FFBAD_SEARCH, ['licence' => $licence, 'submit' => '1']);
    if (isset($r['error'])) {
        $r = ffbad_get(FFBAD_SEARCH, ['search_licence' => $licence]);
    }

    $nom    = '';
    $prenom = '';
    $club   = '';
    $sexe   = '';

    if (!isset($r['error'])) {
        $players = parse_players_html($r['html']);
        foreach ($players as $p) {
            if ($p['licence'] === $licence) {
                $nom    = $p['nom'];
                $prenom = $p['prenom'];
                $club   = $p['club'];
                break;
            }
        }
        // Chercher un lien vers la fiche détaillée (classements)
        $detail_url = find_player_detail_url($r['html'], FFBAD_ORIGIN, $licence);
    }

    $rankings = [];

    // Page de classement par licence
    $rk = ffbad_get(FFBAD_RANKING, ['licence' => $licence, 'submit' => '1']);
    if (!isset($rk['error'])) {
        $rankings = parse_rankings_html($rk['html']);
    }

    // Si on a une page détail, la tenter aussi
    if (empty($rankings) && !empty($detail_url)) {
        $det = ffbad_get($detail_url);
        if (!isset($det['error'])) {
            $rankings = parse_rankings_html($det['html']);
        }
    }

    echo json_encode([
        'licence'  => $licence,
        'nom'      => $nom,
        'prenom'   => $prenom,
        'club'     => $club,
        'sexe'     => $sexe,
        'rankings' => $rankings,
    ]);
    exit;
}

echo json_encode(['error' => 'Action inconnue. Essayez: ?action=test']);
