# Parafering en wat stilte betekent

Doelgroep: beheerders die paraferingsroutes inrichten, en medewerkers die een
stap in zo'n route moeten zetten.

Een paraferingsroute is een rij stappen langs een besluit, een document of een
zaak. Elke stap wijst iemand aan, en pas als die stap gezet is gaat de route
verder. Deze pagina beschrijft twee dingen die daar sinds kort bij horen: een
stap kan een regel aanwijzen in plaats van een persoon, en een stap kan zelf
vastleggen wat het betekent als er niets gebeurt.

## 1. Een stap kan een regel aanwijzen

Een stap draagt een persoon of een regel, nooit allebei. De regels zijn:

- `manager-of-subject-owner`: de leidinggevende van degene die het onderwerp
  bezit.
- `manager-of-actor`: de leidinggevende van de persoon die de stap noemt.
- `substitute-of-actor`: de vervanger van die persoon.

De regel wordt pas opgelost op het moment dat de stap aan de beurt is, niet bij
het starten van de route. Een route die in maart begint en in september bij stap
drie aankomt, vraagt dus de leidinggevende van september. Stappen die al getekend
zijn blijven staan zoals ze getekend zijn.

De uitkomst wordt op de stap zelf vastgelegd, samen met welke regel hem opleverde
en wanneer. De route kan daarna nog steeds vertellen waarom juist deze persoon
gevraagd is.

### Als de regel niemand oplevert

Dan gaat de stap niet live en krijgt degene die de route start een foutmelding
die de regel en het onderwerp noemt. Dat geldt ook als de regel twee mensen
oplevert, en als de gevonden persoon geen account heeft op deze installatie.

Er is met opzet geen terugval op de eigenaar van de route of op een beheerder.
Een handtekening van iemand die de route nooit gevraagd heeft, is achteraf niet
te onderscheiden van een echte.

## 2. Wat stilte betekent

Elke stap draagt `onSilence`, met vier mogelijke waarden.

| Waarde | Wat er gebeurt als de termijn verloopt |
| --- | --- |
| `hold` | Niets. De stap blijft open en iemand moet hem alsnog zetten. Dit is de standaard. |
| `approve` | De stap wordt afgerond als akkoord en de route gaat verder. |
| `refuse` | De stap wordt afgerond als afgewezen en de route sluit. |
| `escalate` | De stap gaat naar de leidinggevende van de aangewezen persoon, met een nieuwe termijn, eenmalig. |

`hold` is de standaard, en dat is wat elke bestaande route betekent. Er verandert
dus niets aan routes die al lopen.

`approve` mag alleen een beheerder instellen. Stilte die goedkeurt is een
handtekening die niemand gezet heeft, en of dat acceptabel is, is een keuze van
de organisatie en niet van degene die toevallig een route zit te bewerken.

Een stap zonder termijn verloopt nooit, wat er ook in `onSilence` staat. Een
ontbrekende einddatum is geen verstreken einddatum.

## 3. Wie wordt er gewaarschuwd, en wanneer

Een stap kan `askSubstituteAfter` dragen: een breukdeel van de termijn, tussen 0
en 1. Staat er 0.5 en duurt de termijn tien dagen, dan wordt na vijf dagen ook de
vervanger gevraagd. Beiden krijgen bericht, beiden mogen tekenen, en wie het
eerst tekent sluit de stap. De oorspronkelijke persoon wordt dus niet vervangen,
er komt iemand bij.

Is er geen vervanger te vinden, dan wordt dat op de stap vastgelegd en krijgt de
oorspronkelijke persoon daar bericht van. De termijn en de betekenis van stilte
blijven ongewijzigd. Geen vervanger hebben is een normale situatie en mag de
route niet ophouden.

Verloopt de termijn en treedt er een beleid in werking, dan krijgt degene die
zweeg bericht van wat zijn stilte betekend heeft. Bij `escalate` krijgt ook de
leidinggevende bericht dat de stap nu van hem is.

Alles gebeurt via een achtergrondtaak die elk uur draait. Die taak legt elke
verandering vast als een actie op naam van het systeem, met de gehanteerde regel
erbij, zodat later te lezen is waarom een stap bewoog terwijl niemand tekende.
De taak is veilig om twee keer te draaien: een stap die al verlopen is wordt
overgeslagen.

## 4. Is dit onderwerp rond

Een andere app die een zaak of een dossier wil sluiten, stelt decidiq één vraag:
is alles wat moest tekenen, getekend. Het antwoord is ja of nee, en bij nee noemt
het de route, de stap en de persoon waarop gewacht wordt.

```
GET /apps/decidiq/api/approval-routes/clearance?subject=<uuid>&subjectSchema=<slug>
```

Een route draagt `required`, standaard `true`. Een route die op `false` staat
blokkeert nooit. Een route waarvan niets bekend is, blokkeert wel: een route die
iemand de moeite waard vond om te starten is een route waarop iemand wacht.

Een app die decidiq niet kan bereiken, behandelt het onderwerp als niet rond. Het
ontbreken van een besluitmotor is geen goedkeuring.

Hetzelfde antwoord reist mee op `ApprovalRouteConcludedEvent`, zodat een app die
de uitkomst overneemt er niet apart om hoeft te vragen. Een leeg antwoord op dat
event betekent dat er geen antwoord meegereisd is, niet dat het onderwerp rond
is.
