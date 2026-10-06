<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Suivi des demandes d'actes</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 860px; margin: 2rem auto; padding: 0 1rem; color: #1b1b1b; }
  h1 { font-size: 1.4rem; }
  fieldset { border: 1px solid #ccc; border-radius: 8px; margin-bottom: 1.2rem; }
  label { margin-right: .8rem; }
  input, select, button { padding: .4rem .6rem; font-size: 1rem; }
  button { cursor: pointer; }
  table { width: 100%; border-collapse: collapse; margin-top: .8rem; }
  th, td { text-align: left; padding: .5rem; border-bottom: 1px solid #e3e3e3; }
  .badge { padding: .1rem .5rem; border-radius: 99px; font-size: .85rem; background: #eee; }
  .deposee { background: #e8f0fe; } .en_cours { background: #fff4d6; }
  .validee { background: #d9f5e1; } .rejetee { background: #fbdcdc; }
  #message { min-height: 1.4rem; margin: .5rem 0; }
  .erreur { color: #b00020; } .ok { color: #1a7f37; }
</style>
</head>
<body>
<h1>Suivi des demandes d'actes</h1>

<fieldset>
  <legend>Déposer une demande</legend>
  <label>NPI <input id="dep-npi" maxlength="10" inputmode="numeric" placeholder="10 chiffres"></label>
  <label>Acte
    <select id="dep-type">
      <option value="acte_de_naissance">Acte de naissance</option>
      <option value="casier_judiciaire">Casier judiciaire</option>
      <option value="certificat_de_residence">Certificat de résidence</option>
    </select>
  </label>
  <label>Copies <input id="dep-copies" type="number" min="1" max="5" value="1" style="width:4rem"></label>
  <button id="dep-btn">Déposer</button>
</fieldset>

<fieldset>
  <legend>Demandes d'un usager</legend>
  <label>NPI <input id="npi" maxlength="10" inputmode="numeric" placeholder="10 chiffres"></label>
  <label>Statut
    <select id="statut">
      <option value="">Tous</option><option value="deposee">Déposée</option>
      <option value="en_cours">En cours</option><option value="validee">Validée</option>
      <option value="rejetee">Rejetée</option>
    </select>
  </label>
  <button id="liste-btn">Afficher</button>
  <div id="stats"></div>
  <div id="message"></div>
  <table>
    <thead><tr><th>N°</th><th>Acte</th><th>Copies</th><th>Statut</th><th>Déposée le</th><th>Motif</th></tr></thead>
    <tbody id="corps"></tbody>
  </table>
  <div style="margin-top:.8rem">
    <button id="prec">« Précédent</button>
    <span id="page-info"></span>
    <button id="suiv">Suivant »</button>
  </div>
</fieldset>

<script>
const $ = (id) => document.getElementById(id);
let page = 1, pages = 1;

function message(texte, ok = false) {
  const m = $("message");
  m.textContent = texte || "";
  m.className = ok ? "ok" : "erreur";
}

async function appel(url, options) {
  const rep = await fetch(url, options);
  const data = await rep.json();
  if (!rep.ok) throw new Error(data.erreur || "Erreur inconnue");
  return data;
}

async function deposer() {
  try {
    const d = await appel("/api/demandes", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        npi: $("dep-npi").value,
        type_acte: $("dep-type").value,
        nombre_copies: Number($("dep-copies").value),
      }),
    });
    $("npi").value = d.npi;
    page = 1;
    await charger();
    message(`Demande n°${d.id} déposée.`, true);
  } catch (e) { message(e.message); }
}

async function charger() {
  const npi = $("npi").value;
  const q = new URLSearchParams({ page, taille: 20 });
  if ($("statut").value) q.set("statut", $("statut").value);
  try {
    const [liste, stats] = await Promise.all([
      appel(`/api/usagers/${encodeURIComponent(npi)}/demandes?${q}`),
      appel(`/api/usagers/${encodeURIComponent(npi)}/statistiques`),
    ]);
    pages = Math.max(liste.pages, 1);
    message("");
    $("stats").textContent = "Total : " + stats.total + " — " +
      Object.entries(stats.par_statut).map(([s, n]) => `${s} : ${n}`).join(" · ");
    const corps = $("corps");
    corps.replaceChildren();
    for (const d of liste.items) {
      const tr = document.createElement("tr");
      const cellules = [d.id, d.type_acte.replaceAll("_", " "), d.nombre_copies, null,
                        new Date(d.date_depot).toLocaleString("fr-FR"), d.motif_rejet || ""];
      for (const c of cellules) {
        const td = document.createElement("td");
        if (c === null) {
          const b = document.createElement("span");
          b.className = "badge " + d.statut; b.textContent = d.statut.replace("_", " ");
          td.appendChild(b);
        } else td.textContent = c;
        tr.appendChild(td);
      }
      corps.appendChild(tr);
    }
    if (!liste.items.length) message("Aucune demande.", true);
    $("page-info").textContent = `Page ${liste.page} / ${pages}`;
  } catch (e) {
    $("corps").replaceChildren(); $("stats").textContent = ""; message(e.message);
  }
}

$("dep-btn").onclick = deposer;
$("liste-btn").onclick = () => { page = 1; charger(); };
$("statut").onchange = () => { page = 1; if ($("npi").value) charger(); };
$("prec").onclick = () => { if (page > 1) { page--; charger(); } };
$("suiv").onclick = () => { if (page < pages) { page++; charger(); } };
</script>
</body>
</html>
