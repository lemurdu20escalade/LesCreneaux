(function () {
    'use strict';

    const form = document.querySelector('#plage form');
    if (!form) return;

    function liste(csv) {
        return (csv || '').split(',').filter(Boolean);
    }

    function reprendre(li) {
        const jours   = liste(li.dataset.jours);
        const ajouter = liste(li.dataset.ajouter);
        const retirer = liste(li.dataset.retirer);

        const debut = form.querySelector('input[name="debut"]');
        const fin   = form.querySelector('input[name="fin"]');
        if (debut) debut.value = li.dataset.debut || '';
        if (fin)   fin.value   = li.dataset.fin || '';

        form.querySelectorAll('input[name="jours_semaine[]"]').forEach(function (cb) {
            cb.checked = jours.indexOf(cb.value) !== -1;
        });

        form.querySelectorAll('input[type="radio"]').forEach(function (radio) {
            const m = radio.name.match(/^action\[(\d+)\]$/);
            if (!m) return;
            const id = m[1];
            let cible = 'rien';
            if (ajouter.indexOf(id) !== -1) cible = 'ajouter';
            else if (retirer.indexOf(id) !== -1) cible = 'retirer';
            radio.checked = (radio.value === cible);
        });

        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    document.querySelectorAll('#plage .plage-op-reprendre').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const li = btn.closest('.plage-op');
            if (li) reprendre(li);
        });
    });
})();
