# Bingo

Bingo à **projeter en salle** : https://tbxm.be/bingo/

L'organisateur pilote la partie depuis son panneau (ordinateur, tablette ou téléphone) ; l'**écran de projection**
s'ouvre sur l'ordinateur relié au projecteur et se met à jour tout seul (environ une fois par seconde).

## Fonctions

- **Comptes organisateurs** (sans inscription publique) : premier compte avec le code d'installation, puis invitations.
- **Sessions** : en créer plusieurs, y revenir, dupliquer, réinitialiser (nouvelle partie, mêmes cartons), supprimer.
  Bingo **90 boules** (quine, double quine, carton plein) ou **75 boules** (B-I-N-G-O).
- **Mode manuel** : l'organisateur encode les numéros (clavier, pavé numérique ou clic dans la grille) ;
  doublons et numéros hors limites refusés ; **annuler la dernière boule** (Ctrl+Z) ou retirer n'importe quel numéro.
- **Mode automatique** : bouton « Tirer une boule » (tirage aléatoire `random_int`) ou **tirage automatique** toutes
  les N secondes (4 à 60 s), avec pause. Le tirage est fait par le serveur : plusieurs écrans ouverts ne tirent jamais deux fois.
  Il se met en pause tout seul quand un BINGO est annoncé.
- **Écran de projection** (`ecran.php?t=…`, lien secret, en lecture seule) :
  - grosse boule animée (suspense de 2 s pour les tirages au hasard), 6 boules précédentes, compteur ;
  - grille de toutes les boules, les numéros sortis s'allument en couleur ;
  - phase en cours et lot (« On joue : QUINE — 🎁 Bouteille de champagne ») ;
  - **annonce vocale** en français (« Le 23. Deux, trois »), sons, écran **BINGO !** avec confettis ;
  - thèmes *Nuit étoilée*, *Fête*, *Casino*, *Clair* ; plein écran, écran maintenu allumé ;
  - lisible aussi sur téléphone : le lien peut être envoyé aux joueurs.
  - Si l'organisateur est connecté sur l'ordinateur de l'écran, il peut **piloter depuis l'écran** : barre d'outils
    au mouvement de la souris et raccourcis (chiffres + Entrée, Espace = tirer, Retour arrière = annuler, A = auto,
    Échap = masquer, `?` = aide).
- **Phases et lots** personnalisables (« Quine | Panier garni », une par ligne), liste des **gagnants**.
- **Planches à imprimer** : une planche = une feuille A4 de **1 à 6 grilles** (au choix, la taille des grilles s'adapte).
  Plusieurs séries possibles pour un même bingo (ex. 20 planches de 3 puis 10 planches de 6) : les numéros de grilles
  se suivent et ne sont jamais réutilisés. Historique des séries avec « Réimprimer », et réimpression de grilles précises.
- **Vérification d'une grille** par son numéro : lignes complètes, carton plein, numéros manquants ;
  affichage possible de la grille sur l'écran avant de valider le gagnant.
- **Thème « École Saint-Martin »** (réservé aux administrateurs) : logo, titre et deux couleurs réglés dans
  *Administration* (bouton pour reprendre automatiquement les couleurs du logo). Le logo et le titre apparaissent sur
  l'écran de projection, l'écran BINGO et les planches imprimées. Le logo est rangé dans `private/bingo/theme/`.
- **Message à l'écran** (« Pause de 10 minutes », etc.).

## Installation (Plesk)

La base SQLite et les sessions sont rangées automatiquement dans `/var/www/vhosts/<domaine>/private/bingo/` (hors du web).

Seule étape : créer **`private/bingo/config.php`** avec le code d'installation, puis ouvrir https://tbxm.be/bingo/ :

```php
<?php
return [
    'setup_code' => 'choisis-un-code',
];
```

En local : `bingo/config.local.php` (même contenu), données dans `bingo/data/`.
