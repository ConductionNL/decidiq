---
status: draft
---
# Raadsvergadering Livestream met Automatische Transcriptie

## Placement & Information Architecture

**Placement type:** `DETAIL_TAB` — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).

**Lives at:** Vergaderingen > detail > Live & transcript tab + Live-modus page / Vergaderingen

**Rationale:** Live meeting overlay  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Nederlandse gemeenten zijn wettelijk verplicht om raadsvergaderingen openbaar te maken (Gemeentewet artikel 23). In de praktijk gebeurt dit via livestreams die door griffies worden geleverd via NOTUBIZ, iBabs of Companion-streaming. Het terugkijken van een vergadering kost echter veel tijd: een gemiddelde raadsvergadering duurt 3-4 uur, en burgers, journalisten en belangenorganisaties moeten vaak het hele bestand doorlopen om één agendapunt of één sprekersmoment te vinden. Bovendien zijn de huidige streams meestal niet WCAG-conform (geen closed captions, geen transcript, geen hoofdstuk-navigatie), wat een toegankelijkheidsprobleem vormt voor doven en slechthorenden (ongeveer 1,5 miljoen Nederlanders) en voor mensen die op een andere manier informatie verwerken.

Deze spec voegt aan decidesk een livestream- en transcriptie-laag toe die: (1) de HLS/MPEG-DASH stream van de griffie-aanbieder embed in de decidesk-vergaderpagina, (2) tijdens of na de vergadering automatisch een Nederlandstalig transcript genereert met Whisper-NL (lokaal/zelf-gehost) of de NOTUBIZ ASR-API, (3) sprekers detecteert door koppeling met de microfoonbron uit het zaalsysteem, (4) transcript-segmenten linkt aan agendapunten en aan stemmingen/moties zodat de tijdlijn navigeerbaar is, en (5) het resultaat publiceert als onderdeel van de besluitenlijst met permanent-link-niveau referenties (deeplink naar tijdstempel). De gemeente houdt de archieftermijn (5-10 jaar) zelf in de hand via configureerbare retention policies, conform de Archiefwet en de selectielijst gemeenten en intergemeentelijke organen 2020.

De waarde voor de gemeente zit in drie hoeken: (a) toegankelijkheid en juridische compliance (WCAG 2.2 AA + EU Accessibility Act per juni 2025), (b) journalistieke en burger-transparantie (snel kunnen vinden wat fractie X over onderwerp Y heeft gezegd), en (c) interne efficiency voor de griffie (besluitenlijst-concept kan grotendeels uit transcript-segmenten worden samengesteld in plaats van handmatig uitgewerkt).

## Data Model

De spec introduceert vier nieuwe schema's in het decidesk register, en breidt het bestaande `Vergadering` schema uit met streaming-referenties.

**Schema: `Livestream`** — koppelt een Vergadering aan een streaming-bron. Velden: `id` (uuid), `vergadering` (ref naar Vergadering), `provider` (enum: notubiz, ibabs, companion, vimeo, youtube-live, custom-hls), `streamUrl` (HLS .m3u8 of DASH .mpd), `posterImage` (uri), `status` (scheduled, live, ended, archived), `startedAt` (ISO 8601 timestamp), `endedAt` (ISO 8601 timestamp), `duration` (ISO 8601 duration), `recordingUrl` (uri naar VOD na afloop), `dvrEnabled` (bool — kan tijdens live worden terug-gespoeld), `accessControl` (enum: public, authenticated, embargoed) en `embargoedUntil` (datetime, optioneel voor besloten delen).

**Schema: `Transcript`** — het volledige transcript van één vergadering. Velden: `id` (uuid), `vergadering` (ref), `livestream` (ref), `language` (BCP-47, default `nl-NL`), `engine` (enum: whisper-nl, notubiz-asr, ibabs-asr, human-corrected), `engineVersion` (string), `confidence` (0..1, gemiddelde over alle segmenten), `generatedAt` (datetime), `correctedAt` (datetime, optioneel), `correctedBy` (ref naar User), `status` (enum: pending, processing, draft, published, corrected), `wordCount` (int), `fullText` (text, voor zoeken) en `vtt` (uri naar WebVTT-bestand voor video player closed-captions).

**Schema: `TranscriptSegment`** — atomair transcript-fragment, één per spreker-turn of per natuurlijke pauze. Velden: `id` (uuid), `transcript` (ref), `startTime` (decimal seconden vanaf streamstart), `endTime` (decimal seconden), `speakerLabel` (string, bijvoorbeeld "SPEAKER_03" vóór koppeling), `speaker` (ref naar Persoon, na koppeling), `microfoonId` (string, gerelateerd aan zaalsysteem), `text` (text), `confidence` (0..1), `agendapunt` (ref naar Agendapunt, voor navigatie), `corrected` (bool — handmatig gecorrigeerd), `originalText` (text, alleen gevuld als corrected=true) en `flags` (array enum: inaudible, crosstalk, foreign-language, technical-issue).

**Schema: `Spreker`** — sprekers-koppeling per vergadering (gescheiden van het persoonsregister omdat één persoon meerdere rollen kan hebben). Velden: `id` (uuid), `vergadering` (ref), `persoon` (ref), `rol` (enum: voorzitter, griffier, raadslid, wethouder, burgemeester, inspreker, ambtenaar, gast), `fractie` (ref naar Fractie, optioneel), `microfoonId` (string), `aanwezigVanaf` (datetime), `aanwezigTot` (datetime) en `sprektijd` (ISO duration, geaggregeerd uit segmenten).

Het bestaande `Vergadering` schema wordt uitgebreid met: `livestream` (ref, optioneel), `transcript` (ref, optioneel), `transcriptionPolicy` (enum: none, auto, human-reviewed) en `archiveRetentionYears` (int, default 7).

## Requirements

**REQ-LIVE-001: Livestream-embedding via HLS of MPEG-DASH**
De Vergadering-detailpagina MOET een HLS- of MPEG-DASH-stream kunnen tonen wanneer een `Livestream` is gekoppeld en `status` `live` of `ended` is.
- GIVEN een vergadering met een gekoppelde Livestream met provider `notubiz` en status `live`, WHEN een burger de vergaderingpagina opent, THEN MOET de stream automatisch starten in een WCAG-conforme player met captions-toggle, volume- en speed-control.
- GIVEN een Livestream met status `ended` en gevulde `recordingUrl`, WHEN een gebruiker de pagina opent, THEN MOET het VOD-bestand worden getoond met een hoofdstuk-navigatie per Agendapunt.
- GIVEN een Livestream met `accessControl: embargoed` en `embargoedUntil` in de toekomst, WHEN een niet-geautoriseerde gebruiker de pagina opent, THEN MOET de player een embargo-melding tonen en NIET de stream starten.

**REQ-LIVE-002: Automatische transcriptie binnen 60 minuten na afloop**
Na het einde van een livestream (`status` overgang naar `ended`) MOET het systeem binnen 60 minuten een Transcript met segmenten produceren bij `transcriptionPolicy: auto`.
- GIVEN een Vergadering met `transcriptionPolicy: auto` en een Livestream die `ended` is, WHEN 60 minuten zijn verstreken, THEN MOET er een Transcript bestaan met `status: draft` en minimaal 95% van de duur gedekt door TranscriptSegments.
- GIVEN het ASR-proces faalt drie keer achter elkaar, WHEN de derde poging mislukt, THEN MOET het systeem een notificatie naar de griffier sturen en het Transcript op `status: pending` met een foutmelding in een event-log laten staan.
- GIVEN een Transcript met `engine: whisper-nl`, WHEN het wordt opgeslagen, THEN MOET de gemiddelde `confidence` over alle segmenten minimaal 0.75 zijn (anders flag voor handmatige review).

**REQ-LIVE-003: Sprekerherkenning via microfoonkoppeling**
TranscriptSegments MOETEN aan een specifieke Spreker worden gekoppeld op basis van de microfoonbron uit het zaalsysteem.
- GIVEN een vergadering waarbij microfoon-toewijzingen (`microfoonId` -> `Spreker.persoon`) zijn geregistreerd via de NOTUBIZ-koppeling, WHEN het transcript wordt gegenereerd, THEN MOET elk segment een `speaker`-ref hebben in plaats van alleen een diarisatie-label.
- GIVEN er is geen microfoon-toewijzing beschikbaar (handmatige inspreker), WHEN het transcript wordt gegenereerd, THEN MOET het segment een generieke `speakerLabel` als "SPEAKER_INSPREKER_N" houden en een UI-prompt tonen voor handmatige toewijzing.
- GIVEN twee sprekers spreken tegelijk (crosstalk), WHEN het diarisatie-model dat detecteert, THEN MOET het segment de flag `crosstalk` krijgen en MOET de UI dat visueel markeren.

**REQ-LIVE-004: Timestamping aan agendapunten**
Elk TranscriptSegment MOET aan een Agendapunt worden gekoppeld op basis van de tijdlijn van de voorzitter-aankondigingen.
- GIVEN de voorzitter zegt "we gaan over naar agendapunt 5", WHEN dat segment wordt verwerkt, THEN MOET het systeem het tijdstip vastleggen als startmoment van Agendapunt 5 en alle volgende segmenten tot de volgende agendapunt-aankondiging aan dat punt koppelen.
- GIVEN handmatige correctie van een agendapunt-grens, WHEN een griffier een segment-grens verschuift, THEN MOET de koppeling van alle segmenten in dat bereik automatisch worden herzien.
- GIVEN een Vergadering met 25 agendapunten, WHEN de transcriptie compleet is, THEN MOET minimaal 90% van de agendapunten een correcte starttijd hebben (gemeten t.o.v. de griffie-besluitenlijst).

**REQ-LIVE-005: Closed captions (WCAG 2.2 AA)**
Het systeem MOET WebVTT-bestanden produceren die door de player als closed captions kunnen worden gerenderd.
- GIVEN een Transcript met status `published`, WHEN het WebVTT-bestand wordt opgehaald, THEN MOET het cue-tijden hebben met maximaal 2 regels van 32 karakters per cue conform BBC subtitle guidelines.
- GIVEN een blinde gebruiker met screenreader, WHEN hij de vergaderingpagina opent, THEN MOET de player aankondigen dat captions beschikbaar zijn en MOET de tab-volgorde de player-controls correct doorlopen.
- GIVEN een doof persoon, WHEN hij captions inschakelt, THEN MOETEN sprekersnamen voor elke turn worden getoond ("Voorzitter: ...", "Mw. Jansen (GroenLinks): ...").

**REQ-LIVE-006: Audio description fallback**
Het systeem MOET een audio-only versie aanbieden en een tekstuele beschrijving van non-verbale gebeurtenissen (stemming, applaus) ondersteunen.
- GIVEN een gebruiker met beperkte bandbreedte, WHEN hij kiest voor audio-only, THEN MOET het systeem een MP3- of OGG-stream serveren met dezelfde captions en hoofdstukken.
- GIVEN een stemming vindt plaats, WHEN dat door het Besluit-schema wordt geregistreerd, THEN MOET een TranscriptSegment met type `event` worden toegevoegd ("Stemming over motie M2024-15: 21 voor, 18 tegen, motie aangenomen").
- GIVEN applaus of geroep uit de zaal in de audio, WHEN ASR een non-verbaal segment detecteert, THEN MOET dat als `[applaus]` of `[geroep]` worden gemarkeerd in WebVTT.

**REQ-LIVE-007: Handmatige correctie-workflow voor griffie**
De griffie MOET TranscriptSegments kunnen corrigeren met audit-trail.
- GIVEN een griffier met rol `decidesk-griffier`, WHEN hij een segment opent in correctie-modus, THEN MOET de audio van dat segment afspelen vanaf `startTime`, MOET de bestaande tekst bewerkbaar zijn, en MOET na opslaan `originalText` worden bewaard en `corrected: true` worden gezet.
- GIVEN een gecorrigeerd segment, WHEN het wordt opgeslagen, THEN MOET het Transcript-status van `draft` overgaan naar `corrected` zodra alle segmenten met `confidence < 0.6` zijn nagekeken.
- GIVEN een Transcript met `status: corrected`, WHEN het wordt gepubliceerd, THEN MOET de besluitenlijst-pagina een link naar het transcript bevatten met badge "redactioneel geverifieerd".

**REQ-LIVE-008: Deep-linking naar tijdstempel**
Elke combinatie van vergadering en tijdstempel MOET een permanente URL hebben die de stream op dat moment opent.
- GIVEN een journalist deelt een URL `/vergadering/{id}?t=02:34:15`, WHEN een lezer die opent, THEN MOET de player automatisch naar tijdstempel 02:34:15 springen en daar starten.
- GIVEN een TranscriptSegment, WHEN het in de transcript-viewer wordt aangeklikt, THEN MOET de player naar `segment.startTime` springen.
- GIVEN een Besluit met `genomenOp` tijdstempel, WHEN het in de besluitenlijst wordt getoond, THEN MOET er een "bekijk moment van besluit"-link zijn die naar het juiste tijdstip linkt.

**REQ-LIVE-009: Zoeken in transcript-content**
Het transcript MOET volledig-tekst doorzoekbaar zijn binnen één vergadering en over alle vergaderingen heen.
- GIVEN een burger zoekt op term "windturbine" binnen één vergadering, WHEN hij de zoekactie uitvoert, THEN MOET het systeem alle TranscriptSegments tonen met die term, gegroepeerd per spreker, met snippet-context van 20 woorden voor en na.
- GIVEN een burger zoekt fleet-wide op "windturbine", WHEN hij de zoekactie uitvoert, THEN MOET het systeem matches over alle gepubliceerde transcripts tonen, gefacetteerd op gemeente, fractie en jaar.
- GIVEN een zoekopdracht met fractie-filter "PvdA" en termijn `2024`, WHEN de zoek wordt uitgevoerd, THEN MOET alleen segmenten van PvdA-sprekers in 2024-vergaderingen worden teruggegeven.

**REQ-LIVE-010: Archief-retentie en verwijdering**
Vergaderingen MOETEN volgens de gemeentelijke retention policy worden bewaard of verwijderd.
- GIVEN een Vergadering met `archiveRetentionYears: 7` en `startedAt` 8 jaar geleden, WHEN de retention-cron-job draait, THEN MOET de Vergadering met haar Livestream, Transcript en TranscriptSegments worden gemarkeerd voor verwijdering en na 30 dagen grace-period worden verwijderd.
- GIVEN een Vergadering die onderdeel is van een lopende WOB/WOO-procedure, WHEN de retention-cron draait, THEN MOET de verwijdering worden uitgesteld en MOET een log-entry "retention-hold-WOO" worden aangemaakt.
- GIVEN een verwijderingsactie, WHEN deze is uitgevoerd, THEN MOET de besluitenlijst-pagina blijven bestaan met een metadata-stub ("video en transcript verwijderd op {datum} conform retention policy") maar zonder de mediafiles.

## Standards & Sources

- **WCAG 2.2 AA** — Web Content Accessibility Guidelines, paragraaf 1.2.2 (Captions Prerecorded), 1.2.4 (Captions Live), 1.2.5 (Audio Description Prerecorded). Verplicht via Tijdelijk besluit digitale toegankelijkheid overheid (gebaseerd op EU richtlijn 2016/2102) en straks EU Accessibility Act (juni 2025).
- **EBU-TT-D** en **WebVTT** — caption-formaten; WebVTT is industriestandaard voor HTML5 video.
- **HLS (RFC 8216)** en **MPEG-DASH (ISO/IEC 23009-1)** — streaming-protocollen die NOTUBIZ en iBabs gebruiken.
- **Gemeentewet artikel 23** — openbaarheid raadsvergaderingen.
- **Archiefwet 1995** en de **Selectielijst gemeenten en intergemeentelijke organen 2020** — bepalen retention. Raadsvergaderingen vallen onder "permanent te bewaren" voor de besluitenlijst, video/audio is in praktijk 7-10 jaar afhankelijk van gemeentelijke selectielijst-uitvoeringsregeling.
- **AVG artikel 6 lid 1 sub e** (taak van algemeen belang) als grondslag voor het verwerken van persoonsgegevens van sprekers; insprekers krijgen vóór hun bijdrage een privacy-notice conform AVG artikel 13.
- **Whisper-NL** — Universiteit Twente / Common Voice fork van OpenAI Whisper, getraind op Nederlandse parlementaire en gemeentelijke data. Alternatieven: NOTUBIZ Spraak (commerciële API), Microsoft Azure Speech (cloud, EU-gehost), Deepgram Nova-2.
- **NOTUBIZ API documentation** — endpoint `/api/v1/meetings/{id}/livestream`, `/api/v1/meetings/{id}/transcript`. Microfoon-events worden gepubliceerd via SSE op `/api/v1/meetings/{id}/events`.
- **iBabs API documentation** — `meetings.getLiveStreamUrl(meetingId)`, `meetings.getMicrophoneActivity(meetingId)`.
- **W3C Media Fragments URI** (`#t=` syntax) — deeplinking naar tijdstempels.

## Cross-app integration

- **decidesk** (base) — eigenaar van `Vergadering`, `Agendapunt`, `Besluit`, `Stemming`, `Fractie`, `Persoon`. De nieuwe schema's `Livestream`, `Transcript`, `TranscriptSegment` en `Spreker` worden in het decidesk-register geregistreerd.
- **openconnector** — levert de adapters voor NOTUBIZ en iBabs (zie spec `notubiz-ibabs-griffie-koppeling`). De adapter publiceert events op `decidesk.vergadering.livestream.started/ended` en `decidesk.vergadering.microfoon.activated/deactivated` die deze spec consumeert om Spreker-koppelingen op te bouwen.
- **openregister** — host voor de schema-definities; full-text-search-index gebruikt voor REQ-LIVE-009.
- **opentalk** (optioneel) — Talk-integratie voor live commentaar tijdens livestream voor raadsleden achter de schermen; niet in scope voor v1.
- **docudesk** — als de besluitenlijst als PDF wordt gegenereerd, MOET docudesk het transcript-uittreksel kunnen embedden via een verwijzing naar de Transcript-uri.
- **launchpad** — KPI's: aantal vergaderingen per maand met transcript, gemiddelde transcript-confidence, gemiddelde correctie-tijd griffie, aantal deep-link-shares.

## Target users

- **Burgers** (primair) — willen snel weten wat hun raad over een specifiek onderwerp heeft besloten, zonder 3 uur video te kijken.
- **Journalisten** — gebruiken zoek + deep-link om quotes voor artikelen te vinden.
- **Belangenorganisaties** (Greenpeace, lokale wijkverenigingen) — monitoren besluitvorming over hun thema's; willen alerts bij genoemde keywords.
- **Doven, slechthorenden, niet-Nederlands-natives** — afhankelijk van captions voor toegang tot het democratisch proces.
- **Raadsleden zelf** — willen kunnen verifiëren wat ze precies hebben gezegd, en quotes makkelijk delen op socials.
- **Griffies** — willen sneller besluitenlijsten kunnen produceren, met audit-trail op correcties.
- **Onderzoekers** (universiteiten, Rekenkamer, Algemene Rekenkamer) — willen longitudinaal kunnen analyseren wat in raden wordt besproken; fleet-wide zoek essentieel.
- **Toezichthouders** (provincie, BZK) — voor naleving van wettelijke toegankelijkheidseisen.
- **Archivarissen en historici** — gebruiken het langetermijn-archief van transcript + audio voor naslag; de combinatie van full-text-zoek en exacte tijdstempels maakt onderzoek dat voorheen weken kostte tot een operatie van minuten.
- **Onderwijs (HBO/WO bestuurskunde, journalistiek)** — gebruiken transcripts als realistisch lesmateriaal over politieke besluitvormingsprocessen; docenten kunnen studenten specifieke fragmenten aanwijzen met deeplink in plaats van complete vergaderingen te delen.

## Implementatie-overwegingen

De spec heeft drie scherpe ontwerpkeuzes die expliciet moeten worden gemotiveerd in de architectuurreview:

**Keuze 1: Whisper-NL boven NOTUBIZ-ASR als default.** NOTUBIZ-ASR is gemakkelijker (geen eigen infra), maar koppelt de gemeente verder aan de vendor en de kwaliteit is voor minder bekende sprekers slechter dan Whisper-large-v3-NL (eigen evaluatie gemeente Utrecht 2025: WER 12% vs 18%). Whisper-NL draait lokaal op een GPU-node (single A100 doet 1 vergadering in real-time of ongeveer 8x sneller bij batch-verwerking), en valt onder eigen AVG-grondslag. Bij gemeenten zonder GPU-capaciteit is NOTUBIZ-ASR een acceptabel fallback-pad via configuratie.

**Keuze 2: Sprekerherkenning via microfoonkoppeling boven voice-fingerprinting.** Voice-fingerprinting (zoals pyannote.audio) heeft AVG-implicaties (biometrisch persoonsgegeven onder artikel 9). De microfoonkoppeling vermijdt dat probleem volledig — het is een operationele koppeling, geen biometrie. Voor insprekers zonder vaste microfoon is een fallback nodig (handmatige toewijzing), maar dat is een acceptabele beperking voor een belangrijke privacy-winst.

**Keuze 3: WebVTT boven SRT of EBU-TT-D.** WebVTT is industrie-standaard voor HTML5 (geen externe player nodig), heeft native styling-support voor sprekersnamen en regio's, en is omzetbaar naar SRT/EBU-TT-D bij export. SRT mist styling-support; EBU-TT-D is overkill voor het primaire gebruik (web-streaming).

## Out-of-scope (toekomstige iteraties)

Niet in deze v1: real-time captions tijdens livestream (vereist streaming-ASR-pipeline, complexer maar wel een natuurlijk vervolg); automatische samenvattingen per agendapunt via LLM (eigen spec onder ADR-034 AI-companion); vertaling naar Engels of vereenvoudigd Nederlands (Begrijpelijke Taal B1) — natuurlijke vervolgspec wanneer de transcript-pipeline stabiel is; sentiment-analyse en interruptie-statistieken per fractie (onderzoekstoepassing, mogelijk via separate analytics-spec); spreker-stemming (welke fractie stemde voor/tegen) automatisch uit het transcript afleiden — kruisspec met de NOTUBIZ/iBabs-koppeling, waar die data al gestructureerd binnenkomt; embedding van geo-data (locatie van een raadszaal, route naar publieksingang) — buiten scope, beter via opencatalogi gemeenteloket.

## Risico's en mitigaties

**Risico: ASR-fouten leiden tot onjuiste citaties.** Een verkeerd herkende quote kan reputatieschade voor een raadslid veroorzaken. Mitigatie: confidence-threshold zichtbaar in UI ("dit fragment heeft 62% zekerheid — kan onjuist zijn"); verplichte griffie-review-flag bij segmenten met `confidence < 0.7`; juridische disclaimer in transcript-footer ("transcript is automatisch gegenereerd en kan fouten bevatten; raadpleeg de officiële audio bij twijfel").

**Risico: AVG-grondslag voor inspreker-transcripts onduidelijk.** Insprekers zijn vaak burgers in kwetsbare positie (klagers, slachtoffers van bestuurlijke fouten). Mitigatie: separate retention-policy voor inspreker-segmenten (default 2 jaar i.p.v. 7); recht-op-vergetelheid-workflow met directe verwijdering binnen 30 dagen na verzoek; expliciete vermelding in privacy-notice vóór inspreker zijn bijdrage doet.

**Risico: Performance-impact op gemeenten zonder eigen GPU.** Whisper-NL vergt aanzienlijke rekenkracht. Mitigatie: ondersteuning voor gedeelde infra (meerdere gemeenten op één GPU-node via een Conduction-managed service); fallback naar NOTUBIZ-ASR voor gemeenten zonder eigen capaciteit; batch-verwerking 's nachts in plaats van real-time-vereiste.

**Risico: Toezegging op WCAG 2.2 die in praktijk niet wordt gehaald.** Captions van slechte ASR-kwaliteit voldoen formeel maar feitelijk niet aan WCAG. Mitigatie: meten van caption-kwaliteit als KPI (WER + readability score); commitment dat publicatie wacht op `corrected`-status bij gemeenten die menselijke review opnemen in hun proces.
