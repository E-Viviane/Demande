<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemandeTest extends TestCase
{
    use RefreshDatabase;

    private const NPI = '0123456789';

    private function deposer(array $surcharge = [])
    {
        return $this->postJson('/api/demandes', array_merge([
            'npi' => self::NPI,
            'type_acte' => 'acte_de_naissance',
            'nombre_copies' => 1,
        ], $surcharge));
    }

    // --- Dépôt -----------------------------------------------------------
    public function test_depot_valide(): void
    {
        $this->deposer(['nombre_copies' => 3])
            ->assertCreated()
            ->assertJsonPath('statut', 'deposee')
            ->assertJsonPath('nombre_copies', 3)
            ->assertJsonStructure(['id']);
    }

    public function test_npi_invalide(): void
    {
        foreach (['123', '01234567890', '01234abcde', '', 1234567890] as $npi) {
            $this->deposer(['npi' => $npi])->assertStatus(422)->assertSee('10 chiffres', false);
        }
    }

    public function test_type_acte_invalide(): void
    {
        $this->deposer(['type_acte' => 'permis'])->assertStatus(422);
    }

    public function test_copies_invalides(): void
    {
        foreach ([0, 6, -1, '2', 2.5, true] as $copies) {
            $this->deposer(['nombre_copies' => $copies])->assertStatus(422);
        }
    }

    public function test_copies_bornes_acceptees(): void
    {
        $this->deposer(['nombre_copies' => 1])->assertCreated();
        $this->deposer(['nombre_copies' => 5])->assertCreated();
    }

    public function test_champ_manquant(): void
    {
        $this->postJson('/api/demandes', ['npi' => self::NPI])->assertStatus(422)->assertSee('obligatoire', false);
    }

    // --- Consultation ----------------------------------------------------
    public function test_liste_recente_a_ancienne_et_isolee_par_usager(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->deposer()->json('id');
        }
        $this->deposer(['npi' => '9999999999']);

        $r = $this->getJson('/api/usagers/'.self::NPI.'/demandes')->assertOk();
        $this->assertSame(array_reverse($ids), array_column($r->json('items'), 'id'));
        $this->assertSame(3, $r->json('total'));
    }

    public function test_filtre_par_statut(): void
    {
        $a = $this->deposer()->json('id');
        $this->deposer();
        $this->postJson("/api/demandes/$a/traiter");

        $r = $this->getJson('/api/usagers/'.self::NPI.'/demandes?statut=en_cours')->assertOk();
        $this->assertSame([$a], array_column($r->json('items'), 'id'));
        $this->getJson('/api/usagers/'.self::NPI.'/demandes?statut=x')->assertStatus(422);
    }

    public function test_liste_npi_invalide(): void
    {
        $this->getJson('/api/usagers/abc/demandes')->assertStatus(422);
    }

    public function test_pagination_max_20(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->deposer();
        }
        $p1 = $this->getJson('/api/usagers/'.self::NPI.'/demandes')->assertOk();
        $p2 = $this->getJson('/api/usagers/'.self::NPI.'/demandes?page=2')->assertOk();
        $this->assertCount(20, $p1->json('items'));
        $this->assertCount(5, $p2->json('items'));
        $this->assertSame(2, $p1->json('pages'));
        $this->getJson('/api/usagers/'.self::NPI.'/demandes?taille=50')->assertStatus(422);
    }

    public function test_statistiques(): void
    {
        $a = $this->deposer()->json('id');
        $this->deposer();
        $this->postJson("/api/demandes/$a/traiter");

        $this->getJson('/api/usagers/'.self::NPI.'/statistiques')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('par_statut', ['deposee' => 1, 'en_cours' => 1, 'validee' => 0, 'rejetee' => 0]);
    }

    // --- Cycle de vie ----------------------------------------------------
    public function test_cycle_validation(): void
    {
        $id = $this->deposer()->json('id');
        $this->postJson("/api/demandes/$id/traiter")->assertOk()->assertJsonPath('statut', 'en_cours');
        $this->postJson("/api/demandes/$id/valider")->assertOk()->assertJsonPath('statut', 'validee');
    }

    public function test_cycle_rejet_motive(): void
    {
        $id = $this->deposer()->json('id');
        $this->postJson("/api/demandes/$id/traiter");
        $this->postJson("/api/demandes/$id/rejeter", ['motif' => 'Pièce illisible'])
            ->assertOk()
            ->assertJsonPath('statut', 'rejetee')
            ->assertJsonPath('motif_rejet', 'Pièce illisible');
    }

    public function test_rejet_sans_motif_refuse(): void
    {
        $id = $this->deposer()->json('id');
        $this->postJson("/api/demandes/$id/traiter");
        foreach ([[], ['motif' => ''], ['motif' => '   '], ['motif' => null]] as $corps) {
            $this->postJson("/api/demandes/$id/rejeter", $corps)->assertStatus(422);
        }
        $this->getJson("/api/demandes/$id")->assertJsonPath('statut', 'en_cours');
    }

    public function test_sauts_interdits(): void
    {
        $id = $this->deposer()->json('id');
        $this->postJson("/api/demandes/$id/valider")->assertStatus(409);
        $this->postJson("/api/demandes/$id/rejeter", ['motif' => 'x'])->assertStatus(409);
        $this->getJson("/api/demandes/$id")->assertJsonPath('statut', 'deposee');
    }

    public function test_double_traitement_refuse(): void
    {
        $id = $this->deposer()->json('id');
        $this->postJson("/api/demandes/$id/traiter")->assertOk();
        $this->postJson("/api/demandes/$id/traiter")->assertStatus(409);
    }

    public function test_etat_final_immuable(): void
    {
        $id = $this->deposer()->json('id');
        $this->postJson("/api/demandes/$id/traiter");
        $this->postJson("/api/demandes/$id/valider");

        $this->postJson("/api/demandes/$id/traiter")->assertStatus(409)->assertSee('ne peut plus changer', false);
        $this->postJson("/api/demandes/$id/valider")->assertStatus(409);
        $this->postJson("/api/demandes/$id/rejeter", ['motif' => 'autre'])->assertStatus(409);
    }

    public function test_demande_inconnue(): void
    {
        $this->getJson('/api/demandes/999')->assertNotFound();
        $this->postJson('/api/demandes/999/traiter')->assertNotFound();
    }
}
