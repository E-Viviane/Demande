<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes', function (Blueprint $table) {
            $table->id();
            $table->string('npi', 10);
            $table->string('type_acte');
            $table->unsignedTinyInteger('nombre_copies');
            $table->string('statut')->default('deposee');
            $table->text('motif_rejet')->nullable();
            $table->timestamps();

            $table->index(['npi', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes');
    }
};
