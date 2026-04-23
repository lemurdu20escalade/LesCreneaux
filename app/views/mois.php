<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
$debutMois = new DateTimeImmutable($mois . '-01');
$prev      = $debutMois->modify('-1 month')->format('Y-m');
$next      = $debutMois->modify('+1 month')->format('Y-m');
$moisPrev  = DateFr::moisNom((int)$debutMois->modify('-1 month')->format('n'));
$moisCour  = DateFr::moisNom((int)$debutMois->format('n'));
$moisNext  = DateFr::moisNom((int)$debutMois->modify('+1 month')->format('n'));
?>
<?php if (!empty($bandeauHtml)): ?>
    <details class="bandeau" id="bandeau" open>
        <summary class="bandeau-summary">
            <?= icon('error_outline', 18) ?>
            <span class="bandeau-titre">Infos importantes</span>
            <span class="bandeau-chevron" aria-hidden="true"><?= icon('expand_more', 20) ?></span>
        </summary>
        <div class="bandeau-corps"><?= $bandeauHtml /* déjà sanitizé au save */ ?></div>
    </details>
    <script>
    // Persistance de l'état ouvert/fermé via localStorage.
    // Absent ou '1' = ouvert (défaut HTML), '0' = fermé.
    (function () {
        const CLE = 'lemur-bandeau-ouvert';
        const el  = document.getElementById('bandeau');
        if (!el) return;
        try {
            if (localStorage.getItem(CLE) === '0') el.removeAttribute('open');
        } catch (e) {}
        el.addEventListener('toggle', () => {
            try { localStorage.setItem(CLE, el.open ? '1' : '0'); } catch (e) {}
        });
    })();
    </script>
<?php endif; ?>

<nav class="nav-mois" aria-label="Navigation entre mois">
    <a href="/mois/<?= e($prev) ?>" class="nav-btn"
       aria-label="Mois précédent : <?= e(ucfirst($moisPrev)) ?>">
        <?= icon('arrow_back', 18) ?><span><?= e(ucfirst($moisPrev)) ?></span>
    </a>
    <h2 class="titre-mois"><?= e(ucfirst($moisCour)) ?> <?= e($debutMois->format('Y')) ?></h2>
    <a href="/mois/<?= e($next) ?>" class="nav-btn"
       aria-label="Mois suivant : <?= e(ucfirst($moisNext)) ?>">
        <span><?= e(ucfirst($moisNext)) ?></span><?= icon('arrow_forward', 18) ?>
    </a>
</nav>

<?php if (empty($jours) && empty($fermetures)): ?>
    <div class="etat-vide">
        <p>Aucun créneau ce mois-ci.</p>
        <p><a class="btn-text" href="/reglages">Ouvrir les réglages</a> pour ajouter des créneaux récurrents.</p>
    </div>
<?php else: ?>
    <section id="liste-jours"
             hx-get="/mois/<?= e($mois) ?>"
             hx-trigger="every 60s"
             hx-select="#liste-jours"
             hx-swap="outerHTML">
        <?php
        // Fusionne créneaux et fermetures en un flux chronologique unique :
        // même clé de tri (date + heure) pour que les fermetures glissent
        // à leur place. Les fermetures ont une heure virtuelle "00:00"
        // pour s'afficher en tête de la journée concernée.
        //
        // Règle de priorité : une fermeture écrase tout créneau partageant
        // la même date (évite les doublons si un créneau a été généré avant
        // que la fermeture soit déclarée). Le write-path nettoie aussi,
        // ce filtre est une ceinture-bretelles d'affichage.
        $datesFermees = [];
        foreach ($fermetures as $f) {
            $datesFermees[$f['date']] = true;
        }
        $items = [];
        foreach ($jours as $j) {
            if (isset($datesFermees[$j['date']])) {
                continue;
            }
            $items[] = [
                'type' => 'jour',
                'sort' => $j['date'] . ' ' . $j['heure_debut'],
                'data' => $j,
            ];
        }
        foreach ($fermetures as $f) {
            $items[] = [
                'type' => 'fermeture',
                'sort' => $f['date'] . ' 00:00',
                'data' => $f,
            ];
        }
        usort($items, static fn(array $a, array $b): int => strcmp($a['sort'], $b['sort']));

        $today   = new DateTimeImmutable('today');
        $futurs  = [];
        $passes  = [];
        foreach ($items as $it) {
            $d = new DateTimeImmutable($it['data']['date']);
            if ($d >= $today) {
                $futurs[] = $it;
            } else {
                $passes[] = $it;
            }
        }
        $nbPassesCreneaux = count(array_filter($passes, static fn($it): bool => $it['type'] === 'jour'));

        $rendre = static function (array $it): void {
            if ($it['type'] === 'jour') {
                $jour = $it['data'];
                require __DIR__ . '/_ligne.php';
            } else {
                $fermeture = $it['data'];
                require __DIR__ . '/_fermeture.php';
            }
        };
        ?>
        <ol class="liste-creneaux">
            <?php foreach ($futurs as $it) { $rendre($it); } ?>
        </ol>
        <?php if (!empty($passes)): ?>
            <details class="historique">
                <summary class="historique-summary">
                    <span class="historique-inner">
                        <span class="historique-label">
                            <?= icon('history', 18) ?>
                            <span>Historique</span>
                            <span class="historique-count"><?= $nbPassesCreneaux ?> créneau<?= $nbPassesCreneaux > 1 ? 'x' : '' ?> passé<?= $nbPassesCreneaux > 1 ? 's' : '' ?></span>
                        </span>
                        <span class="historique-chevron" aria-hidden="true"><?= icon('expand_more', 20) ?></span>
                    </span>
                </summary>
                <ol class="liste-creneaux liste-historique">
                    <?php foreach ($passes as $it) { $rendre($it); } ?>
                </ol>
            </details>
        <?php endif; ?>
    </section>
<?php endif; ?>
