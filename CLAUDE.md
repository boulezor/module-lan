# CLAUDE.md — Espace applis (https://tbxm.be)

Petites applis web perso (amis, famille, LAN…), en **français**, **une appli par dossier** à la racine.
La branche `main` est **la production** : chaque push sur `main` est déployé automatiquement sur le
serveur Plesk (OVH) via le webhook Git de Plesk, dans `/httpdocs` de **tbxm.be**.
L'appli `xxx/` est en ligne sur `https://tbxm.be/xxx/`.
Le dépôt est **public** : rien de confidentiel dans le code.

## Règles de travail

- Travailler sur une branche, puis ouvrir une Pull Request vers `main` : le propriétaire valide (le merge = mise en ligne).
  Ne pousser directement sur `main` que s'il le demande explicitement.
- Ne jamais modifier le dossier d'une autre appli sans qu'on le demande (sauf `index.php` / `README.md` racine).
- Tester avant de pousser : `php -l` sur chaque fichier PHP modifié, et un essai réel avec `php -S` + curl
  (ou Playwright pour le visuel).
- Ne jamais committer de secret : mots de passe, clés API, bases de données.

## Stack imposée (pour que tout tourne sur l'hébergement sans rien installer)

- **PHP 8.2+ sans framework ni Composer**, HTML/CSS/JS vanilla, **aucune étape de build**.
- Données : **SQLite** via PDO (`pdo_sqlite`). Pas de MySQL (aucune base à créer dans Plesk).
- Appels externes : extension `curl`, avec délai court et repli propre si le service ne répond pas.
- Pas de CDN obligatoire : les assets vivent dans le dossier de l'appli.

## Structure d'une appli `xxx/`

```
xxx/
  app.json                 # {"name": "...", "description": "...", "icon": "🎲"} → affichée sur la page d'accueil
  index.php                # point d'entrée (liens et chemins RELATIFS : l'appli vit dans /xxx/)
  config.php               # valeurs par défaut, sans secret (versionné)
  config.local.sample.php  # modèle des secrets
  inc/                     # code PHP non public (+ .htaccess « Require all denied »)
  data/                    # données en local uniquement (+ .htaccess « Require all denied »)
  assets/
  README.md
```

Prendre `lan/` comme modèle (voir `lan/config.php`, `lan/inc/bootstrap.php`, `lan/inc/helpers.php`, `lan/inc/db.php`).

## Conventions obligatoires

- **Dossier privé** : base SQLite et secrets dans `private/<appli>/`, **hors du dossier web**. Le dossier `private`
  de Plesk est trouvé en remontant depuis l'appli (code dans `lan/config.php`) ; à défaut (local), repli sur `data/`.
  Créer le sous-dossier automatiquement (`mkdir` au premier accès).
- **Secrets** : chargés depuis `private/<appli>/config.php` (serveur) puis `config.local.php` (local),
  par-dessus `config.php`. Documenter chaque clé dans `config.local.sample.php`.
- **Isolation** : toutes les applis partagent le même domaine → `session_name('<appli>_session')` unique,
  cookies préfixés par le nom de l'appli (`lan_auth`, …).
- **Sécurité minimale** : requêtes préparées PDO partout, `htmlspecialchars` sur toute sortie, jeton CSRF sur
  chaque formulaire POST, `password_hash` / `password_verify` pour les mots de passe.
- **Schéma** : `CREATE TABLE IF NOT EXISTS` au premier accès ; pour une colonne ajoutée plus tard, migration
  automatique (voir `add_missing_column` dans `lan/inc/db.php`) : les bases en production doivent continuer à marcher.
- nginx (Plesk) ne lit pas les `.htaccess` pour les fichiers statiques : ne jamais compter sur `.htaccess`
  seul pour cacher un fichier sensible → il va dans `private/`.
- Interface responsive (utilisable au téléphone), textes en français.

## Nouvelle appli : check-list

1. Créer `xxx/` selon la structure ci-dessus (avec `app.json`).
2. Ajouter une ligne dans le tableau de `README.md` à la racine.
3. Si l'appli a des secrets : dire au propriétaire quel fichier créer dans `private/xxx/config.php` et avec quoi.
