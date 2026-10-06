# Suivi des demandes d'actes — API Laravel

API REST de dépôt et de suivi des demandes d'actes administratifs (acte de naissance, casier judiciaire, certificat de résidence). Étude de cas DEP/ASIN 2026.

**Stack** : Laravel (PHP 8.2+), MySQL, PHPUnit.

## Installation et démarrage

Prérequis : PHP 8.2+, Composer, MySQL.

```bash
git clone <url-du-depot> && cd <dossier>
composer install
cp .env.example .env
php artisan key:generate
```

Créer la base MySQL :

```sql
CREATE DATABASE demandes_actes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Dans `.env`, renseigner la connexion :

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=demandes_actes
DB_USERNAME=root
DB_PASSWORD=
```

Puis :

```bash
php artisan migrate
php artisan serve
```

- API : http://127.0.0.1:8000/api
- Écran simple : http://127.0.0.1:8000/

## Tests

Les tests utilisent une base SQLite en mémoire (configurée dans `phpunit.xml`) : ils ne touchent pas à la base MySQL.

```bash
php artisan test
```

17 tests couvrant toutes les règles de gestion (NPI, type d'acte, copies, cycle de vie, rejet motivé, états finaux, filtre, pagination, statistiques).

## Cycle de vie

```
deposee ──► en_cours ──► validee
                    └──► rejetee (motif obligatoire)
```

`validee` et `rejetee` sont des états finaux.

## API (préfixe `/api`)

| Méthode | Route | Rôle |
|---|---|---|
| POST | `/api/demandes` | Déposer une demande → 201, statut `deposee` |
| GET | `/api/demandes/{id}` | Consulter une demande |
| GET | `/api/usagers/{npi}/demandes?statut=&page=&taille=` | Demandes d'un usager, de la plus récente à la plus ancienne ; filtre `statut` facultatif ; 20 par page max |
| GET | `/api/usagers/{npi}/statistiques` | Nombre de demandes par statut |
| POST | `/api/demandes/{id}/traiter` | `deposee` → `en_cours` |
| POST | `/api/demandes/{id}/valider` | `en_cours` → `validee` |
| POST | `/api/demandes/{id}/rejeter` | `en_cours` → `rejetee`, corps `{"motif": "..."}` obligatoire |

Statuts : `deposee`, `en_cours`, `validee`, `rejetee`.
Types d'acte : `acte_de_naissance`, `casier_judiciaire`, `certificat_de_residence`.

### Exemple (scénario de recette)

```bash
# Déposer
curl -X POST localhost:8000/api/demandes -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"npi":"0123456789","type_acte":"acte_de_naissance","nombre_copies":2}'

# Lister (filtre facultatif)
curl localhost:8000/api/usagers/0123456789/demandes
curl "localhost:8000/api/usagers/0123456789/demandes?statut=en_cours"

# Faire avancer
curl -X POST localhost:8000/api/demandes/1/traiter
curl -X POST localhost:8000/api/demandes/1/valider
# ou rejeter
curl -X POST localhost:8000/api/demandes/1/rejeter -H "Content-Type: application/json" \
  -d '{"motif":"Pièce justificative illisible"}'

# Statistiques
curl localhost:8000/api/usagers/0123456789/statistiques
```

### Erreurs

Toujours au format `{"erreur": "message clair"}` :
- **422** saisie invalide (NPI ≠ 10 chiffres, type inconnu, copies hors 1–5, rejet sans motif, statut de filtre inconnu) ;
- **409** action interdite (saut d'étape, demande déjà validée ou rejetée) ;
- **404** demande introuvable.

## Choix de conception

- Table unique `demandes`, modèle `App\Models\Demande`, logique dans `DemandeController`.
- Transitions = actions explicites (`/traiter`, `/valider`, `/rejeter`) : le client ne peut pas imposer un statut incohérent.
- La transition est une mise à jour conditionnelle (`WHERE statut = <attendu>`), sûre en cas d'accès concurrents.
- Validation manuelle dans le contrôleur pour des messages d'erreur clairs et un format JSON uniforme.

## État d'avancement

| Élément | État |
|---|---|
| Socle : dépôt, consultation filtrée, cycle de vie | fait |
| Bonus : pagination (20 max) | fait |
| Bonus : compte par statut | fait |
| Bonus : tests automatisés | fait |
| Bonus : écran simple | fait |

Limites : pas d'authentification ni de rôles (agent / usager).
