# Een document laten paraferen

Doelgroep: iedereen die een stuk door een paar collega's wil laten aftekenen, en
de collega's die zo'n verzoek krijgen.

Een paraferingsroute hoeft niet vooraf ingericht te zijn. Je kunt er ook een
maken op het moment dat je hem nodig hebt: dit stuk, deze drie mensen, in deze
volgorde, voor deze datum. Deze pagina beschrijft hoe dat werkt en waar je de
route terugziet.

## 1. Een route maken van een rijtje namen

Je noemt de mensen in de volgorde waarin ze moeten tekenen. Er wordt geen
sjabloon opgeslagen: een beoordeling van één stuk door drie collega's is geen
sjabloon dat iemand hergebruikt, en elk zo'n rijtje bewaren vult het register met
routes die niemand teruggaat lezen.

Wat er wel gebeurt:

- Per persoon ontstaat één stap, genummerd in de volgorde die je opgaf.
- Alleen de eerste stap staat open. De rest wacht.
- De route draagt `origin: adhoc`, zodat een scherm dat geen sjabloon vindt weet
  dat er ook nooit een was.

Iemand die je twee keer noemt, wordt één keer gevraagd. De tweede vraag zou toch
geweigerd worden: na de eerste handtekening is de route die stap al voorbij.

## 2. Eén datum, verdeeld over de stappen

Je geeft één einddatum op voor het geheel. Die wordt verdeeld over de stappen, in
werkdagen.

Een voorbeeld: negen werkdagen over drie stappen zet de stappen op werkdag drie,
zes en negen. De laatste stap valt precies op de einddatum, want die datum is de
afspraak met degene die om de beoordeling vroeg, en die is niet aan ons om te
verschuiven.

Weekenden tellen niet mee. Een stap die op zondag afloopt is te laat voordat er
iemand achter zijn bureau zit, en dan heeft het verdelen van een termijn geen
zin. Feestdagen worden bewust niet meegerekend: die verschillen per land en per
organisatie, en ernaar raden maakt een termijn stilletjes verkeerd.

Geef je geen einddatum op, dan krijgen de stappen geen termijn. Een stap zonder
termijn verloopt nooit.

Een stap die zijn datum voorbij is en waarop nog niets is vastgelegd, wordt als
verlopen getoond. Dat wordt nergens opgeslagen, het wordt bij het lezen bepaald.
Een opgeslagen vlaggetje zou iemand moeten weghalen op het moment dat er getekend
wordt, en zodra dat een keer niet gebeurt staat er iets op het scherm dat niet
waar is.

## 3. Waar je de route ziet

De route verschijnt op het object zelf, in de app waar je het stuk beheert. Dat
werkt via een leaf: decidiq levert twee schermen aan, en het scherm van de
andere app toont ze.

- **Het tabblad** toont de hele route: elke stap, aan wie hij gevraagd is, wat
  diegene gedaan heeft en waarom, en wanneer de stap af moest zijn. Het is
  bewust alleen om te lezen.
- **De widget** toont de stap die nu open staat: welke stap van hoeveel, op wie
  gewacht wordt, en wanneer hij afloopt. Staat de termijn er voorbij, dan zie je
  dat ernaast.

Is het jouw beurt, dan staan er in de widget twee knoppen: akkoord en afwijzen.
Afwijzen kan pas als je een reden hebt ingevuld. Niet uit beleefdheid: degene die
het stuk daarna opent moet weten wat er anders moet, en "afgewezen" zonder meer
stuurt die persoon terug naar jou om het te vragen.

Is het niet jouw beurt, dan zie je de route wel en de knoppen niet.

Dat de knoppen er staan is een kwestie van netheid, niet de beveiliging. Wie
akkoord mag geven wordt op de server bepaald: een actie van iemand die de open
stap niet noemt wordt geweigerd, ook als het verzoek buiten het scherm om komt.

## 4. Voor ontwikkelaars

De leaf heet `decidiq-approval-chain` en is van het soort `render-surface`. Hij
wordt geregistreerd door decidiq's eigen init-bundel, die op elke pagina wordt
meegeladen; er is geen `decidiq-leaves.js` en die is ook niet nodig. Beide
helften van de registratie, de JavaScript en de PHP, verklaren hetzelfde id,
dezelfde label, hetzelfde icoon en dezelfde oppervlakken, en een test vergelijkt
die twee verklaringen rechtstreeks.

Starten, akkoord geven en afwijzen lopen allemaal via decidiq's eigen controller.
De leaf roept niets aan in de app waarin hij getoond wordt, en schrijft nooit
rechtstreeks in het register: dan zou er een tweede motor zijn, zonder een van de
regels die de eerste heeft.

Een andere app kan een route ook aanvragen via de gebeurtenis
`ApprovalRouteRequestedEvent`, met een `actors`-lijst en een `deadline` in plaats
van uitgeschreven stappen. Dat is dezelfde gebeurtenis als voor een route uit een
sjabloon: een tweede gebeurtenis voor dezelfde opdracht zou betekenen dat de
aanvrager moet weten naar welke van de twee deze versie van decidiq luistert.
