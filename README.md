# Auto Article Importer Full - Extension WordPress

## Description

**Auto Article Importer Full** est une extension WordPress avancée qui permet d'importer automatiquement des articles **complets** depuis des flux RSS. Contrairement aux importateurs classiques qui ne récupèrent que les extraits, cette extension récupère le contenu intégral des articles directement depuis les pages web sources.

## 🚀 Nouveautés Version 2.0+

### Fonctionnalités principales ajoutées :

- **Import d'articles entiers** : Récupération du contenu complet depuis les pages web (pas seulement les extraits RSS)
- **Gestion avancée des images** : Import et optimisation automatique des images avec CSS responsive
- **Interface d'administration complète** : Gestion intuitive des flux RSS avec test en temps réel
- **Création rapide de catégories** : Possibilité de créer des catégories directement depuis l'interface
- **Import automatique programmé** : Exécution automatique toutes les heures via cron WordPress
- **Outils de maintenance** : Correction des images existantes et optimisation du contenu
- **Système de tracking** : Évite les doublons et suit les articles importés
- **Support HTTP/HTTPS** : Compatible avec tous types de flux RSS sécurisés ou non

### Améliorations techniques :

- **Parsing RSS robuste** : Gestion des flux RSS malformés avec nettoyage automatique
- **Gestion d'erreurs avancée** : Diagnostic complet des problèmes de flux
- **Optimisation mobile** : CSS responsive automatique pour tous les contenus importés
- **Sécurité renforcée** : Validation stricte des URLs et protection contre les injections
- **Performance optimisée** : Import en arrière-plan sans impact sur le site

## 📋 Prérequis

- WordPress 5.0 ou supérieur
- PHP 7.4 ou supérieur
- Extension cURL activée
- Permissions d'écriture dans le répertoire uploads

## 🔧 Installation

1. Téléchargez le fichier `auto-article-importer-full.php`
2. Placez-le dans le dossier `/wp-content/plugins/` de votre site WordPress
3. Activez l'extension depuis l'administration WordPress (Extensions > Extensions installées)
4. Accédez aux réglages via **Réglages > Auto Import Full**

## 📖 Guide d'utilisation

### Configuration d'un flux RSS

1. **Accédez à l'interface** : Réglages > Auto Import Full
2. **Ajoutez un nouveau flux** :
   - **Nom du site source** : Nom descriptif pour identifier le flux
   - **URL du flux RSS** : URL complète du flux (http:// ou https://)
   - **Catégorie** : Sélectionnez ou créez une catégorie WordPress
   - **Récupérer article entier** : Cochez pour importer le contenu complet (recommandé)

3. **Testez le flux** : Utilisez le bouton "Tester le flux RSS" pour vérifier la validité
4. **Ajoutez le flux** : Cliquez sur "Ajouter le flux"

### Gestion des catégories

- **Création rapide** : Utilisez le bouton "+ Créer une catégorie rapidement"
- **Gestion complète** : Accédez à Articles > Catégories pour une gestion avancée
- **Attribution automatique** : Chaque flux est associé à une catégorie spécifique

### Import des articles

#### Import automatique
- **Fréquence** : Toutes les heures automatiquement
- **Activation** : Se lance dès l'activation de l'extension
- **Gestion** : Via le système cron de WordPress

#### Import manuel
- **Import global** : Bouton "Importer tous les flux maintenant"
- **Import sélectif** : Bouton "Importer maintenant" pour chaque flux
- **Suivi en temps réel** : Affichage du nombre d'articles importés

### Outils de maintenance

#### Correction des images existantes
- **Fonction** : Corrige l'affichage des images dans les articles déjà importés
- **Optimisations** :
  - Suppression des dimensions fixes
  - Ajout des classes CSS responsive
  - Suppression des pixels de tracking
  - Optimisation pour mobile

## 🎨 Fonctionnalités CSS automatiques

L'extension ajoute automatiquement des styles CSS pour :

- **Images responsive** : Adaptation automatique à tous les écrans
- **Effets visuels** : Bordures arrondies et ombres subtiles
- **Animations** : Effet de zoom au survol
- **Optimisation mobile** : Affichage parfait sur smartphones et tablettes

## 🔍 Diagnostic et dépannage

### Test de flux RSS
L'outil de test intégré vérifie :
- Validité de l'URL du flux
- Accessibilité du contenu
- Nombre d'articles disponibles
- Informations sur le dernier article

### Gestion des erreurs
- **Flux inaccessibles** : Diagnostic automatique des problèmes de connexion
- **Contenu malformé** : Nettoyage automatique du XML
- **Parsing manuel** : Solution de secours pour les flux non-standard

## 📊 Base de données

L'extension crée deux tables :

### `wp_auto_import_feeds_full`
Stockage des flux RSS configurés :
- ID, nom, URL, catégorie
- Statut actif/inactif
- Date du dernier import
- Option de récupération complète

### `wp_auto_import_articles_full`
Tracking des articles importés :
- Liaison flux/article
- GUID unique pour éviter les doublons
- Date d'import
- ID de l'article WordPress créé

## 🛡️ Sécurité

- **Validation stricte** : Toutes les URLs sont validées
- **Nonces WordPress** : Protection contre les attaques CSRF
- **Permissions** : Vérification des droits administrateur
- **Échappement** : Tous les contenus sont sécurisés avant affichage

## 🔄 Désinstallation

1. Désactivez l'extension depuis l'administration WordPress
2. Les tâches cron sont automatiquement supprimées
3. Les tables de base de données sont conservées (suppression manuelle si nécessaire)

## 📝 Notes techniques

- **Timeout** : 30 secondes pour les requêtes HTTP
- **User-Agent** : Identification comme navigateur standard
- **SSL** : Vérification SSL désactivée pour compatibilité maximale
- **Redirections** : Jusqu'à 5 redirections autorisées

## 🆘 Support

Pour toute question ou problème :
1. Vérifiez les logs d'erreur WordPress
2. Testez le flux RSS avec l'outil intégré
3. Consultez la documentation WordPress sur les flux RSS
4. Contactez le support technique si nécessaire

## 📄 Licence

Cette extension est distribuée sous licence GPL v2 ou ultérieure, compatible avec WordPress.

---

**Version actuelle** : 2.1  
**Auteur** : Réveil Libertaire  
**Compatibilité** : WordPress 5.0+  
**Dernière mise à jour** : 2025