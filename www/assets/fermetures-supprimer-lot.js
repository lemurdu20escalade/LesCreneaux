(function () {
    'use strict';

    const form = document.getElementById('form-supprimer-lot');
    const bulk = document.querySelector('.fermetures-bulk');
    if (!form || !bulk) return;

    const compteur = document.getElementById('barre-compteur-n');
    const pluriel  = document.getElementById('barre-compteur-s');
    const decocher = document.getElementById('barre-decocher');
    const master   = document.getElementById('master-check');
    const masterTxt = document.getElementById('master-check-texte');
    if (!compteur || !pluriel || !decocher || !master || !masterTxt) return;

    const cases = Array.from(bulk.querySelectorAll('input[name="ids[]"]'));
    let derniereCliquee = null;

    function cochees() {
        return cases.filter(function (c) { return c.checked; });
    }

    function maj() {
        const n = cochees().length;
        compteur.textContent = String(n);
        pluriel.hidden = n <= 1;
        if (n === 0) {
            master.checked = false;
            master.indeterminate = false;
            masterTxt.textContent = 'Tout sélectionner';
        } else if (n === cases.length) {
            master.checked = true;
            master.indeterminate = false;
            masterTxt.textContent = 'Tout désélectionner';
        } else {
            master.checked = false;
            master.indeterminate = true;
            masterTxt.textContent = 'Tout sélectionner';
        }
    }

    // Shift+clic : coche/décoche toutes les cases entre la dernière cliquée
    // sans shift et la case cliquée (état = celui de la case cliquée après le clic).
    bulk.addEventListener('click', function (e) {
        const cb = e.target;
        if (!(cb instanceof HTMLInputElement) || cb.name !== 'ids[]') return;
        if (e.shiftKey && derniereCliquee && derniereCliquee !== cb) {
            const i1 = cases.indexOf(derniereCliquee);
            const i2 = cases.indexOf(cb);
            if (i1 >= 0 && i2 >= 0) {
                const [from, to] = i1 < i2 ? [i1, i2] : [i2, i1];
                const etat = cb.checked;
                for (let i = from; i <= to; i++) cases[i].checked = etat;
            }
        }
        derniereCliquee = cb;
    });

    bulk.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'ids[]') maj();
    });

    master.addEventListener('change', function () {
        const etat = master.checked;
        cases.forEach(function (c) { c.checked = etat; });
        maj();
    });

    decocher.addEventListener('click', function () {
        cases.forEach(function (c) { c.checked = false; });
        maj();
    });

    form.addEventListener('submit', function (e) {
        const n = cochees().length;
        if (n === 0) { e.preventDefault(); return; }
        const msg = n === 1
            ? 'Supprimer 1 fermeture ?'
            : 'Supprimer ' + n + ' fermetures ?';
        if (!window.confirm(msg)) e.preventDefault();
    });

    maj();
})();
