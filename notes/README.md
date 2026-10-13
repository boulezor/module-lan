# Notes

Espace de notes partagé façon **Notion**, avec **assistant de rédaction IA**. Accès par compte uniquement,
sans inscription publique : https://tbxm.be/notes/

## Fonctions

- **Pages imbriquées** dans une barre latérale (glisser-déposer pour réorganiser), fil d'Ariane, sous-pages.
- **Deux espaces** : *Équipe* (visible et modifiable par tous les comptes) et *Privé* (visible par soi seul).
- **Éditeur par blocs** ([Editor.js](https://editorjs.io), intégré dans `assets/vendor`) :
  - texte, titres, listes, cases à cocher, citations, code, tableaux, séparateurs ;
  - images et fichiers joints, vidéos intégrées (YouTube, Vimeo…) ;
  - blocs maison : encadré coloré, bloc repliable, lien vers une page, table des matières.
  - **« / »** ouvre le menu des blocs (Entrée choisit le premier résultat).
  - **Raccourcis Markdown** : `#`, `##`, `###`, `-`, `1.`, `[]`, `>`, ```` ``` ````, `---`.
- **Icône (emoji)** et **couverture** (dégradé ou image) par page.
- **Enregistrement automatique**, avec détection des conflits si deux personnes modifient la même page.
- **Recherche** (Ctrl+K) dans les titres et le contenu ; **favoris** ; **récemment modifiées** sur l'accueil.
- **Corbeille** (restaurer / supprimer définitivement), **dupliquer**, **déplacer vers…**
- **Partage public en lecture seule** par lien secret (activable / désactivable par page).
- **Export Markdown** et impression / PDF propre.
- **Assistant IA** (OpenAI) :
  - sur le texte sélectionné (bouton **✨ IA**) : améliorer, corriger, raccourcir, développer, simplifier,
    changer de ton, transformer en liste, résumer, extraire les tâches, traduire (EN, NL, FR), continuer, ou consigne libre ;
  - bloc **« Écrire avec l'IA »** (menu « / ») : écrire à partir d'une consigne, continuer, résumer, plan, idées ;
  - bouton **✨ IA** de la page : résumer la page (en haut), continuer, extraire les tâches, demander à l'IA.

## Comptes

1. **Premier compte (administrateur)** : à la première visite, la page d'installation demande un **code d'installation**
   défini dans le fichier privé (voir ci-dessous), puis nom, email et mot de passe.
2. **Autres comptes** : *Administration › Créer un lien d'invitation* → un lien à usage unique (valable 7 jours)
   à envoyer à la personne, qui choisit elle-même son mot de passe.
3. **Mot de passe oublié** : *Administration › Lien mot de passe* génère un lien de réinitialisation.

Les administrateurs peuvent aussi donner / retirer les droits d'admin et supprimer un compte
(ses pages privées sont supprimées, les pages d'équipe restent).

## Installation (Plesk)

Rien à créer dans Plesk : la base SQLite et les fichiers envoyés sont rangés automatiquement dans
`/var/www/vhosts/<domaine>/private/notes/` (hors du web).

Seule étape : créer **`private/notes/config.php`** avec le code d'installation :

```php
<?php
return [
    'setup_code' => 'un-code-secret',
];
```

Puis ouvrir `https://tbxm.be/notes/` et créer le compte administrateur.

- **IA** : coller la clé OpenAI dans *Administration › Assistant de rédaction IA* (ou `'openai_api_key'` dans ce fichier),
  vérifier le modèle, cliquer sur **Tester**.
- **Taille des fichiers** : 20 Mo par défaut (`'max_upload_mb'`). PHP doit aussi l'accepter
  (*Plesk › Paramètres PHP* : `upload_max_filesize` et `post_max_size`).

## Développement local

```bash
cp config.local.sample.php config.local.php   # puis définir setup_code
php -S localhost:8000                          # depuis ce dossier
```

## Licences

Editor.js et ses extensions (`assets/vendor/editorjs/`) sont sous licence MIT (fichiers `LICENSE-*.txt`).
