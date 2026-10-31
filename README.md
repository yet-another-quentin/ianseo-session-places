# Places left per session for IANSEO

[Français](#français) · English

IANSEO module that publishes an image of the places left in each session of a competition, to show on the club's website or share on social media.

- **Per session**: places left in large print (or "FULL"), fill bar, registered / places. Places = targets × archers per target (IANSEO session settings); taken = entries of the session.
- **Online registration**: with the [SelfRegistration](https://github.com/yet-another-quentin/ianseo-self-registration) module, pending requests hold their places, and the image can show the places left per bow type when quotas are set there.
- **Per distance** (optional): IANSEO only assigns targets after registration. The organiser enters how many targets each distance will use in each session (e.g. 70 m: 10 targets, 50 m: 6). The image then shows the places left per distance, from the distances of each category (Competition › Distances).
- **Always up to date, drawn only when needed**: the image is redrawn only when an entry, a pending request, a setting or the competition details change. Otherwise it is served from a cache, and browsers that already have it get a "not modified" answer.
- **Two formats**: full (logo, name, dates and town of the competition, a card per session) or compact (`&f=compact`: a narrow list of the sessions only, to fit in a sidebar or next to a text).
- **Safe public address**: `…/Modules/Custom/SessionPlaces/image.php?c=<competition code>` takes no other parameter than `&f=compact`. Unknown or unpublished competitions get the same empty answer, and the image holds no personal data.
- **Language**: French or English, chosen per competition.

Tested with IANSEO 2025-02-10 (FR rules).

## Requirements

- PHP `gd` extension with FreeType (standard on IANSEO installs). The texts use IANSEO's own fonts (Roboto, else FreeSans).
- To show the image outside the club's network: IANSEO reachable from the Internet, at least for `image.php` (see below).

## Installation

1. Download `SessionPlaces.zip` from the [latest release](https://github.com/yet-another-quentin/ianseo-session-places/releases/latest).
2. Unzip it into IANSEO's `Modules/Custom/` folder, so that you get `Modules/Custom/SessionPlaces/index.php`.
3. Open a competition. The module is in **Participants › Places left**.

IANSEO updates never touch `Modules/Custom/`, so the module stays installed.

## Usage

1. **Sessions**: untick the sessions to leave out of the image (sessions without targets are never shown).
2. **Bow types**: tick "Show the places left per bow type" to add the quotas of the SelfRegistration module.
3. **Targets per distance** (optional): enter the targets of each distance in each session. Empty = not shown.
4. **Public image**: tick "Publish the image", choose its language, then save. The preview shows the saved settings, even before publishing.
5. **Share** the address of the image (choose the format first), or paste the HTML code given on the page into the club's website.

The image is cached by browsers for one minute, then checked again: an embedded image follows the registrations within a minute.

## Putting the image online

The image is the only public file of the module. If IANSEO is protected by a reverse proxy (as recommended for SelfRegistration), open only `image.php`.

### Behind Nginx Proxy Manager

In **Proxy host › Advanced**, next to the other public locations:

```nginx
# Public image of the places left (the rest of IANSEO keeps the Access List)
location = /Modules/Custom/SessionPlaces/image.php {
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $x_forwarded_proto;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_pass $forward_scheme://$server:$port$uri$is_args$args;
}
```

**Check** from a private window: `https://your-site/Modules/Custom/SessionPlaces/image.php?c=CODE` shows the image, `…/SessionPlaces/index.php` asks for the password, and `…/image.php?c=CODE&x=1` answers 400.

### Short address

Instead of `…/Modules/Custom/SessionPlaces/image.php?c=SP26&f=compact`, the image can be served as `https://inscription.example.org/SP26/compact/places.png` (or `…/SP26/full/places.png`), for instance on the sub-domain of [SelfRegistration's short address](https://github.com/yet-another-quentin/ianseo-self-registration#short-address-for-the-form). In **Proxy host › Advanced** of that sub-domain, add **before** `location ~ . { return 404; }` (nginx tries regex locations in order):

```nginx
# Image of the places left: /CODE/full/places.png or /CODE/compact/places.png
location ~ ^/(?<sp_code>[A-Za-z0-9][A-Za-z0-9_.-]*)/(?<sp_format>full|compact)/places\.png$ {
    set $sp_query c=$sp_code;
    if ($sp_format = compact) { set $sp_query c=$sp_code&f=compact; }
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $x_forwarded_proto;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_pass $forward_scheme://$server:$port/Modules/Custom/SessionPlaces/image.php?$sp_query;
}
```

- Named captures: nginx does not know `$2` inside `if`.
- Only `image.php` is reached, with a query it builds itself: the query string of the short address is dropped (`?v=2` changes nothing), and `image.php` still checks the code.
- **Check**: `…/SP26/compact/places.png` shows the image, `…/SP26/other/places.png` answers 404.

### What the public address accepts

- `GET` or `HEAD` only (otherwise 405), and exactly `?c=<code>` or `?c=<code>&f=compact`: 1 to 8 letters, digits, `_`, `-` or `.`, checked on the raw query string **before IANSEO starts**. Anything else (other parameter or value, other order, repeated parameter, array, encoded character) gets 400, so IANSEO's own URL hooks (language, debug…) can never be reached through it.
- Malformed code, unknown competition, image not published: the same empty 404.
- No session cookie, no `Access-Control-Allow-Origin`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`. Nothing of the request is echoed back.

### Cache

- The image's data are summed up in a fingerprint: places per session, bow type and category, settings, sessions, competition name, dates, town and logo, module version. A few `count(*)` queries, without drawing.
- The fingerprint is the `ETag`: a browser or proxy that has the image gets a `304 Not Modified`.
- On the server, the PNG is kept in the system's temporary folder (`ianseo-sessionplaces-…`, readable by the web server only, at most one file per competition and format). It is drawn only for a new fingerprint, by one request at a time.

## Development

```sh
node --test tests/*.test.js     # translations, nightly versioning
php tests/core.test.php         # public parameter, places, distances, fingerprint and ETag, dates, line breaks

# Test IANSEO on http://localhost:8091/ (IANSEO_DIR = a COPY of the IANSEO sources)
cd dev && IANSEO_DIR=/path/to/ianseo-copy docker compose up -d --build && IANSEO_DIR=/path/to/ianseo-copy ./seed.sh
```

`seed.sh` creates the competition "SP26" (FR outdoor, TAE: recurve at 70 m, compound at 50 m) with 3 sessions, the first one full, and fictitious entries. With `SELFREG_DIR=../../ianseo-self-registration/SelfRegistration`, open the SelfRegistration page once, then run `seed.sh` again: it adds pending requests and bow type quotas.

**Releases**:
- **Stable**: push a `vX.Y.Z` tag.
- **Nightly**: built every night from `main`.
- Administrators update the module in one click from its page.

---

## Français

Module IANSEO qui publie une image des places restantes à chaque départ d'une compétition, à afficher sur le site du club ou à partager sur les réseaux sociaux.

- **Par départ** : places restantes en gros (ou « COMPLET »), barre de remplissage, inscrits / places. Places = cibles × archers par cible (réglages des départs dans IANSEO) ; prises = participants du départ.
- **Inscription en ligne** : avec le module [SelfRegistration](https://github.com/yet-another-quentin/ianseo-self-registration), les demandes en attente réservent leurs places, et l'image peut afficher les places restantes par arme quand des quotas y sont fixés.
- **Par distance** (facultatif) : IANSEO n'attribue les cibles qu'après les inscriptions. L'organisateur indique combien de cibles chaque distance occupera à chaque départ (ex. 70 m : 10 cibles, 50 m : 6). L'image affiche alors les places restantes par distance, d'après les distances de chaque catégorie (Compétition › Distances).
- **Toujours à jour, dessinée seulement si besoin** : l'image n'est redessinée que quand une inscription, une demande en attente, un réglage ou les informations de la compétition changent. Sinon elle est servie depuis un cache, et un navigateur qui l'a déjà reçoit une réponse « non modifiée ».
- **Deux formats** : complet (logo, nom, dates et ville de la compétition, une carte par départ) ou compact (`&f=compact` : une liste étroite des seuls départs, pour une colonne latérale ou à côté d'un texte).
- **Adresse publique sûre** : `…/Modules/Custom/SessionPlaces/image.php?c=<code de la compétition>` n'accepte aucun autre paramètre que `&f=compact`. Une compétition inconnue ou non publiée reçoit la même réponse vide, et l'image ne contient aucune donnée personnelle.
- **Langue** : français ou anglais, au choix pour chaque compétition.

### Prérequis

- Extension PHP `gd` avec FreeType (standard dans les installations d'IANSEO). Les textes utilisent les polices d'IANSEO (Roboto, sinon FreeSans).
- Pour montrer l'image hors du réseau du club : IANSEO accessible depuis Internet, au moins pour `image.php` (voir plus bas).

### Installation

1. Télécharger `SessionPlaces.zip` depuis la [dernière release](https://github.com/yet-another-quentin/ianseo-session-places/releases/latest).
2. Décompresser l'archive dans le dossier `Modules/Custom/` d'IANSEO, pour obtenir `Modules/Custom/SessionPlaces/index.php`.
3. Ouvrir une compétition. Le module se trouve dans **Participants › Places restantes**.

### Utilisation

1. **Départs** : décocher les départs à ne pas afficher (les départs sans cibles ne le sont jamais).
2. **Armes** : cocher « Afficher les places restantes par arme » pour ajouter les quotas du module SelfRegistration.
3. **Cibles par distance** (facultatif) : indiquer les cibles de chaque distance à chaque départ. Vide = non affiché.
4. **Image publique** : cocher « Publier l'image », choisir sa langue, puis enregistrer. L'aperçu montre les réglages enregistrés, même avant la publication.
5. **Diffuser** l'adresse de l'image (après avoir choisi le format), ou coller le code HTML donné sur la page dans le site du club.

Les navigateurs gardent l'image une minute, puis vérifient si elle a changé : une image intégrée à un site suit les inscriptions à la minute près.

### Mise en ligne de l'image

L'image est le seul fichier public du module. Si IANSEO est protégé par un reverse proxy (comme recommandé pour SelfRegistration), n'ouvrir que `image.php` : bloc `location = /Modules/Custom/SessionPlaces/image.php` dans l'onglet **Advanced** du proxy host (configuration complète dans la partie anglaise).

**Vérifier** en navigation privée que l'image s'affiche, que `…/SessionPlaces/index.php` demande le mot de passe et que `…/image.php?c=CODE&x=1` répond 400.

### Adresse courte

Au lieu de `…/Modules/Custom/SessionPlaces/image.php?c=SP26&f=compact`, l'image peut être servie en `https://inscription.example.org/SP26/compact/places.png` (ou `…/SP26/full/places.png`), par exemple sur le sous-domaine de l'adresse courte de SelfRegistration. Ajouter le bloc `location` de la partie anglaise dans l'onglet **Advanced** de ce proxy host, **avant** `location ~ . { return 404; }` : nginx teste les `location` regex dans l'ordre. La query string de l'adresse courte est ignorée et `image.php` vérifie toujours le code.

**Vérifier** que `…/SP26/compact/places.png` affiche l'image et que `…/SP26/other/places.png` répond 404.

### Cache

- Les données de l'image sont résumées par une empreinte : places par départ, arme et catégorie, réglages, départs, nom, dates, ville et logo de la compétition, version du module. Quelques requêtes `count(*)`, sans dessin.
- L'empreinte sert d'`ETag` : un navigateur ou un proxy qui a déjà l'image reçoit un `304 Not Modified`.
- Sur le serveur, le PNG est gardé dans le dossier temporaire du système (`ianseo-sessionplaces-…`, lisible par le seul serveur web, au plus un fichier par compétition et par format). Il n'est dessiné que pour une nouvelle empreinte, par une seule requête à la fois.
