<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Demande extends Model
{
    public const TYPES_ACTE = ['acte_de_naissance', 'casier_judiciaire', 'certificat_de_residence'];

    public const DEPOSEE = 'deposee';
    public const EN_COURS = 'en_cours';
    public const VALIDEE = 'validee';
    public const REJETEE = 'rejetee';
    public const STATUTS = [self::DEPOSEE, self::EN_COURS, self::VALIDEE, self::REJETEE];

    public const LIBELLES = [
        self::DEPOSEE => 'déposée',
        self::EN_COURS => 'en cours de traitement',
        self::VALIDEE => 'validée',
        self::REJETEE => 'rejetée',
    ];

    protected $table = 'demandes';

    protected $fillable = ['npi', 'type_acte', 'nombre_copies', 'statut', 'motif_rejet'];

    public function formater(): array
    {
        return [
            'id' => $this->id,
            'npi' => $this->npi,
            'type_acte' => $this->type_acte,
            'nombre_copies' => (int) $this->nombre_copies,
            'statut' => $this->statut,
            'motif_rejet' => $this->motif_rejet,
            'date_depot' => $this->created_at?->toIso8601String(),
            'date_maj' => $this->updated_at?->toIso8601String(),
        ];
    }
}
