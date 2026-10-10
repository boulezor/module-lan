# Vote des jeux LAN

Petit module PHP pour choisir les jeux d'une LAN party :

| Page | Accès | Rôle |
|------|-------|------|
| `index.php` — **Proposer** | public | Nom + jeu. Vérifie les doublons, propose une description, une image et des liens automatiquement. |
| `voter.php` — **Voter** | public | Un vote par personne (modifiable depuis le même appareil). |
| `resultats.php` — **Résultats** | mot de passe | Classement, votants par jeu, ouverture/fermeture des phases, suppression de jeux/votes. |

## Installation

Prérequis : PHP 8.1+ avec les extensions `pdo_sqlite` et `curl` (présentes chez presque tous les hébergeurs).

1. Copier les fichiers sur l'hébergement (ex. dans un dossier `/lan/`).
2. Copier `config.local.sample.php` en `config.local.php` et définir `admin_password`.
3. Vérifier que le dossier `data/` est accessible en écriture par PHP (la base SQLite y est créée automatiquement).

Rien d'autre : pas de base MySQL, pas de Composer, pas de build.

Sous Apache, les fichiers `.htaccess` fournis bloquent l'accès direct à `inc/`, `data/` et aux fichiers de configuration.
Sous Nginx, ajouter l'équivalent :

```nginx
location ~ ^/(lan/)?(inc|data)/ { deny all; }
location ~ /config.*\.php$ { deny all; }
```

## Description automatique : faut-il une clé API ?

**Non.** Sans aucune clé, le module interroge :

1. **Steam** (API publique de la boutique) : description en français, image, genres, modes multijoueur / LAN / coop, « Gratuit » ;
2. **Wikipédia FR** si le jeu n'est pas sur Steam ;
3. **YouTube** : lien vers une recherche « *nom du jeu* gameplay trailer ».

La personne qui propose peut toujours corriger la description et les liens avant d'envoyer.

Clés **optionnelles** dans `config.local.php` :

- `openai_api_key` : une IA réécrit la description en 2-3 phrases orientées LAN (genre, nombre de joueurs…) en s'appuyant sur les infos Steam/Wikipédia. Modèle réglable via `openai_model`. Coût négligeable (quelques centimes pour des centaines de jeux).
- `youtube_api_key` (YouTube Data API v3, gratuite) : lien direct vers une vidéo au lieu d'une page de recherche.

## Doublons

- Les noms sont comparés sans majuscules, accents ni ponctuation (« Counter-Strike 2 » = « counter strike 2 ») ;
- Deux propositions pointant vers la même fiche Steam sont refusées, même avec des noms différents (« AoE 2 » / « Age of Empires II ») ;
- Les noms proches (fautes de frappe, éditions) déclenchent un avertissement sans bloquer.

## Un vote par personne

Chaque vote est lié au nom (sans tenir compte des majuscules/accents) **et** à un cookie du navigateur :

- un même nom ne peut voter qu'une fois ; seul le navigateur d'origine peut modifier ce vote ;
- un navigateur qui a déjà voté ne peut pas revoter sous un autre nom.

C'est suffisant entre amis, mais ce n'est pas un système d'authentification : en cas de souci, l'administrateur peut supprimer un vote depuis la page Résultats.

## Déroulement conseillé

1. Phase de propositions (propositions et votes ouverts par défaut) ;
2. Sur la page Résultats, fermer les propositions quand la liste est complète ;
3. Fermer les votes à la date prévue et consulter le classement.
