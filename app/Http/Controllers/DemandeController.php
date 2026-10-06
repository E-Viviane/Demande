<?php

namespace App\Http\Controllers;

use App\Models\Demande;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DemandeController extends Controller
{
    private const TAILLE_MAX = 20;
    private const MSG_NPI = 'Le NPI doit comporter exactement 10 chiffres.';
    private const MSG_COPIES = 'Le nombre de copies doit être un entier compris entre 1 et 5.';

    private function erreur(string $message, int $code): JsonResponse
    {
        return response()->json(['erreur' => $message], $code);
    }

    private function npiValide(mixed $npi): bool
    {
        return is_string($npi) && preg_match('/^[0-9]{10}$/', $npi) === 1;
    }

    // 1. Déposer une demande
    public function deposer(Request $request): JsonResponse
    {
        $data = $request->json()->all();

        $erreurs = [];
        $npi = $data['npi'] ?? null;
        $type = $data['type_acte'] ?? null;
        $copies = $data['nombre_copies'] ?? null;

        if (! array_key_exists('npi', $data)) {
            $erreurs[] = 'Le champ « npi » est obligatoire.';
        } elseif (! $this->npiValide($npi)) {
            $erreurs[] = self::MSG_NPI;
        }

        if ($type === null) {
            $erreurs[] = 'Le champ « type_acte » est obligatoire.';
        } elseif (! is_string($type) || ! in_array($type, Demande::TYPES_ACTE, true)) {
            $erreurs[] = "Le type d'acte doit être l'un de : ".implode(', ', Demande::TYPES_ACTE).'.';
        }

        if ($copies === null) {
            $erreurs[] = 'Le champ « nombre_copies » est obligatoire.';
        } elseif (! is_int($copies) || $copies < 1 || $copies > 5) {
            $erreurs[] = self::MSG_COPIES;
        }

        if ($erreurs) {
            return response()->json(['erreur' => implode(' ', $erreurs), 'details' => $erreurs], 422);
        }

        $demande = Demande::create([
            'npi' => $npi,
            'type_acte' => $type,
            'nombre_copies' => $copies,
            'statut' => Demande::DEPOSEE,
        ]);

        return response()->json($demande->fresh()->formater(), 201);
    }

    public function consulter(int $id): JsonResponse
    {
        $demande = Demande::find($id);

        return $demande ? response()->json($demande->formater()) : $this->erreur("Demande $id introuvable.", 404);
    }

    // 2. Demandes d'un usager : plus récentes d'abord, filtre facultatif, pagination (20 max)
    public function lister(Request $request, string $npi): JsonResponse
    {
        if (! $this->npiValide($npi)) {
            return $this->erreur(self::MSG_NPI, 422);
        }

        $statut = $request->query('statut');
        if ($statut !== null && $statut !== '' && ! in_array($statut, Demande::STATUTS, true)) {
            return $this->erreur('Le statut doit être l\'un de : '.implode(', ', Demande::STATUTS).'.', 422);
        }

        $page = $request->query('page', 1);
        $taille = $request->query('taille', self::TAILLE_MAX);
        if (! ctype_digit((string) $page) || (int) $page < 1) {
            return $this->erreur('La page doit être un entier supérieur ou égal à 1.', 422);
        }
        if (! ctype_digit((string) $taille) || (int) $taille < 1 || (int) $taille > self::TAILLE_MAX) {
            return $this->erreur('La taille de page doit être comprise entre 1 et '.self::TAILLE_MAX.'.', 422);
        }

        $requete = Demande::where('npi', $npi);
        if ($statut) {
            $requete->where('statut', $statut);
        }

        $pagination = $requete->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) $taille, ['*'], 'page', (int) $page);

        return response()->json([
            'total' => $pagination->total(),
            'page' => $pagination->currentPage(),
            'taille' => $pagination->perPage(),
            'pages' => $pagination->lastPage(),
            'items' => $pagination->getCollection()->map(fn (Demande $d) => $d->formater())->values(),
        ]);
    }

    // Bonus : nombre de demandes par statut
    public function statistiques(string $npi): JsonResponse
    {
        if (! $this->npiValide($npi)) {
            return $this->erreur(self::MSG_NPI, 422);
        }

        $compte = array_fill_keys(Demande::STATUTS, 0);
        foreach (Demande::where('npi', $npi)->selectRaw('statut, COUNT(*) as n')->groupBy('statut')->get() as $ligne) {
            $compte[$ligne->statut] = (int) $ligne->n;
        }

        return response()->json(['npi' => $npi, 'total' => array_sum($compte), 'par_statut' => $compte]);
    }

    // 3. Cycle de vie : deposee -> en_cours -> validee | rejetee
    public function traiter(int $id): JsonResponse
    {
        return $this->transition($id, Demande::DEPOSEE, Demande::EN_COURS);
    }

    public function valider(int $id): JsonResponse
    {
        return $this->transition($id, Demande::EN_COURS, Demande::VALIDEE);
    }

    public function rejeter(Request $request, int $id): JsonResponse
    {
        $motif = $request->json('motif');
        if (! is_string($motif) || trim($motif) === '') {
            return $this->erreur('Un rejet doit toujours être motivé : le motif est obligatoire.', 422);
        }

        return $this->transition($id, Demande::EN_COURS, Demande::REJETEE, trim($motif));
    }

    private function transition(int $id, string $attendu, string $nouveau, ?string $motif = null): JsonResponse
    {
        $demande = Demande::find($id);
        if (! $demande) {
            return $this->erreur("Demande $id introuvable.", 404);
        }

        if ($demande->statut !== $attendu) {
            if (in_array($demande->statut, [Demande::VALIDEE, Demande::REJETEE], true)) {
                $message = 'Cette demande est déjà '.Demande::LIBELLES[$demande->statut].' et ne peut plus changer.';
            } else {
                $message = 'Action impossible : la demande est '.Demande::LIBELLES[$demande->statut]
                    .', elle doit être '.Demande::LIBELLES[$attendu]
                    ." pour passer à l'état « ".Demande::LIBELLES[$nouveau].' ».';
            }

            return $this->erreur($message, 409);
        }

        // Mise à jour conditionnelle : sûre même si deux agents agissent en même temps
        $modifiees = Demande::where('id', $id)->where('statut', $attendu)->update([
            'statut' => $nouveau,
            'motif_rejet' => $motif,
            'updated_at' => now(),
        ]);

        if ($modifiees !== 1) {
            return $this->erreur('La demande a été modifiée entre-temps, réessayez.', 409);
        }

        return response()->json(Demande::find($id)->formater());
    }
}
