# Mes applis

Mes petites applis web, **une par dossier**, publiées automatiquement sur **https://tbxm.be**
(serveur Plesk chez OVH) à chaque mise à jour de la branche `main`.

| Dossier | Adresse | Appli |
|---------|---------|-------|
| [`lan/`](lan/) | https://tbxm.be/lan/ | Vote des jeux LAN : listes perso, pot commun et vote |

La racine (https://tbxm.be/) liste toutes les applis (chaque dossier contenant un `app.json`).

## Mise en place sur Plesk (déjà faite)

- Domaine `tbxm.be`, PHP 8.2+ (FPM), certificat Let's Encrypt.
- *Git* : dépôt `https://github.com/boulezor/module-lan` (public, donc pas de clé), branche **`main`**,
  déploiement automatique vers `/httpdocs`.
- Pour que chaque push se déploie tout seul : copier l'**URL du webhook** affichée par Plesk
  (icône réglages du dépôt Git) dans GitHub › dépôt › *Settings › Webhooks › Add webhook*.
  Sans webhook, cliquer sur *Exécuter pull* puis *Déployer maintenant* dans Plesk.

**Pas de base MySQL** : chaque appli utilise un fichier SQLite qu'elle crée toute seule dans
`/var/www/vhosts/tbxm.be/private/<appli>/` (hors du web, conservé entre les déploiements et inclus dans les
sauvegardes Plesk).

Sécurité en plus (facultatif) — *Paramètres Apache & nginx › Directives nginx supplémentaires* :

```nginx
location ~ /(inc|data|private)/ { deny all; }
location ~ \.(sqlite|sqlite-wal|sqlite-shm|md|json)$ { deny all; }
```

## Ajouter / configurer une appli

- Les nouvelles applis arrivent par Git (Claude Code crée le dossier et pousse) : rien à faire dans Plesk.
- Si l'appli a des secrets (mot de passe, clé API) : créer **une fois** `private/<appli>/config.php`
  avec le gestionnaire de fichiers Plesk, à partir du `config.local.sample.php` de l'appli.

Pour l'appli LAN : `/var/www/vhosts/tbxm.be/private/lan/config.php` avec

```php
<?php
return ['admin_password' => 'mon-mot-de-passe'];
```

## Développement en local

```bash
php -S localhost:8000      # depuis la racine du dépôt, puis http://localhost:8000/
```

En local, chaque appli lit `config.local.php` et range ses données dans son dossier `data/`.
