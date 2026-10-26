# OpenAP Interface Design System

Stato: riferimento visivo di progetto
Data di definizione iniziale: 13 agosto 2026
Ultimo aggiornamento della baseline: 25 agosto 2026
Riferimento di sviluppo: branch `mockup/advanced-network-topology`

## 1. Obiettivo

Questo documento definisce le regole comuni da usare per creare o modificare
componenti dell'interfaccia OpenAP.

L'obiettivo non e' produrre una dashboard amministrativa generica. Ogni
sezione deve apparire come un modulo funzionale appartenente a un dispositivo
OpenAP: compatto, tecnico, leggibile e immediatamente riconoscibile.

La direzione estetica combina:

- l'aspetto del pannello frontale di un router;
- una struttura modulare e densa, con una leggera atmosfera simile a Winamp;
- una gerarchia chiara tra contenitore, componente, etichetta, valore e stato;
- un'identita' propria, senza imitare direttamente altri prodotti.

## 2. Principio fondamentale

Ogni nuova interfaccia deve essere costruita, quando applicabile, con tre
livelli visivi:

1. **Section Header**: identifica la funzione principale.
2. **Outer Panel**: contiene e raggruppa la funzione.
3. **Inner Components**: rappresentano dati, controlli, opzioni e stati.

Schema concettuale:

```text
Section Header
└── Outer Panel
    ├── Inner Component
    ├── Inner Component
    └── Status / Actions
```

Il layout esistente non deve essere modificato quando il compito richiede
soltanto l'applicazione dello stile.

## 3. Section Header

Il Section Header e' la barra verde che introduce una sezione della dashboard
o l'intestazione equivalente di un modal.

Anatomia raccomandata:

```text
[icona] TITOLO — descrizione                         [stato/azioni]
```

Regole:

- gradiente principale: `linear-gradient(90deg, #0b4e50, #16847f)`;
- titolo bianco, marcato e normalmente maiuscolo;
- descrizione secondaria piu' piccola, bianca con opacita' ridotta;
- icona integrata in una piccola cella dedicata;
- azioni e badge allineati a destra;
- altezza compatta;
- bordo petrolio e angoli coerenti con la sezione;
- il Section Header identifica la sezione, ma non deve contenere il corpo dei
  dati.

Le intestazioni dei modal conservano una gerarchia maggiore rispetto ai testi
interni e non devono essere ridotte alla dimensione delle metriche.

### 3.1 Main Header di riferimento

Il riferimento visivo ufficiale per il **Main Header** e' l'intestazione
`Network Topology and Operational Mode` mostrata in `main_header.png`.
Le indicazioni di questa sottosezione riguardano esclusivamente il lato
sinistro del componente: superficie, icona, titolo e sottotitolo. Stato,
badge e azioni collocati a destra non fanno parte di questo riferimento.

Anatomia:

```text
[icona] TITOLO
        Sottotitolo
```

Caratteristiche comuni ai temi light e dark:

- header indipendente, largo quanto la sezione che introduce;
- altezza di riferimento circa `54-62px`, con contenuto centrato
  verticalmente;
- padding indicativo `9px 12px`;
- bordo `1px solid rgba(22, 132, 127, .5)`;
- raggio esterno tipico `10px`;
- sfondo petrolio con gradiente
  `linear-gradient(90deg, #0b4e50, #16847f)`;
- ombra minima o assente: il bordo deve definire il componente;
- icona e blocco testuale allineati orizzontalmente, con gap di circa `9px`;
- cella icona quadrata di circa `32-36px`, con raggio `8-9px`;
- il contenuto deve rimanere leggibile e compatto anche quando il titolo e'
  lungo.

Titolo:

- colore bianco `#fff` in entrambi i temi;
- dimensione canonica desktop e tablet: **`13px`**;
- unica riduzione ammessa: **`12px` sotto `400px`**;
- peso `800`;
- `line-height` compatta, circa `1.2`;
- `letter-spacing: .3px`;
- normalmente in maiuscolo (`text-transform: uppercase`);
- una sola riga quando lo spazio disponibile lo consente.

La dimensione del Main Header non e' un valore indicativo da ricalcolare per
pagina: `13px` e' la baseline condivisa. Non usare la misura `10px` dei
Section Header secondari e non ereditare eventuali regole compatte dal
contenitore riutilizzato (per esempio WiFi Hotspot dentro AP Configuration o
DHCP Setting). Se un componente assume il ruolo di primo header visibile,
deve applicare esplicitamente la misura canonica del Main Header.

Sottotitolo:

- disposto sotto il titolo, non sulla stessa linea;
- margine superiore indicativo `2px`;
- dimensione indicativa `9-10px`;
- colore bianco attenuato, per esempio
  `rgba(255, 255, 255, .72)`;
- peso inferiore al titolo e nessuna trasformazione automatica in maiuscolo;
- descrizione breve della funzione della sezione, non uno stato operativo.

Variante light:

- il gradiente, il bordo e i testi restano quelli comuni;
- cella icona con sfondo bianco;
- icona blu tecnico, riferimento `#1e3a8a` o `#1e5eb8`;
- la cella chiara crea il contrasto principale rispetto al gradiente.

Variante dark:

- il gradiente, il bordo e i testi restano quelli comuni: il Main Header non
  deve trasformarsi in una superficie nera piatta;
- cella icona con fondo scuro petrolio, riferimento `#182a2d`;
- bordo cella `rgba(85, 195, 187, .32)`;
- icona verde chiaro, riferimento `var(--primary)` / `#55c3bb`;
- evitare sfondo bianco e icona blu nella cella del tema dark.

Il Main Header identifica la funzione principale e deve rimanere visivamente
distinto dall'Outer Panel sottostante. Non deve duplicare un'altra
intestazione immediatamente all'interno del pannello.

In ogni pagina il **Main Header e' esclusivamente il primo header visibile**.
Tutti gli header che seguono nella stessa pagina sono **Section Header
secondari** e devono conservare altezza, icona, titolo, sottotitolo e spaziature
compatte previste per tale livello. Questa gerarchia vale allo stesso modo su
desktop e mobile e non cambia tra tema light e tema dark.

Implementazione normativa corrente:

- il Main Header deve usare la classe condivisa `.openap-page-main-header`;
- il componente di riferimento effettivo e' il Main Header della Dashboard:
  bordo, gradiente, raggio, cella icona e tipografia devono coincidere;
- cambiano esclusivamente titolo, sottotitolo e icona pertinenti alla pagina;
- badge, stati e azioni sono contenuti opzionali e specifici della pagina: non
  devono alterare il trattamento comune del lato titolo;
- la struttura corrente riusa direttamente il blocco Dashboard mediante
  `.openap-dashboard-main-header` e la fascia inferiore
  `.openap-dashboard-header-status-row`: la fascia ospita il
  sottotitolo e, quando presenti, stati, badge o azioni pertinenti alla pagina;
- la fascia non deve contenere dati inventati: nelle pagine prive di stati
  operativi rimane il solo sottotitolo descrittivo;
- sotto `400px` il titolo usa la variante compatta da `12px`; eventuali testi
  della fascia di stato della Dashboard usano `8.5px`.

## 4. Outer Panel

L'Outer Panel e' la cornice funzionale sotto il Section Header.

Regole generali:

- bordo: `1px solid rgba(18, 104, 105, .48)`;
- bordo superiore, quando previsto: `3px solid #126869`;
- raggio esterno tipico: `12px`;
- ombra molto discreta, mai decorativa;
- padding desktop tipico: `9px`;
- sfondo distinto dalle celle interne;
- nessuna intestazione duplicata se il Section Header e' gia' presente.

Nel tema dark, la superficie di riferimento concordata per le aree libere dei
modal e':

```css
background: #1a2628;
```

Questo colore deve essere applicato alla superficie che circonda i componenti,
non automaticamente alle celle interne.

## 5. Inner Components

Le celle interne contengono una singola unita' informativa o operativa.

Anatomie principali:

```text
[icona] ETICHETTA
        Valore
```

```text
[icona] Nome componente                         [stato]
        Descrizione
```

Regole:

- sfondo piu' scuro della superficie esterna nel tema dark;
- riferimento dark corrente: `var(--bg)`;
- bordo tipico: `1px solid rgba(85, 195, 187, .26)`;
- raggio inferiore a quello dell'Outer Panel, normalmente `8px` o `9px`;
- icona in una cella dedicata con bordo petrolio;
- niente ombre pesanti;
- stato selezionato riconoscibile tramite bordo, lieve tinta verde e, quando
  utile, un sottile accento superiore;
- hover piu' chiaro dello stato normale, ma meno evidente dello stato attivo.

Le celle ripetute nello stesso gruppo devono avere anatomia, altezza, padding e
allineamenti uniformi.

## 6. Sistema tipografico principale

La tipografia interna usa principalmente la coppia visiva rappresentata dalle
metriche **CHANNEL** e **36** della sezione WiFi Hotspot.

La famiglia tipografica di riferimento dell'interfaccia applicativa e'
`Rajdhani`, con fallback `DejaVu Sans`, `Arial`, `sans-serif`. Deve essere
usata nel menu, nei Main/Section Header, nella Dashboard, nell'area widget e
nelle pagine AP Configuration, DHCP Setting, Logging, System e About OpenAP.
L'applicazione del font non autorizza a cambiare dimensioni, pesi o geometria:
la gerarchia esistente va conservata. Font Awesome e gli altri font di icone
mantengono sempre la propria famiglia dedicata.

I modal applicativi seguono la stessa famiglia. Il contenitore del modal e i
suoi controlli devono dichiarare Rajdhani quando il contenuto viene caricato
dinamicamente fuori dallo scope della pagina; `#apEthernetModal` e' il
riferimento per Ethernet Mode.

Le superfici che mostrano output da terminale o journal costituiscono
un'eccezione funzionale: devono usare una pila monospaziata stabile
`"DejaVu Sans Mono", "Liberation Mono", Consolas, monospace`, con legature
disattivate. La pagina Logging usa Rajdhani per header, tab, toolbar e azioni,
ma `.openap-log-output` conserva sempre il font terminale per mantenere
allineamento, scansione dei timestamp e leggibilita' dei log.

### 6.1 Stile CHANNEL

Usare per:

- etichette;
- metadati;
- descrizioni compatte;
- nomi delle proprieta';
- didascalie tecniche.

Valori:

```css
font-size: 9px;
font-weight: 650;
line-height: 1.2;
letter-spacing: .25px;
text-transform: uppercase;
color: var(--text-light);
```

Nel tema light `--text-light` usa `#7f919d`: il CHANNEL deve risultare
discreto ma chiaramente leggibile sui fondi chiari. Il tema dark conserva la
propria variabile dedicata e non deve ereditare questa tonalita'.

### 6.2 Stile 36

Usare per:

- valori;
- nomi delle reti;
- opzioni principali;
- nomi dei componenti;
- risultati e dati operativi.

Valori:

```css
font-size: 11px;
font-weight: 750;
line-height: 1.2;
letter-spacing: 0;
text-transform: none;
color: var(--text);
```

### 6.3 Eccezioni

Possono mantenere una gerarchia diversa:

- titoli di pagina;
- Section Header;
- intestazioni dei modal;
- messaggi di errore o conferma importanti;
- numeri di stato che richiedono particolare evidenza.

Le eccezioni devono essere intenzionali e non introdotte da stili inline
casuali.

## 7. Palette e ruoli cromatici

Il colore deve comunicare un ruolo.

| Ruolo | Colore o trattamento |
|---|---|
| Canvas pagina light | `#fff` |
| Superficie pannello light | `var(--bg)` / `#eef3f5` |
| Superficie interna tenue light | `#f4f7f8` |
| Campo informativo light | `#fff` |
| Footer widget light | `#d8ece9` |
| Superficie modal dark | `#1a2628` |
| Cella interna dark | `var(--bg)` |
| Verde principale | `#16847f` |
| Save Action light: fondo | `#16847f` |
| Save Action dark: fondo | `#1c827e` |
| Verde chiaro / primary dark | `#55c3bb` |
| Cornice forte | `rgba(18, 104, 105, .48)` |
| Divisore light | `rgba(18, 104, 105, .24)` |
| Cornice interna dark | `rgba(85, 195, 187, .26)` |
| Info light: fondo | `#f3f6fb` |
| Info light: bordo | `#c9d8ec` |
| Info light: testo | `#3d5873` |
| Info dark: fondo | `#10293a` |
| Info dark: bordo | `#294e68` |
| Info dark: testo | `#a9c3d3` |
| Testo principale | `var(--text)` |
| Testo secondario | `var(--text-light)` o `var(--text-muted)` |
| Testo di supporto ad alta leggibilita' | `var(--openap-text-support-strong)` (`#111820` light / `#fff` dark) |
| Stato positivo | verde |
| Avviso | ambra |
| Errore | rosso |
| Inattivo | grigio |

Non usare colori diversi per elementi con lo stesso ruolo senza una ragione
funzionale.

## 8. Icone

Regole:

- icone sempre integrate nella struttura del componente;
- cella icona con bordo coerente alle altre celle;
- nel tema dark usare verde/petrolio, non bianco pieno;
- dimensioni ricorrenti: circa `30px` per celle interne e `34px` per header;
- evitare icone puramente decorative se non aiutano la scansione visiva;
- icone equivalenti devono mantenere dimensioni e allineamento equivalenti.

## 9. Stati interattivi

Stati minimi da prevedere:

- normale;
- hover/focus;
- selezionato/attivo;
- disabilitato;
- successo;
- avviso;
- errore.

Regole:

- il focus deve essere visibile anche da tastiera;
- lo stato attivo non deve dipendere soltanto dal colore del testo;
- usare una combinazione di bordo, fondo e indicatore;
- evitare animazioni decorative continue;
- rispettare `prefers-reduced-motion`;
- gli aggiornamenti live non devono spostare il layout senza necessita'.

## 9.1 Sezioni Info

Le sezioni Info sono callout compatti destinati a spiegazioni operative,
conseguenze non bloccanti, note di compatibilita' e indicazioni contestuali.
Il riferimento visivo corrente e' il componente mostrato nella pagina AP
Configuration, per esempio:

- la nota sull'attivazione delle bande e la breve disconnessione dei client;
- la nota sulla compatibilita' WPA2-PSK con AES/CCMP.

Classi di riferimento esistenti:

```css
.openap-info-badge
.openap-config-info-badge
```

Anatomia:

```text
[icona info] Testo informativo breve e direttamente collegato al controllo
              o alla sezione precedente.
```

Regole strutturali:

- usare `inline-flex` quando il messaggio deve occupare solo lo spazio
  necessario;
- consentire `max-width: 100%` per evitare overflow;
- icona a sinistra e testo a destra;
- allineamento verticale centrato per messaggi brevi;
- `gap: 8px`;
- padding tipico: `8px 10px`;
- bordo: `1px solid`;
- raggio tipico: `8px`;
- distanza dal contenuto precedente: normalmente `10px`;
- testo compatto ma leggibile: `11px`, peso `700`, line-height `1.35`;
- icona circolare `fa-info-circle` o equivalente, circa `15px`, dentro una
  colonna riservata di circa `16px`;
- il testo puo' andare a capo; non applicare ellissi a una spiegazione
  operativa.

### 9.1.1 Tema light

Valori di riferimento:

```css
.openap-info-badge,
.openap-config-info-badge {
  border-color: #c9d8ec;
  background: #f3f6fb;
  color: #3d5873;
}

.openap-info-badge i,
.openap-config-info-badge i {
  color: #2563a6;
}
```

Nel tema light il callout deve apparire azzurro-grigio, chiaramente separato
dal fondo ma non piu' importante del controllo o del valore a cui si riferisce.

### 9.1.2 Tema dark

Valori di riferimento:

```css
html[data-bs-theme="dark"] .openap-info-badge,
html[data-bs-theme="dark"] .openap-config-info-badge {
  border-color: #294e68;
  background: #10293a;
  color: #a9c3d3;
}

html[data-bs-theme="dark"] .openap-info-badge i,
html[data-bs-theme="dark"] .openap-config-info-badge i {
  color: var(--primary, #55c3bb);
}
```

Nel tema dark il fondo Info e' blu-petrolio e deve restare distinto sia dalla
superficie libera `#1a2628` sia dalle celle interne `var(--bg)`. L'icona verde
chiaro collega il callout al linguaggio cromatico OpenAP senza trasformarlo in
uno stato positivo.

### 9.1.3 Semantica e uso corretto

Usare una sezione Info per:

- spiegare cosa accadra' applicando una configurazione;
- descrivere una limitazione o una compatibilita' non critica;
- fornire contesto a un campo o a un gruppo di controlli;
- indicare un comportamento temporaneo previsto.

Non usarla per:

- errori che impediscono il completamento dell'azione;
- avvisi con rischio concreto o possibile perdita di connettivita' non
  reversibile;
- conferme di successo;
- semplici decorazioni o testo ridondante gia' espresso dall'etichetta.

Per questi casi usare rispettivamente gli stati Error, Warning o Success. Il
callout Info non deve lampeggiare, animarsi o sembrare un pulsante. Se contiene
un collegamento o un'azione, l'elemento interattivo deve essere chiaramente
distinguibile e accessibile da tastiera.

## 10. Pulsanti e azioni

Regole:

- azione primaria: fondo `#16847f`, testo bianco;
- azione secondaria: fondo della cella o `var(--bg)`, bordo petrolio e testo
  attenuato;
- azioni distruttive: rosso, senza confonderle con l'azione primaria;
- dimensioni compatte e coerenti;
- icona e testo devono restare centrati;
- una modifica di stile non deve modificare l'ordine o la semantica delle
  azioni.

### 10.1 Pulsante Save

Tutte le azioni che salvano o applicano una configurazione devono usare la
variante primaria **Save Action**. Il riferimento visivo e' il pulsante Save
del modal AP via Ethernet.

Anatomia obbligatoria:

```text
[icona salvataggio] Save
```

Regole comuni:

- icona sempre a sinistra del testo;
- usare esclusivamente l'icona `fa-floppy-disk` per le azioni Save;
- non sostituire `fa-floppy-disk` con `fa-check`, `fa-save`, `fa-upload`,
  `fa-cloud-arrow-up` o altre icone;
- non usare un'icona generica o non collegata all'azione;
- icona e testo devono appartenere allo stesso pulsante, non a celle separate;
- disposizione: `inline-flex`, centrata verticalmente e orizzontalmente;
- distanza icona/testo: `4px`;
- altezza compatta di riferimento: `28px`;
- padding orizzontale: `10px`;
- raggio: `6px`;
- font: `11px`, coerente con lo stile 36;
- testo breve e operativo: **Save**, **Apply**, **Apply configuration** o
  equivalente contestuale;
- usare un solo Save Action primario per ogni gruppo di azioni;
- collocarlo normalmente a destra, dopo Cancel o le azioni secondarie;
- non cambiare ordine, tipo `submit`, associazione al form o logica durante un
  intervento esclusivamente visivo.

Markup di riferimento:

```html
<button type="submit" class="btn-ss primary openap-save-action">
  <span class="spinner-border spinner-border-sm d-none openap-save-spinner"
        role="status" aria-hidden="true"></span>
  <i class="fas fa-floppy-disk openap-save-icon" aria-hidden="true"></i>
  <span>Save</span>
</button>
```

Se il testo visibile descrive gia' l'azione, l'icona deve essere decorativa con
`aria-hidden="true"`. Se il pulsante mostra soltanto un'icona, deve avere un
`aria-label`, ma per le azioni Save e' preferibile conservare anche il testo.

### 10.1.1 Tema light

Valori di riferimento:

```css
.openap-save-action,
.btn-ss.primary {
  border: 1px solid #16847f;
  background: #16847f;
  color: #fff;
}

.openap-save-action:hover,
.btn-ss.primary:hover {
  border-color: #0b6462;
  background: #0b6462;
  color: #fff;
}
```

Nel tema light il riferimento normativo e' il colore CSS effettivo del pulsante
Save nel modal AP Ethernet: `#16847f`. Non ricavare il valore light campionando
uno screenshot del tema dark.

Nel tema light il pulsante deve essere chiaramente primario, ma non deve
ricorrere a gradienti, ombre pesanti o colori fluorescenti.

### 10.1.2 Tema dark

Valori di riferimento:

```css
html[data-bs-theme="dark"] .openap-save-action,
html[data-bs-theme="dark"] .btn-ss.primary {
  border: 1px solid #1c827e;
  background: #1c827e;
  color: #fff;
}

html[data-bs-theme="dark"] .openap-save-action:hover,
html[data-bs-theme="dark"] .btn-ss.primary:hover {
  border-color: #55c3bb;
  background: #126f6c;
  color: #fff;
}
```

Nel tema dark il verde pieno `#1c827e` deve emergere sulle superfici `#1a2628` e
`var(--bg)`. L'icona e il testo restano bianchi; non usare il verde chiaro per
il testo interno del pulsante.

I due valori sono intenzionalmente distinti:

- tema light: `#16847f`, come il modal AP Ethernet;
- tema dark: `#1c827e`, come il riferimento visivo dark in `ap.png`.

Non usare una singola regola non circoscritta per sovrascrivere entrambi i
temi.

### 10.1.3 Focus, disabled e loading

Focus da tastiera:

```css
.openap-save-action:focus-visible,
.btn-ss.primary:focus-visible {
  outline: 0;
  box-shadow: 0 0 0 4px rgba(85, 195, 187, .28);
}
```

Regole di stato:

- `disabled`: ridurre l'opacita', mantenere testo leggibile e impedire il
  puntatore attivo;
- `loading`: lo spinner e' obbligatorio per ogni operazione Save asincrona o
  che non produce una risposta immediata;
- durante il loading nascondere temporaneamente `fa-floppy-disk` e mostrare lo
  spinner nella stessa posizione, a sinistra del testo;
- durante il loading aggiornare il testo, per esempio **Saving...**;
- evitare variazioni di larghezza evidenti: prevedere spazio sufficiente per
  il testo di caricamento;
- non mostrare contemporaneamente `fa-floppy-disk` e spinner;
- disabilitare il pulsante durante il salvataggio per impedire invii multipli;
- lo spinner deve usare `role="status"`; se il testo cambia in **Saving...**,
  puo' essere `aria-hidden="true"` per evitare annunci duplicati;
- il successo finale deve essere comunicato anche fuori dal colore del
  pulsante, tramite messaggio, stato o aggiornamento dell'interfaccia.

## 11. Spaziatura

Usare una scala compatta basata principalmente su multipli di 4px:

| Valore | Uso tipico |
|---|---|
| `4px` | micro-spazi, icona/testo |
| `8px` | distanza tra celle o righe |
| `9–10px` | padding dei pannelli |
| `12px` | gruppi importanti |
| `16px` | separazione tra sezioni |
| `24px` | spazi strutturali eccezionali |

Evitare valori arbitrari quando un valore della scala produce lo stesso
risultato.

## 12. Griglia e composizione

- una colonna per riepiloghi e flussi stretti;
- due colonne per confronti o interfacce parallele;
- tre colonne per metriche omogenee;
- righe ripetute per bande, radio, reti e interfacce;
- consentire a una cella di espandersi senza perdere anatomia e padding;
- usare CSS Grid o Flexbox secondo la relazione tra i dati, non per semplice
  comodita'.

## 13. Responsive e mobile

Regole:

- mobile ordinato e deterministico;
- nessun drag-and-drop nella visualizzazione mobile;
- preservare la gerarchia titolo -> indicatore -> dettaglio;
- troncare il testo soltanto quando lo spazio disponibile e' realmente
  insufficiente;
- usare `text-overflow: ellipsis` sul singolo testo, non sull'intero gruppo;
- linee e indicatori devono restare centrati rispetto ai nodi;
- non ridurre i font sotto la gerarchia CHANNEL/36 senza una necessita'
  verificata su schermi reali;
- i target interattivi devono restare utilizzabili al tocco.

## 14. Network Topology and Operational Mode

Questa sezione rappresenta il riferimento piu' caratteristico del design.

Deve evocare il pannello frontale di un router:

- pallini come LED di stato;
- testo principale sopra il LED su schermi piccoli;
- dettaglio sotto il LED;
- linee centrate tra i LED;
- selettore AP Ethernet / Repeater Mode integrato nel pannello;
- contenitore comune senza divisori superflui;
- geometria compatta e tecnica.

I LED devono avere dimensioni uniformi. Le linee devono adattarsi allo spazio
residuo senza apparire spezzate o disallineate.

## 15. Modal

I modal devono riutilizzare lo stesso sistema della dashboard senza modificarne
il layout quando non richiesto.

Mappatura:

- header modal = Section Header;
- corpo modal = Outer Panel;
- opzioni, reti e campi = Inner Components;
- riepilogo stato = fascia informativa;
- footer = area azioni.

Regole specifiche:

- le personalizzazioni sperimentali dark devono essere circoscritte con
  `html[data-bs-theme="dark"]`;
- il tema light non deve cambiare durante il lavoro sul dark;
- evitare regole globali per `.modal-body` o `.modal-content`;
- usare l'ID del modal come scope, per esempio `#apEthernetModal` o
  `#uplinkModal`;
- tenere separato lo sfondo libero del corpo dalle celle interne;
- contenuti caricati dinamicamente richiedono lo scope sul contenitore reale,
  per esempio `#apEthernetModalContent` o `#uplinkModalContent`.

## 16. Tema light e tema dark

I due temi devono essere trattati come varianti indipendenti.

### 16.1 Baseline autorevole del tema light

La baseline light corrente sostituisce le precedenti combinazioni locali di
bianco, grigio e bordi cromatici ereditate dai singoli componenti. Si applica
alla Dashboard e alle pagine AP Configuration, DHCP Setting, Logging,
Connected Clients, System e About OpenAP.

Gerarchia delle superfici:

- canvas esterno della pagina: `#fff`;
- superficie degli Outer Panel e area libera dei widget: `var(--bg)`, il cui
  valore corrente e' `#eef3f5`;
- superficie interna tenue, usata quando serve separare due livelli chiari
  senza ricorrere al bianco: `#f4f7f8`;
- campi, metriche e celle informative: `#fff`;
- footer dei widget: `#d8ece9`;
- header di sezione: gradiente petrolio gia' definito dal sistema condiviso.

Gerarchia delle cornici:

- cornice principale: `1px solid rgba(18, 104, 105, .48)`;
- accento superiore degli Outer Panel e dei widget: `3px solid #126869`;
- divisori e giunzioni interne: `1px solid rgba(18, 104, 105, .24)`;
- le celle icona usano la stessa cornice principale `.48`;
- i divisori non devono essere simulati tramite gap, margini o porzioni vuote:
  devono essere bordi reali e restare allineati durante le animazioni.

Regole applicative:

- non usare colori diversi per il bordo superiore dei widget; le vecchie
  varianti blu, verde, ambra e viola non fanno parte della baseline light;
- conservare il bianco per i campi interni, non per il fondo generale del
  widget;
- mantenere simmetrici lati, angoli e spazi tra pannelli affiancati;
- i selettori animati devono coprire esattamente la propria meta' senza gap e
  senza nascondere il divisore centrale;
- testo e icone dei footer widget usano `#334155`; restano ammesse eccezioni
  semantiche, come le frecce blu e verde di Uplink sent/received;
- stati positivi, avvisi ed errori mantengono i propri colori semantici.

### 16.2 Baseline del tema dark

Il dark conserva la propria gerarchia indipendente: superfici esterne
`#1a2628` o `var(--surface)`, celle interne `var(--bg)`, cornici
`rgba(85, 195, 187, .26)`/`.32` e accenti petrolio chiaro. I valori light non
devono propagarsi nel dark e viceversa.

Regola obbligatoria durante gli esperimenti sul dark:

```css
html[data-bs-theme="dark"] #componentId .component-class {
  /* dark-only styles */
}
```

Non introdurre colori dark con selettori non circoscritti. Prima di consegnare
una modifica, verificare che il tema light mantenga lo stato precedente, salvo
richiesta esplicita contraria.

### 16.3 Testo di supporto ad alta leggibilita'

Il token condiviso `--openap-text-support-strong` identifica testi secondari
che, pur non essendo titoli o valori principali, devono essere letti senza la
riduzione di contrasto propria di `--text-light` e `--text-muted`.

Definizione normativa:

```css
:root {
  --openap-text-support-strong: #111820;
}

html[data-bs-theme="dark"] {
  --openap-text-support-strong: #fff;
}
```

Il valore light `#111820` e' un **testo di supporto forte**: un quasi-nero
leggermente freddo, coerente con le superfici petrolio e blu di OpenAP. Il
valore dark corrispondente e' bianco pieno, per mantenere la stessa priorita'
percettiva sulle superfici scure. I due valori appartengono allo stesso ruolo
semantico e non sono due eccezioni indipendenti.

Usare il token per:

- sottotitoli di widget che devono restare immediatamente leggibili;
- etichette e descrizioni operative dentro metriche o celle compatte;
- nomi e dettagli tecnici di interfacce quando costituiscono informazione
  necessaria, non semplice metadato attenuato;
- brevi istruzioni contestuali, come la maniglia testuale `Drag`, quando il
  controllo deve essere scoperto facilmente;
- note operative nei footer di configurazione quando descrivono validazione,
  gestione o rollback e devono rimanere immediatamente leggibili.

Non usarlo per:

- titoli e valori principali, che continuano a usare `var(--text)`;
- testo realmente accessorio o decorativo, che usa `--text-light` o
  `--text-muted`;
- footer puramente riepilogativi, stati, badge e callout Info con una propria
  semantica cromatica;
- icone, bordi o superfici: il token descrive esclusivamente il colore del
  testo.

Regola di implementazione: non duplicare `#111820` o `#fff` nei singoli
componenti per questo ruolo. Applicare il token con selettori circoscritti al
componente; la variante di tema deriva dalla variabile, non da due serie di
override locali. Prima della consegna verificare almeno sottotitolo, etichetta
e dettaglio tecnico in entrambi i temi.

## 17. Modal universale di applicazione

Il modal universale di applicazione nasce dalla combinazione intenzionale di
due riferimenti esistenti, che non devono essere confusi:

1. **Ethernet Mode** fornisce overlay, oscuramento della pagina, geometria del
   pannello, identita' OpenAP, titolo, descrizione e lista dei passaggi;
2. **Repeater Mode** fornisce esclusivamente la grafica dello spinner con
   icona centrale, comprese dimensioni, forma, bordi e animazione.

Il riferimento Ethernet Mode e' mostrato in `apply.png`. Lo spinner di
riferimento e' quello visualizzato durante la scansione delle reti nel modal
Repeater Mode (`.openap-uplink-scan-visual`). Il modal universale combina i due
senza ridisegnare liberamente nessuno dei loro elementi.

Questo componente e' diverso:

- dai modal che raccolgono o modificano impostazioni;
- dai modal di conferma che riepilogano le modifiche;
- dallo spinner inserito temporaneamente in un pulsante Save.

Deve essere usato quando OpenAP sta realmente eseguendo una sequenza di
operazioni di sistema, per esempio applicazione AP Configuration, DHCP/DNS,
modalita' Ethernet, Repeater Mode o altre configurazioni che richiedono
riavvio, verifica o rollback.

### 17.1 Dimensioni e contenitore

- usare l'overlay di Ethernet Mode a tutta viewport;
- oscuramento di riferimento: `background: rgba(5, 20, 22, .78)`;
- sfocatura di riferimento: `backdrop-filter: blur(5px)`;
- il passaggio da pagina visibile a pagina oscurata usa una transizione di
  opacita' breve, circa `.2s ease`;
- pannello centrato orizzontalmente e verticalmente;
- larghezza desktop esatta di riferimento: `420px`;
- altezza visiva di riferimento: circa `315px`; non trasformare il componente
  in un modal largo derivato dalla pagina di configurazione;
- le dimensioni non devono cambiare durante il passaggio tra gli stati della
  stessa operazione;
- larghezza mobile: `calc(100% - 24px)`, senza superare la larghezza desktop;
- padding interno desktop: `24px`;
- bordo dark: `1px solid rgba(45, 212, 191, .25)`;
- raggio esterno: `14px`;
- superficie: `var(--surface)` del tema corrente;
- ombra: `0 24px 70px rgba(0, 0, 0, .38)`;
- nessun pulsante di chiusura mentre l'operazione non e' interrompibile.

Non riutilizzare la larghezza del modal di conferma che precede
l'applicazione. Quando comincia l'operazione, il contenuto deve diventare il
pannello universale da `420px` sopra l'overlay Ethernet Mode.

### 17.2 Gerarchia del contenuto

Ordine obbligatorio:

```text
Identita' OpenAP
Titolo dell'operazione
Descrizione breve
Spinner Repeater Mode con icona contestuale
Separatore
Elenco live dei passaggi
```

L'identita' OpenAP replica l'eyebrow di Ethernet Mode:

- colore dark di riferimento `#2dd4bf`;
- `font-size: 10px`;
- `font-weight: 700`;
- `letter-spacing: .7px`;
- testo centrato e maiuscolo.

Il titolo:

- usa sempre il testo principale `Applying changes`;
- non cambia in base alla pagina o alla configurazione applicata;
- il contesto specifico viene comunicato dall'icona e dai passaggi elencati
  nella parte inferiore;
- usa testo principale chiaro, peso forte e dimensione indicativa `17-18px`;
- riferimento effettivo Ethernet Mode: `18px`, peso `700`, centrato;
- non deve contenere il nome della pagina, del servizio o della modalita'.

La descrizione:

- spiega l'impatto immediato in una sola frase;
- usa testo secondario, dimensione indicativa `10-11px`;
- riferimento effettivo Ethernet Mode: `11px`, centrato, colore
  `var(--text-muted)`;
- esempi: riavvio dei servizi, breve perdita di connettivita', tempo di attesa.

### 17.3 Spinner e icona contestuale

La visuale centrale deve replicare lo spinner del modal Repeater Mode. Non
usare uno spinner Bootstrap piccolo, una cella quadrata arbitraria o le
dimensioni dei nodi Ethernet Mode.

Dimensioni desktop obbligatorie:

- contenitore spinner: `88px × 88px`;
- anello esterno: `inset: 0`, spessore `4px`, forma circolare;
- bordo base light: `rgba(22, 132, 127, .16)`;
- segmento attivo light: `#16847f` e `#55c3bb` su lato superiore e destro;
- rotazione: `.9s linear infinite`;
- ombra anello: `0 0 20px rgba(22, 132, 127, .14)`;
- cella icona interna: `58px × 58px`;
- cella interna rigorosamente circolare (`border-radius: 50%`);
- icona: `24px`;
- fondo light: `linear-gradient(145deg, #fff, #edf6f5)`;
- colore icona light: `#1e5eb8`;
- bordo cella light: `rgba(30, 94, 184, .2)`.

Variante dark dello spinner:

- anello base: `rgba(121, 221, 213, .14)`;
- segmenti attivi: `#79ddd5` e `#16847f`;
- cella interna:
  `linear-gradient(145deg, #173134, #102326)`;
- bordo cella: `rgba(121, 221, 213, .24)`;
- colore icona: `#79ddd5`;
- ombra cella: `0 10px 28px rgba(0, 0, 0, .35)`.

Dimensioni mobile:

- contenitore spinner: `78px × 78px`;
- cella icona: `52px × 52px`;
- icona: `21px`.

Solo il glifo dentro la cella cambia in base alla pagina o funzione:

- Wi-Fi per AP Configuration;
- rete o server DHCP per DHCP Setting;
- scudo per Encrypted DNS;
- Wi-Fi/antenna per Repeater Mode;
- Ethernet per Ethernet Mode.

Forma circolare, anello, dimensioni e animazione non cambiano con la pagina.
Con `prefers-reduced-motion`, rallentare l'anello come nel riferimento
Repeater Mode; non sostituire la visuale con un'altra grafica.

### 17.4 Elenco live delle operazioni

Sotto un separatore deve comparire obbligatoriamente la sequenza reale delle
azioni in corso, replicando la checklist Ethernet Mode nella parte inferiore
di `apply.png`.
Ogni riga contiene indicatore e descrizione breve:

```text
[completato] Preparazione / validazione
[in corso]   Applicazione della configurazione
[attesa]     Verifica dei servizi o della connettivita'
```

Stati:

- **completato**: cerchio verde con check;
- **in corso**: spinner circolare verde;
- **in attesa**: cerchio vuoto grigio;
- **errore**: indicatore rosso e testo dell'azione fallita;
- **rollback**: nuova riga o sostituzione esplicita dello stato, mai un errore
  generico senza contesto.

I passaggi devono corrispondere ad attivita' realmente svolte dal backend. Non
mostrare una checklist puramente temporizzata quando e' disponibile uno stato
reale da interrogare.

L'elenco non puo' essere sostituito da un solo spinner generico. Deve restare
visibile durante tutta l'applicazione e avanzare riga per riga. La riga attiva
usa lo spinner verde; le righe completate conservano il check verde; quelle non
ancora iniziate restano grigie.

Misure di riferimento della checklist Ethernet Mode:

- separatore superiore con `padding-top: 14px`;
- gap verticale tra righe: `7px`;
- testo: `11px`;
- gap indicatore/testo: `9px`;
- colonna indicatore: `14px`;
- attivo dark: `#2dd4bf`;
- completato: `#10b981`.

### 17.5 Comportamento

- il pannello compare dopo la conferma e rimane visibile fino a esito noto;
- la pagina deve essere oscurata e sfocata dall'overlay Ethernet Mode, anche se
  l'applicazione nasce dentro un modal Bootstrap gia' aperto;
- durante questa fase non mostrare un secondo spinner concorrente nel corpo;
- lo spinner nel pulsante puo' coprire soltanto l'intervallo precedente alla
  comparsa del pannello universale;
- disabilitare azioni duplicate mentre l'applicazione e' in corso;
- rispettare `prefers-reduced-motion`;
- successo: completare tutti i passaggi, mostrare una conferma breve e poi
  chiudere o ricaricare quando appropriato;
- errore: mantenere il pannello visibile, indicare il passaggio fallito e
  offrire un messaggio comprensibile;
- rollback: dichiarare chiaramente se la configurazione precedente e' stata
  ripristinata e verificata;
- il pannello non deve chiudersi silenziosamente senza un esito.

### 17.6 Tema light e dark

Il riferimento `apply.png` definisce prioritariamente il tema dark. La variante
light conserva dimensioni, anatomia, spinner e checklist, sostituendo
soltanto le superfici:

- pannello light: superficie chiara neutra;
- testo principale light: `var(--text)`;
- testo secondario light: `var(--text-light)`;
- bordo light: `rgba(18, 104, 105, .48)`;
- verde operativo comune: `#16847f`;
- pannello dark: superficie petrolio molto scura;
- testo principale dark: `var(--text)` / bianco attenuato;
- testo secondario dark: `var(--text-light)`;
- bordo dark: `rgba(85, 195, 187, .32)`;
- verde operativo dark: `#55c3bb`.

La geometria non deve cambiare passando da light a dark.

### 17.7 Notifica universale di esito

Al termine di un salvataggio o di un'applicazione usare una notifica laterale
ispirata alla notifica Plasma/KDE gia' impiegata per il rilevamento delle
interfacce Wi-Fi. Questa notifica sostituisce i vecchi toast centrali di
successo o errore, ma resta distinta dalle notifiche hardware.

Regole comuni:

- ingresso laterale su desktop e dall'alto su mobile;
- una sola notifica operativa principale alla volta;
- pulsante `X` sempre presente e accessibile da tastiera;
- titolo, riepilogo breve e icona semantica;
- pulsante **Show more** quando sono disponibili passaggi reali da mostrare;
- espansione e contrazione animate esclusivamente in altezza;
- elenco basato sulle operazioni realmente completate o fallite;
- tema light con superficie chiara, bordo petrolio e testo scuro;
- tema dark con superficie `#1a2628`, bordo petrolio chiaro e testo attenuato;
- `aria-live="polite"` per il successo e `role="alert"` per l'errore.

Successo:

- titolo predefinito **Operation successful**;
- messaggio visibile generico: **Settings successfully applied.**; non
  riportare nel corpo principale l'elenco delle fasi tecniche;
- durata esatta di riferimento: `10s`;
- barra inferiore che rappresenta il tempo residuo;
- timer sospeso durante hover, focus operativo o dettagli espansi;
- icona e indicatori verdi;
- **Show more** elenca esclusivamente i cambiamenti reali effettuati durante
  il salvataggio, preferibilmente nella forma `valore precedente -> valore
  nuovo`;
- includere soltanto proprietà realmente cambiate (SSID, ruoli radio, canale,
  larghezza, potenza, sicurezza e opzioni); non sostituire il diff con una
  checklist generica di validazione, riavvio e verifica;
- non mostrare mai il valore precedente o nuovo di una password: usare una
  voce neutra come **WiFi password updated**;
- se il salvataggio non modifica alcun valore, dichiarare **No setting values
  changed**;
- non mostrare **Copy text** salvo un'esigenza specifica.

Errore:

- non chiudere automaticamente la notifica;
- usare titolo e descrizione pertinenti al fallimento;
- icona e indicatori rossi;
- **Show more** elenca il punto fallito e l'eventuale rollback;
- **Show more** deve riportare una causa diagnostica specifica ottenuta dal
  backend, non una semplice riformulazione dello stato generale. Quando
  disponibili includere fase fallita, servizio o componente, messaggio
  tecnico normalizzato, interfaccia o risorsa coinvolta e risultato del
  rollback;
- non usare dettagli generici come "service restart failed" se il backend
  espone la causa concreta, per esempio canale non consentito, configurazione
  non valida, timeout, comando fallito o servizio inattivo;
- non inventare cause tecniche quando il backend non le fornisce: dichiarare
  che la causa specifica non e' disponibile e indicare dove reperire i log;
- mostrare sempre **Copy text**, includendo titolo, messaggio e dettagli
  diagnostici disponibili;
- la copia deve funzionare anche in contesti HTTP privi di Clipboard API.

## 18. Widget condivisi

L'area widget e' un componente condiviso tra Dashboard, AP Configuration,
DHCP Setting, Logging, Connected Clients, System e About OpenAP. La stessa
baseline cromatica deve essere applicata in ogni pagina; non sono ammesse
varianti locali della medesima scheda.

Baseline light dei widget:

- fondo del widget: `var(--bg)` / `#eef3f5`;
- cornice: `rgba(18, 104, 105, .48)`;
- bordo superiore: `#126869` per tutti i widget;
- campi e metriche: fondo `#fff`, cornice `.48`;
- celle icona: fondo coerente con il campo e cornice `.48`;
- footer: fondo `#d8ece9`, testo e icone `#334155`;
- eccezioni semantiche del footer: Uplink sent blu `#1e3a8a` e Uplink
  received verde `#059669`.

Comportamento dell'area widget:

- riordinamento disponibile soltanto nella modalita' di modifica esplicita;
- persistenza del layout per pagina;
- widget a larghezza singola o doppia secondo il contenuto;
- possibilita' di mostrare, nascondere e ripristinare i moduli;
- layout mobile fisso e non trascinabile;
- Section Header, Outer Panel e tipografia CHANNEL/36 condivisi con il resto
  dell'interfaccia.

Il tema dark mantiene i propri fondi e bordi definiti nella sezione 16.2; la
standardizzazione light non deve neutralizzare stati o contrasti dark.

## 19. Direzione multimediale

Il sistema deve poter accogliere in futuro componenti opzionali come:

- lettore musicale;
- widget Now Playing;
- playlist e libreria locale;
- radio Internet;
- uscita audio selezionabile;
- integrazioni MPD, Mopidy, Bluetooth Audio e UPnP/DLNA.

I moduli multimediali devono essere separati dai servizi di rete essenziali e
installabili/disattivabili senza compromettere la funzione router.

## 20. Procedura per un nuovo componente

Prima di implementare:

1. identificare Section Header, Outer Panel e Inner Components;
2. classificare ogni testo come CHANNEL, 36 o eccezione motivata;
3. stabilire se eventuali messaggi sono Info, Warning, Error o Success;
4. identificare l'azione Save primaria, usare `fa-floppy-disk` e predisporre
   lo spinner di caricamento;
5. stabilire se l'applicazione richiede il modal universale descritto nella
   sezione 17 e definire i suoi passaggi reali;
6. identificare gli stati interattivi;
7. scegliere una griglia coerente con i dati;
8. definire il comportamento mobile;
9. circoscrivere le regole al componente e al tema corretto;
10. evitare modifiche di layout se e' richiesto soltanto uno skin;
11. verificare tema dark e light separatamente;
12. verificare contenuti lunghi, vuoti, errore e caricamento;
13. sincronizzare sorgente canonico e webroot installato quando previsto dal
   flusso di sviluppo locale.

## 21. Checklist di revisione

- [ ] Il componente sembra appartenere a OpenAP?
- [ ] La gerarchia Section Header / Outer Panel / Inner Component e' chiara?
- [ ] Etichette e valori seguono CHANNEL/36?
- [ ] Colori e bordi comunicano ruoli coerenti?
- [ ] Il focus da tastiera e' visibile?
- [ ] Gli stati attivo, inattivo, errore e caricamento sono distinguibili?
- [ ] Le sezioni Info usano semantica e palette corrette nei due temi?
- [ ] Il pulsante Save usa `fa-floppy-disk` a sinistra?
- [ ] Durante Save l'icona viene sostituita dallo spinner?
- [ ] Save gestisce correttamente focus, disabled e loading?
- [ ] Le applicazioni multi-step usano il modal universale con dimensioni,
      icona contestuale e passaggi reali?
- [ ] Il tema light e' rimasto invariato durante modifiche dark-only?
- [ ] Il layout mobile e' leggibile e deterministico?
- [ ] I testi vengono troncati soltanto quando necessario?
- [ ] Non sono stati introdotti stili inline evitabili?
- [ ] Le regole sono circoscritte al componente?
- [ ] La modifica conserva comportamento e semantica esistenti?

## 22. Regola finale

Quando una scelta non e' descritta esplicitamente in questo documento, usare
come riferimento visivo primario la sezione WiFi Hotspot della dashboard:

- **CHANNEL** per le etichette;
- **36** per valori e nomi;
- la cornice del pannello per i contenitori;
- le celle Channel/Channel Width/TX Power per i componenti interni;
- Network Topology and Operational Mode per i componenti che devono evocare
  hardware o flussi di rete.
