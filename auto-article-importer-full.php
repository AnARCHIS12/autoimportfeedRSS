<?php
/*
Plugin Name: Auto Article Importer Full
Description: Récupère automatiquement des articles ENTIERS depuis des flux RSS et les publie en mentionnant la source
Version: 2.0
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
                            <td><input type="url" id="feed_url" name="feed_url" class="regular-text" required></td>
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
        </div>
        
        <script>
        jQuery(document).ready(function($) {
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
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => sanitize_text_field($_POST['name']),
                'url' => esc_url_raw($_POST['url']),
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
        
        // Récupérer le flux RSS
        $rss = fetch_feed($feed->url);
        
        if (is_wp_error($rss)) {
            error_log('Erreur RSS pour ' . $feed->name . ': ' . $rss->get_error_message());
            return 0;
        }
        
        $items = $rss->get_items(0, 10); // Limiter à 10 articles récents
        $imported_count = 0;
        
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
            
            // Créer un utilisateur générique pour les articles importés ou utiliser un existant
            $generic_author_id = $this->get_or_create_generic_author();
            
            $post_data = array(
                'post_title' => $article_title,
                'post_content' => $clean_content,
                'post_status' => 'publish',
                'post_date' => current_time('mysql'),
                'post_author' => $generic_author_id
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
    
    // Fonction pour créer ou récupérer un auteur générique pour les articles importés
    private function get_or_create_generic_author() {
        // Chercher un utilisateur avec le login 'articles-importes'
        $user = get_user_by('login', 'articles-importes');
        
        if (!$user) {
            // Créer un utilisateur générique s'il n'existe pas
            $user_id = wp_insert_user(array(
                'user_login' => 'articles-importes',
                'user_pass' => wp_generate_password(),
                'user_email' => 'articles@' . parse_url(home_url(), PHP_URL_HOST),
                'display_name' => 'Articles Importés',
                'first_name' => 'Articles',
                'last_name' => 'Importés',
                'role' => 'author'
            ));
            
            if (is_wp_error($user_id)) {
                // Si la création échoue, utiliser l'utilisateur admin par défaut
                return 1;
            }
            
            return $user_id;
        }
        
        return $user->ID;
    }
    
    // Fonction pour récupérer l'article complet depuis la page web
    private function get_full_article_content($url) {
        // Récupérer le contenu de la page
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress Article Importer)'
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
        
        // Supprimer les classes et IDs pour éviter les conflits CSS
        $content = preg_replace('/\s(class|id)="[^"]*"/i', '', $content);
        
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
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress Article Importer)'
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
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress Article Importer)'
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
            // Générer les métadonnées de l'image
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
            wp_update_attachment_metadata($attachment_id, $attachment_data);
            
            // Sauvegarder l'URL originale pour éviter les doublons
            add_post_meta($attachment_id, 'original_image_url', $image_url);
            
            return $attachment_id;
        }
        
        return false;
    }
}

// Initialiser le plugin
new AutoArticleImporterFull();