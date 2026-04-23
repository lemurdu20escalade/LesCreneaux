<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Nettoyage strict du HTML saisi via le WYSIWYG du bandeau.
// Autorise uniquement la mise en forme basique + liens http/https/mailto.
// Les attributs autres que href sur <a> sont supprimés.

final class HtmlSanitizer
{
    private const TAGS_AUTORISES = [
        'p', 'br', 'strong', 'em', 'b', 'i', 'u',
        'h3', 'h4', 'ul', 'ol', 'li', 'a',
    ];

    private const ATTR_PAR_TAG = [
        'a' => ['href'],
    ];

    /** Tags dont le contenu entier est supprimé (pas seulement déballé). */
    private const TAGS_DANGEREUX = [
        'script', 'style', 'iframe', 'object', 'embed',
        'noscript', 'svg', 'math', 'form', 'input', 'button',
    ];

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $dom->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return '';
        }

        self::nettoyer($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    private static function nettoyer(DOMNode $parent): void
    {
        $enfants = iterator_to_array($parent->childNodes);
        foreach ($enfants as $node) {
            if ($node instanceof DOMComment) {
                $parent->removeChild($node);
                continue;
            }
            if (!($node instanceof DOMElement)) {
                continue;
            }
            $tag = strtolower($node->nodeName);
            if (in_array($tag, self::TAGS_DANGEREUX, true)) {
                // Tag actif : on retire node ET contenu, aucune fuite de texte.
                $parent->removeChild($node);
                continue;
            }
            if (!in_array($tag, self::TAGS_AUTORISES, true)) {
                // Tag neutre non listé : on garde le texte, on retire le tag.
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            $autorises = self::ATTR_PAR_TAG[$tag] ?? [];
            $attrs = iterator_to_array($node->attributes);
            foreach ($attrs as $attr) {
                if (!in_array(strtolower($attr->nodeName), $autorises, true)) {
                    $node->removeAttributeNode($attr);
                }
            }

            if ($tag === 'a') {
                $href = $node->getAttribute('href');
                if ($href === '' || !preg_match('#^(https?://|mailto:)#i', $href)) {
                    $node->removeAttribute('href');
                }
                if ($node->hasAttribute('href')) {
                    $node->setAttribute('rel', 'noopener noreferrer nofollow');
                    $node->setAttribute('target', '_blank');
                }
            }

            self::nettoyer($node);
        }
    }
}
