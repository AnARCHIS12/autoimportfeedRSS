<?php
/*
Plugin Name: Auto Article Importer Full
Description: Récupère automatiquement des articles ENTIERS depuis des flux RSS et les publie en mentionnant la source
Version: 2.1
Author: Réveil Libertaire
*/

// Sécurité
if (!defined('ABSPATH')) {
    exit;
}

class AutoArticleImporterFull {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('wp_ajax_add_feed', array($this, 'add_feed'));
        add_action('wp_ajax_delete_feed', array($this, 'delete_feed'));
        add_action('wp_ajax_import_now', array($this, 'import_now'));
        add_action('wp_ajax_create_category', array($this, 'create_category'));
        add_action('wp_ajax_test_feed', array($this, 'test_feed'));
        add_action('wp_ajax_fix_existing_images', array($this, 'fix_existing_images'));
        
        // Ajouter le CSS pour les images importées
        add_action('wp_head', array($this, 'add_imported_images_css'));
        
        // Cron job pour import automatique
        add_action('auto_import_articles_full', array($this, 'import_articles'));
        
        // Activation du plugin
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Programmer l'import automatique toutes les heures
        if (!wp_next_scheduled('auto_import_articles_full')) {
            wp_schedule_event(time(), 'hourly', 'auto_import_articles_full');
        }
    }
    
    public function add_imported_images_css() {
        ?>
        <style type="text/css">
        /* CSS pour les images importées */
        .imported-image,
        .responsive-image {
            max-width: 100% !important;
            height: auto !important;
            display: block;
            margin: 15px auto;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        
        .imported-image:hover,
        .responsive-image:hover {
            transform: scale(1.02);
        }
        
        /* Conteneurs d'images */
        .entry-content figure,
        .post-content figure {
            margin: 20px 0;
            text-align: center;
        }
        
        .entry-content figure img,
        .post-content figure img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
        }
        
        /* Images dans les paragraphes */
        .entry-content p img,
        .post-content p img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 10px auto;
            border-radius: 5px;
        }
        
        /* Responsive pour mobile */
        @media (max-width: 768px) {
            .imported-image,
            .responsive-image {
                margin: 10px auto;
                border-radius: 5px;
            }
        }
        
        /* Correction pour les images mal dimensionnées */
        .entry-content img[width],
        .post-content img[width] {
            width: auto !important;
            max-width: 100% !important;
        }
        
        .entry-content img[height],
        .post-content img[height] {
            height: auto !important;
        }
        
        /* Style pour les légendes d'images */
        .wp-caption {
            max-width: 100% !important;
            margin: 15px auto;
        }
        
        .wp-caption img {
            max-width: 100%;
            height: auto;
        }
        
        .wp-caption-text {
            font-size: 0.9em;
            color: #666;
            font-style: italic;
            text-align: center;
            margin-top: 8px;
        }
        </style>
        <?php
    }
    
    public function activate() {
        // Créer la table pour stocker les feeds
        global $wpdb;
        $table_name = $wpdb->prefix . 'auto_import_feeds_full';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            url varchar(500) NOT NULL,
            category_id int(11) DEFAULT 1,
            active tinyint(1) DEFAULT 1,
            scrape_full tinyint(1) DEFAULT 1,
            last_import datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Table pour tracker les articles importés
        $table_imported = $wpdb->prefix . 'auto_import_articles_full';
        $sql2 = "CREATE TABLE $table_imported (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            feed_id mediumint(9) NOT NULL,
            article_guid varchar(500) NOT NULL,
            post_id bigint(20) NOT NULL,
            imported_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_article (feed_id, article_guid)
        ) $charset_collate;";
        
        dbDelta($sql2);
        
        // Créer une catégorie par défaut si aucune n'existe
        $categories = get_categories(array('hide_empty' => false));
        if (empty($categories)) {
            wp_insert_category(array(
                'cat_name' => 'Articles Importés',
                'category_description' => 'Catégorie par défaut pour les articles importés automatiquement',
                'category_nicename' => 'articles-importes'
            ));
        }
    }
    
    public function deactivate() {
        wp_clear_scheduled_hook('auto_import_articles_full');
    }
    
    public function admin_menu() {
        add_options_page(
            'Auto Article Importer Full',
            'Auto Import Full',
            'manage_options',
            'auto-article-importer-full',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        global $wpdb;
        $feeds_table = $wpdb->prefix . 'auto_import_feeds_full';
        $feeds = $wpdb->get_results("SELECT * FROM $feeds_table ORDER BY name");
        // Récupérer toutes les catégories, même celles sans articles
        $categories = get_categories(array(
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        
        // Debug : afficher le nombre de catégories trouvées
        $categories_count = count($categories);
        ?>
        <div class="wrap">
            <h1>Auto Article Importer Full - Articles Entiers</h1>
            
            <?php if ($categories_count == 0): ?>
                <div class="notice notice-warning">
                    <p><strong>Aucune catégorie trouvée !</strong> Vous devez d'abord créer des catégories dans <a href="<?php echo admin_url('edit-tags.php?taxonomy=category'); ?>">Articles > Catégories</a>.</p>
                </div>
            <?php endif; ?>
            
            <div style="background: #fff; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h2>Ajouter un nouveau flux RSS</h2>
                <form id="add-feed-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="feed_name">Nom du site source</label></th>
                            <td><input type="text" id="feed_name" name="feed_name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="feed_url">URL du flux RSS</label></th>
                            <td>
                                <input type="url" id="feed_url" name="feed_url" class="regular-text" required>
                                <p class="description">
                                    Entrez l'URL complète du flux RSS (http:// et https:// sont supportés).
                                    <br>Exemple : http://example.com/feed ou https://example.com/rss
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="feed_category">Catégorie</label></th>
                            <td>
                                <select id="feed_category" name="feed_category" required>
                                    <option value="">-- Sélectionner une catégorie --</option>
                                    <?php if ($categories_count > 0): ?>
                                        <?php foreach($categories as $cat): ?>
                                            <option value="<?php echo $cat->term_id; ?>">
                                                <?php echo esc_html($cat->name); ?> 
                                                (<?php echo $cat->count; ?> articles)
                                                <?php if ($cat->parent > 0): ?>
                                                    - Sous-catégorie de <?php echo get_cat_name($cat->parent); ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="" disabled>Aucune catégorie disponible - Créez-en une d'abord</option>
                                    <?php endif; ?>
                                </select>
                                <p class="description">
                                    Les articles importés seront assignés à cette catégorie. 
                                    <?php if ($categories_count == 0): ?>
                                        <br><strong>Vous devez créer au moins une catégorie avant d'ajouter un flux.</strong>
                                        <a href="<?php echo admin_url('edit-tags.php?taxonomy=category'); ?>" target="_blank" class="button">Créer une catégorie</a>
                                    <?php else: ?>
                                        (<?php echo $categories_count; ?> catégories disponibles)
                                        <a href="<?php echo admin_url('edit-tags.php?taxonomy=category'); ?>" target="_blank" class="button button-small">Gérer les catégories</a>
                                    <?php endif; ?>
                                </p>
                                
                                <!-- Création rapide de catégorie -->
                                <div id="quick-category" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 3px; display: none;">
                                    <h4>Créer une nouvelle catégorie rapidement :</h4>
                                    <input type="text" id="new_category_name" placeholder="Nom de la catégorie" style="width: 200px;">
                                    <button type="button" id="create-category" class="button">Créer</button>
                                    <button type="button" id="cancel-category" class="button">Annuler</button>
                                </div>
                                <button type="button" id="show-quick-category" class="button button-small">+ Créer une catégorie rapidement</button>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="scrape_full">Récupérer article entier</label></th>
                            <td>
                                <input type="checkbox" id="scrape_full" name="scrape_full" value="1" checked>
                                <label for="scrape_full">Récupérer le contenu complet depuis la page web (recommandé)</label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="button" id="test-feed" class="button">Tester le flux RSS</button>
                        <input type="submit" class="button-primary" value="Ajouter le flux">
                    </p>
                </form>
            </div>
            
            <div style="background: #fff; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h2>Flux RSS configurés</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>URL</th>
                            <th>Catégorie</th>
                            <th>Article entier</th>
                            <th>Statut</th>
                            <th>Dernier import</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($feeds as $feed): ?>
                            <tr>
                                <td><?php echo esc_html($feed->name); ?></td>
                                <td><a href="<?php echo esc_url($feed->url); ?>" target="_blank"><?php echo esc_html($feed->url); ?></a></td>
                                <td><?php echo get_cat_name($feed->category_id); ?></td>
                                <td><?php echo $feed->scrape_full ? '✅ Oui' : '❌ Non'; ?></td>
                                <td><?php echo $feed->active ? 'Actif' : 'Inactif'; ?></td>
                                <td><?php echo $feed->last_import; ?></td>
                                <td>
                                    <button class="button import-now" data-feed-id="<?php echo $feed->id; ?>">Importer maintenant</button>
                                    <button class="button delete-feed" data-feed-id="<?php echo $feed->id; ?>">Supprimer</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="background: #fff; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h2>Import manuel</h2>
                <p><strong>Cette version récupère les articles ENTIERS depuis les pages web !</strong></p>
                <p>L'import automatique se fait toutes les heures. Vous pouvez aussi déclencher un import manuel :</p>
                <button id="import-all" class="button-primary">Importer tous les flux maintenant</button>
            </div>
            
            <div style="background: #fff; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h2>Outils de maintenance</h2>
                <p>Corriger l'affichage des images dans les articles déjà importés :</p>
                <button id="fix-images" class="button">Corriger les images existantes</button>
                <p class="description">
                    Cette fonction corrige les problèmes d'affichage des images dans tous les articles importés :
                    <br>• Supprime les dimensions fixes qui cassent le responsive
                    <br>• Ajoute les classes CSS pour un meilleur affichage
                    <br>• Supprime les pixels de tracking
                    <br>• Optimise les images pour mobile
                </p>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Test de flux RSS
            $('#test-feed').on('click', function() {
                var feedUrl = $('#feed_url').val().trim();
                if (!feedUrl) {
                    alert('Veuillez entrer une URL de flux RSS');
                    return;
                }
                
                var testBtn = $(this);
                testBtn.prop('disabled', true).text('Test en cours...');
                
                $.post(ajaxurl, {
                    action: 'test_feed',
                    url: feedUrl,
                    nonce: '<?php echo wp_create_nonce('auto_import_nonce'); ?>'
                }, function(response) {
                    if(response.success) {
                        var data = response.data;
                        var message = 'Flux RSS valide !\n\n';
                        message += 'Titre : ' + data.title + '\n';
                        message += 'Description : ' + data.description + '\n';
                        message += 'Nombre d\'articles trouvés : ' + data.items_count + '\n';
                        message += 'Dernier article : ' + data.latest_item.title + '\n';
                        message += 'Date : ' + data.latest_item.date;
                        alert(message);
                    } else {
                        alert('Erreur lors du test : ' + response.data);
                    }
                    testBtn.prop('disabled', false).text('Tester le flux RSS');
                }).fail(function() {
                    alert('Erreur de connexion lors du test');
                    testBtn.prop('disabled', false).text('Tester le flux RSS');
                });
            });
            
            // Gestion du formulaire d'ajout de flux
            $('#add-feed-form').on('submit', function(e) {
                e.preventDefault();
                
                // Vérifications côté client
                if (!$('#feed_category').val()) {
                    alert('Veuillez sélectionner une catégorie');
                    return;
                }
                
                var submitBtn = $(this).find('input[type="submit"]');
                submitBtn.prop('disabled', true).val('Ajout en cours...');
                
                $.post(ajaxurl, {
                    action: 'add_feed',
                    name: $('#feed_name').val(),
                    url: $('#feed_url').val(),
                    category: $('#feed_category').val(),
                    scrape_full: $('#scrape_full').is(':checked') ? 1 : 0,
                    nonce: '<?php echo wp_create_nonce('auto_import_nonce'); ?>'
                }, function(response) {
                    if(response.success) {
                        alert('Flux ajouté avec succès !');
                        location.reload();
                    } else {
                        alert('Erreur: ' + response.data);
                        submitBtn.prop('disabled', false).val('Ajouter le flux');
                    }
                }).fail(function() {
                    alert('Erreur de connexion. Veuillez réessayer.');
                    submitBtn.prop('disabled', false).val('Ajouter le flux');
                });
            });
            
            // Gestion de la création rapide de catégorie
            $('#show-quick-category').on('click', function() {
                $('#quick-category').slideDown();
                $(this).hide();
            });
            
            $('#cancel-category').on('click', function() {
                $('#quick-category').slideUp();
                $('#show-quick-category').show();
                $('#new_category_name').val('');
            });
            
            $('#create-category').on('click', function() {
                var categoryName = $('#new_category_name').val().trim();
                if (!categoryName) {
                    alert('Veuillez entrer un nom de catégorie');
                    return;
                }
                
                $(this).prop('disabled', true).text('Création...');
                
                $.post(ajaxurl, {
                    action: 'create_category',
                    name: categoryName,
                    nonce: '<?php echo wp_create_nonce('auto_import_nonce'); ?>'
                }, function(response) {
                    if(response.success) {
                        alert('Catégorie créée avec succès !');
                        location.reload();
                    } else {
                        alert('Erreur: ' + response.data);
                        $('#create-category').prop('disabled', false).text('Créer');
                    }
                }).fail(function() {
                    alert('Erreur de connexion. Veuillez réessayer.');
                    $('#create-category').prop('disabled', false).text('Créer');
                });
            });
            
            $('.delete-feed').on('click', function() {
                if(confirm('Êtes-vous sûr de vouloir supprimer ce flux ?')) {
                    $.post(ajaxurl, {
                        action: 'delete_feed',
                        feed_id: $(this).data('feed-id'),
                        nonce: '<?php echo wp_create_nonce('auto_import_nonce'); ?>'
                    }, function(response) {
                        if(response.success) {
                            location.reload();
                        }
                    });
                }
            });
            
            $('.import-now, #import-all').on('click', function() {
                var feedId = $(this).data('feed-id') || 'all';
                $(this).prop('disabled', true).text('Import en cours...');
                
                $.post(ajaxurl, {
                    action: 'import_now',
                    feed_id: feedId,
                    nonce: '<?php echo wp_create_nonce('auto_import_nonce'); ?>'
                }, function(response) {
                    alert(response.data);
                    location.reload();
                });
            });
            
            // Correction des images existantes
            $('#fix-images').on('click', function() {
                if (!confirm('Voulez-vous corriger l\'affichage des images dans tous les articles importés ? Cette opération peut prendre quelques minutes.')) {
                    return;
                }
                
                var fixBtn = $(this);
                fixBtn.prop('disabled', true).text('Correction en cours...');
                
                $.post(ajaxurl, {
                    action: 'fix_existing_images',
                    nonce: '<?php echo wp_create_nonce('auto_import_nonce'); ?>'
                }, function(response) {
                    if(response.success) {
                        alert('Correction terminée ! ' + response.data);
                    } else {
                        alert('Erreur lors de la correction : ' + response.data);
                    }
                    fixBtn.prop('disabled', false).text('Corriger les images existantes');
                }).fail(function() {
                    alert('Erreur de connexion lors de la correction');
                    fixBtn.prop('disabled', false).text('Corriger les images existantes');
                });
            });
        });
        </script>
        <?php
    }
    
    public function add_feed() {
        check_ajax_referer('auto_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permissions insuffisantes');
        }
        
        $category_id = intval($_POST['category']);
        
        // Vérifier qu'une catégorie a été sélectionnée
        if (empty($category_id)) {
            wp_send_json_error('Veuillez sélectionner une catégorie');
            return;
        }
        
        // Vérifier que la catégorie existe
        $category = get_category($category_id);
        if (!$category || is_wp_error($category)) {
            wp_send_json_error('La catégorie sélectionnée n\'existe pas (ID: ' . $category_id . ')');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'auto_import_feeds_full';
        
        // Valider l'URL (accepter HTTP et HTTPS)
        $feed_url = $_POST['url'];
        if (!filter_var($feed_url, FILTER_VALIDATE_URL) || 
            (!str_starts_with($feed_url, 'http://') && !str_starts_with($feed_url, 'https://'))) {
            wp_send_json_error('URL invalide. Veuillez entrer une URL valide commençant par http:// ou https://');
            return;
        }
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => sanitize_text_field($_POST['name']),
                'url' => esc_url_raw($feed_url),
                'category_id' => $category_id,
                'scrape_full' => intval($_POST['scrape_full'])
            )
        );
        
        if ($result) {
            wp_send_json_success('Flux ajouté avec succès dans la catégorie ' . get_cat_name($category_id));
        } else {
            wp_send_json_error('Erreur lors de l\'ajout du flux');
        }
    }
    
    public function delete_feed() {
        check_ajax_referer('auto_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permissions insuffisantes');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'auto_import_feeds_full';
        
        $result = $wpdb->delete($table_name, array('id' => intval($_POST['feed_id'])));
        
        if ($result) {
            wp_send_json_success('Flux supprimé');
        } else {
            wp_send_json_error('Erreur lors de la suppression');
        }
    }
    
    public function import_now() {
        check_ajax_referer('auto_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permissions insuffisantes');
        }
        
        $feed_id = $_POST['feed_id'];
        
        if ($feed_id === 'all') {
            $imported = $this->import_articles();
        } else {
            $imported = $this->import_single_feed(intval($feed_id));
        }
        
        wp_send_json_success("Import terminé. $imported articles entiers importés.");
    }
    
    public function fix_existing_images() {
        check_ajax_referer('auto_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permissions insuffisantes');
        }
        
        // Récupérer tous les articles importés
        $posts = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => 'imported_from',
                    'compare' => 'EXISTS'
                )
            )
        ));
        
        $fixed_count = 0;
        
        foreach ($posts as $post) {
            $content = $post->post_content;
            $fixed_content = $this->fix_content_images($content);
            
            if ($fixed_content !== $content) {
                wp_update_post(array(
                    'ID' => $post->ID,
                    'post_content' => $fixed_content
                ));
                $fixed_count++;
            }
        }
        
        wp_send_json_success("$fixed_count articles ont été corrigés pour un meilleur affichage des images.");
    }
    
    public function test_feed() {
        check_ajax_referer('auto_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permissions insuffisantes');
        }
        
        $feed_url = $_POST['url'];
        $debug_info = array();
        
        // Récupérer le contenu brut pour diagnostic
        $response = wp_remote_get($feed_url, array(
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress Article Importer)',
            'sslverify' => false,
            'redirection' => 5
        ));
        
        if (!is_wp_error($response)) {
            $xml_content = wp_remote_retrieve_body($response);
            $debug_info['content_length'] = strlen($xml_content);
            $debug_info['content_type'] = wp_remote_retrieve_header($response, 'content-type');
            
            // Analyser le contenu du flux
            $debug_info['feed_analysis'] = $this->analyze_feed_content($xml_content);
            
            // Vérifier les derniers caractères
            $debug_info['last_100_chars'] = substr($xml_content, -100);
            
            // Compter les lignes
            $debug_info['line_count'] = substr_count($xml_content, "\n") + 1;
            
            // Extraire un échantillon du début
            $debug_info['first_500_chars'] = substr($xml_content, 0, 500);
        }
        
        // Tester la connexion au flux RSS avec nettoyage XML
        $rss = $this->fetch_clean_rss($feed_url);
        
        if (is_wp_error($rss)) {
            // Si le nettoyage échoue, essayer la méthode standard
            add_filter('http_request_args', array($this, 'allow_http_feeds'), 10, 2);
            $rss = fetch_feed($feed_url);
            remove_filter('http_request_args', array($this, 'allow_http_feeds'), 10);
            
            if (is_wp_error($rss)) {
                // En dernier recours, essayer un parsing manuel
                if (!empty($xml_content)) {
                    $manual_parse = $this->manual_rss_parse($xml_content);
                    if ($manual_parse && !empty($manual_parse['items'])) {
                        wp_send_json_success(array(
                            'title' => $manual_parse['title'] ?: 'Flux RSS',
                            'description' => $manual_parse['description'] ?: 'Flux RSS détecté',
                            'items_count' => count($manual_parse['items']),
                            'latest_item' => array(
                                'title' => $manual_parse['items'][0]['title'],
                                'date' => $manual_parse['items'][0]['date'] ?: 'Date inconnue'
                            ),
                            'method' => 'manual_parse',
                            'debug' => $debug_info
                        ));
                        return;
                    }
                }
                
                $error_message = 'Impossible de récupérer le flux RSS : ' . $rss->get_error_message();
                if (!empty($debug_info)) {
                    $error_message .= "\n\nInformations de debug :\n" . json_encode($debug_info, JSON_PRETTY_PRINT);
                }
                wp_send_json_error($error_message);
                return;
            }
        }
        
        $items = $rss->get_items(0, 10);
        if (empty($items)) {
            // Si SimplePie ne trouve pas d'items mais que notre analyse en détecte, utiliser le parsing manuel
            if (!empty($debug_info['feed_analysis']['item_count']) && $debug_info['feed_analysis']['item_count'] > 0) {
                $manual_parse = $this->manual_rss_parse($xml_content);
                if ($manual_parse && !empty($manual_parse['items'])) {
                    wp_send_json_success(array(
                        'title' => $manual_parse['title'] ?: 'Flux RSS',
                        'description' => $manual_parse['description'] ?: 'Flux RSS détecté',
                        'items_count' => count($manual_parse['items']),
                        'latest_item' => array(
                            'title' => $manual_parse['items'][0]['title'],
                            'date' => $manual_parse['items'][0]['date'] ?: 'Date inconnue'
                        ),
                        'method' => 'manual_parse_fallback',
                        'note' => 'SimplePie a échoué mais le parsing manuel a réussi'
                    ));
                    return;
                }
            }
            
            // Essayer de diagnostiquer pourquoi il n'y a pas d'articles
            $feed_data = $rss->data;
            $debug_info['feed_type'] = get_class($rss);
            $debug_info['feed_data_type'] = is_object($feed_data) ? get_class($feed_data) : gettype($feed_data);
            
            // Essayer d'accéder aux données brutes
            if (is_object($feed_data) && method_exists($feed_data, 'get_items')) {
                $raw_items = $feed_data->get_items();
                $debug_info['raw_items_count'] = is_array($raw_items) ? count($raw_items) : 'non-array';
            }
            
            // Vérifier s'il y a des données dans le feed
            if (is_object($feed_data) && isset($feed_data->data)) {
                $debug_info['has_data'] = 'yes';
                if (is_string($feed_data->data)) {
                    $debug_info['data_snippet'] = substr($feed_data->data, 0, 500);
                }
            }
            
            $error_message = 'Le flux RSS ne contient aucun article détectable.';
            if (!empty($debug_info)) {
                $error_message .= "\n\nInformations de debug :\n" . json_encode($debug_info, JSON_PRETTY_PRINT);
            }
            
            wp_send_json_error($error_message);
            return;
        }
        
        $test_results = array(
            'title' => $rss->get_title(),
            'description' => $rss->get_description(),
            'items_count' => count($items),
            'latest_item' => array(
                'title' => $items[0]->get_title(),
                'date' => $items[0]->get_date('Y-m-d H:i:s')
            )
        );
        
        if (!empty($debug_info)) {
            $test_results['debug'] = $debug_info;
        }
        
        wp_send_json_success($test_results);
    }
    
    public function create_category() {
        check_ajax_referer('auto_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permissions insuffisantes');
        }
        
        $category_name = sanitize_text_field($_POST['name']);
        
        if (empty($category_name)) {
            wp_send_json_error('Le nom de la catégorie ne peut pas être vide');
            return;
        }
        
        // Vérifier si la catégorie existe déjà
        if (term_exists($category_name, 'category')) {
            wp_send_json_error('Une catégorie avec ce nom existe déjà');
            return;
        }
        
        // Créer la catégorie
        $result = wp_insert_category(array(
            'cat_name' => $category_name,
            'category_description' => 'Catégorie créée pour l\'import d\'articles',
            'category_nicename' => sanitize_title($category_name)
        ));
        
        if ($result && !is_wp_error($result)) {
            wp_send_json_success('Catégorie "' . $category_name . '" créée avec succès');
        } else {
            $error_message = is_wp_error($result) ? $result->get_error_message() : 'Erreur inconnue';
            wp_send_json_error('Erreur lors de la création de la catégorie: ' . $error_message);
        }
    }
    
    public function import_articles() {
        global $wpdb;
        $feeds_table = $wpdb->prefix . 'auto_import_feeds_full';
        $feeds = $wpdb->get_results("SELECT * FROM $feeds_table WHERE active = 1");
        
        $total_imported = 0;
        
        foreach ($feeds as $feed) {
            $imported = $this->import_single_feed($feed->id);
            $total_imported += $imported;
        }
        
        return $total_imported;
    }
    
    public function import_single_feed($feed_id) {
        global $wpdb;
        $feeds_table = $wpdb->prefix . 'auto_import_feeds_full';
        $imported_table = $wpdb->prefix . 'auto_import_articles_full';
        
        $feed = $wpdb->get_row($wpdb->prepare("SELECT * FROM $feeds_table WHERE id = %d", $feed_id));
        
        if (!$feed) {
            return 0;
        }
        
        // Récupérer le flux RSS avec nettoyage XML
        $rss = $this->fetch_clean_rss($feed->url);
        
        if (is_wp_error($rss)) {
            // Si le nettoyage échoue, essayer la méthode standard
            add_filter('http_request_args', array($this, 'allow_http_feeds'), 10, 2);
            $rss = fetch_feed($feed->url);
            remove_filter('http_request_args', array($this, 'allow_http_feeds'), 10);
            
            if (is_wp_error($rss)) {
                error_log('Erreur RSS pour ' . $feed->name . ': ' . $rss->get_error_message());
                return 0;
            }
        }
        
        $items = $rss->get_items(0, 10); // Limiter à 10 articles récents
        $imported_count = 0;
        
        // Si SimplePie ne trouve pas d'items, essayer le parsing manuel
        if (empty($items)) {
            $response = wp_remote_get($feed->url, array(
                'timeout' => 30,
                'user-agent' => 'Mozilla/5.0 (compatible; WordPress Article Importer)',
                'sslverify' => false,
                'redirection' => 5
            ));
            
            if (!is_wp_error($response)) {
                $xml_content = wp_remote_retrieve_body($response);
                $manual_parse = $this->manual_rss_parse($xml_content);
                
                if ($manual_parse && !empty($manual_parse['items'])) {
                    // Convertir les items du parsing manuel en format compatible
                    $items = $this->convert_manual_items_to_simplepie_format($manual_parse['items']);
                }
            }
        }
        
        foreach ($items as $item) {
            $guid = $item->get_id();
            
            // Vérifier si l'article existe déjà
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $imported_table WHERE feed_id = %d AND article_guid = %s",
                $feed_id, $guid
            ));
            
            if ($exists) {
                continue; // Article déjà importé
            }
            
            // Récupérer le contenu complet
            $content = $item->get_content();
            
            if ($feed->scrape_full) {
                $full_content = $this->get_full_article_content($item->get_permalink());
                if ($full_content && strlen(strip_tags($full_content)) > strlen(strip_tags($content))) {
                    $content = $full_content;
                }
            }
            
            // Récupérer l'URL de l'image mise en avant AVANT de formater le contenu
            $featured_image_url = $this->get_featured_image_url($item, $content);
            
            // Créer le post avec le contenu nettoyé (sans l'image mise en avant)
            $article_title = $item->get_title();
            $clean_content = $this->format_content($content, $item->get_permalink(), $feed->name, $article_title, $featured_image_url);
            
            $post_data = array(
                'post_title' => $article_title,
                'post_content' => $clean_content,
                'post_status' => 'publish',
                'post_date' => $item->get_date('Y-m-d H:i:s')
            );
            
            $post_id = wp_insert_post($post_data);
            
            // Assigner la catégorie après création du post
            if ($post_id && $feed->category_id) {
                wp_set_post_categories($post_id, array($feed->category_id));
            }
            
            if ($post_id) {
                // Définir l'image mise en avant si on en a trouvé une
                if ($featured_image_url) {
                    $attachment_id = $this->download_and_attach_image($featured_image_url, $post_id, $article_title);
                    if ($attachment_id) {
                        set_post_thumbnail($post_id, $attachment_id);
                        add_post_meta($post_id, 'featured_image_source', $featured_image_url);
                    }
                }
                
                // Enregistrer dans la table des articles importés
                $wpdb->insert(
                    $imported_table,
                    array(
                        'feed_id' => $feed_id,
                        'article_guid' => $guid,
                        'post_id' => $post_id
                    )
                );
                
                // Ajouter des métadonnées
                add_post_meta($post_id, 'source_name', $feed->name);
                add_post_meta($post_id, 'source_url', $item->get_permalink());
                add_post_meta($post_id, 'imported_from', $feed->url);
                add_post_meta($post_id, 'full_article', $feed->scrape_full ? 'yes' : 'no');
                
                $imported_count++;
            }
        }
        
        // Mettre à jour la date du dernier import
        $wpdb->update(
            $feeds_table,
            array('last_import' => current_time('mysql')),
            array('id' => $feed_id)
        );
        
        return $imported_count;
    }
    
    // Fonction pour permettre les flux RSS HTTP
    public function allow_http_feeds($args, $url) {
        // Permettre les connexions HTTP non sécurisées pour les flux RSS
        if (strpos($url, 'http://') === 0) {
            $args['sslverify'] = false;
            $args['timeout'] = 30;
        }
        return $args;
    }
    
    // Nettoyer et valider le XML du flux RSS
    private function clean_rss_xml($xml_content) {
        // Supprimer les caractères de contrôle invalides (sauf tabulation, saut de ligne, retour chariot)
        $xml_content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $xml_content);
        
        // Supprimer les caractères UTF-8 invalides
        $xml_content = mb_convert_encoding($xml_content, 'UTF-8', 'UTF-8');
        
        // Corriger les ampersands non échappés (mais pas ceux déjà échappés)
        $xml_content = preg_replace('/&(?!(?:amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);)/', '&amp;', $xml_content);
        
        // Supprimer les balises CDATA mal fermées ou orphelines
        $xml_content = preg_replace('/\]\]>(?!\s*<)/', ']]>', $xml_content);
        $xml_content = preg_replace('/<!\[CDATA\[(?![^]]*\]\]>)/', '<![CDATA[', $xml_content);
        
        // Corriger les balises XML mal fermées communes
        $xml_content = preg_replace('/<(\w+)([^>]*)>([^<]*)<\/(?!\1>)(\w+)>/', '<$1$2>$3</$1>', $xml_content);
        
        // Supprimer les caractères après la balise de fermeture du document
        if (preg_match('/<\/(?:rss|feed|rdf:RDF)>/i', $xml_content, $matches, PREG_OFFSET_CAPTURE)) {
            $end_pos = $matches[0][1] + strlen($matches[0][0]);
            $xml_content = substr($xml_content, 0, $end_pos);
        }
        
        return trim($xml_content);
    }
    
    // Valider le XML avec libxml
    private function validate_xml($xml_content) {
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        
        $dom = new DOMDocument();
        $dom->recover = true;
        $dom->strictErrorChecking = false;
        
        $result = $dom->loadXML($xml_content);
        $errors = libxml_get_errors();
        
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        
        if (!$result || !empty($errors)) {
            return false;
        }
        
        return true;
    }
    
    // Récupérer et nettoyer un flux RSS avec méthode agressive
    private function fetch_clean_rss($url) {
        // Récupérer le contenu brut du flux
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress Article Importer)',
            'sslverify' => false,
            'redirection' => 5
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $xml_content = wp_remote_retrieve_body($response);
        if (empty($xml_content)) {
            return new WP_Error('empty_feed', 'Le flux RSS est vide');
        }
        
        // Essayer plusieurs méthodes de nettoyage progressivement
        $cleaning_methods = array(
            'basic' => array($this, 'clean_rss_xml_basic'),
            'aggressive' => array($this, 'clean_rss_xml_aggressive'),
            'reconstruct' => array($this, 'reconstruct_rss_from_content')
        );
        
        foreach ($cleaning_methods as $method_name => $method) {
            $clean_xml = call_user_func($method, $xml_content);
            
            if ($clean_xml && $this->validate_xml($clean_xml)) {
                // Créer un fichier temporaire avec le XML nettoyé
                $temp_file = wp_tempnam('rss_clean_' . $method_name);
                if ($temp_file) {
                    file_put_contents($temp_file, $clean_xml);
                    
                    // Utiliser fetch_feed avec le fichier temporaire
                    $rss = fetch_feed($temp_file);
                    
                    // Supprimer le fichier temporaire
                    if (file_exists($temp_file)) {
                        unlink($temp_file);
                    }
                    
                    if (!is_wp_error($rss)) {
                        return $rss;
                    }
                }
            }
        }
        
        return new WP_Error('invalid_xml', 'Le flux RSS contient du XML invalide qui ne peut pas être corrigé automatiquement');
    }
    
    // Nettoyage XML basique
    private function clean_rss_xml_basic($xml_content) {
        return $this->clean_rss_xml($xml_content);
    }
    
    // Nettoyage XML agressif
    private function clean_rss_xml_aggressive($xml_content) {
        // Supprimer tout ce qui suit la balise de fermeture RSS/feed
        $xml_content = preg_replace('/(<\/(?:rss|feed|rdf:RDF)>).*$/si', '$1', $xml_content);
        
        // Supprimer les caractères de contrôle et non-printables
        $xml_content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/', '', $xml_content);
        
        // Corriger l'encodage
        $xml_content = mb_convert_encoding($xml_content, 'UTF-8', 'UTF-8');
        
        // Supprimer les BOM
        $xml_content = preg_replace('/^\xEF\xBB\xBF/', '', $xml_content);
        
        // Corriger les ampersands
        $xml_content = preg_replace('/&(?!(?:amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);)/', '&amp;', $xml_content);
        
        // Supprimer les balises non fermées à la fin
        $xml_content = preg_replace('/<[^>]*$/', '', $xml_content);
        
        // S'assurer que le document se termine correctement
        if (!preg_match('/<\/(?:rss|feed|rdf:RDF)>\s*$/', $xml_content)) {
            if (preg_match('/<(rss|feed|rdf:RDF)[^>]*>/', $xml_content, $matches)) {
                $root_tag = $matches[1];
                $xml_content = rtrim($xml_content) . '</' . $root_tag . '>';
            }
        }
        
        return trim($xml_content);
    }
    
    // Reconstruire le RSS à partir du contenu analysé
    private function reconstruct_rss_from_content($xml_content) {
        // Parser avec DOMDocument en mode récupération
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->recover = true;
        $dom->strictErrorChecking = false;
        
        // Essayer de charger le XML même s'il est cassé
        $loaded = $dom->loadXML($xml_content);
        
        if (!$loaded) {
            // Si ça échoue complètement, essayer de parser comme HTML
            $dom->loadHTML('<?xml encoding="UTF-8">' . $xml_content);
        }
        
        libxml_clear_errors();
        
        // Créer un nouveau document RSS valide
        $new_dom = new DOMDocument('1.0', 'UTF-8');
        $new_dom->formatOutput = true;
        
        // Créer la structure RSS de base
        $rss = $new_dom->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $new_dom->appendChild($rss);
        
        $channel = $new_dom->createElement('channel');
        $rss->appendChild($channel);
        
        // Extraire les informations du channel
        $xpath = new DOMXPath($dom);
        
        // Titre du channel
        $title_nodes = $xpath->query('//channel/title | //feed/title');
        if ($title_nodes->length > 0) {
            $title = $new_dom->createElement('title');
            $title->appendChild($new_dom->createTextNode($title_nodes->item(0)->textContent));
            $channel->appendChild($title);
        }
        
        // Description du channel
        $desc_nodes = $xpath->query('//channel/description | //feed/subtitle');
        if ($desc_nodes->length > 0) {
            $description = $new_dom->createElement('description');
            $description->appendChild($new_dom->createTextNode($desc_nodes->item(0)->textContent));
            $channel->appendChild($description);
        }
        
        // Link du channel
        $link_nodes = $xpath->query('//channel/link | //feed/link[@rel="alternate"]/@href');
        if ($link_nodes->length > 0) {
            $link = $new_dom->createElement('link');
            $link_value = $link_nodes->item(0)->nodeValue ?: $link_nodes->item(0)->textContent;
            $link->appendChild($new_dom->createTextNode($link_value));
            $channel->appendChild($link);
        }
        
        // Extraire les items avec différents sélecteurs
        $item_selectors = array(
            '//item',
            '//entry', 
            '//channel/item',
            '//feed/entry',
            '//*[local-name()="item"]',
            '//*[local-name()="entry"]'
        );
        
        $item_nodes = array();
        foreach ($item_selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                foreach ($nodes as $node) {
                    $item_nodes[] = $node;
                }
                break; // Utiliser le premier sélecteur qui fonctionne
            }
        }
        
        foreach ($item_nodes as $old_item) {
            $new_item = $new_dom->createElement('item');
            
            // Titre de l'item
            $item_title = $xpath->query('.//title', $old_item);
            if ($item_title->length > 0) {
                $title_elem = $new_dom->createElement('title');
                $title_elem->appendChild($new_dom->createTextNode($item_title->item(0)->textContent));
                $new_item->appendChild($title_elem);
            }
            
            // Lien de l'item
            $item_link = $xpath->query('.//link | .//link[@rel="alternate"]/@href', $old_item);
            if ($item_link->length > 0) {
                $link_elem = $new_dom->createElement('link');
                $link_value = $item_link->item(0)->nodeValue ?: $item_link->item(0)->textContent;
                $link_elem->appendChild($new_dom->createTextNode($link_value));
                $new_item->appendChild($link_elem);
            }
            
            // Description/contenu de l'item
            $item_desc = $xpath->query('.//description | .//content | .//summary', $old_item);
            if ($item_desc->length > 0) {
                $desc_elem = $new_dom->createElement('description');
                $desc_elem->appendChild($new_dom->createCDATASection($item_desc->item(0)->textContent));
                $new_item->appendChild($desc_elem);
            }
            
            // Date de publication
            $item_date = $xpath->query('.//pubDate | .//published | .//updated', $old_item);
            if ($item_date->length > 0) {
                $date_elem = $new_dom->createElement('pubDate');
                $date_elem->appendChild($new_dom->createTextNode($item_date->item(0)->textContent));
                $new_item->appendChild($date_elem);
            }
            
            // GUID
            $item_guid = $xpath->query('.//guid | .//id', $old_item);
            if ($item_guid->length > 0) {
                $guid_elem = $new_dom->createElement('guid');
                $guid_elem->appendChild($new_dom->createTextNode($item_guid->item(0)->textContent));
                $new_item->appendChild($guid_elem);
            }
            
            $channel->appendChild($new_item);
        }
        
        return $new_dom->saveXML();
    }
    
    // Analyser le flux RSS avec une approche alternative
    private function analyze_feed_content($xml_content) {
        $analysis = array();
        
        // Compter les différents types d'éléments
        $analysis['has_rss_tag'] = preg_match('/<rss[^>]*>/i', $xml_content) ? 'yes' : 'no';
        $analysis['has_feed_tag'] = preg_match('/<feed[^>]*>/i', $xml_content) ? 'yes' : 'no';
        $analysis['has_channel_tag'] = preg_match('/<channel[^>]*>/i', $xml_content) ? 'yes' : 'no';
        
        // Compter les items/entries
        $analysis['item_count'] = preg_match_all('/<item[^>]*>/i', $xml_content);
        $analysis['entry_count'] = preg_match_all('/<entry[^>]*>/i', $xml_content);
        
        // Vérifier la structure
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $xml_content, $matches)) {
            $analysis['first_title'] = trim($matches[1]);
        }
        
        // Vérifier les namespaces
        $analysis['has_atom_ns'] = preg_match('/xmlns[^=]*=["\'][^"\']*atom[^"\']*["\']/', $xml_content) ? 'yes' : 'no';
        $analysis['has_rdf_ns'] = preg_match('/xmlns[^=]*=["\'][^"\']*rdf[^"\']*["\']/', $xml_content) ? 'yes' : 'no';
        
        return $analysis;
    }
    
    // Parser manuellement le RSS quand tout le reste échoue
    private function manual_rss_parse($xml_content) {
        $result = array(
            'title' => '',
            'description' => '',
            'items' => array()
        );
        
        // Extraire le titre du flux
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $xml_content, $matches)) {
            $result['title'] = trim(strip_tags($matches[1]));
        }
        
        // Extraire la description du flux
        if (preg_match('/<description[^>]*>([^<]+)<\/description>/i', $xml_content, $matches)) {
            $result['description'] = trim(strip_tags($matches[1]));
        }
        
        // Extraire les items avec regex (méthode de dernier recours)
        $item_pattern = '/<item[^>]*>(.*?)<\/item>/si';
        if (preg_match_all($item_pattern, $xml_content, $item_matches)) {
            foreach ($item_matches[1] as $item_content) {
                $item = array();
                
                // Titre de l'item
                if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $item_content, $title_match)) {
                    $item['title'] = trim(strip_tags($title_match[1]));
                }
                
                // Lien de l'item
                if (preg_match('/<link[^>]*>([^<]+)<\/link>/i', $item_content, $link_match)) {
                    $item['link'] = trim($link_match[1]);
                }
                
                // Description de l'item
                if (preg_match('/<description[^>]*>(.*?)<\/description>/si', $item_content, $desc_match)) {
                    $description = trim($desc_match[1]);
                    // Nettoyer le contenu CDATA
                    if (preg_match('/^<!\[CDATA\[(.*?)\]\]>$/s', $description, $cdata_match)) {
                        $description = $cdata_match[1];
                    }
                    // Nettoyer le HTML mal encodé
                    $description = $this->clean_malformed_html($description);
                    $item['description'] = $description;
                }
                
                // Date de l'item
                if (preg_match('/<pubDate[^>]*>([^<]+)<\/pubDate>/i', $item_content, $date_match)) {
                    $item['date'] = trim($date_match[1]);
                }
                
                // GUID de l'item
                if (preg_match('/<guid[^>]*>([^<]+)<\/guid>/i', $item_content, $guid_match)) {
                    $item['guid'] = trim($guid_match[1]);
                }
                
                if (!empty($item['title'])) {
                    $result['items'][] = $item;
                }
            }
        }
        
        // Si pas d'items trouvés, essayer avec les entries (Atom)
        if (empty($result['items'])) {
            $entry_pattern = '/<entry[^>]*>(.*?)<\/entry>/si';
            if (preg_match_all($entry_pattern, $xml_content, $entry_matches)) {
                foreach ($entry_matches[1] as $entry_content) {
                    $item = array();
                    
                    // Titre de l'entry
                    if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $entry_content, $title_match)) {
                        $item['title'] = trim(strip_tags($title_match[1]));
                    }
                    
                    // Lien de l'entry
                    if (preg_match('/<link[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $entry_content, $link_match)) {
                        $item['link'] = trim($link_match[1]);
                    }
                    
                    // Contenu de l'entry
                    if (preg_match('/<content[^>]*>(.*?)<\/content>/si', $entry_content, $content_match)) {
                        $description = trim($content_match[1]);
                        // Nettoyer le contenu CDATA
                        if (preg_match('/^<!\[CDATA\[(.*?)\]\]>$/s', $description, $cdata_match)) {
                            $description = $cdata_match[1];
                        }
                        // Nettoyer le HTML mal encodé
                        $description = $this->clean_malformed_html($description);
                        $item['description'] = $description;
                    } elseif (preg_match('/<summary[^>]*>(.*?)<\/summary>/si', $entry_content, $summary_match)) {
                        $description = trim($summary_match[1]);
                        // Nettoyer le contenu CDATA
                        if (preg_match('/^<!\[CDATA\[(.*?)\]\]>$/s', $description, $cdata_match)) {
                            $description = $cdata_match[1];
                        }
                        // Nettoyer le HTML mal encodé
                        $description = $this->clean_malformed_html($description);
                        $item['description'] = $description;
                    }
                    
                    // Date de l'entry
                    if (preg_match('/<(?:published|updated)[^>]*>([^<]+)<\/(?:published|updated)>/i', $entry_content, $date_match)) {
                        $item['date'] = trim($date_match[1]);
                    }
                    
                    // ID de l'entry
                    if (preg_match('/<id[^>]*>([^<]+)<\/id>/i', $entry_content, $id_match)) {
                        $item['guid'] = trim($id_match[1]);
                    }
                    
                    if (!empty($item['title'])) {
                        $result['items'][] = $item;
                    }
                }
            }
        }
        
        return $result;
    }
    
    // Convertir les items du parsing manuel en objets compatibles avec SimplePie
    private function convert_manual_items_to_simplepie_format($manual_items) {
        $converted_items = array();
        
        foreach ($manual_items as $manual_item) {
            $item_obj = new stdClass();
            
            // Simuler les méthodes de SimplePie_Item
            $item_obj->get_title = function() use ($manual_item) {
                return isset($manual_item['title']) ? html_entity_decode($manual_item['title'], ENT_QUOTES, 'UTF-8') : '';
            };
            
            $item_obj->get_permalink = function() use ($manual_item) {
                return isset($manual_item['link']) ? $manual_item['link'] : '';
            };
            
            $item_obj->get_content = function() use ($manual_item) {
                return isset($manual_item['description']) ? $manual_item['description'] : '';
            };
            
            $item_obj->get_id = function() use ($manual_item) {
                return isset($manual_item['guid']) ? $manual_item['guid'] : (isset($manual_item['link']) ? $manual_item['link'] : '');
            };
            
            $item_obj->get_date = function($format = 'Y-m-d H:i:s') use ($manual_item) {
                if (isset($manual_item['date']) && !empty($manual_item['date'])) {
                    $timestamp = strtotime($manual_item['date']);
                    if ($timestamp !== false) {
                        return date($format, $timestamp);
                    }
                }
                return date($format); // Date actuelle si pas de date
            };
            
            $item_obj->get_enclosures = function() {
                return array(); // Pas d'enclosures pour le parsing manuel simple
            };
            
            // Créer un wrapper qui simule SimplePie_Item
            $wrapper = new class($item_obj) {
                private $item;
                
                public function __construct($item) {
                    $this->item = $item;
                }
                
                public function __call($method, $args) {
                    if (isset($this->item->$method) && is_callable($this->item->$method)) {
                        return call_user_func_array($this->item->$method, $args);
                    }
                    return null;
                }
                
                public function get_title() {
                    return call_user_func($this->item->get_title);
                }
                
                public function get_permalink() {
                    return call_user_func($this->item->get_permalink);
                }
                
                public function get_content() {
                    return call_user_func($this->item->get_content);
                }
                
                public function get_id() {
                    return call_user_func($this->item->get_id);
                }
                
                public function get_date($format = 'Y-m-d H:i:s') {
                    return call_user_func($this->item->get_date, $format);
                }
                
                public function get_enclosures() {
                    return call_user_func($this->item->get_enclosures);
                }
            };
            
            $converted_items[] = $wrapper;
        }
        
        return $converted_items;
    }
    
    // Fonction pour récupérer l'article complet depuis la page web
    private function get_full_article_content($url) {
        // Récupérer le contenu de la page
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress Article Importer)',
            'sslverify' => false, // Permet les connexions HTTP et HTTPS non sécurisées
            'redirection' => 5
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return false;
        }
        
        // Utiliser DOMDocument pour parser le HTML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Sélecteurs communs pour le contenu d'article
        $selectors = array(
            '//article',
            '//*[@class="entry-content"]',
            '//*[@class="post-content"]',
            '//*[@class="content"]',
            '//*[@class="article-content"]',
            '//*[@id="content"]',
            '//*[@class="post-body"]',
            '//main',
            '//*[contains(@class, "content")]'
        );
        
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $content = '';
                foreach ($nodes as $node) {
                    $content .= $dom->saveHTML($node);
                }
                
                // Nettoyer le contenu
                $content = $this->clean_scraped_content($content);
                
                if (strlen(strip_tags($content)) > 200) { // Au moins 200 caractères de texte
                    return $content;
                }
            }
        }
        
        return false;
    }
    
    // Nettoyer le contenu récupéré
    private function clean_scraped_content($content) {
        // D'abord nettoyer le HTML mal encodé
        $content = $this->clean_malformed_html($content);
        
        // Supprimer les scripts, styles, commentaires
        $content = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $content);
        $content = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $content);
        $content = preg_replace('/<!--.*?-->/s', '', $content);
        
        // Supprimer les éléments de navigation, sidebar, footer, header
        $content = preg_replace('/<nav\b[^>]*>.*?<\/nav>/si', '', $content);
        $content = preg_replace('/<aside\b[^>]*>.*?<\/aside>/si', '', $content);
        $content = preg_replace('/<footer\b[^>]*>.*?<\/footer>/si', '', $content);
        $content = preg_replace('/<header\b[^>]*>.*?<\/header>/si', '', $content);
        
        // Supprimer les éléments qui contiennent souvent des titres dupliqués
        $content = preg_replace('/<div[^>]*class="[^"]*title[^"]*"[^>]*>.*?<\/div>/si', '', $content);
        $content = preg_replace('/<div[^>]*class="[^"]*headline[^"]*"[^>]*>.*?<\/div>/si', '', $content);
        $content = preg_replace('/<div[^>]*class="[^"]*entry-title[^"]*"[^>]*>.*?<\/div>/si', '', $content);
        $content = preg_replace('/<div[^>]*class="[^"]*post-title[^"]*"[^>]*>.*?<\/div>/si', '', $content);
        
        // Supprimer les métadonnées d'article (auteur, date) qui peuvent créer des doublons
        $content = preg_replace('/<div[^>]*class="[^"]*meta[^"]*"[^>]*>.*?<\/div>/si', '', $content);
        $content = preg_replace('/<div[^>]*class="[^"]*byline[^"]*"[^>]*>.*?<\/div>/si', '', $content);
        $content = preg_replace('/<div[^>]*class="[^"]*author[^"]*"[^>]*>.*?<\/div>/si', '', $content);
        $content = preg_replace('/<div[^>]*class="[^"]*date[^"]*"[^>]*>.*?<\/div>/si', '', $content);
        
        // Supprimer les structures Drupal spécifiques
        $content = preg_replace('/<div[^>]*class="[^"]*field[^"]*"[^>]*>.*?<\/div>/si', '', $content);
        
        // Supprimer les classes et IDs pour éviter les conflits CSS (mais garder les classes importantes)
        $content = preg_replace('/\s(?:id|class)="[^"]*field[^"]*"/i', '', $content);
        
        // Nettoyer les espaces multiples et balises vides
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/<p>\s*<\/p>/', '', $content);
        $content = preg_replace('/<div>\s*<\/div>/', '', $content);
        
        // Nettoyer avec wp_kses_post pour la sécurité
        $content = wp_kses_post($content);
        
        return trim($content);
    }
    
    private function format_content($content, $source_url, $source_name, $article_title = '', $featured_image_url = '') {
        // Nettoyer le contenu
        $content = wp_kses_post($content);
        
        // Supprimer les titres dupliqués au début du contenu
        if (!empty($article_title)) {
            $title_clean = trim(strip_tags($article_title));
            
            // Supprimer le titre s'il apparaît au début du contenu
            $content_clean = trim(strip_tags($content));
            if (strpos($content_clean, $title_clean) === 0) {
                // Le contenu commence par le titre, on le supprime
                $content = preg_replace('/^<[^>]*>' . preg_quote($title_clean, '/') . '<\/[^>]*>/', '', $content);
                $content = preg_replace('/^' . preg_quote($title_clean, '/') . '/', '', $content);
                $content = ltrim($content);
            }
            
            // Supprimer aussi les balises h1, h2, h3 qui contiennent le titre
            $content = preg_replace('/<h[1-6][^>]*>' . preg_quote($title_clean, '/') . '<\/h[1-6]>/i', '', $content);
        }
        
        // Corriger les images dans le contenu AVANT de supprimer l'image mise en avant
        $content = $this->fix_content_images($content);
        
        // Supprimer l'image mise en avant du contenu pour éviter la duplication
        if (!empty($featured_image_url)) {
            $content = $this->remove_featured_image_from_content($content, $featured_image_url);
        }
        
        // Supprimer les éléments indésirables qui peuvent causer des doublons
        $content = preg_replace('/<header[^>]*>.*?<\/header>/si', '', $content);
        $content = preg_replace('/<div[^>]*class="[^"]*title[^"]*"[^>]*>.*?<\/div>/si', '', $content);
        $content = preg_replace('/<div[^>]*class="[^"]*headline[^"]*"[^>]*>.*?<\/div>/si', '', $content);
        
        // Nettoyer les espaces multiples et les balises vides
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/<p>\s*<\/p>/', '', $content);
        $content = preg_replace('/<div>\s*<\/div>/', '', $content);
        
        // Ajouter la source à la fin
        $source_credit = sprintf(
            '<hr><p><em>Source: <a href="%s" target="_blank" rel="noopener">%s</a></em></p>',
            esc_url($source_url),
            esc_html($source_name)
        );
        
        return trim($content) . $source_credit;
    }
    
    // Corriger les images dans le contenu pour un meilleur affichage
    private function fix_content_images($content) {
        // D'abord nettoyer le HTML mal encodé
        $content = $this->clean_malformed_html($content);
        
        // Utiliser DOMDocument pour traiter les images de manière plus précise
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        $images = $xpath->query('//img');
        
        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            
            // Ignorer les images trop petites (pixels de tracking)
            $width = $img->getAttribute('width');
            $height = $img->getAttribute('height');
            if (($width && $width < 50) || ($height && $height < 50)) {
                $img->parentNode->removeChild($img);
                continue;
            }
            
            // Corriger les URLs relatives et vérifier la validité
            if ($src) {
                // Si l'URL est relative, essayer de la convertir en absolue
                if (!filter_var($src, FILTER_VALIDATE_URL)) {
                    // URL relative - on la passe pour l'instant mais on pourrait l'améliorer
                    continue;
                }
                
                // Vérifier que l'image n'est pas un pixel de tracking
                if (preg_match('/\b(1x1|pixel|tracking|analytics|beacon)\b/i', $src) || 
                    preg_match('/\.(gif|png|jpg|jpeg)\?.*track/i', $src)) {
                    $img->parentNode->removeChild($img);
                    continue;
                }
                
                // Vérifier les dimensions dans l'URL (certains sites mettent des dimensions très petites)
                if (preg_match('/[?&]w=(\d+)/', $src, $matches) && $matches[1] < 100) {
                    // Essayer de supprimer le paramètre de largeur pour obtenir l'image originale
                    $src = preg_replace('/[?&]w=\d+/', '', $src);
                    $img->setAttribute('src', $src);
                }
            }
            
            // Supprimer les attributs de style qui peuvent casser l'affichage
            $img->removeAttribute('style');
            
            // Supprimer les dimensions fixes pour permettre le responsive
            $img->removeAttribute('width');
            $img->removeAttribute('height');
            
            // Ajouter des classes CSS pour un meilleur affichage
            $existing_class = $img->getAttribute('class');
            $new_class = trim($existing_class . ' imported-image responsive-image');
            $img->setAttribute('class', $new_class);
            
            // Ajouter des attributs pour le lazy loading
            $img->setAttribute('loading', 'lazy');
            
            // Ajouter un alt text si manquant
            if (!$img->getAttribute('alt')) {
                $img->setAttribute('alt', 'Image de l\'article importé');
            }
        }
        
        // Récupérer le contenu modifié
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body) {
            $modified_content = '';
            foreach ($body->childNodes as $node) {
                $modified_content .= $dom->saveHTML($node);
            }
            return $modified_content;
        }
        
        return $content;
    }
    
    // Nettoyer le HTML mal encodé et les structures inutiles
    private function clean_malformed_html($content) {
        // Décoder les entités HTML multiples
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'); // Deux fois pour les doubles encodages
        
        // Corriger les guillemets échappés
        $content = str_replace('\"', '"', $content);
        $content = str_replace("\'", "'", $content);
        
        // Supprimer les divs de structure Drupal inutiles
        $content = preg_replace('/<div class="field[^"]*field-name-[^"]*"[^>]*>/', '', $content);
        $content = preg_replace('/<div class="field-items"[^>]*>/', '', $content);
        $content = preg_replace('/<div class="field-item[^"]*"[^>]*>/', '', $content);
        
        // Supprimer les divs fermantes orphelines
        $content = preg_replace('/<\/div>\s*<\/div>\s*<\/div>/', '', $content);
        $content = preg_replace('/<\/div>\s*<\/div>/', '', $content);
        
        // Nettoyer les attributs Drupal inutiles
        $content = preg_replace('/\s+property="[^"]*"/', '', $content);
        $content = preg_replace('/\s+typeof="[^"]*"/', '', $content);
        
        // Supprimer les liens vers les images qui sont juste des wrappers
        $content = preg_replace('/<a href="[^"]*"[^>]*>(\s*<img[^>]*>\s*)<\/a>/', '$1', $content);
        
        // Nettoyer les classes CSS inutiles
        $content = preg_replace('/class="[^"]*field[^"]*"/', '', $content);
        $content = preg_replace('/class="img-responsive"/', 'class="imported-image responsive-image"', $content);
        
        // Supprimer les espaces multiples et les balises vides
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/<p>\s*<\/p>/', '', $content);
        $content = preg_replace('/<div>\s*<\/div>/', '', $content);
        
        // Nettoyer les sauts de ligne multiples
        $content = preg_replace('/(<br\s*\/?>){3,}/', '<br><br>', $content);
        
        return trim($content);
    }
    
    // Récupérer l'URL de l'image mise en avant (sans la télécharger)
    private function get_featured_image_url($rss_item, $content) {
        $image_url = null;
        
        // 1. Essayer de récupérer l'image depuis les enclosures RSS
        $enclosures = $rss_item->get_enclosures();
        if ($enclosures) {
            foreach ($enclosures as $enclosure) {
                $type = $enclosure->get_type();
                if ($type && strpos($type, 'image/') === 0) {
                    $image_url = $enclosure->get_link();
                    break;
                }
            }
        }
        
        // 2. Si pas d'enclosure, chercher dans le contenu RSS
        if (!$image_url) {
            $rss_content = $rss_item->get_content();
            if ($rss_content) {
                $image_url = $this->extract_first_image_from_content($rss_content);
            }
        }
        
        // 3. Si toujours pas d'image, chercher dans le contenu complet récupéré
        if (!$image_url && $content) {
            $image_url = $this->extract_first_image_from_content($content);
        }
        
        // 4. En dernier recours, essayer de récupérer depuis la page web
        if (!$image_url) {
            $image_url = $this->get_featured_image_from_webpage($rss_item->get_permalink());
        }
        
        return $image_url;
    }
    
    // Supprimer l'image mise en avant du contenu pour éviter la duplication
    private function remove_featured_image_from_content($content, $featured_image_url) {
        if (empty($featured_image_url)) {
            return $content;
        }
        
        // Extraire le nom de fichier de l'URL pour une correspondance plus flexible
        $image_filename = basename(parse_url($featured_image_url, PHP_URL_PATH));
        $image_filename_no_ext = pathinfo($image_filename, PATHINFO_FILENAME);
        
        // Supprimer les balises img qui correspondent à l'image mise en avant
        // Correspondance exacte de l'URL
        $content = preg_replace('/<img[^>]+src=["\']' . preg_quote($featured_image_url, '/') . '["\'][^>]*>/i', '', $content);
        
        // Correspondance par nom de fichier (au cas où l'URL serait légèrement différente)
        if (!empty($image_filename)) {
            $content = preg_replace('/<img[^>]+src=["\'][^"\']*' . preg_quote($image_filename, '/') . '["\'][^>]*>/i', '', $content);
        }
        
        // Supprimer aussi les figures/divs qui contiennent cette image
        $content = preg_replace('/<figure[^>]*>.*?<img[^>]+src=["\'][^"\']*' . preg_quote($image_filename, '/') . '[^"\']*["\'][^>]*>.*?<\/figure>/si', '', $content);
        $content = preg_replace('/<div[^>]*>.*?<img[^>]+src=["\'][^"\']*' . preg_quote($image_filename, '/') . '[^"\']*["\'][^>]*>.*?<\/div>/si', '', $content);
        
        // Supprimer les paragraphes vides qui pourraient rester
        $content = preg_replace('/<p>\s*<\/p>/', '', $content);
        $content = preg_replace('/<figure>\s*<\/figure>/', '', $content);
        $content = preg_replace('/<div>\s*<\/div>/', '', $content);
        
        return $content;
    }
    
    // Récupérer et définir l'image mise en avant (fonction obsolète, gardée pour compatibilité)
    private function set_featured_image($post_id, $rss_item, $content) {
        $image_url = null;
        
        // 1. Essayer de récupérer l'image depuis les enclosures RSS
        $enclosures = $rss_item->get_enclosures();
        if ($enclosures) {
            foreach ($enclosures as $enclosure) {
                $type = $enclosure->get_type();
                if ($type && strpos($type, 'image/') === 0) {
                    $image_url = $enclosure->get_link();
                    break;
                }
            }
        }
        
        // 2. Si pas d'enclosure, chercher dans le contenu RSS
        if (!$image_url) {
            $rss_content = $rss_item->get_content();
            if ($rss_content) {
                $image_url = $this->extract_first_image_from_content($rss_content);
            }
        }
        
        // 3. Si toujours pas d'image, chercher dans le contenu complet récupéré
        if (!$image_url && $content) {
            $image_url = $this->extract_first_image_from_content($content);
        }
        
        // 4. En dernier recours, essayer de récupérer depuis la page web
        if (!$image_url) {
            $image_url = $this->get_featured_image_from_webpage($rss_item->get_permalink());
        }
        
        // Télécharger et définir l'image comme mise en avant
        if ($image_url) {
            $attachment_id = $this->download_and_attach_image($image_url, $post_id, $rss_item->get_title());
            if ($attachment_id) {
                set_post_thumbnail($post_id, $attachment_id);
                add_post_meta($post_id, 'featured_image_source', $image_url);
            }
        }
    }
    
    // Extraire la première image du contenu HTML
    private function extract_first_image_from_content($content) {
        // Chercher les balises img
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            $image_url = $matches[1];
            
            // Vérifier que l'URL est valide et pas trop petite (éviter les pixels de tracking)
            if (filter_var($image_url, FILTER_VALIDATE_URL)) {
                // Éviter les images trop petites (probablement des pixels de tracking)
                if (!preg_match('/\b(1x1|pixel|tracking|analytics)\b/i', $image_url)) {
                    return $image_url;
                }
            }
        }
        
        return null;
    }
    
    // Récupérer l'image mise en avant depuis la page web
    private function get_featured_image_from_webpage($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress Article Importer)',
            'sslverify' => false,
            'redirection' => 5
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return null;
        }
        
        // Chercher les meta tags Open Graph
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            return $matches[1];
        }
        
        // Chercher les meta tags Twitter
        if (preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            return $matches[1];
        }
        
        // Chercher dans le contenu de l'article
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Sélecteurs pour les images d'article
        $selectors = array(
            '//article//img[1]',
            '//*[@class="featured-image"]//img',
            '//*[@class="post-thumbnail"]//img',
            '//*[@class="entry-content"]//img[1]',
            '//*[@class="content"]//img[1]',
            '//main//img[1]'
        );
        
        foreach ($selectors as $selector) {
            $images = $xpath->query($selector);
            if ($images->length > 0) {
                $src = $images->item(0)->getAttribute('src');
                if ($src && filter_var($src, FILTER_VALIDATE_URL)) {
                    return $src;
                }
            }
        }
        
        return null;
    }
    
    // Télécharger l'image et l'attacher au post
    private function download_and_attach_image($image_url, $post_id, $post_title) {
        // Vérifier si l'image existe déjà
        $existing = get_posts(array(
            'post_type' => 'attachment',
            'meta_query' => array(
                array(
                    'key' => 'original_image_url',
                    'value' => $image_url,
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1
        ));
        
        if ($existing) {
            return $existing[0]->ID;
        }
        
        // Télécharger l'image
        $response = wp_remote_get($image_url, array(
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress Article Importer)',
            'sslverify' => false,
            'redirection' => 5
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $image_data = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        
        if (empty($image_data) || !$content_type || strpos($content_type, 'image/') !== 0) {
            return false;
        }
        
        // Générer un nom de fichier
        $filename = basename(parse_url($image_url, PHP_URL_PATH));
        if (empty($filename) || !preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $filename)) {
            $extension = '';
            switch ($content_type) {
                case 'image/jpeg':
                    $extension = '.jpg';
                    break;
                case 'image/png':
                    $extension = '.png';
                    break;
                case 'image/gif':
                    $extension = '.gif';
                    break;
                case 'image/webp':
                    $extension = '.webp';
                    break;
                default:
                    $extension = '.jpg';
            }
            $filename = sanitize_title($post_title) . '-featured' . $extension;
        }
        
        // Sauvegarder l'image
        $upload = wp_upload_bits($filename, null, $image_data);
        
        if ($upload['error']) {
            return false;
        }
        
        // Créer l'attachment
        $attachment = array(
            'post_mime_type' => $content_type,
            'post_title' => sanitize_text_field($post_title . ' - Image'),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $post_id
        );
        
        $attachment_id = wp_insert_attachment($attachment, $upload['file'], $post_id);
        
        if ($attachment_id) {
            // Générer les métadonnées de l'image et les différentes tailles
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
            
            // Ajouter des tailles personnalisées si nécessaire
            if (!empty($attachment_data)) {
                // Vérifier que les tailles standard existent
                $image_sizes = array('thumbnail', 'medium', 'medium_large', 'large');
                foreach ($image_sizes as $size) {
                    if (!isset($attachment_data['sizes'][$size])) {
                        // Régénérer cette taille si elle manque
                        $resized = image_make_intermediate_size($upload['file'], 
                            get_option($size . '_size_w'), 
                            get_option($size . '_size_h'), 
                            get_option($size . '_crop')
                        );
                        if ($resized) {
                            $attachment_data['sizes'][$size] = $resized;
                        }
                    }
                }
            }
            
            wp_update_attachment_metadata($attachment_id, $attachment_data);
            
            // Sauvegarder l'URL originale pour éviter les doublons
            add_post_meta($attachment_id, 'original_image_url', $image_url);
            add_post_meta($attachment_id, 'imported_image', 'yes');
            
            return $attachment_id;
        }
        
        return false;
    }
}

// Initialiser le plugin
new AutoArticleImporterFull();