# Vote des jeux LAN

Petit module PHP pour choisir les jeux d'une LAN party entre amis.

**Principe** : chacun tient sa **liste personnelle** de jeux. Quand l'organisateur crée une **LAN** (une date),
chacun envoie des jeux de sa liste dans le **pot commun** de cette LAN, puis tout le monde **vote**.

| Page | Accès | Rôle |
|------|-------|------|
| `index.php` — **LAN** | public | Prochaine LAN (date, état) et son pot commun. |
| `connexion.php` | public | Connexion / création de compte (pseudo, email, mot de passe). |
| `ma-liste.php` — **Ma liste** | compte | Ajouter, modifier, supprimer ses jeux (description, image, vidéo, lien remplis automatiquement) et les proposer au pot commun d'une LAN. |
| `voter.php` — **Voter** | compte | Un vote par personne et par LAN, modifiable tant que les votes sont ouverts. |
| `resultats.php` — **Résultats** | mot de passe organisateur | Créer les LAN, ouvrir/fermer propositions et votes, classement, gestion des comptes. |

## Installation

Cette appli fait partie du dépôt `apps` : elle est déployée automatiquement dans `/lan/` (voir le README à la racine).

Prérequis : PHP 8.1+ avec `pdo_sqlite` et `curl`.

- **Sur le serveur** : la base est créée automatiquement dans le dossier `private/lan/` de Plesk (hors du web).
  Il suffit d'y créer `config.php` avec le mot de passe organisateur (modèle : `config.local.sample.php`).
- **En local** : copier `config.local.sample.php` en `config.local.php` ; la base va dans `data/`.
  Lancer `php -S localhost:8000` depuis ce dossier.

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

Les noms sont comparés sans majuscules, accents ni ponctuation (« Counter-Strike 2 » = « counter strike 2 »),
et deux jeux pointant vers la même fiche Steam sont considérés comme identiques (« AoE 2 » / « Age of Empires II »).

- **Dans sa liste** : on ne peut pas ajouter deux fois le même jeu ; un nom proche affiche un avertissement.
- **Dans le pot commun** : si le jeu a déjà été proposé pour cette LAN, la proposition est refusée et on voit qui l'a proposé.
  Le jeu reste dans la liste perso, et la liste affiche « Déjà proposé par X ».

## Liste perso et pot commun

- Le pot commun garde une copie du jeu : supprimer un jeu de sa liste ne le retire pas d'une LAN.
- Modifier la description ou les liens d'un jeu de sa liste met aussi à jour sa fiche dans les LAN encore ouvertes aux propositions.
- Tant que les propositions sont ouvertes, chacun peut retirer un jeu qu'il a proposé (les votes pour ce jeu sont alors supprimés).

## Comptes

Volontairement simples, c'est pour des amis : pseudo + email + mot de passe (4 caractères minimum), sans vérification d'email.
On reste connecté sur l'appareil (cookie d'un an). Mot de passe oublié : l'organisateur en génère un nouveau depuis la page Résultats.
Chacun peut changer son mot de passe dans « Ma liste › Mon compte ».

## Déroulement

1. Chacun crée son compte et remplit sa liste (à tout moment, même sans LAN prévue).
2. L'organisateur crée une LAN sur la page Résultats (date + nom facultatif) : propositions et votes sont ouverts.
3. Chacun propose des jeux de sa liste, puis vote.
4. L'organisateur ferme les propositions puis les votes et consulte le classement.
