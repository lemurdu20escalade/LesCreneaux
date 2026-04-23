(function () {
    'use strict';

    const form     = document.getElementById('form-multi');
    const dateI    = document.getElementById('multi-date');
    const noteI    = document.getElementById('multi-note');
    const btnAjout = document.getElementById('multi-ajouter');
    const liste    = document.getElementById('multi-liste');
    const actions  = document.getElementById('multi-actions');
    const btnVider = document.getElementById('multi-vider');
    const btnLabel = document.getElementById('multi-btn-label');
    const tpl      = document.getElementById('tpl-fermeture-ligne');
    if (!form || !dateI || !noteI || !btnAjout || !liste || !actions || !tpl) return;

    function formatDateFr(iso) {
        const d = new Date(iso + 'T00:00:00');
        return d.toLocaleDateString('fr-FR', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
    }

    function majEtat() {
        const lignes = liste.querySelectorAll('li');
        const n = lignes.length;
        liste.hidden = n === 0;
        actions.hidden = n === 0;
        if (btnLabel) {
            btnLabel.textContent = n <= 1
                ? 'Déclarer la fermeture'
                : 'Déclarer les ' + n + ' fermetures';
        }
    }

    function dejaPresent(iso) {
        return Array.from(liste.querySelectorAll('input[name="dates[]"]'))
            .some(function (i) { return i.value === iso; });
    }

    function insererTriee(li, iso) {
        for (const existante of liste.querySelectorAll('li')) {
            const d = existante.querySelector('input[name="dates[]"]').value;
            if (iso < d) {
                liste.insertBefore(li, existante);
                return;
            }
        }
        liste.appendChild(li);
    }

    function ajouter() {
        const iso  = dateI.value;
        const note = noteI.value.trim();
        if (!iso) {
            dateI.focus();
            return;
        }
        if (dejaPresent(iso)) {
            // Flash discret sur le champ date pour signaler le doublon.
            dateI.classList.add('input-flash');
            setTimeout(function () { dateI.classList.remove('input-flash'); }, 600);
            return;
        }

        const li = tpl.content.firstElementChild.cloneNode(true);
        li.querySelector('.fermeture-date').textContent = formatDateFr(iso);
        li.querySelector('.fermeture-note').textContent = note;
        li.querySelector('input[name="dates[]"]').value = iso;
        li.querySelector('input[name="notes[]"]').value = note;
        li.querySelector('button').addEventListener('click', function () {
            li.remove();
            majEtat();
        });

        insererTriee(li, iso);
        dateI.value = '';
        noteI.value = '';
        dateI.focus();
        majEtat();
    }

    btnAjout.addEventListener('click', ajouter);

    btnVider.addEventListener('click', function () {
        while (liste.firstChild) liste.removeChild(liste.firstChild);
        majEtat();
    });

    // Entrée dans les champs = ajouter (plus rapide au clavier).
    [dateI, noteI].forEach(function (el) {
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                ajouter();
            }
        });
    });

    // Garde-fou : on ne soumet pas un formulaire vide (sinon le serveur
    // redirige vers #fermetures sans rien créer, expérience confuse).
    form.addEventListener('submit', function (e) {
        if (liste.querySelectorAll('li').length === 0) {
            e.preventDefault();
            dateI.focus();
        }
    });
})();
