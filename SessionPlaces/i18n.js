/*
 * Session places — translations of the admin page (French / English). The image has its own texts (sp_img_t()).
 * The language follows IANSEO: French if it starts with "fr", English otherwise. t(key, params) replaces {name}
 * placeholders; missing keys fall back to English, then to the key itself. Params are inserted as-is: the admin
 * page escapes them (th()).
 */
(function (root) {
  'use strict';

  const en = {
    'admin.title': 'Places left per session',
    'admin.saved': 'Saved.',
    'admin.failed': 'Failed: {error}',
    'alert.noGd': 'The PHP "gd" extension is missing: the image cannot be drawn.',
    'alert.unsaved': 'Unsaved changes: the preview shows the saved settings.',
    'image.title': 'Public image',
    'image.on': 'Published',
    'image.off': 'Not published',
    'image.public': 'Publish the image (anyone with the address can see it)',
    'image.lang': 'Language of the image',
    'image.lang.fr': 'French',
    'image.lang.en': 'English',
    'image.url': 'Address of the image',
    'image.embed': 'HTML code for a website',
    'image.alt': 'Places left',
    'image.copy': 'Copy',
    'image.copied': 'Copied.',
    'image.help': 'The image is updated as soon as an entry, a pending online request or a setting changes, and only then. Behind a reverse proxy, only image.php must be opened to the public (README › Putting the image online). The address takes no other parameter.',
    'sessions.title': 'Sessions',
    'sessions.show': 'Shown',
    'sessions.session': 'Session',
    'sessions.label': 'Session {n}',
    'sessions.capacity': 'Places',
    'sessions.used': 'Taken',
    'sessions.free': 'Left',
    'sessions.help': 'Places = targets × archers per target (IANSEO session settings). Taken = entries in the session, plus the pending requests of the online registration module.',
    'divisions.show': 'Show the places left per bow type (quotas of the online registration module)',
    'divisions.none': 'no quota set in the online registration module',
    'divisions.noModule': 'online registration module not installed',
    'distances.title': 'Targets per distance',
    'distances.help': 'Optional. IANSEO only assigns targets after registration: enter how many targets each distance will use in each session. The image then shows the places left per distance (targets × archers per target, minus the archers whose category shoots that distance, as set in Competition › Distances). Empty: not shown.',
    'distances.none': 'No distance set for this competition (Competition › Distances).',
    'distances.targets': 'Targets',
    'distances.total': 'Total',
    'distances.over': 'more targets than the session has ({n})',
    'settings.save': 'Save',
    'preview.title': 'Preview',
    'preview.help': 'Saved settings, even when the image is not published.',
    'update.available': 'Version {latest} available (installed: {current}).',
    'update.install': 'Update',
    'update.done': 'Version {version} installed. Reload the page.',
    'update.upToDate': 'up to date',
    'update.check': 'Check',
    'update.checkFailed': 'check failed ({error})',
    'update.channel': 'Update channel',
    'update.channel.stable': 'stable',
    'update.channel.nightly': 'nightly',
  };

  const fr = {
    'admin.title': 'Places restantes par départ',
    'admin.saved': 'Enregistré.',
    'admin.failed': 'Échec : {error}',
    'alert.noGd': 'L\'extension PHP « gd » est absente : l\'image ne peut pas être dessinée.',
    'alert.unsaved': 'Modifications non enregistrées : l\'aperçu montre les réglages enregistrés.',
    'image.title': 'Image publique',
    'image.on': 'Publiée',
    'image.off': 'Non publiée',
    'image.public': 'Publier l\'image (toute personne qui a l\'adresse peut la voir)',
    'image.lang': 'Langue de l\'image',
    'image.lang.fr': 'Français',
    'image.lang.en': 'Anglais',
    'image.url': 'Adresse de l\'image',
    'image.embed': 'Code HTML pour un site',
    'image.alt': 'Places restantes',
    'image.copy': 'Copier',
    'image.copied': 'Copié.',
    'image.help': 'L\'image est mise à jour dès qu\'une inscription, une demande en ligne en attente ou un réglage change, et seulement dans ce cas. Derrière un reverse proxy, seul image.php doit être ouvert au public (README › Mise en ligne de l\'image). L\'adresse n\'accepte aucun autre paramètre.',
    'sessions.title': 'Départs',
    'sessions.show': 'Affiché',
    'sessions.session': 'Départ',
    'sessions.label': 'Départ {n}',
    'sessions.capacity': 'Places',
    'sessions.used': 'Prises',
    'sessions.free': 'Restantes',
    'sessions.help': 'Places = cibles × archers par cible (réglages des départs dans IANSEO). Prises = participants du départ, plus les demandes en attente du module d\'inscription en ligne.',
    'divisions.show': 'Afficher les places restantes par arme (quotas du module d\'inscription en ligne)',
    'divisions.none': 'aucun quota défini dans le module d\'inscription en ligne',
    'divisions.noModule': 'module d\'inscription en ligne non installé',
    'distances.title': 'Cibles par distance',
    'distances.help': 'Facultatif. IANSEO n\'attribue les cibles qu\'après les inscriptions : indiquer combien de cibles chaque distance occupera à chaque départ. L\'image affiche alors les places restantes par distance (cibles × archers par cible, moins les archers dont la catégorie tire à cette distance, selon Compétition › Distances). Vide : non affiché.',
    'distances.none': 'Aucune distance définie pour cette compétition (Compétition › Distances).',
    'distances.targets': 'Cibles',
    'distances.total': 'Total',
    'distances.over': 'plus de cibles que le départ n\'en a ({n})',
    'settings.save': 'Enregistrer',
    'preview.title': 'Aperçu',
    'preview.help': 'Réglages enregistrés, même quand l\'image n\'est pas publiée.',
    'update.available': 'Version {latest} disponible (installée : {current}).',
    'update.install': 'Mettre à jour',
    'update.done': 'Version {version} installée. Recharger la page.',
    'update.upToDate': 'à jour',
    'update.check': 'Vérifier',
    'update.checkFailed': 'vérification impossible ({error})',
    'update.channel': 'Canal de mise à jour',
    'update.channel.stable': 'stable',
    'update.channel.nightly': 'nightly',
  };

  const DICTS = { en, fr };

  function make(lang) {
    const l = /^fr/i.test(lang || '') ? 'fr' : 'en';
    const dict = DICTS[l];
    function t(key, params) {
      let s = dict[key] != null ? dict[key] : (en[key] != null ? en[key] : key);
      if (params) {
        for (const k in params) s = s.split('{' + k + '}').join(params[k] == null ? '' : String(params[k]));
      }
      return s;
    }
    return { lang: l, t };
  }

  const api = { DICTS, make };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.SPi18n = api;
})(typeof window !== 'undefined' ? window : this);
