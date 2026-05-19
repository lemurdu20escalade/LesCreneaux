# Tests d'intégration

Harnais standalone — pas de Composer, pas de PHPUnit. PHP 8.2+ requis.

## Lancer les tests

Depuis la racine du projet :

```bash
php tests/integration.php
```

Le script démarre un serveur builtin PHP sur un port aléatoire, crée
une base SQLite dans un dossier temp (jamais dans `data/`), exécute les
scénarios et imprime `N PASS / M FAIL`. Exit code 0 si aucun échec.
