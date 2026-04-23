<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Surveillance — détection de modifications suspectes sur les réglages.
//
// But : ne PAS notifier sur toutes les modifs (sinon spam Discord), ne notifier
// que quand une combinaison de signaux dépasse un seuil de score.
//
// Toutes les règles sont regroupées dans evaluer() ci-dessous : c'est le seul
// endroit à ouvrir pour ajouter, retirer ou ajuster un signal. Chaque règle
// ajoute un score et un motif humain. Au-dessus du seuil, on poste sur Discord.
//
// État persistant dans DATA_DIR/surveillance-state.json :
//   - ips_connues : 200 dernières IPs vues (pour signaler une IP nouvelle)
//   - events      : événements de la dernière heure (pour détecter une rafale)

final class Surveillance
{
    private const SEUIL_URGENT = 5;
    private const SEUIL_WARN   = 2;
    private const TTL_EVENTS_SECONDS    = 3600;
    private const RAFALE_WINDOW_SECONDS = 300;
    private const RAFALE_SEUIL          = 5;

    /**
     * Point d'entrée appelé par chaque route admin après une modif.
     * $ctx contient ce que la route veut transmettre aux règles.
     */
    public static function surveiller(string $action, array $ctx = []): void
    {
        $state = self::chargerEtat();
        $now   = time();
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '?';

        $state['events'] = array_values(array_filter(
            $state['events'],
            static fn(array $e) => $e['ts'] > $now - self::TTL_EVENTS_SECONDS
        ));

        $ipNouvelle = !in_array($ip, $state['ips_connues'], true);

        [$score, $motifs] = self::evaluer($action, $ctx, $state, $ipNouvelle);

        $state['events'][] = ['ts' => $now, 'action' => $action, 'ip' => $ip];
        if ($ipNouvelle && $ip !== '?') {
            $state['ips_connues'][] = $ip;
            if (count($state['ips_connues']) > 200) {
                $state['ips_connues'] = array_slice($state['ips_connues'], -200);
            }
        }
        self::sauverEtat($state);

        if ($score < self::SEUIL_WARN) {
            return;
        }
        $niveau = $score >= self::SEUIL_URGENT ? 'URGENT' : 'WARN';
        self::notifier($niveau, $action, $motifs, $ctx, $ip, $score);
    }

    /**
     * Applique toutes les règles et retourne [score_total, motifs[]].
     * LE seul endroit à ouvrir pour ajuster la détection.
     *
     * @return array{0:int, 1:string[]}
     */
    private static function evaluer(
        string $action,
        array $ctx,
        array $state,
        bool $ipNouvelle,
    ): array {
        $score  = 0;
        $motifs = [];

        $h = (int)(new DateTimeImmutable('now'))->format('G');
        if ($h >= 1 && $h < 6) {
            $score += 2;
            $motifs[] = "Modif nocturne ({$h}h)";
        }

        if ($action === 'settings.update' && !empty($ctx['changement'])) {
            $score += 3;
            $motifs[] = "Identité de l'asso modifiée";
        }

        if ($action === 'settings.bandeau' && isset($ctx['html'])) {
            $html = (string)$ctx['html'];
            if (preg_match('#https?://#i', $html)) {
                $score += 2;
                $motifs[] = 'Bandeau contient un lien externe';
            }
            if (mb_strlen($html) > 5000) {
                $score += 1;
                $motifs[] = 'Bandeau très long (' . mb_strlen($html) . ' car.)';
            }
        }

        if (str_ends_with($action, '.supprimer')) {
            $score += 1;
            $motifs[] = 'Suppression';
        }

        $limite = time() - self::RAFALE_WINDOW_SECONDS;
        $recents = array_filter(
            $state['events'],
            static fn(array $e) => $e['ts'] > $limite
        );
        $nbRecents = count($recents) + 1;
        if ($nbRecents >= self::RAFALE_SEUIL) {
            $score += 3;
            $motifs[] = "Rafale : {$nbRecents} actions admin en "
                . (int)(self::RAFALE_WINDOW_SECONDS / 60) . ' min';
        }

        if ($ipNouvelle) {
            $score += 1;
            $motifs[] = 'IP jamais vue auparavant';
        }

        return [$score, $motifs];
    }

    /** Diff lisible entre deux tableaux associatifs (exposé pour $ctx['diff']). */
    public static function diff(array $avant, array $apres): string
    {
        $lignes = [];
        foreach ($apres as $k => $v) {
            $old = $avant[$k] ?? null;
            if ((string)$old !== (string)$v) {
                $lignes[] = $k . ' : ' . self::fmt($old) . ' → ' . self::fmt($v);
            }
        }
        return $lignes === [] ? '(aucun changement visible)' : implode("\n", $lignes);
    }

    private static function fmt(mixed $v): string
    {
        if ($v === null || $v === '') return '∅';
        if (is_array($v))              return '[' . implode(',', $v) . ']';
        $s = (string)$v;
        if (mb_strlen($s) > 80) {
            $s = mb_substr($s, 0, 80) . '…';
        }
        return '« ' . $s . ' »';
    }

    private static function notifier(
        string $niveau,
        string $action,
        array $motifs,
        array $ctx,
        string $ip,
        int $score,
    ): void {
        if (!defined('DISCORD_WEBHOOK_URL') || DISCORD_WEBHOOK_URL === '') {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $icone   = $niveau === 'URGENT' ? '🔴' : '🟡';
        $prefixe = $niveau === 'URGENT' ? '@here ' : '';
        $detail  = self::extraireDetail($ctx);
        $ua      = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120);
        $quand   = (new DateTimeImmutable('now'))->format('d/m H:i');

        $content = "{$prefixe}{$icone} **[{$niveau}] {$action}** (score {$score})\n"
            . '_Motifs_ : ' . implode(' · ', $motifs) . "\n"
            . ($detail === '' ? '' : "```\n{$detail}\n```\n")
            . "`{$ip}` · `{$ua}` · {$quand}";

        if (mb_strlen($content) > 1900) {
            $content = mb_substr($content, 0, 1900) . '…';
        }
        $payload = json_encode(
            ['content' => $content],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $ch = curl_init(DISCORD_WEBHOOK_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 3,
        ]);
        @curl_exec($ch);
    }

    private static function extraireDetail(array $ctx): string
    {
        if (!empty($ctx['diff']) && is_string($ctx['diff'])) {
            return (string)$ctx['diff'];
        }
        if (!empty($ctx['resume']) && is_string($ctx['resume'])) {
            return (string)$ctx['resume'];
        }
        return '';
    }

    private static function chargerEtat(): array
    {
        $path = self::statePath();
        if (!is_file($path)) {
            return ['events' => [], 'ips_connues' => []];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return ['events' => [], 'ips_connues' => []];
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return ['events' => [], 'ips_connues' => []];
        }
        return $d + ['events' => [], 'ips_connues' => []];
    }

    private static function sauverEtat(array $state): void
    {
        $path = self::statePath();
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log('Surveillance: impossible de créer ' . $dir);
            return;
        }
        $ok = @file_put_contents(
            $path,
            json_encode($state, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        if ($ok === false) {
            error_log('Surveillance: écriture échouée sur ' . $path);
        }
    }

    private static function statePath(): string
    {
        return DATA_DIR . '/surveillance-state.json';
    }
}
