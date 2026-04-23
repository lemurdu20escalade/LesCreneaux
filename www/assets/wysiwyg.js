/*
 * Éditeur de bandeau — contenteditable + execCommand, avec bascule
 * « Code source » vers un <textarea>.
 *
 * Pourquoi ce design :
 *   - Mode rich (défaut) : expérience familière (sélectionner, cliquer
 *     « gras »), undo/redo natif, toutes les subtilités de curseur
 *     gérées par le navigateur. execCommand est « déprécié » depuis
 *     2014 mais universellement supporté (Gmail, Notion, WordPress
 *     l'utilisent encore) et aucun vendor n'a annoncé sa suppression.
 *   - Mode source : textarea avec insertion HTML autour de la
 *     sélection. Escape hatch pour corriger à la main quand
 *     execCommand produit une bizarrerie, ou simplement pour auditer
 *     le HTML qui va partir en base.
 *   - Dans les deux cas, le sanitizer serveur (HtmlSanitizer::sanitize)
 *     nettoie à la sauvegarde : même si le DOM est sale, la base
 *     reçoit du HTML propre.
 *
 * Contrat avec la vue : l'éditeur rich est #bandeau-editor
 * (contenteditable). Le textarea source est #bandeau-source (name="html",
 * c'est lui que le formulaire envoie). Le toggle est #bandeau-mode-toggle.
 * La toolbar a des boutons [data-cmd] (+ [data-arg] optionnel).
 */
(function () {
    'use strict';

    const editor   = document.getElementById('bandeau-editor');
    const source   = document.getElementById('bandeau-source');
    const toolbar  = document.querySelector('.wysiwyg-toolbar');
    const toggle   = document.getElementById('bandeau-mode-toggle');
    const form     = editor && editor.closest('form');
    if (!editor || !source || !toolbar || !toggle || !form) return;

    // Le <textarea name="html"> est la source de vérité pour le formulaire.
    // En mode rich on le tient synchronisé depuis editor.innerHTML avant
    // chaque submit (et à chaque bascule de mode).

    function estModeSource() {
        return !source.hidden;
    }

    function syncRichVersSource() {
        source.value = editor.innerHTML;
    }

    function syncSourceVersRich() {
        editor.innerHTML = source.value;
    }

    // ─── Mode rich : execCommand ──────────────────────────────────────

    function execRich(cmd, arg) {
        editor.focus();
        if (cmd === 'createLink') {
            // Refuse une création de lien sans sélection : sinon
            // execCommand crée un lien vide au curseur, peu utile.
            const sel = window.getSelection();
            if (!sel || sel.isCollapsed) {
                window.alert('Sélectionne d’abord le texte à transformer en lien.');
                return;
            }
            const url = window.prompt('URL du lien (http, https ou mailto) :', 'https://');
            if (!url) return;
            if (!/^(https?:|mailto:)/i.test(url)) {
                window.alert('Seuls les liens http, https ou mailto sont acceptés.');
                return;
            }
            arg = url;
        }
        document.execCommand(cmd, false, arg || null);
    }

    // ─── Mode source : insertion HTML autour de la sélection ──────────

    function wrapSource(before, after) {
        const start = source.selectionStart;
        const end   = source.selectionEnd;
        const v = source.value;
        source.value = v.substring(0, start) + before + v.substring(start, end) + after + v.substring(end);
        source.selectionStart = start + before.length;
        source.selectionEnd   = end   + before.length;
        source.focus();
    }

    function wrapListSource(listTag) {
        const start = source.selectionStart;
        const end   = source.selectionEnd;
        const v = source.value;
        let bloc;
        if (start === end) {
            bloc = '<' + listTag + '>\n  <li></li>\n</' + listTag + '>';
        } else {
            const items = v.substring(start, end).split('\n')
                .map(function (l) { return l.trim(); })
                .filter(function (l) { return l !== ''; })
                .map(function (l) { return '  <li>' + l + '</li>'; })
                .join('\n');
            bloc = '<' + listTag + '>\n' + items + '\n</' + listTag + '>';
        }
        source.value = v.substring(0, start) + bloc + v.substring(end);
        source.selectionStart = start;
        source.selectionEnd   = start + bloc.length;
        source.focus();
    }

    function makeLinkSource() {
        if (source.selectionStart === source.selectionEnd) {
            window.alert('Sélectionne d’abord le texte à transformer en lien.');
            return;
        }
        const url = window.prompt('URL du lien (http, https ou mailto) :', 'https://');
        if (!url) return;
        if (!/^(https?:|mailto:)/i.test(url)) {
            window.alert('Seuls les liens http, https ou mailto sont acceptés.');
            return;
        }
        wrapSource('<a href="' + url.replace(/"/g, '&quot;') + '">', '</a>');
    }

    function stripTagsSource() {
        const start = source.selectionStart;
        const end   = source.selectionEnd;
        const v = source.value;
        const zs = (start === end) ? 0 : start;
        const ze = (start === end) ? v.length : end;
        const propre = v.substring(zs, ze).replace(/<[^>]*>/g, '');
        source.value = v.substring(0, zs) + propre + v.substring(ze);
        source.selectionStart = zs;
        source.selectionEnd   = zs + propre.length;
        source.focus();
    }

    // Les commandes execCommand sont mappées en mode source sur leurs
    // équivalents textuels, pour que le même bouton fasse la même
    // intention dans les deux modes.
    function execSource(cmd, arg) {
        switch (cmd) {
            case 'bold':                return wrapSource('<strong>', '</strong>');
            case 'italic':              return wrapSource('<em>', '</em>');
            case 'formatBlock':         return wrapSource('<' + (arg || 'h3') + '>', '</' + (arg || 'h3') + '>');
            case 'insertUnorderedList': return wrapListSource('ul');
            case 'insertOrderedList':   return wrapListSource('ol');
            case 'createLink':          return makeLinkSource();
            case 'removeFormat':        return stripTagsSource();
        }
    }

    // ─── Toolbar + raccourcis ─────────────────────────────────────────

    // mousedown + preventDefault : garde la sélection intacte pendant
    // le clic (sinon focus transféré au bouton → sélection perdue).
    // Nécessaire en mode source. Inoffensif en mode rich (le
    // contenteditable sauve sa selection seul, mais on préserve par
    // cohérence).
    toolbar.addEventListener('mousedown', function (e) {
        if (e.target.closest('button')) e.preventDefault();
    });

    toolbar.addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-cmd]');
        if (!btn) return;
        e.preventDefault();
        const cmd = btn.dataset.cmd;
        const arg = btn.dataset.arg || null;
        if (estModeSource()) execSource(cmd, arg);
        else                 execRich(cmd, arg);
    });

    editor.addEventListener('keydown', function (e) {
        if (!(e.ctrlKey || e.metaKey) || e.shiftKey || e.altKey) return;
        const k = e.key.toLowerCase();
        if (k === 'b') { e.preventDefault(); execRich('bold'); }
        else if (k === 'i') { e.preventDefault(); execRich('italic'); }
    });
    source.addEventListener('keydown', function (e) {
        if (!(e.ctrlKey || e.metaKey) || e.shiftKey || e.altKey) return;
        const k = e.key.toLowerCase();
        if (k === 'b') { e.preventDefault(); wrapSource('<strong>', '</strong>'); }
        else if (k === 'i') { e.preventDefault(); wrapSource('<em>', '</em>'); }
    });

    // ─── Bascule rich ↔ source ────────────────────────────────────────

    toggle.addEventListener('click', function () {
        if (estModeSource()) {
            // source → rich : le HTML saisi/corrigé à la main remplace
            // le DOM de l'éditeur.
            syncSourceVersRich();
            source.hidden = true;
            editor.hidden = false;
            toggle.setAttribute('aria-pressed', 'false');
            toggle.classList.remove('is-active');
            editor.focus();
        } else {
            // rich → source : on sérialise l'innerHTML dans le textarea.
            syncRichVersSource();
            editor.hidden = true;
            source.hidden = false;
            toggle.setAttribute('aria-pressed', 'true');
            toggle.classList.add('is-active');
            source.focus();
        }
    });

    // ─── Submit : assure la sync finale ───────────────────────────────
    // Si on est en mode rich au moment du submit, le textarea peut être
    // dépassé (il ne s'actualise qu'à la bascule). On le sync ici.
    form.addEventListener('submit', function () {
        if (!estModeSource()) syncRichVersSource();
    });
})();
