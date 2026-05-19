<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Helpers globaux utilisables directement dans les vues.

/** Échappe une chaîne pour inclusion sûre dans du HTML. */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Vrai si la requête arrive en HTTPS (directement, via un reverse-proxy
 * qui pose X-Forwarded-Proto, ou si le serveur renseigne REQUEST_SCHEME).
 * Utilisé pour décider du flag Secure sur les cookies.
 */
function isHttps(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https';
}

/** 'HH:MM' → minutes depuis minuit. */
function heureEnMinutes(string $hhmm): int
{
    [$h, $m] = array_pad(explode(':', $hhmm, 2), 2, '0');
    return ((int)$h) * 60 + (int)$m;
}

/**
 * Couverture de la plage du jour par les référent·es :
 *   'complete' — toute la plage est couverte
 *   'partielle' — couverture partielle
 *   'absente' — aucun·e référent·e
 */
function couleurReferente(array $jour): string
{
    $ref = $jour['referentes'] ?? [];
    if (count($ref) === 0) {
        return 'absente';
    }

    $debut = heureEnMinutes($jour['heure_debut']);
    $fin   = heureEnMinutes($jour['heure_fin']);

    // heure_fin null = pas d'engagement sur la sortie. Interprétation
    // optimiste : on considère la couverture jusqu'à la fin du créneau
    // (sinon tou·tes les référent·es sans heure de sortie apparaîtraient
    // partiel·les, ce qui reviendrait à les sanctionner pour leur souplesse).
    $plages = [];
    foreach ($ref as $r) {
        $plages[] = [
            heureEnMinutes($r['heure_debut']),
            $r['heure_fin'] === null || $r['heure_fin'] === ''
                ? $fin
                : heureEnMinutes($r['heure_fin']),
        ];
    }
    usort($plages, fn(array $a, array $b): int => $a[0] <=> $b[0]);

    $curseur = $debut;
    foreach ($plages as [$d, $f]) {
        if ($d > $curseur) {
            break;
        }
        if ($f > $curseur) {
            $curseur = $f;
        }
    }

    return $curseur >= $fin ? 'complete' : 'partielle';
}

/**
 * Retourne la marque de pluriel si $n > 1, sinon vide.
 * Usage : "<?= $n ?> fermeture<?= pluriel($n) ?>" → "1 fermeture" ou "3 fermetures".
 * La variante $marque permet "créneau"/"créneaux" via pluriel($n, 'x').
 */
function pluriel(int $n, string $marque = 's'): string
{
    return $n > 1 ? $marque : '';
}

/**
 * Libellé lisible du statut référent·e.
 *
 * Note : 'partielle' reste positif ("Encadré") — la couleur orange du chip
 * porte la nuance. Pas question qu'un·e référent·e se sente mal de ne
 * couvrir qu'une partie du créneau : c'est déjà précieux.
 */
function libelleStatut(string $statut): string
{
    return match ($statut) {
        'complete'  => 'Avec référent·e',
        'partielle' => 'Encadré',
        'absente'   => 'Sans référent·e',
        default     => '',
    };
}

/**
 * Style inline pour un chip d'étiquette libre à partir d'un hex.
 * Fond = couleur à ~12 % d'opacité ; texte = couleur pleine.
 */
function chipStyleLabel(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        $hex = '90a4ae';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    // Luminance relative WCAG : plus la couleur est claire, plus le fond
    // doit être opaque pour garder un contraste texte/fond lisible.
    $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
    $alpha = $lum > 0.7 ? 0.35 : ($lum > 0.4 ? 0.22 : 0.16);
    return sprintf('background: rgba(%d,%d,%d,%.2f); color: #%s;', $r, $g, $b, $alpha, strtolower($hex));
}

/** Vrai si au moins une étiquette attachée bloque les inscriptions. */
function jourBloque(array $jour): bool
{
    foreach ($jour['labels'] ?? [] as $l) {
        if ((int)($l['bloque_inscriptions'] ?? 0) === 1) {
            return true;
        }
    }
    return false;
}

/** Vrai si au moins une étiquette ouvre le créneau aux voisin·es. */
function jourOuvreVoisines(array $jour): bool
{
    foreach ($jour['labels'] ?? [] as $l) {
        if ((int)($l['ouvre_voisines'] ?? 0) === 1) {
            return true;
        }
    }
    return false;
}

/** Le jour accepte des inscriptions : pas d'étiquette bloquante attachée. */
function jourAccueilleInscriptions(array $jour): bool
{
    return !jourBloque($jour);
}

/**
 * Moment de la journée selon l'heure de début :
 * matin (avant 12 h), midi (12-14 h), aprem (14-18 h), soir (18 h et +).
 */
function momentJour(string $heureDebut): string
{
    $h = (int)substr($heureDebut, 0, 2);
    return match (true) {
        $h < 12 => 'matin',
        $h < 14 => 'midi',
        $h < 18 => 'aprem',
        default => 'soir',
    };
}

/** Valide et normalise 'H:M' ou 'HH:MM' → 'HH:MM', sinon null. */
function parseHeure(string $s): ?string
{
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($s), $m)) {
        return null;
    }
    return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
}

/** Valide 'YYYY-MM-DD' avec date réelle du calendrier, sinon null. */
function parseDate(string $s): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($s), $m)) {
        return null;
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return null;
    }
    return $s;
}

/**
 * Rend le détail d'un créneau (partiel _detail.php) avec données fraîches.
 * Utilisé pour la réponse HX du GET /jour/{id} et pour les POST htmx
 * qui veulent rafraîchir le drawer in-place sans full reload.
 * Pose $inDrawer = true pour que _detail.php ajoute les attributs hx-*.
 */
function rendreDrawer(PDO $pdo, int $jourId): void
{
    $stmt = $pdo->prepare('SELECT * FROM jours WHERE id = ?');
    $stmt->execute([$jourId]);
    $jour = $stmt->fetch();
    if (!$jour) {
        erreur(404, 'Créneau introuvable.');
        return;
    }
    $stmt = $pdo->prepare('SELECT * FROM referentes WHERE jour_id = ? ORDER BY heure_debut');
    $stmt->execute([$jourId]);
    $jour['referentes'] = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT * FROM inscriptions WHERE jour_id = ? ORDER BY id');
    $stmt->execute([$jourId]);
    $jour['inscriptions'] = $stmt->fetchAll();
    $labelsParJour = LabelRepo::labelsParJour($pdo, [$jourId]);
    $jour['labels'] = $labelsParJour[$jourId] ?? [];
    $tousLabels    = LabelRepo::lister($pdo);
    $prenomMemo    = $_COOKIE['prenom'] ?? '';
    $inDrawer      = true;
    require dirname(__DIR__) . '/views/_detail.php';
}

/**
 * Pose un message flash lu et effacé au prochain rendu de layout.php.
 * Cookie éphémère (60 s de marge pour le round-trip redirect → GET),
 * non-HttpOnly pour permettre à un éventuel JS de nettoyer l'affichage.
 * Types : 'success' (défaut), 'info', 'error'.
 */
function flash(string $message, string $type = 'success'): void
{
    $data = json_encode(['msg' => $message, 'type' => $type], JSON_UNESCAPED_UNICODE);
    setcookie('flash', (string)$data, [
        'expires'  => time() + 60,
        'path'     => '/',
        'secure'   => isHttps(),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/**
 * Lit le cookie flash et le vide immédiatement. À appeler UNE fois dans
 * layout.php. Retourne ['msg' => …, 'type' => …] ou null.
 */
function flashLire(): ?array
{
    if (empty($_COOKIE['flash'])) {
        return null;
    }
    $data = json_decode((string)$_COOKIE['flash'], true);
    setcookie('flash', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => isHttps(),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['flash']);
    if (!is_array($data) || !isset($data['msg'])) {
        return null;
    }
    return [
        'msg'  => (string)$data['msg'],
        'type' => in_array($data['type'] ?? '', ['success', 'info', 'error'], true)
                  ? $data['type'] : 'success',
    ];
}

/**
 * Rend une page d'erreur propre avec layout au lieu de `echo` brut.
 * Pose le code HTTP, rend layout.php avec un contenu minimal et un lien
 * de retour. Appeler `return` après pour stopper le handler.
 */
function erreur(int $code, string $message): void
{
    http_response_code($code);
    $titres = [
        400 => 'Requête invalide',
        404 => 'Page introuvable',
        409 => 'Conflit',
    ];
    $libelle = $titres[$code] ?? ('Erreur ' . $code);
    $titre   = $libelle . ' — ' . setting(SettingsRepo::CLE_ASSO_NOM, ASSO_NOM_DEFAUT);
    $contenu = '<header class="page-entete">'
        . '<h2 class="page-titre">' . e($libelle) . '</h2>'
        . '<p class="page-intro">' . e($message) . '</p>'
        . '</header>'
        . '<p class="retour"><a href="/">← Retour au calendrier</a></p>';
    require dirname(__DIR__) . '/views/layout.php';
}

/**
 * Icône SVG Material (outline). Bibliothèque Material Symbols, Apache 2.0.
 * Usage : <?= icon('close') ?>
 */
/**
 * Bibliothèque de paths SVG Material (outline). Apache 2.0, Google LLC.
 * Partagée entre iconSprite() (défini une fois en tête de layout) et
 * icon() (référence via <use>).
 *
 * @internal
 */
function iconPaths(): array
{
    static $paths = null;
    if ($paths !== null) return $paths;
    $paths = [
        'add'            => '<path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6z"/>',
        'arrow_back'     => '<path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>',
        'arrow_forward'  => '<path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>',
        'check_circle'   => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>',
        'chevron_right'  => '<path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/>',
        'close'          => '<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>',
        'edit'           => '<path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>',
        'error_outline'  => '<path d="M11 15h2v2h-2zm0-8h2v6h-2zm.99-5C6.47 2 2 6.5 2 12s4.47 10 9.99 10C17.52 22 22 17.5 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8z"/>',
        'group'          => '<path d="M12 12.75c1.63 0 3.07.39 4.24.9 1.08.48 1.76 1.56 1.76 2.73V18H6v-1.61c0-1.18.68-2.26 1.76-2.73 1.17-.52 2.61-.91 4.24-.91zM4 13c1.1 0 2-.9 2-2 0-1.1-.9-2-2-2s-2 .9-2 2c0 1.1.9 2 2 2zm1.13 1.1c-.37-.06-.74-.1-1.13-.1-.99 0-1.93.21-2.78.58C.48 14.9 0 15.62 0 16.43V18h4.5v-1.61c0-.83.23-1.61.63-2.29zM20 13c1.1 0 2-.9 2-2 0-1.1-.9-2-2-2s-2 .9-2 2c0 1.1.9 2 2 2zm4 3.43c0-.81-.48-1.53-1.22-1.85-.85-.37-1.79-.58-2.78-.58-.39 0-.76.04-1.13.1.4.68.63 1.46.63 2.29V18H24v-1.57zM12 6c1.66 0 3 1.34 3 3 0 1.66-1.34 3-3 3s-3-1.34-3-3c0-1.66 1.34-3 3-3z"/>',
        'history'        => '<path d="M13 3c-4.97 0-9 4.03-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42C8.27 19.99 10.51 21 13 21c4.97 0 9-4.03 9-9s-4.03-9-9-9zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/>',
        'logout'         => '<path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>',
        'content_copy'   => '<path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>',
        'expand_more'    => '<path d="M16.59 8.59L12 13.17 7.41 8.59 6 10l6 6 6-6z"/>',
        'person_add'     => '<path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>',
        'public'         => '<path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zm6.93 6h-2.95c-.32-1.25-.78-2.45-1.38-3.56 1.84.63 3.37 1.91 4.33 3.56zM12 4.04c.83 1.2 1.48 2.53 1.91 3.96h-3.82c.43-1.43 1.08-2.76 1.91-3.96zM4.26 14C4.1 13.36 4 12.69 4 12s.1-1.36.26-2h3.38c-.08.66-.14 1.32-.14 2 0 .68.06 1.34.14 2H4.26zm.82 2h2.95c.32 1.25.78 2.45 1.38 3.56-1.84-.63-3.37-1.9-4.33-3.56zm2.95-8H5.08c.96-1.66 2.49-2.93 4.33-3.56C8.81 5.55 8.35 6.75 8.03 8zM12 19.96c-.83-1.2-1.48-2.53-1.91-3.96h3.82c-.43 1.43-1.08 2.76-1.91 3.96zM14.34 14H9.66c-.09-.66-.16-1.32-.16-2 0-.68.07-1.35.16-2h4.68c.09.65.16 1.32.16 2 0 .68-.07 1.34-.16 2zm.25 5.56c.6-1.11 1.06-2.31 1.38-3.56h2.95c-.96 1.65-2.49 2.93-4.33 3.56zM16.36 14c.08-.66.14-1.32.14-2 0-.68-.06-1.34-.14-2h3.38c.16.64.26 1.31.26 2s-.1 1.36-.26 2h-3.38z"/>',
        'schedule'       => '<path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8z"/><path d="M12.5 7H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>',
        'settings'       => '<path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>',
    ];
    return $paths;
}

/**
 * Bloc <svg> caché contenant tous les <symbol> d'icônes. À inclure une
 * seule fois en tête de layout.php. icon() émettra ensuite des <svg>
 * légers (~90 bytes) qui référencent ces symbols via <use href="#i-xxx">.
 * Avant : 99 SVG × ~240 bytes = 24 KB par page. Après : 1 sprite ~4 KB
 * + 99 × ~90 bytes = 13 KB. Gain ~11 KB avant gzip, moins après gzip
 * mais surtout moins de nœuds DOM (parse/paint plus rapide).
 */
function iconSprite(): string
{
    $out = '<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden">';
    foreach (iconPaths() as $name => $body) {
        $out .= '<symbol id="i-' . $name . '" viewBox="0 0 24 24">' . $body . '</symbol>';
    }
    return $out . '</svg>';
}

/**
 * Icône SVG Material (outline). Bibliothèque Material Symbols, Apache 2.0.
 * Référence le sprite inséré par iconSprite() en tête de layout.php.
 */
function icon(string $name, int $size = 20): string
{
    return sprintf(
        '<svg class="icon" width="%d" height="%d" aria-hidden="true" focusable="false"><use href="#i-%s"/></svg>',
        $size, $size, $name
    );
}
