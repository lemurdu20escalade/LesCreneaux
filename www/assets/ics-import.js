(function () {
    'use strict';

    const input   = document.getElementById('input-ics');
    const preview = document.getElementById('ics-preview');
    const liste   = document.getElementById('ics-liste');
    const resume  = document.getElementById('ics-resume');
    if (!input || !preview || !liste || !resume) return;

    // 'YYYY-MM-DD' du jour en heure locale — comparable directement aux dates
    // produites par le parser. Sert à décocher par défaut les jours passés.
    function aujourdhui() {
        const d = new Date();
        const p = function (n) { return String(n).padStart(2, '0'); };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }
    const TODAY = aujourdhui();

    /**
     * Parse minimal d'un .ics :
     * - déplie les lignes continuation (RFC 5545 : CRLF + SPACE/TAB)
     * - extrait chaque VEVENT → {dtstart, summary}
     * - normalise en {date: 'YYYY-MM-DD', note}, trie par date.
     */
    function parse(ics) {
        const unfolded = ics.replace(/\r?\n[ \t]/g, '');
        const blocks = [];
        let current = null;
        for (const raw of unfolded.split(/\r?\n/)) {
            if (raw === 'BEGIN:VEVENT') current = {};
            else if (raw === 'END:VEVENT') {
                if (current) blocks.push(current);
                current = null;
            } else if (current) {
                const idx = raw.indexOf(':');
                if (idx < 0) continue;
                const key = raw.slice(0, idx).split(';')[0];
                const val = raw.slice(idx + 1);
                if (key === 'DTSTART') current.dtstart = val;
                else if (key === 'SUMMARY') current.summary = val;
            }
        }
        return blocks
            .map(function (b) {
                const m = (b.dtstart || '').match(/^(\d{4})(\d{2})(\d{2})/);
                if (!m) return null;
                const note = (b.summary || '')
                    .replace(/\\([,;n])/g, function (_, c) {
                        return c === 'n' ? '\n' : c;
                    })
                    .trim();
                return { date: m[1] + '-' + m[2] + '-' + m[3], note: note };
            })
            .filter(Boolean)
            .sort(function (a, b) { return a.date.localeCompare(b.date); });
    }

    function creerLigne(ev) {
        const passee = ev.date < TODAY;
        const li = document.createElement('li');
        li.className = 'fermeture-ligne' + (passee ? ' fermeture-passee' : '');

        const label = document.createElement('label');
        label.className = 'check';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = !passee;
        const dateSpan = document.createElement('span');
        dateSpan.className = 'fermeture-date';
        const d = new Date(ev.date + 'T00:00:00');
        dateSpan.textContent = d.toLocaleDateString('fr-FR', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
        label.appendChild(cb);
        label.appendChild(document.createTextNode(' '));
        label.appendChild(dateSpan);

        const noteSpan = document.createElement('span');
        noteSpan.className = 'fermeture-note';
        noteSpan.textContent = ev.note;

        const hidDate = document.createElement('input');
        hidDate.type = 'hidden';
        hidDate.name = 'dates[]';
        hidDate.value = ev.date;

        const hidNote = document.createElement('input');
        hidNote.type = 'hidden';
        hidNote.name = 'notes[]';
        hidNote.value = ev.note;

        const sync = function () {
            hidDate.disabled = !cb.checked;
            hidNote.disabled = !cb.checked;
        };
        sync();
        cb.addEventListener('change', sync);

        li.appendChild(label);
        li.appendChild(noteSpan);
        li.appendChild(hidDate);
        li.appendChild(hidNote);
        return li;
    }

    function afficher(events) {
        while (liste.firstChild) liste.removeChild(liste.firstChild);
        if (events.length === 0) {
            resume.textContent = 'Aucun événement trouvé dans ce fichier.';
            preview.hidden = false;
            return;
        }
        const n = events.length;
        const passees = events.filter(function (ev) { return ev.date < TODAY; }).length;
        let msg = n + ' date' + (n > 1 ? 's' : '') + ' extraite' + (n > 1 ? 's' : '') + ' du fichier.';
        if (passees > 0) {
            msg += ' ' + passees + ' date' + (passees > 1 ? 's' : '')
                 + ' passée' + (passees > 1 ? 's' : '')
                 + ' décochée' + (passees > 1 ? 's' : '') + ' par défaut.';
        }
        resume.textContent = msg;
        for (const ev of events) liste.appendChild(creerLigne(ev));
        preview.hidden = false;
    }

    input.addEventListener('change', function () {
        const file = input.files && input.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function (e) {
            afficher(parse(String(e.target.result)));
        };
        reader.readAsText(file);
    });

    document.getElementById('ics-tout-cocher').addEventListener('click', function () {
        liste.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
            cb.checked = true;
            cb.dispatchEvent(new Event('change'));
        });
    });
    document.getElementById('ics-tout-decocher').addEventListener('click', function () {
        liste.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
            cb.checked = false;
            cb.dispatchEvent(new Event('change'));
        });
    });
})();
